<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\DepartmentalUnit;
use App\Models\Ticket;
use App\Models\TicketActivityLog;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Services\DepartmentAccessService;
use App\Exceptions\TicketActionException;
use App\Services\TicketAccess;
use App\Services\TicketActions;
use App\Services\TicketQuery;
use App\Services\TicketReporterView;
use App\Services\TicketWorkEstimate;
use App\Support\TicketValidationMessages;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use App\Services\TicketAttachmentService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * EMC internal issue/suggestion tracker.
 *
 * Authorization model (all non-privileged checks go through the canonical
 * DepartmentAccessService — the same source of truth used by Meeting/Weekly
 * Reports — never a hardcoded role-name check):
 *
 *   - Any authenticated user may submit a ticket and view/comment on tickets
 *     they created, are assigned to, or that belong to a department they're
 *     a member/leader of (or global, for privileged system roles).
 *   - Only a department LEADER (departments.leader_id) or a privileged
 *     system role (super_admin/tech_admin/admin/executive_admin) may
 *     approve, admin-reject, or reassign a ticket for that department.
 *   - Only the ticket's actual assignee (or a privileged role) may
 *     accept/reject/complete the assigned task.
 */
class TicketController extends Controller
{
    private readonly TicketAccess $access;

    private readonly TicketQuery $ticketQuery;

    /**
     * The original one-argument construction keeps working (a subclass or test may build the controller
     * with only the department service); the shared access and query services then come from the
     * container. When the container builds the controller it injects all three.
     */
    public function __construct(
        private readonly DepartmentAccessService $departmentAccess,
        ?TicketAccess $access = null,
        ?TicketQuery $ticketQuery = null,
    ) {
        $this->access = $access ?? app(TicketAccess::class);
        $this->ticketQuery = $ticketQuery ?? app(TicketQuery::class);
    }

    // The authorization rules live in TicketAccess (shared with the operations endpoints, so the work
    // queue and the single-ticket page can never differ). These thin wrappers keep this controller's
    // call sites unchanged.

    /** Creator, assignee, department member/leader, or global. */
    private function canView(User $user, Ticket $ticket): bool
    {
        return $this->access->canView($user, $ticket);
    }

    /** Department leadership (or global) only — never mere membership, never a role-name guess. */
    private function canManage(User $user, int $departmentId): bool
    {
        return $this->access->canManage($user, $departmentId);
    }

    /** The ticket's actual assignee (or global). */
    private function isAssignee(User $user, Ticket $ticket): bool
    {
        return $this->access->isAssignee($user, $ticket);
    }

    /** Only the submitter: no management, assignment or global role over the ticket. */
    private function isReporterOnly(User $user, Ticket $ticket): bool
    {
        return $this->access->isReporterOnly($user, $ticket);
    }

    /** Exact unit membership when a unit is given; department access otherwise. */
    private function assigneeEligible(User $assignee, int $departmentId, ?int $unitId): bool
    {
        return $this->access->assigneeEligible($assignee, $departmentId, $unitId);
    }

    /**
     * The canonical per-ticket, per-user capability set (see TicketAccess::capabilities). It REPORTS
     * what the endpoints below would allow; `assign` and `reassign` share one flag because there is
     * no separate assign endpoint.
     *
     * @return array<string, bool>
     */
    private function buildCapabilities(User $user, Ticket $ticket): array
    {
        return $this->access->capabilities($user, $ticket);
    }

    /**
     * Run a status transition under a row lock: the ticket is re-read FOR UPDATE inside a
     * transaction and the callback decides from THAT state, so two people acting at once cannot both
     * succeed (the second sees the new status and gets its 409). Observer notifications are
     * deferred until the transaction commits.
     *
     * @param  callable(Ticket): mixed  $fn
     */
    private function lockedTransition(int|string $id, callable $fn): mixed
    {
        return DB::transaction(function () use ($id, $fn) {
            $ticket = Ticket::lockForUpdate()->where('id', $id)->orWhere('ticket_number', $id)->firstOrFail();

            return $fn($ticket);
        });
    }

    /**
     * A ticket as an action answers with it: the serialized row plus the viewer's capabilities and, for those who may
     * read them, the redacted `credit` block (the raw credit columns never leave in a model dump).
     *
     * @return array<string, mixed>
     */
    private function actionPayload(Ticket $ticket, User $user): array
    {
        $data = $ticket->toArray();
        $data['capabilities'] = $this->buildCapabilities($user, $ticket);

        return $this->access->redactInternal($user, $ticket, $data);
    }
    private function forbidden(string $message = 'ليست لديك صلاحية للوصول إلى هذه التذكرة.')
    {
        return response()->json(['success' => false, 'message' => $message], 403);
    }

