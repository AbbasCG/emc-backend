<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeetingActionItemResource;
use App\Http\Resources\MeetingDecisionResource;
use App\Http\Resources\MeetingResource;
use App\Models\Meeting;
use App\Models\MeetingActionItem;
use App\Models\MeetingReport;
use App\Models\Task;
use App\Services\ActivityLogger;
use App\Services\DepartmentAccessService;
use App\Services\MeetingAccessService;
use App\Services\MeetingParticipantService;
use App\Models\MeetingActionItem as ActionItemModel;
use App\Models\MeetingDecision;
use App\Models\MeetingParticipant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Meetings: the admin schedule (/dashboard/admin/meetings) and the department
 * lounge (/dashboard/department/meeting-lounge) are two views of the SAME
 * `meetings` rows, served here. A meeting has at most ONE official report
 * (`meeting_reports.meeting_id`), filed from the lounge and read by quality
 * management through the operations "تقارير الاجتماعات" list.
 *
 * Every read is department-scoped and every write is authorized here through
 * MeetingAccessService - route middleware only decides who may reach the
 * controller, never who may change a meeting.
 */
class MeetingController extends Controller
{
    /** The canonical `meetings.type` values the database stores. */
    public const TYPES = ['executive', 'department', 'programs', 'partnerships', 'quality', 'external_partner', 'general'];

    /** Names an older frontend build sent for three of those types. */
    private const TYPE_ALIASES = ['exec' => 'executive', 'departments' => 'department', 'external' => 'external_partner'];

