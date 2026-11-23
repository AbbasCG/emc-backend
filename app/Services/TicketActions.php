<?php

namespace App\Services;

use App\Exceptions\TicketActionException;
use App\Models\DepartmentalUnit;
use App\Models\Ticket;
use App\Models\TicketActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ticket mutations shared by the single-ticket endpoints and the bulk endpoint, so a bulk
 * operation can never do anything a person could not do one ticket at a time.
 *
 * Every action re-reads the ticket under a row lock inside a transaction and re-checks the
 * status THERE, so two people acting at once cannot both succeed: the second one sees the new
 * state and is refused. Authorization is checked per ticket, here, never assumed from the caller.
 *
 * No action sends a message of its own. Reporter notifications still come only from
 * TicketStatusObserver (and a reassign does not change status in a way it announces).
 */
class TicketActions
{
    public const PRIORITIES = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];
    public const PRIORITY_LABELS = ['LOW' => 'منخفضة', 'MEDIUM' => 'متوسطة', 'HIGH' => 'عالية', 'CRITICAL' => 'حرجة'];
    public const MAX_BULK = 50;

    public function __construct(private readonly TicketAccess $access) {}

    /**
     * @throws TicketActionException
     */
    public function changePriority(int $ticketId, User $actor, string $priority, ?string $reason = null): Ticket
    {
        return DB::transaction(function () use ($ticketId, $actor, $priority, $reason) {
            $ticket = Ticket::lockForUpdate()->find($ticketId);
            if (! $ticket || ! $this->access->canView($actor, $ticket)) {
                throw new TicketActionException('التذكرة غير موجودة أو لا تملك صلاحية عليها.', 404);
            }
            if (! $this->access->canManage($actor, (int) $ticket->department_id)) {
                throw new TicketActionException('تغيير الأولوية متاح فقط لقائد الإدارة المعنية.', 403);
            }
            if (in_array($ticket->status, TicketAccess::CLOSED_STATUSES, true)) {
                throw new TicketActionException('لا يمكن تغيير أولوية تذكرة مغلقة.', 409);
            }
            if ($ticket->priority === $priority) {
                throw new TicketActionException('التذكرة بهذه الأولوية بالفعل.', 422, ['priority' => ['التذكرة بهذه الأولوية بالفعل.']]);
            }

            $previous = $ticket->priority;
            $ticket->update(['priority' => $priority]);

            TicketActivityLog::create([
                'ticket_id' => $ticket->id,
                'action' => 'PRIORITY_CHANGED',
                'previous_state' => $previous,
                'new_state' => $priority,
                'performed_by' => $actor->id,
                'performed_by_name' => $actor->name,
                'details' => 'تغيير الأولوية من '.(self::PRIORITY_LABELS[$previous] ?? $previous).' إلى '.self::PRIORITY_LABELS[$priority].($reason ? ': '.$reason : ''),
                'logged_at' => now(),
            ]);

            return $ticket;
        });
    }

    /**
     * Hand a ticket to someone (assign again for the first time after approval, a true reassignment, or
     * routing again a ticket its assignee declined: the endpoint has never distinguished them). Status goes
     * back to ASSIGNED so the new assignee accepts it.
     *
     * @throws TicketActionException
     */
    public function reassign(int $ticketId, User $actor, int $assigneeId, ?int $unitId = null, ?string $reason = null, array $estimate = []): Ticket
    {
        return DB::transaction(function () use ($ticketId, $actor, $assigneeId, $unitId, $reason, $estimate) {
            $ticket = Ticket::lockForUpdate()->find($ticketId);
            if (! $ticket || ! $this->access->canView($actor, $ticket)) {
                throw new TicketActionException('التذكرة غير موجودة أو لا تملك صلاحية عليها.', 404);
            }
            if (! $this->access->canManage($actor, (int) $ticket->department_id)) {
                throw new TicketActionException('إعادة التوجيه متاحة فقط لقائد الإدارة المعنية.', 403);
            }
            if (! in_array($ticket->status, TicketAccess::REASSIGNABLE_STATUSES, true)) {
                throw new TicketActionException('لا يمكن إعادة توجيه تذكرة بحالتها الراهنة.', 409);
            }

            if ($unitId !== null) {
                $belongs = DepartmentalUnit::where('id', $unitId)->where('department_id', $ticket->department_id)->exists();
                if (! $belongs) {
                    throw new TicketActionException('الوحدة المحددة لا تتبع إدارة هذه التذكرة.', 422, ['unit_id' => ['الوحدة المحددة لا تتبع إدارة هذه التذكرة.']]);
                }
            }

            // A reassign that does not touch the unit must still honour the unit the ticket already carries.
            $effectiveUnit = $unitId ?? ($ticket->unit_id ? (int) $ticket->unit_id : null);
            $assignee = User::find($assigneeId);
            if (! $assignee || ! $this->access->assigneeEligible($assignee, (int) $ticket->department_id, $effectiveUnit)) {
                throw new TicketActionException(
                    $effectiveUnit ? 'المستخدم المحدد ليس عضواً فعالاً في الوحدة التقنية المختارة.' : 'المستخدم المحدد غير مؤهل لإسناد تذاكر هذه الإدارة إليه.',
                    422,
                    ['assigned_to_id' => ['المستخدم المحدد غير مؤهل للتكليف بناءً على الوحدة/الإدارة المحددة.']],
                );
            }

            // A declined ticket must go to SOMEONE ELSE: handing it straight back to the person who just
            // apologised for it would only loop (they can still be asked in person and accept normally).
            $declined = $ticket->status === 'REJECTED_BY_ASSIGNEE';
            if ($declined && $ticket->assigned_to_id !== null && (int) $ticket->assigned_to_id === $assigneeId) {
                throw new TicketActionException(
                    'المكلف الحالي اعتذر عن هذه التذكرة؛ اختر مكلفًا آخر.',
                    422,
                    ['assigned_to_id' => ['المكلف الحالي اعتذر عن هذه التذكرة؛ اختر مكلفًا آخر.']],
                );
            }
            $previousReason = $declined ? trim((string) $ticket->assignee_rejection_reason) : '';

            // The ticket becomes work for a person: it must carry its size and points, from the request or
            // already stored. A legacy ticket with neither is refused here, on every path (single + bulk).
            $work = TicketWorkEstimate::resolve($ticket, $estimate);
            $estimateChanged = TicketWorkEstimate::differs($ticket, $work);
            $estimateBefore = TicketWorkEstimate::snapshot($ticket);

            $previous = $ticket->status;
            $ticket->update([
                'assigned_to_id' => $assigneeId,
                'unit_id' => $unitId ?? $ticket->unit_id,
                'status' => 'ASSIGNED',
                'accepted_at' => null,
                // The apology belongs to the previous assignee. It stays in the activity log (the
                // REJECTED_BY_ASSIGNEE row and the details below) but is cleared from the ticket, so the new
                // assignee never reads someone else's «sorry» as the state of their own task.
                'assignee_rejection_reason' => null,
            ] + $work);

            TicketActivityLog::create([
                'ticket_id' => $ticket->id,
                'action' => 'REASSIGNED',
                'previous_state' => $previous,
                'new_state' => 'ASSIGNED',
                'performed_by' => $actor->id,
                'performed_by_name' => $actor->name,
                'details' => 'تمت إعادة التوجيه وتغيير المكلف'.($reason ? ': '.$reason : '')
                    .($previousReason !== '' ? ' | سبب اعتذار المكلف السابق: '.Str::limit($previousReason, 500) : ''),
                'logged_at' => now(),
            ]);

            // The estimate has its own row, visible only to managers, global roles and the assignee: this row
            // (like APPROVED_AND_ASSIGNED) is read by every department member, so it carries no estimate text.
            if ($estimateChanged) {
                TicketWorkEstimate::log($ticket, $actor, $estimateBefore, $work);
            }

            return $ticket;
        });
    }

    /**
     * Change the planning estimate (size, points, approximate hours) of an OPEN ticket. Department
     * leadership only, like priority and reassignment. Estimate only: nothing is credited.
     *
     * @param  array<string, mixed>  $input  any subset of task_scope / task_points / estimated_hours; what is not sent keeps its stored value
     *
     * @throws TicketActionException
     */
    public function updateEstimate(int $ticketId, User $actor, array $input): Ticket
    {
        return DB::transaction(function () use ($ticketId, $actor, $input) {
            $ticket = Ticket::lockForUpdate()->find($ticketId);
            if (! $ticket || ! $this->access->canView($actor, $ticket)) {
                throw new TicketActionException('التذكرة غير موجودة أو لا تملك صلاحية عليها.', 404);
            }
            if (! $this->access->canManage($actor, (int) $ticket->department_id)) {
                throw new TicketActionException('تعديل تقدير العمل متاح فقط لقائد الإدارة المعنية.', 403);
            }
            if (in_array($ticket->status, TicketAccess::CLOSED_STATUSES, true)) {
                throw new TicketActionException('لا يمكن تعديل تقدير العمل لتذكرة مغلقة.', 409);
            }

            // The only place where an explicit `estimated_hours: null` clears the hours.
            $work = TicketWorkEstimate::resolve($ticket, TicketWorkEstimate::only($input), allowClearHours: true);

            if (! TicketWorkEstimate::differs($ticket, $work)) {
                return $ticket;
            }

            $before = TicketWorkEstimate::snapshot($ticket);
            $ticket->update($work);
            TicketWorkEstimate::log($ticket, $actor, $before, $work);

            return $ticket;
        });
    }

    /**
     * One action over many tickets. Each ticket succeeds or fails ON ITS OWN, with its own
     * authorization and status check; a failure never rolls back the others and never reveals
     * whether a refused id exists (not found and not permitted read the same).
     *
     * @param  array<int, int>  $ids
     * @param  array<string, mixed>  $params
     * @return array{requested: int, succeeded: int, failed: int, results: array<int, array<string, mixed>>}
     */
    public function bulk(User $actor, string $action, array $ids, array $params): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $numbers = Ticket::query()->whereIn('id', $ids)->pluck('ticket_number', 'id');
        $results = [];

        foreach ($ids as $id) {
            try {
                $ticket = match ($action) {
                    'priority' => $this->changePriority($id, $actor, (string) $params['priority'], $params['reason'] ?? null),
                    'reassign' => $this->reassign($id, $actor, (int) $params['assigned_to_id'], isset($params['unit_id']) ? (int) $params['unit_id'] : null, $params['reason'] ?? null, TicketWorkEstimate::only($params)),
                };
                $results[] = ['id' => $id, 'ticket_number' => $ticket->ticket_number, 'ok' => true, 'message' => 'تم']
                    + ($action === 'reassign' ? ['task_scope' => $ticket->task_scope, 'task_points' => $ticket->task_points, 'estimated_hours' => $ticket->estimated_hours] : []);
            } catch (TicketActionException $e) {
                // "Not found" and "not visible to you" are the same 404 (nothing is revealed about the
                // id, not even its number). A 403/409/422 only reaches a ticket the caller may already see.
                $results[] = ['id' => $id, 'ticket_number' => $e->status === 404 ? null : ($numbers[$id] ?? null), 'ok' => false, 'message' => $e->getMessage()];
            }
        }

        $ok = count(array_filter($results, fn ($r) => $r['ok']));

        return ['requested' => count($ids), 'succeeded' => $ok, 'failed' => count($ids) - $ok, 'results' => $results];
    }
}
