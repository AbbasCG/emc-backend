<?php

namespace App\Services;

use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Support\WorkBoardColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * The GLOBAL operations board (/dashboard/operations/board): every operational task and every assigned ticket, of every
 * assignee, in the SAME columns as «مهامي ومسؤولياتي» (App\Support\WorkBoardColumns is the one status -> column map).
 *
 * It is a PUBLIC board, so each card is a whitelist built from scratch (never a model or TaskResource serialization):
 *   title, reference, kind, column / status, stage badge, assignee NAME, department NAME, due date, priority and (only
 *   where the viewer may read an estimate) points / hours. No description, comments, checklist, attachments, notes,
 *   rejection reasons, evaluations, credit / deployment data, live URLs, reporter contact data or activity logs.
 *
 * Seeing a card grants NOTHING: the per-card `capabilities` are computed with the viewer's real access (can_open through
 * TicketAccess::canView for a ticket; the assignee or a task-management role for a task) and carry only the assignee's own
 * actions. No administrative action (approve, reassign, review, lock, evaluate...) is ever offered by this payload;
 * every such action stays checked by its own endpoint.
 *
 * What is on the board: the union of every user's «مهامي» (so everything shown at /dashboard/admin/tasks/my is here, in the same
 * column) PLUS the tasks that belong to nobody's «مهامي»: standalone tasks with no assignee («غير مسندة»).
 *   - tasks: any task, except a collective-task PARENT whose own assignee also owns one of its children (the child is that
 *     person's assignment: the very rule of MyWorkTaskController::index) and except a parent with no assignee that has
 *     children (a container, never work). A standalone task with no assignee IS included. One row per task, no joins, so no
 *     card can be produced twice;
 *   - tickets: the ones TicketMyWork shows for their assignee (an assignee, in one of TicketMyWork::STATUSES, finished ones
 *     only for CLOSED_WINDOW_DAYS). Unassigned tickets (waiting for approval) are not on it. A ticket is never copied into
 *     the tasks table, so no ticket card has a task twin.
 *
 * Ticket cards are masked for a viewer who cannot open the ticket (TicketAccess::canView): the title and reference are replaced
 * (`masked: true`) so a reporter's free text never becomes public, while the work distribution (column, stage, assignee,
 * department, priority) stays visible. `search` only matches tickets the viewer may open, so it is never a title oracle. A viewer
 * who only REPORTED a ticket gets no assignee and no deadline on it, exactly like TicketReporterView::row.
 */
class OperationsWorkBoard
{
    public const DEFAULT_PER_COLUMN = 30;

    /** The title of a ticket card the viewer cannot open. */
    public const MASKED_TITLE = 'تذكرة — بيانات محجوبة';

    public const MAX_PER_COLUMN = 100;

    /** Most rows read per kind for one column (page * per_column): bounds the cost of «load more». */
    public const MAX_DEPTH = 1000;

    /** Roles that may open ANY task through the management route (operations/tasks/{task}: role:admin,super_admin,tech_admin,operations_manager). */
    public const TASK_DETAIL_ROLES = ['admin', 'super_admin', 'tech_admin', 'operations_manager'];

    /** @var array<int, bool> department id => may the viewer open a ticket there (when they are neither its creator nor its assignee) */
    private array $deptOpen = [];

    public function __construct(private readonly TicketAccess $access, private readonly TicketQuery $tickets) {}

