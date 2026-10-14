<?php

namespace App\Services;

use App\Models\Department;
use App\Models\TeamMember;
use App\Models\Ticket;
use App\Models\TicketActivityLog;
use App\Models\User;
use App\Support\SmartOperationalRuleConfig;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only operational intelligence for the ticket work queue.
 *
 * Everything here is derived from rows that already exist (tickets and their activity log). It
 * never changes a ticket, assigns anyone, or sends a message, and it invents no history: a
 * timestamp that was not recorded is simply not part of a figure, and each figure reports the
 * sample it was computed from.
 *
 *  - stats       counts by aggregate queries; timings over a bounded recent sample
 *  - board       per-status columns, a few cards each
 *  - analytics   volume per day, distributions, workload, resolution trend
 *  - insights    per-ticket conditions that reuse the Smart Operational Rules thresholds
 *  - duplicates  title word-overlap hints (never merges anything)
 *  - suggestions eligible assignees ordered by open workload (advisory)
 *
 * Visibility and filtering come from TicketQuery, so every figure is scoped to what the viewer
 * is allowed to see.
 */
class TicketOpsService
{
    /** Window for the timing averages. */
    public const TIMING_DAYS = 30;
    /** Most rows read to compute an average; the response says when a sample was cut. */
    private const SAMPLE_LIMIT = 2000;
    /** Open ticket with no logged activity for this long is "inactive" (a page-level reading aid, not a Smart Rule). */
    public const INACTIVE_HOURS = 72;
    private const INSIGHT_LIMIT = 20;

    private const STATUS_ORDER = ['PENDING_APPROVAL', 'ASSIGNED', 'IN_PROGRESS', 'RESOLVED', 'UNRESOLVED', 'REJECTED_BY_ADMIN', 'REJECTED_BY_ASSIGNEE'];
    private const PRIORITY_LABELS = ['LOW' => 'منخفضة', 'MEDIUM' => 'متوسطة', 'HIGH' => 'عالية', 'CRITICAL' => 'حرجة'];
    private const STOPWORDS = ['في', 'من', 'على', 'الى', 'عن', 'مع', 'هذا', 'هذه', 'ذلك', 'التي', 'الذي', 'لا', 'ما', 'او', 'ان', 'كل'];

    public function __construct(
        private readonly TicketQuery $query,
        private readonly TicketAccess $access,
        private readonly DepartmentAccessService $departments,
    ) {}

