<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\LmsSessionResource;
use App\Models\CourseSession;
use App\Models\LmsSession;
use App\Models\SessionLinkOpen;
use App\Policies\LmsSessionPolicy;
use App\Services\LmsEnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class StudentSessionController extends Controller
{
    /**
     * GET /api/student/sessions/{session}
     *
     * Canonical live-session detail for a student — class-scoped via
     * LmsSessionPolicy::view() (active membership required for class-scoped
     * sessions; another class in the same course, or a removed member, is
     * denied with 403/404 rather than an empty 200).
     */
    public function show(Request $request, LmsSession $session): JsonResponse
    {
        $user = $request->user();
        if (! (new LmsSessionPolicy())->view($user, $session)) {
            abort(404);
        }

        $session->load(['course', 'classGroup', 'instructor', 'attendances' => fn ($q) => $q->where('user_id', $user->id)]);

        return response()->json([
            'success' => true,
            'data'    => LmsSessionResource::make($session),
        ]);
    }

    /**
     * GET /api/student/sessions/calendar?from=...&to=...
     *
     * Bounded-range calendar of live sessions the student is eligible to see
     * (class-scoped exactly like index(), never the whole course's history).
     */
    public function calendar(Request $request, LmsEnrollmentService $enrollment): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to'   => ['required', 'date', 'after_or_equal:from'],
        ]);

        $user = $request->user();
        $courseIds = $enrollment->enrolledCourseIds($user);
        $myClassGroupIds = $enrollment->classGroupIdsByCourse($user)->values();

        if ($courseIds->isEmpty()) {
            return response()->json(['data' => [], 'meta' => ['from' => $data['from'], 'to' => $data['to']]]);
        }

        $sessions = LmsSession::with(['course', 'classGroup', 'instructor'])
            ->whereIn('course_id', $courseIds)
            ->visibleToClassGroupIn($myClassGroupIds)
            ->whereBetween('session_date', [
                Carbon::parse($data['from'])->toDateString(),
                Carbon::parse($data['to'])->toDateString(),
            ])
            ->whereNotIn('status', ['cancelled', 'archived'])
            ->orderBy('session_date')->orderBy('start_time')
            ->get();

        return response()->json([
            'data' => LmsSessionResource::collection($sessions),
            'meta' => [
                'from'     => $data['from'],
                'to'       => $data['to'],
                'timezone' => config('app.booking_timezone', 'Europe/Amsterdam'),
            ],
        ]);
    }

    /**
     * GET /api/student/sessions
     * GET /api/student/upcoming-sessions
     *
     * Returns a unified list of sessions (LMS sessions + course content sessions)
     * scoped to courses the authenticated student is enrolled in.
     *
     * All items share consistent ISO datetime fields: starts_at, ends_at.
     */
    public function index(Request $request, LmsEnrollmentService $enrollment): JsonResponse
    {
        $user      = $request->user();
        $courseIds = $enrollment->enrolledCourseIds($user);

        if ($courseIds->isEmpty()) {
            return response()->json([
                'success' => true,
                'data'    => [],
                'meta'    => ['total' => 0],
            ]);
        }

        // The class groups this student is actively assigned to, across all
        // their courses. class_group_id is a single global PK (never re-used
        // across courses), so "unscoped OR in this set" is exactly equivalent
        // to per-course matching, without needing to join back through course_id.
        $myClassGroupIds = $enrollment->classGroupIdsByCourse($user)->values();

        $statusFilter = $request->query('status');

        // ── 1. LMS Sessions (live / recorded) ────────────────────────────────
        $lmsSessions = LmsSession::with(['course:id,title,slug'])
            ->where(function ($q) use ($courseIds) {
                $q->whereIn('course_id', $courseIds)
                    ->orWhereHas('workshop', fn ($w) => $w->whereIn('course_id', $courseIds));
            })
            ->visibleToClassGroupIn($myClassGroupIds)
            ->when($statusFilter, fn ($q) => $q->where('status', $statusFilter))
            ->when(! $statusFilter, fn ($q) => $q->whereNotIn('status', ['cancelled', 'archived']))
            ->get()
            ->map(fn (LmsSession $s) => $this->formatLmsSession($s));

        // ── 2. Course Sessions (planned content sessions) ─────────────────────
        $courseSessions = CourseSession::with(['course:id,title,slug'])
            ->whereIn('course_id', $courseIds)
            ->visibleToClassGroupIn($myClassGroupIds)
            ->where('is_visible', true)
            ->when($statusFilter, fn ($q) => $q->where('status', $statusFilter))
            ->when(! $statusFilter, fn ($q) => $q->whereNotIn('status', ['cancelled', 'archived']))
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

    /**
     * POST /api/student/sessions/{session}/open-link
     *
     * Records that the student opened the meeting link, then returns the URL.
     * The {session} route-model-binding resolves CourseSession by default.
     */
    public function openLink(Request $request, LmsEnrollmentService $enrollment, int $session): JsonResponse
    {
        $user        = $request->user();
        $courseSession = CourseSession::findOrFail($session);

        // Verify enrollment
        $enrolledIds = $enrollment->enrolledCourseIds($user);
        if (! $enrolledIds->contains($courseSession->course_id)) {
            return response()->json([
                'success' => false,
                'message' => 'غير مسجّل في هذه الدورة.',
            ], 403);
        }

        // Block ended / cancelled
        if ($courseSession->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'تم إلغاء هذه الجلسة.',
            ], 409);
        }

        $now   = now();
        $endAt = $courseSession->end_at;
        if ($endAt && $endAt->lt($now) && $courseSession->status !== 'live') {
            return response()->json([
                'success' => false,
                'message' => 'انتهت الجلسة.',
            ], 409);
        }

        if (! $courseSession->meeting_link) {
            return response()->json([
                'success' => false,
                'message' => 'لا يوجد رابط اجتماع لهذه الجلسة.',
            ], 422);
        }

        // Record/update the open event
        SessionLinkOpen::updateOrCreate(
            [
                'session_id'   => $courseSession->id,
                'session_type' => 'course_session',
                'user_id'      => $user->id,
            ],
            [
                'course_id'  => $courseSession->course_id,
                'opened_at'  => $now,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]
        );

        return response()->json([
            'success'     => true,
            'meeting_url' => $courseSession->meeting_link,
            'message'     => 'تم تسجيل الدخول للجلسة.',
        ]);
    }

    private function formatLmsSession(LmsSession $s): array
    {
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
            'starts_at'    => $startsAt,
            'start_at'     => $startsAt,
            'ends_at'      => $endsAt,
            'end_at'       => $endsAt,
            'session_date' => $date,
            'start_time'   => $s->start_time,
            'end_time'     => $s->end_time,
            'meeting_url'  => $s->meeting_url,
            'meeting_link' => $s->meeting_url,
            'recording_url'=> $s->recording_url,
            'location'     => $s->location,
            'status'       => $s->status ?? 'scheduled',
            'is_visible'   => true,
            'is_cancelled' => $s->status === 'cancelled',
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
            'starts_at'    => $startsAt,
            'start_at'     => $startsAt,
            'ends_at'      => $endsAt,
            'end_at'       => $endsAt,
            'session_date' => $s->start_at?->toDateString(),
            'start_time'   => $s->start_at?->format('H:i'),
            'end_time'     => $s->end_at?->format('H:i'),
            'meeting_url'  => $s->meeting_link,
            'meeting_link' => $s->meeting_link,
            'recording_url'=> null,
            'location'     => $s->location,
            'status'       => $s->status ?? 'draft',
            'is_visible'   => (bool) $s->is_visible,
            'is_cancelled' => $s->status === 'cancelled',
            'type'         => 'course_session',
        ];
    }
}
