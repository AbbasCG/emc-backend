<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\ClassGroupStudent;
use App\Models\LmsSession;
use App\Models\OralAssessmentBooking;
use App\Models\PlacementTestAttempt;
use App\Models\Registration;
use App\Models\StudentProgress;
use App\Services\CourseAccessEligibilityService;
use App\Services\PlacementProgressService;
use App\Support\CourseComputedStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentRegistrationsController extends Controller
{
    public function __construct(
        private readonly PlacementProgressService $placement,
        private readonly CourseAccessEligibilityService $eligibility,
    ) {}

    /**
     * GET /api/student/registrations
     *
     * All registrations (all statuses), matched by user_id OR email.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $registrations = Registration::query()
            ->with(['course.instructor.user', 'course.track', 'payments'])
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhere('email', $user->email);
            })
            ->latest()
            ->get();

        $courseIds       = $registrations->pluck('course_id')->unique()->filter()->values();
        $nextSessionsMap = $this->nextSessionsForCourses($courseIds->all());

        $data = $registrations->map(fn ($reg) => [
            'id'             => $reg->id,
            'user_id'        => $reg->user_id,
            'course_id'      => $reg->course_id,
            'full_name'      => $reg->full_name,
            'email'          => $reg->email,
            'status'         => $reg->status,
            'payment_status' => $reg->payments->last()?->status
                ?? ($reg->course?->type === 'free' ? 'free' : null),
            'registered_at'  => $reg->created_at,
            'next_session'   => $nextSessionsMap[$reg->course_id] ?? null,
            // Production hotfix — canonical payment/placement access block.
            // Never absent: the "pending" tab renders straight off registrations
            // regardless of status, so every row (including pending_payment
            // ones) needs an honest, backend-derived eligibility answer, not a
            // frontend guess based on the registration existing.
            'access'         => $reg->course ? $this->eligibility->forUserAndCourse($user, $reg->course) : null,
            'course'         => $reg->course ? [
                'id'           => $reg->course->id,
                'title'        => $reg->course->title,
                'slug'         => $reg->course->slug,
                'image_url'    => $this->storageUrl($reg->course->course_image),
                'type'         => $reg->course->type,
                'price'        => (float) $reg->course->price,
                'start_date'   => $reg->course->start_date?->format('Y-m-d'),
                'start_time'   => $reg->course->start_time,
                'end_date'     => $reg->course->end_date?->format('Y-m-d'),
                'end_time'     => $reg->course->end_time,
                // Computed lifecycle — never overwrites the stored `status` column;
                // see CourseComputedStatus for the single source of this logic.
                'is_ended'         => CourseComputedStatus::isEnded($reg->course),
                'computed_status'  => CourseComputedStatus::computedStatus($reg->course),
                'lifecycle_status' => CourseComputedStatus::lifecycleStatus($reg->course),
                'starts_at'        => CourseComputedStatus::resolveStartDateTime($reg->course)?->toIso8601String(),
                'ends_at'          => CourseComputedStatus::resolveEndDateTime($reg->course)?->toIso8601String(),
                'timezone'         => 'Europe/Amsterdam',
                'location_type'=> $reg->course->location_type,
                'meeting_link' => $reg->course->meeting_link,
                'track'        => $reg->course->track
                    ? ['id' => $reg->course->track->id, 'name' => $reg->course->track->title]
                    : null,
                'instructor'   => $reg->course->instructor ? [
                    'id'   => $reg->course->instructor->id,
                    'name' => $reg->course->instructor->user?->name
                           ?? $reg->course->instructor->name,
                ] : null,
            ] : null,
        ])->values()->all();

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    private function storageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        if (str_starts_with($path, 'http')) {
            return $path;
        }
        return asset('storage/' . ltrim($path, '/'));
    }

    /**
     * GET /api/student/courses
     *
     * Active enrolled courses with full detail, including placement status.
     */
    public function courses(Request $request): JsonResponse
    {
        $user = $request->user();

        $registrations = Registration::query()
            ->with(['course.track', 'course.instructor.user', 'payments', 'learningPath:id,title'])
            ->forStudent($user)
            ->activeEnrollment()
            ->latest()
            ->get();

        $courseIds       = $registrations->pluck('course_id')->unique()->filter()->values();
        $nextSessionsMap = $this->nextSessionsForCourses($courseIds->all());

        $progressMap = StudentProgress::where('user_id', $user->id)
            ->whereIn('course_id', $courseIds->all())
            ->get()
            ->keyBy('course_id');

        $certificateMap = Certificate::where('user_id', $user->id)
            ->whereIn('course_id', $courseIds->all())
            ->whereIn('status', ['issued', 'approved'])
            ->get()
            ->keyBy('course_id');

        // Bulk-load placement data for this student across all enrolled courses
        $attemptMap = PlacementTestAttempt::where('user_id', $user->id)
            ->whereIn('course_id', $courseIds->all())
            ->orderBy('id')
            ->get()
            ->keyBy('course_id');

        $bookingMap = OralAssessmentBooking::with(['slot', 'instructor.user', 'calendarEvents'])
            ->where('user_id', $user->id)
            ->whereIn('course_id', $courseIds->all())
            ->orderBy('id')
            ->get()
            ->keyBy('course_id');

        // Class group assignments for this student
        $classAssignmentMap = ClassGroupStudent::with(['classGroup.instructor.user'])
            ->where('user_id', $user->id)
            ->whereIn('status', ClassGroupStudent::ACTIVE_STATUSES)
            ->get()
            ->filter(fn ($cgs) => $cgs->classGroup !== null)
            ->keyBy(fn ($cgs) => $cgs->classGroup->course_id);

        $data = $registrations->map(function ($reg) use (
            $user, $progressMap, $certificateMap, $nextSessionsMap, $attemptMap, $bookingMap, $classAssignmentMap
        ) {
            $course  = $reg->course;
            $attempt = $attemptMap->get($reg->course_id);
            $booking = $bookingMap->get($reg->course_id);

            $requiresPlacement = (bool) ($course?->requires_placement_test ?? false);
            $placementProgress = $this->placement->progress($attempt, $booking, $requiresPlacement);
            $canStartLearning  = ! $requiresPlacement || ($placementProgress['can_access_course'] ?? false);

            $cgs = $classAssignmentMap->get($reg->course_id);
            $cg  = $cgs?->classGroup;

            return [
                'course_id'                => $reg->course_id,
                'title'                    => $course?->title,
                'slug'                     => $course?->slug,
                'description'              => $course?->description,
                'type'                     => $course?->type,
                'level'                    => $course?->level,
                'thumbnail'                => $course?->course_image,
                'location_type'            => $course?->location_type,
                'meeting_link'             => $course?->meeting_link,
                'start_date'               => $course?->start_date,
                'start_time'               => $course?->start_time,
                'end_date'                 => $course?->end_date,
                'end_time'                 => $course?->end_time,
                'next_cohort_pending'      => $course?->start_date === null,
                // Computed lifecycle — stored publication `status` stays untouched;
                // see CourseComputedStatus for the single source of this logic.
                'is_ended'                 => $course ? CourseComputedStatus::isEnded($course) : null,
                'computed_status'          => $course ? CourseComputedStatus::computedStatus($course) : null,
                'lifecycle_status'         => $course ? CourseComputedStatus::lifecycleStatus($course) : null,
                'starts_at'                => $course ? CourseComputedStatus::resolveStartDateTime($course)?->toIso8601String() : null,
                'ends_at'                  => $course ? CourseComputedStatus::resolveEndDateTime($course)?->toIso8601String() : null,
                'timezone'                 => 'Europe/Amsterdam',
                'track'                    => $course?->track ? [
                    'id'   => $course->track->id,
                    'name' => $course->track->title,
                ] : null,
                'instructor_name'          => $course?->instructor?->user?->name
                    ?? $course?->instructor?->name,
                'registration_id'          => $reg->id,
                'registration_status'      => $reg->status,
                'payment_status'           => $reg->payments->last()?->status
                    ?? ($course?->type === 'free' ? 'free' : null),
                'registered_at'            => $reg->created_at,
                'next_session'             => $nextSessionsMap[$reg->course_id] ?? null,
                'progress'                 => ($p = $progressMap->get($reg->course_id)) ? [
                    'percentage'                      => $p->progress_percentage,
                    'completed_sessions'              => $p->completed_sessions,
                    'total_sessions'                  => $p->total_sessions,
                    'attendance_percentage'            => $p->attendance_percentage,
                    'assignment_completion_percentage' => $p->assignment_completion_percentage,
                    'status'                          => $p->status,
                ] : null,
                'certificate_status'       => $certificateMap->get($reg->course_id)?->status ?? null,
                // Access source — 'learning_path' when enrolled via a path, 'direct' otherwise
                'access_source'            => $reg->learning_path_id ? 'learning_path' : 'direct',
                'learning_path_id'         => $reg->learning_path_id,
                'learning_path_title'      => $reg->learningPath?->title,
                // Placement fields
                'requires_placement_test' => $requiresPlacement,
                'placement_status'        => $placementProgress['status'] ?? null,
                'placement_progress'      => $placementProgress,
                'placement_attempt'       => $attempt ? $this->placement->writtenTestShape($attempt) : null,
                'oral_booking_status'     => $booking?->status,
                'estimated_level'         => $attempt?->estimated_level,
                'final_level'             => $booking?->final_level,
                'can_start_learning'      => $canStartLearning,
                // Production hotfix — same canonical access block as
                // index(); always enrollment_active here since this
                // endpoint is already scoped to activeEnrollment(), but
                // exposed for a single, uniform frontend contract.
                'access'                  => $course ? $this->eligibility->forUserAndCourse($user, $course) : null,
                // Class group assignment (null until instructor assigns)
                'class_assignment'        => $cg ? [
                    'class_group_id'  => $cg->id,
                    'name'            => $cg->name,
                    'level_code'      => $cg->level_code,
                    'schedule_day'    => $cg->schedule_day,
                    'schedule_time'   => $cg->schedule_time ? substr($cg->schedule_time, 0, 5) : null,
                    'location_type'   => $cg->location_type,
                    'meeting_link'    => $cg->meeting_link,
                    'start_date'      => $cg->start_date?->format('Y-m-d'),
                    'instructor_name' => $cg->instructor?->user?->name ?? $cg->instructor?->name,
                    'assigned_at'     => $cgs->assigned_at?->toIso8601String(),
                ] : null,
            ];
        })->values()->all();

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    /**
     * GET /api/student/exams
     *
     * Completed placement test attempts only (no in-progress or not-started).
     */
    public function exams(Request $request): JsonResponse
    {
        $user = $request->user();

        $attempts = PlacementTestAttempt::with(['course', 'oralBooking.slot'])
            ->where('user_id', $user->id)
            ->where('status', 'completed')
            ->orderByDesc('submitted_at')
            ->get();

        $data = $attempts->map(function ($attempt) {
            $booking = $attempt->oralBooking;
            return [
                'id'                 => $attempt->id,
                'status'             => $attempt->status,
                'score'              => $attempt->score,
                'total_questions'    => $attempt->total_questions,
                'percentage'         => $attempt->percentage,
                'estimated_level'    => $attempt->estimated_level,
                'submitted_at'       => $attempt->submitted_at?->toIso8601String(),
                'oral_booking_status'=> $booking?->status,
                'final_level'        => $booking?->final_level,
                'course'             => $attempt->course ? [
                    'id'    => $attempt->course->id,
                    'title' => $attempt->course->title,
                    'slug'  => $attempt->course->slug,
                ] : null,
                'placement_progress' => $this->placement->progress($attempt, $booking),
            ];
        })->values()->all();

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    /**
     * GET /api/student/available-courses
     *
     * Publicly registered courses open for enrollment.
     */
    public function availableCourses(Request $request): JsonResponse
    {
        $user = $request->user();

        $enrolledCourseIds = Registration::where(function ($q) use ($user) {
            $q->where('user_id', $user->id)->orWhere('email', $user->email);
        })->whereNotIn('status', ['Cancelled'])->pluck('course_id')->all();

        $query = \App\Models\Course::with(['instructor.user', 'track'])
            ->where('status', 'published')
            ->where('registration_open', true)
            ->whereNotIn('id', $enrolledCourseIds);

        if ($request->filled('track_id')) {
            $query->where('track_id', $request->query('track_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->filled('search')) {
            $q = (string) $request->query('search');
            $query->where(fn ($s) => $s->where('title', 'like', "%{$q}%")->orWhere('description', 'like', "%{$q}%"));
        }

        $perPage = min((int) $request->query('per_page', 15), 50);
        $courses = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $courses->map(fn ($c) => [
                'id'                  => $c->id,
                'title'               => $c->title,
                'slug'                => $c->slug,
                'description'         => $c->description,
                'short_description'   => $c->short_description,
                'type'                => $c->type,
                'price'               => (float) $c->price,
                'level'               => $c->level,
                'language'            => $c->language,
                'course_image'        => $c->course_image,
                'location_type'       => $c->location_type,
                'start_date'          => $c->start_date,
                'next_cohort_pending' => $c->start_date === null,
                'instructor_name'     => $c->instructor?->user?->name ?? $c->instructor?->name,
                'track'               => $c->track ? ['id' => $c->track->id, 'title' => $c->track->title] : null,
            ]),
            'meta' => [
                'total'        => $courses->total(),
                'current_page' => $courses->currentPage(),
                'last_page'    => $courses->lastPage(),
            ],
        ]);
    }

    /** Returns a map of course_id => next scheduled session for the given course IDs. */
    private function nextSessionsForCourses(array $courseIds): array
    {
        if (empty($courseIds)) {
            return [];
        }

        $sessions = LmsSession::query()
            ->where(function ($q) use ($courseIds) {
                $q->whereIn('course_id', $courseIds)
                  ->orWhereHas('workshop', fn ($w) => $w->whereIn('course_id', $courseIds));
            })
            ->whereIn('status', ['scheduled', 'live'])
            ->orderBy('session_date')
            ->orderBy('start_time')
            ->with('workshop:id,course_id')
            ->get(['id', 'course_id', 'workshop_id', 'title', 'session_date', 'start_time', 'end_time', 'status', 'location']);

        $map = [];
        foreach ($sessions as $session) {
            $cid = $session->course_id ?? $session->workshop?->course_id;
            if ($cid && ! isset($map[$cid])) {
                $map[$cid] = [
                    'id'           => $session->id,
                    'title'        => $session->title,
                    'session_date' => $session->session_date,
                    'start_time'   => $session->start_time,
                    'end_time'     => $session->end_time,
                    'status'       => $session->status,
                    'location'     => $session->location,
                ];
            }
        }

        return $map;
    }
}
