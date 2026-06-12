<?php

namespace App\Http\Controllers\Student;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CourseMaterialResource;
use App\Http\Resources\CourseSessionResource;
use App\Http\Resources\StudentCourseAssignmentResource;
use App\Http\Resources\StudentLearnModuleResource;
use App\Models\Course;
use App\Models\Registration;
use App\Services\CourseAssignmentBridgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentLearnController extends Controller
{
    /**
     * GET /api/student/courses/{courseId}/learn
     * GET /api/student/learn/{courseId}
     *
     * Full course content tree for enrolled students.
     */
    public function show(
        Request $request,
        int $courseId,
        CourseAssignmentBridgeService $bridge,
    ): JsonResponse {
        $user = $request->user();

        $enrolled = Registration::where('user_id', $user->id)
            ->where('course_id', $courseId)
            ->whereIn('status', RegistrationStatus::activeEnrollment())
            ->exists();

        if (! $enrolled) {
            return response()->json([
                'success' => false,
                'message' => 'You are not enrolled in this course.',
            ], 403);
        }

        $bridge->syncForCourses([$courseId]);

        $course = Course::with([
            'instructor.user',
            'modules' => fn ($q) => $q
                ->where(fn ($sub) => $sub->where('is_active', true)->orWhereNull('is_active'))
                ->whereNotIn('status', ['inactive', 'draft'])
                ->orderBy('sort_order')
                ->with([
                    'lessons' => fn ($l) => $l->where('status', 'active')->orderBy('sort_order'),
                    'courseAssignments' => fn ($a) => $a
                        ->where(fn ($v) => $v->where('is_visible', true)->orWhereNull('is_visible'))
                        ->whereIn('status', ['published', 'active'])
                        ->orderBy('sort_order'),
                ]),
        ])->findOrFail($courseId);

        $instructor = $course->instructor;

        $visibleAssignments = $course->courseAssignments()
            ->where(fn ($v) => $v->where('is_visible', true)->orWhereNull('is_visible'))
            ->whereIn('status', ['published', 'active'])
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'course' => [
                    'id'              => $course->id,
                    'title'           => $course->title,
                    'slug'            => $course->slug,
                    'description'     => $course->description,
                    'image_url'       => $course->course_image ? asset('storage/' . $course->course_image) : null,
                    'instructor_name' => $instructor?->user?->name ?? $instructor?->name,
                    'instructor'      => $instructor ? [
                        'id'   => $instructor->id,
                        'name' => $instructor->user?->name ?? $instructor->name,
                    ] : null,
                ],
                'modules'     => StudentLearnModuleResource::collection($course->modules),
                'sessions'    => CourseSessionResource::collection(
                    $course->courseSessions()
                        ->where('is_visible', true)
                        ->orderBy('sort_order')
                        ->get()
                ),
                'materials'   => CourseMaterialResource::collection(
                    $course->materials()
                        ->where('is_visible', true)
                        ->orderBy('sort_order')
                        ->get()
                ),
                'assignments' => StudentCourseAssignmentResource::collection($visibleAssignments),
            ],
        ]);
    }
}
