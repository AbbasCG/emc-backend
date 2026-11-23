<?php

namespace App\Services;

use App\Models\Department;
use App\Models\DepartmentalUnit;
use App\Models\Task;
use App\Models\TeamMember;
use App\Models\Ticket;
use App\Models\User;
use App\Models\VolunteerProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The ONE place that answers "who may do what with a ticket".
 *
 * Everything here delegates to DepartmentAccessService (department leadership, membership, the
 * global roles); nothing is decided from a role name in this class. TicketController, the
 * operations endpoints (stats, board, bulk, insights...) and the capability report all use it,
 * so a rule can never differ between the single-ticket page and the work queue.
 *
 * Page access (may this user OPEN the admin page) is a separate layer and is not decided here.
 */
class TicketAccess
{
    /** Statuses after which a ticket is finished. PENDING_REVIEW is NOT one of them: the work is submitted but not yet finally approved. */
    public const CLOSED_STATUSES = ['RESOLVED', 'REJECTED_BY_ADMIN', 'UNRESOLVED'];

    /** The assignee finished and submitted the work; it waits for the final approval (which is what credits). */
    public const PENDING_REVIEW = 'PENDING_REVIEW';

    /**
     * The deployment cycle (only tickets with requires_deployment): the work is approved for deployment, put on the live
     * site, and only the assignee's verification THERE closes (and credits) it.
     */
    public const APPROVED_FOR_DEPLOY = 'APPROVED_FOR_DEPLOY';
    public const PENDING_VERIFICATION = 'PENDING_VERIFICATION';

    /** The internal review steps after the assignee's submission: open, held by someone else, and folded into IN_PROGRESS for a reporter. */
    public const REVIEW_STAGES = ['PENDING_REVIEW', 'APPROVED_FOR_DEPLOY', 'PENDING_VERIFICATION'];

    /** The only statuses in which the estimate (size, points, hours) and the deployment flag can be edited by a manager. */
    public const ESTIMATE_EDITABLE_STATUSES = ['PENDING_APPROVAL', 'ASSIGNED', 'IN_PROGRESS', 'REJECTED_BY_ASSIGNEE'];

    /**
     * Roles that give the FINAL approval of work larger than «بسيطة». The very list TaskController::hrApprove uses:
     * regular tasks above the simple size need HR's final approval, so tickets do too.
     */
    public const HR_APPROVAL_ROLES = ['admin', 'super_admin', 'hr_manager', 'executive_admin'];

    /** Log rows only managers, global roles and the assignee may read. */
    public const INTERNAL_LOG_ACTIONS = ['INTERNAL_NOTE', 'ESTIMATE_UPDATED', 'CREDIT_ADDED', 'RETURNED_FOR_REWORK', 'APPROVED_FOR_DEPLOY', 'DEPLOYED_LIVE', 'DEPLOY_DETAILS', 'VERIFIED_RESOLVED', 'VERIFICATION_FAILED', 'DEPLOYMENT_FLAG_CHANGED'];

    /**
     * Statuses a manager may hand to (another) person: held by someone (ASSIGNED, IN_PROGRESS) or declined
     * by its assignee and waiting for a manager to route it again (REJECTED_BY_ASSIGNEE).
     */
    public const REASSIGNABLE_STATUSES = ['ASSIGNED', 'IN_PROGRESS', 'REJECTED_BY_ASSIGNEE'];

    /** @var array<int, array<int, int>> department ids led by a user id, looked up once per request */
    private array $led = [];

    public function __construct(private readonly DepartmentAccessService $departments) {}

    /** @return array<int, int> */
    private function ledBy(User $user): array
    {
        return $this->led[$user->id] ??= $this->departments->getLedDepartmentIds($user)->map(fn ($id) => (int) $id)->all();
    }

    public function isGlobal(User $user): bool
    {
        return $this->departments->isGlobal($user);
    }

    /** Creator, assignee, member/leader of the ticket's department, or global. */
    public function canView(User $user, Ticket $ticket): bool
    {
        if ($this->departments->isGlobal($user)) {
            return true;
        }

        return $ticket->created_by_id === $user->id
            || $ticket->assigned_to_id === $user->id
            || $this->departments->canAccessDepartment($user, (int) $ticket->department_id);
    }

