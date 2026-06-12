<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Registration;
use App\Models\Workshop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Public workshop listing and detail endpoints.
 *
 * Workshops in EMC are sub-entities of a Course; price, certificate, image
 * and capacity data are pulled from the parent Course record.
 *
 * Enrollment = a Registration on the parent Course.
 */
class WorkshopController extends Controller
{
    /** Statuses visible on the public website. */
    private const PUBLIC_STATUSES = ['published', 'active', 'scheduled'];

    // ─── GET /api/workshops ───────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = Workshop::with([
            'course:id,title,slug,short_description,course_image,price,is_free,certificate,capacity,certificate_available,level,language',
            'instructor.user:id,name,avatar',
        ])
            ->whereIn('status', self::PUBLIC_STATUSES);

        // Filters
        if ($request->filled('search')) {
            $q = (string) $request->query('search');
            $query->where(fn ($sub) => $sub
                ->where('title', 'like', "%{$q}%")
                ->orWhere('description', 'like', "%{$q}%")
                ->orWhereHas('course', fn ($c) => $c->where('title', 'like', "%{$q}%"))
            );
        }

        if ($request->filled('status')) {
            $status = $request->query('status');
            if (in_array($status, self::PUBLIC_STATUSES, true)) {
                $query->where('status', $status);
            }
        }

        if ($request->filled('language')) {
            $query->whereHas('course', fn ($c) => $c->where('language', $request->query('language')));
        }

        if ($request->filled('level')) {
            $query->whereHas('course', fn ($c) => $c->where('level', $request->query('level')));
        }

        if ($request->boolean('registration_open')) {
            $query->where('registration_open', true);
        }

        $perPage   = min((int) $request->query('per_page', 12), 50);
        $workshops = $query->orderBy('start_date')->paginate($perPage);

        // Batch-fetch enrolled counts for the returned workshops.
        $courseIds    = $workshops->pluck('course_id')->filter()->unique()->all();
        $enrollCounts = [];
        if (! empty($courseIds)) {
            $enrollCounts = Registration::whereIn('course_id', $courseIds)
                ->whereNotIn('status', ['cancelled', 'Cancelled'])
                ->selectRaw('course_id, count(*) as cnt')
                ->groupBy('course_id')
                ->pluck('cnt', 'course_id')
                ->all();
        }

        return response()->json([
            'success' => true,
            'data'    => $workshops->map(fn ($w) => $this->formatWorkshop($w, $enrollCounts)),
            'meta'    => [
                'total'        => $workshops->total(),
                'current_page' => $workshops->currentPage(),
                'last_page'    => $workshops->lastPage(),
                'per_page'     => $workshops->perPage(),
            ],
        ]);
    }

    // ─── GET /api/workshops/{slug} ────────────────────────────────────────────

    public function show(Request $request, string $slug): JsonResponse
    {
        $workshop = Workshop::with([
            'course:id,title,slug,short_description,description,course_image,price,is_free,certificate,capacity,certificate_available,level,language,learning_outcomes,requirements',
            'course.features',
            'instructor.user:id,name,avatar',
            'sessions' => fn ($q) => $q->whereIn('status', ['scheduled', 'live'])->orderBy('session_date'),
        ])
            ->where('slug', $slug)
            ->whereIn('status', self::PUBLIC_STATUSES)
            ->firstOrFail();

        $enrollCount = Registration::where('course_id', $workshop->course_id)
            ->whereNotIn('status', ['cancelled', 'Cancelled'])
            ->count();

        // Enrollment status for authenticated users.
        $enrollmentStatus = null;
        $userId           = Auth::id();
        if ($userId && $workshop->course_id) {
            $reg = Registration::where('user_id', $userId)
                ->where('course_id', $workshop->course_id)
                ->whereNotIn('status', ['cancelled', 'Cancelled'])
                ->latest()
                ->first();

            $enrollmentStatus = $reg ? [
                'enrolled'        => true,
                'status'          => $reg->status,
                'registration_id' => $reg->id,
                'registered_at'   => $reg->created_at?->toIso8601String(),
            ] : ['enrolled' => false];
        }

        return response()->json([
            'success' => true,
            'data'    => array_merge(
                $this->formatWorkshop($workshop, [$workshop->course_id => $enrollCount]),
                [
                    // Extra detail fields
                    'full_description' => $workshop->description
                        ?? $workshop->course?->description,
                    'learning_outcomes' => $this->ensureArray($workshop->course?->learning_outcomes),
                    'requirements'      => $this->ensureArray($workshop->course?->requirements),
                    'sessions'          => $workshop->sessions->map(fn ($s) => [
                        'id'           => $s->id,
                        'title'        => $s->title,
                        'session_date' => $s->session_date?->toDateString(),
                        'start_time'   => $s->start_time,
                        'end_time'     => $s->end_time,
                        'meeting_url'  => $s->meeting_url,
                        'status'       => $s->status,
                    ])->values(),
                    'enrollment_status' => $enrollmentStatus,
                ]
            ),
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function formatWorkshop(Workshop $w, array $enrollCounts): array
    {
        $course       = $w->course;
        $instructor   = $w->instructor;
        $instructorUser = $instructor?->user;

        return [
            'id'               => $w->id,
            'title'            => $w->title,
            'slug'             => $w->slug,
            'short_description'=> $course?->short_description,
            'description'      => $w->description ?? $course?->description,
            'image_url'        => $this->imageUrl($course?->course_image),
            'status'           => $w->status,
            'start_date'       => $w->start_date?->toDateString(),
            'end_date'         => $w->end_date?->toDateString(),
            'start_time'       => $w->start_time ?? null,
            'end_time'         => $w->end_time ?? null,
            'location_type'    => $w->location_type,
            'meeting_link'     => $w->meeting_link,
            'registration_open'=> (bool) $w->registration_open,
            'enrollment_open'  => (bool) $w->registration_open,  // alias
            'course_id'        => $w->course_id,
            'course_slug'      => $course?->slug,

            // Price / access
            'price'            => $course?->price !== null ? (float) $course->price : null,
            'is_free'          => (bool) ($course?->is_free ?? false),

            // Certificate
            'certificate'      => $course?->certificate,
            'certificate_available' => (bool) ($course?->certificate_available ?? false),

            // Capacity / enrollment
            'seats'            => $course?->capacity,
            'enrolled_count'   => (int) ($enrollCounts[$w->course_id] ?? 0),

            // Level / language
            'level'            => $course?->level,
            'language'         => $course?->language,

            // Instructor
            'instructor'       => $instructor ? [
                'id'         => $instructor->id,
                'name'       => $instructorUser?->name ?? $instructor->name ?? null,
                'avatar_url' => $this->imageUrl($instructorUser?->avatar ?? $instructor->image ?? null),
                'title'      => $instructor->title ?? null,
            ] : null,
        ];
    }

    private function imageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        if (str_starts_with($path, 'http')) {
            return $path;
        }
        return asset('storage/' . ltrim($path, '/'));
    }

    private function ensureArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}