    /**
     * @param  array{scope?: string, department_id?: int|null, kind?: string, search?: string|null, per_column?: int, column?: string|null, page?: int}  $f  validated filters
     * @return array{data: array{columns: array<int, array<string, mixed>>}, meta: array<string, mixed>}
     */
    public function build(User $viewer, array $f): array
    {
        $this->deptOpen = [];
        $per = max(1, min(self::MAX_PER_COLUMN, (int) ($f['per_column'] ?? self::DEFAULT_PER_COLUMN)));
        $only = $f['column'] ?? null;
        $page = $only === null ? 1 : max(1, (int) ($f['page'] ?? 1));
        $kind = $f['kind'] ?? 'all';
        $wantTasks = $kind !== 'tickets';
        $wantTickets = $kind !== 'tasks';

        $taskBase = fn () => $this->taskQuery($viewer, $f);
        $ticketBase = fn () => $this->ticketQuery($viewer, $f);

        // Exact counts per column and per kind, from the same filtered queries the cards come from.
        [$taskCols, $taskPairs] = $wantTasks ? $this->taskColumnCounts($taskBase()) : [[], []];
        $ticketCols = $wantTickets ? $this->ticketColumnCounts($ticketBase(), $viewer) : [];

        $columns = [];
        $rowsByColumn = [];
        foreach (array_keys(WorkBoardColumns::COLUMNS) as $key) {
            if ($only !== null && $only !== $key) {
                continue;
            }
            $depth = $page * $per;
            $rows = [];
            if (($taskCols[$key] ?? 0) > 0) {
                foreach ($this->fetchTasks($taskBase(), $taskPairs[$key] ?? [], $key, $depth) as $task) {
                    $rows[] = ['sort' => $this->taskSortKey($task, $key), 'model' => $task];
                }
            }
            if (($ticketCols[$key] ?? 0) > 0) {
                foreach ($this->fetchTickets($ticketBase(), $viewer, $key, $depth) as $ticket) {
                    $rows[] = ['sort' => $this->ticketSortKey($ticket, $key), 'model' => $ticket];
                }
            }
            usort($rows, fn (array $a, array $b) => $a['sort'] <=> $b['sort']);
            $total = ($taskCols[$key] ?? 0) + ($ticketCols[$key] ?? 0);
            $rowsByColumn[$key] = array_slice($rows, ($page - 1) * $per, $per);
            $columns[$key] = ['count' => $total, 'tasks' => $taskCols[$key] ?? 0, 'tickets' => $ticketCols[$key] ?? 0];
        }

        // One batch of eager loads for the whole page of cards (a query per relation, never per card).
        $tasks = new EloquentCollection();
        $tickets = new EloquentCollection();
        foreach ($rowsByColumn as $rows) {
            foreach ($rows as $r) {
                $r['model'] instanceof Task ? $tasks->push($r['model']) : $tickets->push($r['model']);
            }
        }
        $tasks->load(['department:id,name,name_ar', 'assignedTo:id,name']);
        $tickets->load(['department:id,name,name_ar', 'assignee:id,name']);

        $now = now();
        $out = [];
        $seen = [];
        foreach ($columns as $key => $counts) {
            $items = [];
            foreach ($rowsByColumn[$key] as $r) {
                $card = $r['model'] instanceof Task ? $this->taskCard($viewer, $r['model'], $key, $now) : $this->ticketCard($viewer, $r['model'], $key, $now);
                if (isset($seen[$card['key']])) {
                    continue; // defensive: one card per item on the whole board
                }
                $seen[$card['key']] = true;
                $items[] = $card;
            }
            $out[] = [
                'key' => $key,
                'label' => WorkBoardColumns::COLUMNS[$key],
                'count' => $counts['count'],
                'counts' => ['tasks' => $counts['tasks'], 'tickets' => $counts['tickets']],
                'items' => $items,
                'has_more' => $counts['count'] > $page * $per,
            ];
        }

        // The totals of the WHOLE board (every column), also when a single column was asked for.
        $tasksTotal = array_sum($taskCols);
        $ticketsTotal = array_sum($ticketCols);

        return [
            'data' => ['columns' => $out],
            'meta' => [
                'scope' => $f['scope'] ?? 'all',
                'kind' => $kind,
                'per_column' => $per,
                'column' => $only,
                'page' => $page,
                // The numbers of the kind segments follow every other filter, like the page's own counters.
                'counts' => ['all' => $tasksTotal + $ticketsTotal, 'tasks' => $tasksTotal, 'tickets' => $ticketsTotal],
                'generated_at' => $now->toIso8601String(),
            ],
        ];
    }

