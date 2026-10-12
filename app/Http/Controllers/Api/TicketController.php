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
use App\Services\TicketReporterView;
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
    public function __construct(private readonly DepartmentAccessService $departmentAccess)
    {
    }

    /**
     * Can this user read the ticket (creator, assignee, department member/leader, or global)?
     */
    private function canView(User $user, Ticket $ticket): bool
    {
        if ($this->departmentAccess->isGlobal($user)) {
            return true;
        }

        return $ticket->created_by_id === $user->id
            || $ticket->assigned_to_id === $user->id
            || $this->departmentAccess->canAccessDepartment($user, (int) $ticket->department_id);
    }

    /**
     * Can this user perform department-management actions (approve, admin-reject,
     * reassign) on this ticket's department? Leadership or global only — never
     * mere membership, and never a role-name guess.
     */
    private function canManage(User $user, int $departmentId): bool
    {
        return $this->departmentAccess->canManageDepartment($user, $departmentId);
    }

    /**
     * Can this user act as the assignee (accept/reject/complete the task)?
     */
    private function isAssignee(User $user, Ticket $ticket): bool
    {
        if ($this->departmentAccess->isGlobal($user)) {
            return true;
        }

        return $ticket->assigned_to_id !== null && $ticket->assigned_to_id === $user->id;
    }

    /**
     * Is this viewer ONLY the person who submitted the ticket - not global, not a
     * manager of its department and not its assignee? Those viewers get the
     * read-only, public-safe projection (TicketReporterView) instead of the
     * operational payload. Anyone with a staff role over the ticket keeps the
     * full payload, even when they also happen to be the submitter.
     */
    private function isReporterOnly(User $user, Ticket $ticket): bool
    {
        return $ticket->created_by_id !== null
            && $ticket->created_by_id === $user->id
            && ! $this->departmentAccess->isGlobal($user)
            && ! $this->canManage($user, (int) $ticket->department_id)
            && $ticket->assigned_to_id !== $user->id;
    }

    /**
     * Is $assignee eligible to be assigned a ticket in $departmentId, optionally
     * scoped to a specific technical unit? When $unitId is given, membership must
     * be exact — an active team_members row for that user in that unit AND
     * department (never just "somewhere in the department"), closing the ID-
     * manipulation path where a caller sends a valid unit alongside a user who
     * belongs to a *different* unit. Global roles bypass both checks, same as
     * every other authorization path in this controller.
     */
    private function assigneeEligible(User $assignee, int $departmentId, ?int $unitId): bool
    {
        if ($this->departmentAccess->isGlobal($assignee)) {
            return true;
        }

        if ($unitId) {
            return \App\Models\TeamMember::where('user_id', $assignee->id)
                ->where('unit_id', $unitId)
                ->where('department_id', $departmentId)
                ->where('status', 'active')
                ->exists();
        }

        return $this->departmentAccess->canAccessDepartment($assignee, $departmentId);
    }

    /**
     * The canonical per-ticket, per-user capability set.
     *
     * ONE authoritative place, reusing the EXACT SAME authorization helpers
     * every mutating endpoint already guards itself with (canManage/
     * isAssignee) and the EXACT SAME status guards each endpoint already
     * enforces server-side. This does not decide authorization - it REPORTS
     * the decision those endpoints would already make, so the frontend can
     * show only the actions that would actually succeed instead of drifting
     * out of sync with the real rules (as the Admin table's "reassign"
     * button had: it showed for REJECTED_BY_ASSIGNEE, a status reassign()
     * has never accepted).
     *
     * `assign` and `reassign` intentionally share one flag: there is no
     * separate "assign" endpoint. approve() optionally sets the first
     * assignee; reassign() is the ONLY endpoint that sets one afterwards,
     * for both "assign for the first time post-approval" and "true
     * reassignment" - it guards on status only, never on whether an
     * assignee already exists. The frontend derives its OWN button label
     * ("تعيين" vs "إعادة التوجيه") from whether ticket.assignee is null;
     * that is presentation, not a second authorization decision.
     *
     * `comment` is always true here because building capabilities at all
     * implies canView() already passed for this ticket.
     *
     * @return array<string, bool>
     */
    private function buildCapabilities(User $user, Ticket $ticket): array
    {
        $canManage = $this->canManage($user, (int) $ticket->department_id);
        $isAssignee = $this->isAssignee($user, $ticket);

        return [
            'approve' => $canManage && $ticket->status === 'PENDING_APPROVAL',
            'reject' => $canManage && $ticket->status === 'PENDING_APPROVAL',
            'reassign' => $canManage && in_array($ticket->status, ['ASSIGNED', 'IN_PROGRESS'], true),
            'accept_task' => $isAssignee && $ticket->status === 'ASSIGNED',
            'reject_task' => $isAssignee && $ticket->status === 'ASSIGNED',
            'complete_task' => $isAssignee && $ticket->status === 'IN_PROGRESS',
            'add_internal_note' => $canManage,
            'comment' => true,
        ];
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

        // Assignable users: only relevant when building the approve/reassign
        // UI, so gated the same way approve()/reassign() themselves are —
        // canManageDepartment (department leader or global), not isGlobal
        // alone. A plain department leader is fully authorized to approve a
        // ticket and pick its assignee; they must see the same candidate
        // list the endpoint they're about to call will actually accept.
        // Least privilege still holds — no email, no full user table.
        $assignableUsers = [];
        if ($departmentId && $this->departmentAccess->canManageDepartment($user, $departmentId)) {
            // A unit was chosen: eligibility is exact unit membership, nothing
            // broader — this is what stops "unit A selected, user from unit B
            // sent anyway" from ever appearing as an option in the first place.
            if ($unitId) {
                $memberIds = \App\Models\TeamMember::where('department_id', $departmentId)
                    ->where('unit_id', $unitId)
                    ->where('status', 'active')
                    ->pluck('user_id');
            } else {
                $memberIds = collect();
                $dept = Department::find($departmentId);
                if ($dept) {
                    if ($dept->leader_id) {
                        $memberIds->push($dept->leader_id);
                    }
                    $memberIds = $memberIds->merge(
                        \App\Models\TeamMember::where('department_id', $departmentId)
                            ->where('status', 'active')
                            ->pluck('user_id')
                    );
                }
                // Tech Support staff can always be assigned (they run the ticket
                // desk) when no specific unit was chosen — unchanged from before.
                $techDepartment = Department::where('slug', 'technology')
                    ->orWhere('name_ar', 'LIKE', '%التقنية%')
                    ->first();
                if ($techDepartment) {
                    $memberIds = $memberIds->merge(
                        \App\Models\TeamMember::where('department_id', $techDepartment->id)
                            ->where('status', 'active')
                            ->pluck('user_id')
                    );
                }
            }

            $assignableUsers = User::query()
                ->select('id', 'name', 'role')
                ->where('is_active', true)
                ->whereIn('id', $memberIds->unique()->values())
                ->limit(200)
                ->get();
        }

        return response()->json([
            'success' => true,
            'departments' => $departments,
            'tech_units' => $techUnits,
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
                'RESOLVED' => ['label_ar' => 'تم الحل والإغلاق', 'color' => 'green'],
                'UNRESOLVED' => ['label_ar' => 'تعذر الحل', 'color' => 'orange'],
                'REJECTED_BY_ADMIN' => ['label_ar' => 'مرفوضة من الإدارة', 'color' => 'red'],
                'REJECTED_BY_ASSIGNEE' => ['label_ar' => 'مرفوضة من المكلف', 'color' => 'rose'],
            ]
        ]);
    }

    /**
     * Filterable tickets list — scoped to what the user may actually see:
     * global roles see everything, everyone else sees tickets in their
     * accessible departments plus anything they created or are assigned to.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Ticket::with(['department', 'unit', 'creator', 'approver', 'assignee', 'attachments']);

        if (! $this->departmentAccess->isGlobal($user)) {
            $accessibleDeptIds = $this->departmentAccess->getAccessibleDepartmentIds($user);
            $query->where(function ($q) use ($accessibleDeptIds, $user) {
                $q->whereIn('department_id', $accessibleDeptIds)
                  ->orWhere('created_by_id', $user->id)
                  ->orWhere('assigned_to_id', $user->id);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        if ($request->filled('unit_id')) {
            $query->where('unit_id', $request->unit_id);
        }

        if ($request->filled('ticket_category')) {
            $query->where('ticket_category', $request->ticket_category);
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        if ($request->filled('assigned_to_id')) {
            $query->where('assigned_to_id', $request->assigned_to_id);
        }

        if ($request->filled('created_by_id')) {
            $query->where('created_by_id', $request->created_by_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('ticket_number', 'LIKE', "%{$search}%")
                  ->orWhere('title', 'LIKE', "%{$search}%")
                  ->orWhere('description', 'LIKE', "%{$search}%")
                  ->orWhere('created_by_name', 'LIKE', "%{$search}%");
            });
        }

        if ($request->boolean('delayed_only')) {
            $query->whereNotIn('status', ['RESOLVED', 'REJECTED_BY_ADMIN', 'UNRESOLVED'])
                  ->whereNotNull('expected_resolution_time')
                  ->where('expected_resolution_time', '<', now());
        }

        $tickets = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 20));

        // Same capability computation as show() - one canonical source, so the
        // Admin table and Ticket Detail can never drift on which actions a
        // given ticket/user pair actually permits.
        $tickets->getCollection()->transform(function (Ticket $ticket) use ($user) {
            $data = $ticket->toArray();
            $data['capabilities'] = $this->buildCapabilities($user, $ticket);

            return $data;
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
        ]);

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
                'data' => $ticket->load(['department', 'attachments', 'activityLogs']),
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

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Admin/Department-leader Action: Approve ticket, assign unit & user, set SLA deadline.
     */
    public function approve(Request $request, $id)
    {
        $ticket = Ticket::findOrFail($id);
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
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'الرجاء تحديد المهلة الزمنية للـ SLA بشكل صحيح',
                'errors' => $validator->errors(),
            ], 422);
        }

        // The unit must actually belong to the ticket's own department.
        if ($request->filled('unit_id')) {
            $unitBelongs = DepartmentalUnit::where('id', $request->unit_id)
                ->where('department_id', $ticket->department_id)
                ->exists();
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
                    'message' => $unitId
                        ? 'المستخدم المحدد ليس عضواً فعالاً في الوحدة التقنية المختارة.'
                        : 'المستخدم المحدد غير مؤهل لإسناد تذاكر هذه الإدارة إليه.',
                    'errors' => ['assigned_to_id' => ['المستخدم المحدد غير مؤهل للتكليف بناءً على الوحدة/الإدارة المحددة.']],
                ], 422);
            }
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
        ]);

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
        $ticket = Ticket::findOrFail($id);
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
        ], [
            'admin_rejection_reason.required' => 'حقل سبب الرفض إجباري عند رفض التذكرة من قبل الإدارة',
            'admin_rejection_reason.min' => 'يجب كتابة سبب الرفض بتفصيل لا يقل عن 5 حروف',
        ]);

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
        $ticket = Ticket::findOrFail($id);
        $user = $request->user();

        if (! $this->isAssignee($user, $ticket)) {
            return $this->forbidden('هذه المهمة غير مُسنَدة إليك.');
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
        $ticket = Ticket::findOrFail($id);
        $user = $request->user();

        if (! $this->isAssignee($user, $ticket)) {
            return $this->forbidden('هذه المهمة غير مُسنَدة إليك.');
        }

        if ($ticket->status !== 'ASSIGNED') {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن الاعتذار عن تذكرة ليست بحالة (معتمدة ومحالة).',
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'assignee_rejection_reason' => 'required|string|min:5',
        ], [
            'assignee_rejection_reason.required' => 'يلزم تدوين مبررات اعتذار/رفض استلام المهمة',
        ]);

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
        $ticket = Ticket::findOrFail($id);
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
        ], [
            'resolution_summary.required_if' => 'حقل (ماذا تم لحل المشكلة) إجباري عند اختيار "تم الحل"',
            'unresolved_reason.required_if' => 'حقل (أسباب عدم التمكن من الحل والعوائق) إجباري عند اختيار "تعذر الحل"',
        ]);

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
            $newStatus = $request->resolution_type;

            $updateData = [
                'status' => $newStatus,
            ];

            if ($newStatus === 'RESOLVED') {
                $updateData['resolved_at'] = now();
                $updateData['resolution_summary'] = $request->resolution_summary;
            } else {
                $updateData['unresolved_reason'] = $request->unresolved_reason;
            }

            if ($request->filled('internal_notes')) {
                $updateData['internal_notes'] = $request->internal_notes;
            }

            $ticket->update($updateData);

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
                'action' => $newStatus === 'RESOLVED' ? 'RESOLVED' : 'MARKED_UNRESOLVED',
                'previous_state' => $prevState,
                'new_state' => $newStatus,
                'performed_by' => $user->id,
                'performed_by_name' => $user->name,
                'details' => $newStatus === 'RESOLVED'
                    ? 'تم حل المشكلة وتدوين الإجراءات المتخذة'
                    : 'تعذر الحل: ' . $request->unresolved_reason,
                'logged_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $newStatus === 'RESOLVED' ? 'تم إنهاء التذكرة كـ (تم الحل) بنجاح' : 'تم تدوين حالة تعذر الحل وتصعيد التقرير للقيادة',
                'data' => $ticket->fresh(['department', 'unit', 'attachments', 'activityLogs']),
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
        ]);

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
     * Reassign ticket to a different assignee (department leader / privileged only).
     */
    public function reassign(Request $request, $id)
    {
        $ticket = Ticket::where('id', $id)->orWhere('ticket_number', $id)->firstOrFail();
        $user = $request->user();

        if (! $this->canManage($user, (int) $ticket->department_id)) {
            return $this->forbidden('إعادة التوجيه متاحة فقط لقائد الإدارة المعنية.');
        }

        if (! in_array($ticket->status, ['ASSIGNED', 'IN_PROGRESS'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن إعادة توجيه تذكرة بحالتها الراهنة.',
            ], 409);
        }

        $request->validate([
            'assigned_to_id' => 'required|exists:users,id',
            'unit_id'        => 'nullable|exists:departmental_units,id',
            'reason'         => 'nullable|string|max:500',
        ]);

        if ($request->filled('unit_id')) {
            $unitBelongs = DepartmentalUnit::where('id', $request->unit_id)
                ->where('department_id', $ticket->department_id)
                ->exists();
            if (! $unitBelongs) {
                return response()->json([
                    'success' => false,
                    'message' => 'الوحدة المحددة لا تتبع إدارة هذه التذكرة.',
                    'errors' => ['unit_id' => ['الوحدة المحددة لا تتبع إدارة هذه التذكرة.']],
                ], 422);
            }
        }

        // Effective unit for eligibility purposes: the one being set now, else the
        // ticket's existing unit (a reassign that doesn't touch unit_id must still
        // enforce membership in whatever unit the ticket already carries).
        $effectiveUnitId = $request->filled('unit_id') ? (int) $request->unit_id : $ticket->unit_id;

        $assignee = User::find($request->assigned_to_id);
        if (! $this->assigneeEligible($assignee, (int) $ticket->department_id, $effectiveUnitId)) {
            return response()->json([
                'success' => false,
                'message' => $effectiveUnitId
                    ? 'المستخدم المحدد ليس عضواً فعالاً في الوحدة التقنية المختارة.'
                    : 'المستخدم المحدد غير مؤهل لإسناد تذاكر هذه الإدارة إليه.',
                'errors' => ['assigned_to_id' => ['المستخدم المحدد غير مؤهل للتكليف بناءً على الوحدة/الإدارة المحددة.']],
            ], 422);
        }

        $prevState = $ticket->status;

        $ticket->update([
            'assigned_to_id' => $request->assigned_to_id,
            'unit_id'        => $request->unit_id ?? $ticket->unit_id,
            'status'         => 'ASSIGNED',
            'accepted_at'    => null,
        ]);

        TicketActivityLog::create([
            'ticket_id'         => $ticket->id,
            'action'            => 'REASSIGNED',
            'previous_state'    => $prevState,
            'new_state'         => 'ASSIGNED',
            'performed_by'      => $user->id,
            'performed_by_name' => $user->name,
            'details'           => 'تمت إعادة التوجيه وتغيير المكلف' . ($request->reason ? ': ' . $request->reason : ''),
            'logged_at'         => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تمت إعادة توجيه التذكرة وتغيير المكلف بنجاح',
            'data'    => $ticket->fresh(['department', 'unit', 'assignee']),
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
