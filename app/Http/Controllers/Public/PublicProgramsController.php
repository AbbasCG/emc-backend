<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\CourseResource;
use App\Http\Resources\LearningPathResource;
use App\Models\Course;
use App\Models\LearningPath;
use App\Models\LearningPathEnrollment;
use App\Models\Registration;
use App\Models\Track;
use App\Models\Workshop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/programs
 *
 * Aggregated public programs page endpoint.
 * Returns courses, workshops, learning paths, stats and categories —
 * only published/visible items, no fake data.
 */
class PublicProgramsController extends Controller
{
    private const COURSE_STATUSES   = ['published', 'active', 'ended'];
    private const WORKSHOP_STATUSES = ['published', 'active', 'scheduled'];
    private const LP_STATUS         = ['published'];

    // ─── GET /api/programs ────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 12), 50);
        $search  = $request->filled('search') ? (string) $request->query('search') : null;
        $type    = $request->filled('type')   ? (string) $request->query('type')   : null; // course|workshop|learning_path

        // ── Stats ─────────────────────────────────────────────────────────────

        $coursesCount       = Course::whereIn('status', self::COURSE_STATUSES)->count();
        $workshopsCount     = Workshop::whereIn('status', self::WORKSHOP_STATUSES)->count();
        $learningPathsCount = LearningPath::whereIn('status', self::LP_STATUS)->count();

        // Distinct enrolled students across all programs (courses + learning paths)
        $courseStudents = Registration::whereIn('status', ['confirmed', 'Confirmed', 'attended', 'Attended', 'active'])
            ->distinct('user_id')
            ->count('user_id');

        $lpStudents = 0;
        try {
            $lpStudents = LearningPathEnrollment::whereIn('status', ['active', 'enrolled', 'completed'])
                ->distinct('user_id')
                ->count('user_id');
        } catch (\Throwable) {
            // table may not exist on older environments
        }

        $studentsCount = max($courseStudents, $lpStudents);

        // ── Categories (from Tracks) ───────────────────────────────────────────

        $categories = Track::where('status', 'active')
            ->select(['id', 'title', 'slug', 'description', 'image', 'level', 'duration'])
            ->orderBy('title')
            ->get()
            ->map(fn (Track $t) => [
                'id'          => $t->id,
                'name'        => $t->title,
                'name_ar'     => $t->title,
                'name_en'     => $t->title,
                'slug'        => $t->slug,
                'description' => $t->description,
                'icon'        => null,
                'color'       => null,
                'image'       => $t->image ? asset('storage/' . ltrim($t->image, '/')) : null,
                'sort_order'  => 0,
            ])
            ->values();

        // ── Featured Learning Paths ────────────────────────────────────────────

        $lpQuery = LearningPath::with(['instructor.user'])
            ->withCount('courses')
            ->whereIn('status', self::LP_STATUS)
            ->where('enrollment_open', true);

        if ($search) {
            $lpQuery->where(fn ($q) => $q
                ->where('title', 'like', "%{$search}%")
                ->orWhere('short_description', 'like', "%{$search}%")
            );
        }

        $featuredLPs = (clone $lpQuery)
            ->where('is_featured', true)
            ->latest()
            ->take(6)
            ->get();

        // Attach student counts for featured paths
        $this->attachLPStudentCounts($featuredLPs);

        // ── Courses (published, open) ──────────────────────────────────────────

        $courseQuery = Course::with(['instructor.user', 'features', 'track'])
            ->withCount('registrations')
            ->whereIn('status', self::COURSE_STATUSES)
            ->where(function ($q) {
                $q->whereNull('registration_open')
                    ->orWhere('registration_open', true)
                    ->orWhere(function ($q2) {
                        $q2->whereNotNull('end_date')
                            ->whereDate('end_date', '<', now());
                    });
            })
            ->publiclyVisible();

        if ($search) {
            $courseQuery->where(fn ($q) => $q
                ->where('title', 'like', "%{$search}%")
                ->orWhere('short_description', 'like', "%{$search}%")
            );
        }

        if ($type === 'course') {
            $courseQuery->where(fn ($q) => $q
                ->whereNull('program_type')
                ->orWhere('program_type', '!=', 'workshop')
            );
        }

        $courses = (clone $courseQuery)->latest()->take($perPage)->get();

        // ── Workshops (published / active / scheduled) ─────────────────────────

        $wQuery = Workshop::with([
            'course:id,title,slug,short_description,course_image,price,is_free,certificate,capacity,certificate_available,level,language',
            'instructor.user:id,name,avatar',
        ])->whereIn('status', self::WORKSHOP_STATUSES);