    // ── Queries ──────────────────────────────────────────────────────────────

    private function taskQuery(User $viewer, array $f): Builder
    {
        $q = Task::query()
            // A collective task is a parent plus one child per member: the parent is left out when its assignee also owns
            // a child (MyWorkTaskController::index), and a parent without assignee that has children is only a container.
            ->whereRaw('NOT EXISTS (SELECT 1 FROM tasks AS c WHERE c.parent_task_id = tasks.id AND (tasks.assigned_to IS NULL OR c.assigned_to = tasks.assigned_to))');

        if (($f['scope'] ?? 'all') === 'mine') {
            $q->where('tasks.assigned_to', $viewer->id);
        }
        if (! empty($f['department_id'])) {
            $q->where('tasks.department_id', (int) $f['department_id']);
        }
        if (($term = trim((string) ($f['search'] ?? ''))) !== '') {
            $q->whereRaw("tasks.title LIKE ? ESCAPE '!'", [$this->like($term)]);
        }

        return $q;
    }

    private function ticketQuery(User $viewer, array $f): Builder
    {
        $since = now()->subDays(TicketMyWork::CLOSED_WINDOW_DAYS);
        $q = Ticket::query()
            ->whereNotNull('tickets.assigned_to_id')
            ->where(function ($w) use ($since) {
                $w->whereIn('tickets.status', TicketMyWork::OPEN_STATUSES)
                    ->orWhere(fn ($c) => $c->whereIn('tickets.status', TicketMyWork::CLOSED_STATUSES)
                        ->whereRaw(TicketMyWork::CLOSED_DATE_SQL.' >= ?', ['RESOLVED', $since]));
            });

        if (($f['scope'] ?? 'all') === 'mine') {
            $q->where('tickets.assigned_to_id', $viewer->id);
        }
        if (! empty($f['department_id'])) {
            $q->where('tickets.department_id', (int) $f['department_id']);
        }
        if (($term = trim((string) ($f['search'] ?? ''))) !== '') {
            $like = $this->like($term);
            // Only among the tickets this viewer may see (the visibility of the ticket list), so a search can never confirm the title of a hidden one.
            $q->whereIn('tickets.id', $this->tickets->visible($viewer)->select('tickets.id'))
                ->where(fn ($w) => $w->whereRaw("tickets.title LIKE ? ESCAPE '!'", [$like])->orWhereRaw("tickets.ticket_number LIKE ? ESCAPE '!'", [$like]));
        }

        return $q;
    }

