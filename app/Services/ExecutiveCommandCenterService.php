<?php

namespace App\Services;

use App\Http\Controllers\Admin\EmailSettingsController;
use App\Models\AmbassadorApplication;
use App\Models\ConsultantApplication;
use App\Models\Department;
use App\Models\ExpertApplication;
use App\Models\FinancialRequest;
use App\Models\HrRequest;
use App\Models\LmsSession;
use App\Models\ManualPayment;
use App\Models\Meeting;
use App\Models\NotificationLog;
use App\Models\Payment;
use App\Models\PartnershipRequest;
use App\Models\PlatformNotification;
use App\Models\QualityCorrectiveAction;
use App\Models\QualityIncident;
use App\Models\Registration;
use App\Models\SupportTicket;
use App\Models\Ticket;
use App\Models\User;
use App\Models\VolunteerHrProfile;
use App\Models\VolunteerPointAward;
use App\Models\VolunteerRequest;
use App\Models\WeeklyDepartmentReport;
use App\Support\SmartOperationalRuleConfig;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Private executive follow-up hub: one normalized feed built from many modules.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHAT IT IS (AND IS NOT)
 * ─────────────────────────────────────────────────────────────────────────
 * Read-only. It never sends a notification or an email, never changes a workflow, and
 * never calls another controller. Every collector reads its own module's tables and
 * returns normalized items, each with a source, a priority, a time and an INTERNAL url to
 * the page where the work is actually done.
 *
 * Authorization is NOT decided here. The controller requires the
 * `executive.command_center.view` permission before this class is touched.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * PRIVACY
 * ─────────────────────────────────────────────────────────────────────────
 * Even for the owner, the feed shows enough to follow up and no more. Ticket subjects and
 * reporter names are the only free text; financial requests and payments show a count or a
 * department, never a title, amount or proof; users and registrations are counted, not
 * listed; notification failures show the email TYPE, never the recipient or the SMTP reply;
 * internal notes, reviewer notes, files and tokens are never selected at all.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * FAILURE ISOLATION
 * ─────────────────────────────────────────────────────────────────────────
 * Each collector runs inside its own try/catch. A broken table degrades that source to
 * "unavailable" plus a warning item; the rest of the page still loads.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * COST
 * ─────────────────────────────────────────────────────────────────────────
 * Every collector is bounded: row queries are `limit(PER_COLLECTOR_ROWS)`, everything else is a
 * COUNT over an indexed status/date column filtered to the selected window. No collector
 * loads a relation per row.
 */
class ExecutiveCommandCenterService
{
    public const SECTIONS = [
        'needs_attention' => 'يحتاج انتباه الآن',
        'upcoming' => 'القادم',
        'recent_updates' => 'آخر التحديثات',
        'errors' => 'مشاكل وتنبيهات',
    ];

    /** Filter groups shown on the page; every item belongs to exactly one. */
    public const SOURCES = [
        'smart_rules' => 'التنبيهات الذكية',
        'tickets' => 'التذاكر',
        'meetings' => 'الاجتماعات',
        'education' => 'التعليم',
        'finance' => 'المالية',
        'hr' => 'الموارد البشرية',
        'quality' => 'الجودة',
        'email' => 'البريد',
        'users' => 'المستخدمون',
        'system' => 'النظام',
    ];

    /** One scale across the whole page, base items and smart alerts alike. */
    public const PRIORITIES = SmartOperationalRuleConfig::SEVERITY_LABELS;

    public const RANGES = ['today', 'week', 'month'];

