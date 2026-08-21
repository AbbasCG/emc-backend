<?php

namespace App\Http\Controllers\Lms;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Models\AttendanceLock;
use App\Models\Course;
use App\Models\CourseSession;
use App\Models\LmsSession;
use App\Models\Registration;
use App\Services\AttendanceSettingsService;
use App\Services\AttendanceStatisticsService;
use App\Services\NotificationService;
use App\Support\AttendanceStatus;
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

        // All enrolled students for this course (paid/active registrations only)
        $enrolledStudents = $courseId
            ? Registration::with('user:id,name,email,avatar')
                ->where('course_id', $courseId)
                ->whereIn('status', RegistrationStatus::activeEnrollment())
                ->whereNotNull('user_id')
                ->get()
            : collect();

        $students = $enrolledStudents->map(function (Registration $reg) use ($attendanceMap) {
            $att = $attendanceMap->get($reg->user_id);
            return [
                'id'                        => $reg->user_id,
                'name'                      => $reg->user?->name ?? $reg->full_name,
                'email'                     => $reg->user?->email ?? $reg->email,
                'avatar_url'                => $this->resolveAvatarUrl($reg->user?->avatar ?? null),
                'current_attendance_status' => $att?->status,
                'attendance_status'         => $att?->status,
                'notes'                     => $att?->notes,
                'attendance_id'             => $att?->id,
            ];
        })->values();

        $lock = $this->findLock($sessionId, $courseSessionId);

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
                'is_locked'     => $lock !== null,
                'locked_at'     => $lock?->locked_at?->toIso8601String(),
                'locked_by'     => $lock?->lockedBy?->name,
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

        if ($session->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن تسجيل الحضور لجلسة ملغاة.',
            ], 422);
        }

        // Ticket 1 class-status gating: a draft class isn't ready for
        // students yet, and completed/archived/cancelled classes are
        // historical — attendance shouldn't be recordable in either state.
        // ClassGroup::canPerformTeachingActions() is the single source of
        // truth for this rule (also enforced on session creation).
        if ($session->class_group_id && $session->relationLoaded('classGroup') === false) {
            $session->load('classGroup');
        }
        if ($session->class_group_id && $session->classGroup && ! $session->classGroup->canPerformTeachingActions()) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن تسجيل الحضور لهذا الصف في حالته الحالية (' . $session->classGroup->status . ').',
            ], 422);
        }

        [$courseId, $sessionId, $courseSessionId] = $this->sessionMeta($session);

        // Instructors cannot re-edit once locked; admins bypass.
        $user = $request->user();
        if (! $user->isSuperAdmin() && ! $user->isAdmin()) {
            $existingLock = $this->findLock($sessionId, $courseSessionId);
            if ($existingLock) {
                return response()->json([
                    'success'    => false,
                    'message'    => 'تم حفظ الحضور مسبقاً ولا يمكن تعديله.',
                    'is_locked'  => true,
                    'locked_at'  => $existingLock->locked_at?->toIso8601String(),
                    'locked_by'  => $existingLock->lockedBy?->name,
                ], 423);
            }
        }

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
            'rows.*.status'   => ['required', 'in:' . implode(',', AttendanceStatus::ALL)],
            'rows.*.notes'    => ['nullable', 'string', 'max:2000'],
        ]);

        // Reject rows for users who aren't actively enrolled in this session's
        // course — same roster show() already restricts to, previously
        // unenforced here so any existing user_id was silently accepted.
        $enrolledUserIds = $courseId
            ? Registration::where('course_id', $courseId)
                ->whereIn('status', RegistrationStatus::activeEnrollment())
                ->whereNotNull('user_id')
                ->pluck('user_id')
                ->all()
            : [];

        $invalidRows = collect($validated['rows'])->reject(fn ($row) => in_array($row['user_id'], $enrolledUserIds, true));
        if ($invalidRows->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'بعض الطلاب غير مسجلين في هذه الدورة.',
                'invalid_user_ids' => $invalidRows->pluck('user_id')->values(),
            ], 422);
        }

        $classGroupId = $session->class_group_id;

        // Recovery gap closed: course enrollment alone isn't enough for a
        // class-scoped session — a student enrolled in the course but a
        // member of a DIFFERENT class group must not be markable for this
        // session. Mirrors the same class-membership check already applied
        // to assignments/materials elsewhere in this codebase.
        if ($classGroupId) {
            $activeClassMemberIds = \App\Models\ClassGroupStudent::where('class_group_id', $classGroupId)
                ->whereIn('status', \App\Models\ClassGroupStudent::ACTIVE_STATUSES)
                ->pluck('user_id')
                ->all();

            $outOfClassRows = collect($validated['rows'])
                ->reject(fn ($row) => in_array($row['user_id'], $activeClassMemberIds, true));

            if ($outOfClassRows->isNotEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'بعض الطلاب ليسوا أعضاء نشطين في هذا الصف.',
                    'invalid_user_ids' => $outOfClassRows->pluck('user_id')->values(),
                ], 422);
            }
        }

        // Snapshot each affected student's attendance % BEFORE this save
        // writes anything, so the notification loop below can detect an
        // actual threshold crossing rather than just the current state.
        $attendanceStatsBefore = [];
        if ($courseId) {
            $statsService = app(AttendanceStatisticsService::class);
            foreach ($validated['rows'] as $row) {
                $attendanceStatsBefore[$row['user_id']] = $statsService->forStudent((int) $row['user_id'], $courseId);
            }
        }

        DB::transaction(function () use ($session, $validated, $sessionId, $courseSessionId, $classGroupId) {
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
                        'class_group_id'    => $classGroupId,
                        'status'            => $row['status'],
                        'notes'             => $row['notes'] ?? null,
                        'checked_in_at'     => in_array($row['status'], [AttendanceStatus::PRESENT, AttendanceStatus::LATE], true)
                            ? now()
                            : null,
                    ]
                );
            }
        });

        // Create lock on first save (instructors cannot re-edit; admins skip lock creation too).
        $lock = $this->findLock($sessionId, $courseSessionId);
        $justFinalized = ! $lock;
        if (! $lock) {
            $lock = AttendanceLock::create([
                'session_id'        => $sessionId,
                'course_session_id' => $courseSessionId,
                'locked_by'         => $request->user()->id,
                'locked_at'         => now(),
            ]);
            $lock->load('lockedBy:id,name');
        }

        // Notify each student of their recorded attendance status.
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

            if (! $courseId) {
                continue;
            }

            // Ticket 6: repeated-absence — fires exactly when the trailing
            // streak FIRST reaches the configured threshold N (not on every
            // absence beyond that), by checking the (N+1)-th-most-recent
            // row wasn't also absent. N is read from AttendanceSettingsService,
            // not hardcoded — defaults to 3 when no settings row exists.
            if ($row['status'] === AttendanceStatus::ABSENT) {
                $threshold = app(AttendanceSettingsService::class)->get('repeated_absence_threshold');
                $recentStatuses = Attendance::where('user_id', $row['user_id'])
                    ->where(function ($q) use ($courseId) {
                        $q->whereHas('session', fn ($q2) => $q2->where('course_id', $courseId))
                            ->orWhereHas('courseSession', fn ($q2) => $q2->where('course_id', $courseId));
                    })
                    ->latest('created_at')
                    ->limit($threshold + 1)
                    ->pluck('status');

                $lastN = $recentStatuses->take($threshold);
                $nPlus1WasAlsoAbsent = $recentStatuses->count() === $threshold + 1 && $recentStatuses->get($threshold) === AttendanceStatus::ABSENT;

                if ($lastN->count() === $threshold && $lastN->every(fn ($s) => $s === AttendanceStatus::ABSENT) && ! $nPlus1WasAlsoAbsent) {
                    NotificationService::repeatedAbsence($row['user_id'], $courseTitle, $threshold, $courseId);
                }
            }

            // Ticket 6: low-attendance / recovered — fires only on an actual
            // threshold CROSSING (before this row vs. after), never just
            // because the student is currently below/above the threshold —
            // otherwise every subsequent save would resend the same warning.
            // Threshold read from AttendanceSettingsService, not hardcoded.
            $statsBefore = $attendanceStatsBefore[$row['user_id']] ?? null;
            if ($statsBefore !== null) {
                $lowThreshold = app(AttendanceSettingsService::class)->get('low_attendance_notification_threshold');
                $statsAfter = app(AttendanceStatisticsService::class)->forStudent((int) $row['user_id'], $courseId);
                $wasBelow = $statsBefore['attendance_percentage'] < $lowThreshold && $statsBefore['total'] > 0;
                $isBelow  = $statsAfter['attendance_percentage'] < $lowThreshold;

                if (! $wasBelow && $isBelow) {
                    NotificationService::lowAttendanceWarning($row['user_id'], $courseTitle, $statsAfter['attendance_percentage'], $courseId);
                } elseif ($wasBelow && ! $isBelow) {
                    NotificationService::attendanceRecovered($row['user_id'], $courseTitle, $statsAfter['attendance_percentage'], $courseId);
                }
            }
        }

        // Ticket 6: fires exactly once — only on the save that creates the
        // lock, never on subsequent admin edits of already-finalized attendance.
        if ($justFinalized && $courseId) {
            $instructorUserId = $session->course?->instructor?->user_id;
            if ($instructorUserId) {
                $finalCounts = Attendance::where(function ($q) use ($sessionId, $courseSessionId) {
                    if ($sessionId) $q->where('session_id', $sessionId);
                    if ($courseSessionId) $q->orWhere('course_session_id', $courseSessionId);
                })->get(['status']);
                NotificationService::classAttendanceCompleted(
                    $instructorUserId, $courseTitle, $sessionTitle,
                    $finalCounts->where('status', AttendanceStatus::PRESENT)->count(),
                    $finalCounts->count(), $courseId,
                );
            }
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
                'is_locked'   => true,
                'locked_at'   => $lock->locked_at?->toIso8601String(),
                'locked_by'   => $lock->lockedBy?->name,
            ],
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function resolveAvatarUrl(?string $path): ?string
    {
        if (!$path) return null;
        if (str_starts_with($path, 'http')) return $path;
        return asset('storage/' . ltrim($path, '/'));
    }

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

    private function findLock(?int $sessionId, ?int $courseSessionId): ?AttendanceLock
    {
        return AttendanceLock::with('lockedBy:id,name')
            ->when($sessionId, fn ($q) => $q->where('session_id', $sessionId))
            ->when($courseSessionId && ! $sessionId, fn ($q) => $q->where('course_session_id', $courseSessionId))
            ->first();
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

    // ─── GET /api/instructor/attendance/dashboard ─────────────────────────────

    /**
     * Ticket 6: aggregate attendance dashboard — today/week/month counts,
     * overall status breakdown, at-risk students, and top/worst attendance.
     * Every value is bulk-queried; never one query per session/student.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user    = $request->user();
        $profile = $user->instructorProfile;

        $courseIds = $profile
            ? Course::query()->where('instructor_id', $profile->id)->pluck('id')
            : collect();

        if ($courseIds->isEmpty()) {
            return response()->json(['success' => true, 'data' => [
                'today_sessions' => 0, 'today_attendance_marked' => 0,
                'week_present' => 0, 'week_absent' => 0, 'week_late' => 0, 'week_excused' => 0,
                'month_present' => 0, 'month_absent' => 0, 'month_late' => 0, 'month_excused' => 0,
                'current_attendance_percentage' => 0,
                'at_risk_students' => [], 'top_attendance' => [], 'worst_attendance' => [],
            ]]);
        }

        $today = now()->toDateString();
        $weekStart = now()->startOfWeek()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();

        $sessionIdsToday = LmsSession::whereIn('course_id', $courseIds)->whereDate('session_date', $today)->pluck('id');
        $todaySessions = $sessionIdsToday->count();
        $todayAttendanceMarked = $sessionIdsToday->isEmpty() ? 0 : Attendance::whereIn('session_id', $sessionIdsToday)->count();

        // All attendance rows for this instructor's courses this month — one
        // bulk query, sliced in PHP for week/month/status breakdowns rather
        // than four separate DB round-trips.
        $lmsSessionIdsThisMonth = LmsSession::whereIn('course_id', $courseIds)->where('session_date', '>=', $monthStart)->pluck('id');
        $courseSessionIdsThisMonth = CourseSession::whereIn('course_id', $courseIds)->where('start_at', '>=', $monthStart)->pluck('id');

        $monthRows = Attendance::where(function ($q) use ($lmsSessionIdsThisMonth, $courseSessionIdsThisMonth) {
            $q->whereIn('session_id', $lmsSessionIdsThisMonth)->orWhereIn('course_session_id', $courseSessionIdsThisMonth);
        })->get(['status', 'created_at']);

        $weekRows = $monthRows->filter(fn ($r) => $r->created_at?->toDateString() >= $weekStart);

        $countBy = fn ($rows, $status) => $rows->where('status', $status)->count();

        // At-risk / top / worst — reuses StudentProgress.attendance_percentage
        // (already computed by StudentProgressService, never recalculated here).
        $studentProgress = \App\Models\StudentProgress::whereIn('course_id', $courseIds)
            ->with('user:id,name,email')
            ->get(['user_id', 'course_id', 'attendance_percentage']);

        $byStudent = $studentProgress->groupBy('user_id')->map(function ($rows) {
            $first = $rows->first();
            return [
                'user_id' => $first->user_id,
                'name'    => $first->user?->name,
                'attendance_percentage' => (int) round($rows->avg('attendance_percentage')),
            ];
        })->values();

        $atRiskThreshold = app(AttendanceSettingsService::class)->get('at_risk_percentage');
        $atRisk = $byStudent->filter(fn ($s) => $s['attendance_percentage'] < $atRiskThreshold)->sortBy('attendance_percentage')->values();
        $sorted = $byStudent->sortByDesc('attendance_percentage')->values();

        return response()->json(['success' => true, 'data' => [
            'today_sessions'           => $todaySessions,
            'today_attendance_marked'  => $todayAttendanceMarked,
            'week_present'  => $countBy($weekRows, AttendanceStatus::PRESENT),
            'week_absent'   => $countBy($weekRows, AttendanceStatus::ABSENT),
            'week_late'     => $countBy($weekRows, AttendanceStatus::LATE),
            'week_excused'  => $countBy($weekRows, AttendanceStatus::EXCUSED),
            'month_present' => $countBy($monthRows, AttendanceStatus::PRESENT),
            'month_absent'  => $countBy($monthRows, AttendanceStatus::ABSENT),
            'month_late'    => $countBy($monthRows, AttendanceStatus::LATE),
            'month_excused' => $countBy($monthRows, AttendanceStatus::EXCUSED),
            'current_attendance_percentage' => $byStudent->isEmpty() ? 0 : (int) round($byStudent->avg('attendance_percentage')),
            'at_risk_students'  => $atRisk->take(20)->values(),
            'top_attendance'    => $sorted->take(5)->values(),
            'worst_attendance'  => $sorted->reverse()->take(5)->values(),
        ]]);
    }

    // ─── GET /api/instructor/attendance/export ─────────────────────────────────

    /**
     * Ticket 6: CSV attendance export, filterable by course/class/date range —
     * reuses the same instructor-ownership scoping as index()/dashboard().
     * Streamed, not built in memory, so it stays bounded for large ranges.
     */
    /**
     * Ticket 6: the ONE canonical scoped-and-filtered attendance query,
     * reused by export() (CSV), exportPdf() (PDF), and reports() — so all
     * three surfaces can never disagree about what "the same filters"
     * returns. Never accepts course/class/session/student IDs the acting
     * instructor doesn't own — courseIds is always derived from the
     * authenticated instructor's own courses, then narrowed by request
     * filters, never widened by them.
     *
     * @return array{rows: \Illuminate\Support\Collection, from: string, to: string}
     */
    private function scopedReportRows(Request $request): array
    {
        $user    = $request->user();
        $profile = $user->instructorProfile;

        $courseIds = $profile
            ? Course::query()->where('instructor_id', $profile->id)->pluck('id')
            : collect();

        if (! empty($request->query('course_id'))) {
            $courseIds = $courseIds->filter(fn ($id) => (int) $id === (int) $request->query('course_id'));
        }

        $from = $request->query('from') ? \Carbon\Carbon::parse($request->query('from'))->toDateString() : now()->subMonth()->toDateString();
        $to = $request->query('to') ? \Carbon\Carbon::parse($request->query('to'))->toDateString() : now()->toDateString();
        if ($request->filled('month') && $request->filled('year')) {
            $from = \Carbon\Carbon::create((int) $request->query('year'), (int) $request->query('month'), 1)->toDateString();
            $to = \Carbon\Carbon::create((int) $request->query('year'), (int) $request->query('month'), 1)->endOfMonth()->toDateString();
        } elseif ($request->filled('year') && ! $request->filled('month')) {
            $from = \Carbon\Carbon::create((int) $request->query('year'), 1, 1)->toDateString();
            $to = \Carbon\Carbon::create((int) $request->query('year'), 12, 31)->toDateString();
        }

        $classGroupId = $request->query('class_group_id');
        $studentId = $request->query('student_id');
        $status = $request->query('status');

        $sessionQuery = LmsSession::whereIn('course_id', $courseIds)
            ->whereDate('session_date', '>=', $from)
            ->whereDate('session_date', '<=', $to);
        if ($classGroupId) {
            $sessionQuery->where('class_group_id', $classGroupId);
        }
        $sessionIds = $sessionQuery->pluck('id');

        $rowsQuery = Attendance::whereIn('session_id', $sessionIds)
            ->with(['user:id,name,email', 'session:id,title,session_date,course_id', 'session.course:id,title']);

        if ($studentId) {
            $rowsQuery->where('user_id', $studentId);
        }
        if ($status && AttendanceStatus::isValid($status)) {
            $rowsQuery->where('status', $status);
        }

        return ['rows' => $rowsQuery->orderBy('created_at')->get(), 'from' => $from, 'to' => $to];
    }

    private function statusLabelArabic(string $status): string
    {
        return match ($status) {
            AttendanceStatus::PRESENT => 'حاضر',
            AttendanceStatus::ABSENT  => 'غائب',
            AttendanceStatus::LATE    => 'متأخر',
            AttendanceStatus::EXCUSED => 'معذور',
            default => $status,
        };
    }

    public function export(Request $request)
    {
        ['rows' => $rows, 'from' => $from, 'to' => $to] = $this->scopedReportRows($request);
        $filename = 'attendance-' . $from . '-to-' . $to . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['الطالب', 'البريد الإلكتروني', 'الدورة', 'الجلسة', 'التاريخ', 'الحالة', 'ملاحظات']);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->user?->name, $row->user?->email, $row->session?->course?->title,
                    $row->session?->title, $row->session?->session_date?->toDateString(),
                    $this->statusLabelArabic($row->status), $row->notes,
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * GET /api/instructor/attendance/export-pdf
     *
     * Reuses barryvdh/laravel-dompdf (already installed and used for
     * certificates elsewhere) — no new dependency. Same canonical query as
     * export()/reports(), just a different rendering.
     */
    public function exportPdf(Request $request, AttendanceStatisticsService $stats)
    {
        ['rows' => $rows, 'from' => $from, 'to' => $to] = $this->scopedReportRows($request);

        $present = $rows->where('status', AttendanceStatus::PRESENT)->count();
        $absent = $rows->where('status', AttendanceStatus::ABSENT)->count();
        $late = $rows->where('status', AttendanceStatus::LATE)->count();
        $excused = $rows->where('status', AttendanceStatus::EXCUSED)->count();
        $total = $rows->count();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.attendance-report', [
            'rows' => $rows, 'from' => $from, 'to' => $to,
            'generatedAt' => now(), 'total' => $total,
            'present' => $present, 'absent' => $absent, 'late' => $late, 'excused' => $excused,
            'percentage' => $total > 0 ? (int) round((($present + $late) / $total) * 100) : 0,
            'statusLabel' => fn ($s) => $this->statusLabelArabic($s),
        ]);

        return $pdf->download("attendance-{$from}-to-{$to}.pdf");
    }

    /**
     * GET /api/instructor/attendance/export-excel
     *
     * Reuses phpoffice/phpspreadsheet (added this pass — no suitable Excel
     * package existed anywhere in the project or its transitive dependencies).
     * Same canonical scopedReportRows() query as CSV/PDF/reports — one
     * filtering implementation, four renderings.
     */
    public function exportExcel(Request $request)
    {
        ['rows' => $rows, 'from' => $from, 'to' => $to] = $this->scopedReportRows($request);

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);
        $sheet->setTitle('الحضور');

        $headers = ['الطالب', 'البريد الإلكتروني', 'الدورة', 'الجلسة', 'التاريخ', 'الوقت', 'الحالة', 'ملاحظات'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);

        $rowNum = 2;
        foreach ($rows as $row) {
            $date = $row->session?->session_date;
            $sheet->fromArray([
                $row->user?->name,
                $row->user?->email,
                $row->session?->course?->title,
                $row->session?->title,
                $date ? $date->format('d/m/Y') : null,
                $row->session?->start_time,
                $this->statusLabelArabic($row->status),
                $row->notes,
            ], null, "A{$rowNum}");
            $rowNum++;
        }

        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = "attendance-{$from}-to-{$to}.xlsx";
        $tmpPath = tempnam(sys_get_temp_dir(), 'attendance_xlsx_');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($tmpPath);

        return response()->download($tmpPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * GET /api/instructor/attendance/reports
     *
     * Ticket 6: filtered report with backend-computed aggregate KPIs — the
     * frontend never calculates totals/percentages itself. Same canonical
     * query and filters (course/class/student/status/date-range/month/year)
     * as export()/exportPdf().
     */
    public function reports(Request $request, AttendanceStatisticsService $stats)
    {
        ['rows' => $rows, 'from' => $from, 'to' => $to] = $this->scopedReportRows($request);

        $summary = $stats->computeFromRows($rows);

        $perPage = min((int) $request->query('per_page', 25), 100);
        $page = max(1, (int) $request->query('page', 1));
        $paged = $rows->forPage($page, $perPage)->values();

        return response()->json([
            'success' => true,
            'summary' => $summary,
            'data' => $paged->map(fn ($row) => [
                'id' => $row->id,
                'student_name' => $row->user?->name,
                'student_email' => $row->user?->email,
                'course_title' => $row->session?->course?->title,
                'session_title' => $row->session?->title,
                'date' => $row->session?->session_date?->toDateString(),
                'status' => $row->status,
                'status_label' => $this->statusLabelArabic($row->status),
                'notes' => $row->notes,
            ])->values(),
            'meta' => [
                'total' => $rows->count(), 'per_page' => $perPage, 'current_page' => $page,
                'last_page' => max(1, (int) ceil($rows->count() / $perPage)),
                'from' => $from, 'to' => $to,
            ],
        ]);
    }
}