    /** Department leadership (or global): approve, reject, reassign, internal notes, priority. */
    public function canManage(User $user, int $departmentId): bool
    {
        // Same rule as DepartmentAccessService::canManageDepartment (global, or the department's leader),
        // with the leadership lookup remembered so a page of rows does not query it once per row.
        return $this->departments->isGlobal($user) || in_array($departmentId, $this->ledBy($user), true);
    }

    /** The actual assignee (or global) may accept / reject / complete the task. */
    public function isAssignee(User $user, Ticket $ticket): bool
    {
        if ($this->departments->isGlobal($user)) {
            return true;
        }

        return $ticket->assigned_to_id !== null && $ticket->assigned_to_id === $user->id;
    }

    /** The ticket's actual assignee (a global role is NOT counted here, unlike isAssignee()). */
    public function isTicketAssignee(User $user, Ticket $ticket): bool
    {
        return $ticket->assigned_to_id !== null && (int) $ticket->assigned_to_id === (int) $user->id;
    }

    /** True when the viewer is ONLY the submitter: no management, assignment or global role over the ticket. */
    public function isReporterOnly(User $user, Ticket $ticket): bool
    {
        return $ticket->created_by_id !== null
            && $ticket->created_by_id === $user->id
            && ! $this->departments->isGlobal($user)
            && ! $this->canManage($user, (int) $ticket->department_id)
            && $ticket->assigned_to_id !== $user->id;
    }

    /**
     * May this viewer read internal notes (the `internal_notes` column and INTERNAL_NOTE log rows)?
     *
     * Leadership and global roles write them; the assignee receives them as working instructions.
     * A plain department MEMBER may open the ticket but is not part of its internal conversation.
     */
    public function canSeeInternal(User $user, Ticket $ticket): bool
    {
        return $this->departments->isGlobal($user)
            || $this->canManage($user, (int) $ticket->department_id)
            || ($ticket->assigned_to_id !== null && $ticket->assigned_to_id === $user->id);
    }

    // ── Who can be handed a ticket: ONE rule, used by the lists AND the validators ───────────────────────

    /** Does the department have at least one ACTIVE technical unit? A department without any never needs a unit. */
    public function departmentHasUnits(int $departmentId): bool
    {
        return DepartmentalUnit::where('department_id', $departmentId)->where('is_active', true)->exists();
    }

    /** Does this unit belong to this department (active or not)? The check every endpoint makes for a unit it was sent. */
    public function unitBelongsToDepartment(int $unitId, int $departmentId): bool
    {
        return DepartmentalUnit::where('id', $unitId)->where('department_id', $departmentId)->exists();
    }

    /**
     * The unit an assignment is judged against: the one sent with the request, else the unit the ticket already
     * carries, but only while that unit is still an ACTIVE unit of the ticket's own department. A ticket that
     * carries a unit its department has since deactivated (or lost) is judged by the department alone, so a
     * department that no longer has technical units is never blocked by a stale unit id.
     */
    public function effectiveUnitId(Ticket $ticket, ?int $explicitUnitId): ?int
    {
        if ($explicitUnitId !== null) {
            return $explicitUnitId;
        }

        if (! $ticket->unit_id) {
            return null;
        }

        $live = DepartmentalUnit::where('id', $ticket->unit_id)
            ->where('department_id', $ticket->department_id)
            ->where('is_active', true)
            ->exists();

        return $live ? (int) $ticket->unit_id : null;
    }