    public const DUPLICATE_REPORT_MESSAGE = 'تم رفع تقرير لهذا الاجتماع مسبقًا.';

    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly DepartmentAccessService $departmentAccess,
        private readonly MeetingParticipantService $participants,
    ) {
    }

    /** One MeetingAccessService per request, shared with the resources (memoized lookups). */
    private function acc(): MeetingAccessService
    {
        return MeetingAccessService::for(request());
    }

    private function forbidden(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], 403);
    }

    /**
     * A business-rule refusal (HTTP 422) in the same envelope as field validation,
     * but with the Arabic reason as the top-level `message` so a client can show
     * it directly. The app's handler would otherwise reduce it to "Validation failed.".
     */
    private function refuse(string $field, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'success' => false,
            'message' => $message,
            'errors'  => [$field => [$message]],
        ], 422));
    }

    private function normalizeType(Request $request): void
    {
        $type = $request->input('type');
        if (is_string($type) && isset(self::TYPE_ALIASES[$type])) {
            $request->merge(['type' => self::TYPE_ALIASES[$type]]);
        }
    }

    /**
     * Meetings this user may read, before any filter. Mirrors
     * MeetingAccessService::canView so a list never shows what the detail refuses:
     * oversight sees all; everyone else sees what they organize, what they are
     * invited to, what their department leads or manages, and department-wide
     * meetings (nobody invited by name) of departments they belong to.
     */
    private function visibleQuery(Request $request): Builder
    {
        $user = $request->user();

        return $this->acc()->hasOversight($user) ? Meeting::query() : $this->acc()->assignedQuery($user);
    }

    /** Narrow a query to upcoming / live / past by the meeting's own wall-clock time (Europe/Amsterdam). */
    private function applyWhen(Builder $query, string $when): void
    {
        $now = now('Europe/Amsterdam');
        $today = $now->toDateString();
        $time = $now->format('H:i:s');

        $ended = fn ($q) => $q->whereDate('meeting_date', '<', $today)
            ->orWhere(fn ($t) => $t->whereDate('meeting_date', $today)->whereNotNull('end_time')->where('end_time', '<', $time));
        $live = fn ($q) => $q->whereDate('meeting_date', $today)->where('start_time', '<=', $time)
            ->where(fn ($e) => $e->whereNull('end_time')->orWhere('end_time', '>=', $time));

        match ($when) {
            'past'     => $query->where($ended),
            'live'     => $query->where($live),
            'upcoming' => $query->whereDate('meeting_date', '>=', $today)->where(fn ($q) => $q->whereDate('meeting_date', '>', $today)
                ->orWhere('start_time', '>', $time)),
            default    => null,
        };
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->normalizeType($request);

        $request->validate([
            'q'             => 'nullable|string|max:100',
            'type'          => 'nullable|string|in:'.implode(',', self::TYPES),
            'status'        => 'nullable|in:scheduled,in_progress,completed,cancelled',
            'report_status' => 'nullable|in:submitted,pending',
            'department_id' => 'nullable|integer',
            'date_from'     => 'nullable|date',
            'date_to'       => 'nullable|date',
            'when'          => 'nullable|in:upcoming,live,past',
            'per_page'      => 'nullable|integer|min:1',
        ]);

        $query = $this->visibleQuery($request)->with(['department:id,name,name_ar', 'organizer:id,name', 'report', 'participants']);
        if ($request->filled('when')) {
            $this->applyWhen($query, (string) $request->query('when'));
        }

        // A department filter can only narrow the user's own scope.
        if ($request->filled('department_id')) {
            $query->where('department_id', (int) $request->query('department_id'));
        }
        if ($request->filled('q')) {
            $query->where('title', 'like', '%'.trim((string) $request->query('q')).'%');
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('meeting_date', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('meeting_date', '<=', $request->query('date_to'));
        }
        if ($request->query('report_status') === 'submitted') {
            $query->whereHas('report');
        } elseif ($request->query('report_status') === 'pending') {
            $query->whereDoesntHave('report')->where('status', '!=', 'cancelled');
        }

        $perPage = min(max((int) $request->query('per_page', 50), 1), self::MAX_PER_PAGE);
        $upcoming = $request->query('when') === 'upcoming' || $request->query('when') === 'live';
        $meetings = ($upcoming
            ? $query->orderBy('meeting_date')->orderBy('start_time')->orderBy('id')
            : $query->orderByDesc('meeting_date')->orderByDesc('start_time')->orderByDesc('id'))->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => MeetingResource::collection($meetings)->response()->getData(true),
            'stats'   => $this->stats($request),
            'access'  => $this->creationAccess($user),
        ]);
    }

    /**
     * Headline counts over everything this user may read (not the current filter).
     *
     * @return array{total: int, this_month: int, awaiting_report: int, with_report: int}
     */
    private function stats(Request $request): array
    {
        $base = fn () => $this->visibleQuery($request);
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        return [
            'total'           => $base()->count(),
            'this_month'      => $base()->whereBetween('meeting_date', [$monthStart, $monthEnd])->count(),
            // Held (or due) and still no report. Future scheduled meetings are not "late".
            'awaiting_report' => $base()->whereDoesntHave('report')
                ->where('status', '!=', 'cancelled')
                ->where(function ($q) {
                    $q->where('status', 'completed')->orWhereDate('meeting_date', '<', now()->toDateString());
                })->count(),
            'with_report'     => $base()->whereHas('report')->count(),
        ];
    }

    /**
     * What the "new meeting" control needs: may this user schedule at all, and for
     * which departments (null = any department).
     *
     * @return array{can_create: bool, creatable_departments: array<int, array{id: int, name: string}>|null}
     */
    private function creationAccess($user): array
    {
        $ids = $this->acc()->creatableDepartmentIds($user);
        $departments = $ids === null
            ? null
            : \App\Models\Department::query()->whereIn('id', $ids)->active()->orderBy('name')->get(['id', 'name', 'name_ar'])
                ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name_ar ?: $d->name])->values()->all();

        return [
            'can_create'            => $this->acc()->canCreateAny($user),
            'creatable_departments' => $departments,
        ];
    }

    /** Validate and normalize `participant_ids` against the pool the server allows for this department. */
    private function validatedParticipantIds(Request $request, ?int $departmentId): ?array
    {
        if (! $request->has('participant_ids')) {
            return null;
        }

        $ids = array_values(array_unique(array_map('intval', (array) $request->input('participant_ids', []))));
        $allowed = $this->participants->candidatesForScheduling($request->user(), $departmentId)->pluck('id')->all();

        if (array_diff($ids, $allowed) !== []) {
            throw $this->refuse('participant_ids', 'بعض المدعوّين ليسوا من أعضاء الإدارة أو نطاقك المسموح.');
        }

        return $ids;
    }

    /**
     * Every meeting must say who it is for: a department (everyone in it) or named
     * invitees. A meeting with neither would be visible to its organizer and oversight
     * only, which is never what "a department meeting" means.
     */
    private function requireAnAudience(?int $departmentId, int $inviteeCount): void
    {
        if ($departmentId === null && $inviteeCount === 0) {
            throw $this->refuse('department_id', 'اختر الإدارة لجعل الاجتماع لكل أعضائها، أو اختر مدعوّين بأسمائهم.');
        }
    }

    /** `agenda` arrives as lines of text; store trimmed non-empty strings. */
    private function normalizedAgenda(Request $request): ?array
    {
        if (! $request->has('agenda')) {
            return null;
        }

        $raw = $request->input('agenda');
        $lines = is_string($raw) ? preg_split('/\r?\n/', $raw) : (array) $raw;

        return array_values(array_filter(array_map(fn ($l) => is_string($l) ? trim($l) : '', $lines), fn ($l) => $l !== ''));
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalizeType($request);

        $data = $request->validate([
            'title'         => 'required|string|max:255',
            'description'   => 'nullable|string',
            'type'          => 'nullable|string|in:'.implode(',', self::TYPES),
            'department_id' => 'nullable|exists:departments,id',
            'meeting_date'  => 'required|date',
            'start_time'    => 'required|date_format:H:i',
            'end_time'      => 'nullable|date_format:H:i|after:start_time',
            'meeting_url'   => 'nullable|url',
            'status'        => 'nullable|in:scheduled,in_progress,completed,cancelled',
            'agenda'        => 'nullable',
            'moderator_id'  => 'nullable|exists:users,id',
            'participant_ids'   => 'nullable|array|max:300',
            'participant_ids.*' => 'integer',
            'minutes'       => 'nullable|string',
            'files'         => 'nullable|array',
            'recording_url' => 'nullable|url',
        ], [
            'title.required'        => 'اكتب عنوان الاجتماع.',
            'meeting_date.required' => 'حدّد تاريخ الاجتماع.',
            'start_time.required'   => 'حدّد وقت بدء الاجتماع.',
            'end_time.after'        => 'يجب أن يكون وقت الانتهاء بعد وقت البدء.',
            'type.in'               => 'نوع الاجتماع غير صالح.',
            'department_id.exists'  => 'الإدارة المختارة غير موجودة.',
            'meeting_url.url'       => 'رابط الاجتماع غير صالح.',
        ]);

        $user = $request->user();
        $departmentId = isset($data['department_id']) ? (int) $data['department_id'] : null;

        // A non-global user who named no department is filed under the one
        // department they can schedule for; with several, they must choose.
        if ($departmentId === null && ! $this->departmentAccess->isGlobal($user)) {
            $allowed = $this->acc()->creatableDepartmentIds($user) ?? [];
            if (count($allowed) === 1) {
                $departmentId = (int) $allowed[0];
            } elseif (count($allowed) > 1) {
                throw $this->refuse('department_id', 'اختر الإدارة التي يتبعها الاجتماع.');
            }
        }

        if (! $this->acc()->canCreate($user, $departmentId)) {
            return $this->forbidden('ليست لديك صلاحية جدولة اجتماعات لهذه الإدارة.');
        }

        $participantIds = $this->validatedParticipantIds($request, $departmentId);
        $this->requireAnAudience($departmentId, count($participantIds ?? []));
        $agenda = $this->normalizedAgenda($request);

        unset($data['participant_ids']);
        $data['department_id'] = $departmentId;
        $data['organizer_id'] = $user->id;
        $data['type'] = $data['type'] ?? 'general';
        $data['agenda'] = $agenda;

        $meeting = DB::transaction(function () use ($data, $participantIds) {
            $meeting = Meeting::create($data);
            if ($participantIds) {
                $this->participants->syncInvited($meeting, $participantIds);
            }

            return $meeting;
        });

        ActivityLogger::log('meeting.created', $meeting, [], $request);

        return response()->json([
            'success' => true,
            'message' => 'تمت جدولة الاجتماع.',
            'data'    => (new MeetingResource($meeting->load(['department:id,name,name_ar', 'organizer:id,name', 'report', 'participants'])))->resolve($request),
        ], 201);
    }

    /** Everything the details page shows, loaded once. */
    private function loadDetail(Meeting $meeting): Meeting
    {
        return $meeting->load([
            'department:id,name,name_ar', 'organizer:id,name', 'decisions.decidedBy', 'actionItems.assignedTo', 'report.author', 'participants',
        ]);
    }

    public function show(Request $request, Meeting $meeting): JsonResponse
    {
        if (! $this->acc()->canView($request->user(), $meeting)) {
            return $this->forbidden('غير مصرح لك باستعراض اجتماع خاص بإدارة أخرى');
        }

        return response()->json([
            'success' => true,
            'data'    => (new MeetingResource($this->loadDetail($meeting)))->detailed()->resolve($request),
        ]);
    }

    public function update(Request $request, Meeting $meeting): JsonResponse
    {
        if (! $this->acc()->canManage($request->user(), $meeting)) {
            return $this->forbidden('ليست لديك صلاحية تعديل هذا الاجتماع.');
        }
        $this->normalizeType($request);

        $data = $request->validate([
            'title'         => 'string|max:255',
            'description'   => 'nullable|string',
            'type'          => 'string|in:'.implode(',', self::TYPES),
            'department_id' => 'nullable|exists:departments,id',
            'meeting_date'  => 'date',
            'start_time'    => 'date_format:H:i',
            'end_time'      => 'nullable|date_format:H:i',
            'meeting_url'   => 'nullable|url',
            'status'        => 'in:scheduled,in_progress,completed,cancelled',
            'agenda'        => 'nullable',
            'moderator_id'  => 'nullable|exists:users,id',
            'participant_ids'   => 'nullable|array|max:300',
            'participant_ids.*' => 'integer',
            'minutes'       => 'nullable|string',
            'files'         => 'nullable|array',
            'recording_url' => 'nullable|url',
        ]);

        // Moving a meeting to another department needs the right to schedule there.
        if (array_key_exists('department_id', $data)
            && (int) $data['department_id'] !== (int) $meeting->department_id
            && ! $this->acc()->canCreate($request->user(), $data['department_id'] !== null ? (int) $data['department_id'] : null)) {
            return $this->forbidden('ليست لديك صلاحية نقل الاجتماع إلى هذه الإدارة.');
        }

        $departmentId = array_key_exists('department_id', $data)
            ? ($data['department_id'] !== null ? (int) $data['department_id'] : null)
            : ($meeting->department_id !== null ? (int) $meeting->department_id : null);
        $participantIds = $this->validatedParticipantIds($request, $departmentId);

        // Re-check the audience only when this edit touches it, so a legacy meeting can still be retitled.
        if (array_key_exists('department_id', $data) || $participantIds !== null) {
            $this->requireAnAudience($departmentId, $participantIds !== null
                ? count($participantIds)
                : $meeting->participants()->where('source', MeetingParticipant::SOURCE_INVITED)->count());
        }

        unset($data['participant_ids']);
        if ($request->has('agenda')) {
            $data['agenda'] = $this->normalizedAgenda($request);
        }

        DB::transaction(function () use ($meeting, $data, $participantIds) {
            $meeting->update($data);
            if ($participantIds !== null) {
                $this->participants->syncInvited($meeting, $participantIds);
            }
        });

        return response()->json([
            'success' => true,
            'data'    => (new MeetingResource($meeting->load(['department:id,name,name_ar', 'organizer:id,name', 'report', 'participants'])))->resolve($request),
        ]);
    }

    // ── Participants & attendance ────────────────────────────────────────────

    /**
     * People who can be invited to a meeting for a department (or, with none,
     * within the viewer's scope). Decided on the server; the client never filters.
     */
    public function candidates(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate(['department_id' => 'nullable|integer|exists:departments,id']);
        $departmentId = $request->filled('department_id') ? (int) $request->query('department_id') : null;

        if (! $this->acc()->canCreateAny($user) || ($departmentId !== null && ! $this->acc()->canCreate($user, $departmentId))) {
            return $this->forbidden('ليست لديك صلاحية اختيار مشاركين لهذه الإدارة.');
        }

        return response()->json([
            'success' => true,
            'data'    => $this->participants->candidatesForScheduling($user, $departmentId)->all(),
        ]);
    }

    /**
     * The attendance roster for a meeting's report: the people who can be marked, each
     * with what is already recorded (joined/confirmed) so the form can pre-check them.
     */
    public function participantPool(Request $request, Meeting $meeting): JsonResponse
    {
        $user = $request->user();
        if (! $this->acc()->canManage($user, $meeting)) {
            return $this->forbidden('ليست لديك صلاحية تسجيل حضور هذا الاجتماع.');
        }

        $meeting->load(['department:id,name,name_ar', 'participants']);
        $rows = $meeting->participants->keyBy('user_id');

        $pool = $this->participants->poolForMeeting($meeting, $user)->map(function (array $m) use ($rows) {
            $row = $rows->get($m['id']);

            return $m + [
                'status'       => $row?->status ?? MeetingParticipant::STATUS_INVITED,
                'joined_at'    => $row?->joined_at?->toIso8601String(),
                'confirmed_at' => $row?->confirmed_at?->toIso8601String(),
            ];
        })->all();

        return response()->json([
            'success'  => true,
            'data'     => $pool,
            'audience' => $meeting->hasExplicitAudience() ? 'explicit' : ($meeting->department_id !== null ? 'department' : 'general'),
        ]);
    }

    private function recordAttendance(Request $request, Meeting $meeting, string $kind): JsonResponse
    {
        $user = $request->user();
        if (! $this->acc()->canView($user, $meeting)) {
            return $this->forbidden('غير مصرح لك باستعراض اجتماع خاص بإدارة أخرى');
        }
        if (! $this->acc()->canJoin($user, $meeting)) {
            return $this->forbidden($meeting->status === 'cancelled' ? 'هذا الاجتماع ملغى.' : 'هذا الاجتماع غير مخصص لك.');
        }

        $this->participants->record($meeting, $user, $kind);

        return response()->json([
            'success' => true,
            'message' => $kind === 'confirm' ? 'تم تأكيد حضورك.' : 'تم تسجيل انضمامك.',
            'data'    => (new MeetingResource($this->loadDetail($meeting->refresh())))->detailed()->resolve($request),
        ]);
    }

    /** "انضمام": the person opened the meeting. The join link is part of the details payload. */
    public function join(Request $request, Meeting $meeting): JsonResponse
    {
        return $this->recordAttendance($request, $meeting, 'join');
    }

    /** "تأكيد الحضور": for in-person meetings or when there is no link. */
    public function confirmAttendance(Request $request, Meeting $meeting): JsonResponse
    {
        return $this->recordAttendance($request, $meeting, 'confirm');
    }

    public function destroy(Meeting $meeting, Request $request): JsonResponse
    {
        if (! $this->acc()->canManage($request->user(), $meeting)) {
            return $this->forbidden('ليست لديك صلاحية حذف هذا الاجتماع.');
        }

        ActivityLogger::log('meeting.deleted', $meeting, [], $request);
        $meeting->delete();

        return response()->json(['success' => true, 'message' => 'Meeting deleted.']);
    }

    public function storeDecision(Request $request, Meeting $meeting): JsonResponse
    {
        if (! $this->acc()->canManage($request->user(), $meeting)) {
            return $this->forbidden('ليست لديك صلاحية إضافة قرار لهذا الاجتماع.');
        }

        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'decided_by'  => 'nullable|exists:users,id',
        ]);

        $decision = $meeting->decisions()->create($data);

        return response()->json([
            'success' => true,
            'data'    => new MeetingDecisionResource($decision->load('decidedBy')),
        ], 201);
    }

    public function storeActionItem(Request $request, Meeting $meeting): JsonResponse
    {
        if (! $this->acc()->canManage($request->user(), $meeting)) {
            return $this->forbidden('ليست لديك صلاحية إضافة بند عمل لهذا الاجتماع.');
        }

        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'assigned_to' => 'nullable|exists:users,id',
            'due_date'    => 'nullable|date',
            'status'      => 'in:pending,in_progress,completed,cancelled',
        ]);

        $item = $meeting->actionItems()->create($data);

        return response()->json([
            'success' => true,
            'data'    => new MeetingActionItemResource($item->load('assignedTo')),
        ], 201);
    }

    /**
     * The meeting's ONE official report. Filed once; a second attempt is refused
     * with a clear Arabic message - by this check, by a row lock against two
     * simultaneous submits, and by a unique index on meeting_id where the table
     * has no historical duplicates.
     *
     * The report carries the meeting's attendance (who the server allows as a
     * participant, marked present or absent), its decisions and its action items.
     * Everything the client names is checked against the participant pool the
     * server computes; the client's own filtering is never trusted.
     */
    public function storeReport(Request $request, Meeting $meeting): JsonResponse
    {
        $user = $request->user();

        if (! $this->acc()->canView($user, $meeting)) {
            return $this->forbidden('غير مصرح لك باستعراض اجتماع خاص بإدارة أخرى');
        }
        if (! $this->acc()->canManage($user, $meeting)) {
            return $this->forbidden('ليست لديك صلاحية رفع تقرير لهذا الاجتماع.');
        }
        // Say so before anything else: a second report is never welcome, whatever it contains.
        if ($meeting->report()->exists()) {
            throw $this->refuse('meeting', self::DUPLICATE_REPORT_MESSAGE);
        }

        $data = $request->validate([
            'absentees'               => 'nullable|array|max:100',
            'absentees.*'             => 'string|max:120',
            'team_commitment_score'   => 'nullable|integer|min:1|max:5',
            'team_commitment_notes'   => 'nullable|string|max:5000',
            'completed_tasks_summary' => 'nullable|string|max:5000',
            'kpi_percentage'          => 'nullable|integer|min:0|max:100',
            'top_interactive_member'  => 'nullable|string|max:255',
            'challenges'              => 'nullable|string|max:5000',
            'executive_requests'      => 'nullable|string|max:5000',
            'next_month_goals'        => 'nullable|string|max:5000',
            'improvement_suggestions' => 'nullable|string|max:5000',
            'notes'                   => 'nullable|string|max:5000',
            'attendance'              => 'nullable|array|max:300',
            'attendance.*.user_id'    => 'required|integer',
            'attendance.*.attended'   => 'required|boolean',
            'decisions'               => 'nullable|array|max:50',
            'decisions.*.title'       => 'required|string|max:255',
            'decisions.*.description' => 'nullable|string|max:2000',
            'action_items'            => 'nullable|array|max:50',
            'action_items.*.title'    => 'required|string|max:255',
            'action_items.*.assigned_to' => 'nullable|integer',
            'action_items.*.due_date' => 'nullable|date',
        ], [
            'team_commitment_score.min' => 'مقياس التزام الفريق من 1 إلى 5.',
            'team_commitment_score.max' => 'مقياس التزام الفريق من 1 إلى 5.',
            'kpi_percentage.max'        => 'نسبة تحقيق الأهداف بين 0 و100.',
            'decisions.*.title.required' => 'اكتب نص القرار أو احذف السطر الفارغ.',
            'action_items.*.title.required' => 'اكتب وصف بند العمل أو احذف السطر الفارغ.',
        ]);

        // An official report is filed once and cannot be replaced, so an empty one
        // would lock the meeting out of a real report.
        $narrative = array_filter(
            array_intersect_key($data, array_flip(['completed_tasks_summary', 'challenges', 'executive_requests', 'next_month_goals', 'improvement_suggestions', 'team_commitment_notes', 'notes'])),
            fn ($v) => is_string($v) && trim($v) !== ''
        );
        if ($narrative === []) {
            throw $this->refuse('report', 'أضف محتوى للتقرير قبل رفعه (إنجازات، تحديات، طلبات أو أهداف).');
        }

        // Attendance and responsible people must come from the server's pool for this meeting.
        $pool = $this->participants->poolForMeeting($meeting->loadMissing('department'), $user)->keyBy('id');
        $attendance = collect($data['attendance'] ?? [])->unique('user_id')->values();

        if ($attendance->contains(fn ($a) => ! $pool->has((int) $a['user_id']))) {
            throw $this->refuse('attendance', 'لا يمكن تسجيل حضور مستخدم ليس من المشاركين في هذا الاجتماع.');
        }
        if ($pool->isNotEmpty() && $attendance->isEmpty()) {
            throw $this->refuse('attendance', 'سجّل حضور المشاركين قبل رفع التقرير.');
        }
        foreach ($data['action_items'] ?? [] as $item) {
            if (! empty($item['assigned_to']) && ! $pool->has((int) $item['assigned_to'])) {
                throw $this->refuse('action_items', 'المسؤول عن بند العمل يجب أن يكون من المشاركين في الاجتماع.');
            }
        }

        $absentNames = $attendance->filter(fn ($a) => ! $a['attended'])
            ->map(fn ($a) => $pool[(int) $a['user_id']]['name'])->values()->all();
        $absentees = array_values(array_unique(array_merge($data['absentees'] ?? [], $absentNames)));

        $duplicate = $this->refuse('meeting', self::DUPLICATE_REPORT_MESSAGE);

        try {
            DB::transaction(function () use ($meeting, $data, $user, $duplicate, $attendance, $absentees) {
                // Serialize concurrent submits for this meeting.
                $locked = Meeting::query()->whereKey($meeting->id)->lockForUpdate()->firstOrFail();

                if ($locked->status === 'cancelled') {
                    throw $this->refuse('meeting', 'لا يمكن رفع تقرير لاجتماع ملغى.');
                }
                if ($locked->report()->exists()) {
                    throw $duplicate;
                }

                $notesParts = array_filter([
                    ! empty($data['notes']) ? $data['notes'] : null,
                    ! empty($data['team_commitment_notes']) ? $data['team_commitment_notes'] : null,
                    ! empty($data['improvement_suggestions']) ? $data['improvement_suggestions'] : null,
                ]);

                $locked->report()->create(array_merge(
                    collect($data)->except(['attendance', 'decisions', 'action_items', 'notes'])->all(),
                    [
                        'absentees'     => $absentees,
                        'department_id' => $locked->department_id,
                        'title'         => 'تقرير دوري — '.$locked->title,
                        'meeting_date'  => $locked->meeting_date ?? now()->toDateString(),
                        'created_by'    => $user->id,
                        // Mirrors the structured-minutes columns so the operations list shows the same text.
                        'achieved'      => $data['completed_tasks_summary'] ?? null,
                        'planned'       => $data['next_month_goals'] ?? null,
                        'needs'         => $data['executive_requests'] ?? null,
                        'needs_reason'  => $data['challenges'] ?? null,
                        'notes'         => $notesParts ? implode("\n", $notesParts) : null,
                    ],
                ));

                $this->participants->markAttendance($locked, $attendance->map(fn ($a) => [
                    'user_id' => (int) $a['user_id'], 'attended' => (bool) $a['attended'],
                ])->all(), $user);

                foreach ($data['decisions'] ?? [] as $d) {
                    MeetingDecision::create([
                        'meeting_id' => $locked->id, 'title' => $d['title'], 'description' => $d['description'] ?? null, 'decided_by' => $user->id,
                    ]);
                }
                foreach ($data['action_items'] ?? [] as $i) {
                    ActionItemModel::create([
                        'meeting_id' => $locked->id, 'title' => $i['title'], 'assigned_to' => $i['assigned_to'] ?? null,
                        'due_date' => $i['due_date'] ?? null, 'status' => 'pending',
                    ]);
                }

                // A report means the meeting was held.
                if (in_array($locked->status, ['scheduled', 'in_progress'], true)) {
                    $locked->update(['status' => 'completed']);
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw $duplicate; // two submits raced past the check; the unique index caught the second
        }

        ActivityLogger::log('meeting.report_submitted', $meeting, [], $request);

        return response()->json([
            'success' => true,
            'message' => 'تم رفع تقرير الاجتماع.',
            'data'    => (new MeetingResource($this->loadDetail($meeting->refresh())))->detailed()->resolve($request),
        ], 201);
    }

    /** Kept for the older route name; the single code path is storeReport(). */
    public function report(Request $request, Meeting $meeting): JsonResponse
    {
        return $this->storeReport($request, $meeting);
    }

    public function convertActionItemToTask(Request $request, Meeting $meeting, MeetingActionItem $actionItem): JsonResponse
    {
        abort_if($actionItem->meeting_id !== $meeting->id, 404);

        if (! $this->acc()->canManage($request->user(), $meeting)) {
            return $this->forbidden('ليست لديك صلاحية تحويل بنود هذا الاجتماع إلى مهام.');
        }

        $data = $request->validate([
            'department_id' => 'nullable|exists:departments,id',
            'priority'      => 'in:low,medium,high,urgent',
        ]);

        $task = DB::transaction(function () use ($actionItem, $data, $request) {
            $task = Task::create([
                'title'         => $actionItem->title,
                'assigned_to'   => $actionItem->assigned_to,
                'due_date'      => $actionItem->due_date,
                'created_by'    => $request->user()->id,
                'department_id' => $data['department_id'] ?? null,
                'priority'      => $data['priority'] ?? 'medium',
                'status'        => 'planning',
            ]);

            $actionItem->update(['task_id' => $task->id]);

            return $task;
        });

        ActivityLogger::log('task.converted_from_action_item', $task, ['action_item_id' => $actionItem->id], $request);

        return response()->json([
            'success' => true,
            'message' => 'Action item converted to task.',
            'data'    => ['task_id' => $task->id],
        ], 201);
    }

    /** تحديث بند متابعة (إنجاز/نص) — تستهلكه صالة الاجتماعات. */
    public function updateActionItem(Request $request, Meeting $meeting, MeetingActionItem $actionItem): JsonResponse
    {
        abort_if($actionItem->meeting_id !== $meeting->id, 404);

        $user = $request->user();
        $isAssignee = $actionItem->assigned_to !== null && (int) $actionItem->assigned_to === $user->id;
        if (! $isAssignee && ! $this->acc()->canManage($user, $meeting)) {
            return $this->forbidden('ليست لديك صلاحية تحديث بنود هذا الاجتماع.');
        }

        $data = $request->validate([
            'done'   => 'nullable|boolean',
            'text'   => 'nullable|string|max:255',
            'status' => 'nullable|in:pending,in_progress,completed,cancelled',
        ]);

        $updates = [];
        if (array_key_exists('done', $data)) {
            $updates['status'] = $data['done'] ? 'completed' : 'pending';
        }
        if (! empty($data['status'])) {
            $updates['status'] = $data['status'];
        }
        if (! empty($data['text'])) {
            $updates['title'] = $data['text'];
        }
        $actionItem->update($updates);

        return response()->json([
            'success' => true,
            'data'    => new MeetingActionItemResource($actionItem->fresh()->load('assignedTo')),
        ]);
    }

    /**
     * Close the meeting from the lounge: marks attendance & excuses, calculates discipline rate automatically,
     * awards attendance points to attendees, awards 100 points to top interactive member,
     * and saves decisions & action items without requiring a manual meeting report.
     */
    public function closeLounge(Request $request, Meeting $meeting): JsonResponse
    {
        $user = $request->user();

        if (! $this->acc()->canView($user, $meeting)) {
            return $this->forbidden('غير مصرح لك باستعراض اجتماع خاص بإدارة أخرى');
        }
        if (! $this->acc()->canManage($user, $meeting)) {
            return $this->forbidden('ليست لديك صلاحية إغلاق هذا الاجتماع.');
        }

        $data = $request->validate([
            'attendance'                  => 'required|array|min:1|max:300',
            'attendance.*.user_id'        => 'required|integer|exists:users,id',
            'attendance.*.attended'       => 'required|boolean',
            'attendance.*.excuse_status'  => 'nullable|string|in:with_excuse,without_excuse',
            'attendance.*.excuse_reason'  => 'nullable|string|max:1000',
            'top_interactive_user_id'     => 'nullable|integer|exists:users,id',
            'minutes'                     => 'nullable|string|max:10000',
            'decisions'                   => 'nullable|array|max:50',
            'decisions.*.title'           => 'required|string|max:255',
            'decisions.*.description'     => 'nullable|string|max:2000',
            'action_items'                => 'nullable|array|max:50',
            'action_items.*.title'        => 'required|string|max:255',
            'action_items.*.assigned_to'  => 'nullable|integer|exists:users,id',
            'action_items.*.due_date'     => 'nullable|date',
        ]);

        $pool = $this->participants->poolForMeeting($meeting->loadMissing('department'), $user)->keyBy('id');
        $attendance = collect($data['attendance'])->unique('user_id')->values();

        if ($attendance->contains(fn ($a) => ! $pool->has((int) $a['user_id']))) {
            throw $this->refuse('attendance', 'لا يمكن تسجيل حضور مستخدم ليس من المشاركين في هذا الاجتماع.');
        }

        $totalCount = $attendance->count();
        $attendedItems = $attendance->filter(fn ($a) => (bool) $a['attended']);
        $attendedCount = $attendedItems->count();

        // Automatic attendance discipline rate percentage (0.00% to 100.00%)
        $disciplineRate = $totalCount > 0 ? round(($attendedCount / $totalCount) * 100, 2) : 100.00;

        $topUserId = ! empty($data['top_interactive_user_id']) ? (int) $data['top_interactive_user_id'] : null;
        if ($topUserId !== null && ! $pool->has($topUserId)) {
            throw $this->refuse('top_interactive_user_id', 'العضو الأكثر تفاعلاً يجب أن يكون من المشاركين في الاجتماع.');
        }

        DB::transaction(function () use ($meeting, $data, $user, $attendance, $attendedItems, $disciplineRate, $topUserId) {
            $locked = Meeting::query()->whereKey($meeting->id)->lockForUpdate()->firstOrFail();

            // 1. Update meeting metadata
            $updateData = [
                'status'                  => 'completed',
                'discipline_rate'         => $disciplineRate,
                'top_interactive_user_id' => $topUserId,
            ];
            if (isset($data['minutes'])) {
                $updateData['minutes'] = $data['minutes'];
            }
            $locked->update($updateData);

            // 2. Mark attendance with excuses
            $this->participants->markAttendance($locked, $attendance->all(), $user);

            // 3. Save Decisions
            foreach ($data['decisions'] ?? [] as $d) {
                MeetingDecision::create([
                    'meeting_id'  => $locked->id,
                    'title'       => $d['title'],
                    'description' => $d['description'] ?? null,
                    'decided_by'  => $user->id,
                ]);
            }

            // 4. Save Action items
            foreach ($data['action_items'] ?? [] as $i) {
                ActionItemModel::create([
                    'meeting_id'  => $locked->id,
                    'title'       => $i['title'],
                    'assigned_to' => $i['assigned_to'] ?? null,
                    'due_date'    => $i['due_date'] ?? null,
                    'status'      => 'pending',
                ]);
            }

            // 5. Automatic Impact Points:
            // Revoke any previous awards for this meeting to stay idempotent
            \App\Services\ImpactPoints::revokeSource('meeting', $locked->id);

            // A) 50 points to all confirmed attendees
            foreach ($attendedItems as $att) {
                \App\Services\ImpactPoints::awardMeetingAttendance(
                    (int) $att['user_id'],
                    $locked->title,
                    $user->id,
                    'meeting',
                    $locked->id,
                );
            }

            // B) 100 points bonus for top interactive member
            if ($topUserId !== null) {
                \App\Services\ImpactPoints::awardTopInteractiveBonus(
                    $topUserId,
                    $locked->title,
                    $user->id,
                    'meeting',
                    $locked->id,
                );

                // Notify top interactive user
                \App\Services\NotificationService::send(
                    $topUserId,
                    'meeting_top_interactive',
                    "وسام التفاعل المتميز 🌟",
                    "تهانينا! حصلت على 100 نقطة أثر كأكثر الأعضاء تفاعلاً في اجتماع '{$locked->title}'",
                    [
                        'entity_type' => 'meeting',
                        'entity_id'   => $locked->id,
                        'action_url'  => "/dashboard/assignee?tab=points",
                        'meta_url'    => "/dashboard/assignee?tab=points",
                        'icon'        => 'star',
                        'color'       => 'amber',
                    ]
                );
            }
        });

        ActivityLogger::log('meeting.closed_from_lounge', $meeting, [], $request);

        return response()->json([
            'success' => true,
            'message' => 'تم إنهاء الاجتماع ورصد الحضور واحتساب نقاط الأثر تلقائياً.',
            'data'    => (new MeetingResource($this->loadDetail($meeting->refresh())))->detailed()->resolve($request),
        ]);
    }
}
