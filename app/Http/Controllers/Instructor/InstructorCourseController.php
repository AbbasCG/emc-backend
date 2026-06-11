<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use App\Models\OralAssessmentBooking;
use App\Models\PlacementTestAttempt;
use App\Models\Registration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InstructorCourseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user    = $request->user();
        $profile = $user->instructorProfile;

        $query = Course::query()->with(['track', 'instructor.user', 'features']);

        if ($profile) {
            $query->where('instructor_id', $profile->id);
        } else {
            $query->whereRaw('1 = 0');
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $courses = $query->latest()->paginate($perPage);

        // Collect placement stats for all courses in one query each
        $courseIds = $courses->pluck('id')->all();

        $enrolledCounts = Registration::whereIn('course_id', $courseIds)
            ->whereNotIn('status', ['Rejected', 'Cancelled'])
            ->groupBy('course_id')
            ->selectRaw('course_id, count(*) as cnt')
            ->pluck('cnt', 'course_id');

        // written_completed_count: attempts with status=completed
        $writtenCompletedCounts = PlacementTestAttempt::whereIn('course_id', $courseIds)
            ->where('status', 'completed')
            ->groupBy('course_id')
            ->selectRaw('course_id, count(*) as cnt')
            ->pluck('cnt', 'course_id');

        // oral_booked_count: bookings with status=booked (pending oral, slot reserved)
        $oralBookedCounts = OralAssessmentBooking::whereIn('course_id', $courseIds)
            ->where('status', 'booked')
            ->groupBy('course_id')
            ->selectRaw('course_id, count(*) as cnt')
            ->pluck('cnt', 'course_id');

        // oral_completed_count: bookings with status=completed
        $oralCompletedCounts = OralAssessmentBooking::whereIn('course_id', $courseIds)
            ->where('status', 'completed')
            ->groupBy('course_id')
            ->selectRaw('course_id, count(*) as cnt')
            ->pluck('cnt', 'course_id');

        // oral_pending_count (waiting_oral): written done but no oral booking at all
        $studentsWithAnyBooking = OralAssessmentBooking::whereIn('course_id', $courseIds)
            ->groupBy('course_id')
            ->selectRaw('course_id, count(distinct user_id) as cnt')
            ->pluck('cnt', 'course_id');

        // final_level_count: oral completed with a final level recorded
        $finalLevelCounts = OralAssessmentBooking::whereIn('course_id', $courseIds)
            ->where('status', 'completed')
            ->whereNotNull('final_level')
            ->groupBy('course_id')
            ->selectRaw('course_id, count(*) as cnt')
            ->pluck('cnt', 'course_id');

        $items = $courses->map(function (Course $course) use (
            $enrolledCounts,
            $writtenCompletedCounts,
            $oralBookedCounts,
            $oralCompletedCounts,
            $studentsWithAnyBooking,
            $finalLevelCounts
        ) {
            $resource = (new CourseResource($course))->toArray(request());

            $writtenCount   = (int) ($writtenCompletedCounts[$course->id] ?? 0);
            $withBooking    = (int) ($studentsWithAnyBooking[$course->id] ?? 0);

            $resource['enrolled_students_count']  = (int) ($enrolledCounts[$course->id] ?? 0);
            $resource['written_tests_count']      = $writtenCount;
            $resource['oral_pending_count']       = max(0, $writtenCount - $withBooking);
            $resource['oral_booked_count']        = (int) ($oralBookedCounts[$course->id] ?? 0);
            $resource['oral_completed_count']     = (int) ($oralCompletedCounts[$course->id] ?? 0);
            $resource['final_level_count']        = (int) ($finalLevelCounts[$course->id] ?? 0);

            return $resource;
        });

        return response()->json([
            'success' => true,
            'message' => 'Courses retrieved successfully',
            'data'    => $items,
            'meta'    => [
                'total'        => $courses->total(),
                'current_page' => $courses->currentPage(),
                'last_page'    => $courses->lastPage(),
                'per_page'     => $courses->perPage(),
            ],
        ]);
    }
}