    /**
     * THE eligibility rule, as a query over users. Everything that lists assignable people (the form metadata,
     * the suggestions) and everything that accepts one (approve, reassign, bulk reassign, the audit) is built on
     * this single query, so a listed person is never refused and a refused person is never listed.
     *
     *  - With a unit: an ACTIVE team_members row in that unit AND department. Nothing broader.
     *  - Without a unit: the department's leader, an active team member of the department, or a member of it in
     *    user_departments. (No standing exception for another department's staff, and no global-role shortcut: a
     *    global role is assignable only when it actually belongs to the department.)
     *  - Always: an active account, and not a volunteer TaskWorkflowService::canAssignTaskToUser blocks
     *    (inactive / on leave / withdrawn), the same guard regular tasks apply.
     *
     * Known per-ticket difference (by design, reported per ticket): when a ticket ALREADY carries a live unit, reassign
     * judges the assignee by that unit's members (effectiveUnitId), while `meta()` without a `unit_id` lists the whole
     * department. A bulk reassign of unit-bearing tickets can therefore refuse some listed users for some tickets; the
     * bulk result already states each refusal per ticket. Listing with the ticket's `unit_id` gives the exact set.
     *
     * @return Builder<User>
     */
    public function eligibleAssigneesQuery(int $departmentId, ?int $unitId): Builder
    {
        $query = User::query()->where('users.is_active', true);

        if ($unitId) {
            $query->whereIn('users.id', TeamMember::query()->select('user_id')
                ->where('unit_id', $unitId)->where('department_id', $departmentId)->where('status', 'active'));
        } else {
            $query->where(function ($q) use ($departmentId) {
                $q->whereIn('users.id', Department::query()->select('leader_id')->where('id', $departmentId)->whereNotNull('leader_id'))
                    ->orWhereIn('users.id', TeamMember::query()->select('user_id')->where('department_id', $departmentId)->where('status', 'active'))
                    ->orWhereIn('users.id', DB::table('user_departments')->select('user_id')->where('department_id', $departmentId));
            });
        }

        // Same statuses as TaskWorkflowService::canAssignTaskToUser (a user without a volunteer profile is allowed).
        // whereNotNull is defensive: a single NULL inside NOT IN (...) would make the whole condition unknown and
        // exclude EVERYONE (the column is NOT NULL today; this keeps it safe if that ever changes).
        return $query->whereNotIn('users.id', VolunteerProfile::query()->select('user_id')->whereNotNull('user_id')->whereIn('status', [
            VolunteerProfile::STATUS_INACTIVE, VolunteerProfile::STATUS_ON_LEAVE, VolunteerProfile::STATUS_WITHDRAWN,
        ]));
    }

    /**
     * The people a ticket of this department (optionally scoped to one unit) can be handed to, by name. Only id,
     * name and role leave the server (no e-mail), capped at $limit.
     *
     * @return Collection<int, User>
     */
    public function eligibleAssignees(int $departmentId, ?int $unitId, int $limit = 200): Collection
    {
        return $this->eligibleAssigneesQuery($departmentId, $unitId)->orderBy('users.name')->orderBy('users.id')->limit($limit)->get(['users.id', 'users.name', 'users.role']);
    }

    /** Is $assignee eligible for a ticket of $departmentId, optionally scoped to one unit? See eligibleAssigneesQuery(). */
    public function assigneeEligible(User $assignee, int $departmentId, ?int $unitId): bool
    {
        return $this->eligibleAssigneesQuery($departmentId, $unitId)->where('users.id', $assignee->id)->exists();
    }

    /** The Arabic reason an ineligible assignee is refused, always the same wording on every path. */
    public function assigneeRefusal(User $assignee, ?int $unitId): string
    {
        if (! $assignee->is_active) {
            return 'المستخدم المحدد غير فعّال ولا يمكن إسناد تذكرة إليه.';
        }

        $volunteer = TaskWorkflowService::canAssignTaskToUser((int) $assignee->id);
        if (! $volunteer['allowed']) {
            return $volunteer['message'];
        }

        return $unitId
            ? 'المستخدم المحدد ليس عضواً فعالاً في الوحدة التقنية المختارة.'
            : 'المستخدم المحدد غير مؤهل لإسناد تذاكر هذه الإدارة إليه.';
    }

    // ── Final approval of the work (the only step that credits) ───────────────────────────────────────────

    /** Does this role give the FINAL approval of work larger than «بسيطة»? (mirrors TaskController::hrApprove) */
    public function isHrApprover(User $user): bool
    {
        return in_array($user->role, self::HR_APPROVAL_ROLES, true);
    }

    /** Work above the simple size needs HR's final approval; a ticket with no size yet is judged once one is set. */
    public function needsHrApproval(?string $scope): bool
    {
        return $scope !== null && $scope !== Task::SCOPE_SIMPLE;
    }

