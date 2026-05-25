<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\CourseAssignmentResource;
use App\Http\Resources\CourseMaterialResource;
use App\Http\Resources\CourseModuleResource;
use App\Http\Resources\CourseSessionResource;
use App\Models\Course;
use App\Models\Registration;
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
    public function show(Request $request, int $courseId): JsonResponse
    {
        $user = $request->user();

        $enrolled = Registration::where(function ($q) use ($user) {
            $q->where('user_id', $user->id)->orWhere('email', $user->email);
        })->where('course_id', $courseId)
          ->whereIn('status', ['Registered', 'registered', 'Payment Confirmed', 'Attended'])
          ->exists();

        if (! $enrolled) {
            return response()->json([
                'success' => false,
                'message' => 'You are not enrolled in this course.',
            ], 403);
        }

        $course = Course::with([
            'instructor.user',
            'modules' => fn ($q) => $q->where('is_active', true)
                ->orderBy('sort_order')
                ->with([
                    'sessions' => fn ($s) => $s->where('is_visible', true)
                        ->where('status', 'published')
                        ->orderBy('sort_order')
                        ->with([
                            'materials'   => fn ($m) => $m->where('is_visible', true)->orderBy('sort_order'),
                            'assignments' => fn ($a) => $a->where('is_visible', true)->where('status', 'published')->orderBy('sort_order'),
                        ]),
                    'materials' => fn ($m) => $m->where('is_visible', true)->orderBy('sort_order'),
                    'courseAssignments' => fn ($a) => $a->where('is_visible', true)->where('status', 'published')->orderBy('sort_order'),
                ]),
        ])->findOrFail($courseId);

        $instructor = $course->instructor;

        return response()->json([
            'success' => true,
            'data'    => [
                'course' => [
                    'id'           => $course->id,
                    'title'        => $course->title,
                    'slug'         => $course->slug,
                    'description'  => $course->description,
                    'image_url'    => $course->course_image ? asset('storage/' . $course->course_image) : null,
                    'instructor_name' => $instructor?->user?->name ?? $instructor?->name,
                    'instructor'   => $instructor ? [
                        'id'   => $instructor->id,
                        'name' => $instructor->user?->name ?? $instructor->name,
                    ] : null,
                ],
                'modules'     => CourseModuleResource::collection($course->modules),
                'sessions'    => CourseSessionResource::collection(
                    $course->courseSessions()
                        ->where('is_visible', true)
                        ->where('status', 'published')
                        ->orderBy('sort_order')
                        ->get()
                ),
                'materials'   => CourseMaterialResource::collection(
                    $course->materials()
                        ->where('is_visible', true)
                        ->orderBy('sort_order')
                        ->get()
                ),
                'assignments' => CourseAssignmentResource::collection(
                    $course->courseAssignments()
                        ->where('is_visible', true)
                        ->where('status', 'published')
                        ->orderBy('sort_order')
                        ->get()
                ),
            ],
        ]);
    }
}
