<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CourseAssignmentResource;
use App\Http\Resources\CourseMaterialResource;
use App\Http\Resources\CourseModuleResource;
use App\Http\Resources\CourseSessionResource;
use App\Services\ContentReorderService;
use App\Support\CourseContentStatus;
use Illuminate\Validation\Rule;
use App\Http\Resources\LessonResource;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseMaterial;
use App\Models\CourseModule;
use App\Models\CourseSession;
use App\Models\Lesson;
use App\Services\AuditLogService;
use App\Services\CourseAssignmentBridgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CourseContentController extends Controller
{
    // ─── Full content tree ────────────────────────────────────────────────────

    public function content(Course $course): JsonResponse
    {
        $course->load([
            'instructor.user',
            'modules' => fn ($q) => $q->orderBy('sort_order')->with([
                'lessons'           => fn ($l) => $l->orderBy('sort_order'),
                'sessions'          => fn ($s) => $s->orderBy('sort_order'),
                'materials'         => fn ($m) => $m->orderBy('sort_order'),
                'courseAssignments' => fn ($a) => $a->orderBy('sort_order'),
            ]),
        ]);

        $instructor = $course->instructor;

        return response()->json([
            'success' => true,
            'data'    => [
                'course' => [
                    'id'         => $course->id,
                    'title'      => $course->title,
                    'slug'       => $course->slug,
                    'image_url'  => $course->course_image ? asset('storage/' . $course->course_image) : null,
                    'instructor' => $instructor ? [
                        'id'   => $instructor->id,
                        'name' => $instructor->user?->name ?? $instructor->name,
                    ] : null,
                ],
                'modules'     => CourseModuleResource::collection($course->modules),
                'sessions'    => CourseSessionResource::collection(
                    $course->courseSessions()->whereNull('module_id')->orderBy('sort_order')->get()
                ),
                'materials'   => CourseMaterialResource::collection(
                    $course->materials()->whereNull('module_id')->orderBy('sort_order')->get()
                ),
                'assignments' => CourseAssignmentResource::collection(
                    $course->courseAssignments()->whereNull('module_id')->orderBy('sort_order')->get()
                ),
            ],
        ]);
    }

    // ─── Modules — nested (POST /admin/courses/{course}/modules) ─────────────

    public function storeModule(Request $request, Course $course): JsonResponse
    {
        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'sort_order'  => 'nullable|integer|min:0',
            'status'      => ['nullable', Rule::in(CourseContentStatus::ALL)],
            'is_active'   => 'nullable|boolean',
        ]);

        $data['course_id']  = $course->id;
        $data['sort_order'] = $data['sort_order']
            ?? (int) CourseModule::where('course_id', $course->id)->max('sort_order') + 1;

        $module = CourseModule::create($data);

        AuditLogService::logModel('created', $module, null, null, ['course_id' => $course->id], $request);

        return response()->json([
            'success' => true,
            'message' => 'تم الحفظ بنجاح',
            'data'    => new CourseModuleResource($module),
        ], 201);
    }

    // ─── Modules — flat (PUT /admin/modules/{module}) ─────────────────────────

    public function updateModule(Request $request, Course $course, CourseModule $module): JsonResponse
    {
        $this->enforceCourseScope($course, $module->course_id);

        $data = $request->validate([
            'title'       => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'sort_order'  => 'nullable|integer|min:0',
            'status'      => ['nullable', Rule::in(CourseContentStatus::ALL)],
            'is_active'   => 'nullable|boolean',
        ]);

        $old = $module->only(['title', 'description', 'sort_order', 'status', 'is_active']);
        $module->update($data);

        AuditLogService::logModel('updated', $module->fresh(), $old, null, null, $request);

        return response()->json([
            'success' => true,
            'message' => 'تم الحفظ بنجاح',
            'data'    => new CourseModuleResource($module->fresh()),
        ]);
    }

    public function destroyModule(Request $request, Course $course, CourseModule $module): JsonResponse
    {
        $this->enforceCourseScope($course, $module->course_id);

        AuditLogService::logModel('deleted', $module, null, null, ['course_id' => $module->course_id], $request);
        $module->delete();

        return response()->json(['success' => true, 'message' => 'تم الحذف بنجاح']);
    }

    // ─── Sessions — nested (POST /admin/courses/{course}/sessions) ───────────

    public function storeCourseSession(Request $request, Course $course): JsonResponse
    {
        $data = $this->validateSession($request);

        $data['course_id']  = $course->id;
        $data['sort_order'] = $data['sort_order']
            ?? (int) CourseSession::where('course_id', $course->id)->max('sort_order') + 1;

        $session = CourseSession::create($data);

        AuditLogService::logModel('created', $session, null, null, ['course_id' => $course->id], $request);

        return response()->json([
            'success' => true,
            'message' => 'تم الحفظ بنجاح',
            'data'    => new CourseSessionResource($session),
        ], 201);
    }

    // ─── Sessions — flat (PUT /admin/sessions/{session}) ─────────────────────

    public function updateCourseSession(Request $request, Course $course, CourseSession $courseSession): JsonResponse
    {
        $this->enforceCourseScope($course, $courseSession->course_id);

        $data = $this->validateSession($request, required: false);

        $old = $courseSession->only(['title', 'description', 'status', 'start_at', 'end_at', 'location']);
        $courseSession->update($data);

        AuditLogService::logModel('updated', $courseSession->fresh(), $old, null, null, $request);

        return response()->json([
            'success' => true,
            'message' => 'تم الحفظ بنجاح',
            'data'    => new CourseSessionResource($courseSession->fresh()),
        ]);
    }

    public function destroyCourseSession(Request $request, Course $course, CourseSession $courseSession): JsonResponse
    {
        $this->enforceCourseScope($course, $courseSession->course_id);

        AuditLogService::logModel('deleted', $courseSession, null, null, ['course_id' => $courseSession->course_id], $request);
        $courseSession->delete();

        return response()->json(['success' => true, 'message' => 'تم الحذف بنجاح']);
    }

    // ─── Materials — nested (POST /admin/courses/{course}/materials) ──────────

    public function storeMaterial(Request $request, Course $course): JsonResponse
    {
        $data = $this->validateMaterial($request);

        if ($request->hasFile('file')) {
            $path              = $request->file('file')->store('course-materials', 'local');
            $data['file_path'] = $path;
            $data['file_url']  = null;
        }

        $data['course_id']  = $course->id;
        $data['sort_order'] = $data['sort_order']
            ?? (int) CourseMaterial::where('course_id', $course->id)->max('sort_order') + 1;
        unset($data['file']);

        $material = CourseMaterial::create($data);

        AuditLogService::logModel('created', $material, null, null, ['course_id' => $course->id], $request);

        return response()->json([
            'success' => true,
            'message' => 'تم الحفظ بنجاح',
            'data'    => new CourseMaterialResource($material),
        ], 201);
    }

    // ─── Materials — flat (PUT /admin/materials/{material}) ───────────────────

    public function updateMaterial(Request $request, Course $course, CourseMaterial $material): JsonResponse
    {
        $this->enforceCourseScope($course, $material->course_id);

        $data = $this->validateMaterial($request, required: false);

        if ($request->hasFile('file')) {
            if ($material->file_path) {
                $this->deleteFile($material->file_path);
            }
            $path              = $request->file('file')->store('course-materials', 'local');
            $data['file_path'] = $path;
            $data['file_url']  = null;
        }

        $old = $material->only(['title', 'description', 'type', 'file_path', 'external_url', 'is_visible']);
        unset($data['file']);
        $material->update($data);

        AuditLogService::logModel('updated', $material->fresh(), $old, null, null, $request);

        return response()->json([
            'success' => true,
            'message' => 'تم الحفظ بنجاح',
            'data'    => new CourseMaterialResource($material->fresh()),
        ]);
    }

    public function destroyMaterial(Request $request, Course $course, CourseMaterial $material): JsonResponse
    {
        $this->enforceCourseScope($course, $material->course_id);

        if ($material->file_path) {
            $this->deleteFile($material->file_path);
        }

        AuditLogService::logModel('deleted', $material, null, null, ['course_id' => $material->course_id], $request);
        $material->delete();

        return response()->json(['success' => true, 'message' => 'تم الحذف بنجاح']);
    }

    // ─── Assignments — nested (POST /admin/courses/{course}/assignments) ───────

    public function storeAssignment(Request $request, Course $course): JsonResponse
    {
        $data = $this->validateAssignment($request);

        $data['course_id']  = $course->id;
        $data['sort_order'] = $data['sort_order']
            ?? (int) CourseAssignment::where('course_id', $course->id)->max('sort_order') + 1;
        $data['status']     = $data['status'] ?? 'published';

        $assignment = CourseAssignment::create($data);
        app(CourseAssignmentBridgeService::class)->ensureLmsAssignment($assignment);

        AuditLogService::logModel('created', $assignment, null, null, ['course_id' => $course->id], $request);

        return response()->json([
            'success' => true,
            'message' => 'تم الحفظ بنجاح',
            'data'    => new CourseAssignmentResource($assignment),
        ], 201);
    }

    // ─── Assignments — flat (PUT /admin/assignments/{assignment}) ─────────────

    public function updateAssignment(Request $request, Course $course, CourseAssignment $courseAssignment): JsonResponse
    {
        $this->enforceCourseScope($course, $courseAssignment->course_id);

        $data = $this->validateAssignment($request, required: false);

        $old = $courseAssignment->only(['title', 'description', 'deadline', 'max_points', 'status', 'is_visible']);
        $courseAssignment->update($data);
        app(CourseAssignmentBridgeService::class)->ensureLmsAssignment($courseAssignment->fresh());

        AuditLogService::logModel('updated', $courseAssignment->fresh(), $old, null, null, $request);

        return response()->json([
            'success' => true,
            'message' => 'تم الحفظ بنجاح',
            'data'    => new CourseAssignmentResource($courseAssignment->fresh()),
        ]);
    }

    public function destroyAssignment(Request $request, Course $course, CourseAssignment $courseAssignment): JsonResponse
    {
        $this->enforceCourseScope($course, $courseAssignment->course_id);

        AuditLogService::logModel('deleted', $courseAssignment, null, null, ['course_id' => $courseAssignment->course_id], $request);

        // Also delete the linked LMS Assignment row so it no longer appears in global admin.
        \App\Models\Assignment::where('course_assignment_id', $courseAssignment->id)->delete();

        $courseAssignment->delete();

        return response()->json(['success' => true, 'message' => 'تم الحذف بنجاح']);
    }

    // ─── Lessons — nested (POST /admin/courses/{course}/lessons) ─────────────

    public function storeLesson(Request $request, Course $course): JsonResponse
    {
        $data = $this->validateLesson($request);

        $moduleId = (int) ($data['module_id'] ?? 0);
        abort_if(
            ! CourseModule::where('id', $moduleId)->where('course_id', $course->id)->exists(),
            422,
            'الوحدة المحددة لا تنتمي إلى هذه الدورة.'
        );

        $data['sort_order'] = $data['sort_order']
            ?? (int) Lesson::where('module_id', $moduleId)->max('sort_order') + 1;
        $data['status'] = $data['status'] ?? 'active';

        $lesson = Lesson::create($data);
        AuditLogService::logModel('created', $lesson, null, null, ['course_id' => $course->id], $request);

        return response()->json([
            'success' => true,
            'message' => 'تم الحفظ بنجاح',
            'data'    => new LessonResource($lesson),
        ], 201);
    }

    public function updateLesson(Request $request, Course $course, Lesson $lesson): JsonResponse
    {
        $this->enforceCourseScope($course, $lesson->module?->course_id);

        $data = $this->validateLesson($request, required: false);
        unset($data['module_id']);

        $old = $lesson->only(['title', 'description', 'video_url', 'duration_minutes', 'sort_order', 'status']);
        $lesson->update($data);
        AuditLogService::logModel('updated', $lesson->fresh(), $old, null, null, $request);

        return response()->json([
            'success' => true,
            'message' => 'تم الحفظ بنجاح',
            'data'    => new LessonResource($lesson->fresh()),
        ]);
    }

    public function destroyLesson(Request $request, Course $course, Lesson $lesson): JsonResponse
    {
        $this->enforceCourseScope($course, $lesson->module?->course_id);

        AuditLogService::logModel('deleted', $lesson, null, null, ['module_id' => $lesson->module_id], $request);
        $lesson->delete();

        return response()->json(['success' => true, 'message' => 'تم الحذف بنجاح']);
    }

    // ─── Module reorder ───────────────────────────────────────────────────────

    public function reorderModules(Request $request, Course $course): JsonResponse
    {
        $data = $request->validate([
            'ids'         => 'sometimes|array',
            'ordered_ids' => 'sometimes|array',
            'module_ids'  => 'sometimes|array',
        ]);

        $orderedIds = array_map('intval', $data['ids'] ?? $data['ordered_ids'] ?? $data['module_ids'] ?? []);

        app(ContentReorderService::class)->reorder(
            CourseModule::query()->where('course_id', $course->id),
            $orderedIds
        );

        return response()->json(['success' => true, 'message' => 'تم حفظ الترتيب']);
    }

    /**
     * POST /admin/modules/{module}/lessons/reorder
     */
    public function reorderLessons(Request $request, CourseModule $module): JsonResponse
    {
        $data = $request->validate([
            'ids'         => 'sometimes|array',
            'ordered_ids' => 'sometimes|array',
            'lesson_ids'  => 'sometimes|array',
        ]);

        $orderedIds = array_map('intval', $data['ids'] ?? $data['ordered_ids'] ?? $data['lesson_ids'] ?? []);

        app(ContentReorderService::class)->reorder(
            Lesson::query()->where('module_id', $module->id),
            $orderedIds
        );

        return response()->json(['success' => true, 'message' => 'تم حفظ الترتيب']);
    }

    // ─── Nested-route ownership guard ─────────────────────────────────────────

    /**
     * For nested routes (admin/courses/{course}/…/{id}), verify the resolved
     * resource actually belongs to the {course} in the URL.
     */
    private function enforceCourseScope(Course $course, ?int $actualCourseId): void
    {
        abort_if((int) $actualCourseId !== $course->id, 404, 'Resource not found in this course.');
    }

    // ─── Shared validation helpers ────────────────────────────────────────────

    private function validateSession(Request $request, bool $required = true): array
    {
        $data = $request->validate([
            'module_id'     => 'nullable|exists:course_modules,id',
            'title'         => ($required ? 'required' : 'sometimes') . '|string|max:255',
            'description'   => 'nullable|string',
            'start_at'      => 'nullable|date',
            'end_at'        => 'nullable|date',
            'location_type' => 'nullable|in:online,offline,hybrid',
            'location'      => 'nullable|string|max:255',
            'meeting_link'  => 'nullable|url|max:500',
            'meeting_url'   => 'nullable|url|max:500',
            'sort_order'    => 'nullable|integer|min:0',
            'status'        => 'nullable|in:draft,published,archived,scheduled,live,completed,cancelled',
            'is_visible'    => 'nullable|boolean',
        ]);

        if (!empty($data['meeting_url']) && empty($data['meeting_link'])) {
            $data['meeting_link'] = $data['meeting_url'];
        }
        unset($data['meeting_url']);

        $this->convertAmsterdamFieldsToUtc($data, ['start_at', 'end_at']);

        return $data;
    }

    /**
     * Convert Amsterdam local time → UTC before storing.
     * The frontend datetime-local input sends bare "YYYY-MM-DDTHH:mm" which
     * represents Europe/Amsterdam local time. Carbon without a TZ hint would
     * treat it as UTC (wrong). Parsing with the Amsterdam context gives the
     * correct UTC value (e.g. Amsterdam 23:00 CEST → UTC 21:00).
     */
    private function convertAmsterdamFieldsToUtc(array &$data, array $fields): void
    {
        foreach ($fields as $field) {
            if (!empty($data[$field])) {
                try {
                    $data[$field] = \Carbon\Carbon::parse($data[$field], 'Europe/Amsterdam')
                        ->utc()
                        ->toDateTimeString();
                } catch (\Throwable) {
                    // leave original value; database cast will handle it
                }
            }
        }
    }

    private function validateMaterial(Request $request, bool $required = true): array
    {
        $data = $request->validate([
            'module_id'         => 'nullable|exists:course_modules,id',
            'course_session_id' => 'nullable|exists:course_sessions,id',
            'title'             => ($required ? 'required' : 'sometimes') . '|string|max:255',
            'description'       => 'nullable|string',
            'type'              => 'nullable|in:pdf,video,link,file,image,audio,other,slides,document',
            'kind'              => 'nullable|in:pdf,video,link,file,image,audio,other,slides,document',
            'external_url'      => 'nullable|url|max:500',
            'sort_order'        => 'nullable|integer|min:0',
            'is_visible'        => 'nullable|boolean',
            'visibility'        => 'nullable|string',
            'file'              => [
                'nullable', 'file', 'max:51200',
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,mp4,mov,avi,mp3,wav,jpg,jpeg,png,webp,gif,zip',
                'mimetypes:application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.presentationml.presentation,video/mp4,video/quicktime,video/x-msvideo,audio/mpeg,audio/wav,image/jpeg,image/png,image/webp,image/gif,application/zip',
            ],
        ]);

        if (!empty($data['kind']) && empty($data['type'])) {
            $data['type'] = $data['kind'];
        }
        unset($data['kind']);

        if (array_key_exists('visibility', $data) && !array_key_exists('is_visible', $data)) {
            $data['is_visible'] = !in_array($data['visibility'], ['private', 'hidden', 'none'], true);
        }
        unset($data['visibility']);

        return $data;
    }

    private function validateLesson(Request $request, bool $required = true): array
    {
        return $request->validate([
            'module_id'        => ($required ? 'required' : 'sometimes') . '|exists:course_modules,id',
            'title'            => ($required ? 'required' : 'sometimes') . '|string|max:255',
            'description'      => 'nullable|string',
            'content'          => 'nullable|string',
            'video_url'        => 'nullable|url|max:500',
            'duration_minutes' => 'nullable|integer|min:1',
            'sort_order'       => 'nullable|integer|min:0',
            'status'           => ['nullable', Rule::in(CourseContentStatus::ALL)],
        ]);
    }

    private function validateAssignment(Request $request, bool $required = true): array
    {
        $data = $request->validate([
            'module_id'         => 'nullable|exists:course_modules,id',
            'course_session_id' => 'nullable|exists:course_sessions,id',
            'title'             => ($required ? 'required' : 'sometimes') . '|string|max:255',
            'description'       => 'nullable|string',
            'instructions'      => 'nullable|string',
            'deadline'          => 'nullable|date',
            'max_points'        => 'nullable|integer|min:1',
            'submission_type'   => 'nullable|in:file,text,url,any,both',
            'is_required'       => 'nullable|boolean',
            'is_visible'        => 'nullable|boolean',
            'status'            => 'nullable|in:draft,published',
            'sort_order'        => 'nullable|integer|min:0',
        ]);

        $this->convertAmsterdamFieldsToUtc($data, ['deadline']);

        return $data;
    }

    // Try local disk first, fall back to public (for pre-migration files)
    private function deleteFile(string $path): void
    {
        if (Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        } elseif (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
