<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * «مهامي»: the tickets that are assigned to ONE person, derived from the tickets themselves.
 *
 * The ticket stays the single source of truth: nothing is copied into the tasks module, no Task row and
 * no points award is created, and a ticket that is reassigned stops appearing here on the next call
 * because the answer is computed from tickets.assigned_to_id every time.
 *
 * Scope is fixed to the caller: `assigned_to_id = $user->id`. There is no parameter that selects another
 * person, and the optional status filter only narrows. Opening a ticket still goes through the normal
 * single-ticket authorization (TicketController::show), which this class does not replace.
 */
class TicketMyWork
{
    /** Waiting for the assignee, being worked on, then submitted and waiting for the final approval (still the assignee's until it is approved). */
    public const OPEN_STATUSES = ['ASSIGNED', 'IN_PROGRESS', 'PENDING_REVIEW', 'APPROVED_FOR_DEPLOY', 'PENDING_VERIFICATION'];

    /** The assignee's own outcomes, shown for a while in the board's closed column. */
    public const CLOSED_STATUSES = ['RESOLVED', 'UNRESOLVED', 'REJECTED_BY_ASSIGNEE'];

    public const STATUSES = ['ASSIGNED', 'IN_PROGRESS', 'PENDING_REVIEW', 'APPROVED_FOR_DEPLOY', 'PENDING_VERIFICATION', 'RESOLVED', 'UNRESOLVED', 'REJECTED_BY_ASSIGNEE'];

    /** How long a finished ticket stays in the closed column. */
    public const CLOSED_WINDOW_DAYS = 30;

    public const OPEN_LIMIT = 300;
    public const CLOSED_LIMIT = 100;

    /** Only this much of a description is read from the database; the row shows 200 characters of it. */
    private const DESCRIPTION_READ = 600;

    /** The date a closed outcome is windowed AND ordered by: resolution time for RESOLVED, else the last update. */
    private const CLOSED_DATE_SQL = 'COALESCE(CASE WHEN tickets.status = ? THEN tickets.resolved_at END, tickets.updated_at)';

    private int $openLimit = self::OPEN_LIMIT;

    private int $closedLimit = self::CLOSED_LIMIT;

    public function __construct(private readonly TicketAccess $access) {}

    /** Same service with other caps (tests; a future per-screen setting). */
    public function withLimits(int $open, int $closed): static
    {
        $clone = clone $this;
        $clone->openLimit = $open;
        $clone->closedLimit = $closed;

        return $clone;
    }

    /**
     * @param  array<int, string>  $statuses  optional narrowing (empty = every status this screen shows)
     * @return array{data: array<int, array<string, mixed>>, meta: array{open_total: int, closed_total: int, truncated: bool}}
     */
    public function forUser(User $user, array $statuses = []): array
    {
        $open = $statuses === [] ? self::OPEN_STATUSES : array_values(array_intersect($statuses, self::OPEN_STATUSES));
        $closed = $statuses === [] ? self::CLOSED_STATUSES : array_values(array_intersect($statuses, self::CLOSED_STATUSES));

        $rows = collect();
        $openTotal = 0;
        $closedTotal = 0;

        if ($open !== []) {
            $query = $this->base($user)->whereIn('tickets.status', $open);
            $openTotal = (clone $query)->count();
            $rows = $rows->concat(
                $query
                    // Waiting-for-receipt first, then in progress; the nearest deadline first, no deadline last.
                    ->orderByRaw("CASE tickets.status WHEN 'ASSIGNED' THEN 0 WHEN 'PENDING_VERIFICATION' THEN 1 WHEN 'IN_PROGRESS' THEN 2 ELSE 3 END")
                    ->orderByRaw('tickets.expected_resolution_time IS NULL')
                    ->orderBy('tickets.expected_resolution_time')
                    ->orderByDesc('tickets.id')
                    ->limit($this->openLimit)
                    ->get(),
            );
        }

        if ($closed !== []) {
            $since = now()->subDays(self::CLOSED_WINDOW_DAYS);
            // A resolved ticket is dated by its resolution; declined / unresolved ones carry no dedicated
            // timestamp, so the last update is the moment they reached that state. The same expression
            // windows AND orders the group, so what is cut by the cap is always the oldest outcome.
            $query = $this->base($user)->whereIn('tickets.status', $closed)->whereRaw(self::CLOSED_DATE_SQL.' >= ?', ['RESOLVED', $since]);
            $closedTotal = (clone $query)->count();
            $rows = $rows->concat(
                $query->orderByRaw(self::CLOSED_DATE_SQL.' DESC', ['RESOLVED'])->orderByDesc('tickets.id')->limit($this->closedLimit)->get(),
            );
        }

        return [
            'data' => $rows->map(fn (Ticket $t) => $this->present($user, $t))->values()->all(),
            'meta' => [
                'open_total' => $openTotal,
                'closed_total' => $closedTotal,
                'truncated' => $openTotal > $this->openLimit || $closedTotal > $this->closedLimit,
            ],
        ];
    }

