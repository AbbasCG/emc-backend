<?php

namespace App\Http\Controllers\Instructor;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CourseResource;
use App\Models\ClassGroup;
use App\Models\ClassGroupStudent;
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
            ->whereIn('status', RegistrationStatus::activeEnrollment())
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

        // Ticket 2 Phase 3: class-related counters, same bulk-group-by
        // pattern as the placement counters above — one query per counter
        // across all courses on this page, never one query per course.
        $assignedStudentCounts = ClassGroupStudent::whereIn('status', ClassGroupStudent::ACTIVE_STATUSES)
            ->whereHas('classGroup', fn ($q) => $q->whereIn('course_id', $courseIds))
            ->with('classGroup:id,course_id')
            ->get()
            ->groupBy(fn ($cgs) => $cgs->classGroup?->course_id)
            ->map->count();

        $classesCounts = ClassGroup::whereIn('course_id', $courseIds)
            ->groupBy('course_id')->selectRaw('course_id, count(*) as cnt')->pluck('cnt', 'course_id');

        $activeClassesCounts = ClassGroup::whereIn('course_id', $courseIds)
            ->whereIn('status', ClassGroup::TEACHING_ACTIVE_STATUSES)
            ->groupBy('course_id')->selectRaw('course_id, count(*) as cnt')->pluck('cnt', 'course_id');

        $completedClassesCounts = ClassGroup::whereIn('course_id', $courseIds)
            ->where('status', 'completed')
            ->groupBy('course_id')->selectRaw('course_id, count(*) as cnt')->pluck('cnt', 'course_id');

        $items = $courses->map(function (Course $course) use (
            $enrolledCounts,
            $writtenCompletedCounts,
            $oralBookedCounts,
            $oralCompletedCounts,
            $studentsWithAnyBooking,
            $finalLevelCounts,
            $assignedStudentCounts,
            $classesCounts,
            $activeClassesCounts,
            $completedClassesCounts
        ) {
            $resource = (new CourseResource($course))->toArray(request());

            $writtenCount   = (int) ($writtenCompletedCounts[$course->id] ?? 0);
            $withBooking    = (int) ($studentsWithAnyBooking[$course->id] ?? 0);
            $enrolled       = (int) ($enrolledCounts[$course->id] ?? 0);
            $assigned       = (int) ($assignedStudentCounts[$course->id] ?? 0);
            $finalLevel     = (int) ($finalLevelCounts[$course->id] ?? 0);

            $resource['enrolled_students_count']  = $enrolled;
            $resource['written_tests_count']      = $writtenCount;
            $resource['oral_pending_count']       = max(0, $writtenCount - $withBooking);
            $resource['oral_booked_count']        = (int) ($oralBookedCounts[$course->id] ?? 0);
            $resource['oral_completed_count']     = (int) ($oralCompletedCounts[$course->id] ?? 0);
            $resource['final_level_count']        = $finalLevel;

            // Ticket 2 Phase 3 — additive canonical counters. Backend-computed
            // only; the frontend must never re-derive any of these.
            $resource['students_count']                = $enrolled;
            $resource['assigned_students_count']        = $assigned;
            $resource['waiting_assignment_count']       = max(0, $finalLevel - $assigned);
            $resource['completed_written_tests_count']  = $writtenCount;
            $resource['completed_oral_tests_count']     = (int) ($oralCompletedCounts[$course->id] ?? 0);
            $resource['completed_placement_count']      = $finalLevel;
            $resource['classes_count']                  = (int) ($classesCounts[$course->id] ?? 0);
            $resource['active_classes_count']           = (int) ($activeClassesCounts[$course->id] ?? 0);
            $resource['completed_classes_count']        = (int) ($completedClassesCounts[$course->id] ?? 0);

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
