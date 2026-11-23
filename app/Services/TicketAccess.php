<?php

namespace App\Services;

use App\Models\TeamMember;
use App\Models\Ticket;
use App\Models\User;

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
    /** Statuses after which a ticket is finished. */
    public const CLOSED_STATUSES = ['RESOLVED', 'REJECTED_BY_ADMIN', 'UNRESOLVED'];

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

    /**
     * Is $assignee eligible for a ticket of $departmentId, optionally scoped to one unit? With a unit,
     * membership must be exact (an active team_members row in that unit AND department).
     */
    public function assigneeEligible(User $assignee, int $departmentId, ?int $unitId): bool
    {
        if ($this->departments->isGlobal($assignee)) {
            return true;
        }

        if ($unitId) {
            return TeamMember::where('user_id', $assignee->id)
                ->where('unit_id', $unitId)
                ->where('department_id', $departmentId)
                ->where('status', 'active')
                ->exists();
        }

        return $this->departments->canAccessDepartment($assignee, $departmentId);
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
            'add_internal_note' => $canManage,
            'comment' => true,
            'change_priority' => $canManage && $open,
            // The planning estimate (size, points, hours) of an open ticket: same gate as priority.
            'can_edit_estimate' => $canManage && $open,
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
            return $data;
        }

        $data['internal_notes'] = null;

        // The planning estimate (size, points, hours) and its change history are for the same people who may
        // read internal notes: managers, global roles and the assignee. A plain department member can open the
        // ticket without them.
        foreach (['task_scope', 'task_points', 'estimated_hours'] as $key) {
            unset($data[$key]);
        }

        foreach (['activity_logs', 'activityLogs'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $data[$key] = array_values(array_filter($data[$key], fn ($log) => ! in_array($log['action'] ?? null, ['INTERNAL_NOTE', 'ESTIMATE_UPDATED'], true)));
            }
        }

        return $data;
    }
}
