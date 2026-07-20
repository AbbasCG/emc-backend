<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\LmsSession;
use App\Services\AttendanceStatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Student-scoped attendance endpoints.
 *
 * Students can only see their own attendance records — IDOR prevented by
 * always scoping queries to request()->user()->id.
 */
class StudentAttendanceController extends Controller
{
    /**
     * GET /api/student/attendance
     *
     * List all attendance records for the authenticated student,
     * with session and course metadata.
     */
    public function index(Request $request): JsonResponse
    {
        $user    = $request->user();
        $perPage = min((int) $request->query('per_page', 25), 100);

        $attendances = Attendance::with([
            'session:id,title,course_id,session_date,start_time',
            'session.course:id,title',
            'courseSession:id,title,course_id,start_at',
            'courseSession.course:id,title',
        ])
            ->where('user_id', $user->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('checked_in_at')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $data = $attendances->getCollection()->map(function (Attendance $att) {
            $lmsSession    = $att->session;
            $courseSession = $att->courseSession;
            $course        = $lmsSession?->course ?? $courseSession?->course;
            $sessionTitle  = $lmsSession?->title ?? $courseSession?->title ?? '—';

            if ($lmsSession) {
                $dateStr  = $lmsSession->session_date?->toDateString();
                $startsAt = $dateStr && $lmsSession->start_time
                    ? $dateStr . 'T' . $lmsSession->start_time . ':00'
                    : $dateStr;
            } else {
                $startsAt = $courseSession?->start_at?->toIso8601String();
                $dateStr  = $courseSession?->start_at?->toDateString();
            }

            return [
                'id'                 => $att->id,
                'session_id'         => $att->session_id,
                'course_session_id'  => $att->course_session_id,
                'session_title'      => $sessionTitle,
                'course_id'          => $course?->id,
                'course_title'       => $course?->title,
                'date'               => $dateStr,
                'starts_at'          => $startsAt,
                'status'             => $att->status,
                'notes'              => $att->notes,
                'marked_at'          => ($att->checked_in_at ?? $att->created_at)?->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $data,
            'meta'    => [
                'total'        => $attendances->total(),
                'current_page' => $attendances->currentPage(),
                'last_page'    => $attendances->lastPage(),
                'per_page'     => $attendances->perPage(),
            ],
        ]);
    }

    /**
     * GET /api/student/attendance/{session}
     *
     * Show the authenticated student's own attendance record for a specific session.
     * {session} resolves to LmsSession or CourseSession via AppServiceProvider binding.
     */
    public function show(Request $request, $session): JsonResponse
    {
        $user = $request->user();

        [$courseId, $sessionId, $courseSessionId, $sessionTitle, $sessionDate, $startsAt]
            = $this->sessionMeta($session);

        // Scope strictly to the authenticated student — prevents IDOR.
        $attendance = Attendance::where('user_id', $user->id)
            ->where(function ($q) use ($sessionId, $courseSessionId) {
                if ($sessionId !== null) {
                    $q->where('session_id', $sessionId);
                }
                if ($courseSessionId !== null) {
                    $q->orWhere('course_session_id', $courseSessionId);
                }
            })
            ->first();

        return response()->json([
            'success' => true,
            'data'    => [
                'session_id'        => $session->id,
                'session_type'      => $session instanceof LmsSession ? 'lms_session' : 'course_session',
                'session_title'     => $sessionTitle,
                'session_date'      => $sessionDate,
                'starts_at'         => $startsAt,
                'course_id'         => $courseId,
                'course_title'      => $session->course?->title,
                'status'            => $attendance?->status,
                'notes'             => $attendance?->notes,
                'marked_at'         => ($attendance?->checked_in_at ?? $attendance?->created_at)?->toIso8601String(),
                'attendance_id'     => $attendance?->id,
            ],
        ]);
    }

    /**
     * GET /api/student/attendance/summary
     *
     * Ticket 6: the authenticated student's own attendance statistics/
     * streaks — never accepts user_id from the request, always derives the
     * student from auth. Optional `course_id` filter.
     */
    public function summary(Request $request, AttendanceStatisticsService $stats): JsonResponse
    {
        $courseId = $request->query('course_id') ? (int) $request->query('course_id') : null;

        return response()->json([
            'success' => true,
            'data'    => $stats->forStudent($request->user()->id, $courseId),
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Returns normalised meta-data for either session type.
     * [courseId, lmsSessionId|null, courseSessionId|null, title, dateString, startsAt]
     */
    private function sessionMeta(mixed $session): array
    {
        if ($session instanceof LmsSession) {
            $date     = $session->session_date?->toDateString();
            $startsAt = $date && $session->start_time
                ? $date . 'T' . $session->start_time . ':00'
                : $date;

            return [$session->course_id, $session->id, null, $session->title, $date, $startsAt];
        }

        // CourseSession
        $startsAt = $session->start_at?->toIso8601String();

        return [
            $session->course_id,
            null,
            $session->id,
            $session->title,
            $session->start_at?->toDateString(),
            $startsAt,
        ];
    }
}
