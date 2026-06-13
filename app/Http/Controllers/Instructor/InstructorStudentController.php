<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
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

        $courseIds = $instructor
            ? Course::where('instructor_id', $instructor->id)->pluck('id')->all()
            : [];

        if (empty($courseIds)) {
            return response()->json([
                'success' => true,
                'data'    => [],
                'meta'    => ['total' => 0],
            ]);
        }

        $courses = Course::whereIn('id', $courseIds)->get()->keyBy('id');

        $query = Registration::with('user')
            ->whereIn('course_id', $courseIds)
            ->whereNotIn('status', ['Rejected', 'Cancelled'])
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

        $bookings = OralAssessmentBooking::with('slot')
            ->whereIn('course_id', $courseIds)
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy(fn ($b) => (string) $b->course_id . '_' . (string) $b->user_id);

        $data = $registrations->map(function ($reg) use ($courses, $attempts, $bookings) {
            $course  = $courses->get($reg->course_id);
            $key     = (string) $reg->course_id . '_' . (string) $reg->user_id;
            $attempt = $reg->user_id ? $attempts->get($key) : null;
            $booking = $reg->user_id ? $bookings->get($key) : null;

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
            ];

            // Placement keys are only included when the course actually requires the test.
            // Omitting them entirely prevents the frontend panel from appearing on normal courses.
            if ($requiresPlacement) {
                $base['placement_status']   = $progress ? $progress['status'] : null;
                $base['written_score']      = $progress ? ($progress['written_test']['score'] ?? null) : null;
                $base['total_questions']    = $progress ? ($progress['written_test']['total_questions'] ?? null) : null;
                $base['written_level']      = $progress ? ($progress['written_test']['estimated_level'] ?? null) : null;
                $base['oral_booking_at']    = $progress ? ($progress['oral_assessment']['starts_at'] ?? null) : null;
                $base['final_level']        = $progress ? ($progress['oral_assessment']['final_level'] ?? null) : null;
                $base['placement_progress'] = $progress;
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
            'data'    => $data,
            'meta'    => ['total' => $data->count()] + $statusCounts,
        ]);
    }
}
