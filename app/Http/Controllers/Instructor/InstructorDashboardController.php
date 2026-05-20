<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Resources\LmsSessionResource;
use App\Models\Course;
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
                    'user'                    => $this->userShape($user),
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

        $studentsCount = Registration::whereIn('course_id', $courseIds)
            ->where('status', 'Registered')
            ->distinct('email')
            ->count('email');

        $upcomingSessions = LmsSession::query()
            ->with(['workshop', 'instructor'])
            ->where(function ($q) use ($courseIds, $profile) {
                $q->where('instructor_id', $profile->id)
                  ->orWhereIn('course_id', $courseIds)
                  ->orWhereHas('workshop', fn ($w) => $w->whereIn('course_id', $courseIds));
            })
            ->whereIn('status', ['scheduled', 'live'])
            ->orderBy('session_date')
            ->take(10)
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
                'user'                 => $this->userShape($user),
                'courses_count'        => $courseIds->count(),
                'workshops_count'      => $workshopsCount,
                'students_count'       => $studentsCount,
                'pending_reviews'      => $pendingReviews,
                'unread_notifications' => PlatformNotification::where('user_id', $user->id)->where('is_read', false)->count(),
                'upcoming_sessions'    => LmsSessionResource::collection($upcomingSessions)->resolve(),
                'recent_registrations' => $recentRegistrations->values()->all(),
            ],
        ]);
    }

    private function userShape(\App\Models\User $user): array
    {
        return [
            'id'         => $user->id,
            'name'       => $user->name,
            'email'      => $user->email,
            'avatar_url' => $user->avatar_url,
            'role'       => $user->role,
        ];
    }
}