    /**
     * System metadata for the ticket forms. Authenticated only.
     *
     * `tech_units` (kept under its original key for frontend-contract
     * stability) is now genuinely department-scoped: pass `department_id`
     * (normally the target ticket's own department) to get that
     * department's active units — never a hardcoded "Technology department"
     * lookup, and never every unit in the system. Omitted, it returns none
     * — a caller should always know which department it's asking about.
     *
     * `users` (the assignable-employee list) is scoped the same way it
     * always was by department alone, but now additionally narrows to a
     * specific technical unit when `unit_id` is passed alongside
     * `department_id` — active team_members of that exact unit, never the
     * whole department or the whole platform. Only privileged roles ever
     * receive this list; email is intentionally omitted (least privilege —
     * the UI only needs id/name to let someone search and select).
     */
    public function meta(Request $request)
    {
        $user = $request->user();

        $departments = Department::active()->where('is_active', true)->get(['id', 'name', 'name_ar', 'slug']);

        $departmentId = $request->integer('department_id') ?: null;
        $unitId = $request->integer('unit_id') ?: null;

        $techUnits = [];
        if ($departmentId) {
            $techUnits = DepartmentalUnit::where('department_id', $departmentId)
                ->where('is_active', true)
                ->get();
        }

        // Assignable users: only relevant when building the approve/reassign UI, so gated the same way approve() /
        // reassign() themselves are (department leader or global). The list is built by the SAME query the
        // endpoints validate with (TicketAccess::eligibleAssigneesQuery): with a unit, its exact active members;
        // without one, the department's leader, team members and members. A listed person is never refused by
        // approve/reassign and a refused person is never listed. Only id/name/role leave the server (no e-mail).
        $assignableUsers = [];
        if ($departmentId && $this->departmentAccess->canManageDepartment($user, $departmentId)) {
            $assignableUsers = $this->access->eligibleAssignees($departmentId, $unitId);
        }
        return response()->json([
            'success' => true,
            'departments' => $departments,
            'tech_units' => $techUnits,
            // A department without any active technical unit never needs one: its people are assignable directly.
            'has_units' => $departmentId ? $this->access->departmentHasUnits($departmentId) : false,
            'users' => $assignableUsers,
            'categories' => [
                ['id' => 'OLD_ISSUE', 'label_ar' => 'مشكلة قائمة / قديمة', 'label_en' => 'Existing / Past Issue'],
                ['id' => 'NEW_SUGGESTION', 'label_ar' => 'مقترح جديد / تطويري', 'label_en' => 'New Feature / Improvement Suggestion'],
            ],
            'priorities' => [
                ['id' => 'LOW', 'label_ar' => 'منخفضة', 'color' => 'gray'],
                ['id' => 'MEDIUM', 'label_ar' => 'متوسطة', 'color' => 'blue'],
                ['id' => 'HIGH', 'label_ar' => 'عالية', 'color' => 'amber'],
                ['id' => 'CRITICAL', 'label_ar' => 'حرجة / طارئة', 'color' => 'red'],
            ],
            'statuses' => [
                'PENDING_APPROVAL' => ['label_ar' => 'معلقة (بانتظار الاعتماد)', 'color' => 'yellow'],
                'ASSIGNED' => ['label_ar' => 'معتمدة ومحالة', 'color' => 'blue'],
                'IN_PROGRESS' => ['label_ar' => 'قيد المعالجة', 'color' => 'purple'],
                'PENDING_REVIEW' => ['label_ar' => 'بانتظار المراجعة والاعتماد', 'color' => 'indigo'],
                'RESOLVED' => ['label_ar' => 'تم الحل والإغلاق', 'color' => 'green'],
                'UNRESOLVED' => ['label_ar' => 'تعذر الحل', 'color' => 'orange'],
                'REJECTED_BY_ADMIN' => ['label_ar' => 'مرفوضة من الإدارة', 'color' => 'red'],
                'REJECTED_BY_ASSIGNEE' => ['label_ar' => 'مرفوضة من المكلف', 'color' => 'rose'],
            ]
        ]);
    }

