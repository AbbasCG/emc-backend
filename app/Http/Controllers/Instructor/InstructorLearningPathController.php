<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Resources\LearningPathResource;
use App\Models\LearningPath;
use App\Models\LearningPathEnrollment;
use App\Models\LearningPathItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InstructorLearningPathController extends Controller
{
    // ─── GET /api/instructor/learning-paths ───────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $instructorId = Auth::user()?->instructor?->id;

        if (! $instructorId) {
            return response()->json(['success' => false, 'message' => 'غير مرتبط بمدرب.'], 403);
        }

        $query = LearningPath::with(['instructor.user'])
            ->withCount(['courses'])
            ->where('instructor_id', $instructorId);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $paths = $query->latest()->get();

        // Attach enrollment counts
        $pathIds = $paths->pluck('id');
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

        $path->load(['instructor.user', 'courses'])->loadCount('courses');
        $path->students_count = LearningPathEnrollment::where('learning_path_id', $id)->count();

        return response()->json([
            'success' => true,
            'data'    => new LearningPathResource($path),
        ]);
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

        $courseId   = (int) $request->input('course_id');
        $sortOrder  = $request->input('sort_order')
            ?? ($path->items()->max('sort_order') + 1);

        $exists = LearningPathItem::where('learning_path_id', $path->id)
            ->where('course_id', $courseId)
            ->exists();

        if ($exists) {
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
                'enrollment_id'  => $e->id,
                'user_id'        => $e->user_id,
                'name'           => $e->user?->name,
                'email'          => $e->user?->email,
                'status'         => $e->status,
                'enrolled_at'    => $e->enrolled_at,
                'completed_at'   => $e->completed_at,
            ]);

        return response()->json(['success' => true, 'data' => $enrollments]);
    }

    // ─── Helper ───────────────────────────────────────────────────────────────

    private function resolveAssigned(int $id): LearningPath
    {
        $instructorId = Auth::user()?->instructor?->id;

        if (! $instructorId) {
            abort(403, 'غير مرتبط بمدرب.');
        }

        return LearningPath::where('id', $id)
            ->where('instructor_id', $instructorId)
            ->firstOrFail();
    }
}
