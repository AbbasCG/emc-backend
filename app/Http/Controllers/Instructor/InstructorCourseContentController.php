<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Resources\CourseAssignmentResource;
use App\Http\Resources\CourseMaterialResource;
use App\Http\Resources\CourseModuleResource;
use App\Http\Resources\CourseSessionResource;
use App\Http\Resources\LessonResource;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseMaterial;
use App\Models\CourseModule;
use App\Models\CourseSession;
use App\Models\Lesson;
use App\Services\CourseAssignmentBridgeService;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class InstructorCourseContentController extends Controller
{
    // ─── Individual content-type GET indexes ─────────────────────────────────

    /**
     * GET /api/instructor/courses/{course}/modules
     */
    public function indexModules(Request $request, Course $course): JsonResponse
    {
        $this->authorise($request, $course);

        $course->load([
            'modules' => fn ($q) => $q->orderBy('sort_order')->with([
                'sessions'          => fn ($s) => $s->orderBy('sort_order'),
                'materials'         => fn ($m) => $m->orderBy('sort_order'),
                'courseAssignments' => fn ($a) => $a->orderBy('sort_order'),
                'lessons',
            ]),
        ]);

        return response()->json([
            'success' => true,
            'data'    => CourseModuleResource::collection($course->modules),
        ]);
    }

    /**
     * GET /api/instructor/courses/{course}/sessions
     */
    public function indexSessions(Request $request, Course $course): JsonResponse
    {
        $this->authorise($request, $course);

        return response()->json([
            'success' => true,
            'data'    => CourseSessionResource::collection(
                $course->courseSessions()->orderBy('sort_order')->get()
            ),
        ]);
    }

    /**
     * GET /api/instructor/courses/{course}/materials
     */
    public function indexMaterials(Request $request, Course $course): JsonResponse
    {
        $this->authorise($request, $course);

        return response()->json([
            'success' => true,
            'data'    => CourseMaterialResource::collection(
                $course->materials()->orderBy('sort_order')->get()
            ),
        ]);
    }

    /**
     * GET /api/instructor/courses/{course}/assignments
     */
    public function indexAssignments(Request $request, Course $course): JsonResponse
    {
        $this->authorise($request, $course);

        return response()->json([
            'success' => true,
            'data'    => CourseAssignmentResource::collection(
                $course->courseAssignments()->orderBy('sort_order')->get()
            ),
        ]);
    }

    // ─── Full content tree ────────────────────────────────────────────────────

    public function content(Request $request, Course $course): JsonResponse
    {
        $this->authorise($request, $course);

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

    // ─── Modules ─────────────────────────────────────────────────────────────

    public function storeModule(Request $request, Course $course): JsonResponse
    {
        $this->authorise($request, $course);

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

        return response()->json([
            'success' => true,
            'message' => 'تم الحفظ بنجاح',
            'data'    => new CourseModuleResource($module),
        ], 201);
    }

    /**
     * PUT /instructor/courses/{course}/modules/{module}
     * PUT /instructor/modules/{module}
     *
     * Uses $request->route() to resolve parameters so that both nested and flat
     * routes work without positional-injection issues for primitive int params.
     */
    public function updateModule(Request $request): JsonResponse
    {
        $module = CourseModule::findOrFail($this->routeId($request, 'module'));
        $this->enforceCourseScope($request, $module->course_id);
        $this->authoriseByCourse($request, $module->course_id);

        $data = $request->validate([
            'title'       => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'sort_order'  => 'nullable|integer|min:0',
            'status'      => 'nullable|in:active,inactive,draft',
            'is_active'   => 'nullable|boolean',
        ]);

        $module->update($data);

        return response()->json([
            'success' => true,
            'message' => 'تم الحفظ بنجاح',
            'data'    => new CourseModuleResource($module->fresh()),
        ]);
    }

    public function destroyModule(Request $request): JsonResponse
    {
        $mod = CourseModule::findOrFail($this->routeId($request, 'module'));
        $this->enforceCourseScope($request, $mod->course_id);
        $this->authoriseByCourse($request, $mod->course_id);
        $mod->delete();

        return response()->json(['success' => true, 'message' => 'تم الحذف بنجاح']);
    }

    // ─── Sessions ────────────────────────────────────────────────────────────

    public function storeCourseSession(Request $request, Course $course): JsonResponse
    {
        $this->authorise($request, $course);

        $data               = $this->validateSession($request);
        $data['course_id']  = $course->id;
        $data['sort_order'] = $data['sort_order']
            ?? (int) CourseSession::where('course_id', $course->id)->max('sort_order') + 1;

        $session = CourseSession::create($data);

        // Notify enrolled students about the new session.
        NotificationService::sessionScheduled(
            courseId:     $course->id,
            courseTitle:  $course->title,
            sessionTitle: $session->title,
            startsAt:     $session->start_at?->toIso8601String(),
            isUpdate:     false,
        );

        return response()->json([
            'success' => true,
            'message' => 'تم الحفظ بنجاح',
            'data'    => new CourseSessionResource($session),
        ], 201);
    }

    /**
     * PUT /instructor/courses/{course}/sessions/{courseSession}
     * PUT /instructor/sessions/{courseSession}
     */
    public function updateCourseSession(Request $request): JsonResponse
    {
        $session = CourseSession::findOrFail($this->routeId($request, 'courseSession'));
        $this->enforceCourseScope($request, $session->course_id);
        $this->authoriseByCourse($request, $session->course_id);

        $data = $this->validateSession($request, required: false);
        $session->update($data);

        $fresh = $session->fresh();

        // Notify enrolled students about the session update.
        $courseTitle = Course::where('id', $session->course_id)->value('title') ?? 'الدورة';
        NotificationService::sessionScheduled(
            courseId:     $session->course_id,
            courseTitle:  $courseTitle,
            sessionTitle: $fresh->title,
            startsAt:     $fresh->start_at?->toIso8601String(),
            isUpdate:     true,
        );

        return response()->json([
            'success' => true,
            'message' => 'تم الحفظ بنجاح',
            'data'    => new CourseSessionResource($fresh),
        ]);
    }

    public function destroyCourseSession(Request $request): JsonResponse
    {
        $session = CourseSession::findOrFail($this->routeId($request, 'courseSession'));
        $this->enforceCourseScope($request, $session->course_id);
        $this->authoriseByCourse($request, $session->course_id);
        $session->delete();

        return response()->json(['success' => true, 'message' => 'تم الحذف بنجاح']);
    }

    // ─── Materials ────────────────────────────────────────────────────────────

    public function storeMaterial(Request $request, Course $course): JsonResponse
    {
        $this->authorise($request, $course);

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

        return response()->json([
            'success' => true,
            'message' => 'تم الحفظ بنجاح',
            'data'    => new CourseMaterialResource($material),
        ], 201);
    }

    /**
     * PUT /instructor/courses/{course}/materials/{material}
     * PUT /instructor/materials/{material}
     */
    public function updateMaterial(Request $request): JsonResponse
    {
        $mat = CourseMaterial::findOrFail($this->routeId($request, 'material'));
        $this->enforceCourseScope($request, $mat->course_id);
        $this->authoriseByCourse($request, $mat->course_id);

        $data = $this->validateMaterial($request, required: false);

        if ($request->hasFile('file')) {
            if ($mat->file_path) {
                $this->deleteFile($mat->file_path);
            }
            $path              = $request->file('file')->store('course-materials', 'local');
            $data['file_path'] = $path;
            $data['file_url']  = null;
        }

        unset($data['file']);
        $mat->update($data);

        return response()->json([
            'success' => true,
            'message' => 'تم الحفظ بنجاح',
            'data'    => new CourseMaterialResource($mat->fresh()),
        ]);
    }

    public function destroyMaterial(Request $request): JsonResponse
    {
        $mat = CourseMaterial::findOrFail($this->routeId($request, 'material'));
        $this->enforceCourseScope($request, $mat->course_id);
        $this->authoriseByCourse($request, $mat->course_id);

        if ($mat->file_path) {
            $this->deleteFile($mat->file_path);
        }
        $mat->delete();

        return response()->json(['success' => true, 'message' => 'تم الحذف بنجاح']);
    }

    // ─── Assignments ──────────────────────────────────────────────────────────

    public function storeAssignment(Request $request, Course $course): JsonResponse
    {
        $this->authorise($request, $course);

        $data               = $this->validateAssignment($request);
        $data['course_id']  = $course->id;
        $data['sort_order'] = $data['sort_order']
            ?? (int) CourseAssignment::where('course_id', $course->id)->max('sort_order') + 1;
        // Default to published so enrolled students can see newly created assignments immediately.
        $data['status']     = $data['status'] ?? 'published';
        $data['is_visible'] = $data['is_visible'] ?? true;

        $assignment = CourseAssignment::create($data);
        app(CourseAssignmentBridgeService::class)->ensureLmsAssignment($assignment);

        // Notify enrolled students about the new assignment.
        NotificationService::assignmentCreatedForStudents(
            courseId:          $course->id,
            courseTitle:       $course->title,
            assignmentTitle:   $assignment->title,
            deadline:          isset($assignment->deadline) ? $assignment->deadline->toDateString() : null,
            courseAssignmentId: $assignment->id,
        );

        return response()->json([
            'success' => true,
            'message' => 'تم الحفظ بنجاح',
            'data'    => new CourseAssignmentResource($assignment),
        ], 201);
    }

    /**
     * PUT /instructor/courses/{course}/assignments/{courseAssignment}
     * PUT /instructor/assignments/{courseAssignment}
     */
    public function updateAssignment(Request $request): JsonResponse
    {
        $assignment = CourseAssignment::findOrFail($this->routeId($request, 'courseAssignment'));
        $this->enforceCourseScope($request, $assignment->course_id);
        $this->authoriseByCourse($request, $assignment->course_id);

        $data = $this->validateAssignment($request, required: false);
        $assignment->update($data);
        app(CourseAssignmentBridgeService::class)->ensureLmsAssignment($assignment->fresh());

        return response()->json([
            'success' => true,
            'message' => 'تم الحفظ بنجاح',
            'data'    => new CourseAssignmentResource($assignment->fresh()),
        ]);
    }

    public function destroyAssignment(Request $request): JsonResponse
    {
        $assignment = CourseAssignment::findOrFail($this->routeId($request, 'courseAssignment'));
        $this->enforceCourseScope($request, $assignment->course_id);
        $this->authoriseByCourse($request, $assignment->course_id);
        $assignment->delete();

        return response()->json(['success' => true, 'message' => 'تم الحذف بنجاح']);
    }

    // ─── Lessons ─────────────────────────────────────────────────────────────

    public function storeLesson(Request $request, Course $course): JsonResponse
    {
        $this->authorise($request, $course);

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

        return response()->json([
            'success' => true,
            'message' => 'تم الحفظ بنجاح',
            'data'    => new LessonResource($lesson),
        ], 201);
    }

    public function updateLesson(Request $request): JsonResponse
    {
        $lesson = Lesson::with('module')->findOrFail($this->routeId($request, 'lesson'));
        $this->enforceCourseScope($request, $lesson->module?->course_id);
        $this->authoriseByCourse($request, $lesson->module?->course_id);

        $data = $this->validateLesson($request, required: false);
        unset($data['module_id']); // module cannot be changed
        $lesson->update($data);

        return response()->json([
            'success' => true,
            'message' => 'تم الحفظ بنجاح',
            'data'    => new LessonResource($lesson->fresh()),
        ]);
    }

    public function destroyLesson(Request $request): JsonResponse
    {
        $lesson = Lesson::with('module')->findOrFail($this->routeId($request, 'lesson'));
        $this->enforceCourseScope($request, $lesson->module?->course_id);
        $this->authoriseByCourse($request, $lesson->module?->course_id);
        $lesson->delete();

        return response()->json(['success' => true, 'message' => 'تم الحذف بنجاح']);
    }

    // ─── Module reorder ───────────────────────────────────────────────────────

    public function reorderModules(Request $request, Course $course): JsonResponse
    {
        $this->authorise($request, $course);

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

    // ─── Route parameter resolution ───────────────────────────────────────────

    /**
     * Resolve a route parameter to an integer ID.
     *
     * Works for:
     *  - Flat routes (/modules/{module}) — parameter = raw string from URL
     *  - Nested routes (/courses/{course}/modules/{module}) — parameter may be
     *    a raw string OR an already-bound Eloquent model (from AppServiceProvider bindings).
     *
     * By reading via $request->route() instead of relying on method type-hints,
     * we completely bypass Laravel's positional injection for primitive parameters.
     */
    private function routeId(Request $request, string $param): int
    {
        $raw = $request->route($param);

        if ($raw instanceof Model) {
            return (int) $raw->getKey();
        }

        return (int) $raw;
    }

    /**
     * For nested routes, verify the resolved resource belongs to the {course}
     * route parameter (if present).  Flat routes skip this check.
     */
    private function enforceCourseScope(Request $request, ?int $actualCourseId): void
    {
        $rawCourse = $request->route('course');

        if ($rawCourse === null) {
            return; // flat route — no course scope to enforce
        }

        $scopeCourseId = $rawCourse instanceof Course
            ? $rawCourse->id
            : (int) $rawCourse;

        abort_if((int) $actualCourseId !== $scopeCourseId, 404, 'Resource not found in this course.');
    }

    // ─── Ownership helpers ────────────────────────────────────────────────────

    private function authorise(Request $request, Course $course): void
    {
        $profile = $request->user()->instructorProfile;

        if (in_array($request->user()->role, ['admin', 'super_admin'], true)) {
            return;
        }

        abort_if(
            ! $profile || $course->instructor_id !== $profile->id,
            403,
            'You can only manage your own courses.'
        );
    }

    private function authoriseByCourse(Request $request, ?int $courseId): void
    {
        if (in_array($request->user()->role, ['admin', 'super_admin'], true)) {
            return;
        }

        $profile = $request->user()->instructorProfile;

        abort_if(! $courseId, 403, 'Resource has no associated course.');

        $ownsCourse = Course::where('id', $courseId)
            ->where('instructor_id', $profile?->id)
            ->exists();

        abort_if(! $ownsCourse, 403, 'You can only manage your own courses.');
    }

    private function deleteFile(string $path): void
    {
        if (Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        } elseif (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
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
            'status'           => 'nullable|in:active,inactive,draft',
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
            // Legacy aliases sent by older frontend clients
            'visible'           => 'nullable|boolean',
            'required'          => 'nullable|boolean',
            'status'            => 'nullable|in:draft,published',
            'sort_order'        => 'nullable|integer|min:0',
        ]);

        // Map legacy keys to canonical column names
        if (!array_key_exists('is_visible', $data) && array_key_exists('visible', $data)) {
            $data['is_visible'] = $data['visible'];
        }
        if (!array_key_exists('is_required', $data) && array_key_exists('required', $data)) {
            $data['is_required'] = $data['required'];
        }
        unset($data['visible'], $data['required']);

        return $data;
    }
}
