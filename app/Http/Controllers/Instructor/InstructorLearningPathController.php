<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Resources\LearningPathResource;
use App\Models\CourseAssignment;
use App\Models\CourseMaterial;
use App\Models\CourseSession;
use App\Models\LearningPath;
use App\Models\LearningPathEnrollment;
use App\Models\LmsSession;
use App\Services\CourseAssignmentBridgeService;
use App\Support\AssignmentSubmissionStatus;
use App\Models\AssignmentSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class InstructorLearningPathController extends Controller
{
    // ─── GET /api/instructor/learning-paths ───────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $user         = Auth::user();
        $isAdmin      = $user->isSuperAdmin() || $user->isAdmin();
        $instructorId = $user->instructorProfile?->id;

        if (! $isAdmin && ! $instructorId) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بالوصول إلى هذا المسار.',
            ], 403);
        }

        // Admins see all learning paths; instructors see only assigned ones.
        $query = LearningPath::with(['instructor.user'])->withCount(['courses']);

        if (! $isAdmin) {
            $query->where(function ($q) use ($instructorId) {
                $q->where('instructor_id', $instructorId)
                  ->orWhereHas('courses', fn ($cq) => $cq->where('instructor_id', $instructorId));
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('search')) {
            $query->where('title', 'like', '%' . $request->query('search') . '%');
        }

        $paths = $query->latest()->get();

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

        $path->load(['instructor.user', 'courses' => function ($q) {
            $q->withCount(['courseSessions', 'materials', 'courseAssignments']);
        }])->loadCount('courses');

        $path->students_count = LearningPathEnrollment::where('learning_path_id', $id)->count();

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
                'sessions_count'    => $c->course_sessions_count ?? 0,
                'materials_count'   => $c->materials_count ?? 0,
                'assignments_count' => $c->course_assignments_count ?? 0,
            ]);

        $currentInstructorId = Auth::user()?->instructorProfile?->id;
        $isPathInstructor    = $path->instructor_id === $currentInstructorId;

        $data                   = (new LearningPathResource($path))->toArray(request());
        $data['courses']        = $courses;
        $data['is_path_instructor'] = $isPathInstructor;
        // Instruct the frontend that the course structure is read-only for instructors.
        $data['can_edit_courses'] = false;

        return response()->json(['success' => true, 'data' => $data]);
    }

    // ─── GET /api/instructor/learning-paths/{id}/sessions ─────────────────────
    // Returns both LmsSession (live) and CourseSession (curriculum) records.
    public function sessions(int $id): JsonResponse
    {
        $path      = $this->resolveAssigned($id);
        $courseIds = $path->items()->pluck('course_id');

        // ── Live sessions (lms_sessions) ─────────────────────────────────────
        $lmsSessions = LmsSession::whereIn('course_id', $courseIds)
            ->with('course:id,title')
            ->orderBy('session_date')
            ->orderBy('start_time')
            ->get()
            ->map(fn ($s) => [
                'id'            => $s->id,
                'source_type'   => 'lms_session',
                'course_id'     => $s->course_id,
                'course_title'  => $s->course?->title,
                'title'         => $s->title,
                'description'   => $s->description,
                'session_date'  => $s->session_date?->format('Y-m-d'),
                'start_time'    => $s->start_time,
                'end_time'      => $s->end_time,
                'starts_at'     => $s->session_date && $s->start_time
                    ? $s->session_date->format('Y-m-d') . 'T' . $s->start_time . ':00'
                    : $s->session_date?->format('Y-m-d'),
                'ends_at'       => $s->session_date && $s->end_time
                    ? $s->session_date->format('Y-m-d') . 'T' . $s->end_time . ':00'
                    : null,
                'meeting_url'   => $s->meeting_url,
                'recording_url' => $s->recording_url,
                'status'        => $s->status,
                'location'      => $s->location,
            ]);

        // ── Curriculum sessions (course_sessions) ─────────────────────────────
        $courseSessions = CourseSession::whereIn('course_id', $courseIds)
            ->with('course:id,title')
            ->orderBy('start_at')
            ->get()
            ->map(fn ($s) => [
                'id'            => $s->id,
                'source_type'   => 'course_session',
                'course_id'     => $s->course_id,
                'course_title'  => $s->course?->title,
                'module_id'     => $s->module_id,
                'title'         => $s->title,
                'description'   => $s->description,
                'session_date'  => $s->start_at?->toDateString(),
                'start_time'    => $s->start_at?->format('H:i'),
                'end_time'      => $s->end_at?->format('H:i'),
                'starts_at'     => $s->start_at?->toIso8601String(),
                'ends_at'       => $s->end_at?->toIso8601String(),
                'location_type' => $s->location_type,
                'meeting_url'   => $s->meeting_link,
                'location'      => $s->location,
                'status'        => $s->status,
                'is_visible'    => (bool) $s->is_visible,
            ]);

        $all = $lmsSessions
            ->concat($courseSessions)
            ->sortBy(fn ($s) => $s['starts_at'] ?? '')
            ->values();

        return response()->json(['success' => true, 'data' => $all]);
    }

    // ─── GET /api/instructor/learning-paths/{id}/assignments ──────────────────
    public function assignments(int $id): JsonResponse
    {
        $path      = $this->resolveAssigned($id);
        $courseIds = $path->items()->pluck('course_id');
        $userId    = Auth::id();
        $bridge    = app(CourseAssignmentBridgeService::class);

        $assignments = CourseAssignment::whereIn('course_id', $courseIds)
            ->where(fn ($v) => $v->where('is_visible', true)->orWhereNull('is_visible'))
            ->whereIn('status', ['published', 'active'])
            ->with('course:id,title,slug')
            ->orderBy('course_id')
            ->orderBy('sort_order')
            ->get()
            ->map(function ($ca) use ($bridge) {
                $lms = $bridge->ensureLmsAssignment($ca);
                return [
                    'id'                   => $ca->id,
                    'course_id'            => $ca->course_id,
                    'course_title'         => $ca->course?->title,
                    'course_slug'          => $ca->course?->slug,
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
                    'is_required'          => (bool) $ca->is_required,
                    'is_visible'           => (bool) $ca->is_visible,
                    'status'               => $ca->status,
                    'sort_order'           => (int) $ca->sort_order,
                ];
            });

        return response()->json(['success' => true, 'data' => $assignments]);
    }

    // ─── GET /api/instructor/learning-paths/{id}/materials ───────────────────
    public function materials(int $id): JsonResponse
    {
        $path      = $this->resolveAssigned($id);
        $courseIds = $path->items()->pluck('course_id');

        $materials = CourseMaterial::whereIn('course_id', $courseIds)
            ->where(fn ($v) => $v->where('is_visible', true)->orWhereNull('is_visible'))
            ->with('course:id,title,slug')
            ->orderBy('course_id')
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($m) => [
                'id'               => $m->id,
                'course_id'        => $m->course_id,
                'course_title'     => $m->course?->title,
                'course_slug'      => $m->course?->slug,
                'module_id'        => $m->module_id,
                'title'            => $m->title,
                'description'      => $m->description,
                'kind'             => $m->type,
                'type'             => $m->type,
                'file_url'         => $m->file_path ? url('/api/materials/' . $m->id . '/download') : null,
                'external_url'     => $m->external_url,
                'sort_order'       => (int) $m->sort_order,
                'is_visible'       => (bool) $m->is_visible,
                'updated_at'       => $m->updated_at?->toIso8601String(),
            ]);

        return response()->json(['success' => true, 'data' => $materials]);
    }

    // ─── GET /api/instructor/learning-paths/{id}/students ────────────────────
    public function students(int $id): JsonResponse
    {
        $path = $this->resolveAssigned($id);

        $enrollments = LearningPathEnrollment::with('user:id,name,email,avatar')
            ->where('learning_path_id', $path->id)
            ->latest('enrolled_at')
            ->get()
            ->map(fn ($e) => [
                'enrollment_id' => $e->id,
                'user_id'       => $e->user_id,
                'name'          => $e->user?->name,
                'email'         => $e->user?->email,
                'avatar'        => $e->user?->avatar ?? null,
                'status'        => $e->status,
                'enrolled_at'   => $e->enrolled_at,
                'completed_at'  => $e->completed_at,
            ]);

        return response()->json(['success' => true, 'data' => $enrollments]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function resolveAssigned(int $id): LearningPath
    {
        $user    = Auth::user();
        $isAdmin = $user->isSuperAdmin() || $user->isAdmin();

        // Admins have full access to any path.
        if ($isAdmin) {
            return LearningPath::findOrFail($id);
        }

        $instructorId = $user->instructorProfile?->id;

        if (! $instructorId) {
            abort(403, 'غير مصرح لك بالوصول إلى هذا المسار.');
        }

        // Instructors may only view paths they are assigned to or that contain their courses.
        $path = LearningPath::where('id', $id)
            ->where(function ($q) use ($instructorId) {
                $q->where('instructor_id', $instructorId)
                  ->orWhereHas('courses', fn ($cq) => $cq->where('instructor_id', $instructorId));
            })
            ->first();

        if (! $path) {
            abort(403, 'غير مصرح لك بالوصول إلى هذا المسار.');
        }

        return $path;
    }

    private function imageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        return str_starts_with($path, 'http') ? $path : asset('storage/' . ltrim($path, '/'));
    }
}