    /**
     * Filterable, sortable, paginated tickets list — scoped to what the user may actually see: global
     * roles see everything, everyone else sees tickets in their accessible departments plus anything
     * they created or are assigned to. A filter only ever narrows that set (TicketQuery).
     *
     * `lite=1` (the work queue) returns slim rows: no attachment files and only id/name for the
     * people involved. Without it the payload is exactly what existing callers always received.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $f = $this->ticketQuery->validate($request);
        $lite = (bool) ($f['lite'] ?? false);

        $query = $this->ticketQuery->visible($user);
        $this->ticketQuery->filter($query, $f, $user);
        $this->ticketQuery->withLastActivity($query);

        if ($lite) {
            $query->with(['department:id,name,name_ar', 'unit:id,name_ar', 'creator:id,name', 'assignee:id,name', 'creditor:id,name'])
                ->withCount('attachments');
        } else {
            $query->with(['department', 'unit', 'creator', 'approver', 'assignee', 'creditor:id,name', 'attachments']);
        }

        $this->ticketQuery->sort($query, $f);
        $tickets = $query->paginate($f['per_page'] ?? TicketQuery::DEFAULT_PER_PAGE);

        // Same capability computation as show() - one canonical source, so the work queue and the
        // detail page can never drift on which actions a given ticket/user pair actually permits.
        $tickets->getCollection()->transform(function (Ticket $ticket) use ($user, $lite) {
            // Someone who is only the submitter gets the reporter-safe row (no assignee, deadline or notes).
            if ($this->isReporterOnly($user, $ticket)) {
                return app(TicketReporterView::class)->row($ticket);
            }

            $data = $ticket->toArray();
            if ($lite) {
                // The list never needs the long text; the detail request has it.
                unset($data['description']);
            }
            $data['capabilities'] = $this->buildCapabilities($user, $ticket);

            return $this->access->redactInternal($user, $ticket, $data);
        });

        return response()->json([
            'success' => true,
            'data' => $tickets,
        ]);
    }

    /**
     * Submit a new ticket with optional file attachments. Any authenticated user.
     */
    public function store(Request $request, TicketAttachmentService $attachments)
    {
        $user = $request->user();

        /** @var array<int, string> Files written to disk, for compensating cleanup. */
        $storedPaths = [];

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'ticket_category' => 'required|in:OLD_ISSUE,NEW_SUGGESTION',
            'priority' => 'nullable|in:LOW,MEDIUM,HIGH,CRITICAL',
            'department_id' => 'required|exists:departments,id',
            'created_by_name' => 'required|string|max:150',
            'created_by_email' => 'nullable|email|max:150',
            // ONE canonical policy, owned by TicketAttachmentService. Video and
            // SVG are deliberately absent: a ticket attachment is a screenshot or
            // a document, and SVG is active content browsers execute.
            'attachments.*' => 'nullable|file|mimes:'.TicketAttachmentService::MIMES_RULE.'|max:'.TicketAttachmentService::MAX_KILOBYTES,
        ], TicketValidationMessages::all());

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'بيانات التذكرة غير مكتملة أو تحتوي على أخطاء',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $ticket = $this->createTicketWithUniqueNumber($request, $user);

            // Handle file attachments. Every write goes through the one service
            // that owns the policy, the optimisation and the storage naming.
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $stored = $attachments->store($file);
                    $storedPaths[] = $stored['path'];

                    TicketAttachment::create([
                        'ticket_id' => $ticket->id,
                        'file_url' => $stored['path'],
                        'file_name' => $stored['original_name'],
                        'file_type' => $stored['category'],
                        'mime_type' => $stored['mime'],
                        'file_size' => $stored['size'],
                        'attachment_context' => 'INITIAL_SUBMISSION',
                        'uploaded_by' => $user->id,
                    ]);
                }
            }

            // Create activity log — actor always comes from the authenticated session, never the payload.
            TicketActivityLog::create([
                'ticket_id' => $ticket->id,
                'action' => 'SUBMITTED',
                'previous_state' => null,
                'new_state' => 'PENDING_APPROVAL',
                'performed_by' => $user->id,
                'performed_by_name' => $user->name,
                'details' => 'تم إنشاء التذكرة بنجاح وتوجيهها للإدارة المعنية',
                'logged_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'تم تقديم التذكرة بنجاح وتندرج الآن تحت بند معلقة بانتظار اعتماد الإدارة',
                'data' => $ticket->load(['department', 'attachments', 'activityLogs'])->makeHidden([...TicketWorkEstimate::KEYS, 'actual_minutes']),
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();

            // The DB is rolled back, but files already written are not - remove
            // them so a failed submission cannot leave orphans on the disk.
            $attachments->cleanup($storedPaths);

            // The user message stays generic on purpose; the diagnostic context
            // belongs in the log, which previously received nothing at all.
            Log::error('Ticket creation failed.', [
                'user_id' => $user->id,
                'department_id' => $request->input('department_id'),
                'attachment_count' => count($storedPaths),
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء حفظ التذكرة. حاول مرة أخرى.',
            ], 500);
        }
    }

    /**
     * Create the Ticket row with a collision-safe number.
     *
     * The previous generator used `count() + 1`, which is not a sequence: a
     * hard-deleted ticket lowers the count so the next number lands on one that
     * already exists. Ticket::create() then hit the UNIQUE index on
     * ticket_number, threw, and was swallowed into the generic 500 - the
     * confirmed production symptom.
     *
     * nextTicketNumber() fixes that by deriving from the highest EXISTING
     * number, which is monotonic under deletion. But its own existence check is
     * a plain SELECT, not a lock: two requests racing in the same instant can
     * both read the same MAX() before either commits, and both compute the same
     * candidate. That is a genuinely different race, not the deletion bug, and
     * no amount of pre-checking closes it without serialising every ticket
     * creation behind a table lock - which would throttle submissions for a
     * race that barely ever fires.
     *
     * Instead we let the UNIQUE index be the actual safety net: a collision
     * throws a duplicate-entry QueryException, caught here and retried with a
     * freshly computed number. No duplicate ticket_number can ever be
     * persisted - the constraint guarantees that regardless of this loop - and
     * a genuine race now self-heals inside one request instead of surfacing a
     * 500 the user would have to retry by hand.
     */
    private function createTicketWithUniqueNumber(Request $request, User $user): Ticket
    {
        $maxAttempts = 5;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return Ticket::create([
                    'ticket_number' => $this->nextTicketNumber(),
                    'title' => $request->title,
                    'description' => $request->description,
                    'ticket_category' => $request->ticket_category,
                    'priority' => $request->priority ?? 'MEDIUM',
                    'department_id' => $request->department_id,
                    'created_by_id' => $user->id,
                    'created_by_name' => $request->created_by_name,
                    'created_by_email' => $request->created_by_email,
                    'status' => 'PENDING_APPROVAL',
                ]);
            } catch (QueryException $e) {
                // SQLSTATE 23000 = integrity constraint violation. Only a
                // duplicate ticket_number is worth retrying; any other
                // constraint failure (e.g. an invalid department_id slipping
                // past validation) must propagate immediately.
                $isDuplicate = $e->getCode() === '23000'
                    && str_contains(strtolower($e->getMessage()), 'ticket_number');

                if (! $isDuplicate || $attempt === $maxAttempts) {
                    throw $e;
                }

                // Fall through and recompute on the next iteration. A single
                // MySQL statement failing inside a transaction does not abort
                // the transaction itself (unlike Postgres), so the same
                // DB::beginTransaction() from store() remains valid to commit
                // once this loop succeeds.
            }
        }

        // Unreachable: the loop above always returns or throws.
        throw new \RuntimeException('Failed to allocate a unique ticket number.');
    }

    /**
     * Next candidate ticket number. NOT concurrency-safe on its own - see
     * createTicketWithUniqueNumber(), which is the only caller and which
     * treats this purely as a candidate to attempt, not a guarantee.
     */
    protected function nextTicketNumber(): string
    {
        $year = date('Y');
        $prefix = 'EMC-TCK-'.$year.'-';

        $highest = Ticket::withTrashed()
            ->where('ticket_number', 'like', $prefix.'%')
            ->selectRaw('MAX(CAST(SUBSTRING(ticket_number, ?) AS UNSIGNED)) AS seq', [strlen($prefix) + 1])
            ->value('seq');

        $next = ((int) $highest) + 1;

        // Skip any number already committed (e.g. a gap-filling row inserted by
        // a previous request). This still doesn't see another transaction's
        // UNCOMMITTED insert - that residual race is what the caller's retry
        // loop exists to close.
        while (Ticket::withTrashed()->where('ticket_number', $prefix.sprintf('%03d', $next))->exists()) {
            $next++;
        }

        return $prefix.sprintf('%03d', $next);
    }

    /**
     * View single ticket details. Object-level authorization: creator,
     * assignee, department member/leader, or global only.
     */
    public function show(Request $request, $id)
    {
        $ticket = Ticket::with([
            'department',
            'unit',
            'creator',
            'approver',
            'assignee',
            'creditor:id,name',
            'attachments',
            'activityLogs'
        ])->where('id', $id)->orWhere('ticket_number', $id)->first();

        if (!$ticket) {
            return response()->json([
                'success' => false,
                'message' => 'التذكرة غير موجودة',
            ], 404);
        }

        if (! $this->canView($request->user(), $ticket)) {
            return $this->forbidden();
        }

        if ($this->isReporterOnly($request->user(), $ticket)) {
            return response()->json([
                'success' => true,
                'data' => app(TicketReporterView::class)->present($ticket, $request->user()),
            ]);
        }

        $data = $ticket->toArray();
        $data['viewer_role'] = 'staff';
        $data['capabilities'] = $this->buildCapabilities($request->user(), $ticket);

        // Internal notes (the column and the INTERNAL_NOTE log rows) are for leadership, global roles
        // and the assignee. A plain department member can open the ticket without reading them.
        return response()->json([
            'success' => true,
            'data' => $this->access->redactInternal($request->user(), $ticket, $data),
        ]);
    }

    /**
     * Admin/Department-leader Action: Approve ticket, assign unit & user, set SLA deadline.
     */
    public function approve(Request $request, $id)
    {
        return $this->lockedTransition($id, fn (Ticket $ticket) => $this->doApprove($request, $ticket));
    }

    private function doApprove(Request $request, Ticket $ticket)
    {
        $user = $request->user();

        if (! $this->canManage($user, (int) $ticket->department_id)) {
            return $this->forbidden('اعتماد التذكرات متاح فقط لقائد الإدارة المعنية.');
        }

        if ($ticket->status !== 'PENDING_APPROVAL') {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن اعتماد تذكرة ليست بحالة (معلقة بانتظار الاعتماد).',
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'unit_id' => 'nullable|exists:departmental_units,id',
            'assigned_to_id' => 'nullable|exists:users,id',
            'sla_hours' => 'required|integer|min:1|max:720', // e.g. 24, 48, 72 hours
            'internal_notes' => 'nullable|string',
        ] + TicketWorkEstimate::rules(), TicketWorkEstimate::messages() + TicketValidationMessages::all());

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                // The headline names the field that failed: SLA, else the estimate, else the approval data in general.
                'message' => $validator->errors()->has('sla_hours')
                    ? 'الرجاء تحديد المهلة الزمنية للـ SLA بشكل صحيح'
                    : ($validator->errors()->first('task_scope') ?: ($validator->errors()->first('task_points') ?: ($validator->errors()->first('estimated_hours') ?: 'بيانات الاعتماد غير صحيحة'))),
                'errors' => $validator->errors(),
            ], 422);
        }

        // The unit must actually belong to the ticket's own department.
        if ($request->filled('unit_id')) {
            $unitBelongs = $this->access->unitBelongsToDepartment((int) $request->unit_id, (int) $ticket->department_id);
            if (! $unitBelongs) {
                return response()->json([
                    'success' => false,
                    'message' => 'الوحدة المحددة لا تتبع إدارة هذه التذكرة.',
                    'errors' => ['unit_id' => ['الوحدة المحددة لا تتبع إدارة هذه التذكرة.']],
                ], 422);
            }
        }

        // The assignee must actually be eligible: an active member of the selected
        // unit (when a unit was chosen) or, absent a unit, of the department itself.
        if ($request->filled('assigned_to_id')) {
            $assignee = User::find($request->assigned_to_id);
            $unitId = $request->filled('unit_id') ? (int) $request->unit_id : null;
            if (! $assignee || ! $this->assigneeEligible($assignee, (int) $ticket->department_id, $unitId)) {
                return response()->json([
                    'success' => false,
                    'message' => $assignee
                        ? $this->access->assigneeRefusal($assignee, $unitId)
                        : 'المستخدم المحدد غير موجود.',
                    'errors' => ['assigned_to_id' => ['المستخدم المحدد غير مؤهل للتكليف بناءً على الوحدة/الإدارة المحددة.']],
                ], 422);
            }
        }

        // Assigning a person turns the ticket into work for them: size and points become mandatory (same
        // rule as reassign). Approving WITHOUT an assignee needs none, but whatever is sent must be valid.
        $estimateInput = TicketWorkEstimate::only($request->only(TicketWorkEstimate::KEYS));
        $estimate = [];
        $estimateBefore = TicketWorkEstimate::snapshot($ticket);
        try {
            if ($request->filled('assigned_to_id') || $request->filled('task_scope') || $request->filled('task_points')) {
                $estimate = TicketWorkEstimate::resolve($ticket, $estimateInput);
            } elseif ($request->filled('estimated_hours')) {
                // No assignee and no size/points: only the approximate duration was given. (null never clears.)
                $estimate = ['estimated_hours' => round((float) $request->estimated_hours, 2)];
            }
        } catch (TicketActionException $e) {
            return response()->json($e->body(), $e->status);
        }

        $prevState = $ticket->status;
        $expectedSlaTime = now()->addHours((int) $request->sla_hours);

        $ticket->update([
            'unit_id' => $request->unit_id,
            'assigned_to_id' => $request->assigned_to_id,
            'approved_by_id' => $user->id,
            'status' => 'ASSIGNED',
            'expected_resolution_time' => $expectedSlaTime,
            'internal_notes' => $request->internal_notes,
        ] + $estimate);

        TicketActivityLog::create([
            'ticket_id' => $ticket->id,
            'action' => 'APPROVED_AND_ASSIGNED',
            'previous_state' => $prevState,
            'new_state' => 'ASSIGNED',
            'performed_by' => $user->id,
            'performed_by_name' => $user->name,
            'details' => sprintf('تم قبول واعتماد التذكرة وتحديد المهلة (SLA) حتى %s', $expectedSlaTime->format('Y-m-d H:i')),
            'logged_at' => now(),
        ]);

        // The estimate gets its own row (visible to managers, global roles and the assignee only); the row above
        // is read by every department member and carries no estimate text.
        if ($estimate !== []) {
            TicketWorkEstimate::log($ticket, $user, $estimateBefore, TicketWorkEstimate::snapshot($ticket));
        }

        return response()->json([
            'success' => true,
            'message' => 'تم قبول واعتماد التذكرة وإحالتها بنجاح',
            'data' => $ticket->fresh(['department', 'unit', 'assignee', 'approver', 'activityLogs']),
        ]);
    }

    /**
     * Admin/Department-leader Action: Reject ticket with MANDATORY reason.
     */
    public function rejectByAdmin(Request $request, $id)
    {
        return $this->lockedTransition($id, fn (Ticket $ticket) => $this->doRejectByAdmin($request, $ticket));
    }

    private function doRejectByAdmin(Request $request, Ticket $ticket)
    {
        $user = $request->user();

        if (! $this->canManage($user, (int) $ticket->department_id)) {
            return $this->forbidden('رفض التذاكر متاح فقط لقائد الإدارة المعنية.');
        }

        if ($ticket->status !== 'PENDING_APPROVAL') {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن رفض تذكرة ليست بحالة (معلقة بانتظار الاعتماد).',
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'admin_rejection_reason' => 'required|string|min:5',
        ], TicketValidationMessages::all());

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'سبب الرفض إجباري قبل الإغلاق',
                'errors' => $validator->errors(),
            ], 422);
        }

        $prevState = $ticket->status;

        $ticket->update([
            'approved_by_id' => $user->id,
            'status' => 'REJECTED_BY_ADMIN',
            'admin_rejection_reason' => $request->admin_rejection_reason,
        ]);

        TicketActivityLog::create([
            'ticket_id' => $ticket->id,
            'action' => 'REJECTED_BY_ADMIN',
            'previous_state' => $prevState,
            'new_state' => 'REJECTED_BY_ADMIN',
            'performed_by' => $user->id,
            'performed_by_name' => $user->name,
            'details' => 'سبب الرفض: ' . $request->admin_rejection_reason,
            'logged_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم رفض التذكرة وتدوين سبب الرفض الإداري بنجاح',
            'data' => $ticket->fresh(['activityLogs']),
        ]);
    }

    /**
     * Assignee Action: Accept task assignment -> Status IN_PROGRESS.
     */
    public function acceptTask(Request $request, $id)
    {
        return $this->lockedTransition($id, fn (Ticket $ticket) => $this->doAcceptTask($request, $ticket));
    }

    private function doAcceptTask(Request $request, Ticket $ticket)
    {
        $user = $request->user();

        if (! $this->isAssignee($user, $ticket)) {
            return $this->forbidden('هذه المهمة غير مُسنَدة إليك.');
        }

        if ($ticket->assigned_to_id === null) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن قبول تذكرة غير مُسنَدة إلى أحد؛ أسندها أولًا.',
            ], 409);
        }

        if ($ticket->status !== 'ASSIGNED') {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن قبول تذكرة ليست بحالة (معتمدة ومحالة).',
            ], 409);
        }

        $prevState = $ticket->status;

        $ticket->update([
            'status' => 'IN_PROGRESS',
            'accepted_at' => now(),
        ]);

        TicketActivityLog::create([
            'ticket_id' => $ticket->id,
            'action' => 'ACCEPTED_BY_ASSIGNEE',
            'previous_state' => $prevState,
            'new_state' => 'IN_PROGRESS',
            'performed_by' => $user->id,
            'performed_by_name' => $user->name,
            'details' => 'تم قبول استلام المهمة وبدء عداد التنفيذ بالـ SLA',
            'logged_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم قبول استلام المهمة وبدء العمل عليها',
            'data' => $ticket->fresh(['activityLogs']),
        ]);
    }

    /**
     * Assignee Action: Reject task assignment with MANDATORY reason.
     */
    public function rejectTask(Request $request, $id)
    {
        return $this->lockedTransition($id, fn (Ticket $ticket) => $this->doRejectTask($request, $ticket));
    }

    private function doRejectTask(Request $request, Ticket $ticket)
    {
        $user = $request->user();

        if (! $this->isAssignee($user, $ticket)) {
            return $this->forbidden('هذه المهمة غير مُسنَدة إليك.');
        }

        if ($ticket->assigned_to_id === null) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن الاعتذار عن تذكرة غير مُسنَدة إلى أحد؛ أسندها أولًا.',
            ], 409);
        }

        if ($ticket->status !== 'ASSIGNED') {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن الاعتذار عن تذكرة ليست بحالة (معتمدة ومحالة).',
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'assignee_rejection_reason' => 'required|string|min:5|max:1000',
        ], TicketValidationMessages::all());

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'سبب الرفض إجباري عند الاعتذار عن المهمة',
                'errors' => $validator->errors(),
            ], 422);
        }

        $prevState = $ticket->status;

        $ticket->update([
            'status' => 'REJECTED_BY_ASSIGNEE',
            'assignee_rejection_reason' => $request->assignee_rejection_reason,
        ]);

        TicketActivityLog::create([
            'ticket_id' => $ticket->id,
            'action' => 'REJECTED_BY_ASSIGNEE',
            'previous_state' => $prevState,
            'new_state' => 'REJECTED_BY_ASSIGNEE',
            'performed_by' => $user->id,
            'performed_by_name' => $user->name,
            'details' => 'مبررات الاعتذار: ' . $request->assignee_rejection_reason,
            'logged_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل اعتذار الاستلام وتصعيد التذكرة للإدارة للتوجيه البديل',
            'data' => $ticket->fresh(['activityLogs']),
        ]);
    }

    /**
     * Assignee Action: Complete Task with strict validation rules.
     * - RESOLVED: REQUIRES resolution_summary + optional proof files
     * - UNRESOLVED: REQUIRES unresolved_reason
     */
    public function completeTask(Request $request, $id)
    {
        return $this->lockedTransition($id, fn (Ticket $ticket) => $this->doCompleteTask($request, $ticket));
    }

    private function doCompleteTask(Request $request, Ticket $ticket)
    {
        $user = $request->user();

        if (! $this->isAssignee($user, $ticket)) {
            return $this->forbidden('هذه المهمة غير مُسنَدة إليك.');
        }

        if ($ticket->status !== 'IN_PROGRESS') {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن إغلاق تذكرة ليست بحالة (قيد المعالجة).',
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'resolution_type' => 'required|in:RESOLVED,UNRESOLVED',
            'resolution_summary' => 'required_if:resolution_type,RESOLVED|nullable|string|min:5',
            'unresolved_reason' => 'required_if:resolution_type,UNRESOLVED|nullable|string|min:5',
            'proof_files.*' => 'nullable|file|mimes:jpg,jpeg,png,webp,mp4,mov,pdf|max:51200',
        ], TicketValidationMessages::all());

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'الرجاء استكمال البيانات والتبريرات الإجبارية',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $prevState = $ticket->status;

            // «تم الحل» is only the assignee SUBMITTING the work: a ticket that has someone to credit goes to
            // PENDING_REVIEW and becomes RESOLVED (and credits) only at the final approval (TicketCreditService).
            // A legacy IN_PROGRESS ticket with no assignee has nobody to credit and closes directly, as before.
            $submitForReview = $request->resolution_type === 'RESOLVED' && $ticket->assigned_to_id !== null;
            $newStatus = $submitForReview ? TicketAccess::PENDING_REVIEW : $request->resolution_type;

            $updateData = [
                'status' => $newStatus,
            ];

            if ($request->resolution_type === 'RESOLVED') {
                if ($submitForReview) {
                    // Who submitted this review round (a global role may do it for the assignee): they cannot review it.
                    $updateData['submitted_by_id'] = $user->id;
                    $updateData['submitted_at'] = now();
                } else {
                    $updateData['resolved_at'] = now();
                }
                $updateData['resolution_summary'] = $request->resolution_summary;
            } else {
                $updateData['unresolved_reason'] = $request->unresolved_reason;
            }

            if ($request->filled('internal_notes')) {
                $updateData['internal_notes'] = $request->internal_notes;
            }

            $ticket->forceFill($updateData)->save(); // forceFill: submitted_by_id is not mass assignable

            // Handle resolution proof attachments
            if ($request->hasFile('proof_files')) {
                foreach ($request->file('proof_files') as $file) {
                    $mime = $file->getMimeType();
                    $fileType = 'DOCUMENT';
                    if (str_contains($mime, 'image')) {
                        $fileType = 'IMAGE';
                    } elseif (str_contains($mime, 'video')) {
                        $fileType = 'VIDEO';
                    }

                    $path = $file->store('tickets/proofs', 'private');

                    TicketAttachment::create([
                        'ticket_id' => $ticket->id,
                        'file_url' => $path,
                        'file_name' => $file->getClientOriginalName(),
                        'file_type' => $fileType,
                        'attachment_context' => 'RESOLUTION_PROOF',
                        'uploaded_by' => $user->id,
                    ]);
                }
            }

            // Log activity
            TicketActivityLog::create([
                'ticket_id' => $ticket->id,
                'action' => $submitForReview ? 'SUBMITTED_FOR_REVIEW' : ($newStatus === 'RESOLVED' ? 'RESOLVED' : 'MARKED_UNRESOLVED'),
                'previous_state' => $prevState,
                'new_state' => $newStatus,
                'performed_by' => $user->id,
                'performed_by_name' => $user->name,
                'details' => $submitForReview
                    ? 'أنهى المكلف التنفيذ وأرسل التذكرة للمراجعة والاعتماد النهائي'
                    : ($newStatus === 'RESOLVED'
                        ? 'تم حل المشكلة وتدوين الإجراءات المتخذة'
                        : 'تعذر الحل: ' . $request->unresolved_reason),
                'logged_at' => now(),
            ]);

            DB::commit();

            $fresh = $ticket->fresh(['department', 'unit', 'attachments', 'activityLogs']);

            return response()->json([
                'success' => true,
                'message' => $submitForReview
                    ? 'تم إرسال التذكرة للمراجعة والاعتماد النهائي؛ تُضاف النقاط والساعات بعد الاعتماد.'
                    : ($newStatus === 'RESOLVED' ? 'تم إنهاء التذكرة كـ (تم الحل) بنجاح' : 'تم تدوين حالة تعذر الحل وتصعيد التقرير للقيادة'),
                'data' => $this->actionPayload($fresh, $user),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء حفظ حالة الإغلاق',
            ], 500);
        }
    }

    /**
     * Add a comment / follow-up note to a ticket. Requires view access.
     */
    public function addComment(Request $request, $id)
    {
        $ticket = Ticket::where('id', $id)->orWhere('ticket_number', $id)->firstOrFail();
        $user = $request->user();

        if (! $this->canView($user, $ticket)) {
            return $this->forbidden();
        }

        $request->validate([
            'comment'      => 'required|string|max:2000',
            'is_internal'  => 'nullable|boolean',
        ], TicketValidationMessages::all());

        // A submitter can follow up while the ticket is open; once it is closed
        // out the page is read-only for them.
        if ($this->isReporterOnly($user, $ticket)
            && in_array($ticket->status, TicketReporterView::FINAL_STATUSES, true)) {
            return $this->forbidden('هذه التذكرة مغلقة ولا تقبل تعقيبات جديدة.');
        }

        // Internal notes are for department leadership / privileged staff only.
        if ($request->boolean('is_internal') && ! $this->canManage($user, (int) $ticket->department_id)) {
            return $this->forbidden('الملاحظات الداخلية متاحة فقط لقائد الإدارة المعنية.');
        }

        TicketActivityLog::create([
            'ticket_id'         => $ticket->id,
            'action'            => $request->boolean('is_internal') ? 'INTERNAL_NOTE' : 'COMMENT',
            'previous_state'    => $ticket->status,
            'new_state'         => $ticket->status,
            'performed_by'      => $user->id,
            'performed_by_name' => $user->name,
            'details'           => $request->comment,
            'logged_at'         => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم إضافة التعقيب بنجاح',
        ]);
    }

    /**
     * Reassign ticket to a different assignee (department leader / privileged only). The rules and
     * the locked, re-checked update live in TicketActions, the same code the bulk endpoint uses.
     */
    public function reassign(Request $request, $id, TicketActions $actions)
    {
        $ticket = Ticket::where('id', $id)->orWhere('ticket_number', $id)->firstOrFail();
        $user = $request->user();

        if (! $this->canManage($user, (int) $ticket->department_id)) {
            return $this->forbidden('إعادة التوجيه متاحة فقط لقائد الإدارة المعنية.');
        }

        if (! in_array($ticket->status, TicketAccess::REASSIGNABLE_STATUSES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن إعادة توجيه تذكرة بحالتها الراهنة.',
            ], 409);
        }

        $request->validate([
            'assigned_to_id' => 'required|exists:users,id',
            'unit_id'        => 'nullable|exists:departmental_units,id',
            'reason'         => 'nullable|string|max:500',
        ] + TicketWorkEstimate::rules(), TicketWorkEstimate::messages() + TicketValidationMessages::all());

        try {
            $updated = $actions->reassign(
                (int) $ticket->id,
                $user,
                (int) $request->assigned_to_id,
                $request->filled('unit_id') ? (int) $request->unit_id : null,
                $request->input('reason'),
                TicketWorkEstimate::only($request->only(TicketWorkEstimate::KEYS)),
            );
        } catch (TicketActionException $e) {
            return response()->json($e->body(), $e->status);
        }

        return response()->json([
            'success' => true,
            'message' => 'تمت إعادة توجيه التذكرة وتغيير المكلف بنجاح',
            'data'    => $updated->fresh(['department', 'unit', 'assignee']),
        ]);
    }

    /**
     * Stream a ticket attachment inline — inherits authorization from the
     * parent ticket, and serves from private storage (never a public URL),
     * so unauthorized users get 403/404 instead of a raw file path.
     */
    public function downloadAttachment(Request $request, $ticketId, $attachmentId)
    {
        $ticket = Ticket::findOrFail($ticketId);

        if (! $this->canView($request->user(), $ticket)) {
            return $this->forbidden();
        }

        $attachment = TicketAttachment::where('id', $attachmentId)
            ->where('ticket_id', $ticket->id)
            ->first();

        if (! $attachment) {
            return response()->json(['success' => false, 'message' => 'المرفق غير موجود'], 404);
        }

        // A reporter-only viewer sees what they uploaded, not the assignee's
        // resolution proof files. 404 (not 403) so the id reveals nothing.
        if ($attachment->attachment_context !== 'INITIAL_SUBMISSION'
            && $this->isReporterOnly($request->user(), $ticket)) {
            return response()->json(['success' => false, 'message' => 'المرفق غير موجود'], 404);
        }

        // file_url is server-generated, but never let a corrupted or tampered
        // row walk outside the private disk.
        $path = (string) $attachment->file_url;
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/') || str_contains($path, "\0")) {
            return response()->json(['success' => false, 'message' => 'الملف غير موجود على القرص'], 404);
        }

        $disk = Storage::disk('private');
        if (! $disk->exists($attachment->file_url)) {
            return response()->json(['success' => false, 'message' => 'الملف غير موجود على القرص'], 404);
        }

        $safeName = preg_replace('/[^a-zA-Z0-9._\- ]/', '_', $attachment->file_name ?? 'attachment') ?: 'attachment';

        // MIME comes from the bytes on disk, never from the client filename.
        $mime = $disk->mimeType($attachment->file_url) ?: 'application/octet-stream';

        // Only raster images and PDFs may render inline. Anything else (legacy
        // rows, SVG, HTML) is forced to download so it can never execute in
        // the app's origin.
        $inline = $mime === 'application/pdf'
            || (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml');

        return response($disk->get($attachment->file_url), 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $safeName . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
