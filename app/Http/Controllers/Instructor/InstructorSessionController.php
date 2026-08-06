<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseSession;
use App\Models\LearningPathItem;
use App\Models\LmsSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class InstructorSessionController extends Controller
{
    /**
     * GET /api/instructor/sessions
     *
     * Returns a unified list of sessions scoped to the authenticated instructor,
     * merged from two sources:
     *   - lms_sessions    — live/recorded class sessions
     *   - course_sessions — planned curriculum sessions
     *
     * All items expose consistent ISO date aliases: `starts_at` / `ends_at`,
     * plus `module_id`, `location_type`, and `learning_path` context.
     */
    public function index(Request $request): JsonResponse
    {
        $user    = $request->user();
        $profile = $user->instructorProfile;

        if (! $profile && ! $user->isAdmin() && ! $user->isSuperAdmin()) {
            return response()->json([
                'success' => true,
                'data'    => [],
                'meta'    => ['total' => 0],
            ]);
        }

        $courseIds = $profile
            ? Course::query()->where('instructor_id', $profile->id)->pluck('id')
            : collect();

        if ($courseIds->isEmpty() && ! $user->isAdmin() && ! $user->isSuperAdmin()) {
            return response()->json([
                'success' => true,
                'data'    => [],
                'meta'    => ['total' => 0],
            ]);
        }

        $statusFilter   = $request->query('status');
        $courseIdFilter = $request->filled('course_id') ? (int) $request->query('course_id') : null;

        // Build a course_id → learning_path map for context enrichment (one query).
        $lpByCourseId = $this->buildLearningPathMap($courseIds);

        // ── 1. LMS Sessions (live/recorded) ─────────────────────────────────
        $lmsQuery = LmsSession::with(['course:id,title,slug', 'instructor'])
            ->when($courseIds->isNotEmpty(), fn ($q) => $q->where(function ($inner) use ($profile, $courseIds) {
                $inner->whereIn('course_id', $courseIds);
                if ($profile) {
                    $inner->orWhere('instructor_id', $profile->id);
                }
            }))
            ->when($courseIdFilter, fn ($q) => $q->where('course_id', $courseIdFilter))
            ->when($statusFilter, fn ($q) => $q->where('status', $statusFilter))
            ->when(! $statusFilter, fn ($q) => $q->whereNotIn('status', ['cancelled', 'archived']));

        $lmsSessions = $lmsQuery->get()
            ->map(fn (LmsSession $s) => $this->formatLmsSession($s, $lpByCourseId));

        // ── 2. Course Sessions (curriculum / planned) ────────────────────────
        $csQuery = CourseSession::with(['course:id,title,slug'])
            ->when($courseIds->isNotEmpty(), fn ($q) => $q->whereIn('course_id', $courseIds))
            ->when($courseIdFilter, fn ($q) => $q->where('course_id', $courseIdFilter))
            ->when($statusFilter, fn ($q) => $q->where('status', $statusFilter))
            ->when(! $statusFilter, fn ($q) => $q->whereNotIn('status', ['archived']));

        $courseSessions = $csQuery->get()
            ->map(fn (CourseSession $s) => $this->formatCourseSession($s, $lpByCourseId));

        // ── Merge + sort by starts_at desc ───────────────────────────────────
        $all = $lmsSessions
            ->concat($courseSessions)
            ->sortByDesc(fn ($s) => $s['starts_at'] ?? '')
            ->values();

        return response()->json([
            'success' => true,
            'data'    => $all,
            'meta'    => ['total' => $all->count()],
        ]);
    }

    // ─── Formatters ──────────────────────────────────────────────────────────

    private function formatLmsSession(LmsSession $s, array $lpByCourseId): array
    {
        $date     = $s->session_date?->toDateString();
        $startsAt = $date && $s->start_time ? $date . 'T' . $s->start_time . ':00' : $date;
        $endsAt   = $date && $s->end_time   ? $date . 'T' . $s->end_time   . ':00' : null;

        [$computedStatus, $isLinkVisible, $minUntilStart, $minUntilEnd] =
            $this->computeSessionTiming($startsAt, $endsAt, $s->meeting_url);

        return [
            'id'                  => $s->id,
            'source_type'         => 'lms_session',
            'course_id'           => $s->course_id,
            'course_title'        => $s->course?->title,
            'course_slug'         => $s->course?->slug,
            'module_id'           => null,
            'title'               => $s->title,
            'description'         => $s->description,
            'starts_at'           => $startsAt,
            'start_at'            => $startsAt,
            'ends_at'             => $endsAt,
            'end_at'              => $endsAt,
            'session_date'        => $date,
            'start_time'          => $s->start_time,
            'end_time'            => $s->end_time,
            'location_type'       => $s->location ?? null,
            'location'            => $s->location,
            'meeting_url'         => $s->meeting_url,
            'meeting_link'        => $s->meeting_url,
            'status'              => $s->status ?? 'scheduled',
            'computed_status'     => $computedStatus,
            'is_link_visible'     => $isLinkVisible,
            'minutes_until_start' => $minUntilStart,
            'minutes_until_end'   => $minUntilEnd,
            'is_visible'          => true,
            'type'                => 'lms_session',
            'learning_path'       => $s->course_id ? ($lpByCourseId[$s->course_id] ?? null) : null,
        ];
    }

    private function formatCourseSession(CourseSession $s, array $lpByCourseId): array
    {
        $startsAt = $s->start_at?->toIso8601String();
        $endsAt   = $s->end_at?->toIso8601String();

        [$computedStatus, $isLinkVisible, $minUntilStart, $minUntilEnd] =
            $this->computeSessionTiming($startsAt, $endsAt, $s->meeting_link);

        return [
            'id'                  => $s->id,
            'source_type'         => 'course_session',
            'course_id'           => $s->course_id,
            'course_title'        => $s->course?->title,
            'course_slug'         => $s->course?->slug,
            'module_id'           => $s->module_id,
            'title'               => $s->title,
            'description'         => $s->description,
            'starts_at'           => $startsAt,
            'start_at'            => $startsAt,
            'ends_at'             => $endsAt,
            'end_at'              => $endsAt,
            'session_date'        => $s->start_at?->toDateString(),
            'start_time'          => $s->start_at?->format('H:i'),
            'end_time'            => $s->end_at?->format('H:i'),
            'location_type'       => $s->location_type,
            'location'            => $s->location,
            'meeting_url'         => $s->meeting_link,
            'meeting_link'        => $s->meeting_link,
            'status'              => $s->status ?? 'draft',
            'computed_status'     => $computedStatus,
            'is_link_visible'     => $isLinkVisible,
            'minutes_until_start' => $minUntilStart,
            'minutes_until_end'   => $minUntilEnd,
            'is_visible'          => (bool) $s->is_visible,
            'type'                => 'course_session',
            'learning_path'       => $s->course_id ? ($lpByCourseId[$s->course_id] ?? null) : null,
        ];
    }

    /**
     * Derive computed timing fields from start/end ISO strings.
     * Returns [computed_status, is_link_visible, minutes_until_start, minutes_until_end]
     */
    private function computeSessionTiming(?string $startsAt, ?string $endsAt, ?string $link): array
    {
        if (! $startsAt) {
            return ['scheduled', false, null, null];
        }

        $now      = now()->timestamp;
        $startTs  = strtotime($startsAt);
        $endTs    = $endsAt ? strtotime($endsAt) : $startTs + 5400; // 90 min default

        $minUntilStart = $startTs ? (int) round(($startTs - $now) / 60) : null;
        $minUntilEnd   = $endTs   ? (int) round(($endTs   - $now) / 60) : null;

        if ($now >= $endTs) {
            return ['ended', false, $minUntilStart, $minUntilEnd];
        }

        $windowStart = $startTs - 600; // 10 minutes before start

        if ($now >= $windowStart && $now < $endTs) {
            return ['available', (bool) $link, $minUntilStart, $minUntilEnd];
        }

        return ['scheduled', false, $minUntilStart, $minUntilEnd];
    }

    /**
     * Build course_id → {id, title, slug} map for all courses that belong to a
     * learning path visible to this instructor.
     *
     * @param  Collection<int, int>  $courseIds
     * @return array<int, array{id:int,title:string,slug:string}>
     */
    private function buildLearningPathMap(Collection $courseIds): array
    {
        if ($courseIds->isEmpty()) {
            return [];
        }

        $items = LearningPathItem::whereIn('course_id', $courseIds)
            ->with('learningPath:id,title,slug')
            ->get();

        $map = [];
        foreach ($items as $item) {
            $lp = $item->learningPath;
            if ($lp && ! isset($map[(int) $item->course_id])) {
                $map[(int) $item->course_id] = [
                    'id'    => $lp->id,
                    'title' => $lp->title,
                    'slug'  => $lp->slug,
                ];
            }
        }

        return $map;
    }
}
