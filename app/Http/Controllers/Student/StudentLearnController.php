<?php

namespace App\Http\Controllers\Student;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CourseMaterialResource;
use App\Http\Resources\CourseSessionResource;
use App\Http\Resources\StudentCourseAssignmentResource;
use App\Models\AssignmentSubmission;
use App\Models\ClassGroupStudent;
use App\Models\Course;
use App\Models\LearningPathEnrollment;
use App\Models\Registration;
use App\Services\CourseAssignmentBridgeService;
use App\Services\StudentModuleProgressService;
use App\Support\AssignmentSubmissionStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentLearnController extends Controller
{
    /**
     * GET /api/student/courses/{courseId}/learn
     * GET /api/student/learn/{courseId}
     *
     * Full course content tree for enrolled students.
     * Access is granted by direct registration OR via a learning path that includes this course.
     */
    public function show(
        Request $request,
        int $courseId,
        CourseAssignmentBridgeService $bridge,
        StudentModuleProgressService $progressSvc,
    ): JsonResponse {
        $user = $request->user();

        // Direct enrollment — match by user_id OR email (mirrors StudentRegistrationsController::index)
        $enrolled = Registration::where('course_id', $courseId)
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhere('email', $user->email);
            })
            ->whereIn('status', RegistrationStatus::activeEnrollment())
            ->exists();

        // Fallback: access via learning path enrollment
        if (! $enrolled) {
            $enrolled = LearningPathEnrollment::where('user_id', $user->id)
                ->whereIn('status', ['active', 'completed'])
                ->whereHas('learningPath.courses', fn ($q) => $q->where('courses.id', $courseId))
                ->exists();
        }

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
                    'lessons' => fn ($l) => $l
                        ->where('status', 'active')
                        ->orderBy('sort_order'),
                    'materials' => fn ($m) => $m
                        ->where(fn ($v) => $v->where('is_visible', true)->orWhereNull('is_visible'))
                        ->orderBy('sort_order'),
                    'sessions' => fn ($s) => $s
                        ->where(fn ($v) => $v->where('is_visible', true)->orWhereNull('is_visible'))
                        ->orderBy('sort_order'),
                    'courseAssignments' => fn ($a) => $a
                        ->where(fn ($v) => $v->where('is_visible', true)->orWhereNull('is_visible'))
                        ->whereIn('status', ['published', 'active'])
                        ->orderBy('sort_order'),
                ]),
        ])->findOrFail($courseId);

        $instructor = $course->instructor;

        // Class group the student belongs to for this course (if any)
        $cgStudent = ClassGroupStudent::with(
            'classGroup:id,name,schedule_day,schedule_time,location_type,meeting_link,level_code'
        )
            ->where('user_id', $user->id)
            ->whereHas('classGroup', fn ($q) => $q->where('course_id', $courseId))
            ->first();

        $classGroup = $cgStudent?->classGroup ? [
            'id'            => $cgStudent->classGroup->id,
            'name'          => $cgStudent->classGroup->name,
            'level_code'    => $cgStudent->classGroup->level_code,
            'schedule_day'  => $cgStudent->classGroup->schedule_day,
            'schedule_time' => $cgStudent->classGroup->schedule_time,
            'location_type' => $cgStudent->classGroup->location_type,
            'meeting_link'  => $cgStudent->classGroup->meeting_link,
        ] : null;

        // Build enriched module array: progress stats + full children
        $modulesData = $course->modules->map(function ($mod) use ($user, $bridge, $progressSvc) {
            $progress = $progressSvc->computeForModule($mod, $user);

            $lessons = $mod->lessons->map(fn ($l) => [
                'id'               => $l->id,
                'title'            => $l->title,
                'description'      => $l->description,
                'video_url'        => $l->video_url,
                'duration_minutes' => $l->duration_minutes,
                'sort_order'       => (int) $l->sort_order,
                'status'           => $l->status,
            ])->values();

            $materials = $mod->materials->map(fn ($m) => [
                'id'           => $m->id,
                'course_id'    => $m->course_id,
                'module_id'    => $m->module_id,
                'title'        => $m->title,
                'description'  => $m->description,
                'kind'         => $m->type,
                'type'         => $m->type,
                'file_url'     => $m->file_path ? url('/api/materials/' . $m->id . '/download') : null,
                'external_url' => $m->external_url,
                'sort_order'   => (int) $m->sort_order,
                'is_visible'   => $m->is_visible,
                'updated_at'   => $m->updated_at?->toIso8601String(),
            ])->values();

            $sessions = $mod->sessions->map(fn ($s) => [
                'id'            => $s->id,
                'course_id'     => $s->course_id,
                'module_id'     => $s->module_id,
                'title'         => $s->title,
                'description'   => $s->description,
                'start_at'      => $s->start_at?->toISOString(),
                'end_at'        => $s->end_at?->toISOString(),
                'location_type' => $s->location_type,
                'location'      => $s->location,
                'meeting_link'  => $s->meeting_link,
                'sort_order'    => (int) $s->sort_order,
                'status'        => $s->status,
                'is_visible'    => $s->is_visible,
            ])->values();

            $assignments = $mod->courseAssignments->map(function ($ca) use ($bridge, $user) {
                $lms = $bridge->ensureLmsAssignment($ca);
                $sub = AssignmentSubmission::where('assignment_id', $lms->id)
                    ->where('user_id', $user->id)
                    ->first();
                return [
                    'id'                   => $ca->id,
                    'course_id'            => $ca->course_id,
                    'module_id'            => $ca->module_id,
                    'assignment_id'        => $lms->id,
                    'lms_assignment_id'    => $lms->id,
                    'course_assignment_id' => $ca->id,
                    'title'                => $ca->title,
                    'description'          => $ca->description,
                    'instructions'         => $ca->instructions,
                    'deadline'             => $ca->deadline?->toIso8601String(),
                    'due_at'               => $ca->deadline?->toIso8601String(),
                    'max_points'           => $ca->max_points,
                    'submission_type'      => $ca->submission_type,
                    'is_required'          => $ca->is_required,
                    'is_visible'           => $ca->is_visible,
                    'status'               => AssignmentSubmissionStatus::forStudent($sub, $lms),
                    'sort_order'           => (int) $ca->sort_order,
                ];
            })->values();

            return array_merge($progress, [
                'description' => $mod->description,
                'lessons'     => $lessons,
                'materials'   => $materials,
                'sessions'    => $sessions,
                'assignments' => $assignments,
            ]);
        });

        // Top-level arrays: course-level items only (module_id IS NULL).
        // Module-level content is already embedded inside $modulesData above.
        // This prevents duplication across modules tab and dedicated content tabs.
        $courseLevelAssignments = $course->courseAssignments()
            ->whereNull('module_id')
            ->where(fn ($v) => $v->where('is_visible', true)->orWhereNull('is_visible'))
            ->whereIn('status', ['published', 'active'])
            ->orderBy('sort_order')
            ->get();

        $courseLevelSessions = $course->courseSessions()
            ->whereNull('module_id')
            ->where(fn ($v) => $v->where('is_visible', true)->orWhereNull('is_visible'))
            ->orderBy('sort_order')
            ->get();

        $courseLevelMaterials = $course->materials()
            ->whereNull('module_id')
            ->where(fn ($v) => $v->where('is_visible', true)->orWhereNull('is_visible'))
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
                        'id'    => $instructor->id,
                        'name'  => $instructor->user?->name ?? $instructor->name,
                        'email' => $instructor->user?->email,
                    ] : null,
                ],
                'class_group' => $classGroup,
                'modules'     => $modulesData,
                'sessions'    => CourseSessionResource::collection($courseLevelSessions),
                'materials'   => CourseMaterialResource::collection($courseLevelMaterials),
                'assignments' => StudentCourseAssignmentResource::collection($courseLevelAssignments),
            ],
        ]);
    }
}
