<?php

namespace App\Http\Controllers\Instructor;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\LmsSessionResource;
use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\ClassAnnouncement;
use App\Models\ClassGroup;
use App\Models\ClassGroupSchedule;
use App\Models\ClassGroupStudent;
use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\CourseSession;
use App\Models\Instructor;
use App\Models\LmsSession;
use App\Models\OralAssessmentBooking;
use App\Models\PlacementTestAttempt;
use App\Models\Registration;
use App\Services\ClassSessionGenerationService;
use App\Services\LmsSessionTransitionService;
use App\Services\NotificationService;
use App\Services\PlacementProgressService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InstructorClassController extends Controller
{
    public function __construct(private readonly PlacementProgressService $placement) {}

    // ─── GET /api/instructor/classes ─────────────────────────────────────────
    // All class groups for courses assigned to the instructor.

    public function index(Request $request): JsonResponse
    {
        $instructor = Instructor::where('user_id', $request->user()->id)->first();
        $courseIds  = $instructor
            ? Course::where('instructor_id', $instructor->id)->pluck('id')->all()
            : [];

        $groups = ClassGroup::with(['course', 'activeStudents', 'schedules'])
            ->whereIn('course_id', $courseIds)
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', (int) $request->course_id))
            ->when($request->filled('level_code'), fn ($q) => $q->where('level_code', $request->level_code))
            ->latest()
            ->get()
            ->map(fn ($g) => $this->shapeGroup($g));

        return response()->json(['success' => true, 'data' => $groups]);
    }

    // ─── POST /api/instructor/classes ────────────────────────────────────────
    // Create a new class group.

    public function store(Request $request): JsonResponse
    {
        $instructor = Instructor::where('user_id', $request->user()->id)->first();

        $request->validate([
            'course_id'                  => ['required', 'integer', 'exists:courses,id'],
            'name'                       => ['required', 'string', 'max:120'],
            'level_code'                 => ['nullable', 'string', 'in:Starter,A1,A2,B1,B2,C1'],
            'capacity'                   => ['nullable', 'integer', 'min:1', 'max:200'],
            'start_date'                 => ['nullable', 'date'],
            // Deprecated single-row fields — kept only for backward compatibility
            // with older frontend builds. New clients should send `schedules`.
            'schedule_day'               => ['nullable', 'string'],
            'schedule_time'              => ['nullable', 'date_format:H:i'],
            'location_type'              => ['nullable', 'string', 'in:online,in_person,hybrid'],
            'meeting_link'               => ['nullable', 'string', 'max:500'],
            // Canonical multi-day weekly schedule.
            'schedules'                  => ['sometimes', 'array', 'max:14'],
            'schedules.*.day_of_week'    => ['required_with:schedules', 'string', 'in:' . implode(',', ClassGroupSchedule::DAYS)],
            'schedules.*.start_time'     => ['required_with:schedules', 'date_format:H:i'],
            'schedules.*.end_time'       => ['required_with:schedules', 'date_format:H:i'],
            'schedules.*.delivery_mode'  => ['nullable', 'string', 'in:online,in_person,hybrid'],
            'schedules.*.location'       => ['nullable', 'string', 'max:255'],
        ]);

        if ($request->filled('schedules')) {
            $this->validateSchedules($request->input('schedules'));
        }

        abort_if(! $instructor, 403, 'ليس لديك صلاحية إنشاء مجموعات لهذه الدورة.');
        $course = Course::find($request->course_id);
        if (! $course || (int) $course->instructor_id !== $instructor->id) {
            abort(403, 'ليس لديك صلاحية إنشاء مجموعات لهذه الدورة.');
        }

        $firstSchedule = $request->input('schedules.0');

        $group = ClassGroup::create([
            'course_id'     => $request->course_id,
            'instructor_id' => $instructor?->id,
            'name'          => $request->name,
            'level_code'    => $request->level_code,
            'capacity'      => $request->capacity ?? 20,
            'start_date'    => $request->start_date,
            // Deprecated columns kept in sync from the first schedule row (or
            // the legacy scalar fields) so old readers keep working.
            'schedule_day'  => $firstSchedule['day_of_week']  ?? $request->schedule_day,
            'schedule_time' => $firstSchedule['start_time']   ?? $request->schedule_time,
            'location_type' => $firstSchedule['delivery_mode'] ?? $request->location_type ?? 'online',
            'meeting_link'  => $request->meeting_link,
            'status'        => 'draft',
            'created_by'    => $request->user()->id,
        ]);

        if ($request->filled('schedules')) {
            $this->syncSchedules($group, $request->input('schedules'));
        }

        $group->load(['course', 'activeStudents', 'schedules']);

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء المجموعة بنجاح.',
            'data'    => $this->shapeGroup($group),
        ], 201);
    }

    // ─── PATCH /api/instructor/classes/{group} ───────────────────────────────
    // Update class group status/details.

    public function update(Request $request, ClassGroup $group): JsonResponse
    {
        $this->authorizeGroup($request, $group);

        // A completed/archived class's configuration is frozen, but its
        // status itself must remain changeable (e.g. archiving a completed
        // class, or reopening one) — only block when the request also
        // touches non-status fields.
        $nonStatusKeys = array_diff($request->keys(), ['status']);
        if (! empty($nonStatusKeys) && ! $group->canBeEdited()) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن تعديل بيانات هذا الصف في حالته الحالية (' . $group->status . ').',
            ], 422);
        }

        $request->validate([
            'name'                       => ['sometimes', 'string', 'max:120'],
            'level_code'                 => ['sometimes', 'nullable', 'string', 'in:Starter,A1,A2,B1,B2,C1'],
            'capacity'                   => ['sometimes', 'integer', 'min:1', 'max:200'],
            'start_date'                 => ['sometimes', 'nullable', 'date'],
            'schedule_day'               => ['sometimes', 'nullable', 'string'],
            'schedule_time'              => ['sometimes', 'nullable', 'date_format:H:i'],
            'location_type'              => ['sometimes', 'string', 'in:online,in_person,hybrid'],
            'meeting_link'               => ['sometimes', 'nullable', 'string', 'max:500'],
            'status'                     => ['sometimes', 'string', 'in:draft,ready,active,completed,archived'],
            'schedules'                  => ['sometimes', 'array', 'max:14'],
            'schedules.*.day_of_week'    => ['required_with:schedules', 'string', 'in:' . implode(',', ClassGroupSchedule::DAYS)],
            'schedules.*.start_time'     => ['required_with:schedules', 'date_format:H:i'],
            'schedules.*.end_time'       => ['required_with:schedules', 'date_format:H:i'],
            'schedules.*.delivery_mode'  => ['nullable', 'string', 'in:online,in_person,hybrid'],
            'schedules.*.location'       => ['nullable', 'string', 'max:255'],
        ]);

        if ($request->has('schedules')) {
            $this->validateSchedules($request->input('schedules'));
        }

        $group->update($request->only([
            'name', 'level_code', 'capacity', 'start_date',
            'schedule_day', 'schedule_time', 'location_type', 'meeting_link', 'status',
        ]));

        if ($request->has('schedules')) {
            $schedules = $request->input('schedules');
            $this->syncSchedules($group, $schedules);

            // Keep the deprecated single-row columns in sync with the first
            // active schedule row so any remaining legacy readers still work.
            $first = collect($schedules)->first();
            if ($first) {
                $group->update([
                    'schedule_day'  => $first['day_of_week'],
                    'schedule_time' => $first['start_time'],
                    'location_type' => $first['delivery_mode'] ?? $group->location_type,
                ]);
            }
        }

        $group->load(['course', 'activeStudents', 'schedules']);

        return response()->json(['success' => true, 'data' => $this->shapeGroup($group)]);
    }

    // ─── DELETE /api/instructor/classes/{group} ──────────────────────────────

    public function destroy(Request $request, ClassGroup $group): JsonResponse
    {
        $this->authorizeGroup($request, $group);

        if (! $group->canBeEdited()) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن حذف صف مكتمل أو مؤرشف.',
            ], 422);
        }

        if ($group->activeStudents()->count() > 0) {
            return response()->json(['success' => false, 'message' => 'لا يمكن حذف مجموعة تحتوي على طلاب.'], 422);
        }

        $group->delete();

        return response()->json(['success' => true, 'message' => 'تم حذف المجموعة.']);
    }

    // ─── GET /api/instructor/courses/{course}/students ───────────────────────
    // All enrolled students for a course (registrations, no placement filter).

    public function courseStudents(Request $request, Course $course): JsonResponse
    {
        $this->authorizeCourse($request, $course);

        // Single shared source of truth — same shape (placement + class
        // assignment) the placement-tests page uses, so the two pages can
        // never disagree about the same student's data.
        $result = $this->placement->studentsForCourse($course);
        $rows   = array_map(fn ($row) => $row + ['course_id' => $course->id, 'course_title' => $course->title], $result['students']);

        return response()->json([
            'success' => true,
            'data'    => \App\Http\Resources\InstructorStudentSummaryResource::collection($rows),
            'meta'    => ['total' => count($result['students'])] + $result['status_counts'],
        ]);
    }

    // ─── GET /api/instructor/courses/{course}/classes ────────────────────────
    // Class groups for a specific course.

    public function courseGroups(Request $request, Course $course): JsonResponse
    {
        $this->authorizeCourse($request, $course);

        $groups = ClassGroup::with(['activeStudents', 'schedules'])
            ->where('course_id', $course->id)
            ->latest()
            ->get()
            ->map(fn ($g) => $this->shapeGroup($g));

        return response()->json(['success' => true, 'data' => $groups]);
    }

    // ─── GET /api/instructor/courses/{course}/class-assignment/students ──────
    // Students ready for class assignment (written + oral completed).

    public function assignmentStudents(Request $request, Course $course): JsonResponse
    {
        $this->authorizeCourse($request, $course);

        // Reuses the same shared source of truth as courseStudents() — only
        // filtered down to students who've completed at least the written test.
        $eligibleStatuses = [
            PlacementProgressService::STATUS_WRITTEN_COMPLETED,
            PlacementProgressService::STATUS_ORAL_BOOKED,
            PlacementProgressService::STATUS_ORAL_COMPLETED,
            PlacementProgressService::STATUS_COMPLETED,
        ];

        $students = collect($this->placement->studentsForCourse($course)['students'])
            ->filter(fn ($s) => in_array($s['placement_status'], $eligibleStatuses, true))
            ->map(fn ($row) => $row + ['course_id' => $course->id, 'course_title' => $course->title])
            ->values();

        return response()->json(['success' => true, 'data' => \App\Http\Resources\InstructorStudentSummaryResource::collection($students)]);
    }

    // ─── POST /api/instructor/classes/{group}/students ───────────────────────
    // Assign a student to a class group.

    public function addStudent(Request $request, ClassGroup $group): JsonResponse
    {
        $this->authorizeGroup($request, $group);

        if (! $group->canBeEdited()) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن إضافة طلاب لصف مكتمل أو مؤرشف.',
            ], 422);
        }

        $request->validate([
            'user_id'                   => ['required', 'integer', 'exists:users,id'],
            'placement_attempt_id'      => ['nullable', 'integer'],
            'oral_assessment_booking_id'=> ['nullable', 'integer'],
            'notes'                     => ['nullable', 'string', 'max:500'],
        ]);

        $activeCount = $group->activeStudents()->count();
        if ($activeCount >= $group->capacity) {
            return response()->json(['success' => false, 'message' => 'وصلت المجموعة إلى الحد الأقصى للطلاب.'], 422);
        }

        $existing = ClassGroupStudent::where('class_group_id', $group->id)
            ->where('user_id', $request->user_id)
            ->first();

        if ($existing) {
            $existing->update(['status' => 'assigned', 'assigned_by' => $request->user()->id, 'assigned_at' => now()]);
            $this->sendClassAssignmentNotification($request->user_id, $group);
            return response()->json(['success' => true, 'message' => 'تم تحديث تعيين الطالب.']);
        }

        ClassGroupStudent::create([
            'class_group_id'             => $group->id,
            'user_id'                    => $request->user_id,
            'placement_attempt_id'       => $request->placement_attempt_id,
            'oral_assessment_booking_id' => $request->oral_assessment_booking_id,
            'assigned_by'                => $request->user()->id,
            'assigned_at'                => now(),
            'status'                     => 'assigned',
            'notes'                      => $request->notes,
        ]);

        $this->sendClassAssignmentNotification($request->user_id, $group);

        return response()->json(['success' => true, 'message' => 'تم تعيين الطالب إلى المجموعة.'], 201);
    }

    // ─── DELETE /api/instructor/classes/{group}/students/{user} ─────────────

    public function removeStudent(Request $request, ClassGroup $group, int $userId): JsonResponse
    {
        $this->authorizeGroup($request, $group);

        if (! $group->canBeEdited()) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن إزالة طلاب من صف مكتمل أو مؤرشف.',
            ], 422);
        }

        ClassGroupStudent::where('class_group_id', $group->id)
            ->where('user_id', $userId)
            ->update(['status' => 'removed']);

        return response()->json(['success' => true, 'message' => 'تم إزالة الطالب من المجموعة.']);
    }

    // ─── GET /api/instructor/classes/{group}/students ────────────────────────
    // All active students enrolled in a class group.

    public function groupStudents(Request $request, ClassGroup $group): JsonResponse
    {
        $this->authorizeGroup($request, $group);
        $group->loadMissing('course:id,title');
        $students = array_map(
            fn ($row) => $row + ['course_id' => $group->course_id, 'course_title' => $group->course?->title],
            $this->shapeGroupStudents($group)
        );

        return response()->json([
            'success' => true,
            'data'    => [
                'group_id'       => $group->id,
                'group_name'     => $group->name,
                'course_id'      => $group->course_id,
                'students_count' => count($students),
                'students'       => \App\Http\Resources\InstructorStudentSummaryResource::collection($students),
            ],
        ]);
    }

    // ─── GET /api/instructor/classes/{group} ─────────────────────────────────
    // Full class detail: schedule, course, instructor, students (with
    // placement data), and content counts — single source of truth for the
    // class detail drawer/page.

    public function show(Request $request, ClassGroup $group): JsonResponse
    {
        $this->authorizeGroup($request, $group);
        $group->load(['course', 'instructor.user', 'schedules']);

        $students = array_map(
            fn ($row) => $row + ['course_id' => $group->course_id, 'course_title' => $group->course?->title],
            $this->shapeGroupStudents($group)
        );

        return response()->json([
            'success' => true,
            'data'    => [
                'id'                      => $group->id,
                'name'                    => $group->name,
                'level_code'              => $group->level_code,
                'status'                  => $group->status,
                'capacity'                => $group->capacity,
                'current_students_count'  => count($students),
                'available_seats'         => max(0, $group->capacity - count($students)),
                'course'                  => $group->course ? [
                    'id'    => $group->course->id,
                    'title' => $group->course->title,
                ] : null,
                'instructor'              => $group->instructor ? [
                    'id'   => $group->instructor->id,
                    'name' => $group->instructor->user?->name ?? $group->instructor->name,
                ] : null,
                'schedule'                => [
                    // Deprecated single-row fields — prefer `schedules`.
                    'start_date' => $group->start_date?->toDateString(),
                    'day'        => $group->schedule_day,
                    'time'       => $group->schedule_time ? substr($group->schedule_time, 0, 5) : null,
                    'mode'       => $group->location_type,
                ],
                'schedules'               => $group->schedules->map(fn ($s) => [
                    'id'            => $s->id,
                    'day_of_week'   => $s->day_of_week,
                    'start_time'    => substr($s->start_time, 0, 5),
                    'end_time'      => substr($s->end_time, 0, 5),
                    'delivery_mode' => $s->delivery_mode,
                    'location'      => $s->location,
                    'is_active'     => $s->is_active,
                ])->values(),
                'meeting_link'            => $group->meeting_link,
                'students'                => \App\Http\Resources\InstructorStudentSummaryResource::collection($students),
                'counts'                  => [
                    'students'    => count($students),
                    'sessions'    => LmsSession::where('class_group_id', $group->id)->count()
                        + CourseSession::where('class_group_id', $group->id)->count(),
                    'materials'   => CourseMaterial::where('class_group_id', $group->id)->count(),
                    'assignments' => Assignment::where('class_group_id', $group->id)->count(),
                    'attendance_records' => Attendance::where('class_group_id', $group->id)->count(),
                    // Additive — Ticket 1 Phase 4 canonical shape.
                    'pending_reviews'  => \App\Models\AssignmentSubmission::whereHas(
                        'assignment', fn ($q) => $q->where('class_group_id', $group->id)
                    )->whereIn('status', ['submitted', 'pending_review'])->count(),
                    'announcements'    => ClassAnnouncement::where('class_group_id', $group->id)->count(),
                ],
                // Additive — earliest upcoming (not-yet-started) session for
                // this class, or null. Used by the workspace overview tab.
                'next_session'            => (function () use ($group) {
                    $now = now();
                    $lms = LmsSession::where('class_group_id', $group->id)
                        ->whereNotIn('status', ['cancelled', 'completed'])
                        ->where('session_date', '>=', $now->toDateString())
                        ->orderBy('session_date')->orderBy('start_time')
                        ->first(['id', 'title', 'session_date', 'start_time']);

                    return $lms ? [
                        'id'         => $lms->id,
                        'title'      => $lms->title,
                        'starts_at'  => $lms->session_date?->toDateString() . 'T' . $lms->start_time . ':00',
                    ] : null;
                })(),
                // Additive — backend-computed action permissions, so the
                // frontend never has to infer them from `status` itself.
                'permissions'             => [
                    'edit'              => $group->canBeEdited(),
                    'delete'            => $group->canBeEdited(),
                    'manage_students'   => $group->canBeEdited(),
                    'create_session'    => $group->canPerformTeachingActions(),
                    'record_attendance' => $group->canPerformTeachingActions(),
                    'create_assignment' => $group->canPerformTeachingActions(),
                    'upload_material'   => $group->canPerformTeachingActions(),
                    'send_announcement' => $group->canBeEdited(),
                ],
                // Additive — class-scoped attendance aggregate. "present" and
                // "late" both count as attended, matching the exact formula
                // StudentProgressService already uses (attendanceNum =
                // present+late / attendanceDen) so this doesn't invent a new
                // definition of "attended".
                'attendance_summary'      => (function () use ($group) {
                    $rows = Attendance::where('class_group_id', $group->id)
                        ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
                    $total   = (int) $rows->sum();
                    $present = (int) ($rows->get('present', 0));
                    $late    = (int) ($rows->get('late', 0));

                    return [
                        'attendance_percentage' => $total > 0 ? (int) round((($present + $late) / $total) * 100) : 0,
                        'present'  => $present,
                        'absent'   => (int) ($rows->get('absent', 0)),
                        'late'     => $late,
                        'excused'  => (int) ($rows->get('excused', 0)),
                    ];
                })(),
                // Additive — class-scoped progress aggregate. StudentProgress
                // is keyed by user_id+course_id, not class_group_id (no such
                // column exists), so this intersects the class's actual
                // active ClassGroupStudent membership with StudentProgress
                // rows for the same course — the deterministic-resolution
                // strategy, not a fabricated one. Students in a DIFFERENT
                // class of the same course are excluded by that intersection.
                'progress_summary'        => (function () use ($group, $students) {
                    $memberIds = collect($students)->pluck('id')->filter()->values()->all();
                    if (empty($memberIds)) {
                        return ['students_total' => 0, 'students_completed' => 0, 'average_progress_percentage' => 0, 'at_risk_students' => 0];
                    }

                    $rows = \App\Models\StudentProgress::where('course_id', $group->course_id)
                        ->whereIn('user_id', $memberIds)
                        ->get(['user_id', 'progress_percentage', 'status']);

                    return [
                        'students_total'               => count($memberIds),
                        'students_completed'           => $rows->where('status', 'completed')->count(),
                        'average_progress_percentage'  => $rows->isNotEmpty() ? (int) round($rows->avg('progress_percentage')) : 0,
                        // Threshold matches AdminLmsProgressController's existing "at_risk" definition (progress < 30%).
                        'at_risk_students'              => $rows->where('progress_percentage', '<', 30)->count(),
                    ];
                })(),
            ],
        ]);
    }

    // ─── GET /api/instructor/sessions/calendar ────────────────────────────────
    // Canonical, bounded-range instructor calendar. Returns LmsSession only
    // (the live-teaching entity) — CourseSession (content-scheduling) is
    // deliberately excluded here; it's still exposed elsewhere via the
    // pre-existing generic CalendarController for content-calendar purposes.
    // `from`/`to` are mandatory to avoid ever loading full session history.

    public function calendar(Request $request): JsonResponse
    {
        $instructor = Instructor::where('user_id', $request->user()->id)->first();
        abort_if(! $instructor, 403, 'ليس لديك صلاحية عرض التقويم.');

        $data = $request->validate([
            'from'           => ['required', 'date'],
            'to'             => ['required', 'date', 'after_or_equal:from'],
            'course_id'      => ['nullable', 'integer'],
            'class_group_id' => ['nullable', 'integer'],
            'status'         => ['nullable', 'string', 'in:' . implode(',', LmsSession::STATUSES)],
        ]);

        $query = LmsSession::with(['course', 'classGroup', 'instructor', 'attendances'])
            ->where('instructor_id', $instructor->id)
            ->whereBetween('session_date', [
                Carbon::parse($data['from'])->toDateString(),
                Carbon::parse($data['to'])->toDateString(),
            ]);

        if (! empty($data['course_id'])) {
            $query->where('course_id', $data['course_id']);
        }
        if (! empty($data['class_group_id'])) {
            $query->where('class_group_id', $data['class_group_id']);
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        $sessions = $query->orderBy('session_date')->orderBy('start_time')->get();

        return response()->json([
            'data' => LmsSessionResource::collection($sessions),
            'meta' => [
                'from'     => $data['from'],
                'to'       => $data['to'],
                'timezone' => config('app.booking_timezone', 'Europe/Amsterdam'),
            ],
        ]);
    }

    // ─── GET /api/instructor/classes/{group}/sessions ────────────────────────
    // Sessions explicitly scoped to this class group (never the whole course).

    public function groupSessions(Request $request, ClassGroup $group): JsonResponse
    {
        $this->authorizeGroup($request, $group);
        $tz = config('app.booking_timezone', 'Europe/Amsterdam');

        $lms = LmsSession::where('class_group_id', $group->id)->get()->map(fn ($s) => [
            'id' => $s->id, 'source_type' => 'lms_session', 'title' => $s->title,
            'status' => $s->status, 'session_date' => $s->session_date?->toDateString(),
            'start_time' => $s->start_time, 'end_time' => $s->end_time,
        ]);
        $course = CourseSession::where('class_group_id', $group->id)->get()->map(fn ($s) => [
            'id' => $s->id, 'source_type' => 'course_session', 'title' => $s->title,
            'status' => $s->status,
            'starts_at' => $s->start_at?->copy()->setTimezone($tz)->toIso8601String(),
            'ends_at'   => $s->end_at?->copy()->setTimezone($tz)->toIso8601String(),
        ]);

        return response()->json(['success' => true, 'data' => $lms->concat($course)->values()]);
    }

    // ─── POST /api/instructor/classes/{group}/sessions ───────────────────────
    // Create a new session for this class. Uses LmsSession — it already has
    // instructor_id/meeting_url/recording_url/status, the columns a
    // teaching session needs, unlike CourseSession (curriculum-authoring only).

    public function storeSession(Request $request, ClassGroup $group): JsonResponse
    {
        $instructor = Instructor::where('user_id', $request->user()->id)->first();
        abort_if(! $instructor, 403, 'ليس لديك صلاحية إنشاء جلسات لهذا الصف.');
        if ((int) $group->instructor_id !== $instructor->id) {
            abort(403, 'ليس لديك صلاحية إنشاء جلسات لهذا الصف.');
        }

        if (! $group->canPerformTeachingActions()) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن إنشاء جلسات لهذا الصف في حالته الحالية (' . $group->status . ').',
            ], 422);
        }

        $data = $request->validate([
            'title'         => ['required', 'string', 'max:150'],
            'description'   => ['nullable', 'string', 'max:2000'],
            'session_date'  => ['required', 'date'],
            'start_time'    => ['required', 'date_format:H:i'],
            'end_time'      => ['required', 'date_format:H:i', 'after:start_time'],
            'meeting_url'   => ['nullable', 'string', 'max:500', 'url'],
            'location'      => ['nullable', 'string', 'max:255'],
        ]);

        $session = LmsSession::create([
            'course_id'      => $group->course_id,
            'class_group_id' => $group->id,
            'instructor_id'  => $instructor->id,
            'title'          => $data['title'],
            'description'    => $data['description'] ?? null,
            'session_date'   => $data['session_date'],
            'start_time'     => $data['start_time'],
            'end_time'       => $data['end_time'],
            'meeting_url'    => $data['meeting_url'] ?? null,
            'location'       => $data['location'] ?? null,
            'status'         => 'scheduled',
        ]);

        $this->notifyClassSessionEvent($group, $session, 'session_scheduled', 'جلسة جديدة في صفك', "تمت إضافة جلسة \"{$session->title}\" في صفك.");

        return response()->json(['success' => true, 'message' => 'تم إنشاء الجلسة.', 'data' => $session], 201);
    }

    // ─── PATCH /api/instructor/classes/{group}/sessions/{session} ────────────
    // Update, reschedule, cancel, or complete a session — only for own class.

    public function updateSession(Request $request, ClassGroup $group, LmsSession $session): JsonResponse
    {
        $this->authorizeGroup($request, $group);
        abort_if((int) $session->class_group_id !== $group->id, 404);

        // Same status-vs-content distinction as update(): a completed/
        // archived class's session content (title/time/etc) is frozen —
        // teaching history isn't rewritable — but the session's own status
        // (e.g. marking it completed/cancelled) must remain settable.
        $nonStatusKeys = array_diff($request->keys(), ['status']);
        if (! empty($nonStatusKeys) && ! $group->canBeEdited()) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن تعديل جلسات صف مكتمل أو مؤرشف.',
            ], 422);
        }

        $data = $request->validate([
            'title'            => ['sometimes', 'string', 'max:150'],
            'description'      => ['sometimes', 'nullable', 'string', 'max:2000'],
            'session_date'     => ['sometimes', 'date'],
            'start_time'       => ['sometimes', 'date_format:H:i'],
            'end_time'         => ['sometimes', 'date_format:H:i', 'after:start_time'],
            'meeting_url'      => ['sometimes', 'nullable', 'string', 'max:500', 'url'],
            'meeting_provider' => ['sometimes', 'nullable', 'string', 'in:' . implode(',', LmsSession::MEETING_PROVIDERS)],
            'recording_url'    => ['sometimes', 'nullable', 'string', 'max:500', 'url'],
            'location'         => ['sometimes', 'nullable', 'string', 'max:255'],
            // 'status' is accepted here for backward compatibility with
            // existing callers, but — unlike before — it is no longer a raw
            // mass-assignment: it's routed through LmsSessionTransitionService
            // below, so an illegal transition is rejected with a clear 422
            // instead of silently overwriting the column. New callers should
            // prefer the dedicated transitionSession() endpoint directly.
            'status'           => ['sometimes', 'string', 'in:' . implode(',', LmsSession::STATUSES)],
        ]);

        $status = $data['status'] ?? null;
        unset($data['status']);
        $meetingUrlChanged = array_key_exists('meeting_url', $data) && $data['meeting_url'] !== $session->meeting_url;

        if (! empty($data)) {
            $session->update($data);
        }

        if ($meetingUrlChanged && $session->status !== LmsSession::STATUS_CANCELLED) {
            $this->notifyClassSessionEvent($group, $session, 'session_scheduled', 'تحديث رابط الاجتماع', "تم تحديث رابط الاجتماع لجلسة \"{$session->title}\".");
        }

        if ($status !== null && $status !== $session->status) {
            try {
                $session = app(LmsSessionTransitionService::class)->transition($session, $status, $request->user());
            } catch (ValidationException $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
        }

        return response()->json(['success' => true, 'message' => 'تم تحديث الجلسة.', 'data' => LmsSessionResource::make($session->fresh(['course', 'classGroup', 'instructor']))]);
    }

    // ─── POST /api/instructor/classes/{group}/sessions/{session}/transition ──
    // The only path allowed to change a session's status — delegates to the
    // centralized LmsSessionTransitionService matrix, never accepts an
    // arbitrary status value directly on updateSession().

    public function transitionSession(Request $request, ClassGroup $group, LmsSession $session): JsonResponse
    {
        $this->authorizeGroup($request, $group);
        abort_if((int) $session->class_group_id !== $group->id, 404);

        $data = $request->validate([
            'status' => ['required', 'string', 'in:' . implode(',', LmsSession::STATUSES)],
        ]);

        try {
            $updated = app(LmsSessionTransitionService::class)->transition(
                $session, $data['status'], $request->user()
            );
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        if ($data['status'] === LmsSession::STATUS_CANCELLED) {
            $this->notifyClassSessionEvent($group, $updated, 'session_cancelled', 'تم إلغاء جلسة', "تم إلغاء جلسة \"{$updated->title}\" في صفك.");
        }

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث حالة الجلسة.',
            'data'    => LmsSessionResource::make($updated->load(['course', 'classGroup', 'instructor'])),
        ]);
    }

    /**
     * Notifies only the class's currently-active members (never removed
     * members, never students in a different class within the same course).
     * Deliberately bypasses NotificationService::sessionScheduled()'s
     * course-wide recipient list — a class-scoped session must only reach
     * that class.
     */
    private function notifyClassSessionEvent(ClassGroup $group, LmsSession $session, string $type, string $title, string $message): void
    {
        $recipients = ClassGroupStudent::where('class_group_id', $group->id)
            ->whereIn('status', ClassGroupStudent::ACTIVE_STATUSES)
            ->pluck('user_id')
            ->all();

        if (empty($recipients)) {
            return;
        }

        NotificationService::send($recipients, $type, $title, $message, [
            'entity_type' => 'lms_session',
            'entity_id'   => $session->id,
            'action_url'  => "/dashboard/student/sessions/{$session->id}",
            'icon'        => 'video-camera',
            'color'       => $type === 'session_cancelled' ? 'red' : 'indigo',
        ]);
    }

    // ─── GET /api/instructor/classes/{group}/sessions/{session} ──────────────

    public function showSession(Request $request, ClassGroup $group, LmsSession $session): JsonResponse
    {
        $this->authorizeGroup($request, $group);
        abort_if((int) $session->class_group_id !== $group->id, 404);

        $session->load(['course', 'classGroup', 'instructor', 'attendances', 'materials']);

        return response()->json(['success' => true, 'data' => LmsSessionResource::make($session)]);
    }

    // ─── DELETE /api/instructor/classes/{group}/sessions/{session} ───────────
    // Only scheduled sessions with zero attendance records may be deleted —
    // no silent cascading deletion of attendance history. Anything else must
    // be cancelled/archived via transitionSession() instead.

    public function destroySession(Request $request, ClassGroup $group, LmsSession $session): JsonResponse
    {
        $this->authorizeGroup($request, $group);
        abort_if((int) $session->class_group_id !== $group->id, 404);

        if ($session->status !== LmsSession::STATUS_SCHEDULED || $session->attendances()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن حذف جلسة بها سجلات حضور أو ليست في حالة "مجدولة" — استخدم الإلغاء أو الأرشفة بدلاً من ذلك.',
            ], 422);
        }

        $session->delete();

        return response()->json(['success' => true, 'message' => 'تم حذف الجلسة.']);
    }

    // ─── POST .../sessions/generation-preview & .../sessions/generate ────────

    public function previewSessionGeneration(Request $request, ClassGroup $group): JsonResponse
    {
        $this->authorizeGroup($request, $group);
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to'   => ['required', 'date', 'after_or_equal:from'],
        ]);

        $dates = app(ClassSessionGenerationService::class)->preview(
            $group, Carbon::parse($data['from']), Carbon::parse($data['to'])
        );

        return response()->json(['success' => true, 'data' => $dates]);
    }

    public function generateSessions(Request $request, ClassGroup $group): JsonResponse
    {
        $this->authorizeGroup($request, $group);
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to'   => ['required', 'date', 'after_or_equal:from'],
        ]);

        $result = app(ClassSessionGenerationService::class)->generate(
            $group, Carbon::parse($data['from']), Carbon::parse($data['to']), $request->user()
        );

        return response()->json([
            'success'       => true,
            'created_count' => $result['created_count'],
            'skipped_count' => $result['skipped_count'],
            'sessions'      => LmsSessionResource::collection($result['sessions']),
        ]);
    }

    // ─── GET /api/instructor/classes/{group}/attendance ──────────────────────
    // Attendance records explicitly scoped to this class group.

    public function groupAttendance(Request $request, ClassGroup $group): JsonResponse
    {
        $this->authorizeGroup($request, $group);

        $records = Attendance::with('user:id,name,email')
            ->where('class_group_id', $group->id)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id, 'user_id' => $a->user_id,
                'student_name' => $a->user?->name, 'status' => $a->status,
                'checked_in_at' => $a->checked_in_at?->toIso8601String(),
            ]);

        return response()->json(['success' => true, 'data' => $records->values()]);
    }

    // ─── GET /api/instructor/classes/{group}/materials ───────────────────────

    public function groupMaterials(Request $request, ClassGroup $group): JsonResponse
    {
        $this->authorizeGroup($request, $group);

        $materials = CourseMaterial::where('class_group_id', $group->id)
            ->where('is_visible', true)
            ->get(['id', 'title', 'type', 'sort_order']);

        return response()->json(['success' => true, 'data' => $materials]);
    }

    // ─── GET /api/instructor/classes/{group}/curriculum/analytics ────────────

    public function curriculumAnalytics(Request $request, ClassGroup $group): JsonResponse
    {
        if (! (new \App\Policies\ClassGroupPolicy())->viewAnalytics($request->user(), $group)) {
            abort(403, 'ليس لديك صلاحية الوصول إلى تحليلات هذا الصف.');
        }

        $data = app(\App\Services\LearningContentAnalyticsService::class)->forClass($group);

        return response()->json(['success' => true, 'data' => $data]);
    }

    // ─── GET /api/instructor/classes/{group}/assignments ─────────────────────

    public function groupAssignments(Request $request, ClassGroup $group): JsonResponse
    {
        $this->authorizeGroup($request, $group);

        $assignments = Assignment::where('class_group_id', $group->id)
            ->get(['id', 'title', 'due_date', 'max_score', 'status']);

        return response()->json(['success' => true, 'data' => $assignments]);
    }

    // ─── GET /api/instructor/classes/{group}/announcements ───────────────────
    // All announcements for this class (draft + published + archived) —
    // instructor-only view; students only ever see published ones (see
    // StudentClassAnnouncementController).

    public function groupAnnouncements(Request $request, ClassGroup $group): JsonResponse
    {
        $this->authorizeGroup($request, $group);

        $announcements = ClassAnnouncement::where('class_group_id', $group->id)
            ->latest()
            ->get(['id', 'title', 'body', 'priority', 'status', 'published_at', 'created_at']);

        return response()->json(['success' => true, 'data' => $announcements]);
    }

    // ─── POST /api/instructor/classes/{group}/announcements ──────────────────
    // Create a draft announcement for this class.

    public function storeAnnouncement(Request $request, ClassGroup $group): JsonResponse
    {
        $this->authorizeGroup($request, $group);

        if (! $group->canBeEdited()) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن إنشاء إعلانات لصف مكتمل أو مؤرشف.',
            ], 422);
        }

        $data = $request->validate([
            'title'    => ['required', 'string', 'max:200'],
            'body'     => ['required', 'string', 'max:5000'],
            'priority' => ['nullable', 'string', 'in:' . implode(',', ClassAnnouncement::PRIORITIES)],
        ]);

        $announcement = ClassAnnouncement::create([
            'course_id'      => $group->course_id,
            'class_group_id' => $group->id,
            'created_by'     => $request->user()->id,
            'title'          => $data['title'],
            'body'           => $data['body'],
            'priority'       => $data['priority'] ?? 'normal',
            'status'         => 'draft',
        ]);

        return response()->json(['success' => true, 'message' => 'تم إنشاء الإعلان.', 'data' => $announcement], 201);
    }

    // ─── PATCH /api/instructor/classes/{group}/announcements/{announcement}/publish ──
    // Publish a draft announcement — notifies every active class member.

    public function publishAnnouncement(Request $request, ClassGroup $group, ClassAnnouncement $announcement): JsonResponse
    {
        $this->authorizeGroup($request, $group);
        abort_if((int) $announcement->class_group_id !== $group->id, 404);

        if ($announcement->status === 'published') {
            return response()->json(['success' => true, 'message' => 'الإعلان منشور بالفعل.', 'data' => $announcement]);
        }

        $announcement->update(['status' => 'published', 'published_at' => now()]);

        $activeMemberIds = ClassGroupStudent::where('class_group_id', $group->id)
            ->whereIn('status', ClassGroupStudent::ACTIVE_STATUSES)
            ->pluck('user_id')
            ->all();

        if (! empty($activeMemberIds)) {
            NotificationService::classAnnouncementPublished(
                studentUserIds:    $activeMemberIds,
                courseTitle:       $group->course?->title ?? 'الدورة',
                className:         $group->name,
                announcementTitle: $announcement->title,
                classGroupId:      $group->id,
                announcementId:    $announcement->id,
            );
        }

        return response()->json(['success' => true, 'message' => 'تم نشر الإعلان.', 'data' => $announcement->fresh()]);
    }

    // ─── DELETE /api/instructor/classes/{group}/announcements/{announcement} ──
    // Archive (soft) rather than hard-delete — matches Ticket 1's "archive
    // over destructive deletion" rule.

    public function archiveAnnouncement(Request $request, ClassGroup $group, ClassAnnouncement $announcement): JsonResponse
    {
        $this->authorizeGroup($request, $group);
        abort_if((int) $announcement->class_group_id !== $group->id, 404);

        $announcement->update(['status' => 'archived']);

        return response()->json(['success' => true, 'message' => 'تمت أرشفة الإعلان.']);
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    private function authorizeGroup(Request $request, ClassGroup $group): void
    {
        // Delegates ownership to ClassGroupPolicy::view() directly (not via
        // Gate::authorize/can()) — this route group is instructor-only by
        // design and must NOT pick up the global super_admin/tech_admin
        // Gate::before bypass, which never applied to this endpoint before.
        // Calling the Policy class directly reuses the single source of
        // truth without silently widening who can reach it.
        if (! (new \App\Policies\ClassGroupPolicy())->view($request->user(), $group)) {
            abort(403, 'ليس لديك صلاحية الوصول إلى هذه المجموعة.');
        }
    }

    /** Same ownership contract as authorizeGroup(), scoped to a Course. Unlike
     *  the inline `if ($instructor && ...)` checks this replaces, a caller with
     *  no linked Instructor row (e.g. an admin account) is denied rather than
     *  silently let through. */
    private function authorizeCourse(Request $request, Course $course): Instructor
    {
        $instructor = Instructor::where('user_id', $request->user()->id)->first();
        abort_if(! $instructor, 403, 'ليس لديك صلاحية الوصول إلى هذه الدورة.');
        if ((int) $course->instructor_id !== $instructor->id) {
            abort(403, 'ليس لديك صلاحية الوصول إلى هذه الدورة.');
        }

        return $instructor;
    }

    /** Class-group students shaped with placement data — the single source of
     *  truth for both groupStudents() and show(), so they can't disagree. */
    private function shapeGroupStudents(ClassGroup $group): array
    {
        $memberships = $group->students()
            ->whereIn('status', ClassGroupStudent::ACTIVE_STATUSES)
            ->with('user:id,name,email,avatar')
            ->get();

        $userIds = $memberships->pluck('user_id')->filter()->values()->all();

        $attempts = PlacementTestAttempt::where('course_id', $group->course_id)
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy(fn ($a) => (string) $a->user_id);

        $bookings = OralAssessmentBooking::with(['slot', 'instructor.user', 'editedBy'])
            ->where('course_id', $group->course_id)
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy(fn ($b) => (string) $b->user_id);

        return $memberships->map(function (ClassGroupStudent $s) use ($attempts, $bookings) {
            $attempt = $attempts->get((string) $s->user_id);
            $booking = $bookings->get((string) $s->user_id);

            // Reuse the exact same shape methods studentsForCourse() uses, so
            // written/oral/final-level values can never drift between the
            // class-students page and the course/placement pages.
            $writtenShape = $attempt ? $this->placement->writtenTestShape($attempt) : null;
            $oralShape    = $booking ? $this->placement->oralAssessmentShape($booking) : null;

            return [
                // Canonical identifier — matches every other instructor
                // student endpoint (courseStudents, placement-students, etc).
                'student_id'         => $s->user_id,
                // Deprecated alias, kept for backward compatibility with
                // older frontend fallbacks. Remove once all callers are
                // confirmed to read `student_id`.
                'id'                 => $s->user_id,
                'name'               => $s->user?->name,
                'email'              => $s->user?->email,
                'avatar_url'         => $s->user?->avatar_url ?? null,
                'membership_status'  => $s->status,
                'assigned_at'        => $s->assigned_at?->toIso8601String(),
                'notes'              => $s->notes,
                // Flat fields — same names/values as studentsForCourse().
                'written_score'      => $writtenShape['score'] ?? null,
                'total_questions'    => $writtenShape['total_questions'] ?? null,
                'percentage'         => $writtenShape['percentage'] ?? null,
                'written_level'      => $writtenShape['estimated_level'] ?? null,
                'oral_score'         => $oralShape['oral_score'] ?? null,
                'final_level'        => $oralShape['final_level'] ?? null,
                // Kept for backward compatibility with the existing frontend
                // normalizer, which reads placement.* for this endpoint.
                'placement'          => [
                    'final_level'    => $oralShape['final_level'] ?? null,
                    'written_score'  => $writtenShape['score'] ?? null,
                    'written_total'  => $writtenShape['total_questions'] ?? null,
                    'percentage'     => $writtenShape['percentage'] ?? null,
                ],
                // Full rubric breakdown, evaluator, timestamps, and edit
                // history — the class card's "view full assessment" modal
                // never needs a follow-up request for this student.
                'oral_assessment'    => $this->placement->oralAssessmentFullShape($booking),
            ];
        })->values()->all();
    }

    // ─── Notification helper ─────────────────────────────────────────────────

    private function sendClassAssignmentNotification(int $studentUserId, ClassGroup $group): void
    {
        $group->loadMissing(['course', 'instructor.user']);

        NotificationService::classGroupAssigned(
            studentUserId: $studentUserId,
            classGroupName: $group->name,
            courseTitle: $group->course?->title ?? '',
            instructorName: $group->instructor?->user?->name ?? $group->instructor?->name,
            scheduleDay: $group->schedule_day,
            scheduleTime: $group->schedule_time,
            locationType: $group->location_type,
        );
    }

    // ─── Shape helper ────────────────────────────────────────────────────────

    private function shapeGroup(ClassGroup $g): array
    {
        $enrolled = $g->relationLoaded('activeStudents') ? $g->activeStudents->count() : 0;

        return [
            'id'            => $g->id,
            'course_id'     => $g->course_id,
            'course_title'  => $g->course?->title,
            'instructor_id' => $g->instructor_id,
            'level_code'    => $g->level_code,
            'name'          => $g->name,
            'capacity'      => $g->capacity,
            'enrolled'      => $enrolled,
            'remaining'     => max(0, $g->capacity - $enrolled),
            'start_date'    => $g->start_date?->toDateString(),
            // Deprecated single-row fields — kept for backward compatibility.
            // Prefer `schedules` (the canonical multi-day weekly schedule).
            'schedule_day'  => $g->schedule_day,
            'schedule_time' => $g->schedule_time ? substr($g->schedule_time, 0, 5) : null,
            'location_type' => $g->location_type,
            'meeting_link'  => $g->meeting_link,
            'schedules'     => $g->relationLoaded('schedules')
                ? $g->schedules->map(fn ($s) => [
                    'id'            => $s->id,
                    'day_of_week'   => $s->day_of_week,
                    'start_time'    => substr($s->start_time, 0, 5),
                    'end_time'      => substr($s->end_time, 0, 5),
                    'delivery_mode' => $s->delivery_mode,
                    'location'      => $s->location,
                    'is_active'     => $s->is_active,
                ])->values()
                : [],
            'status'        => $g->status,
            'created_at'    => $g->created_at->toIso8601String(),
        ];
    }

    /** Reject duplicate day+start_time rows and end<=start rows before saving. */
    private function validateSchedules(array $schedules): void
    {
        $seen = [];
        foreach ($schedules as $row) {
            $key = ($row['day_of_week'] ?? '') . '_' . ($row['start_time'] ?? '');
            if (isset($seen[$key])) {
                abort(422, 'لا يمكن تكرار نفس اليوم والوقت في الجدول الأسبوعي.');
            }
            $seen[$key] = true;

            if (($row['start_time'] ?? null) && ($row['end_time'] ?? null) && $row['end_time'] <= $row['start_time']) {
                abort(422, 'وقت نهاية الحصة يجب أن يكون بعد وقت البداية.');
            }
        }
    }

    /** Replace a class group's weekly schedule rows atomically. */
    private function syncSchedules(ClassGroup $group, array $schedules): void
    {
        \DB::transaction(function () use ($group, $schedules) {
            $group->schedules()->delete();
            foreach ($schedules as $row) {
                $group->schedules()->create([
                    'day_of_week'   => $row['day_of_week'],
                    'start_time'    => $row['start_time'],
                    'end_time'      => $row['end_time'],
                    'delivery_mode' => $row['delivery_mode'] ?? 'online',
                    'location'      => $row['location'] ?? null,
                    'is_active'     => true,
                ]);
            }
        });
    }
}
