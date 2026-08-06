<?php

namespace App\Http\Controllers\Instructor;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\LmsSessionResource;
use App\Http\Resources\UserResource;
use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\CourseSession;
use App\Models\LmsSession;
use App\Models\PlatformNotification;
use App\Models\Registration;
use App\Models\Workshop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InstructorDashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user    = $request->user();
        $profile = $user->instructorProfile;

        if (! $profile) {
            return response()->json([
                'success' => true,
                'message' => 'Instructor dashboard data retrieved successfully.',
                'data'    => [
                    'user'                    => UserResource::make($user),
                    'courses_count'           => 0,
                    'workshops_count'         => 0,
                    'students_count'          => 0,
                    'pending_reviews'         => 0,
                    'unread_notifications'    => PlatformNotification::where('user_id', $user->id)->where('is_read', false)->count(),
                    'upcoming_sessions'       => [],
                    'recent_registrations'    => [],
                ],
            ]);
        }

        $courseIds = Course::where('instructor_id', $profile->id)->pluck('id');

        $workshopsCount = Workshop::where('instructor_id', $profile->id)->count();

        // Class groups assigned to this instructor
        $classesCount = ClassGroup::where('instructor_id', $profile->id)->count();

        // Total LMS sessions for instructor's courses or directly assigned
        $lmsSessionQuery = fn ($q) => $q->where(function ($inner) use ($courseIds, $profile) {
            $inner->whereIn('course_id', $courseIds)
                  ->orWhere('instructor_id', $profile->id);
        });

        $sessionsCount = LmsSession::where(function ($q) use ($courseIds, $profile) {
            $q->whereIn('course_id', $courseIds)
              ->orWhere('instructor_id', $profile->id);
        })->count();

        // Sessions that have ended (by real time) but have no attendance records saved yet
        $now         = now();
        $todayDate   = $now->toDateString();
        $currentTime = $now->format('H:i:s');

        $pendingAttendanceCount = LmsSession::where(function ($q) use ($courseIds, $profile) {
            $q->whereIn('course_id', $courseIds)
              ->orWhere('instructor_id', $profile->id);
        })->where(function ($q) use ($todayDate, $currentTime) {
            // Past sessions: date before today OR (today AND end_time has passed)
            $q->whereDate('session_date', '<', $todayDate)
              ->orWhere(function ($inner) use ($todayDate, $currentTime) {
                  $inner->whereDate('session_date', $todayDate)
                        ->whereNotNull('end_time')
                        ->whereTime('end_time', '<', $currentTime);
              });
        })->whereDoesntHave('attendances')
          ->count();

        $studentsCount = Registration::whereIn('course_id', $courseIds)
            ->whereIn('status', RegistrationStatus::activeEnrollment())
            ->distinct('email')
            ->count('email');

        $upcomingSessions = LmsSession::query()
            ->with(['workshop', 'instructor', 'course'])
            ->where(function ($q) use ($courseIds, $profile) {
                $q->where('instructor_id', $profile->id)
                  ->orWhereIn('course_id', $courseIds)
                  ->orWhereHas('workshop', fn ($w) => $w->whereIn('course_id', $courseIds));
            })
            ->where(function ($q) use ($todayDate, $currentTime) {
                // Include any session that has not ended yet (real-time, not DB status)
                $q->whereDate('session_date', '>', $todayDate)
                  ->orWhere(function ($inner) use ($todayDate, $currentTime) {
                      $inner->whereDate('session_date', $todayDate)
                            ->where(function ($t) use ($currentTime) {
                                // No end_time recorded → always include today's sessions
                                $t->whereNull('end_time')
                                  ->orWhereTime('end_time', '>=', $currentTime);
                            });
                  });
            })
            ->orderBy('session_date')
            ->orderBy('start_time')
            ->take(15)
            ->get();

        $pendingReviews = \App\Models\AssignmentSubmission::query()
            ->where('status', 'submitted')
            ->whereHas('assignment', function ($q) use ($courseIds) {
                $q->whereIn('course_id', $courseIds)
                  ->orWhereHas('workshop', fn ($w) => $w->whereIn('course_id', $courseIds));
            })
            ->count();

        // Recent student registrations for instructor's courses
        $recentRegistrations = Registration::with('course:id,title,slug')
            ->whereIn('course_id', $courseIds)
            ->where('status', 'Registered')
            ->latest()
            ->take(5)
            ->get()
            ->map(fn ($r) => [
                'id'           => $r->id,
                'full_name'    => $r->full_name,
                'email'        => $r->email,
                'course_title' => $r->course?->title,
                'registered_at'=> $r->created_at,
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Instructor dashboard data retrieved successfully.',
            'data'    => [
                'user'                     => UserResource::make($user),
                'courses_count'            => $courseIds->count(),
                'workshops_count'          => $workshopsCount,
                'classes_count'            => $classesCount,
                'students_count'           => $studentsCount,
                'sessions_count'           => $sessionsCount,
                'pending_reviews'          => $pendingReviews,
                'pending_attendance_count' => $pendingAttendanceCount,
                'unread_notifications'     => PlatformNotification::where('user_id', $user->id)->where('is_read', false)->count(),
                'upcoming_sessions'        => LmsSessionResource::collection($upcomingSessions)->resolve(),
                'recent_registrations'     => $recentRegistrations->values()->all(),
            ],
        ]);
    }

}
