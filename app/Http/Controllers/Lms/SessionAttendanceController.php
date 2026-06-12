<?php

namespace App\Http\Controllers\Lms;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Models\Course;
use App\Models\CourseSession;
use App\Models\LmsSession;
use App\Models\Registration;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Attendance management for instructors.
 *
 * {session} route parameter resolves (via AppServiceProvider binding) to either
 * an LmsSession (live class) or a CourseSession (content session).  Both are
 * supported transparently so instructors do not have to know the difference.
 */
class SessionAttendanceController extends Controller
{
    // ─── GET /api/instructor/attendance ──────────────────────────────────────

    /**
     * List all sessions belonging to the instructor's courses, with attendance summary.
     */
    public function index(Request $request): JsonResponse
    {
        $user    = $request->user();
        $profile = $user->instructorProfile;

        $courseIds = $profile
            ? Course::query()->where('instructor_id', $profile->id)->pluck('id')
            : collect();

        $perPage = min((int) $request->query('per_page', 25), 100);

        // ── LMS sessions ──────────────────────────────────────────────────────
        $lmsSessions = LmsSession::with(['course:id,title,slug', 'attendances'])
            ->where(function ($q) use ($profile, $courseIds) {
                $q->whereIn('course_id', $courseIds);
                if ($profile) {
                    $q->orWhere('instructor_id', $profile->id);
                }
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('session_date')
            ->get()
            ->map(fn (LmsSession $s) => $this->formatLmsSessionSummary($s));

        // ── Course sessions ───────────────────────────────────────────────────
        $courseSessions = CourseSession::with(['course:id,title,slug'])
            ->whereIn('course_id', $courseIds)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('start_at')
            ->get()
            ->map(fn (CourseSession $s) => $this->formatCourseSessionSummary($s));

        $all = $lmsSessions->concat($courseSessions)
            ->sortByDesc('session_date')
            ->values();

        // Paginate manually
        $page    = max(1, (int) $request->query('page', 1));
        $total   = $all->count();
        $paged   = $all->forPage($page, $perPage)->values();

        return response()->json([
            'success' => true,
            'data'    => $paged,
            'meta'    => [
                'total'        => $total,
                'current_page' => $page,
                'last_page'    => max(1, (int) ceil($total / $perPage)),
                'per_page'     => $perPage,
            ],
        ]);
    }

    // ─── GET /api/instructor/attendance/{session} ─────────────────────────────

    /**
     * Returns the session detail with a student roster and their attendance status.
     * {session} = LmsSession | CourseSession (resolved by AppServiceProvider binding).
     */
    public function show(Request $request, $session): JsonResponse
    {
        $this->authorizeSession($request, $session);

        [$courseId, $sessionId, $courseSessionId, $sessionLabel, $sessionDate, $startsAt] =
            $this->sessionMeta($session);

        // Build attendance map (keyed by user_id)
        $attendanceMap = Attendance::where(function ($q) use ($sessionId, $courseSessionId) {
            if ($sessionId) {
                $q->where('session_id', $sessionId);
            }
            if ($courseSessionId) {
                $q->orWhere('course_session_id', $courseSessionId);
            }
        })->get()->keyBy('user_id');

        // All enrolled students for this course
        $enrolledStudents = $courseId
            ? Registration::with('user:id,name,email,avatar')
                ->where('course_id', $courseId)
                ->whereNotIn('status', ['Rejected', 'Cancelled', 'rejected', 'cancelled'])
                ->whereNotNull('user_id')
                ->get()
            : collect();

        $students = $enrolledStudents->map(function (Registration $reg) use ($attendanceMap) {
            $att = $attendanceMap->get($reg->user_id);
            return [
                'id'                        => $reg->user_id,
                'name'                      => $reg->user?->name ?? $reg->full_name,
                'email'                     => $reg->user?->email ?? $reg->email,
                'avatar'                    => $reg->user?->avatar ?? null,
                'current_attendance_status' => $att?->status,
                'attendance_status'         => $att?->status,
                'notes'                     => $att?->notes,
                'attendance_id'             => $att?->id,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data'    => [
                'session_id'    => $session->id,
                'session_type'  => $session instanceof LmsSession ? 'lms_session' : 'course_session',
                'session_title' => $sessionLabel,
                'session_date'  => $sessionDate,
                'starts_at'     => $startsAt,
                'course_id'     => $courseId,
                'course_title'  => $session->course?->title,
                'students'      => $students,
            ],
        ]);
    }

    // ─── PUT /api/instructor/attendance/{session} ─────────────────────────────

    /**
     * Save attendance for a session.
     *
     * Payload:
     * {
     *   "attendances": [
     *     { "student_id": 1, "status": "present", "notes": "" }
     *   ]
     * }
     *
     * Accepted statuses: present, absent, late, excused
     */
    public function update(Request $request, $session): JsonResponse
    {
        $this->authorizeSession($request, $session);

        // Accept both `rows` (legacy) and `attendances` as payload key.
        // Accept `student_id` as alias for `user_id`.
        $rawRows = $request->input('attendances') ?? $request->input('rows') ?? [];

        $rows = collect($rawRows)->map(function ($row) {
            if (isset($row['student_id']) && ! isset($row['user_id'])) {
                $row['user_id'] = $row['student_id'];
            }
            return $row;
        })->all();

        $request->merge(['rows' => $rows]);

        $validated = $request->validate([
            'rows'            => ['required', 'array', 'min:1'],
            'rows.*.user_id'  => ['required', 'exists:users,id'],
            'rows.*.status'   => ['required', 'in:present,absent,late,excused'],
            'rows.*.notes'    => ['nullable', 'string', 'max:2000'],
        ]);

        [, $sessionId, $courseSessionId] = $this->sessionMeta($session);

        DB::transaction(function () use ($session, $validated, $sessionId, $courseSessionId) {
            foreach ($validated['rows'] as $row) {
                $match = ['user_id' => $row['user_id']];

                if ($sessionId) {
                    $match['session_id'] = $sessionId;
                } elseif ($courseSessionId) {
                    $match['course_session_id'] = $courseSessionId;
                }

                Attendance::updateOrCreate(
                    $match,
                    [
                        'session_id'        => $sessionId,
                        'course_session_id' => $courseSessionId,
                        'status'            => $row['status'],
                        'notes'             => $row['notes'] ?? null,
                        'checked_in_at'     => in_array($row['status'], ['present', 'late'], true)
                            ? now()
                            : null,
                    ]
                );
            }
        });

        // Notify each student of their recorded attendance status.
        [$courseId] = $this->sessionMeta($session);
        $courseTitle  = $session->course?->title ?? 'الدورة';
        $sessionTitle = $session->title ?? 'الجلسة';

        foreach ($validated['rows'] as $row) {
            NotificationService::attendanceMarked(
                studentUserId: $row['user_id'],
                courseTitle:   $courseTitle,
                sessionTitle:  $sessionTitle,
                status:        $row['status'],
                courseId:      $courseId ?? 0,
            );
        }

        $attendances = Attendance::with('user:id,name,email,avatar')
            ->where(function ($q) use ($sessionId, $courseSessionId) {
                if ($sessionId) {
                    $q->where('session_id', $sessionId);
                }
                if ($courseSessionId) {
                    $q->orWhere('course_session_id', $courseSessionId);
                }
            })
            ->get();

        return response()->json([
            'success'    => true,
            'message'    => 'Attendance saved successfully',
            'data'       => [
                'session_id'  => $session->id,
                'attendances' => AttendanceResource::collection($attendances),
            ],
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Ensure the authenticated instructor (or admin) is authorised to
     * manage attendance for the given session.
     */
    private function authorizeSession(Request $request, $session): void
    {
        $user = $request->user();

        if ($user->isSuperAdmin() || $user->isAdmin()) {
            return;
        }

        $profile = $user->instructorProfile;
        abort_if(! $profile, 403, 'Instructor profile not found.');

        $courseIds = Course::where('instructor_id', $profile->id)->pluck('id');

        $courseId = $session instanceof LmsSession
            ? $session->course_id
            : ($session instanceof CourseSession ? $session->course_id : null);

        $ownedByInstructor = $courseIds->contains($courseId)
            || ($session instanceof LmsSession && $session->instructor_id === $profile->id);

        abort_if(! $ownedByInstructor, 403, 'You are not authorised to manage attendance for this session.');
    }

    /**
     * Return normalised meta-data for either session type.
     * Returns: [courseId, sessionId (lms), courseSessionId, title, sessionDate, startsAt]
     */
    private function sessionMeta($session): array
    {
        if ($session instanceof LmsSession) {
            $date     = $session->session_date?->toDateString();
            $startsAt = $date && $session->start_time
                ? $date . 'T' . $session->start_time . ':00'
                : $date;

            return [
                $session->course_id,
                $session->id,   // session_id (lms)
                null,           // course_session_id
                $session->title,
                $date,
                $startsAt,
            ];
        }

        // CourseSession
        $startsAt = $session->start_at?->toIso8601String();

        return [
            $session->course_id,
            null,               // session_id (lms)
            $session->id,       // course_session_id
            $session->title,
            $session->start_at?->toDateString(),
            $startsAt,
        ];
    }

    private function formatLmsSessionSummary(LmsSession $session): array
    {
        $attendances = $session->attendances;
        $total       = $attendances->count();
        $date        = $session->session_date?->toDateString();

        return [
            'id'                   => $session->id,
            'session_type'         => 'lms_session',
            'course_id'            => $session->course_id,
            'course_title'         => $session->course?->title,
            'title'                => $session->title,
            'session_date'         => $date,
            'start_time'           => $session->start_time,
            'end_time'             => $session->end_time,
            'starts_at'            => $date && $session->start_time
                ? $date . 'T' . $session->start_time . ':00'
                : $date,
            'status'               => $session->status,
            'meeting_url'          => $session->meeting_url,
            'attendance_count'     => $total,
            'present_count'        => $attendances->where('status', 'present')->count(),
            'absent_count'         => $attendances->where('status', 'absent')->count(),
            'late_count'           => $attendances->where('status', 'late')->count(),
            'excused_count'        => $attendances->where('status', 'excused')->count(),
            'attendance_saved'     => $total > 0,
        ];
    }

    private function formatCourseSessionSummary(CourseSession $session): array
    {
        $courseSessionAttendances = Attendance::where('course_session_id', $session->id)->get();
        $total = $courseSessionAttendances->count();
        $startsAt = $session->start_at?->toIso8601String();

        return [
            'id'                   => $session->id,
            'session_type'         => 'course_session',
            'course_id'            => $session->course_id,
            'course_title'         => $session->course?->title,
            'title'                => $session->title,
            'session_date'         => $session->start_at?->toDateString(),
            'start_time'           => $session->start_at?->format('H:i'),
            'end_time'             => $session->end_at?->format('H:i'),
            'starts_at'            => $startsAt,
            'status'               => $session->status ?? 'draft',
            'meeting_url'          => $session->meeting_link,
            'attendance_count'     => $total,
            'present_count'        => $courseSessionAttendances->where('status', 'present')->count(),
            'absent_count'         => $courseSessionAttendances->where('status', 'absent')->count(),
            'late_count'           => $courseSessionAttendances->where('status', 'late')->count(),
            'excused_count'        => $courseSessionAttendances->where('status', 'excused')->count(),
            'attendance_saved'     => $total > 0,
        ];
    }
}
