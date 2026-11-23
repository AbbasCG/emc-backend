<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Visibility and filtering for the ticket work queue: ONE implementation used by the list, the
 * statistics, the board and the analytics, so the four can never disagree about which tickets a
 * viewer sees or which a filter matches.
 *
 * Visibility is always applied first and can not be widened by any filter: a user sees the
 * tickets of the departments they may access plus those they created or are assigned to
 * (global roles see all). A filter only ever NARROWS that set.
 */
class TicketQuery
{
    public const STATUSES = ['PENDING_APPROVAL', 'ASSIGNED', 'IN_PROGRESS', 'PENDING_REVIEW', 'APPROVED_FOR_DEPLOY', 'PENDING_VERIFICATION', 'RESOLVED', 'UNRESOLVED', 'REJECTED_BY_ADMIN', 'REJECTED_BY_ASSIGNEE'];
    public const PRIORITIES = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];
    public const CATEGORIES = ['OLD_ISSUE', 'NEW_SUGGESTION'];
    public const SORTS = ['created_at', 'updated_at', 'priority', 'status', 'ticket_number', 'title', 'due', 'last_activity'];
    public const MAX_PER_PAGE = 100;
    public const DEFAULT_PER_PAGE = 20;

    /** "Due soon" for the SLA state: the deadline falls within this many hours. */
    public const DUE_SOON_HOURS = 24;

    /**
     * Filters that the statistics must IGNORE: they are the very subsets the KPI cards select, so
     * counting them while they are active would make every other card read zero.
     */
    public const KPI_TOGGLES = ['status', 'priority', 'overdue', 'unassigned', 'state', 'sla', 'attention', 'mine'];

    /** @var array<int, array<int, int>> */
    private array $led = [];

    public function __construct(private readonly DepartmentAccessService $departments, private readonly TicketAccess $access) {}

    /**
     * Validate and normalize the query string. Multi-value filters accept `a,b` or `a[]=a&a[]=b`.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(Request $request): array
    {
        $input = $request->all();
        foreach (['status', 'priority'] as $multi) {
            if (isset($input[$multi]) && is_string($input[$multi])) {
                $input[$multi] = array_values(array_filter(array_map('trim', explode(',', $input[$multi])), fn ($v) => $v !== ''));
            }
        }
        // The legacy flag keeps working.
        if (! isset($input['overdue']) && isset($input['delayed_only'])) {
            $input['overdue'] = $input['delayed_only'];
        }

        $v = validator($input, [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'array', 'max:10'],
            'status.*' => ['string', Rule::in(self::STATUSES)],
            'priority' => ['sometimes', 'array', 'max:4'],
            'priority.*' => ['string', Rule::in(self::PRIORITIES)],
            'ticket_category' => ['sometimes', 'nullable', Rule::in(self::CATEGORIES)],
            'department_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'unit_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'assigned_to_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'created_by_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'unassigned' => ['sometimes', 'boolean'],
            'mine' => ['sometimes', 'boolean'],
            'attention' => ['sometimes', 'boolean'],
            'overdue' => ['sometimes', 'boolean'],
            'state' => ['sometimes', 'nullable', Rule::in(['open', 'closed'])],
            'sla' => ['sometimes', 'nullable', Rule::in(['overdue', 'due_soon', 'on_track', 'none'])],
            'created_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'created_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:created_from'],
            'updated_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'updated_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:updated_from'],
            'due_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'due_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:due_from'],
            'sort' => ['sometimes', 'nullable', Rule::in(self::SORTS)],
            'dir' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'lite' => ['sometimes', 'boolean'],
        ], [
            'created_to.after_or_equal' => 'تاريخ نهاية الإنشاء يجب ألا يسبق تاريخ البداية.',
            'updated_to.after_or_equal' => 'تاريخ نهاية التحديث يجب ألا يسبق تاريخ البداية.',
            'due_to.after_or_equal' => 'تاريخ نهاية الاستحقاق يجب ألا يسبق تاريخ البداية.',
        ]);

        return $v->validate();
    }

    /** Tickets this user may see, before any filter. */
    public function visible(User $user): Builder
    {
        $query = Ticket::query();

        if (! $this->departments->isGlobal($user)) {
            $ids = $this->departments->getAccessibleDepartmentIds($user);
            $query->where(function ($q) use ($ids, $user) {
                $q->whereIn('tickets.department_id', $ids)
                    ->orWhere('tickets.created_by_id', $user->id)
                    ->orWhere('tickets.assigned_to_id', $user->id);
            });
        }

        return $query;
    }

    /**
     * Narrow a query by validated filters.
     *
     * @param  array<string, mixed>  $f
     * @param  array<int, string>  $ignore  filter keys to skip (see KPI_TOGGLES)
     */
    public function filter(Builder $query, array $f, User $user, array $ignore = []): Builder
    {
        $use = fn (string $key) => ! in_array($key, $ignore, true) && array_key_exists($key, $f) && $f[$key] !== null && $f[$key] !== '' && $f[$key] !== [];

        if ($use('status')) {
            // Through the viewer's effective status: a submitter who only reported a ticket sees PENDING_REVIEW as
            // IN_PROGRESS, so filtering IN_PROGRESS includes it and filtering PENDING_REVIEW cannot reveal it.
            $query->whereRaw($this->effectiveStatusSql($user).' IN ('.implode(',', array_fill(0, count($f['status']), '?')).')', array_values($f['status']));
        }
        if ($use('priority')) {
            $query->whereIn('tickets.priority', $f['priority']);
        }
        foreach (['ticket_category', 'department_id', 'unit_id', 'assigned_to_id', 'created_by_id'] as $col) {
            if ($use($col)) {
                $query->where("tickets.{$col}", $f[$col]);
            }
        }

        if ($use('unassigned') && $f['unassigned']) {
            $query->whereNull('tickets.assigned_to_id');
        }
        if ($use('mine') && $f['mine']) {
            $query->where('tickets.assigned_to_id', $user->id);
        }
        if ($use('attention') && $f['attention']) {
            $this->needsMyAttention($query, $user);
        }

        if ($use('state')) {
            $f['state'] === 'closed'
                ? $query->whereIn('tickets.status', TicketAccess::CLOSED_STATUSES)
                : $query->whereNotIn('tickets.status', TicketAccess::CLOSED_STATUSES);
        }

        if ($use('overdue') && $f['overdue']) {
            $this->slaState($query, 'overdue');
        }
        if ($use('sla')) {
            $this->slaState($query, $f['sla']);
        }

        foreach (['created_at' => 'created', 'updated_at' => 'updated', 'expected_resolution_time' => 'due'] as $col => $key) {
            // whereDate keeps a "to" day inclusive and is independent of the stored time.
            if ($use("{$key}_from")) {
                $query->whereDate("tickets.{$col}", '>=', $f["{$key}_from"]);
            }
            if ($use("{$key}_to")) {
                $query->whereDate("tickets.{$col}", '<=', $f["{$key}_to"]);
            }
        }

        if ($use('search')) {
            $term = trim((string) $f['search']);
            // `!` is the escape character: unlike a backslash it means the same on MySQL and SQLite, so a
            // typed % or _ is searched for literally instead of acting as a wildcard.
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
            $query->where(function ($q) use ($term, $like) {
                foreach (['ticket_number', 'title', 'description', 'created_by_name'] as $i => $col) {
                    $q->{$i === 0 ? 'whereRaw' : 'orWhereRaw'}("tickets.{$col} LIKE ? ESCAPE '!'", [$like]);
                }
                // A bare number is also a ticket id.
                if (ctype_digit($term)) {
                    $q->orWhere('tickets.id', (int) $term);
                }
            });
        }

        return $query;
    }

    /** Open tickets by deadline: overdue, due within DUE_SOON_HOURS, later, or without a deadline. */
    public function slaState(Builder $query, string $state): void
    {
        $open = fn (Builder $q) => $q->whereNotIn('tickets.status', TicketAccess::CLOSED_STATUSES);
        $now = now();

        match ($state) {
            'overdue' => $open($query)->whereNotNull('tickets.expected_resolution_time')->where('tickets.expected_resolution_time', '<', $now),
            'due_soon' => $open($query)->whereBetween('tickets.expected_resolution_time', [$now, $now->copy()->addHours(self::DUE_SOON_HOURS)]),
            'on_track' => $open($query)->where('tickets.expected_resolution_time', '>', $now->copy()->addHours(self::DUE_SOON_HOURS)),
            default => $open($query)->whereNull('tickets.expected_resolution_time'),
        };
    }

    /**
     * Tickets where THIS user is the next actor:
     *  - the assignee of a task that is waiting for them (assigned, or in progress);
     *  - a manager of the department: a ticket waiting for approval, one the assignee declined, or finished work
     *    waiting for the final approval (never one they hold themselves: nobody approves their own work).
     */
    private function needsMyAttention(Builder $query, User $user): void
    {
        $global = $this->departments->isGlobal($user);
        $led = $global ? collect() : $this->departments->getLedDepartmentIds($user);

        $query->where(function ($q) use ($user, $global, $led) {
            // The assignee's turn: accept, work, or verify the change on the live site.
            $q->where(fn ($a) => $a->where('tickets.assigned_to_id', $user->id)->whereIn('tickets.status', ['ASSIGNED', 'IN_PROGRESS', TicketAccess::PENDING_VERIFICATION]));

            // The managers' turn: review finished work, and put the approved deployment tickets on the live site.
            $review = fn ($m) => $m->whereIn('tickets.status', [TicketAccess::PENDING_REVIEW, TicketAccess::APPROVED_FOR_DEPLOY])
                ->whereNotNull('tickets.assigned_to_id')->where('tickets.assigned_to_id', '!=', $user->id);

            if ($global) {
                $q->orWhereIn('tickets.status', ['PENDING_APPROVAL', 'REJECTED_BY_ASSIGNEE'])->orWhere($review);
            } elseif ($led->isNotEmpty()) {
                $q->orWhere(fn ($m) => $m->whereIn('tickets.department_id', $led)->whereIn('tickets.status', ['PENDING_APPROVAL', 'REJECTED_BY_ASSIGNEE']));
                $q->orWhere(fn ($m) => $m->whereIn('tickets.department_id', $led)->where($review));
            }
        });
    }

    /** Apply the requested ordering; always ends with a stable tiebreak on id. */
    public function sort(Builder $query, array $f): Builder
    {
        $dir = ($f['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        match ($f['sort'] ?? 'created_at') {
            'priority' => $query->orderByRaw("CASE tickets.priority WHEN 'CRITICAL' THEN 4 WHEN 'HIGH' THEN 3 WHEN 'MEDIUM' THEN 2 WHEN 'LOW' THEN 1 ELSE 0 END {$dir}"),
            // Tickets without a deadline sort last in either direction.
            'due' => $query->orderByRaw('tickets.expected_resolution_time IS NULL')->orderBy('tickets.expected_resolution_time', $dir),
            'last_activity' => $query->orderBy('last_activity_at', $dir),
            default => $query->orderBy('tickets.'.($f['sort'] ?? 'created_at'), $dir),
        };

        return $query->orderBy('tickets.id', 'desc');
    }

    /**
     * Keep only tickets this viewer handles as STAFF: drop those where they are merely the submitter (they
     * created it and neither lead its department nor are assigned to it). Mirrors TicketAccess::isReporterOnly.
     * Operational views (insights, assignee workload) use it so a reporter is never told who holds their
     * ticket, or that it is overdue: the platform keeps the deadline and the assignee away from reporters.
     */
    public function withoutReporterOnly(Builder $query, User $user): Builder
    {
        if ($this->departments->isGlobal($user)) {
            return $query;
        }

        $led = $this->departments->getLedDepartmentIds($user);

        return $query->where(function ($w) use ($user, $led) {
            $w->whereNull('tickets.created_by_id')
                ->orWhere('tickets.created_by_id', '!=', $user->id)
                ->orWhereIn('tickets.department_id', $led)
                ->orWhere('tickets.assigned_to_id', $user->id);
        });
    }

    /** Adds `last_activity_at` (the newest log row) without loading the log. */
    public function withLastActivity(Builder $query): Builder
    {
        return $query->addSelect([
            'tickets.*',
            'last_activity_at' => TicketActivityLog::query()->selectRaw('MAX(logged_at)')->whereColumn('ticket_id', 'tickets.id'),
        ]);
    }

    /**
     * The status this viewer is TOLD, as SQL. PENDING_REVIEW, APPROVED_FOR_DEPLOY and PENDING_VERIFICATION are internal steps: for a ticket the viewer only
     * reported (TicketAccess::isReporterOnly: created it, leads nothing, is not global, is not its assignee) it reads
     * IN_PROGRESS, exactly like TicketReporterView::publicStatus. For everyone else it is tickets.status. Only
     * integers are interpolated.
     */
    public function effectiveStatusSql(User $user): string
    {
        if ($this->departments->isGlobal($user)) {
            return 'tickets.status';
        }

        $uid = (int) $user->id;
        $led = $this->ledDepartmentIds($user);
        $notLed = $led === [] ? '' : ' AND tickets.department_id NOT IN ('.implode(',', $led).')';

        return "(CASE WHEN tickets.status IN ('".implode("','", TicketAccess::REVIEW_STAGES)."') AND tickets.created_by_id = {$uid} AND (tickets.assigned_to_id IS NULL OR tickets.assigned_to_id <> {$uid}){$notLed} THEN 'IN_PROGRESS' ELSE tickets.status END)";
    }

    /** Departments the user leads, looked up once per request and user. @return array<int, int> */
    public function ledDepartmentIds(User $user): array
    {
        return $this->led[$user->id] ??= $this->departments->getLedDepartmentIds($user)->map(fn ($id) => (int) $id)->all();
    }

    /** Rows per EFFECTIVE status (see effectiveStatusSql), as [status => count]. */
    public function countByStatus(Builder $query, User $user): array
    {
        return $query->reorder()->select(DB::raw($this->effectiveStatusSql($user).' AS status_key'), DB::raw('COUNT(*) as c'))
            ->groupBy('status_key')->pluck('c', 'status_key')->map(fn ($c) => (int) $c)->all();
    }

    /** Count of a query's rows grouped by one column, as [value => count]. */
    public function countBy(Builder $query, string $column): array
    {
        return $query->reorder()->select("tickets.{$column}", DB::raw('COUNT(*) as c'))->groupBy("tickets.{$column}")->pluck('c', $column)->map(fn ($c) => (int) $c)->all();
    }
}