    // ── Statistics ───────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $f  validated filters
     * @return array<string, mixed>
     */
    public function stats(User $user, array $f): array
    {
        $now = now();
        $closed = $this->closedSql();
        $open = "tickets.status NOT IN {$closed}";

        $row = $this->scope($user, $f)->reorder()->selectRaw(
            "COUNT(*) AS total,
             SUM(CASE WHEN tickets.status = 'PENDING_APPROVAL' THEN 1 ELSE 0 END) AS pending_approval,
             SUM(CASE WHEN tickets.status = 'ASSIGNED' THEN 1 ELSE 0 END) AS assigned,
             SUM(CASE WHEN tickets.status = 'IN_PROGRESS' THEN 1 ELSE 0 END) AS in_progress,
             SUM(CASE WHEN tickets.status = 'RESOLVED' THEN 1 ELSE 0 END) AS resolved,
             SUM(CASE WHEN tickets.status = 'UNRESOLVED' THEN 1 ELSE 0 END) AS unresolved,
             SUM(CASE WHEN tickets.status = 'REJECTED_BY_ADMIN' THEN 1 ELSE 0 END) AS rejected_by_admin,
             SUM(CASE WHEN tickets.status = 'REJECTED_BY_ASSIGNEE' THEN 1 ELSE 0 END) AS rejected_by_assignee,
             SUM(CASE WHEN {$open} THEN 1 ELSE 0 END) AS open_total,
             SUM(CASE WHEN {$open} AND tickets.expected_resolution_time IS NOT NULL AND tickets.expected_resolution_time < ? THEN 1 ELSE 0 END) AS overdue,
             SUM(CASE WHEN {$open} AND tickets.expected_resolution_time >= ? AND tickets.expected_resolution_time <= ? THEN 1 ELSE 0 END) AS due_soon,
             SUM(CASE WHEN {$open} AND tickets.assigned_to_id IS NULL THEN 1 ELSE 0 END) AS unassigned,
             SUM(CASE WHEN {$open} AND tickets.priority IN ('HIGH','CRITICAL') THEN 1 ELSE 0 END) AS urgent_open,
             SUM(CASE WHEN tickets.created_at >= ? THEN 1 ELSE 0 END) AS new_24h",
            [$now, $now, $now->copy()->addHours(TicketQuery::DUE_SOON_HOURS), $now->copy()->subDay()],
        )->first();

        $n = fn (string $k) => (int) ($row->{$k} ?? 0);

        return [
            'counts' => [
                'total' => $n('total'),
                'pending_approval' => $n('pending_approval'),
                'assigned' => $n('assigned'),
                'in_progress' => $n('in_progress'),
                'resolved' => $n('resolved'),
                'unresolved' => $n('unresolved'),
                'rejected_by_admin' => $n('rejected_by_admin'),
                'rejected_by_assignee' => $n('rejected_by_assignee'),
                'open' => $n('open_total'),
                'closed' => $n('total') - $n('open_total'),
                'overdue' => $n('overdue'),
                'due_soon' => $n('due_soon'),
                'unassigned' => $n('unassigned'),
                'high_priority' => $n('urgent_open'),
                'new_24h' => $n('new_24h'),
            ],
            'timings' => $this->timings($user, $f),
            'scope' => [
                'ignored_filters' => TicketQuery::KPI_TOGGLES,
                'note' => 'الأرقام تتبع نطاق الرؤية والبحث والإدارة والتواريخ؛ ولا تتأثر بفلاتر الحالة والأولوية والتأخر التي تختارها البطاقات نفسها.',
                'due_soon_hours' => TicketQuery::DUE_SOON_HOURS,
            ],
            'generated_at' => $now->toIso8601String(),
        ];
    }

    /**
     * Average time to the first management decision (approval or admin rejection) and to resolution,
     * for tickets in the last TIMING_DAYS. Both come from timestamps that were recorded; tickets
     * without them are left out, and the sample sizes are reported.
     *
     * @return array<string, mixed>
     */
    private function timings(User $user, array $f): array
    {
        $since = now()->subDays(self::TIMING_DAYS);

        $decided = $this->scope($user, $f)->reorder()->where('tickets.created_at', '>=', $since)
            ->select('tickets.id', 'tickets.created_at')
            ->selectSub(
                TicketActivityLog::query()->selectRaw('MIN(logged_at)')->whereColumn('ticket_id', 'tickets.id')->whereIn('action', ['APPROVED_AND_ASSIGNED', 'REJECTED_BY_ADMIN']),
                'decided_at',
            )->limit(self::SAMPLE_LIMIT)->get();

        $response = $decided->filter(fn ($t) => $t->decided_at !== null)
            ->map(fn ($t) => max(0, CarbonImmutable::parse($t->created_at)->diffInSeconds(CarbonImmutable::parse($t->decided_at), true)));

        $resolved = $this->scope($user, $f)->reorder()->where('tickets.status', 'RESOLVED')->whereNotNull('tickets.resolved_at')
            ->where('tickets.resolved_at', '>=', $since)->select('tickets.created_at', 'tickets.resolved_at')->limit(self::SAMPLE_LIMIT)->get()
            ->map(fn ($t) => max(0, $t->created_at->diffInSeconds($t->resolved_at, true)));

        return [
            'window_days' => self::TIMING_DAYS,
            'avg_response_hours' => $response->isEmpty() ? null : round($response->avg() / 3600, 1),
            'response_sample' => $response->count(),
            'avg_resolution_hours' => $resolved->isEmpty() ? null : round($resolved->avg() / 3600, 1),
            'resolution_sample' => $resolved->count(),
            'truncated' => $decided->count() >= self::SAMPLE_LIMIT || $resolved->count() >= self::SAMPLE_LIMIT,
            'definitions' => [
                'response' => 'من إنشاء التذكرة إلى أول قرار إداري مسجَّل (اعتماد أو رفض).',
                'resolution' => 'من إنشاء التذكرة إلى وقت حلّها المسجَّل، للتذاكر المحلولة.',
            ],
        ];
    }