        if ($search) {
            $wQuery->where(fn ($q) => $q
                ->where('title', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
                ->orWhereHas('course', fn ($c) => $c->where('title', 'like', "%{$search}%"))
            );
        }

        $workshops = (clone $wQuery)->latest()->take($perPage)->get();

        // ── All Learning Paths (paginated) ────────────────────────────────────

        $allLPs = (clone $lpQuery)->latest()->take($perPage)->get();
        $this->attachLPStudentCounts($allLPs);

        // ── Latest Programs (mixed: courses + workshops as courses, ordered by created_at) ─

        $latestCourses = Course::with(['instructor.user'])
            ->withCount('registrations')
            ->whereIn('status', self::COURSE_STATUSES)
            ->where(function ($q) {
                $q->whereNull('registration_open')
                    ->orWhere('registration_open', true)
                    ->orWhere(function ($q2) {
                        $q2->whereNotNull('end_date')
                            ->whereDate('end_date', '<', now());
                    });
            })
            ->publiclyVisible()
            ->when($search, fn ($q) => $q->where(fn ($s) => $s
                ->where('title', 'like', "%{$search}%")
                ->orWhere('short_description', 'like', "%{$search}%")
            ))
            ->latest()
            ->take(8)
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'stats' => [
                    'courses_count'        => $coursesCount,
                    'workshops_count'      => $workshopsCount,
                    'learning_paths_count' => $learningPathsCount,
                    'students_count'       => $studentsCount,
                ],

                'categories'              => $categories,

                'featured_learning_paths' => LearningPathResource::collection($featuredLPs)->resolve(),

                'courses'    => CourseResource::collection($courses)->resolve(),

                'workshops'  => $workshops->map(fn (Workshop $w) => $this->formatWorkshop($w))->values(),

                'learning_paths' => LearningPathResource::collection($allLPs)->resolve(),

                'latest_programs' => CourseResource::collection($latestCourses)->resolve(),
            ],
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function attachLPStudentCounts(\Illuminate\Database\Eloquent\Collection $paths): void
    {
        if ($paths->isEmpty()) {
            return;
        }

        $ids = $paths->pluck('id');

        try {
            $counts = LearningPathEnrollment::whereIn('learning_path_id', $ids)
                ->whereIn('status', ['active', 'enrolled', 'completed'])
                ->selectRaw('learning_path_id, count(*) as cnt')
                ->groupBy('learning_path_id')
                ->pluck('cnt', 'learning_path_id');
        } catch (\Throwable) {
            $counts = collect();
        }

        $paths->each(function (LearningPath $p) use ($counts) {
            $p->students_count = (int) $counts->get($p->id, 0);
        });
    }

    private function formatWorkshop(Workshop $w): array
    {
        $course = $w->course;
        $inst   = $w->instructor;
        $user   = $inst?->relationLoaded('user') ? $inst->user : null;

        $instructorData = $inst ? [
            'id'        => $inst->id,
            'name'      => $user?->name ?? $inst->name,
            'avatar_url' => $this->resolveImageUrl($user?->avatar ?? $inst->image ?? null),
        ] : null;

        return [
            'id'               => $w->id,
            'title'            => $w->title ?? $course?->title,
            'slug'             => $w->slug ?? $course?->slug,
            'short_description' => $w->description ?? $course?->short_description,
            'image_url'        => $this->resolveImageUrl($course?->course_image),
            'price'            => $course?->price !== null ? (float) $course->price : null,
            'is_free'          => (bool) ($course?->is_free ?? ($course?->price == 0)),
            'duration'         => $course?->duration,
            'duration_unit'    => null,
            'status'           => $w->status,
            'enrollment_open'  => (bool) $w->registration_open,
            'start_date'       => $w->start_date?->format('Y-m-d'),
            'end_date'         => $w->end_date?->format('Y-m-d'),
            'start_time'       => $w->start_time,
            'meeting_link'     => $w->meeting_link,
            'level'            => $course?->level,
            'language'         => $course?->language,
            'certificate'      => (bool) ($course?->certificate_available ?? false),
            'capacity'         => $course?->capacity,
            'instructor'       => $instructorData,
            'type'             => 'workshop',
            'source'           => 'workshop',
        ];
    }

    private function resolveImageUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }
        if (str_starts_with($path, 'http')) {
            return $path;
        }
        return asset('storage/' . ltrim($path, '/'));
    }
}
