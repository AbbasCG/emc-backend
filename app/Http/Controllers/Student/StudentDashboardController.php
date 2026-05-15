<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\LmsAssignmentResource;
use App\Http\Resources\LmsSessionResource;
use App\Http\Resources\RegistrationResource;
use App\Models\Assignment;
use App\Models\LmsSession;
use App\Models\Registration;
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
            ->with(['course.track', 'course.instructor'])
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)->orWhere('email', $user->email);
            })
            ->where('status', 'Registered')
            ->latest()
            ->take(10)
            ->get();

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

        return response()->json([
            'success' => true,
            'message' => 'Student dashboard data retrieved successfully.',
            'data' => [
                'enrolled_course_ids' => $courseIds,
                'registrations' => RegistrationResource::collection($registrations),
                'upcoming_sessions' => LmsSessionResource::collection($upcomingSessions),
                'assignments' => LmsAssignmentResource::collection($assignments),
            ],
        ]);
    }
}