    /**
     * May this user review (approve or return) the work submitted on this ticket? A department leader or a global
     * role, and NEVER the person who did the work (not even a leader who assigned the ticket to themselves, nor a
     * global role that is the assignee) nor the person who submitted it for review. Nobody approves their own work.
     */
    public function canReviewCompletion(User $user, Ticket $ticket): bool
    {
        return $this->canReviewStage($user, $ticket, self::PENDING_REVIEW);
    }

    /** The reviewer rules of canReviewCompletion(), for a ticket that must currently be in $status. */
    public function canReviewStage(User $user, Ticket $ticket, string $status): bool
    {
        return $ticket->status === $status
            && $ticket->assigned_to_id !== null
            && (int) $ticket->assigned_to_id !== (int) $user->id
            // The person who submitted the work (a global role may submit on the assignee's behalf) cannot review it.
            && ($ticket->submitted_by_id === null || (int) $ticket->submitted_by_id !== (int) $user->id)
            && $this->canManage($user, (int) $ticket->department_id);
    }

    public function isDeploymentTicket(Ticket $ticket): bool
    {
        return (bool) $ticket->requires_deployment;
    }

    /**
     * Is the person the ticket is assigned to unable to carry it on: gone (deleted), deactivated, or blocked the way regular tasks
     * are (inactive / on leave / withdrawn volunteer)?
     */
    public function assigneeUnavailable(Ticket $ticket): bool
    {
        if ($ticket->assigned_to_id === null) {
            return true;
        }

        $assignee = User::find($ticket->assigned_to_id);

        return ! $assignee || ! $assignee->is_active || ! TaskWorkflowService::canAssignTaskToUser((int) $assignee->id)['allowed'];
    }

    /**
     * May this user return the submitted/approved work to the assignee (the reviewer rules, in the stages that allow it)?
     * Once the change is live only the assignee sends it back (verify-failed), EXCEPT when the assignee can no longer act: then a
     * manager may, so the ticket cannot be stuck forever.
     */
    public function canReturnForRework(User $user, Ticket $ticket): bool
    {
        if ($this->canReviewStage($user, $ticket, self::PENDING_REVIEW)) {
            return true;
        }

        if (! $this->isDeploymentTicket($ticket)) {
            return false;
        }

        return $this->canReviewStage($user, $ticket, self::APPROVED_FOR_DEPLOY)
            || ($this->canReviewStage($user, $ticket, self::PENDING_VERIFICATION) && $this->assigneeUnavailable($ticket));
    }

    /** May this user put an approved deployment ticket on the live site (department leader or global role, never its assignee)? */
    public function canMarkDeployed(User $user, Ticket $ticket): bool
    {
        return $this->isDeploymentTicket($ticket)
            && $ticket->status === self::APPROVED_FOR_DEPLOY
            && ! $this->isTicketAssignee($user, $ticket)
            && $this->canManage($user, (int) $ticket->department_id);
    }

    /** The assignee's live verification: the ACTUAL assignee only, never a global role acting for them. */
    public function canVerifyLive(User $user, Ticket $ticket): bool
    {
        return $this->isDeploymentTicket($ticket)
            && $ticket->status === self::PENDING_VERIFICATION
            && $this->isTicketAssignee($user, $ticket);
    }

    /**
     * Where the ticket is in its workflow and who must act next (for the UI; derived from the status, never stored).
     * Tickets still waiting for a manager's decision, declined by their assignee, rejected or unresolved are outside the
     * work cycle: stage null.
     *
     * @return array{stage: ?string, next_actor: ?string, next_step_key: string}
     */
    public function workflow(Ticket $ticket): array
    {
        return match ($ticket->status) {
            'ASSIGNED', 'IN_PROGRESS' => ['stage' => 'in_progress', 'next_actor' => 'assignee', 'next_step_key' => 'submit_for_review'],
            self::PENDING_REVIEW => ['stage' => 'pending_review', 'next_actor' => 'reviewer', 'next_step_key' => 'review'],
            self::APPROVED_FOR_DEPLOY => ['stage' => 'approved_for_deploy', 'next_actor' => 'deployer', 'next_step_key' => 'deploy'],
            self::PENDING_VERIFICATION => ['stage' => 'pending_verification', 'next_actor' => 'assignee', 'next_step_key' => 'verify_live'],
            'RESOLVED' => ['stage' => 'resolved', 'next_actor' => null, 'next_step_key' => 'none'],
            default => ['stage' => null, 'next_actor' => null, 'next_step_key' => 'none'],
        };
    }