    /** `!` escapes % and _ the same way on MySQL and SQLite (see TicketQuery::filter). */
    private function like(string $term): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
    }

    /**
     * @return array{0: array<string, int>, 1: array<string, array<int, array{0: ?string, 1: ?string}>>} per column: count, and the
     *         (status, acceptance) pairs that land in it (so the cards of a column are selected with the SAME map as the counts)
     */
    private function taskColumnCounts(Builder $q): array
    {
        $rows = $q->reorder()->select('tasks.status', 'tasks.acceptance_status', DB::raw('COUNT(*) AS c'))->groupBy('tasks.status', 'tasks.acceptance_status')->get();
        $counts = [];
        $pairs = [];
        foreach ($rows as $r) {
            $col = WorkBoardColumns::forTask($r->status, $r->acceptance_status);
            $counts[$col] = ($counts[$col] ?? 0) + (int) $r->c;
            $pairs[$col][] = [$r->status, $r->acceptance_status];
        }

        return [$counts, $pairs];
    }

    /** Per column, through the status THIS viewer is told (a submitter's review stage reads IN_PROGRESS, like everywhere else). @return array<string, int> */
    private function ticketColumnCounts(Builder $q, User $viewer): array
    {
        $counts = [];
        foreach ($this->tickets->countByStatus($q, $viewer) as $status => $c) {
            $col = WorkBoardColumns::forTicket((string) $status);
            $counts[$col] = ($counts[$col] ?? 0) + (int) $c;
        }

        return $counts;
    }

    /** @param array<int, array{0: ?string, 1: ?string}> $pairs @return EloquentCollection<int, Task> */
    private function fetchTasks(Builder $q, array $pairs, string $column, int $limit): EloquentCollection
    {
        $q->where(function ($w) use ($pairs) {
            foreach ($pairs as [$status, $acceptance]) {
                $w->orWhere(function ($p) use ($status, $acceptance) {
                    $status === null ? $p->whereNull('tasks.status') : $p->where('tasks.status', $status);
                    $acceptance === null ? $p->whereNull('tasks.acceptance_status') : $p->where('tasks.acceptance_status', $acceptance);
                });
            }
        });

        $now = now();
        $due = 'COALESCE(tasks.due_datetime, tasks.due_date)';
        $q->select([
            'tasks.id', 'tasks.parent_task_id', 'tasks.title', 'tasks.department_id', 'tasks.assigned_to', 'tasks.priority', 'tasks.task_scope', 'tasks.points',
            'tasks.estimated_hours', 'tasks.status', 'tasks.acceptance_status', 'tasks.due_date', 'tasks.due_datetime', 'tasks.locked_at', 'tasks.created_at', 'tasks.updated_at',
        ]);
        // Finished / parked columns: the most recently changed first. Open columns: overdue, then urgency, then the nearest deadline.
        if (! in_array($column, WorkBoardColumns::NOT_OVERDUE, true)) {
            $q->orderByRaw('CASE WHEN (tasks.due_datetime IS NOT NULL AND tasks.due_datetime < ?) OR (tasks.due_datetime IS NULL AND tasks.due_date IS NOT NULL AND tasks.due_date < ?) THEN 0 ELSE 1 END', [$now, $now->toDateString()])
                ->orderByRaw("CASE tasks.priority WHEN 'urgent' THEN 3 WHEN 'critical' THEN 3 WHEN 'high' THEN 2 WHEN 'medium' THEN 1 ELSE 0 END DESC")
                ->orderByRaw("{$due} IS NULL")->orderByRaw($due);
        }

        return $q->orderByDesc('tasks.updated_at')->orderByDesc('tasks.id')->limit(min($limit, self::MAX_DEPTH))->get();
    }

    /** @return EloquentCollection<int, Ticket> */
    private function fetchTickets(Builder $q, User $viewer, string $column, int $limit): EloquentCollection
    {
        $statuses = WorkBoardColumns::ticketStatusesFor($column);
        $q->whereRaw($this->tickets->effectiveStatusSql($viewer).' IN ('.implode(',', array_fill(0, count($statuses), '?')).')', $statuses);

        $now = now();
        $q->select([
            'tickets.id', 'tickets.ticket_number', 'tickets.title', 'tickets.status', 'tickets.priority', 'tickets.department_id', 'tickets.created_by_id', 'tickets.assigned_to_id',
            'tickets.expected_resolution_time', 'tickets.resolved_at', 'tickets.task_scope', 'tickets.task_points', 'tickets.estimated_hours', 'tickets.requires_deployment',
            'tickets.created_at', 'tickets.updated_at',
        ]);
        if (! in_array($column, WorkBoardColumns::NOT_OVERDUE, true)) {
            $q->orderByRaw('CASE WHEN tickets.expected_resolution_time IS NOT NULL AND tickets.expected_resolution_time < ? THEN 0 ELSE 1 END', [$now])
                ->orderByRaw("CASE tickets.priority WHEN 'CRITICAL' THEN 3 WHEN 'HIGH' THEN 2 WHEN 'MEDIUM' THEN 1 ELSE 0 END DESC")
                ->orderByRaw('tickets.expected_resolution_time IS NULL')->orderBy('tickets.expected_resolution_time');
        }

        return $q->orderByDesc('tickets.updated_at')->orderByDesc('tickets.id')->limit(min($limit, self::MAX_DEPTH))->get();
    }

    // ── Ordering across the two kinds (the same key family as the SQL above) ──

    /** @return array<int, int|float|string> */
    private function taskSortKey(Task $t, string $column): array
    {
        $tail = [-($t->updated_at?->getTimestamp() ?? 0), -$t->id];
        if (in_array($column, WorkBoardColumns::NOT_OVERDUE, true)) {
            return $tail;
        }
        $due = $t->due_datetime ?? $t->due_date;

        return [$this->taskOverdue($t, now()) ? 0 : 1, -$this->rank(strtolower((string) $t->priority)), $due === null ? 1 : 0, $due?->getTimestamp() ?? 0, ...$tail];
    }

    /** @return array<int, int|float|string> */
    private function ticketSortKey(Ticket $t, string $column): array
    {
        $tail = [-($t->updated_at?->getTimestamp() ?? 0), -$t->id];
        if (in_array($column, WorkBoardColumns::NOT_OVERDUE, true)) {
            return $tail;
        }
        $due = $t->expected_resolution_time;

        return [$due !== null && $due->lt(now()) ? 0 : 1, -$this->rank(strtolower((string) $t->priority)), $due === null ? 1 : 0, $due?->getTimestamp() ?? 0, ...$tail];
    }

    private function rank(string $priority): int
    {
        return ['urgent' => 3, 'critical' => 3, 'high' => 2, 'medium' => 1][$priority] ?? 0;
    }

    // ── Cards ────────────────────────────────────────────────────────────────

    private function taskOverdue(Task $t, $now): bool
    {
        if ($t->due_datetime !== null) {
            return $t->due_datetime->lt($now);
        }

        return $t->due_date !== null && $t->due_date->lt($now->copy()->startOfDay());
    }

    /** Task priorities are low|medium|high|urgent; the boards speak low|medium|high|critical. */
    private function priorityOf(?string $p): string
    {
        $p = strtolower((string) $p);

        return $p === 'urgent' ? 'critical' : (in_array($p, ['low', 'medium', 'high', 'critical'], true) ? $p : 'medium');
    }

    /** @return array<string, mixed> */
    private function taskCard(User $viewer, Task $t, string $column, $now): array
    {
        $isMine = $t->assigned_to !== null && (int) $t->assigned_to === (int) $viewer->id;
        $pending = $isMine && $t->acceptance_status === Task::ACCEPTANCE_PENDING;
        $due = $t->due_datetime ?? $t->due_date;

        return [
            'key' => 'task:'.$t->id,
            'kind' => 'task',
            'id' => $t->id,
            'reference' => null,
            'title' => $t->title,
            'masked' => false,
            'column' => $column,
            'status' => $t->status,
            'acceptance' => $t->acceptance_status,
            'stage' => null,
            'stage_badge' => null,
            'next_step' => null,
            'priority' => $this->priorityOf($t->priority),
            'due_at' => $t->due_datetime ? $t->due_datetime->toIso8601String() : $t->due_date?->toDateString(),
            'is_overdue' => ! in_array($column, WorkBoardColumns::NOT_OVERDUE, true) && $due !== null && $this->taskOverdue($t, $now),
            'assignee' => $t->assignedTo ? ['id' => $t->assignedTo->id, 'name' => $t->assignedTo->name] : null,
            'department' => $t->department ? ['id' => $t->department->id, 'name' => $t->department->name_ar ?: $t->department->name] : null,
            // A task's size, points and hours are already public on the existing board; a ticket's are gated below.
            'scope' => $t->task_scope,
            'points' => $t->points,
            'hours' => $t->estimated_hours,
            'estimate_hidden' => false,
            'is_locked' => $t->locked_at !== null,
            'group_id' => $t->parent_task_id,
            'is_mine' => $isMine,
            'updated_at' => $t->updated_at?->toIso8601String(),
            'capabilities' => [
                'can_open' => $isMine || $this->mayOpenAnyTask($viewer),
                'can_accept' => $pending,
                'can_reject' => $pending,
                'can_complete' => false,
            ],
        ];
    }

    /** The management route `operations/tasks/{task}` is role-gated (role:admin,super_admin,tech_admin,operations_manager), so this mirrors that gate. */
    private function mayOpenAnyTask(User $viewer): bool
    {
        return $viewer->isSuperAdmin() || $viewer->isTechAdmin() || in_array($viewer->role, self::TASK_DETAIL_ROLES, true);
    }

    /** @return array<string, mixed> */
    private function ticketCard(User $viewer, Ticket $t, string $column, $now): array
    {
        $reporterOnly = $this->access->isReporterOnly($viewer, $t);
        $isMine = $this->access->isTicketAssignee($viewer, $t);
        // A submitter who only reported the ticket is told what they are told everywhere else: the internal review steps read IN_PROGRESS.
        $status = $reporterOnly ? (string) TicketReporterView::publicStatus($t->status) : (string) $t->status;
        $stage = ! $reporterOnly ? (WorkBoardColumns::TICKET_STAGE_CARD[$status] ?? null) : null;
        $estimateVisible = $this->access->canSeeInternal($viewer, $t);
        $canOpen = $this->canOpenTicket($viewer, $t);
        // A ticket the viewer cannot open is shown by its place on the board only: its title and number are the reporter's text.
        $masked = ! $canOpen;
        // Lateness is as private as the title: neither a submitter nor a viewer who cannot open the ticket is told the deadline.
        $due = $reporterOnly || $masked ? null : $t->expected_resolution_time;

        return [
            'key' => 'ticket:'.$t->id,
            'kind' => 'ticket',
            'id' => $t->id,
            'reference' => $masked ? null : $t->ticket_number,
            'title' => $masked ? self::MASKED_TITLE : $t->title,
            'masked' => $masked,
            'column' => $column,
            'status' => $status,
            'acceptance' => null,
            'stage' => $stage ? $this->access->workflow($t)['stage'] : null,
            'stage_badge' => $stage['badge'] ?? null,
            'next_step' => $stage['next'] ?? null,
            'priority' => $this->priorityOf($t->priority),
            // A submitter is never told the deadline or the assignee (TicketReporterView::row omits both).
            'due_at' => $due?->toIso8601String(),
            'is_overdue' => ! in_array($column, WorkBoardColumns::NOT_OVERDUE, true) && $due !== null && $due->lt($now),
            'assignee' => ! $reporterOnly && $t->assignee ? ['id' => $t->assignee->id, 'name' => $t->assignee->name] : null,
            'department' => $t->department ? ['id' => $t->department->id, 'name' => $t->department->name_ar ?: $t->department->name] : null,
            // The estimate is internal to managers, global roles and the assignee (TicketAccess::canSeeInternal), exactly as on the ticket itself.
            'scope' => $estimateVisible ? $t->task_scope : null,
            'points' => $estimateVisible ? $t->task_points : null,
            'hours' => $estimateVisible ? $t->estimated_hours : null,
            'estimate_hidden' => ! $estimateVisible,
            'is_locked' => false,
            'group_id' => null,
            'is_mine' => $isMine,
            'updated_at' => $t->updated_at?->toIso8601String(),
            'capabilities' => [
                'can_open' => $canOpen,
                'can_accept' => $isMine && $t->status === 'ASSIGNED',
                'can_reject' => $isMine && $t->status === 'ASSIGNED',
                'can_complete' => $isMine && $t->status === 'IN_PROGRESS',
            ],
        ];
    }

    /**
     * TicketAccess::canView, asked once per department instead of once per card: when the viewer is neither the creator nor the
     * assignee the answer depends on the department alone (canView short-circuits before it), so it is remembered by department.
     */
    private function canOpenTicket(User $viewer, Ticket $t): bool
    {
        if ((int) $t->created_by_id === (int) $viewer->id || $this->access->isTicketAssignee($viewer, $t)) {
            return $this->access->canView($viewer, $t);
        }

        return $this->deptOpen[(int) $t->department_id] ??= $this->access->canView($viewer, $t);
    }
}
