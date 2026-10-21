<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\CalendarEvent;
use App\Models\Course;
use App\Models\CourseSession;
use App\Models\Department;
use App\Models\Instructor;
use App\Models\LearningPath;
use App\Models\LearningPathEnrollment;
use App\Models\LmsSession;
use App\Models\Meeting;
use App\Models\Registration;
use App\Models\Task;
use App\Models\Workshop;
use App\Services\CalendarService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

class CalendarController extends Controller
{
    public function __construct(private readonly CalendarService $calendarService) {}

    // ── GET /api/calendar/events ──────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $events = $this->buildEventsForUser($request);

        return response()->json(['success' => true, 'data' => $events->values()]);
    }

    // ── POST /api/calendar/events ─────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $user      = $request->user();
        $isDeptLdr = Department::where('leader_id', $user->id)->exists();

        if (! $user->isAdmin() && ! $isDeptLdr) {
            return response()->json(['success' => false, 'message' => 'غير مصرح لك بإنشاء أحداث'], 403);
        }

        $data = $request->validate([
            'title'         => 'required|string|max:255',
            'description'   => 'nullable|string',
            'kind'          => 'required|in:meeting,event,task',
            'start_at'      => 'required|date',
            'end_at'        => 'nullable|date|after:start_at',
            'location'      => 'nullable|string|max:255',
            'meeting_url'   => 'nullable|url|max:500',
            'department_id' => 'nullable|exists:departments,id',
            'attendees'     => 'nullable|array',
            'visibility'    => 'required|in:private,department,all',
        ]);

        $event = CalendarEvent::create(array_merge($data, ['user_id' => $user->id]));
        $event->load('user');

        return response()->json(['success' => true, 'data' => $this->normalizeCalendarEvent($event, $user)], 201);
    }

    // ── PUT /api/calendar/events/{id} ─────────────────────────────────────────

    public function update(Request $request, CalendarEvent $calendarEvent): JsonResponse
    {
        $user = $request->user();
        if (! $user->isAdmin() && $calendarEvent->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'غير مصرح'], 403);
        }

        $data = $request->validate([
            'title'       => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'start_at'    => 'sometimes|date',
            'end_at'      => 'nullable|date',
            'location'    => 'nullable|string|max:255',
            'meeting_url' => 'nullable|url|max:500',
            'visibility'  => 'sometimes|in:private,department,all',
            'attendees'   => 'nullable|array',
        ]);

        $calendarEvent->update($data);
        $calendarEvent->load('user');

        return response()->json(['success' => true, 'data' => $this->normalizeCalendarEvent($calendarEvent->fresh(), $user)]);
    }

    // ── DELETE /api/calendar/events/{id} ──────────────────────────────────────

    public function destroy(Request $request, CalendarEvent $calendarEvent): JsonResponse
    {
        $user = $request->user();
        if (! $user->isAdmin() && $calendarEvent->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => 'غير مصرح'], 403);
        }

        $calendarEvent->delete();

        return response()->json(['success' => true]);
    }

    // ── GET /api/calendar/export.ics ──────────────────────────────────────────

    public function exportAll(Request $request): Response
    {
        $events = $this->buildEventsForUser($request);
        $ics    = $this->buildIcsFromNormalized($events);

        return response($ics, 200, [
            'Content-Type'        => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="emc-calendar.ics"',
            'Cache-Control'       => 'no-store',
        ]);
    }

    // ── GET /api/calendar/events/{id}/ics ─────────────────────────────────────

    public function downloadIcs(Request $request, CalendarEvent $calendarEvent): Response
    {
        if (! $request->user()->isAdmin() && $calendarEvent->user_id !== $request->user()->id) {
            abort(403);
        }

        $ics  = $this->calendarService->toIcs($calendarEvent);
        $slug = \Illuminate\Support\Str::slug($calendarEvent->title);

        return response($ics, 200, [
            'Content-Type'        => 'text/calendar; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$slug}.ics\"",
        ]);
    }

    // ── Core aggregation ──────────────────────────────────────────────────────

    private function buildEventsForUser(Request $request): Collection
    {
        $user     = $request->user();
        $startRaw = $request->query('start_date') ?? $request->query('start');
        $endRaw   = $request->query('end_date')   ?? $request->query('end');

        $start = $startRaw ? Carbon::parse($startRaw)->startOfDay() : now()->subDays(7)->startOfDay();
        $end   = $endRaw   ? Carbon::parse($endRaw)->endOfDay()     : now()->addDays(90)->endOfDay();

        $type     = $request->query('type') ?? $request->query('kind');
        $deptId   = $request->query('department_id');
        $courseId = $request->query('course_id');

        /** Returns true when no type filter is active, or when the requested type matches.
         *  'program' is an alias for 'course' on the frontend. */
        $wants = function (string $t) use ($type): bool {
            if (! $type || $type === 'all') {
                return true;
            }
            if ($type === $t) {
                return true;
            }
            // 'program' filter should also match courses, and vice-versa
            if (($type === 'program' && $t === 'course') || ($type === 'course' && $t === 'program')) {
                return true;
            }
            return false;
        };

        $wantsProgramType = fn (string $programCalendarType): bool =>
            ! $type || $type === 'all' || $type === $programCalendarType;

        $events = collect();

        // ── Admin / Super Admin: all events ──────────────────────────────────
        if ($user->isAdmin()) {
            if ($wants('session')) {
                $events = $events
                    ->merge($this->normalizeLmsSessions(
                        LmsSession::with(['course:id,title,slug', 'instructor.user'])
                            ->where(fn ($q) => $q
                                ->whereBetween('session_date', [$start->toDateString(), $end->toDateString()])
                            )
                            ->when($courseId, fn ($q) => $q->where('course_id', $courseId))
                            ->get(),
                        $user, true,
                    ))
                    ->merge($this->normalizeCourseSessions(
                        CourseSession::with('course:id,title,slug')
                            ->whereBetween('start_at', [$start, $end])
                            ->when($courseId, fn ($q) => $q->where('course_id', $courseId))
                            ->get(),
                        $user, true,
                    ));
            }
            if ($wants('workshop')) {
                $events = $events->merge($this->normalizeWorkshops(
                    Workshop::with(['course:id,title,slug', 'instructor.user'])
                        ->whereBetween('start_date', [$start->toDateString(), $end->toDateString()])
                        ->get(),
                    $user, true,
                ));
            }
            if ($wants('assignment')) {
                $events = $events->merge($this->normalizeAssignments(
                    Assignment::with(['course:id,title,slug'])
                        ->where('status', 'active')
                        ->whereNotNull('due_date')
                        ->whereBetween('due_date', [$start, $end])
                        ->when($courseId, fn ($q) => $q->where('course_id', $courseId))
                        ->get(),
                    $user, true,
                ));
            }
            if ($wants('meeting')) {
                $events = $events->merge($this->normalizeCalendarEventList(
                    CalendarEvent::with('user:id,name')
                        ->whereBetween('start_at', [$start, $end])
                        ->get(),
                    $user,
                ));
            }
            if ($wants('task')) {
                $events = $events->merge($this->normalizeTasks(
                    Task::with(['department:id,name,name_ar', 'assignedTo:id,name'])
                        ->whereNotNull('due_date')
                        ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
                        ->when($deptId, fn ($q) => $q->where('department_id', $deptId))
                        ->get(),
                    $user, true,
                ));
            }
            if ($wantsProgramType('course') || $wantsProgramType('workshop') || $wantsProgramType('program')) {
                $courses = $this->queryScheduledPrograms($start, $end, $courseId)->get();
                $events  = $events->merge(
                    $this->normalizePrograms($courses, $user, true)
                        ->filter(fn ($e) => $wantsProgramType($e['type'])),
                );
            }
        }

        // ── Instructor / Teacher: assigned courses & sessions ─────────────────
        elseif ($user->isTeacher() || in_array($user->role, ['instructor'], true)) {
            $instructor = Instructor::where('user_id', $user->id)->first();
            $courseIds  = $instructor
                ? Course::where('instructor_id', $instructor->id)->pluck('id')
                : collect();

            if ($wants('session')) {
                $events = $events
                    ->merge($this->normalizeLmsSessions(
                        LmsSession::with(['course:id,title,slug', 'instructor.user'])
                            ->where(fn ($q) => $q
                                ->whereIn('course_id', $courseIds)
                                ->orWhere('instructor_id', $instructor?->id ?? 0)
                            )
                            ->whereBetween('session_date', [$start->toDateString(), $end->toDateString()])
                            ->get(),
                        $user, true,
                    ))
                    ->merge($this->normalizeCourseSessions(
                        CourseSession::with('course:id,title,slug')
                            ->whereIn('course_id', $courseIds)
                            ->whereBetween('start_at', [$start, $end])
                            ->get(),
                        $user, true,
                    ));
            }
            if ($wants('workshop') && $instructor) {
                $events = $events->merge($this->normalizeWorkshops(
                    Workshop::with(['course:id,title,slug', 'instructor.user'])
                        ->where('instructor_id', $instructor->id)
                        ->whereBetween('start_date', [$start->toDateString(), $end->toDateString()])
                        ->get(),
                    $user, false,
                ));
            }
            if ($wants('assignment')) {
                $events = $events->merge($this->normalizeAssignments(
                    Assignment::with(['course:id,title,slug'])
                        ->where('status', 'active')
                        ->whereNotNull('due_date')
                        ->whereIn('course_id', $courseIds)
                        ->whereBetween('due_date', [$start, $end])
                        ->get(),
                    $user, true,
                ));
            }
            if ($instructor && ($wantsProgramType('course') || $wantsProgramType('workshop') || $wantsProgramType('program'))) {
                $courses = $this->queryScheduledPrograms($start, $end)
                    ->where('instructor_id', $instructor->id)
                    ->get();
                $events = $events->merge(
                    $this->normalizePrograms($courses, $user, true)
                        ->filter(fn ($e) => $wantsProgramType($e['type'])),
                );
            }
            // Personal calendar events
            $events = $events->merge($this->normalizeCalendarEventList(
                CalendarEvent::with('user:id,name')
                    ->where('user_id', $user->id)
                    ->whereBetween('start_at', [$start, $end])
                    ->get(),
                $user,
            ));
        }

        // ── Student: only own enrolled content + own deadlines ────────────────
        // (every other non-admin role gets the same enrolled content merged in below: learning is not a role)
        elseif ($user->isStudent()) {
            $events = $events->merge($this->learnerEvents($user, $start, $end, $wants, $wantsProgramType));
        }

        // ── Department member / Leader / Operations staff / Default ──────────
        else {
            if ($wants('meeting')) {
                // All-visibility calendar events
                $events = $events->merge($this->normalizeCalendarEventList(
                    CalendarEvent::with('user:id,name')
                        ->where('visibility', 'all')
                        ->whereBetween('start_at', [$start, $end])
                        ->get(),
                    $user,
                ));
            }
            if ($wants('task')) {
                $events = $events->merge($this->normalizeTasks(
                    Task::with(['department:id,name,name_ar', 'assignedTo:id,name'])
                        ->where('assigned_to', $user->id)
                        ->whereNotNull('due_date')
                        ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
                        ->get(),
                    $user, false,
                ));
            }
            if ($wants('session')) {
                // Show publicly scheduled sessions
                $events = $events->merge($this->normalizeLmsSessions(
                    LmsSession::with(['course:id,title,slug'])
                        ->where('status', 'scheduled')
                        ->whereBetween('session_date', [$start->toDateString(), $end->toDateString()])
                        ->get(),
                    $user, false,
                ));
            }
            if ($wantsProgramType('course') || $wantsProgramType('workshop') || $wantsProgramType('program')) {
                $courses = $this->queryScheduledPrograms($start, $end)
                    ->where('status', 'published')
                    ->get();
                $events = $events->merge(
                    $this->normalizePrograms($courses, $user, false)
                        ->filter(fn ($e) => $wantsProgramType($e['type'])),
                );
            }
            // This branch lists what is publicly scheduled. A join link is not public: only people enrolled in that course get it.
            $events = $this->withoutForeignLinks($events, $this->enrolledCourseIds($user));
        }

        // ── Anyone who learns sees what they are enrolled in, whatever their role ───────────────────────
        // Admins already see everything; students got it above; instructors and every other role get it here, merged into what
        // their role shows. The learner copy wins when the same item is in both (it carries the join link).
        if (! $user->isAdmin() && ! $user->isStudent()) {
            $events = $this->learnerEvents($user, $start, $end, $wants, $wantsProgramType)->merge($events)->unique('id')->values();
        }

        // ── Meetings: the same rule for every role ───────────────────────────────
        // A meeting shows only for people it concerns: global admins (all), the
        // organizer, invited participants, and members/leaders of its department
        // when nobody was invited by name. Students and instructors included.
        if ($wants('meeting')) {
            $access = \App\Services\MeetingAccessService::for($request);
            $query = $this->departmentAccessIsGlobal($user)
                ? Meeting::query()
                : $access->assignedQuery($user);

            $events = $events->merge($this->normalizeMeetings(
                $query->with(['department:id,name,name_ar', 'organizer:id,name', 'participants'])
                    ->whereBetween('meeting_date', [$start->toDateString(), $end->toDateString()])
                    ->when($deptId, fn ($q) => $q->where('department_id', $deptId))
                    ->get(),
                $user,
                $access,
            ));
        }

        return $events->filter(fn ($e) => ! empty($e['start_at']))->sortBy('start_at')->values();
    }

    /**
     * What a LEARNER sees: the sessions, workshops, assignment deadlines, learning paths and programs of what they are enrolled in,
     * nothing else. The same for every account that learns, whatever its organizational role.
     *
     * "Enrolled" is the platform's one definition (LmsEnrollmentService): an enrollment that grants access (not pending payment, not
     * cancelled), directly or through a learning path. So a meeting link or a deadline is never shown for something unpaid.
     */
    private function learnerEvents($user, Carbon $start, Carbon $end, \Closure $wants, \Closure $wantsProgramType): Collection
    {
        $events = collect();
        $enrolledCourseIds = $this->enrolledCourseIds($user);

        if ($wants('session')) {
            $events = $events
                ->merge($this->normalizeLmsSessions(
                    LmsSession::with(['course:id,title,slug'])
                        ->whereIn('course_id', $enrolledCourseIds)
                        ->whereIn('status', ['scheduled', 'live'])
                        ->whereBetween('session_date', [$start->toDateString(), $end->toDateString()])
                        ->get(),
                    $user, false,
                ))
                ->merge($this->normalizeCourseSessions(
                    CourseSession::with('course:id,title,slug')
                        ->whereIn('course_id', $enrolledCourseIds)
                        ->where(fn ($q) => $q->where('is_visible', true)->orWhereNull('is_visible'))
                        ->whereBetween('start_at', [$start, $end])
                        ->get(),
                    $user, false,
                ));
        }
        if ($wants('workshop')) {
            // Workshops are enrolled via the parent course (no separate workshop registration)
            $events = $events->merge($this->normalizeWorkshops(
                Workshop::with(['course:id,title,slug', 'instructor.user'])
                    ->whereIn('course_id', $enrolledCourseIds)
                    ->whereIn('status', ['published', 'active', 'scheduled'])
                    ->whereBetween('start_date', [$start->toDateString(), $end->toDateString()])
                    ->get(),
                $user, false,
            ));
        }
        if ($wants('assignment')) {
            $events = $events->merge($this->normalizeAssignments(
                Assignment::with(['course:id,title,slug'])
                    ->where('status', 'active')
                    ->whereNotNull('due_date')
                    ->whereIn('course_id', $enrolledCourseIds)
                    ->whereBetween('due_date', [$start, $end])
                    // Only show unsubmitted or graded assignments
                    ->whereDoesntHave('submissions', fn ($q) =>
                        $q->where('user_id', $user->id)
                          ->whereNotNull('submitted_at')
                    )
                    ->get(),
                $user, false,
            ));
        }
        if ($wants('learning_path')) {
            $lpEnrollments = LearningPathEnrollment::with('learningPath')
                ->where('user_id', $user->id)
                ->whereIn('status', ['active', 'enrolled'])
                ->get();
            $events = $events->merge($this->normalizeLearningPaths($lpEnrollments, $user));
        }
        if ($wantsProgramType('course') || $wantsProgramType('workshop') || $wantsProgramType('program')) {
            $courses = $this->queryScheduledPrograms($start, $end)
                ->whereIn('id', $enrolledCourseIds)
                ->where('status', 'published')
                ->get();
            $events = $events->merge(
                $this->normalizePrograms($courses, $user, false)
                    ->filter(fn ($e) => $wantsProgramType($e['type'])),
            );
        }


        return $events;
    }

    /**
     * Takes the join link (and the "can join" flag) off course-bound events of courses the user is not enrolled in.
     *
     * @param  Collection<int, array<string, mixed>>  $events
     * @param  Collection<int, int>  $enrolled
     * @return Collection<int, array<string, mixed>>
     */
    private function withoutForeignLinks(Collection $events, Collection $enrolled): Collection
    {
        return $events->map(function (array $e) use ($enrolled) {
            if (! in_array($e['type'] ?? null, ['session', 'course', 'workshop', 'program'], true)) {
                return $e;
            }
            $courseId = $e['course']['id'] ?? null;
            if ($courseId !== null && $enrolled->contains($courseId)) {
                return $e;
            }

            return ['meeting_link' => null, 'can_join' => false] + $e;
        });
    }

    /** @return Collection<int, int> */
    private function enrolledCourseIds($user): Collection
    {
        return app(\App\Services\LmsEnrollmentService::class)->enrolledCourseIds($user);
    }

    private function departmentAccessIsGlobal($user): bool
    {
        return app(\App\Services\DepartmentAccessService::class)->isGlobal($user);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function getUserDepartmentIds($user): Collection
    {
        $byLeader = Department::where('leader_id', $user->id)->pluck('id');
        $byDeptId = $user->department_id
            ? collect([$user->department_id])
            : collect();
        $byName = ($user->department && ! $user->department_id)
            ? Department::where('name', $user->department)
                ->orWhere('name_ar', $user->department)
                ->pluck('id')
            : collect();

        return $byLeader->merge($byDeptId)->merge($byName)->unique()->values();
    }

    /** Europe/Amsterdam is the canonical display timezone for all EMC calendar events. */
    private const DISPLAY_TZ = 'Europe/Amsterdam';

    private function safeCarbon(mixed $value, ?string $tz = null): ?Carbon
    {
        if (! $value) {
            return null;
        }
        try {
            if ($value instanceof Carbon) {
                return $value;
            }
            return $tz ? Carbon::parse($value, $tz) : Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function queryScheduledPrograms(Carbon $start, Carbon $end, ?int $courseId = null)
    {
        return Course::with(['instructor.user'])
            ->whereNotNull('start_date')
            ->whereBetween('start_date', [$start->toDateString(), $end->toDateString()])
            ->when($courseId, fn ($q) => $q->where('id', $courseId));
    }

    private function resolveProgramCalendarType(Course $course): string
    {
        $programType = strtolower((string) ($course->program_type ?? 'course'));

        return match ($programType) {
            'workshop', 'one_session' => 'workshop',
            'full_program'            => 'program',
            default                   => 'course',
        };
    }

    private function courseScheduleStartAt(Course $course): ?Carbon
    {
        $dateStr = $course->start_date instanceof Carbon
            ? $course->start_date->toDateString()
            : (string) ($course->start_date ?? '');

        if ($dateStr === '') {
            return null;
        }

        $time = $course->start_time ?: ($course->study_time ?: '09:00:00');
        if (strlen((string) $time) === 5) {
            $time .= ':00';
        }

        return $this->safeCarbon($dateStr . ' ' . $time, self::DISPLAY_TZ);
    }

    private function courseScheduleEndAt(Course $course): ?Carbon
    {
        $dateStr = $course->end_date instanceof Carbon
            ? $course->end_date->toDateString()
            : (string) ($course->end_date ?? '');

        if ($dateStr === '') {
            $start = $this->courseScheduleStartAt($course);

            return $start?->copy()->addHours(max(1, (int) ($course->training_hours ?: 1)));
        }

        $time = $course->end_time ?: '17:00:00';
        if (strlen((string) $time) === 5) {
            $time .= ':00';
        }

        return $this->safeCarbon($dateStr . ' ' . $time, self::DISPLAY_TZ);
    }

    private function programImageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        if (str_starts_with($path, 'http')) {
            return $path;
        }

        return asset('storage/' . ltrim($path, '/'));
    }

    private function programSubtitle(Course $course): ?string
    {
        $parts = array_filter([
            $course->short_description,
            $course->level,
            $course->duration,
        ]);

        return $parts[0] ?? null;
    }

    private function normalizePrograms(Collection $courses, $user, bool $canEdit): Collection
    {
        return $courses->map(function (Course $course) use ($user, $canEdit) {
            $startAt = $this->courseScheduleStartAt($course);
            $endAt   = $this->courseScheduleEndAt($course);
            $type    = $this->resolveProgramCalendarType($course);
            $instructor = $course->instructor?->user;

            return [
                'id'           => 'program-' . $course->id,
                'source_id'    => $course->id,
                'source'       => 'program',
                'type'         => $type,
                'title'        => $course->title,
                'subtitle'     => $this->programSubtitle($course),
                'description'  => $course->description ?? $course->short_description,
                'start_at'     => $startAt?->toIso8601String(),
                'end_at'       => $endAt?->toIso8601String(),
                'status'       => $course->status ?? 'draft',
                'location'     => $course->location,
                'meeting_link' => $course->meeting_link,
                'image_url'    => $this->programImageUrl($course->course_image ?? $course->course_thumbnail),
                'instructor'   => $instructor
                    ? ['id' => $instructor->id, 'name' => $instructor->name]
                    : null,
                'course'       => [
                    'id'    => $course->id,
                    'title' => $course->title,
                    'slug'  => $course->slug,
                ],
                'workshop'     => null,
                'department'   => null,
                'owner'        => $instructor
                    ? ['id' => $instructor->id, 'name' => $instructor->name]
                    : null,
                'visibility'   => ($course->status ?? 'draft') === 'published' ? 'all' : 'private',
                'can_edit'     => $canEdit,
                'can_join'     => false,
            ];
        });
    }

    // ── Normalizers ───────────────────────────────────────────────────────────

    /**
     * LMS live/scheduled class sessions (table: lms_sessions).
     * These have session_date + start_time / end_time.
     */
    private function normalizeLmsSessions(Collection $sessions, $user, bool $canEdit): Collection
    {
        return $sessions->map(function ($s) use ($canEdit) {
            $dateStr  = $s->session_date instanceof Carbon
                ? $s->session_date->toDateString()
                : (string) $s->session_date;

            $startAt = $dateStr && $s->start_time
                ? Carbon::parse($dateStr . ' ' . $s->start_time, self::DISPLAY_TZ)
                : ($dateStr ? Carbon::parse($dateStr, self::DISPLAY_TZ) : null);

            $endAt = $dateStr && $s->end_time
                ? Carbon::parse($dateStr . ' ' . $s->end_time, self::DISPLAY_TZ)
                : null;

            return [
                'id'           => 'lms_session_' . $s->id,
                'type'         => 'session',
                'title'        => $s->title,
                'subtitle'     => $s->course?->title,
                'description'  => $s->description,
                'start_at'     => $startAt?->toIso8601String(),
                'end_at'       => $endAt?->toIso8601String(),
                'status'       => $s->status ?? 'scheduled',
                'location'     => $s->location,
                'meeting_link' => $s->meeting_url,
                'course'       => $s->course
                    ? ['id' => $s->course->id, 'title' => $s->course->title, 'slug' => $s->course->slug]
                    : null,
                'workshop'     => null,
                'department'   => null,
                'owner'        => null,
                'visibility'   => 'all',
                'can_edit'     => $canEdit,
                'can_join'     => (bool) $s->meeting_url,
            ];
        });
    }

    /**
     * Course content sessions (table: course_sessions).
     * These have start_at / end_at ISO datetimes.
     */
    private function normalizeCourseSessions(Collection $sessions, $user, bool $canEdit): Collection
    {
        return $sessions->map(fn ($s) => [
            'id'           => 'course_session_' . $s->id,
            'type'         => 'session',
            'title'        => $s->title,
            'subtitle'     => $s->course?->title,
            'description'  => $s->description,
            'start_at'     => $this->safeCarbon($s->start_at)?->toIso8601String(),
            'end_at'       => $this->safeCarbon($s->end_at)?->toIso8601String(),
            'status'       => $s->status ?? 'published',
            'location'     => $s->location,
            'meeting_link' => $s->meeting_link,
            'course'       => $s->course
                ? ['id' => $s->course->id, 'title' => $s->course->title, 'slug' => $s->course->slug]
                : null,
            'workshop'     => null,
            'department'   => null,
            'owner'        => null,
            'visibility'   => 'all',
            'can_edit'     => $canEdit,
            'can_join'     => (bool) $s->meeting_link,
        ]);
    }

    private function normalizeWorkshops(Collection $workshops, $user, bool $canEdit): Collection
    {
        return $workshops->map(function ($w) use ($canEdit) {
            $dateStr = $w->start_date instanceof Carbon
                ? $w->start_date->toDateString()
                : (string) ($w->start_date ?? '');

            $startAt = $dateStr
                ? Carbon::parse($dateStr . ' ' . ($w->start_time ?? '00:00:00'), self::DISPLAY_TZ)
                : null;

            $endDateStr = $w->end_date instanceof Carbon
                ? $w->end_date->toDateString()
                : (string) ($w->end_date ?? $dateStr ?? '');

            $endAt = $endDateStr
                ? Carbon::parse($endDateStr . ' ' . ($w->end_time ?? '23:59:00'), self::DISPLAY_TZ)
                : null;

            return [
                'id'           => 'workshop_' . $w->id,
                'type'         => 'workshop',
                'title'        => $w->title,
                'subtitle'     => $w->course?->title,
                'description'  => $w->description,
                'start_at'     => $startAt?->toIso8601String(),
                'end_at'       => $endAt?->toIso8601String(),
                'status'       => $w->status ?? 'published',
                'location'     => $w->location_type,
                'meeting_link' => $w->meeting_link,
                'course'       => $w->course
                    ? ['id' => $w->course->id, 'title' => $w->course->title, 'slug' => $w->course->slug ?? null]
                    : null,
                'workshop'     => ['id' => $w->id, 'title' => $w->title, 'slug' => $w->slug],
                'department'   => null,
                'owner'        => $w->instructor?->user
                    ? ['id' => $w->instructor->user->id, 'name' => $w->instructor->user->name]
                    : null,
                'visibility'   => 'all',
                'can_edit'     => $canEdit,
                'can_join'     => (bool) $w->meeting_link,
            ];
        });
    }

    private function normalizeAssignments(Collection $assignments, $user, bool $canEdit): Collection
    {
        return $assignments->map(fn ($a) => [
            'id'           => 'assignment_' . $a->id,
            'type'         => 'assignment',
            'title'        => $a->title,
            'subtitle'     => $a->course?->title
                ? 'موعد تسليم — ' . $a->course->title
                : 'موعد تسليم الواجب',
            'description'  => $a->description,
            'start_at'     => $this->safeCarbon($a->due_date)?->toIso8601String(),
            'end_at'       => $this->safeCarbon($a->due_date)?->endOfDay()->toIso8601String(),
            'status'       => $a->status ?? 'active',
            'location'     => null,
            'meeting_link' => null,
            'course'       => $a->course
                ? ['id' => $a->course->id, 'title' => $a->course->title, 'slug' => $a->course->slug]
                : null,
            'workshop'     => null,
            'department'   => null,
            'owner'        => null,
            'visibility'   => 'private',
            'can_edit'     => $canEdit,
            'can_join'     => false,
        ]);
    }

    private function normalizeMeetings(Collection $meetings, $user, \App\Services\MeetingAccessService $access): Collection
    {
        return $meetings->map(function ($m) use ($user, $access) {
            $canJoin = $access->canJoin($user, $m);
            $dateStr = $m->meeting_date instanceof Carbon
                ? $m->meeting_date->toDateString()
                : (string) ($m->meeting_date ?? '');
            $startAt = $dateStr && $m->start_time
                ? Carbon::parse($dateStr . ' ' . $m->start_time, self::DISPLAY_TZ)
                : ($dateStr ? Carbon::parse($dateStr, self::DISPLAY_TZ)->startOfDay() : now(self::DISPLAY_TZ)->startOfDay());
            $endAt = $dateStr && $m->end_time
                ? Carbon::parse($dateStr . ' ' . $m->end_time, self::DISPLAY_TZ)
                : null;

            return [
                'id'           => 'meeting_' . $m->id,
                'type'         => 'meeting',
                'title'        => $m->title,
                'subtitle'     => $m->department?->name_ar ?? $m->department?->name,
                'description'  => $m->description,
                'start_at'     => $startAt->toIso8601String(),
                'end_at'       => $endAt?->toIso8601String(),
                'status'       => $m->status ?? 'scheduled',
                'location'     => null,
                'meeting_type' => $m->type,
                'details_url'  => '/dashboard/department/meeting-lounge/'.$m->id,
                'meeting_link' => $canJoin ? $m->meeting_url : null,
                'course'       => null,
                'workshop'     => null,
                'department'   => $m->department
                    ? ['id' => $m->department->id, 'name_ar' => $m->department->name_ar ?? $m->department->name]
                    : null,
                'owner'        => $m->organizer
                    ? ['id' => $m->organizer->id, 'name' => $m->organizer->name]
                    : null,
                'visibility'   => $m->hasExplicitAudience() ? 'participants' : ($m->department_id ? 'department' : 'private'),
                'can_edit'     => $access->canManage($user, $m),
                'can_join'     => $canJoin && (bool) $m->meeting_url,
            ];
        });
    }

    private function normalizeTasks(Collection $tasks, $user, bool $canEdit): Collection
    {
        return $tasks->map(fn ($t) => [
            'id'           => 'task_' . $t->id,
            'type'         => 'task',
            'title'        => $t->title,
            'subtitle'     => $t->department?->name_ar ?? $t->department?->name,
            'description'  => $t->description,
            'start_at'     => $this->safeCarbon($t->due_date)?->toIso8601String(),
            'end_at'       => $this->safeCarbon($t->due_date)?->endOfDay()->toIso8601String(),
            'status'       => $t->status ?? 'pending',
            'location'     => null,
            'meeting_link' => null,
            'course'       => null,
            'workshop'     => null,
            'department'   => $t->department
                ? ['id' => $t->department->id, 'name_ar' => $t->department->name_ar ?? $t->department->name]
                : null,
            'owner'        => $t->assignedTo
                ? ['id' => $t->assignedTo->id, 'name' => $t->assignedTo->name]
                : null,
            'visibility'   => 'department',
            'can_edit'     => $canEdit,
            'can_join'     => false,
        ]);
    }

    private function normalizeLearningPaths(Collection $enrollments, $user): Collection
    {
        return $enrollments
            ->filter(fn ($e) => $e->learningPath && $e->enrolled_at)
            ->map(fn ($e) => [
                'id'           => 'learning_path-' . $e->learningPath->id,
                'source_id'    => $e->learningPath->id,
                'source'       => 'learning_path',
                'type'         => 'learning_path',
                'title'        => $e->learningPath->title,
                'subtitle'     => $e->learningPath->certificate_name,
                'description'  => $e->learningPath->short_description,
                'start_at'     => $this->safeCarbon($e->enrolled_at)?->toIso8601String(),
                'end_at'       => ($e->learningPath->duration && $e->enrolled_at)
                    ? $this->safeCarbon($e->enrolled_at)?->addWeeks((int) $e->learningPath->duration)->toIso8601String()
                    : null,
                'status'       => $e->status ?? 'active',
                'location'     => null,
                'meeting_link' => null,
                'image_url'    => $this->programImageUrl($e->learningPath->featured_image),
                'instructor'   => null,
                'course'       => null,
                'workshop'     => null,
                'department'   => null,
                'owner'        => null,
                'visibility'   => 'private',
                'can_edit'     => false,
                'can_join'     => false,
                'learning_path'=> [
                    'id'   => $e->learningPath->id,
                    'title'=> $e->learningPath->title,
                    'slug' => $e->learningPath->slug,
                ],
            ]);
    }

    private function normalizeCalendarEventList(Collection $events, $user): Collection
    {
        return $events->map(fn ($e) => $this->normalizeCalendarEvent($e, $user));
    }

    private function normalizeCalendarEvent(CalendarEvent $e, $user): array
    {
        return [
            'id'           => 'event_' . $e->id,
            'type'         => $e->kind ?? 'event',
            'title'        => $e->title,
            'subtitle'     => null,
            'description'  => $e->description,
            'start_at'     => $this->safeCarbon($e->start_at)?->toIso8601String(),
            'end_at'       => $this->safeCarbon($e->end_at)?->toIso8601String(),
            'status'       => 'scheduled',
            'location'     => $e->location,
            'meeting_link' => $e->meeting_url,
            'course'       => null,
            'workshop'     => null,
            'department'   => null,
            'owner'        => $e->user ? ['id' => $e->user->id, 'name' => $e->user->name] : null,
            'visibility'   => $e->visibility ?? 'private',
            'can_edit'     => $user->isAdmin() || $e->user_id === $user->id,
            'can_join'     => (bool) $e->meeting_url,
        ];
    }

    // ── ICS builder for normalized events ─────────────────────────────────────

    private function buildIcsFromNormalized(Collection $events): string
    {
        $esc = fn (string $t): string =>
            str_replace(["\n", "\r", ',', ';', '\\'], ['\\n', '', '\\,', '\\;', '\\\\'], $t);

        $fmt = function (string $iso): string {
            $d = new \DateTime($iso);
            $d->setTimezone(new \DateTimeZone('UTC'));

            return $d->format('Ymd\THis\Z');
        };

        $now   = $fmt(now()->toIso8601String());
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//EMC Platform//AR', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH'];

        foreach ($events as $ev) {
            if (empty($ev['start_at'])) {
                continue;
            }
            $endIso = $ev['end_at'] ?? (new \DateTime($ev['start_at']))->modify('+1 hour')->format(\DateTime::ATOM);

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:emc-' . $ev['id'] . '@emc.sa';
            $lines[] = 'DTSTAMP:' . $now;
            $lines[] = 'DTSTART:' . $fmt($ev['start_at']);
            $lines[] = 'DTEND:' . $fmt($endIso);
            $lines[] = 'SUMMARY:' . $esc($ev['title']);
            if (! empty($ev['description'])) {
                $lines[] = 'DESCRIPTION:' . $esc($ev['description']);
            }
            if (! empty($ev['location'])) {
                $lines[] = 'LOCATION:' . $esc($ev['location']);
            }
            if (! empty($ev['meeting_link'])) {
                $lines[] = 'URL:' . $ev['meeting_link'];
            }
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines) . "\r\n";
    }
}