    /**
     * The `deployment` block (managers, global roles and the assignee only; see redactInternal).
     *
     * @return array{review_approved_by_name: ?string, review_approved_at: ?string, deployed_by_name: ?string, deployed_at: ?string, live_url: ?string, verified_at: ?string, round: int}
     */
    public function deploymentInfo(Ticket $ticket): array
    {
        if ($ticket->review_approved_by_id !== null && ! $ticket->relationLoaded('reviewApprover')) {
            $ticket->load('reviewApprover:id,name');
        }
        if ($ticket->deployed_by_id !== null && ! $ticket->relationLoaded('deployer')) {
            $ticket->load('deployer:id,name');
        }

        return [
            'review_approved_by_name' => $ticket->reviewApprover?->name,
            'review_approved_at' => $ticket->review_approved_at?->toIso8601String(),
            'deployed_by_name' => $ticket->deployer?->name,
            'deployed_at' => $ticket->deployed_at?->toIso8601String(),
            'live_url' => $ticket->live_url,
            'verified_at' => $ticket->verified_at?->toIso8601String(),
            'round' => (int) $ticket->review_round,
        ];
    }
    /**
     * The `credit` block every staff payload carries (for those who may read it, see redactInternal).
     * status: none | pending_review | approved | credited. `approved` (a deployment ticket approved for deployment, not yet
     * verified live) shows the APPROVED points and minutes but nothing is credited; the numbers are final once credited.
     *
     * `hours` is the credited actual time; `minutes` its exact value (null for a credit made before actual durations existed).
     *
     * @return array{status: string, points: ?int, hours: ?float, minutes: ?int, credited_at: ?string, credited_by_name: ?string}
     */
    public function creditInfo(Ticket $ticket): array
    {
        $credited = $ticket->credited_at !== null;
        if ($credited && ! $ticket->relationLoaded('creditor')) {
            $ticket->load('creditor:id,name');
        }

        // Approved for deployment but not yet verified live: the approved values, clearly not credited.
        if (! $credited && $this->isDeploymentTicket($ticket) && in_array($ticket->status, [self::APPROVED_FOR_DEPLOY, self::PENDING_VERIFICATION], true)) {
            return [
                'status' => 'approved',
                'points' => $ticket->task_points === null ? null : (int) $ticket->task_points,
                'hours' => $ticket->actual_minutes === null ? null : round($ticket->actual_minutes / 60, 2),
                'minutes' => $ticket->actual_minutes === null ? null : (int) $ticket->actual_minutes,
                'credited_at' => null,
                'credited_by_name' => null,
            ];
        }

        return [
            'status' => $credited ? 'credited' : ($ticket->status === self::PENDING_REVIEW ? 'pending_review' : 'none'),
            'points' => $credited ? (int) $ticket->credited_points : null,
            'hours' => $credited ? round((float) $ticket->credited_hours, 2) : null,
            'minutes' => $credited && $ticket->credited_minutes !== null ? (int) $ticket->credited_minutes : null,
            'credited_at' => $ticket->credited_at?->toIso8601String(),
            'credited_by_name' => $credited ? $ticket->creditor?->name : null,
        ];
    }