    // ── Board ────────────────────────────────────────────────────────────────

    /**
     * Columns by status: the exact count of each, and the first $perColumn cards.
     *
     * @param  array<string, mixed>  $f
     * @return array<int, array<string, mixed>>
     */
    public function board(User $user, array $f, int $perColumn = 8): array
    {
        $counts = $this->query->countBy($this->scope($user, $f, ignore: []), 'status');
        $wanted = ! empty($f['status']) ? array_values(array_intersect(self::STATUS_ORDER, $f['status'])) : self::STATUS_ORDER;

        $columns = [];
        foreach ($wanted as $status) {
            $cards = $this->query->withLastActivity($this->scope($user, $f, ignore: [])->where('tickets.status', $status))
                ->with(['department:id,name,name_ar', 'assignee:id,name'])
                ->orderByRaw("CASE tickets.priority WHEN 'CRITICAL' THEN 4 WHEN 'HIGH' THEN 3 WHEN 'MEDIUM' THEN 2 ELSE 1 END DESC")
                ->orderBy('tickets.created_at', 'desc')->limit($perColumn)->get();

            $columns[] = [
                'status' => $status,
                'count' => (int) ($counts[$status] ?? 0),
                'tickets' => $cards->map(fn (Ticket $t) => $this->card($user, $t))->values()->all(),
            ];
        }

        return $columns;
    }

    /** @return array<string, mixed> */
    private function card(User $user, Ticket $t): array
    {
        // A viewer who is only the submitter sees the ticket's own facts, never its assignee, deadline or actions.
        if ($this->access->isReporterOnly($user, $t)) {
            return [
                'id' => $t->id, 'ticket_number' => $t->ticket_number, 'title' => $t->title, 'ticket_category' => $t->ticket_category,
                'priority' => $t->priority, 'status' => $t->status,
                'department' => $t->department ? ['id' => $t->department->id, 'name_ar' => $t->department->name_ar ?: $t->department->name] : null,
                'assignee' => null, 'expected_resolution_time' => null, 'is_delayed' => false,
                'created_at' => $t->created_at?->toIso8601String(), 'capabilities' => $this->access->capabilities($user, $t),
            ];
        }

        return [
            'id' => $t->id,
            'ticket_number' => $t->ticket_number,
            'title' => $t->title,
            'ticket_category' => $t->ticket_category,
            'priority' => $t->priority,
            'status' => $t->status,
            'department' => $t->department ? ['id' => $t->department->id, 'name_ar' => $t->department->name_ar ?: $t->department->name] : null,
            'assignee' => $t->assignee ? ['id' => $t->assignee->id, 'name' => $t->assignee->name] : null,
            'expected_resolution_time' => $t->expected_resolution_time?->toIso8601String(),
            'is_delayed' => $t->is_delayed,
            'created_at' => $t->created_at?->toIso8601String(),
            'capabilities' => $this->access->capabilities($user, $t),
        ];
    }

    // ── Analytics ────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public function analytics(User $user, array $f, int $days): array
    {
        $since = now()->subDays($days - 1)->startOfDay();
        $created = $this->scope($user, $f)->reorder()->where('tickets.created_at', '>=', $since);

        $perDay = fn ($q, string $col) => (clone $q)->selectRaw("DATE(tickets.{$col}) AS d, COUNT(*) AS c")->groupBy('d')->pluck('c', 'd');
        $createdPerDay = $perDay($created, 'created_at');
        $resolvedPerDay = $perDay($this->scope($user, $f)->reorder()->where('tickets.status', 'RESOLVED')->where('tickets.resolved_at', '>=', $since), 'resolved_at');

        $volume = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $since->copy()->addDays($i)->toDateString();
            $volume[] = ['date' => $d, 'created' => (int) ($createdPerDay[$d] ?? 0), 'resolved' => (int) ($resolvedPerDay[$d] ?? 0)];
        }

