<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Registration;
use App\Support\CourseComputedStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Public workshop listing and detail endpoints.
 *
 * Source of truth: courses with program_type = 'workshop' and status = 'published'.
 * The separate Workshop scheduling model is not the public catalog — it tracks
 * specific sessions/occurrences. The course record is what users browse and register for.
 */
class WorkshopController extends Controller
{
    // ─── GET /api/workshops ───────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = Course::with(['instructor.user'])
            ->withCount('registrations')
            ->withSeatsTaken()
            ->where('program_type', 'workshop')
            ->where('status', 'published')
            ->financeApprovedForPublic();

        // Search across title, short_description, description
        if ($request->filled('search')) {
            $q = (string) $request->query('search');
            $query->where(fn ($sub) => $sub
                ->where('title', 'like', "%{$q}%")
                ->orWhere('short_description', 'like', "%{$q}%")
                ->orWhere('description', 'like', "%{$q}%")
                ->orWhereHas('instructor.user', fn ($u) => $u->where('name', 'like', "%{$q}%"))
            );
        }

        // Delivery mode
        if ($request->filled('location_type') && $request->query('location_type') !== 'all') {
            $query->where('location_type', $request->query('location_type'));
        }

        // Price type
        if ($request->filled('price_type')) {
            if ($request->query('price_type') === 'free') {
                $query->where(fn ($q) => $q->where('is_free', true)->orWhere('price', 0)->orWhereNull('price'));
            } elseif ($request->query('price_type') === 'paid') {
                $query->where('is_free', false)->where('price', '>', 0);
            }
        }

        // Instructor
        if ($request->filled('instructor_id')) {
            $query->where('instructor_id', (int) $request->query('instructor_id'));
        }

        // Department
        if ($request->filled('department_id')) {
            $query->where('department_id', (int) $request->query('department_id'));
        }

        // Date range (start_date)
        if ($request->filled('date_from')) {
            $query->whereDate('start_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('start_date', '<=', $request->query('date_to'));
        }

        $perPage   = min((int) $request->query('per_page', 24), 200);
        $workshops = $query->latest('id')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $workshops->map(fn ($c) => $this->formatCourse($c)),
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
        $course = Course::with(['instructor.user', 'features'])
            ->withCount('registrations')
            ->withSeatsTaken()
            ->where('slug', $slug)
            ->where('program_type', 'workshop')
            ->where('status', 'published')
            ->financeApprovedForPublic()
            ->firstOrFail();

        $enrollCount = (int) ($course->seats_taken_count ?? $course->registrations_count ?? 0);

        // Enrollment status for authenticated users
        $enrollmentStatus = null;
        $userId = Auth::id();
        if ($userId) {
            $reg = Registration::where('user_id', $userId)
                ->where('course_id', $course->id)
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
                $this->formatCourse($course),
                [
                    'full_description'  => $course->description,
                    'learning_outcomes' => $this->ensureArray($course->learning_outcomes),
                    'requirements'      => $this->ensureArray($course->requirements),
                    'features'          => $course->features?->pluck('title')->all() ?? [],
                    'enrolled_count'    => $enrollCount,
                    'enrollment_status' => $enrollmentStatus,
                ]
            ),
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function formatCourse(Course $c): array
    {
        $instructor = $c->instructor;
        $user       = $instructor?->user;
        $isOnline   = in_array(strtolower((string) $c->location_type), ['online', ''], true)
                      || $c->location_type === null;

        $capacity        = (int) ($c->capacity ?? 0);
        $enrolledCount   = (int) ($c->seats_taken_count ?? $c->registrations_count ?? 0);
        $spotsRemaining  = max(0, $capacity - $enrolledCount);

        return [
            'id'               => $c->id,
            'title'            => $c->title,
            'slug'             => $c->slug,
            'short_description'=> $c->short_description,
            'description'      => $c->short_description ?? $c->description,
            'image_url'        => $this->imageUrl($c->course_image),
            'status'           => $c->status,
            'program_type'     => $c->program_type,

            // Dates
            'start_date'       => $this->dateStr($c->start_date),
            'end_date'         => $this->dateStr($c->end_date),
            'start_time'       => $c->start_time,
            'end_time'         => $c->end_time,
            // Legacy aliases for fetchUpcomingWorkshops() mapping
            'date'             => $this->dateStr($c->start_date) ?? '',
            'time'             => $c->start_time ?? '',

            // Delivery
            'location_type'    => $c->location_type ?? 'online',
            'meeting_link'     => $c->meeting_link,
            'is_online'        => $isOnline,

            // Registration
            'is_ended'          => CourseComputedStatus::isEnded($c),
            'computed_status'   => CourseComputedStatus::computedStatus($c),
            'status_label_ar'   => CourseComputedStatus::statusLabelAr($c),
            'registration_open' => ! CourseComputedStatus::isEnded($c) && (bool) ($c->registration_open ?? true),
            'enrollment_open'   => ! CourseComputedStatus::isEnded($c) && (bool) ($c->registration_open ?? true),

            // Price / access
            'price'    => $c->price !== null ? (float) $c->price : null,
            'is_free'  => (bool) ($c->is_free ?? ($c->price == 0)),

            // Certificate
            'certificate'           => $c->certificate,
            'certificate_available' => (bool) ($c->certificate_available ?? false),

            // Capacity / enrollment — aliases for both normalizeWorkshopRow and fetchUpcomingWorkshops
            'seats'           => $capacity ?: null,
            'total_spots'     => $capacity ?: 0,
            'enrolled_count'  => $enrolledCount,
            // No capacity means no limit: no number of seats left (it used to read as "0 left", i.e. full).
            'spots_remaining' => $capacity > 0 ? $spotsRemaining : null,
            'seats_remaining' => $capacity > 0 ? $spotsRemaining : null,
            'is_full'         => $capacity > 0 && $enrolledCount >= $capacity,

            // Level / language
            'level'    => $c->level,
            'language' => $c->language,

            // Duration — alias for fetchUpcomingWorkshops
            'training_hours' => $c->training_hours,
            'duration_hours' => $c->training_hours ?? 0,

            // Instructor — both nested object and flat alias
            'instructor' => $instructor ? [
                'id'         => $instructor->id,
                'name'       => $user?->name ?? $instructor->name ?? null,
                'avatar_url' => $this->imageUrl($user?->avatar ?? $instructor->image ?? null),
                'title'      => $instructor->title ?? null,
            ] : null,
            // Flat alias for fetchUpcomingWorkshops
            'trainer_name' => $instructor ? ($user?->name ?? $instructor->name ?? 'فريق EMC') : 'فريق EMC',

            'course_slug' => $c->slug,
        ];
    }

    private function imageUrl(?string $path): ?string
    {
        if (! $path) return null;
        if (str_starts_with($path, 'http')) return $path;
        return asset('storage/' . ltrim($path, '/'));
    }

    private function dateStr(mixed $value): ?string
    {
        if (! $value) return null;
        return \Carbon\Carbon::parse($value)->format('Y-m-d');
    }

    private function ensureArray(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}