    private const PRIORITY_RANK = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'info' => 4];
    private const PER_COLLECTOR_ROWS = 10;
    private const TICKET_CLOSED = ['RESOLVED', 'REJECTED_BY_ADMIN', 'UNRESOLVED'];

    private CarbonImmutable $now;
    private CarbonImmutable $since;
    private CarbonImmutable $until;
    private ?User $viewer = null;

    /** @var array<int, array<string, mixed>> */
    private array $items = [];

    /** @var array<string, array{available: bool, group: string, count: int, label: string}> */
    private array $health = [];

    /** @var array<int, string> labels of smart rules that failed in the current run */
    private array $pendingFailedRules = [];

    /** @var array<int, array<string, mixed>> per-rule status of the smart engine, for the page */
    private array $smartRules = [];

    public function __construct(private readonly SmartOperationalRulesService $smart) {}

    /**
     * @return array<string, mixed>
     */
    public function build(?User $viewer, string $range = 'today', string $source = 'all', string $priority = 'all', int $perSection = 25): array
    {
        $this->viewer = $viewer;
        $range = in_array($range, self::RANGES, true) ? $range : 'today';
        $this->now = CarbonImmutable::now();
        [$this->since, $this->until] = $this->window($range);
        $this->items = [];
        $this->health = [];
        $this->smartRules = [];
        $this->pendingFailedRules = [];

        foreach ($this->collectors() as $name => [$group, $label, $method]) {
            $this->run($name, $group, $label, fn () => $this->{$method}());
        }

        $all = collect($this->items);
        // Totals come from the full set; the filters only narrow what is listed.
        $summary = [];
        foreach (self::SECTIONS as $key => $_) {
            $summary[$key] = $all->where('section', $key)->count();
        }

        $visible = $all
            ->when($source !== 'all', fn ($c) => $c->filter(fn ($i) => $i['source'] === $source || ($i['module'] ?? null) === $source))
            ->when($priority !== 'all', fn ($c) => $c->where('priority', $priority));

        $sections = [];
        foreach (self::SECTIONS as $key => $title) {
            $rows = $visible->where('section', $key);
            $rows = $key === 'upcoming'
                ? $rows->sortBy(fn ($i) => $i['due_at'] ?? $i['timestamp'])
                : $rows->sort(fn ($x, $y) => [self::PRIORITY_RANK[$x['priority']] ?? 9, $y['priority_score'] ?? 0, $y['timestamp'] ?? ''] <=> [self::PRIORITY_RANK[$y['priority']] ?? 9, $x['priority_score'] ?? 0, $x['timestamp'] ?? '']);

            $sections[] = [
                'key' => $key,
                'title' => $title,
                'total' => $rows->count(),
                'items' => $rows->take($perSection)->map(fn ($i) => $this->publicItem($i))->values()->all(),
            ];
        }

        $sources = $this->sourceRows($all);
        $summary['smart_alerts'] = $all->where('source', 'smart_rules')->where('type', 'operational_alert')->count();
        $active = count(array_filter($sources, fn ($s) => $s['available']));

        return [
            'meta' => [
                'generated_at' => $this->now->toIso8601String(),
                'range' => $range,
                'source' => $source,
                'priority' => $priority,
                'timezone' => (string) config('app.booking_timezone', 'Europe/Amsterdam'),
            ],
            'summary' => $summary + ['sources_active' => $active, 'sources_total' => count($sources)],
            'sections' => $sections,
            'sources' => $sources,
            'smart_rules' => $this->smartRules,
            'capabilities' => ['can_view' => true, 'can_refresh' => true, 'read_only' => true],
        ];
    }

    // ── Plumbing ─────────────────────────────────────────────────────────────

    /** @return array<string, array{0: string, 1: string, 2: string}> name => [group, label, method] */
    private function collectors(): array
    {
        return [
            'tickets' => ['tickets', 'التذاكر والاقتراحات', 'collectTickets'],
            'support_requests' => ['tickets', 'طلبات الدعم', 'collectSupportRequests'],
            'meetings' => ['meetings', 'الاجتماعات', 'collectMeetings'],
            'sessions' => ['education', 'الحصص التعليمية', 'collectSessions'],
            'registrations' => ['education', 'التسجيلات', 'collectRegistrations'],
            'finance_requests' => ['finance', 'الطلبات المالية', 'collectFinanceRequests'],
            'payments' => ['finance', 'المدفوعات', 'collectPayments'],
            'hr_requests' => ['hr', 'طلبات الموارد البشرية', 'collectHrRequests'],
            'applications' => ['hr', 'طلبات الانضمام', 'collectApplications'],
            'impact_points' => ['hr', 'نقاط الأثر', 'collectImpactPoints'],
            'quality' => ['quality', 'الجودة', 'collectQuality'],
            'weekly_reports' => ['quality', 'التقارير الأسبوعية', 'collectWeeklyReports'],
            'email_failures' => ['email', 'فشل الرسائل', 'collectEmailFailures'],
            'users' => ['users', 'المستخدمون', 'collectUsers'],
            'system_health' => ['system', 'صحة النظام', 'collectSystemHealth'],
            'notifications' => ['system', 'الإشعارات', 'collectNotifications'],
            // Last on purpose: it replaces the base items it makes redundant.
            'smart_rules' => ['smart_rules', 'القواعد الذكية', 'collectSmartRules'],
        ];
    }

    private function run(string $name, string $group, string $label, callable $collect): void
    {
        $before = count($this->items);

        try {
            $collect();
            $this->health[$name] = ['available' => true, 'group' => $group, 'count' => count($this->items) - $before, 'label' => $label];
            if ($name === 'smart_rules' && $this->pendingFailedRules !== []) {
                $this->health[$name]['failed'] = $this->pendingFailedRules;
            }
        } catch (Throwable $e) {
            // Drop whatever a half-run collector added, then report the source as unavailable.
            $this->items = array_slice($this->items, 0, $before);
            Log::warning('ExecutiveCommandCenter: collector failed', ['collector' => $name, 'error' => $e->getMessage()]);
            $this->health[$name] = ['available' => false, 'group' => $group, 'count' => 0, 'label' => $label];
            $this->add($group, 'source_unavailable', 'errors', "مصدر غير متاح: {$label}", 'تعذّر تحميل بيانات هذا المصدر؛ بقية المصادر تعمل بشكل طبيعي.', 'high', '/dashboard/tech-admin', 'فتح لوحة التقنية', status: 'unavailable', statusLabel: 'غير متاح', idSuffix: $name);
        }
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} recent lookback start, upcoming horizon end */
    private function window(string $range): array
    {
        $now = CarbonImmutable::now();

        return match ($range) {
            'week' => [$now->subDays(7), $now->addDays(7)],
            'month' => [$now->subDays(30), $now->addDays(30)],
            default => [$now->startOfDay(), $now->addDays(2)],
        };
    }

    private function add(
        string $group,
        string $type,
        string $section,
        string $title,
        string $description,
        string $priority,
        string $url,
        string $action,
        ?string $status = null,
        ?string $statusLabel = null,
        ?string $badge = null,
        $timestamp = null,
        $dueAt = null,
        ?string $actor = null,
        string $idSuffix = '',
    ): void {
        $n = count($this->items);
        $this->items[] = [
            'id' => $type.'-'.($idSuffix !== '' ? $idSuffix : $n),
            'source' => $group,
            'module' => $group,
            'priority_score' => null,
            'type' => $type,
            'section' => $section,
            'title' => $title,
            'description' => $description,
            'status' => $status,
            'status_label' => $statusLabel,
            'priority' => $priority,
            'priority_label' => self::PRIORITIES[$priority] ?? self::PRIORITIES['info'],
            'timestamp' => $timestamp ? CarbonImmutable::parse($timestamp)->toIso8601String() : null,
            'due_at' => $dueAt ? CarbonImmutable::parse($dueAt)->toIso8601String() : null,
            'actor' => $actor ? ['name' => $actor] : null,
            'url' => $url,
            'action_label' => $action,
            'badge' => $badge,
        ];
    }

    /** @param array<string, mixed> $i @return array<string, mixed> */
    private function publicItem(array $i): array
    {
        unset($i['section'], $i['_supersedes'], $i['_entity']);

        return $i;
    }

    /** @return array<int, array<string, mixed>> */
    private function sourceRows($all): array
    {
        $rows = [];
        foreach (self::SOURCES as $key => $label) {
            $mine = array_filter($this->health, fn ($h) => $h['group'] === $key);
            $rows[] = [
                'key' => $key,
                'label' => $label,
                'available' => $mine !== [] && ! in_array(false, array_column($mine, 'available'), true) && ! array_filter(array_map(fn ($h) => $h['failed'] ?? [], $mine)),
                'count' => $all->filter(fn ($i) => $i['type'] !== 'source_unavailable' && ($i['source'] === $key || ($i['module'] ?? null) === $key))->count(),
                'unavailable' => array_values(array_merge(
                    array_map(fn ($h) => $h['label'], array_filter($mine, fn ($h) => ! $h['available'])),
                    ...array_map(fn ($h) => $h['failed'] ?? [], array_values($mine)),
                )),
            ];
        }

        return $rows;
    }

    private function trim(?string $text, int $max = 90): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', (string) $text));

        return mb_strlen($t) > $max ? mb_substr($t, 0, $max - 1).'…' : $t;
    }

    private function meetingStart(Meeting $m): CarbonImmutable
    {
        $tz = (string) config('app.booking_timezone', 'Europe/Amsterdam');

        return CarbonImmutable::parse($m->meeting_date->format('Y-m-d').' '.substr((string) $m->start_time, 0, 8), $tz)->utc();
    }

    // ── Collectors ───────────────────────────────────────────────────────────

    private function collectTickets(): void
    {
        // New and waiting for routing/approval.
        foreach (Ticket::query()->where('status', 'PENDING_APPROVAL')->latest('id')->limit(self::PER_COLLECTOR_ROWS)->get(['id', 'title', 'priority', 'ticket_category', 'created_by_name', 'created_at']) as $t) {
            $suggestion = $t->ticket_category === 'NEW_SUGGESTION';
            $this->add('tickets', 'ticket_new', 'needs_attention', $this->trim($t->title), $suggestion ? 'اقتراح جديد بانتظار الاعتماد.' : 'بلاغ جديد بانتظار الاعتماد والتوجيه.',
                in_array($t->priority, ['CRITICAL', 'HIGH'], true) ? 'critical' : 'high', '/dashboard/tickets/'.$t->id, 'فتح التذكرة',
                'PENDING_APPROVAL', 'بانتظار الاعتماد', $suggestion ? 'اقتراح' : 'جديد', $t->created_at, null, $t->created_by_name, (string) $t->id);
        }

        // Past their expected resolution time.
        foreach (Ticket::query()->whereIn('status', ['ASSIGNED', 'IN_PROGRESS'])->whereNotNull('expected_resolution_time')->where('expected_resolution_time', '<', $this->now)
            ->orderBy('expected_resolution_time')->limit(self::PER_COLLECTOR_ROWS)->get(['id', 'title', 'status', 'expected_resolution_time', 'created_by_name']) as $t) {
            $this->add('tickets', 'ticket_overdue', 'needs_attention', $this->trim($t->title), 'تجاوزت الموعد المتوقع للحل.', 'critical', '/dashboard/tickets/'.$t->id, 'فتح التذكرة',
                $t->status, $t->status === 'ASSIGNED' ? 'مُسندة' : 'قيد المعالجة', 'متأخرة', $t->expected_resolution_time, $t->expected_resolution_time, $t->created_by_name, (string) $t->id);
        }

        // Recently resolved, and suggestions that were taken up.
        foreach (Ticket::query()->where('status', 'RESOLVED')->where('resolved_at', '>=', $this->since)->latest('resolved_at')->limit(self::PER_COLLECTOR_ROWS)->get(['id', 'title', 'ticket_category', 'resolved_at']) as $t) {
            $suggestion = $t->ticket_category === 'NEW_SUGGESTION';
            $this->add('tickets', $suggestion ? 'suggestion_done' : 'ticket_resolved', 'recent_updates', $this->trim($t->title), $suggestion ? 'اقتراح جرى اعتماده وتنفيذه.' : 'تم حل البلاغ.', 'info',
                '/dashboard/tickets/'.$t->id, 'فتح التذكرة', 'RESOLVED', 'تم الحل', $suggestion ? 'اقتراح منفَّذ' : 'محلول', $t->resolved_at, null, null, (string) $t->id);
        }
    }

    private function collectSupportRequests(): void
    {
        $open = SupportTicket::query()->whereNotIn('status', ['resolved', 'closed', 'cancelled'])->where('created_at', '>=', $this->since)->count();
        if ($open > 0) {
            $this->add('tickets', 'support_open', 'needs_attention', "{$open} طلب دعم مفتوح", 'طلبات وردت من نموذج الدعم ولم تُغلق بعد.', 'medium', '/dashboard/support', 'متابعة الطلبات',
                'open', 'مفتوح', null, $this->now, null, null, 'all');
        }
    }

    private function collectMeetings(): void
    {
        $horizonDate = $this->until->toDateString();
        $today = $this->now->toDateString();

        // Upcoming (within the horizon), earliest first.
        $upcoming = Meeting::query()->where('status', 'scheduled')->whereBetween('meeting_date', [$today, $horizonDate])
            ->orderBy('meeting_date')->orderBy('start_time')->limit(self::PER_COLLECTOR_ROWS)->with('department:id,name,name_ar')->get(['id', 'title', 'meeting_date', 'start_time', 'department_id', 'organizer_id']);
        foreach ($upcoming as $m) {
            $start = $this->meetingStart($m);
            if ($start->lt($this->now)) {
                continue;
            }
            $this->add('meetings', 'meeting_upcoming', 'upcoming', $this->trim($m->title), $m->department ? 'اجتماع إدارة '.($m->department->name_ar ?: $m->department->name).'.' : 'اجتماع قادم.', 'medium',
                '/dashboard/department/meeting-lounge/'.$m->id, 'فتح الاجتماع', 'scheduled', 'مجدول', $start->isToday() ? 'اليوم' : null, $m->created_at ?? $this->now, $start, null, (string) $m->id);
        }

        // Past meetings with no report filed yet.
        $missing = Meeting::query()->whereIn('status', ['scheduled', 'completed'])->whereBetween('meeting_date', [$this->since->toDateString(), $today])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('meeting_reports')->whereColumn('meeting_reports.meeting_id', 'meetings.id'))
            ->orderByDesc('meeting_date')->limit(self::PER_COLLECTOR_ROWS)->get(['id', 'title', 'meeting_date', 'start_time']);
        foreach ($missing as $m) {
            $start = $this->meetingStart($m);
            if ($start->gte($this->now)) {
                continue;
            }
            $this->add('meetings', 'meeting_missing_report', 'needs_attention', $this->trim($m->title), 'انتهى الاجتماع ولم يُرفع تقريره بعد.', 'high', '/dashboard/operations/meeting-reports', 'مراجعة التقارير',
                'missing_report', 'بلا تقرير', 'ينقصه تقرير', $start, null, null, (string) $m->id);
        }

        // New reports.
        $reports = DB::table('meeting_reports')->where('created_at', '>=', $this->since)->count();
        if ($reports > 0) {
            $this->add('meetings', 'meeting_reports_new', 'recent_updates', "{$reports} تقرير اجتماع جديد", 'تقارير اجتماعات رُفعت خلال الفترة المحددة.', 'info', '/dashboard/operations/meeting-reports', 'مراجعة التقارير',
                'submitted', 'مُسلَّم', null, $this->now, null, null, 'all');
        }
    }

    private function collectSessions(): void
    {
        $sessions = LmsSession::query()->where('status', LmsSession::STATUS_SCHEDULED)->whereBetween('session_date', [$this->now->toDateString(), $this->until->toDateString()])
            ->orderBy('session_date')->orderBy('start_time')->limit(self::PER_COLLECTOR_ROWS)->get(['id', 'title', 'session_date', 'start_time', 'instructor_id']);
        $tz = (string) config('app.booking_timezone', 'Europe/Amsterdam');
        foreach ($sessions as $s) {
            $start = CarbonImmutable::parse($s->session_date->format('Y-m-d').' '.substr((string) $s->start_time, 0, 8), $tz)->utc();
            if ($start->lt($this->now)) {
                continue;
            }
            $noInstructor = $s->instructor_id === null;
            $this->add('education', $noInstructor ? 'session_no_instructor' : 'session_upcoming', $noInstructor ? 'needs_attention' : 'upcoming', $this->trim($s->title),
                $noInstructor ? 'حصة قادمة بدون مدرب معيَّن.' : 'حصة تعليمية قادمة.', $noInstructor ? 'high' : 'medium', '/dashboard/admin/lms/sessions', 'فتح الحصص',
                'scheduled', 'مجدولة', $noInstructor ? 'بلا مدرب' : null, $start, $start, null, (string) $s->id);
        }
    }

    private function collectRegistrations(): void
    {
        $count = Registration::query()->where('created_at', '>=', $this->since)->count();
        if ($count > 0) {
            $this->add('education', 'registrations_new', 'recent_updates', "{$count} تسجيل جديد", 'تسجيلات في الدورات والورش خلال الفترة المحددة.', 'info', '/dashboard/super-admin/crud/registrations', 'عرض التسجيلات',
                'new', 'جديد', null, $this->now, null, null, 'all');
        }

        $pending = Registration::query()->where('status', 'pending_payment')->where('created_at', '>=', $this->since)->count();
        if ($pending > 0) {
            $this->add('education', 'registrations_pending_payment', 'needs_attention', "{$pending} تسجيل بانتظار الدفع", 'تسجيلات لم يكتمل دفعها بعد.', 'medium', '/dashboard/super-admin/crud/registrations', 'متابعة التسجيلات',
                'pending_payment', 'بانتظار الدفع', null, $this->now, null, null, 'all');
        }
    }

    private function collectFinanceRequests(): void
    {
        $departments = Department::query()->pluck('name_ar', 'id');
        // Counts per department only: no title, amount, requester or note is read.
        $rows = FinancialRequest::query()->whereIn('status', ['submitted', 'finance_review', 'finance_approved', 'executive_review'])
            ->select('department_id', DB::raw('COUNT(*) as c'), DB::raw('MIN(submitted_at) as oldest'))->groupBy('department_id')->limit(self::PER_COLLECTOR_ROWS)->get();
        foreach ($rows as $r) {
            $dept = $departments[$r->department_id] ?? null;
            $this->add('finance', 'finance_request_pending', 'needs_attention', "{$r->c} طلب مالي معلق".($dept ? " — {$dept}" : ''), 'طلبات مالية بانتظار المراجعة أو الاعتماد.', 'high',
                '/dashboard/executive/financial-requests', 'متابعة الطلبات', 'pending', 'معلّق', null, $r->oldest ?: $this->now, null, null, (string) ($r->department_id ?? 'none'));
        }
    }

    private function collectPayments(): void
    {
        $manual = ManualPayment::query()->where('status', 'pending_review')->count();
        if ($manual > 0) {
            $this->add('finance', 'manual_payments_pending', 'needs_attention', "{$manual} دفعة يدوية بانتظار المراجعة", 'دفعات سُجّلت يدويًا ولم تُراجع بعد.', 'high', '/dashboard/finance/manual-payments', 'مراجعة الدفعات',
                'pending_review', 'بانتظار المراجعة', null, $this->now, null, null, 'all');
        }

        $failed = Payment::query()->where('status', 'failed')->where('created_at', '>=', $this->since)->count();
        if ($failed > 0) {
            $this->add('finance', 'payments_failed', 'errors', "{$failed} عملية دفع فاشلة", 'عمليات دفع إلكترونية لم تكتمل خلال الفترة المحددة.', 'critical', '/dashboard/finance/payments', 'عرض المدفوعات',
                'failed', 'فاشلة', null, $this->now, null, null, 'all');
        }

        $confirmed = Payment::query()->whereNotNull('confirmed_at')->where('confirmed_at', '>=', $this->since)->count();
        if ($confirmed > 0) {
            $this->add('finance', 'payments_confirmed', 'recent_updates', "{$confirmed} دفعة مؤكَّدة", 'مدفوعات اكتملت خلال الفترة المحددة.', 'info', '/dashboard/finance/payments', 'عرض المدفوعات',
                'confirmed', 'مؤكَّدة', null, $this->now, null, null, 'all');
        }
    }

    private function collectHrRequests(): void
    {
        $departments = Department::query()->pluck('name_ar', 'id');
        foreach (HrRequest::query()->whereIn('status', ['pending', 'in_progress'])->latest('id')->limit(self::PER_COLLECTOR_ROWS)->get(['id', 'department_id', 'required_count', 'status', 'created_at']) as $r) {
            $dept = $departments[$r->department_id] ?? null;
            $this->add('hr', 'hr_request_pending', 'needs_attention', 'طلب احتياج كادر'.($dept ? " — {$dept}" : ''), "يطلب {$r->required_count} من الكوادر.", $r->status === 'pending' ? 'high' : 'medium',
                '/dashboard/hr/incoming-requests', 'متابعة الطلب', $r->status, $r->status === 'pending' ? 'جديد' : 'قيد المعالجة', null, $r->created_at, null, null, (string) $r->id);
        }
    }

    private function collectApplications(): void
    {
        $groups = [
            ['volunteer_profiles', VolunteerHrProfile::query()->whereIn('status', ['submitted', 'under_review']), 'ملف تطوع قيد المراجعة', '/dashboard/hr/volunteers', 'مراجعة الملفات'],
            ['volunteer_requests', VolunteerRequest::query()->where('status', 'pending'), 'طلب تطوع جديد', '/dashboard/hr/volunteer-requests', 'مراجعة الطلبات'],
            ['ambassador', AmbassadorApplication::query()->where('is_draft', false)->whereIn('status', ['new', 'under_review']), 'طلب سفير رقمي', '/dashboard/hr/ambassador-applications', 'مراجعة الطلبات'],
            ['consultants', ConsultantApplication::query()->whereIn('status', ['new', 'under_review']), 'طلب استشاري', '/dashboard/admin/consultant-applications', 'مراجعة الطلبات'],
            ['experts', ExpertApplication::query()->whereIn('status', ['new', 'under_review', 'submitted']), 'طلب انضمام خبير', '/dashboard/admin/ai/expert-applications', 'مراجعة الطلبات'],
            ['partnerships', PartnershipRequest::query()->whereIn('status', ['new', 'pending', 'under_review']), 'طلب شراكة', '/dashboard/admin/partnership-requests', 'مراجعة الطلبات'],
        ];

        foreach ($groups as [$key, $query, $label, $url, $action]) {
            try {
                $count = $query->count();
            } catch (Throwable $e) {
                // One application table failing must not hide the others.
                Log::warning('ExecutiveCommandCenter: application source failed', ['source' => $key, 'error' => $e->getMessage()]);
                continue;
            }
            if ($count > 0) {
                $this->add('hr', 'application_pending', 'needs_attention', "{$count} {$label}", 'طلبات جديدة أو قيد المراجعة تنتظر قرارًا.', 'medium', $url, $action, 'pending', 'قيد المراجعة', null, $this->now, null, null, $key);
            }
        }
    }

    private function collectImpactPoints(): void
    {
        $awards = VolunteerPointAward::query()->where('awarded_at', '>=', $this->since);
        $count = (clone $awards)->count();
        if ($count > 0) {
            $points = (int) (clone $awards)->sum('points');
            $this->add('hr', 'impact_points_awarded', 'recent_updates', "{$count} منحة نقاط أثر ({$points} نقطة)", 'نقاط أُضيفت للمتطوعين والأعضاء خلال الفترة المحددة.', 'info', '/dashboard/operations/impact-points', 'عرض نقاط الأثر',
                'awarded', 'مُنحت', null, $this->now, null, null, 'all');
        }

        // A single award far above the usual is worth a second look.
        $big = (clone $awards)->where('points', '>=', 100)->count();
        if ($big > 0) {
            $this->add('hr', 'impact_points_large', 'needs_attention', "{$big} منحة نقاط كبيرة", 'منح نقاط بقيمة 100 أو أكثر في منحة واحدة؛ يُنصح بالمراجعة.', 'medium', '/dashboard/operations/impact-points', 'مراجعة النقاط',
                'review', 'للمراجعة', null, $this->now, null, null, 'large');
        }
    }

    private function collectQuality(): void
    {
        foreach (QualityIncident::query()->whereIn('status', ['open', 'in_progress'])->orderByDesc('id')->limit(self::PER_COLLECTOR_ROWS)->get(['id', 'title', 'severity', 'status', 'due_date', 'created_at']) as $i) {
            $overdue = $i->due_date && $i->due_date->lt($this->now->startOfDay());
            $severe = in_array(strtolower((string) $i->severity), ['critical', 'high'], true);
            $this->add('quality', 'quality_incident', 'needs_attention', $this->trim($i->title), $overdue ? 'حادثة جودة تجاوزت موعد المعالجة.' : 'حادثة جودة مفتوحة.', $overdue || strtolower((string) $i->severity) === 'critical' ? 'critical' : ($severe ? 'high' : 'medium'),
                '/dashboard/quality/incidents', 'فتح الحوادث', $i->status, $i->status === 'open' ? 'مفتوحة' : 'قيد المعالجة', $overdue ? 'متأخرة' : null, $i->created_at, $i->due_date, null, (string) $i->id);
        }

        $actions = QualityCorrectiveAction::query()->whereNotIn('status', ['completed', 'done', 'closed', 'cancelled'])->whereNotNull('due_date')->where('due_date', '<', $this->now->startOfDay())->count();
        if ($actions > 0) {
            $this->add('quality', 'corrective_actions_overdue', 'needs_attention', "{$actions} إجراء تصحيحي متأخر", 'إجراءات تصحيحية تجاوزت تاريخ استحقاقها.', 'high', '/dashboard/quality/corrective-actions', 'متابعة الإجراءات',
                'overdue', 'متأخر', null, $this->now, null, null, 'all');
        }
    }

    private function collectWeeklyReports(): void
    {
        $weekStart = $this->now->startOfWeek(CarbonImmutable::MONDAY);
        // Only worth flagging once the week is well under way.
        if ($this->now->diffInDays($weekStart) >= 3) {
            $submitted = WeeklyDepartmentReport::query()->whereDate('week_start', $weekStart->toDateString())->pluck('department_id');
            $missing = Department::query()->active()->whereNotIn('id', $submitted)->count();
            if ($missing > 0) {
                $this->add('quality', 'weekly_reports_missing', 'needs_attention', "{$missing} إدارة لم تسلّم تقرير الأسبوع", 'التقارير الأسبوعية للأسبوع الحالي لم تكتمل.', 'medium', '/dashboard/operations/weekly-reports', 'متابعة التقارير',
                    'missing', 'ناقص', null, $this->now, null, null, 'all');
            }
        }

        $recent = WeeklyDepartmentReport::query()->where('submitted_at', '>=', $this->since)->count();
        if ($recent > 0) {
            $this->add('quality', 'weekly_reports_new', 'recent_updates', "{$recent} تقرير أسبوعي جديد", 'تقارير إدارات سُلّمت خلال الفترة المحددة.', 'info', '/dashboard/operations/weekly-reports', 'مراجعة التقارير',
                'submitted', 'مُسلَّم', null, $this->now, null, null, 'all');
        }
    }

    private function collectEmailFailures(): void
    {
        $labels = EmailSettingsController::LABELS;
        // By email TYPE only: never the recipient, the subject or the SMTP reply.
        $rows = NotificationLog::query()->where('status', 'failed')->where('failed_at', '>=', $this->since)
            ->select('type', DB::raw('COUNT(*) as c'), DB::raw('MAX(failed_at) as last_at'))->groupBy('type')->orderByDesc('c')->limit(self::PER_COLLECTOR_ROWS)->get();

        foreach ($rows as $r) {
            $label = $labels[$r->type]['label'] ?? 'رسالة بريد';
            $this->add('email', 'email_failures', 'errors', "فشل إرسال {$r->c} رسالة: {$label}", 'رسائل لم تُسلَّم؛ راجع سجلات البريد وأعد المحاولة.', $r->c >= 5 ? 'critical' : 'high', '/dashboard/super-admin/email-logs', 'فتح سجلات البريد',
                'failed', 'فاشلة', $r->c >= 5 ? 'متكرر' : null, $r->last_at, null, null, (string) $r->type);
        }
    }

    private function collectUsers(): void
    {
        $new = User::query()->where('created_at', '>=', $this->since)->count();
        if ($new > 0) {
            $this->add('users', 'users_new', 'recent_updates', "{$new} مستخدم جديد", 'حسابات أُنشئت خلال الفترة المحددة.', 'info', '/dashboard/super-admin/crud/users', 'عرض المستخدمين',
                'new', 'جديد', null, $this->now, null, null, 'all');
        }

        // Recent accounts only: legacy accounts predate verification and are not a signal.
        $unverified = User::query()->whereNull('email_verified_at')->where('is_active', true)->where('created_at', '>=', $this->now->subDays(30))->count();
        if ($unverified > 0) {
            $this->add('users', 'users_unverified', 'needs_attention', "{$unverified} حساب لم يوثّق بريده", 'حسابات أُنشئت خلال آخر 30 يومًا وما زال بريدها غير موثّق.', 'medium', '/dashboard/super-admin/crud/users', 'متابعة التوثيق',
                'unverified', 'غير موثّق', null, $this->now, null, null, 'all');
        }
    }

    private function collectSystemHealth(): void
    {
        if (! Schema::hasTable('failed_jobs')) {
            throw new \RuntimeException('failed_jobs table missing');
        }

        $failedRecent = DB::table('failed_jobs')->where('failed_at', '>=', $this->since)->count();
        $failedTotal = DB::table('failed_jobs')->count();
        if ($failedTotal > 0) {
            $this->add('system', 'failed_jobs', 'errors', "{$failedTotal} مهمة فاشلة في الطابور", $failedRecent > 0 ? "منها {$failedRecent} خلال الفترة المحددة." : 'لم يُعالَج بعضها منذ فترة.', $failedRecent > 0 ? 'critical' : 'high',
                '/dashboard/tech-admin', 'فتح لوحة التقنية', 'failed', 'فاشلة', null, $this->now, null, null, 'all');
        }
    }

    private function collectNotifications(): void
    {
        $user = $this->viewer;
        if (! $user) {
            return;
        }

        $unread = PlatformNotification::query()->where('user_id', $user->id)->where('is_read', false)->whereNull('archived_at')->where('pinned', true)->count();
        if ($unread > 0) {
            $this->add('system', 'notifications_pinned', 'needs_attention', "{$unread} إشعار مثبّت غير مقروء", 'إشعارات مهمة مثبّتة في صندوقك لم تُقرأ بعد.', 'medium', '/dashboard/notifications', 'فتح الإشعارات',
                'unread', 'غير مقروء', null, $this->now, null, null, 'pinned');
        }
    }

    /**
     * The smart rules engine. It replaces the base items that describe the same condition
     * (an entity alert replaces that entity's base item; an aggregate alert replaces the base
     * item type), so one problem is one card. A rule that fails is reported, not fatal.
     */
    private function collectSmartRules(): void
    {
        $result = $this->smart->evaluate(['now' => $this->now]);
        $this->smartRules = $result['rules'];

        foreach ($result['alerts'] as $alert) {
            $this->supersede($alert);
            $this->items[] = $alert;
        }

        foreach ($result['rules'] as $rule) {
            if ($rule['status'] === 'failed') {
                $this->pendingFailedRules[] = $rule['label'];
                $this->add('smart_rules', 'source_unavailable', 'errors', "قاعدة ذكية غير متاحة: {$rule['label']}", 'تعذّر تقييم هذه القاعدة؛ بقية القواعد تعمل بشكل طبيعي.', 'high', '/dashboard/tech-admin', 'فتح لوحة التقنية', status: 'unavailable', statusLabel: 'غير متاحة', idSuffix: 'rule-'.$rule['key']);
            }
        }
    }

    /** @param array<string, mixed> $alert */
    private function supersede(array $alert): void
    {
        $types = $alert['_supersedes'] ?? [];
        if ($types === []) {
            return;
        }

        $entityId = $alert['related_entity']['id'] ?? null;
        $this->items = array_values(array_filter($this->items, function ($i) use ($types, $alert, $entityId) {
            if (! in_array($i['type'], $types, true)) {
                return true;
            }

            // Entity alert: only that entity's base item. Aggregate alert: every base item of the type.
            return $alert['_entity'] ? $i['id'] !== $i['type'].'-'.$entityId : false;
        }));
    }
}
