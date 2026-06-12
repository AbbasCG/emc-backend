<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseSession;
use App\Models\LmsSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InstructorSessionController extends Controller
{
    /**
     * GET /api/instructor/sessions
     *
     * Returns a unified list of sessions scoped to the authenticated instructor,
     * merged from two sources:
     *   - lms_sessions  — live/recorded class sessions (session_date + start_time/end_time)
     *   - course_sessions — planned content sessions (start_at/end_at datetimes)
     *
     * All items expose consistent ISO date aliases: `starts_at` and `ends_at`.
     */
    public function index(Request $request): JsonResponse
    {
        $user    = $request->user();
        $profile = $user->instructorProfile;

        $courseIds = $profile
            ? Course::query()->where('instructor_id', $profile->id)->pluck('id')
            : collect();

        if (! $profile && $courseIds->isEmpty()) {
            return response()->json([
                'success' => true,
                'data'    => [],
                'meta'    => ['total' => 0],
            ]);
        }

        $statusFilter = $request->query('status');

        // ── 1. LMS Sessions (live/recorded) ──────────────────────────────────
        $lmsSessions = LmsSession::with(['course:id,title,slug', 'instructor'])
            ->where(function ($q) use ($profile, $courseIds) {
                $q->whereIn('course_id', $courseIds);
                if ($profile) {
                    $q->orWhere('instructor_id', $profile->id);
                }
            })
            ->when($statusFilter, fn ($q) => $q->where('status', $statusFilter))
            ->when(! $statusFilter, fn ($q) => $q->whereNotIn('status', ['cancelled', 'archived']))
            ->get()
            ->map(fn (LmsSession $s) => $this->formatLmsSession($s));

        // ── 2. Course Sessions (planned content sessions) ─────────────────────
        $courseSessions = CourseSession::with(['course:id,title,slug'])
            ->whereIn('course_id', $courseIds)
            ->when($statusFilter, fn ($q) => $q->where('status', $statusFilter))
            ->when(! $statusFilter, fn ($q) => $q->whereNotIn('status', ['archived']))
            ->get()
            ->map(fn (CourseSession $s) => $this->formatCourseSession($s));

        // ── Merge + sort by starts_at desc ────────────────────────────────────
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

    // ─────────────────────────────────────────────────────────────────────────

    private function formatLmsSession(LmsSession $s): array
    {
        // Combine date + time into ISO-ish strings for consistent frontend parsing
        $date     = $s->session_date?->toDateString();
        $startsAt = $date && $s->start_time ? $date . 'T' . $s->start_time . ':00' : $date;
        $endsAt   = $date && $s->end_time   ? $date . 'T' . $s->end_time   . ':00' : null;

        return [
            'id'           => $s->id,
            'source_type'  => 'lms_session',
            'course_id'    => $s->course_id,
            'course_title' => $s->course?->title,
            'course_slug'  => $s->course?->slug,
            'title'        => $s->title,
            'description'  => $s->description,
            // ISO date/time aliases (consistent with course_sessions)
            'starts_at'    => $startsAt,
            'start_at'     => $startsAt,
            'ends_at'      => $endsAt,
            'end_at'       => $endsAt,
            // Raw fields for backward compat
            'session_date' => $date,
            'start_time'   => $s->start_time,
            'end_time'     => $s->end_time,
            'meeting_url'  => $s->meeting_url,
            'meeting_link' => $s->meeting_url,
            'location'     => $s->location,
            'status'       => $s->status ?? 'scheduled',
            'visibility'   => 'published',
            'is_visible'   => true,
            'type'         => 'lms_session',
        ];
    }

    private function formatCourseSession(CourseSession $s): array
    {
        $startsAt = $s->start_at?->toIso8601String();
        $endsAt   = $s->end_at?->toIso8601String();

        return [
            'id'           => $s->id,
            'source_type'  => 'course_session',
            'course_id'    => $s->course_id,
            'course_title' => $s->course?->title,
            'course_slug'  => $s->course?->slug,
            'title'        => $s->title,
            'description'  => $s->description,
            // ISO date/time (consistent naming)
            'starts_at'    => $startsAt,
            'start_at'     => $startsAt,
            'ends_at'      => $endsAt,
            'end_at'       => $endsAt,
            // Raw fields
            'session_date' => $s->start_at?->toDateString(),
            'start_time'   => $s->start_at?->format('H:i'),
            'end_time'     => $s->end_at?->format('H:i'),
            'meeting_url'  => $s->meeting_link,
            'meeting_link' => $s->meeting_link,
            'location'     => $s->location,
            'status'       => $s->status ?? 'draft',
            'visibility'   => $s->is_visible ? 'published' : 'draft',
            'is_visible'   => (bool) $s->is_visible,
            'type'         => 'course_session',
        ];
    }
}