        $openScope = fn () => $this->scope($user, $f)->reorder()->whereNotIn('tickets.status', TicketAccess::CLOSED_STATUSES);

        $byDepartment = $openScope()->join('departments', 'departments.id', '=', 'tickets.department_id')
            ->selectRaw('tickets.department_id AS id, departments.name_ar AS name_ar, departments.name AS name, COUNT(*) AS open_count, SUM(CASE WHEN tickets.expected_resolution_time IS NOT NULL AND tickets.expected_resolution_time < ? THEN 1 ELSE 0 END) AS overdue_count', [now()])
            ->groupBy('tickets.department_id', 'departments.name_ar', 'departments.name')->orderByDesc('open_count')->limit(12)->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name_ar ?: $r->name, 'open' => (int) $r->open_count, 'overdue' => (int) $r->overdue_count])->all();

        $assignees = $this->query->withoutReporterOnly($openScope(), $user)->whereNotNull('tickets.assigned_to_id')->join('users', 'users.id', '=', 'tickets.assigned_to_id')
            ->selectRaw('tickets.assigned_to_id AS id, users.name AS name, COUNT(*) AS open_count')->groupBy('tickets.assigned_to_id', 'users.name')
            ->orderByDesc('open_count')->limit(10)->get()->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'open' => (int) $r->open_count])->all();

        return [
            'window' => ['days' => $days, 'from' => $since->toDateString(), 'to' => now()->toDateString()],
            'volume' => $volume,
            'by_status' => $this->distribution($this->query->countBy(clone $created, 'status'), self::STATUS_ORDER),
            'by_priority' => $this->distribution($this->query->countBy(clone $created, 'priority'), array_keys(self::PRIORITY_LABELS)),
            'department_workload' => $byDepartment,
            'assignment' => ['top_assignees' => $assignees, 'unassigned_open' => (clone $openScope())->whereNull('tickets.assigned_to_id')->count()],
            'resolution_trend' => $this->resolutionTrend($user, $f, $since),
            'overdue_now' => $this->overdueBuckets($openScope()),
            'notes' => [
                'distribution' => 'التوزيع لتذاكر أُنشئت داخل النافذة الزمنية.',
                'workload' => 'العبء يخص التذاكر المفتوحة الآن، لا داخل النافذة.',
                'overdue' => 'التأخر لحظي: لا تُحفظ لقطات تاريخية للتأخر، لذا لا يوجد منحنى تاريخي له.',
            ],
        ];
    }

    /** @return array<int, array{key: string, count: int}> */
    private function distribution(array $counts, array $order): array
    {
        return array_map(fn ($k) => ['key' => $k, 'count' => (int) ($counts[$k] ?? 0)], $order);
    }

    /** Average hours to resolve, per ISO week, for tickets resolved inside the window (bounded sample). */
    private function resolutionTrend(User $user, array $f, CarbonImmutable|\Illuminate\Support\Carbon $since): array
    {
        return $this->scope($user, $f)->reorder()->where('tickets.status', 'RESOLVED')->where('tickets.resolved_at', '>=', $since)
            ->select('tickets.created_at', 'tickets.resolved_at')->limit(self::SAMPLE_LIMIT)->get()
            ->groupBy(fn ($t) => $t->resolved_at->copy()->startOfWeek()->toDateString())->sortKeys()
            ->map(fn (Collection $g, string $week) => [
                'week_start' => $week,
                'resolved' => $g->count(),
                'avg_hours' => round($g->avg(fn ($t) => max(0, $t->created_at->diffInSeconds($t->resolved_at, true))) / 3600, 1),
            ])->values()->all();
    }

    /** @return array<int, array{key: string, label: string, count: int}> */
    private function overdueBuckets($openQuery): array
    {
        $rows = $openQuery->whereNotNull('tickets.expected_resolution_time')->where('tickets.expected_resolution_time', '<', now())
            ->select('tickets.expected_resolution_time')->limit(self::SAMPLE_LIMIT)->get();

        $buckets = ['under_1d' => 0, 'd1_3' => 0, 'd3_7' => 0, 'over_7d' => 0];
        foreach ($rows as $t) {
            $hours = $t->expected_resolution_time->diffInHours(now(), true);
            $buckets[$hours < 24 ? 'under_1d' : ($hours < 72 ? 'd1_3' : ($hours < 168 ? 'd3_7' : 'over_7d'))]++;
        }

        $labels = ['under_1d' => 'أقل من يوم', 'd1_3' => '1–3 أيام', 'd3_7' => '3–7 أيام', 'over_7d' => 'أكثر من أسبوع'];

        return array_map(fn ($k) => ['key' => $k, 'label' => $labels[$k], 'count' => $buckets[$k]], array_keys($buckets));
    }

    // ── Insights ─────────────────────────────────────────────────────────────

    /**
     * Tickets that deserve a look now, from conditions the platform already defines: the Smart
     * Operational Rules thresholds for "unassigned too long" and "overdue", plus two plain
     * workflow signals (a declined assignment, a high-priority ticket nobody owns). One entry per
     * ticket, the most severe reason wins. Read-only: it proposes, a person acts.
     *
     * @param  array<string, mixed>  $f
     * @return array<int, array<string, mixed>>
     */
    public function insights(User $user, array $f): array
    {
        $cfg = app(SmartOperationalRuleConfig::class);
        $unassignedRule = $cfg->rule('ticket_unassigned_too_long');
        $overdueRule = $cfg->rule('ticket_overdue');
        $now = CarbonImmutable::now();
        $closed = TicketAccess::CLOSED_STATUSES;

        $base = fn () => $this->query->withLastActivity($this->query->withoutReporterOnly($this->scope($user, $f), $user)->whereNotIn('tickets.status', $closed));
        $found = [];
        $push = function (Ticket $t, string $rule, string $severity, int $score, string $title, string $reason, $since, string $action, ?array $priority = null) use (&$found) {
            if (isset($found[$t->id]) && $found[$t->id]['score'] >= $score) {
                return;
            }
            $found[$t->id] = [
                'ticket_id' => $t->id,
                'ticket_number' => $t->ticket_number,
                'ticket_title' => $t->title,
                'rule' => $rule,
                'severity' => $severity,
                'severity_label' => SmartOperationalRuleConfig::SEVERITY_LABELS[$severity] ?? $severity,
                'score' => $score,
                'title' => $title,
                'reason' => $reason,
                'since' => $since ? CarbonImmutable::parse($since)->toIso8601String() : null,
                'suggested_action' => $action,
                'suggested_priority' => $priority,
                'url' => '/dashboard/tickets/'.$t->id,
            ];
        };

        // Overdue: past the deadline, or (no deadline) older than the rule's threshold.
        if (($overdueRule['enabled'] ?? true) !== false && $overdueRule !== []) {
            $cut = $now->subHours((int) $overdueRule['threshold_hours']);
            $base()->whereNotNull('tickets.assigned_to_id')
                ->where(fn ($q) => $q->where('tickets.expected_resolution_time', '<', $now)->orWhere(fn ($q2) => $q2->whereNull('tickets.expected_resolution_time')->where('tickets.created_at', '<', $cut)))
                ->orderByRaw('COALESCE(tickets.expected_resolution_time, tickets.created_at)')->limit(self::INSIGHT_LIMIT)->get()
                ->each(function (Ticket $t) use ($push, $overdueRule, $now) {
                    $since = $t->expected_resolution_time ?? $t->created_at;
                    $hours = (int) $since->diffInHours($now, true);
                    $critical = $hours >= (int) $overdueRule['critical_after_hours'];
                    $push($t, 'ticket_overdue', $critical ? 'critical' : 'high', ($critical ? 90 : 78) + min(9, intdiv($hours, 24)), 'تذكرة متأخرة',
                        'تجاوزت موعد حلّها منذ '.$this->age($hours).'.', $since, 'تواصل مع المكلف أو أعد توجيه التذكرة.',
                        $critical ? $this->raise($t->priority, 'HIGH', 'متأخرة '.$this->age($hours).' عن موعدها') : null);
                });
        }

        // A declined assignment is waiting for a manager.
        $base()->where('tickets.status', 'REJECTED_BY_ASSIGNEE')->orderBy('tickets.updated_at')->limit(self::INSIGHT_LIMIT)->get()
            ->each(fn (Ticket $t) => $push($t, 'assignment_declined', 'high', 80, 'اعتذر المكلف عن التذكرة', 'لا مكلَّف بها حاليًا وتنتظر إعادة التوجيه.', $t->updated_at, 'اختر مكلفًا بديلًا وأعد التوجيه.'));

        // High priority and nobody owns it.
        $base()->whereNull('tickets.assigned_to_id')->whereIn('tickets.priority', ['HIGH', 'CRITICAL'])->orderBy('tickets.created_at')->limit(self::INSIGHT_LIMIT)->get()
            ->each(fn (Ticket $t) => $push($t, 'high_priority_unowned', $t->priority === 'CRITICAL' ? 'critical' : 'high', $t->priority === 'CRITICAL' ? 92 : 76, 'أولوية '.self::PRIORITY_LABELS[$t->priority].' بلا مكلف',
                $t->status === 'PENDING_APPROVAL' ? 'بانتظار الاعتماد ولم يُحدَّد مكلف بعد.' : 'لا مكلَّف بها.', $t->created_at, $t->status === 'PENDING_APPROVAL' ? 'اعتمد التذكرة وحدّد المكلف.' : 'حدّد مكلفًا للتذكرة.'));

        // Unassigned for longer than the Smart Rule threshold.
        if (($unassignedRule['enabled'] ?? true) !== false && $unassignedRule !== []) {
            $cut = $now->subHours((int) $unassignedRule['threshold_hours']);
            $base()->whereNull('tickets.assigned_to_id')->where('tickets.created_at', '<', $cut)->orderBy('tickets.created_at')->limit(self::INSIGHT_LIMIT)->get()
                ->each(function (Ticket $t) use ($push, $now) {
                    $hours = (int) $t->created_at->diffInHours($now, true);
                    $waiting = $t->status === 'PENDING_APPROVAL';
                    $push($t, 'ticket_unassigned_too_long', 'high', 75 + min(14, intdiv($hours, 12)), $waiting ? 'بانتظار الاعتماد منذ مدة' : 'تذكرة بلا مكلف',
                        ($waiting ? 'تنتظر قرار الاعتماد منذ ' : 'بلا مكلف منذ ').$this->age($hours).'.', $t->created_at, $waiting ? 'راجع التذكرة واعتمدها أو ارفضها.' : 'عيّنها للفريق المناسب.');
                });
        }

        // In the hands of an assignee but silent.
        $silentCut = $now->subHours(self::INACTIVE_HOURS);
        $base()->whereIn('tickets.status', ['ASSIGNED', 'IN_PROGRESS'])->whereNotNull('tickets.assigned_to_id')
            ->whereRaw('COALESCE((SELECT MAX(logged_at) FROM ticket_activity_logs WHERE ticket_id = tickets.id), tickets.created_at) < ?', [$silentCut])
            ->orderBy('tickets.updated_at')->limit(self::INSIGHT_LIMIT)->get()
            ->each(function (Ticket $t) use ($push, $now) {
                $last = $t->last_activity_at ? CarbonImmutable::parse($t->last_activity_at) : $t->created_at;
                $push($t, 'inactive', 'medium', 55 + min(10, intdiv((int) $last->diffInHours($now, true), 24)), 'لا نشاط على التذكرة',
                    'لم يُسجَّل أي نشاط منذ '.$this->age((int) $last->diffInHours($now, true)).'.', $last, 'اطلب تحديثًا من المكلف.');
            });

        $list = array_values($found);
        usort($list, fn ($a, $b) => [$b['score'], $b['since']] <=> [$a['score'], $a['since']]);

        return array_slice($list, 0, self::INSIGHT_LIMIT);
    }

    /** A priority suggestion, or null when the ticket is already at or above the target. */
    private function raise(string $current, string $target, string $because): ?array
    {
        $rank = ['LOW' => 1, 'MEDIUM' => 2, 'HIGH' => 3, 'CRITICAL' => 4];
        if (($rank[$current] ?? 0) >= $rank[$target]) {
            return null;
        }

        return ['value' => $target, 'label' => self::PRIORITY_LABELS[$target], 'current' => $current, 'reason' => $because];
    }

    private function age(int $hours): string
    {
        if ($hours < 1) {
            return 'أقل من ساعة';
        }
        if ($hours < 48) {
            return $hours.' ساعة';
        }

        return intdiv($hours, 24).' يوم';
    }

    // ── Possible duplicates ──────────────────────────────────────────────────

    /**
     * Recent tickets of the same department and category whose titles share most of their words. A
     * hint for a person to check; nothing is merged, closed or linked. Bounded: at most 150 recent
     * candidates, no full-table scan.
     *
     * @return array<int, array<string, mixed>>
     */
    public function possibleDuplicates(User $user, Ticket $ticket): array
    {
        $mine = $this->tokens((string) $ticket->title);
        if (count($mine) < 2) {
            return [];
        }

        $candidates = $this->query->visible($user)
            ->where('tickets.id', '!=', $ticket->id)
            ->where('tickets.department_id', $ticket->department_id)
            ->where('tickets.ticket_category', $ticket->ticket_category)
            ->where('tickets.created_at', '>=', now()->subDays(90))
            ->orderByDesc('tickets.created_at')->limit(150)
            ->get(['tickets.id', 'tickets.ticket_number', 'tickets.title', 'tickets.status', 'tickets.created_at']);

        $out = [];
        foreach ($candidates as $c) {
            $theirs = $this->tokens((string) $c->title);
            $shared = array_values(array_intersect($mine, $theirs));
            $union = count(array_unique(array_merge($mine, $theirs)));
            if ($union === 0 || count($shared) < 2) {
                continue;
            }
            $overlap = count($shared) / $union;
            if ($overlap < 0.5) {
                continue;
            }
            $out[] = [
                'id' => $c->id, 'ticket_number' => $c->ticket_number, 'title' => $c->title, 'status' => $c->status,
                'created_at' => $c->created_at?->toIso8601String(),
                'shared_words' => $shared,
                'word_overlap_percent' => (int) round($overlap * 100),
                'basis' => 'تشابه كلمات العنوان فقط، وليس تحليلًا لمحتوى التذكرة.',
            ];
        }

        usort($out, fn ($a, $b) => $b['word_overlap_percent'] <=> $a['word_overlap_percent']);

        return array_slice($out, 0, 5);
    }

    /** @return array<int, string> normalized significant words of a title */
    private function tokens(string $title): array
    {
        $s = mb_strtolower($title);
        $s = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s); // tashkeel, tatweel
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);
        $s = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s);

        $words = [];
        foreach (preg_split('/\s+/u', trim($s)) ?: [] as $w) {
            if (mb_strlen($w) > 3 && str_starts_with($w, 'ال')) {
                $w = mb_substr($w, 2);
            }
            if (mb_strlen($w) >= 2 && ! in_array($w, self::STOPWORDS, true)) {
                $words[] = $w;
            }
        }

        return array_values(array_unique($words));
    }

    // ── Assignment suggestions ───────────────────────────────────────────────

    /**
     * Who could take this ticket, least loaded first. Eligibility is exactly what the approve and
     * reassign endpoints accept (TicketAccess::assigneeEligible), so a suggestion can never be
     * refused for eligibility. The only signal used is the count of open tickets each person
     * already holds; availability or skills are not known to the system and are not guessed.
     *
     * @return array<string, mixed>
     */
    public function assignmentSuggestions(Ticket $ticket): array
    {
        $deptId = (int) $ticket->department_id;
        $unitId = $ticket->unit_id ? (int) $ticket->unit_id : null;

        $ids = collect();
        if ($unitId) {
            $ids = TeamMember::where('department_id', $deptId)->where('unit_id', $unitId)->where('status', 'active')->pluck('user_id');
        } else {
            $dept = Department::find($deptId);
            $ids = collect([$dept?->leader_id])->filter()
                ->merge(TeamMember::where('department_id', $deptId)->where('status', 'active')->pluck('user_id'))
                ->merge(DB::table('user_departments')->where('department_id', $deptId)->pluck('user_id'));
        }
        $ids = $ids->unique()->values();

        // Every id above comes from the very sources assigneeEligible() accepts: an active member of the
        // ticket's unit (and department), or the department's leader / team member / member. Re-asking the
        // question per person cost one query each, so eligibility is guaranteed by construction here and
        // pinned by a test that sends every suggestion through the real reassign endpoint.
        $users = User::query()->where('is_active', true)->whereIn('id', $ids)->orderBy('name')->limit(200)->get(['id', 'name', 'role']);

        $load = $users->isEmpty() ? collect() : Ticket::query()->whereIn('assigned_to_id', $users->pluck('id'))
            ->whereNotIn('status', TicketAccess::CLOSED_STATUSES)->selectRaw('assigned_to_id, COUNT(*) AS c')->groupBy('assigned_to_id')->pluck('c', 'assigned_to_id');

        $rows = $users->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'open_tickets' => (int) ($load[$u->id] ?? 0),
            'is_current' => $u->id === $ticket->assigned_to_id,
        ])->sort(fn ($a, $b) => [$a['open_tickets'], $a['name']] <=> [$b['open_tickets'], $b['name']])->values();

        $rows = $rows->map(fn ($r, $i) => $r + [
            'recommended' => $i === 0 && $rows->count() > 1 && ! $r['is_current'],
            'reason' => $unitId ? 'عضو فعّال في وحدة التذكرة' : 'ضمن فريق الإدارة المعنية',
        ])->all();

        return [
            'basis' => 'ترتيب بحسب عدد التذاكر المفتوحة المسندة لكل شخص (الأقل أولًا). لا تتوفر بيانات عن التفرغ أو المهارات، فلا تُستخدم.',
            'scope' => $unitId ? 'unit' : 'department',
            'candidates' => $rows,
            'message' => $rows === [] ? 'لا يوجد موظفون مؤهلون للتكليف ضمن نطاق هذه التذكرة.' : null,
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Visibility + filters; `$ignore` defaults to the KPI toggles (so cards stay stable while a card is active).
     *
     * @param  array<string, mixed>  $f
     * @param  array<int, string>|null  $ignore
     */
    private function scope(User $user, array $f, ?array $ignore = null)
    {
        return $this->query->filter($this->query->visible($user), $f, $user, $ignore ?? TicketQuery::KPI_TOGGLES);
    }

    private function closedSql(): string
    {
        return "('".implode("','", TicketAccess::CLOSED_STATUSES)."')";
    }

    /**
     * Raise a suggested priority for display; here so controllers need not know the ladder.
     *
     * @return array<int, array{id: string, label_ar: string}>
     */
    public static function priorityOptions(): array
    {
        return array_map(fn ($k, $v) => ['id' => $k, 'label_ar' => $v], array_keys(self::PRIORITY_LABELS), self::PRIORITY_LABELS);
    }
}
