<?php

namespace App\Http\Controllers\Student;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateResource;
use App\Http\Resources\CourseResource;
use App\Http\Resources\LmsAssignmentResource;
use App\Http\Resources\LmsSessionResource;
use App\Http\Resources\PlatformNotificationResource;
use App\Http\Resources\RegistrationResource;
use App\Http\Resources\StudentProgressResource;
use App\Models\Certificate;
use App\Models\ClassGroupStudent;
use App\Models\LearningPathEnrollment;
use App\Models\LmsSession;
use App\Models\OralAssessmentBooking;
use App\Models\PlacementTestAttempt;
use App\Models\PlatformNotification;
use App\Models\Registration;
use App\Models\StudentProgress;
use App\Services\LmsEnrollmentService;
use App\Services\PlacementProgressService;
use App\Services\StudentProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentDashboardController extends Controller
{
    public function __invoke(
        Request $request,
        LmsEnrollmentService $enrollment,
        StudentProgressService $progress,
        PlacementProgressService $placement
    ): JsonResponse {
        $user      = $request->user();
        $courseIds = $enrollment->enrolledCourseIds($user);

        // Sync progress silently — don't let sync failures fail the dashboard.
        try {
            $progress->syncForUser($user);
        } catch (\Throwable) {
            // non-fatal
        }

        // ── Registrations ─────────────────────────────────────────────────────
        $registrations = Registration::query()
            ->with(['course.track', 'course.instructor.user', 'course.features', 'payments'])
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)->orWhere('email', $user->email);
            })
            ->whereNotIn('status', ['Cancelled', 'cancelled'])
            ->latest()
            ->take(30)
            ->get();

        $regCourseIds = $registrations->pluck('course_id')->filter()->unique()->all();

        // ── Progress map ──────────────────────────────────────────────────────
        $progressKeyed = StudentProgress::query()
            ->where('user_id', $user->id)
            ->whereIn('course_id', $regCourseIds)
            ->get()
            ->keyBy('course_id');

        // ── Placement & oral maps ─────────────────────────────────────────────
        $attemptMap = PlacementTestAttempt::where('user_id', $user->id)
            ->whereIn('course_id', $regCourseIds)
            ->orderBy('id')
            ->get()
            ->keyBy('course_id');

        $bookingMap = OralAssessmentBooking::with(['slot', 'instructor.user'])
            ->where('user_id', $user->id)
            ->whereIn('course_id', $regCourseIds)
            ->orderBy('id')
            ->get()
            ->keyBy('course_id');

        // ── Class assignment map ──────────────────────────────────────────────
        $classAssignmentMap = ClassGroupStudent::with(['classGroup.instructor.user'])
            ->where('user_id', $user->id)
            ->whereIn('status', ['assigned', 'completed'])
            ->get()
            ->filter(fn ($cgs) => $cgs->classGroup !== null)
            ->keyBy(fn ($cgs) => $cgs->classGroup->course_id);

        // ── Build course list ─────────────────────────────────────────────────
        $activeStatuses    = RegistrationStatus::activeEnrollment();
        $completedStatuses = ['attended', 'Attended'];

        $currentCourses = $registrations->map(function (Registration $reg) use (
            $progressKeyed, $attemptMap, $bookingMap, $classAssignmentMap, $placement,
            $activeStatuses, $completedStatuses
        ) {
            $c   = $reg->course;
            $p   = $progressKeyed->get($reg->course_id);
            $cgs = $classAssignmentMap->get($reg->course_id);
            $cg  = $cgs?->classGroup;

            $requiresPlacement = (bool) ($c?->requires_placement_test ?? false);
            $attempt           = $attemptMap->get($reg->course_id);
            $booking           = $bookingMap->get($reg->course_id);
            $placementProgress = $placement->progress($attempt, $booking, $requiresPlacement);

            $progressPct = $p ? (float) $p->progress_percentage : 0.0;
            $isCompleted = $progressPct >= 100 || in_array($reg->status, $completedStatuses, true);
            $isActive    = in_array($reg->status, $activeStatuses, true) && ! $isCompleted;

            // Compute human-readable display status for the frontend card
            $requiresPlacementTest = (bool) ($c?->requires_placement_test ?? false);
            $attempt               = $attemptMap->get($reg->course_id);
            $cg2                   = $classAssignmentMap->get($reg->course_id);
            $waitingClassAssign    = $requiresPlacementTest && $attempt && ! $cg2;

            if ($isCompleted) {
                $displayStatus = 'completed';
            } elseif ($isActive) {
                $displayStatus = 'active';
            } elseif ($waitingClassAssign) {
                $displayStatus = 'waiting_class';
            } elseif ($requiresPlacementTest && ! $attempt) {
                $displayStatus = 'needs_placement';
            } elseif ($c?->start_date && now()->lt($c->start_date)) {
                $displayStatus = 'upcoming';
            } else {
                $displayStatus = 'active';
            }

            return [
                'id'                      => $c?->id ?? $reg->course_id,
                'title'                   => $c?->title ?? '',
                'slug'                    => $c?->slug,
                'instructor_name'         => $c?->instructor?->user?->name ?? $c?->instructor?->name,
                'progress_percent'        => $progressPct,
                'is_completed'            => $isCompleted,
                'is_active'               => $isActive,
                'display_status'          => $displayStatus,
                'status'                  => $reg->status,
                'start_date'              => $c?->start_date?->format('Y-m-d'),
                'start_time'              => $c?->start_time,
                'meeting_link'            => $c?->meeting_link,
                'course_image'            => $c?->course_image,
                'requires_placement_test' => $requiresPlacement,
                'placement_progress'      => $placementProgress,
                'registration_id'         => $reg->id,
                'enrolled_at'             => $reg->created_at?->toIso8601String(),
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
        })->values();

        // ── Active / Recent / Completed slices ────────────────────────────────
        $activeCourses    = $currentCourses->where('is_active', true)->values();
        $completedCourses = $currentCourses->where('is_completed', true)->values();
        $recentCourses    = $currentCourses->take(6)->values();

        // ── Sessions (live + upcoming, excluding already-ended today) ────────
        $nowDate = now()->toDateString();
        $nowTime = now()->format('H:i:s');

        $allEnrolledSessions = LmsSession::query()
            ->with(['course:id,title,slug', 'instructor.user'])
            ->where(function ($q) use ($courseIds) {
                $q->whereIn('course_id', $courseIds)
                    ->orWhereHas('workshop', fn ($w) => $w->whereIn('course_id', $courseIds));
            })
            ->whereNotIn('status', ['cancelled', 'archived'])
            ->where(function ($q) use ($nowDate) {
                // Include sessions from last 2 days so "ended" group has data
                $q->where('session_date', '>=', now()->subDays(2)->toDateString());
            })
            ->orderBy('session_date')
            ->orderBy('start_time')
            ->take(20)
            ->get()
            ->map(function (LmsSession $s) use ($nowDate, $nowTime) {
                $date      = $s->session_date?->toDateString();
                $startTime = $s->start_time;
                $endTime   = $s->end_time;
                $startsAt  = $date && $startTime ? $date . 'T' . $startTime . ':00' : ($date ? $date . 'T00:00:00' : null);
                $endsAt    = $date && $endTime   ? $date . 'T' . $endTime   . ':00' : ($startsAt ? date('Y-m-d\TH:i:s', strtotime($startsAt) + 90 * 60) : null);

                // Compute real-time status using server clock
                if ($date === null) {
                    $computed = 'upcoming';
                } elseif ($date < $nowDate || ($date === $nowDate && $endTime && $endTime < $nowTime)) {
                    $computed = 'ended';
                } elseif ($date === $nowDate && $startTime && $startTime <= $nowTime && (!$endTime || $endTime >= $nowTime)) {
                    $computed = 'live';
                } else {
                    $computed = 'upcoming';
                }

                return [
                    'id'               => $s->id,
                    'title'            => $s->title,
                    'course_id'        => $s->course_id,
                    'course_title'     => $s->course?->title,
                    'course_slug'      => $s->course?->slug,
                    'session_date'     => $date,
                    'start_time'       => $startTime,
                    'end_time'         => $endTime,
                    'starts_at'        => $startsAt,
                    'ends_at'          => $endsAt,
                    'meeting_url'      => $s->meeting_url,
                    'meeting_link'     => $s->meeting_url,
                    'recording_url'    => $s->recording_url,
                    'location'         => $s->location,
                    'status'           => $computed,   // computed real-time status
                    'db_status'        => $s->status,  // raw DB status for reference
                    'instructor_name'  => $s->instructor?->user?->name ?? $s->instructor?->name,
                    'is_cancelled'     => $s->status === 'cancelled',
                ];
            });

        $liveSessions     = $allEnrolledSessions->where('status', 'live')->values();
        $upcomingSessions = $allEnrolledSessions->where('status', 'upcoming')->values();
        $endedSessions    = $allEnrolledSessions->where('status', 'ended')->values();

        // ── Assignments (published, visible) ─────────────────────────────────
        $allAssignments = \App\Models\CourseAssignment::query()
            ->whereIn('status', ['published', 'active'])
            ->where('is_visible', true)
            ->whereIn('course_id', $courseIds)
            ->with([
                'course:id,title,slug',
                'submissions' => fn ($q) => $q->where('user_id', $user->id),
            ])
            ->orderBy('deadline')
            ->get();

        $pendingAssignments = $allAssignments->filter(
            fn ($a) => $a->submissions->isEmpty()
        )->values();

        $submittedAssignments = $allAssignments->filter(
            fn ($a) => $a->submissions->isNotEmpty()
        )->values();

        $gradedAssignments = $submittedAssignments->filter(
            fn ($a) => in_array($a->submissions->first()?->status, ['graded', 'reviewed'], true)
        )->values();

        $overdueAssignments = $pendingAssignments->filter(
            fn ($a) => $a->deadline && now()->gt($a->deadline)
        )->values();

        $awaitingGradingAssignments = $submittedAssignments->filter(
            fn ($a) => ! in_array($a->submissions->first()?->status, ['graded', 'reviewed'], true)
        )->values();

        // ── Certificates ──────────────────────────────────────────────────────
        $certificates = Certificate::with(['course:id,title', 'track'])
            ->where('user_id', $user->id)
            ->whereIn('status', ['issued', 'approved'])
            ->latest('issued_at')
            ->take(5)
            ->get();

        // ── Progress rows ─────────────────────────────────────────────────────
        $progressRows = StudentProgress::with('course:id,title,slug')
            ->where('user_id', $user->id)
            ->latest()
            ->take(10)
            ->get();

        // ── Notifications ─────────────────────────────────────────────────────
        $notifications = PlatformNotification::where('user_id', $user->id)
            ->latest()
            ->take(10)
            ->get();

        $unreadCount = PlatformNotification::where('user_id', $user->id)
            ->where('is_read', false)
            ->count();

        // ── Learning paths ────────────────────────────────────────────────────
        $learningPathsCount = 0;
        try {
            $learningPathsCount = LearningPathEnrollment::where('user_id', $user->id)
                ->whereIn('status', ['active', 'enrolled', 'completed'])
                ->count();
        } catch (\Throwable) {
            // table might not be migrated yet
        }

        // ── Build counts ──────────────────────────────────────────────────────
        $enrolledCount          = $registrations->count();
        $activeCount            = $activeCourses->count();
        $completedCount         = $completedCourses->count();
        $pendingAsgCount        = $pendingAssignments->count();
        $submittedAsgCount      = $submittedAssignments->count();
        $gradedAsgCount         = $gradedAssignments->count();
        $overdueAsgCount        = $overdueAssignments->count();
        $awaitingGradingCount   = $awaitingGradingAssignments->count();
        $upcomingCount          = $upcomingSessions->count() + $liveSessions->count();
        $certsCount             = $certificates->count();

        return response()->json([
            'success' => true,
            'message' => 'Student dashboard data retrieved successfully.',
            'data'    => [
                // ── User ────────────────────────────────────────────────────
                'user' => [
                    'id'         => $user->id,
                    'name'       => $user->name,
                    'email'      => $user->email,
                    'avatar_url' => $user->avatar_url ?? null,
                    'role'       => $user->role,
                ],

                // ── Top-level counters (what the frontend expects) ────────
                'enrolled_courses_count'      => $enrolledCount,
                'active_courses_count'        => $activeCount,
                'completed_courses_count'     => $completedCount,
                'pending_assignments_count'   => $pendingAsgCount,
                'submitted_assignments_count' => $submittedAsgCount,
                'graded_assignments_count'    => $gradedAsgCount,
                'overdue_assignments_count'   => $overdueAsgCount,
                'awaiting_grading_count'      => $awaitingGradingCount,
                'upcoming_sessions_count'     => $upcomingCount,
                'unread_notifications_count'  => $unreadCount,
                'certificates_count'          => $certsCount,
                'learning_paths_count'        => $learningPathsCount,

                // ── Course lists ─────────────────────────────────────────
                'recent_courses'    => $recentCourses,
                'active_courses'    => $activeCourses,
                'current_courses'   => $currentCourses,     // backward compat

                // ── Full course objects (with registration meta) ──────────
                'courses' => $registrations
                    ->filter(fn ($r) => $r->course !== null)
                    ->map(fn ($r) => array_merge(
                        (new CourseResource($r->course))->resolve(),
                        [
                            'registration_id'     => $r->id,
                            'registration_status' => $r->status,
                            'registered_at'       => $r->created_at,
                        ]
                    ))
                    ->values()
                    ->all(),

                // ── Lists ────────────────────────────────────────────────
                'live_sessions'      => $liveSessions->values()->all(),
                'upcoming_sessions'  => $upcomingSessions->values()->all(),
                'ended_sessions'     => $endedSessions->values()->all(),
                'all_sessions'       => $allEnrolledSessions->values()->all(),
                'pending_assignments'=> LmsAssignmentResource::collection($pendingAssignments)->resolve(),
                'assignments'        => LmsAssignmentResource::collection($allAssignments)->resolve(), // compat
                'certificates'       => CertificateResource::collection($certificates)->resolve(),
                'progress'           => StudentProgressResource::collection($progressRows)->resolve(),
                'notifications'      => PlatformNotificationResource::collection($notifications)->resolve(),
                'registrations'      => RegistrationResource::collection($registrations)->resolve(),

                // ── Legacy stats block (kept for backward compat) ────────
                'stats' => [
                    'courses_enrolled'        => $enrolledCount,
                    'active_courses'          => $activeCount,
                    'completed_courses'       => $completedCount,
                    'assignments_pending'     => $pendingAsgCount,
                    'assignments_submitted'   => $submittedAsgCount,
                    'assignments_graded'      => $gradedAsgCount,
                    'assignments_overdue'     => $overdueAsgCount,
                    'assignments_awaiting'    => $awaitingGradingCount,
                    'upcoming_sessions'       => $upcomingCount,
                    'certificates_earned'     => $certsCount,
                    'learning_paths'          => $learningPathsCount,
                    'unread_notifications'    => $unreadCount,
                ],

                'enrolled_course_ids' => $courseIds->values()->all(),
            ],
        ]);
    }
}
