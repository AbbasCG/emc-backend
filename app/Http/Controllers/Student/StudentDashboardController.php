<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\CertificateResource;
use App\Http\Resources\CourseResource;
use App\Http\Resources\LmsAssignmentResource;
use App\Http\Resources\LmsSessionResource;
use App\Http\Resources\PlatformNotificationResource;
use App\Http\Resources\RegistrationResource;
use App\Http\Resources\StudentProgressResource;
use App\Models\Assignment;
use App\Models\Certificate;
use App\Models\LmsSession;
use App\Models\PlatformNotification;
use App\Models\Registration;
use App\Models\StudentProgress;
use App\Services\LmsEnrollmentService;
use App\Services\StudentProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentDashboardController extends Controller
{
    public function __invoke(Request $request, LmsEnrollmentService $enrollment, StudentProgressService $progress): JsonResponse
    {
        $user = $request->user();
        $courseIds = $enrollment->enrolledCourseIds($user);

        $progress->syncForUser($user);

        $registrations = Registration::query()
            ->with(['course.track', 'course.instructor.user', 'course.features', 'payments'])
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)->orWhere('email', $user->email);
            })
            ->whereNotIn('status', ['Cancelled'])
            ->latest()
            ->take(20)
            ->get();

        $progressKeyed = StudentProgress::query()
            ->where('user_id', $user->id)
            ->whereIn('course_id', $registrations->pluck('course_id')->filter()->all())
            ->get()
            ->keyBy('course_id');

        $currentCourses = $registrations->map(function (Registration $reg) use ($progressKeyed) {
            $c = $reg->course;
            $p = $progressKeyed->get($reg->course_id);

            return [
                'id'                 => $c?->id ?? $reg->course_id,
                'title'              => $c?->title ?? '',
                'slug'               => $c?->slug,
                'instructor_name'    => $c?->instructor?->user?->name ?? $c?->instructor?->name,
                'progress_percent'   => $p ? (float) $p->progress_percentage : 0.0,
                'status'             => $reg->status,
                'start_date'         => $c?->start_date?->format('Y-m-d'),
                'start_time'         => $c?->start_time,
                'meeting_link'       => $c?->meeting_link,
                'course_image'       => $c?->course_image,
            ];
        })->values()->all();

        $upcomingSessions = LmsSession::query()
            ->with(['workshop', 'instructor'])
            ->where(function ($q) use ($courseIds) {
                $q->whereIn('course_id', $courseIds)
                    ->orWhereHas('workshop', fn ($w) => $w->whereIn('course_id', $courseIds));
            })
            ->whereIn('status', ['scheduled', 'live'])
            ->orderBy('session_date')
            ->orderBy('start_time')
            ->take(8)
            ->get();

        $assignments = Assignment::query()
            ->where('status', 'active')
            ->where(function ($q) use ($courseIds) {
                $q->whereIn('course_id', $courseIds)
                    ->orWhereHas('workshop', fn ($w) => $w->whereIn('course_id', $courseIds));
            })
            ->with(['submissions' => fn ($q) => $q->where('user_id', $user->id)])
            ->orderBy('due_date')
            ->take(10)
            ->get();

        $certificates = Certificate::with(['course', 'track'])
            ->where('user_id', $user->id)
            ->whereIn('status', ['issued', 'approved'])
            ->latest('issued_at')
            ->take(5)
            ->get();

        $progressRows = StudentProgress::with('course')
            ->where('user_id', $user->id)
            ->latest()
            ->take(10)
            ->get();

        $notifications = PlatformNotification::where('user_id', $user->id)
            ->latest()
            ->take(10)
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Student dashboard data retrieved successfully.',
            'data'    => [
                'user' => [
                    'id'         => $user->id,
                    'name'       => $user->name,
                    'email'      => $user->email,
                    'avatar_url' => $user->avatar_url,
                    'role'       => $user->role,
                ],
                'enrolled_course_ids'  => $courseIds->values()->all(),
                'current_courses'      => $currentCourses,
                'courses'              => $registrations
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
                'registrations'        => RegistrationResource::collection($registrations)->resolve(),
                'upcoming_sessions'    => LmsSessionResource::collection($upcomingSessions)->resolve(),
                'assignments'          => LmsAssignmentResource::collection($assignments)->resolve(),
                'certificates'         => CertificateResource::collection($certificates)->resolve(),
                'progress'             => StudentProgressResource::collection($progressRows)->resolve(),
                'notifications'        => PlatformNotificationResource::collection($notifications)->resolve(),
                'stats' => [
                    'courses_enrolled'     => $registrations->count(),
                    'assignments_pending'  => $assignments->filter(
                        fn ($a) => $a->submissions->isEmpty()
                    )->count(),
                    'certificates_earned'  => $certificates->count(),
                    'unread_notifications' => $notifications->where('is_read', false)->count(),
                ],
            ],
        ]);
    }
}