    /**
     * The per-ticket, per-user action set. It REPORTS what the mutating endpoints would allow; it
     * never decides on its own.
     *
     * @return array<string, bool>
     */
    public function capabilities(User $user, Ticket $ticket): array
    {
        $canManage = $this->canManage($user, (int) $ticket->department_id);
        $isAssignee = $this->isAssignee($user, $ticket);
        $open = ! in_array($ticket->status, self::CLOSED_STATUSES, true);

        return [
            'approve' => $canManage && $ticket->status === 'PENDING_APPROVAL',
            'reject' => $canManage && $ticket->status === 'PENDING_APPROVAL',
            'reassign' => $canManage && in_array($ticket->status, self::REASSIGNABLE_STATUSES, true),
            // A global role may act for the assignee, but there must BE one: accepting an unassigned ticket would
            // leave it in progress with nobody holding it (and invisible in «مهامي»).
            'accept_task' => $isAssignee && $ticket->assigned_to_id !== null && $ticket->status === 'ASSIGNED',
            'reject_task' => $isAssignee && $ticket->assigned_to_id !== null && $ticket->status === 'ASSIGNED',
            'complete_task' => $isAssignee && $ticket->status === 'IN_PROGRESS',
            // The final approval (the one step that credits) and the way back to the assignee. Both need a leader or
            // a global role who is NOT the assignee; approving work above «بسيطة» also needs an HR approver. A
            // ticket with no size yet (legacy) is approved together with the size the approver supplies, so the HR
            // gate for it is judged by the endpoint once that size is known.
            // A deployment ticket is approved FOR DEPLOYMENT instead (and closed only by the assignee's live verification).
            'approve_completion' => ! $this->isDeploymentTicket($ticket)
                && $this->canReviewCompletion($user, $ticket)
                && $ticket->credited_at === null
                && (! $this->needsHrApproval($ticket->task_scope) || $this->isHrApprover($user)),
            'approve_for_deploy' => $this->isDeploymentTicket($ticket)
                && $this->canReviewCompletion($user, $ticket)
                && $ticket->credited_at === null
                && (! $this->needsHrApproval($ticket->task_scope) || $this->isHrApprover($user))
                && ! $this->assigneeUnavailable($ticket),
            'mark_deployed' => $this->canMarkDeployed($user, $ticket),
            'verify_resolved' => $this->canVerifyLive($user, $ticket),
            'verify_failed' => $this->canVerifyLive($user, $ticket),
            'return_for_rework' => $this->canReturnForRework($user, $ticket),
            'add_internal_note' => $canManage,
            'comment' => true,
            'change_priority' => $canManage && $open,
            // The planning estimate (size, points, hours) of an open ticket: same gate as priority.
            // Never once the work is submitted (it is corrected in the approval request, under the HR gate) and never by
            // the assignee, who must not resize their own work.
            'can_edit_estimate' => $canManage && in_array($ticket->status, self::ESTIMATE_EDITABLE_STATUSES, true) && ! $this->isTicketAssignee($user, $ticket),
        ];
    }

    /**
     * Strip what this viewer may not read from a serialized ticket (list row or detail payload): internal notes
     * and the estimate fields / ESTIMATE_UPDATED log rows (see canSeeInternal for who keeps them).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function redactInternal(User $user, Ticket $ticket, array $data): array
    {
        if ($this->canSeeInternal($user, $ticket)) {
            $data['credit'] = $this->creditInfo($ticket);
            $data['deployment'] = $this->deploymentInfo($ticket);
            $data['workflow'] = $this->workflow($ticket);
            // The relations are only loaded to name people; the `credit` and `deployment` blocks carry the names.
            unset($data['creditor'], $data['review_approver'], $data['reviewApprover'], $data['deployer']);

            return $data;
        }

        $data['internal_notes'] = null;

        // The planning estimate (size, points, hours), the credit and their change history are for the same people
        // who may read internal notes: managers, global roles and the assignee. A plain department member can open
        // the ticket without them.
        foreach (['task_scope', 'task_points', 'estimated_hours', 'actual_minutes', 'credit', 'creditor', 'credited_points', 'credited_hours', 'credited_minutes', 'credited_at', 'credited_by_id', 'requires_deployment', 'deployment', 'workflow', 'review_approver', 'reviewApprover', 'deployer'] as $key) {
            unset($data[$key]);
        }

        foreach (['activity_logs', 'activityLogs'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $data[$key] = array_values(array_filter($data[$key], fn ($log) => ! in_array($log['action'] ?? null, self::INTERNAL_LOG_ACTIONS, true)));
            }
        }

        return $data;
    }
}
