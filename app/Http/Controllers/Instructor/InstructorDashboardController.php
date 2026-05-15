<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Resources\LmsSessionResource;
use App\Models\Course;
use App\Models\LmsSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InstructorDashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->instructorProfile;

        if (! $profile) {
            return response()->json([
                'success' => true,
                'message' => 'Instructor dashboard data retrieved successfully.',
                'data' => [
                    'courses_count' => 0,
                    'upcoming_sessions' => [],
                    'pending_submission_reviews' => 0,
                ],
            ]);
        }

        $courseIds = Course::query()->where('instructor_id', $profile->id)->pluck('id');

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

        return response()->json([
            'success' => true,
            'message' => 'Instructor dashboard data retrieved successfully.',
            'data' => [
                'courses_count' => $courseIds->count(),
                'upcoming_sessions' => LmsSessionResource::collection($upcomingSessions),
                'pending_submission_reviews' => $pendingReviews,
            ],
        ]);
    }
}
