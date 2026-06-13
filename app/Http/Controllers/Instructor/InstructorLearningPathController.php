<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Resources\LearningPathResource;
use App\Models\LearningPath;
use App\Models\LearningPathEnrollment;
use App\Models\LearningPathItem;
use App\Models\LmsSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InstructorLearningPathController extends Controller
{
    // ─── GET /api/instructor/learning-paths ───────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $instructorId = Auth::user()?->instructorProfile?->id;

        if (! $instructorId) {
            return response()->json(['success' => false, 'message' => 'غير مرتبط بمدرب.'], 403);
        }

        $query = LearningPath::with(['instructor.user'])
            ->withCount(['courses'])
            ->where(function ($q) use ($instructorId) {
                // Path is accessible if: the instructor is the named path instructor
                // OR the instructor teaches at least one of the included courses.
                $q->where('instructor_id', $instructorId)
                  ->orWhereHas('courses', function ($cq) use ($instructorId) {
                      $cq->where('instructor_id', $instructorId);
                  });
            });

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where('title', 'like', "%{$search}%");
        }

        $paths = $query->latest()->get();

        // Attach enrollment counts in bulk (one query, not N+1).
        $pathIds      = $paths->pluck('id');
        $enrollCounts = LearningPathEnrollment::whereIn('learning_path_id', $pathIds)
            ->groupBy('learning_path_id')
            ->selectRaw('learning_path_id, count(*) as cnt')
            ->pluck('cnt', 'learning_path_id');

        $paths->each(function (LearningPath $p) use ($enrollCounts) {
            $p->students_count = $enrollCounts->get($p->id, 0);
        });

        return response()->json([
            'success' => true,
            'data'    => LearningPathResource::collection($paths),
        ]);
    }

    // ─── GET /api/instructor/learning-paths/{id} ──────────────────────────────
    public function show(int $id): JsonResponse
    {
        $path = $this->resolveAssigned($id);

        // Load courses with per-course counts so the frontend can display stats.
        $path->load(['instructor.user', 'courses' => function ($q) {
            $q->withCount(['sessions', 'materials', 'assignments']);
        }])->loadCount('courses');

        $path->students_count = LearningPathEnrollment::where('learning_path_id', $id)->count();

        // Build enriched course list (overrides LearningPathResource default which lacks counts).
        $courses = $path->courses
            ->sortBy(fn ($c) => $c->pivot->sort_order ?? 0)
            ->values()
            ->map(fn ($c) => [
                'id'                => $c->id,
                'title'             => $c->title,
                'slug'              => $c->slug,
                'short_description' => $c->short_description,
                'image_url'         => $this->imageUrl($c->course_image),
                'duration'          => $c->duration,
                'level'             => $c->level,
                'sort_order'        => $c->pivot->sort_order ?? 0,
                'sessions_count'    => $c->sessions_count ?? 0,
                'materials_count'   => $c->materials_count ?? 0,
                'assignments_count' => $c->assignments_count ?? 0,
            ]);

        // Indicate whether the current user is the path's primary instructor
        // (vs. being an instructor of one of the included courses).
        $currentInstructorId = Auth::user()?->instructorProfile?->id;
        $isPathInstructor    = $path->instructor_id === $currentInstructorId;

        $data             = (new LearningPathResource($path))->toArray(request());
        $data['courses']  = $courses;
        $data['is_path_instructor'] = $isPathInstructor;

        return response()->json(['success' => true, 'data' => $data]);
    }

    // ─── GET /api/instructor/learning-paths/{id}/sessions ─────────────────────
    public function sessions(int $id): JsonResponse
    {
        $path = $this->resolveAssigned($id);

        $courseIds = $path->items()->pluck('course_id');

        $sessions = LmsSession::whereIn('course_id', $courseIds)
            ->with('course:id,title')
            ->orderBy('session_date')
            ->orderBy('start_time')
            ->get()
            ->map(fn ($s) => [
                'id'            => $s->id,
                'title'         => $s->title,
                'description'   => $s->description,
                'course_id'     => $s->course_id,
                'course_title'  => $s->course?->title,
                'session_date'  => $s->session_date?->format('Y-m-d'),
                'start_time'    => $s->start_time,
                'end_time'      => $s->end_time,
                'meeting_url'   => $s->meeting_url,
                'recording_url' => $s->recording_url,
                'status'        => $s->status,
                'location'      => $s->location,
            ]);

        return response()->json(['success' => true, 'data' => $sessions]);
    }

    // ─── PUT /api/instructor/learning-paths/{id}/curriculum ───────────────────
    public function updateCurriculum(Request $request, int $id): JsonResponse
    {
        $path = $this->resolveAssigned($id);

        $request->validate([
            'course_ids'   => 'required|array',
            'course_ids.*' => 'integer|exists:courses,id',
        ]);

        $sync = [];
        foreach ($request->input('course_ids') as $i => $courseId) {
            $sync[(int) $courseId] = ['sort_order' => $i];
        }

        $path->courses()->sync($sync);
        $path->load('courses')->loadCount('courses');

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث المنهج بنجاح.',
            'data'    => new LearningPathResource($path),
        ]);
    }

    // ─── POST /api/instructor/learning-paths/{id}/items ───────────────────────
    public function addItem(Request $request, int $id): JsonResponse
    {
        $path = $this->resolveAssigned($id);

        $request->validate([
            'course_id'  => 'required|integer|exists:courses,id',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $courseId  = (int) $request->input('course_id');
        $sortOrder = $request->input('sort_order')
            ?? ($path->items()->max('sort_order') + 1);

        if (LearningPathItem::where('learning_path_id', $path->id)->where('course_id', $courseId)->exists()) {
            return response()->json(['success' => false, 'message' => 'الدورة موجودة بالفعل في المسار.'], 409);
        }

        LearningPathItem::create([
            'learning_path_id' => $path->id,
            'course_id'        => $courseId,
            'sort_order'       => $sortOrder,
        ]);

        $path->load('courses')->loadCount('courses');

        return response()->json([
            'success' => true,
            'message' => 'تم إضافة الدورة إلى المسار.',
            'data'    => new LearningPathResource($path),
        ]);
    }

    // ─── DELETE /api/instructor/learning-paths/{id}/items/{item} ─────────────
    public function removeItem(int $id, int $item): JsonResponse
    {
        $path = $this->resolveAssigned($id);

        $lpi = LearningPathItem::where('learning_path_id', $path->id)->findOrFail($item);
        $lpi->delete();

        $path->load('courses')->loadCount('courses');

        return response()->json([
            'success' => true,
            'message' => 'تم إزالة الدورة من المسار.',
            'data'    => new LearningPathResource($path),
        ]);
    }

    // ─── GET /api/instructor/learning-paths/{id}/students ────────────────────
    public function students(int $id): JsonResponse
    {
        $path = $this->resolveAssigned($id);

        $enrollments = LearningPathEnrollment::with('user:id,name,email')
            ->where('learning_path_id', $path->id)
            ->latest('enrolled_at')
            ->get()
            ->map(fn ($e) => [
                'enrollment_id' => $e->id,
                'user_id'       => $e->user_id,
                'name'          => $e->user?->name,
                'email'         => $e->user?->email,
                'status'        => $e->status,
                'enrolled_at'   => $e->enrolled_at,
                'completed_at'  => $e->completed_at,
            ]);

        return response()->json(['success' => true, 'data' => $enrollments]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function resolveAssigned(int $id): LearningPath
    {
        $instructorId = Auth::user()?->instructorProfile?->id;

        if (! $instructorId) {
            abort(403, 'غير مرتبط بمدرب.');
        }

        return LearningPath::where('id', $id)
            ->where(function ($q) use ($instructorId) {
                $q->where('instructor_id', $instructorId)
                  ->orWhereHas('courses', function ($cq) use ($instructorId) {
                      $cq->where('instructor_id', $instructorId);
                  });
            })
            ->firstOrFail();
    }

    private function imageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        return str_starts_with($path, 'http') ? $path : asset('storage/' . ltrim($path, '/'));
    }
}
