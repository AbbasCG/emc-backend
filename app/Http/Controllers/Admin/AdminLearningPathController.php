<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\LearningPathResource;
use App\Models\LearningPath;
use App\Models\LearningPathEnrollment;
use App\Models\LearningPathItem;
use App\Models\StudentProgress;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminLearningPathController extends Controller
{
    // ─── GET /api/admin/learning-paths ────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $query = LearningPath::with(['instructor.user'])
            ->withCount(['courses']);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('is_featured')) {
            $query->where('is_featured', filter_var($request->query('is_featured'), FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('instructor_id')) {
            $query->where('instructor_id', $request->query('instructor_id'));
        }
        if ($request->filled('search')) {
            $q = (string) $request->query('search');
            $query->where(fn ($sub) => $sub
                ->where('title', 'like', "%{$q}%")
                ->orWhere('slug', 'like', "%{$q}%")
                ->orWhere('short_description', 'like', "%{$q}%")
            );
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $paths   = $query->latest()->paginate($perPage);

        // Attach student counts via a single query
        $pathIds = $paths->pluck('id');
        $studentCounts = DB::table('learning_path_items as lpi')
            ->join('registrations as r', 'r.course_id', '=', 'lpi.course_id')
            ->whereIn('lpi.learning_path_id', $pathIds)
            ->whereIn('r.status', RegistrationStatus::activeEnrollment())
            ->groupBy('lpi.learning_path_id')
            ->selectRaw('lpi.learning_path_id, count(distinct r.user_id) as cnt')
            ->pluck('cnt', 'learning_path_id');

        $paths->each(function (LearningPath $p) use ($studentCounts) {
            $p->students_count = $studentCounts->get($p->id, 0);
        });

        return response()->json([
            'success' => true,
            'data'    => LearningPathResource::collection($paths->items()),
            'meta'    => [
                'total'        => $paths->total(),
                'current_page' => $paths->currentPage(),
                'last_page'    => $paths->lastPage(),
                'per_page'     => $paths->perPage(),
            ],
        ]);
    }

    // ─── POST /api/admin/learning-paths ───────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', LearningPath::class);

        $data = $this->validate($request);
        $data['slug']   = $data['slug'] ?? $this->uniqueSlug($data['title']);
        $data['status'] = $data['status'] ?? 'draft';

        $data['featured_image'] = $this->handleImageUpload($request, null);

        $courseIds = $data['course_ids'] ?? [];
        unset($data['course_ids']);

        DB::transaction(function () use (&$path, $data, $courseIds) {
            $path = LearningPath::create($data);
            $this->syncCourses($path, $courseIds);
        });

        AuditLogService::logModel('created', $path, null,
            ['title' => $path->title, 'status' => $path->status], null, $request);

        $path->load(['instructor.user', 'courses']);

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء المسار التعليمي بنجاح.',
            'data'    => new LearningPathResource($path),
        ], 201);
    }

    // ─── GET /api/admin/learning-paths/{learningPath} ─────────────────────────
    public function show(LearningPath $learningPath): JsonResponse
    {
        $learningPath->load(['instructor.user', 'courses', 'enrollments.user'])
            ->loadCount(['courses', 'enrollments']);

        $courseIds    = $learningPath->courses->pluck('id')->all();
        $totalCourses = count($courseIds);

        // Per-student progress across all path courses
        $progressMap = [];
        if ($courseIds) {
            $progressRows = StudentProgress::whereIn('course_id', $courseIds)
                ->whereIn('user_id', $learningPath->enrollments->pluck('user_id'))
                ->get()
                ->groupBy('user_id');

            foreach ($progressRows as $userId => $rows) {
                $progressMap[(int) $userId] = [
                    'avg_pct'   => round($rows->avg('progress_percentage') ?? 0, 1),
                    'completed' => $rows->where('status', 'completed')->count(),
                ];
            }
        }

        $students = $learningPath->enrollments->map(function (LearningPathEnrollment $e) use ($progressMap, $totalCourses) {
            $u     = $e->user;
            $prog  = $progressMap[$u->id] ?? ['avg_pct' => 0, 'completed' => 0];
            return [
                'user_id'           => $u->id,
                'name'              => $u->name,
                'email'             => $u->email,
                'phone'             => $u->phone ?? null,
                'avatar_url'        => $u->avatar_url ?? null,
                'enrollment_status' => $e->status,
                'enrolled_at'       => $e->enrolled_at?->toIso8601String(),
                'completed_at'      => $e->completed_at?->toIso8601String(),
                'progress_percentage' => $prog['avg_pct'],
                'courses_completed'   => $prog['completed'],
                'total_courses'       => $totalCourses,
            ];
        })->values()->all();

        $activeStudents    = collect($students)->where('enrollment_status', 'active')->count();
        $completedStudents = collect($students)->where('enrollment_status', 'completed')->count();

        return response()->json([
            'success' => true,
            'data'    => (new LearningPathResource($learningPath))->toArray(request()),
            'students' => $students,
            'counts' => [
                'courses'           => $totalCourses,
                'students'          => count($students),
                'active_students'   => $activeStudents,
                'completed_students' => $completedStudents,
            ],
        ]);
    }

    // ─── GET /api/admin/learning-paths/{learningPath}/students ────────────────
    public function students(Request $request, LearningPath $learningPath): JsonResponse
    {
        $enrollments = $learningPath->enrollments()
            ->with('user')
            ->get();

        $courseIds    = $learningPath->items()->pluck('course_id')->all();
        $totalCourses = count($courseIds);

        $progressMap = [];
        if ($courseIds && $enrollments->isNotEmpty()) {
            $userIds      = $enrollments->pluck('user_id')->all();
            $progressRows = StudentProgress::whereIn('course_id', $courseIds)
                ->whereIn('user_id', $userIds)
                ->get()
                ->groupBy('user_id');

            foreach ($progressRows as $userId => $rows) {
                $progressMap[(int) $userId] = [
                    'avg_pct'   => round($rows->avg('progress_percentage') ?? 0, 1),
                    'completed' => $rows->where('status', 'completed')->count(),
                ];
            }
        }

        $students = $enrollments->map(function (LearningPathEnrollment $e) use ($progressMap, $totalCourses) {
            $u    = $e->user;
            $prog = $progressMap[$u->id] ?? ['avg_pct' => 0, 'completed' => 0];
            return [
                'user_id'             => $u->id,
                'name'                => $u->name,
                'email'               => $u->email,
                'phone'               => $u->phone ?? null,
                'avatar_url'          => $u->avatar_url ?? null,
                'enrollment_status'   => $e->status,
                'enrolled_at'         => $e->enrolled_at?->toIso8601String(),
                'completed_at'        => $e->completed_at?->toIso8601String(),
                'progress_percentage' => $prog['avg_pct'],
                'courses_completed'   => $prog['completed'],
                'total_courses'       => $totalCourses,
            ];
        })->values()->all();

        return response()->json([
            'success' => true,
            'data'    => $students,
            'meta'    => [
                'total'             => count($students),
                'active_students'   => collect($students)->where('enrollment_status', 'active')->count(),
                'completed_students' => collect($students)->where('enrollment_status', 'completed')->count(),
            ],
        ]);
    }

    // ─── PUT /api/admin/learning-paths/{learningPath} ─────────────────────────
    public function update(Request $request, LearningPath $learningPath): JsonResponse
    {
        $this->authorize('update', $learningPath);

        $data = $this->validate($request, $learningPath);

        $oldSnap = ['title' => $learningPath->title, 'status' => $learningPath->status];

        if (isset($data['slug'])) {
            $data['slug'] = Str::slug($data['slug']) ?: $data['slug'];
        }

        $newImage = $this->handleImageUpload($request, $learningPath->featured_image);
        if ($newImage !== $learningPath->featured_image) {
            $data['featured_image'] = $newImage;
        }

        $courseIds = $data['course_ids'] ?? null;
        unset($data['course_ids']);

        DB::transaction(function () use ($learningPath, $data, $courseIds) {
            $learningPath->update($data);
            if ($courseIds !== null) {
                $this->syncCourses($learningPath, $courseIds);
            }
        });

        $newStatus   = $learningPath->status;
        $lpAction    = 'updated';
        if (isset($data['status']) && $data['status'] !== $oldSnap['status']) {
            if ($data['status'] === 'published') {
                $lpAction = 'PUBLISH';
            } elseif ($oldSnap['status'] === 'published') {
                $lpAction = 'UNPUBLISH';
            } else {
                $lpAction = 'status_changed';
            }
        }
        AuditLogService::logModel($lpAction, $learningPath, $oldSnap,
            ['title' => $learningPath->title, 'status' => $newStatus], null, $request);

        $learningPath->load(['instructor.user', 'courses'])->loadCount('courses');

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث المسار التعليمي بنجاح.',
            'data'    => new LearningPathResource($learningPath),
        ]);
    }

    // ─── PATCH /api/admin/learning-paths/{learningPath}/status ───────────────
    public function updateStatus(Request $request, LearningPath $learningPath): JsonResponse
    {
        $this->authorize('update', $learningPath);

        $data = $request->validate([
            'status' => 'required|in:draft,published,archived',
        ]);

        $old = $learningPath->status;
        $learningPath->update(['status' => $data['status']]);

        $action = match ($data['status']) {
            'published' => 'PUBLISH',
            'archived'  => 'ARCHIVE',
            default     => 'UNPUBLISH',
        };

        AuditLogService::logModel(
            $action, $learningPath,
            ['status' => $old],
            ['status' => $data['status']],
            null, $request
        );

        return response()->json([
            'success' => true,
            'message' => match ($data['status']) {
                'published' => 'تم نشر المسار التعليمي بنجاح.',
                'archived'  => 'تم أرشفة المسار التعليمي.',
                default     => 'تم إلغاء نشر المسار التعليمي.',
            },
            'data' => new LearningPathResource($learningPath),
        ]);
    }

    // ─── DELETE /api/admin/learning-paths/{learningPath} ──────────────────────
    public function destroy(Request $request, LearningPath $learningPath): JsonResponse
    {
        $this->authorize('delete', $learningPath);

        $snap = ['title' => $learningPath->title, 'status' => $learningPath->status];
        $id   = $learningPath->id;

        $learningPath->delete();

        AuditLogService::log('deleted', 'LearningPath', $id, $snap['title'], $snap, null, null, $request);

        return response()->json(['success' => true, 'message' => 'تم حذف المسار التعليمي بنجاح.']);
    }

    // ─── POST /api/admin/learning-paths/{learningPath}/courses ────────────────
    public function attachCourses(Request $request, LearningPath $learningPath): JsonResponse
    {
        $request->validate([
            'course_ids'   => 'required|array',
            'course_ids.*' => 'integer|exists:courses,id',
        ]);

        $this->syncCourses($learningPath, $request->input('course_ids'));

        $learningPath->load('courses');

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث قائمة الدورات بنجاح.',
            'data'    => new LearningPathResource($learningPath),
        ]);
    }

    // ─── GET /api/learning-paths (public) ─────────────────────────────────────
    public function publicIndex(Request $request): JsonResponse
    {
        $query = LearningPath::with(['instructor.user'])
            ->withCount('courses')
            ->where('status', 'published')
            ->where('enrollment_open', true);

        if ($request->filled('level')) {
            $query->where('level', $request->query('level'));
        }
        if ($request->filled('language')) {
            $query->where('language', $request->query('language'));
        }
        if ($request->filled('search')) {
            $q = (string) $request->query('search');
            $query->where(fn ($sub) => $sub
                ->where('title', 'like', "%{$q}%")
                ->orWhere('short_description', 'like', "%{$q}%")
            );
        }
        if ($request->boolean('featured')) {
            $query->where('is_featured', true);
        }

        $perPage = min((int) $request->query('per_page', 12), 50);
        $paths   = $query->latest()->paginate($perPage);

        // Attach student counts via a single aggregated query.
        $pathIds       = $paths->pluck('id');
        $studentCounts = DB::table('learning_path_enrollments')
            ->whereIn('learning_path_id', $pathIds)
            ->whereIn('status', ['active', 'enrolled', 'completed'])
            ->selectRaw('learning_path_id, count(*) as cnt')
            ->groupBy('learning_path_id')
            ->pluck('cnt', 'learning_path_id');

        $paths->each(function (LearningPath $p) use ($studentCounts) {
            $p->students_count = (int) $studentCounts->get($p->id, 0);
        });

        return response()->json([
            'success' => true,
            'data'    => LearningPathResource::collection($paths->items()),
            'meta'    => [
                'total'        => $paths->total(),
                'current_page' => $paths->currentPage(),
                'last_page'    => $paths->lastPage(),
                'per_page'     => $paths->perPage(),
            ],
        ]);
    }

    // ─── GET /api/learning-paths/{slug} (public) ──────────────────────────────
    public function publicShow(string $slug): JsonResponse
    {
        $path = LearningPath::with([
            'instructor.user',
            'courses' => fn ($q) => $q->select(
                'courses.id', 'courses.title', 'courses.slug', 'courses.short_description',
                'courses.course_image', 'courses.duration', 'courses.level', 'courses.instructor_id'
            )->with('instructor.user'),
        ])
            ->withCount('courses')
            ->where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        // Enrollment status for authenticated users (optional.sanctum applies).
        $enrollmentStatus = null;
        $userId           = Auth::id();
        if ($userId) {
            $enrollment = LearningPathEnrollment::where('learning_path_id', $path->id)
                ->where('user_id', $userId)
                ->first();

            $enrollmentStatus = $enrollment ? [
                'enrolled'     => true,
                'status'       => $enrollment->status,
                'enrolled_at'  => $enrollment->enrolled_at?->toIso8601String(),
                'completed_at' => $enrollment->completed_at?->toIso8601String(),
            ] : ['enrolled' => false];
        }

        return response()->json([
            'success'           => true,
            'data'              => new LearningPathResource($path),
            'enrollment_status' => $enrollmentStatus,
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function syncCourses(LearningPath $path, array $courseIds): void
    {
        $sync = [];
        foreach ($courseIds as $i => $id) {
            $sync[(int) $id] = ['sort_order' => $i];
        }
        $path->courses()->sync($sync);
    }

    private function validate(Request $request, ?LearningPath $path = null): array
    {
        $isUpdate = $path !== null;

        if ($request->filled('slug')) {
            $request->merge(['slug' => Str::slug((string) $request->input('slug')) ?: $request->input('slug')]);
        } elseif (! $isUpdate && $request->filled('title')) {
            $request->merge(['slug' => Str::slug((string) $request->input('title'))]);
        }

        $data = $request->validate([
            'instructor_id'   => 'nullable|exists:instructors,id',
            'title'           => [
                $isUpdate ? 'sometimes' : 'required',
                'string',
                'max:255',
                Rule::unique('learning_paths', 'title')->ignore($path?->id),
            ],
            'slug'            => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('learning_paths', 'slug')->ignore($path?->id),
            ],
            'short_description' => 'nullable|string|max:1000',
            'full_description'  => 'nullable|string',
            'duration'          => 'nullable|string|max:100',
            'duration_unit'     => 'nullable|in:days,weeks,months',
            'language'          => 'nullable|string|max:100',
            'level'             => 'nullable|string|max:100',
            'certificate_name'  => 'nullable|string|max:255',
            'price'             => 'nullable|numeric|min:0',
            'discount_price'    => 'nullable|numeric|min:0',
            'status'            => 'nullable|in:draft,published,archived',
            'is_featured'       => 'nullable|boolean',
            'enrollment_open'   => 'nullable|boolean',
            'learning_outcomes' => 'nullable|array',
            'learning_outcomes.*' => 'string|max:500',
            'requirements'      => 'nullable|array',
            'requirements.*'    => 'string|max:500',
            'course_ids'        => 'nullable|array',
            'course_ids.*'      => 'integer|exists:courses,id',
            'study_days_per_week' => 'nullable|integer|min:0|max:7',
            'study_days'        => 'nullable|array',
            'study_days.*'      => 'string|max:50',
            'study_time'        => 'nullable|string|max:100',
            'schedule_note'     => 'nullable|string|max:255',
        ]);

        unset($data['featured_image']);

        return $data;
    }

    private function handleImageUpload(Request $request, ?string $existing): ?string
    {
        if ($request->hasFile('featured_image') && $request->file('featured_image')->isValid()) {
            if ($existing && ! str_starts_with($existing, 'http')) {
                Storage::disk('public')->delete($existing);
            }
            return $request->file('featured_image')->store('learning-paths', 'public');
        }

        if ($request->filled('featured_image') && ! $request->hasFile('featured_image')) {
            return $request->input('featured_image');
        }

        return $existing;
    }

    private function uniqueSlug(string $title): string
    {
        $base  = Str::slug($title) ?: 'learning-path';
        $slug  = $base;
        $count = 1;

        while (LearningPath::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $count++;
        }

        return $slug;
    }
}
