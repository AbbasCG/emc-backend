<?php

namespace App\Http\Controllers\Instructor;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Models\ClassGroupStudent;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\OralAssessmentBooking;
use App\Models\PlacementTestAttempt;
use App\Models\Registration;
use App\Services\PlacementProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InstructorStudentController extends Controller
{
    public function __construct(private readonly PlacementProgressService $placement) {}

    // ─── GET /api/instructor/students ────────────────────────────────────────
    //
    // All students from all courses assigned to this instructor.
    // Includes placement status, written score/level, and oral booking.
    //
    // Query params:
    //   q         — search by name or email
    //   course_id — filter to a single course

    public function index(Request $request): JsonResponse
    {
        $instructor = Instructor::where('user_id', $request->user()->id)->first();
        abort_if(! $instructor, 403, 'ليس لديك صلاحية الوصول إلى بيانات الطلاب.');

        $courses = Course::where('instructor_id', $instructor->id)->get()->keyBy('id');
        $courseIds = $courses->keys()->all();

        if (empty($courseIds)) {
            return response()->json([
                'success' => true,
                'data'    => [],
                'meta'    => ['total' => 0],
            ]);
        }

        $query = Registration::with('user')
            ->whereIn('course_id', $courseIds)
            ->whereIn('status', RegistrationStatus::activeEnrollment())
            ->orderBy('course_id')
            ->orderByDesc('created_at');

        if ($request->filled('q')) {
            $term = $request->q;
            $query->where(fn ($q) => $q
                ->where('full_name', 'like', "%{$term}%")
                ->orWhere('email',   'like', "%{$term}%")
            );
        }

        if ($request->filled('course_id')) {
            $query->where('course_id', (int) $request->course_id);
        }

        $registrations = $query->get();

        $userIds = $registrations->pluck('user_id')->filter()->unique()->values()->all();

        // Key by "course_id_user_id" to handle same student in multiple courses correctly
        $attempts = PlacementTestAttempt::whereIn('course_id', $courseIds)
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy(fn ($a) => (string) $a->course_id . '_' . (string) $a->user_id);

        // Eager-load slot/instructor.user/editedBy — required by
        // oralAssessmentFullShape() below, same relations studentsForCourse() loads.
        $bookings = OralAssessmentBooking::with(['slot', 'instructor.user', 'editedBy', 'calendarEvents'])
            ->whereIn('course_id', $courseIds)
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy(fn ($b) => (string) $b->course_id . '_' . (string) $b->user_id);

        // Canonical class-assignment source — same ClassGroupStudent table and
        // ACTIVE_STATUSES definition studentsForCourse() already uses, so this
        // page can never disagree with the course/placement-students pages
        // about the same student's assigned class. Keyed by user_id+course_id
        // (via the related class_group's course_id) — a student enrolled in
        // multiple courses must only ever see the class from the matching one.
        $memberships = ClassGroupStudent::with(['classGroup.instructor.user', 'assignedBy'])
            ->whereIn('user_id', $userIds)
            ->whereIn('status', ClassGroupStudent::ACTIVE_STATUSES)
            ->get()
            ->filter(fn ($cgs) => $cgs->classGroup && in_array($cgs->classGroup->course_id, $courseIds, true))
            ->keyBy(fn ($cgs) => (string) $cgs->classGroup->course_id . '_' . (string) $cgs->user_id);

        $data = $registrations->map(function ($reg) use ($courses, $attempts, $bookings, $memberships) {
            $course  = $courses->get($reg->course_id);
            $key     = (string) $reg->course_id . '_' . (string) $reg->user_id;
            $attempt = $reg->user_id ? $attempts->get($key) : null;
            $booking = $reg->user_id ? $bookings->get($key) : null;
            $cgs     = $reg->user_id ? $memberships->get($key) : null;

            $requiresPlacement = (bool) ($course?->requires_placement_test ?? false);

            $progress = $this->placement->progress($attempt, $booking, $requiresPlacement);

            $base = [
                'student_id'              => $reg->user_id,
                'student_name'            => $reg->user?->name ?? $reg->full_name,
                'student_email'           => $reg->user?->email ?? $reg->email,
                'student_phone'           => $reg->user?->phone ?? $reg->phone,
                'course_id'               => $reg->course_id,
                'course_title'            => $course?->title,
                'requires_placement_test' => $requiresPlacement,
                'enrollment_status'       => strtolower($reg->status),
                'class_assignment'        => $this->placement->classAssignmentShape($cgs),
                'is_assigned'             => $cgs !== null && in_array($cgs->status, ClassGroupStudent::ACTIVE_STATUSES, true),
            ];

            // Placement keys are only included when the course actually requires the test.
            // Omitting them entirely prevents the frontend panel from appearing on normal courses.
            if ($requiresPlacement) {
                $base['placement_status']   = $progress ? $progress['status'] : null;
                $base['written_score']      = $progress ? ($progress['written_test']['score'] ?? null) : null;
                $base['total_questions']    = $progress ? ($progress['written_test']['total_questions'] ?? null) : null;
                $base['written_level']      = $progress ? ($progress['written_test']['estimated_level'] ?? null) : null;
                $base['oral_booking_at']    = $progress ? ($progress['oral_assessment']['starts_at'] ?? null) : null;
                $base['oral_score']         = $progress ? ($progress['oral_assessment']['oral_score'] ?? null) : null;
                $base['final_level']        = $progress ? ($progress['oral_assessment']['final_level'] ?? null) : null;
                $base['placement_progress'] = $progress;
                // Same canonical key name and shape as studentsForCourse() — the
                // full rubric/evaluator/notes breakdown was previously omitted
                // here entirely, causing this page to show a bare oral_score
                // with no detail while course/placement-students showed both.
                $base['oral_assessment_full'] = $this->placement->oralAssessmentFullShape($booking);
            }

            return $base;
        })->values();

        $statusCounts = array_fill_keys([
            PlacementProgressService::STATUS_NOT_STARTED,
            PlacementProgressService::STATUS_IN_PROGRESS,
            PlacementProgressService::STATUS_WRITTEN_COMPLETED,
            PlacementProgressService::STATUS_ORAL_BOOKED,
            PlacementProgressService::STATUS_ORAL_COMPLETED,
            PlacementProgressService::STATUS_COMPLETED,
        ], 0);
        foreach ($data as $row) {
            // Only tally placement status for courses that require the placement test.
            if (! ($row['requires_placement_test'] ?? false)) continue;
            $key = $row['placement_progress']['status'] ?? null;
            if ($key && isset($statusCounts[$key])) {
                $statusCounts[$key]++;
            }
        }

        return response()->json([
            'success' => true,
            'data'    => \App\Http\Resources\InstructorStudentSummaryResource::collection($data),
            'meta'    => ['total' => $data->count()] + $statusCounts,
        ]);
    }
}
