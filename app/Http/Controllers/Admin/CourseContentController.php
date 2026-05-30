<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CourseAssignmentResource;
use App\Http\Resources\CourseMaterialResource;
use App\Http\Resources\CourseModuleResource;
use App\Http\Resources\CourseSessionResource;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseMaterial;
use App\Models\CourseModule;
use App\Models\CourseSession;
use App\Services\AuditLogService;
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
                'sessions'          => fn ($s) => $s->orderBy('sort_order')->with('materials', 'assignments'),
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
                    $course->courseSessions()->orderBy('sort_order')->get()
                ),
                'materials'   => CourseMaterialResource::collection(
                    $course->materials()->orderBy('sort_order')->get()
                ),
                'assignments' => CourseAssignmentResource::collection(
                    $course->courseAssignments()->orderBy('sort_order')->get()
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
            'status'      => 'nullable|in:active,inactive,draft',
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

    public function updateModule(Request $request, CourseModule $module): JsonResponse
    {
        $data = $request->validate([
            'title'       => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'sort_order'  => 'nullable|integer|min:0',
            'status'      => 'nullable|in:active,inactive,draft',
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

    public function destroyModule(Request $request, CourseModule $module): JsonResponse
    {
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

    public function updateCourseSession(Request $request, CourseSession $courseSession): JsonResponse
    {
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

    public function destroyCourseSession(Request $request, CourseSession $courseSession): JsonResponse
    {
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

    public function updateMaterial(Request $request, CourseMaterial $material): JsonResponse
    {
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

    public function destroyMaterial(Request $request, CourseMaterial $material): JsonResponse
    {
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

        $assignment = CourseAssignment::create($data);

        AuditLogService::logModel('created', $assignment, null, null, ['course_id' => $course->id], $request);

        return response()->json([
            'success' => true,
            'message' => 'تم الحفظ بنجاح',
            'data'    => new CourseAssignmentResource($assignment),
        ], 201);
    }

    // ─── Assignments — flat (PUT /admin/assignments/{assignment}) ─────────────

    public function updateAssignment(Request $request, CourseAssignment $courseAssignment): JsonResponse
    {
        $data = $this->validateAssignment($request, required: false);

        $old = $courseAssignment->only(['title', 'description', 'deadline', 'max_points', 'status', 'is_visible']);
        $courseAssignment->update($data);

        AuditLogService::logModel('updated', $courseAssignment->fresh(), $old, null, null, $request);

        return response()->json([
            'success' => true,
            'message' => 'تم الحفظ بنجاح',
            'data'    => new CourseAssignmentResource($courseAssignment->fresh()),
        ]);
    }

    public function destroyAssignment(Request $request, CourseAssignment $courseAssignment): JsonResponse
    {
        AuditLogService::logModel('deleted', $courseAssignment, null, null, ['course_id' => $courseAssignment->course_id], $request);
        $courseAssignment->delete();

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

        $orderedIds = $data['ids'] ?? $data['ordered_ids'] ?? $data['module_ids'] ?? [];

        foreach ($orderedIds as $position => $id) {
            CourseModule::where('id', $id)
                ->where('course_id', $course->id)
                ->update(['sort_order' => $position]);
        }

        return response()->json(['success' => true, 'message' => 'تم حفظ الترتيب']);
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

        return $data;
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

    private function validateAssignment(Request $request, bool $required = true): array
    {
        return $request->validate([
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