    private function base(User $user)
    {
        return Ticket::query()
            // Never the whole description of every row: a bounded slice is all the card shows.
            ->select([
                'tickets.id', 'tickets.ticket_number', 'tickets.title', 'tickets.status', 'tickets.priority', 'tickets.department_id', 'tickets.unit_id',
                'tickets.created_by_id', 'tickets.created_by_name', 'tickets.assigned_to_id', 'tickets.expected_resolution_time', 'tickets.accepted_at',
                'tickets.resolved_at', 'tickets.task_scope', 'tickets.task_points', 'tickets.estimated_hours', 'tickets.actual_minutes', 'tickets.credited_points', 'tickets.credited_hours', 'tickets.credited_minutes', 'tickets.requires_deployment', 'tickets.review_approved_by_id', 'tickets.review_approved_at', 'tickets.deployed_by_id', 'tickets.deployed_at', 'tickets.live_url', 'tickets.verified_at', 'tickets.review_round', 'tickets.credited_at', 'tickets.credited_by_id', 'tickets.created_at', 'tickets.updated_at',
                DB::raw('SUBSTR(tickets.description, 1, '.self::DESCRIPTION_READ.') AS description'),
            ])
            ->where('tickets.assigned_to_id', $user->id)
            ->with(['department:id,name,name_ar', 'unit:id,name_ar', 'creditor:id,name', 'reviewApprover:id,name', 'deployer:id,name']);
    }

    /** @return array<string, mixed> */
    private function present(User $user, Ticket $t): array
    {
        $isAssignee = $this->access->isAssignee($user, $t);

        return [
            'id' => $t->id,
            'ticket_number' => $t->ticket_number,
            'title' => $t->title,
            'description' => Str::limit(trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $t->description))), 200),
            'status' => $t->status,
            'priority' => $t->priority,
            'department' => $t->department ? ['id' => $t->department->id, 'name' => $t->department->name_ar ?: $t->department->name] : null,
            'unit' => $t->unit ? ['id' => $t->unit->id, 'name' => $t->unit->name_ar] : null,
            'created_by_name' => $t->created_by_name,
            'assigned_to_id' => $t->assigned_to_id,
            'expected_resolution_time' => $t->expected_resolution_time?->toIso8601String(),
            'is_delayed' => $t->is_delayed,
            'accepted_at' => $t->accepted_at?->toIso8601String(),
            'resolved_at' => $t->resolved_at?->toIso8601String(),
            'task_scope' => $t->task_scope,
            'task_points' => $t->task_points,
            'estimated_hours' => $t->estimated_hours,
            'actual_minutes' => $t->actual_minutes,
            // The assignee's own credit status (none | pending_review | credited); only ever their own tickets.
            'credit' => $this->access->creditInfo($t),
            // Only ever the assignee's own tickets: the flow stage, who acts next and (for the live test) the page to open.
            'requires_deployment' => (bool) $t->requires_deployment,
            'workflow' => $this->access->workflow($t),
            'deployment' => $this->access->deploymentInfo($t),
            'created_at' => $t->created_at?->toIso8601String(),
            'updated_at' => $t->updated_at?->toIso8601String(),
            'capabilities' => [
                'can_accept' => $isAssignee && $t->status === 'ASSIGNED',
                'can_reject' => $isAssignee && $t->status === 'ASSIGNED',
                'can_complete' => $isAssignee && $t->status === 'IN_PROGRESS',
                // They are the assignee, so the ordinary single-ticket check passes; kept explicit so the UI never guesses.
                'can_open' => $this->access->canView($user, $t),
            ],
        ];
    }
}
