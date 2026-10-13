<?php

namespace App\Services;

use App\Http\Controllers\Admin\EmailSettingsController;
use App\Models\Course;
use App\Models\Department;
use App\Models\FinancialRequest;
use App\Models\HrRequest;
use App\Models\LmsSession;
use App\Models\ManualPayment;
use App\Models\Meeting;
use App\Models\NotificationLog;
use App\Models\Ticket;
use App\Models\User;
use App\Support\SmartOperationalRuleConfig as Config;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Smart operational rules (V1): DETECT, SCORE, DISPLAY. Nothing else.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHAT IT DOES NOT DO
 * ─────────────────────────────────────────────────────────────────────────
 * It never changes a record, assigns a ticket, sends an email or a notification, or runs
 * an action. It reads, decides whether a condition holds, and returns normalized alerts
 * for the Executive Command Center. Authorization is the command center's: this class is
 * only reachable through the permission-gated endpoint and has no route of its own.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * HOW A RULE WORKS
 * ─────────────────────────────────────────────────────────────────────────
 * Each rule is a private method plus an entry in SmartOperationalRuleConfig (thresholds,
 * base severity and score, per-rule limit). A rule returns a list of alerts. Every rule runs
 * inside its own try/catch: a failing rule is reported as `failed` and the others still run.
 *
 * Alert ids are stable so the same condition is never listed twice:
 *   entity alerts     smart-{rule}-{entityType}-{entityId}
 *   aggregate alerts  smart-{rule}-{Y-m-d}
 *
 * ─────────────────────────────────────────────────────────────────────────
 * PRIVACY
 * ─────────────────────────────────────────────────────────────────────────
 * Alerts carry a rule label, an age, a count and (for tickets and meetings) the subject the
 * owner already sees in the command center. Financial alerts name the department only, never
 * a title or an amount; user alerts are counts; email alerts name the email TYPE, never a
 * recipient or an SMTP reply; no internal note, file path or token is ever selected.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * COST
 * ─────────────────────────────────────────────────────────────────────────
 * Per-rule row limits, date-bounded windows, no relation loaded per row, a global cap of
 * Config::GLOBAL_LIMIT alerts, sorted by score then recency.
 */
class SmartOperationalRulesService
{
    private const TICKET_CLOSED = ['RESOLVED', 'REJECTED_BY_ADMIN', 'UNRESOLVED'];
    private const FINANCIAL_PENDING = ['submitted', 'finance_review', 'finance_approved', 'executive_review'];
    /** Candidate rows read before the exact (timezone-aware) filter is applied in PHP. */
    private const CANDIDATES = 40;

    private CarbonImmutable $now;

    public function __construct(private readonly ?Config $injected = null) {}

    private function config(): Config
    {
        return $this->injected ?? app(Config::class);
    }

    /**
     * @param  array{now?: CarbonImmutable}  $context
     * @return array{alerts: array<int, array<string, mixed>>, rules: array<int, array<string, mixed>>}
     */
    public function evaluate(array $context = []): array
    {
        $this->now = $context['now'] ?? CarbonImmutable::now();
        $alerts = [];
        $rules = [];

        foreach ($this->config()->all() as $key => $rule) {
            $row = ['key' => $key, 'label' => $rule['label'], 'module' => $rule['module'], 'status' => 'ok', 'count' => 0];

            if (! ($rule['enabled'] ?? false)) {
                $row['status'] = isset($rule['unavailable_reason']) ? 'skipped' : 'disabled';
                $rules[] = $row;

                continue;
            }

            try {
                $found = $this->{'rule'.str_replace('_', '', ucwords($key, '_'))}($rule);
                $row['count'] = count($found);
                array_push($alerts, ...$found);
            } catch (Throwable $e) {
                Log::warning('SmartOperationalRules: rule failed', ['rule' => $key, 'error' => $e->getMessage()]);
                $row['status'] = 'failed';
            }

            $rules[] = $row;
        }

        return ['alerts' => $this->finalize($alerts), 'rules' => $rules];
    }

    /** Dedupe by id, sort by score desc then recency desc, cap globally. */
    private function finalize(array $alerts): array
    {
        $byId = [];
        foreach ($alerts as $a) {
            $byId[$a['id']] ??= $a;
        }

        $sorted = array_values($byId);
        usort($sorted, fn ($x, $y) => [$y['priority_score'], $y['detected_at']] <=> [$x['priority_score'], $x['detected_at']]);

        return array_slice($sorted, 0, Config::GLOBAL_LIMIT);
    }

    // ── Rules: tickets ───────────────────────────────────────────────────────

    /** @param array<string, mixed> $r */
    private function ruleTicketUnassignedTooLong(array $r): array
    {
        $cutoff = $this->now->subHours((int) $r['threshold_hours']);
        $rows = Ticket::query()->whereNull('assigned_to_id')->whereNotIn('status', self::TICKET_CLOSED)->where('created_at', '<', $cutoff)
            ->orderBy('created_at')->limit((int) $r['limit'])->get(['id', 'title', 'created_at']);

        return $rows->map(function ($t) use ($r) {
            $hours = (int) $t->created_at->diffInHours($this->now, true);

            return $this->alert('ticket_unassigned_too_long', $r, 'high', 75 + min(14, intdiv($hours, 12)), "smart-ticket_unassigned_too_long-ticket-{$t->id}",
                'تذكرة بدون مكلف', 'لم يُعيَّن لهذه التذكرة مكلف منذ '.$this->age($hours).'.', $t->created_at,
                ['type' => 'ticket', 'id' => $t->id, 'title' => $this->trim($t->title)], '/dashboard/tickets/'.$t->id, 'فتح التذكرة', 'راجع التذكرة وعيّنها للفريق المناسب.');
        })->all();
    }

    private function ruleTicketOverdue(array $r): array
    {
        $cutoff = $this->now->subHours((int) $r['threshold_hours']);
        $rows = Ticket::query()->whereNotNull('assigned_to_id')->whereNotIn('status', self::TICKET_CLOSED)
            ->where(fn ($q) => $q->where('expected_resolution_time', '<', $this->now)->orWhere(fn ($q2) => $q2->whereNull('expected_resolution_time')->where('created_at', '<', $cutoff)))
            ->orderByRaw('COALESCE(expected_resolution_time, created_at)')->limit((int) $r['limit'])->get(['id', 'title', 'created_at', 'expected_resolution_time']);

        return $rows->map(function ($t) use ($r) {
            $since = $t->expected_resolution_time ?? $t->created_at;
            $hours = (int) $since->diffInHours($this->now, true);
            $severity = $hours >= (int) $r['critical_after_hours'] ? 'critical' : 'high';

            return $this->alert('ticket_overdue', $r, $severity, ($severity === 'critical' ? 90 : 78) + min(9, intdiv($hours, 24)), "smart-ticket_overdue-ticket-{$t->id}",
                'تذكرة متأخرة', 'تجاوزت التذكرة موعد حلّها منذ '.$this->age($hours).'.', $since,
                ['type' => 'ticket', 'id' => $t->id, 'title' => $this->trim($t->title)], '/dashboard/tickets/'.$t->id, 'فتح التذكرة', 'راجع التذكرة المتأخرة واتخذ إجراءً: تواصل مع المكلف أو أعد التوجيه.');
        })->all();
    }

    private function ruleManyNewTickets(array $r): array
    {
        $count = Ticket::query()->where('created_at', '>=', $this->now->subHours((int) $r['window_hours']))->count();
        if ($count < (int) $r['threshold_count']) {
            return [];
        }
        $severity = $count >= (int) $r['threshold_count'] * (int) $r['high_multiplier'] ? 'high' : 'medium';

        return [$this->alert('many_new_tickets', $r, $severity, ($severity === 'high' ? 72 : 55) + min(10, $count - (int) $r['threshold_count']), 'smart-many_new_tickets-'.$this->now->toDateString(),
            'ضغط مرتفع في التذاكر', "وردت {$count} تذكرة جديدة خلال آخر {$r['window_hours']} ساعة.", $this->now,
            null, '/dashboard/tickets/admin', 'فتح لوحة التذاكر', 'راجع ضغط التذاكر اليومي وتأكد من توزيع العمل.')];
    }

    // ── Rules: meetings ──────────────────────────────────────────────────────

    private function ruleMeetingReportMissing(array $r): array
    {
        $tz = (string) config('app.booking_timezone', 'Europe/Amsterdam');
        $from = $this->now->subDays((int) $r['lookback_days'])->toDateString();
        $rows = Meeting::query()->whereIn('status', ['scheduled', 'completed'])->whereBetween('meeting_date', [$from, $this->now->toDateString()])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('meeting_reports')->whereColumn('meeting_reports.meeting_id', 'meetings.id'))
            ->orderByDesc('meeting_date')->limit(self::CANDIDATES)->get(['id', 'title', 'meeting_date', 'start_time', 'end_time']);

        $out = [];
        foreach ($rows as $m) {
            $day = $m->meeting_date->format('Y-m-d');
            $end = $m->end_time
                ? CarbonImmutable::parse($day.' '.substr((string) $m->end_time, 0, 8), $tz)
                : CarbonImmutable::parse($day.' '.substr((string) $m->start_time, 0, 8), $tz)->addHour();
            $end = $end->utc();
            $hours = (int) $end->diffInHours($this->now, false);
            if ($hours < (int) $r['threshold_hours']) {
                continue;
            }

            $out[] = $this->alert('meeting_report_missing', $r, 'high', 74 + min(15, intdiv($hours, 24)), "smart-meeting_report_missing-meeting-{$m->id}",
                'اجتماع انتهى بلا تقرير', 'انتهى الاجتماع منذ '.$this->age($hours).' ولم يُرفع تقريره الرسمي.', $end,
                ['type' => 'meeting', 'id' => $m->id, 'title' => $this->trim($m->title)], '/dashboard/department/meeting-lounge/'.$m->id, 'فتح الاجتماع', 'ذكّر المسؤول أو ارفع التقرير الرسمي للاجتماع.');
            if (count($out) >= (int) $r['limit']) {
                break;
            }
        }

        return $out;
    }

    private function ruleMeetingStartsSoonMissingParticipants(array $r): array
    {
        $tz = (string) config('app.booking_timezone', 'Europe/Amsterdam');
        $limitAt = $this->now->addHours((int) $r['window_hours']);
        $rows = Meeting::query()->where('status', 'scheduled')->whereNull('department_id')
            ->whereBetween('meeting_date', [$this->now->subDay()->toDateString(), $limitAt->addDay()->toDateString()])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('meeting_participants')->whereColumn('meeting_participants.meeting_id', 'meetings.id'))
            ->orderBy('meeting_date')->orderBy('start_time')->limit(self::CANDIDATES)->get(['id', 'title', 'meeting_date', 'start_time']);

        $out = [];
        foreach ($rows as $m) {
            $start = CarbonImmutable::parse($m->meeting_date->format('Y-m-d').' '.substr((string) $m->start_time, 0, 8), $tz)->utc();
            if ($start->lte($this->now) || $start->gt($limitAt)) {
                continue;
            }
            $hours = (int) $this->now->diffInHours($start, false);

            $out[] = $this->alert('meeting_starts_soon_missing_participants', $r, 'medium', 58 + ($hours < 6 ? 8 : 0), "smart-meeting_starts_soon_missing_participants-meeting-{$m->id}",
                'اجتماع قريب بلا نطاق أو مشاركين', 'يبدأ الاجتماع خلال '.$this->age(max(1, $hours)).' وليس له قسم ولا مشاركون محدَّدون.', $this->now,
                ['type' => 'meeting', 'id' => $m->id, 'title' => $this->trim($m->title)], '/dashboard/department/meeting-lounge/'.$m->id, 'فتح الاجتماع', 'راجع نطاق الاجتماع وأضف المشاركين أو القسم المعني.');
            if (count($out) >= (int) $r['limit']) {
                break;
            }
        }

        return $out;
    }

    // ── Rules: email ─────────────────────────────────────────────────────────

    private function ruleFailedEmailsRecent(array $r): array
    {
        $since = $this->now->subHours((int) $r['window_hours']);
        $base = NotificationLog::query()->where('status', 'failed')->where('failed_at', '>=', $since);
        $count = (clone $base)->count();
        if ($count < 1) {
            return [];
        }

        $labels = EmailSettingsController::LABELS;
        $top = (clone $base)->select('type', DB::raw('COUNT(*) as c'))->groupBy('type')->orderByDesc('c')->limit(3)->get()
            ->map(fn ($x) => ($labels[$x->type]['label'] ?? 'رسالة بريد')." ({$x->c})")->implode('، ');
        $severity = $count >= (int) $r['threshold_count'] ? 'high' : 'medium';

        return [$this->alert('failed_emails_recent', $r, $severity, ($severity === 'high' ? 72 : 55) + min(14, $count), 'smart-failed_emails_recent-'.$this->now->toDateString(),
            'فشل إرسال رسائل بريد', "فشل إرسال {$count} رسالة خلال آخر {$r['window_hours']} ساعة: {$top}.", $this->now,
            null, '/dashboard/super-admin/email-logs', 'فتح سجلات البريد', 'راجع سجلات البريد وأعد الإرسال إن لزم.')];
    }

    private function ruleRepeatedEmailFailureType(array $r): array
    {
        $labels = EmailSettingsController::LABELS;
        $rows = NotificationLog::query()->where('status', 'failed')->where('failed_at', '>=', $this->now->subHours((int) $r['window_hours']))
            ->select('type', DB::raw('COUNT(*) as c'), DB::raw('MAX(failed_at) as last_at'))->groupBy('type')
            ->having('c', '>=', (int) $r['threshold_count'])->orderByDesc('c')->limit((int) $r['limit'])->get();

        return $rows->map(function ($x) use ($r, $labels) {
            $label = $labels[$x->type]['label'] ?? 'رسالة بريد';
            $critical = (int) $x->c >= (int) $r['critical_count'];

            return $this->alert('repeated_email_failure_type', $r, $critical ? 'critical' : 'high', ($critical ? 92 : 80) + min(7, (int) $x->c), 'smart-repeated_email_failure_type-email_type-'.$x->type,
                "تكرار فشل بريد: {$label}", "فشل إرسال هذا النوع {$x->c} مرات خلال آخر {$r['window_hours']} ساعة.", $x->last_at,
                ['type' => 'email_type', 'id' => (string) $x->type, 'title' => $label], '/dashboard/super-admin/email-settings', 'فتح إعدادات البريد', 'افحص إعدادات البريد أو القالب المرتبط بهذا النوع.');
        })->all();
    }

    // ── Rules: users ─────────────────────────────────────────────────────────

    private function ruleUnverifiedUsersOld(array $r): array
    {
        $base = User::query()->whereNull('email_verified_at')->where('is_active', true)
            ->where('created_at', '<', $this->now->subDays((int) $r['threshold_days']))->where('created_at', '>=', $this->now->subDays((int) $r['lookback_days']));
        $count = (clone $base)->count();
        if ($count < 1) {
            return [];
        }
        $oldest = (clone $base)->min('created_at');

        return [$this->alert('unverified_users_old', $r, 'medium', 50 + min(15, $count), 'smart-unverified_users_old-'.$this->now->toDateString(),
            'حسابات لم توثّق بريدها', "{$count} حساب نشط مضى على إنشائه أكثر من {$r['threshold_days']} أيام ولم يوثّق بريده.", $oldest,
            null, '/dashboard/super-admin/crud/users', 'فتح المستخدمين', 'أعد إرسال رابط توثيق البريد أو راجع الحسابات.')];
    }

    private function ruleManyUnverifiedUsers(array $r): array
    {
        $count = User::query()->whereNull('email_verified_at')->where('is_active', true)->count();
        if ($count <= (int) $r['threshold_count']) {
            return [];
        }
        $severity = $count > (int) $r['threshold_count'] * (int) $r['high_multiplier'] ? 'high' : 'medium';

        return [$this->alert('many_unverified_users', $r, $severity, ($severity === 'high' ? 70 : 52) + min(10, intdiv($count, 100)), 'smart-many_unverified_users-'.$this->now->toDateString(),
            'كثرة الحسابات غير الموثّقة', "{$count} حساب نشط لم يوثّق بريده الإلكتروني.", $this->now,
            null, '/dashboard/super-admin/crud/users', 'فتح المستخدمين', 'راجع حملة توثيق البريد الإلكتروني للحسابات.')];
    }

    // ── Rules: courses and sessions ──────────────────────────────────────────

    private function ruleUpcomingSessionMissingInstructor(array $r): array
    {
        $tz = (string) config('app.booking_timezone', 'Europe/Amsterdam');
        $end = $this->now->addDays((int) $r['window_days']);
        $rows = LmsSession::query()->where('status', LmsSession::STATUS_SCHEDULED)->whereNull('instructor_id')
            ->whereBetween('session_date', [$this->now->toDateString(), $end->toDateString()])
            ->orderBy('session_date')->orderBy('start_time')->limit(self::CANDIDATES)->get(['id', 'title', 'session_date', 'start_time']);

        $out = [];
        foreach ($rows as $s) {
            $start = CarbonImmutable::parse($s->session_date->format('Y-m-d').' '.substr((string) $s->start_time, 0, 8), $tz)->utc();
            if ($start->lte($this->now)) {
                continue;
            }
            $hours = (int) $this->now->diffInHours($start, false);

            $out[] = $this->alert('upcoming_session_missing_instructor', $r, 'high', 76 + ($hours <= 48 ? 10 : 0), "smart-upcoming_session_missing_instructor-session-{$s->id}",
                'حصة قادمة بلا مدرب', 'تبدأ الحصة خلال '.$this->age(max(1, $hours)).' ولم يُعيَّن لها مدرب.', $this->now,
                ['type' => 'session', 'id' => $s->id, 'title' => $this->trim($s->title)], '/dashboard/admin/lms/sessions', 'فتح الحصص', 'عيّن مدرسًا قبل موعد الحصة.');
            if (count($out) >= (int) $r['limit']) {
                break;
            }
        }

        return $out;
    }

    private function ruleCourseStartingSoonLowSetup(array $r): array
    {
        $end = $this->now->addDays((int) $r['window_days']);
        $rows = Course::query()->where('status', 'published')->whereNotNull('start_date')->whereBetween('start_date', [$this->now, $end])
            ->orderBy('start_date')->limit(self::CANDIDATES)->get(['id', 'title', 'start_date', 'instructor_id', 'description', 'is_online', 'meeting_link']);

        $out = [];
        foreach ($rows as $c) {
            $missing = [];
            if ($c->instructor_id === null) {
                $missing[] = 'المدرب';
            }
            if (trim((string) $c->description) === '') {
                $missing[] = 'الوصف';
            }
            if ($c->is_online && trim((string) $c->meeting_link) === '') {
                $missing[] = 'رابط الحضور';
            }
            if ($missing === []) {
                continue;
            }
            $days = (int) $this->now->diffInDays($c->start_date, false);
            $high = count($missing) >= 2 || $days <= 3;

            $out[] = $this->alert('course_starting_soon_low_setup', $r, $high ? 'high' : 'medium', ($high ? 72 : 60) + (3 - min(3, max(0, $days))), "smart-course_starting_soon_low_setup-course-{$c->id}",
                'دورة قريبة غير مكتملة الإعداد', 'تبدأ الدورة خلال '.($days <= 0 ? 'أقل من يوم' : "{$days} أيام").' وينقصها: '.implode('، ', $missing).'.', $this->now,
                ['type' => 'course', 'id' => $c->id, 'title' => $this->trim($c->title)], '/dashboard/admin/programs', 'فتح البرامج', 'راجع إعدادات الدورة وأكمل البيانات الناقصة.');
            if (count($out) >= (int) $r['limit']) {
                break;
            }
        }

        return $out;
    }

    // ── Rules: finance ───────────────────────────────────────────────────────

    private function ruleFinanceRequestPendingTooLong(array $r): array
    {
        $cutoff = $this->now->subHours((int) $r['threshold_hours']);
        $departments = Department::query()->pluck('name_ar', 'id');
        // Only the id, department and dates are read: never a title, amount or note.
        $rows = FinancialRequest::query()->whereIn('status', self::FINANCIAL_PENDING)
            ->whereRaw('COALESCE(submitted_at, created_at) < ?', [$cutoff])
            ->orderByRaw('COALESCE(submitted_at, created_at)')->limit((int) $r['limit'])->get(['id', 'department_id', 'submitted_at', 'created_at']);

        return $rows->map(function ($f) use ($r, $departments) {
            $since = $f->submitted_at ?? $f->created_at;
            $hours = (int) $since->diffInHours($this->now, true);
            $dept = $departments[$f->department_id] ?? null;

            return $this->alert('finance_request_pending_too_long', $r, $hours >= (int) $r['high_after_hours'] ? 'high' : 'medium', 62 + min(10, intdiv($hours, 24)), "smart-finance_request_pending_too_long-financial_request-{$f->id}",
                'طلب مالي معلق', 'طلب مالي'.($dept ? " لإدارة {$dept}" : '').' معلّق منذ '.$this->age($hours).'.', $since,
                ['type' => 'financial_request', 'id' => $f->id, 'title' => 'طلب مالي'.($dept ? " — {$dept}" : '')], '/dashboard/executive/financial-requests', 'متابعة الطلب', 'راجع الطلب المالي المعلق واتخذ قرارًا.');
        })->all();
    }

    private function ruleManualPaymentPendingReview(array $r): array
    {
        $count = ManualPayment::query()->where('status', 'pending_review')->count();
        if ($count < 1) {
            return [];
        }

        return [$this->alert('manual_payment_pending_review', $r, 'medium', 56 + min(12, $count), 'smart-manual_payment_pending_review-'.$this->now->toDateString(),
            'دفعات يدوية بانتظار المراجعة', "{$count} دفعة يدوية تنتظر المراجعة.", $this->now,
            null, '/dashboard/finance/manual-payments', 'مراجعة الدفعات', 'راجع الدفعات اليدوية المعلقة.')];
    }

    // ── Rules: HR ────────────────────────────────────────────────────────────

    private function ruleHrRequestPendingTooLong(array $r): array
    {
        $cutoff = $this->now->subHours((int) $r['threshold_hours']);
        $departments = Department::query()->pluck('name_ar', 'id');
        $rows = HrRequest::query()->whereIn('status', ['pending', 'in_progress'])->where('created_at', '<', $cutoff)
            ->orderBy('created_at')->limit((int) $r['limit'])->get(['id', 'department_id', 'created_at']);

        return $rows->map(function ($h) use ($r, $departments) {
            $hours = (int) $h->created_at->diffInHours($this->now, true);
            $dept = $departments[$h->department_id] ?? null;

            return $this->alert('hr_request_pending_too_long', $r, 'medium', 54 + min(14, intdiv($hours, 24)), "smart-hr_request_pending_too_long-hr_request-{$h->id}",
                'طلب موارد بشرية معلق', 'طلب احتياج كادر'.($dept ? " لإدارة {$dept}" : '').' لم يُعالَج منذ '.$this->age($hours).'.', $h->created_at,
                ['type' => 'hr_request', 'id' => $h->id, 'title' => 'طلب احتياج'.($dept ? " — {$dept}" : '')], '/dashboard/hr/incoming-requests', 'متابعة الطلب', 'راجع طلب الموارد البشرية وعالجه.');
        })->all();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Build one normalized alert. `$severity`/`$score` are the values this particular alert
     * earned (a rule may escalate); the score is clamped into the band of that severity.
     *
     * @param  array<string, mixed>  $rule
     * @param  array{type: string, id: int|string, title: string}|null  $entity
     * @return array<string, mixed>
     */
    private function alert(string $key, array $rule, string $severity, int $score, string $id, string $title, string $description, $detectedAt, ?array $entity, string $url, string $action, string $suggested): array
    {
        $at = CarbonImmutable::parse($detectedAt)->toIso8601String();

        return [
            'id' => $id,
            'source' => 'smart_rules',
            'type' => 'operational_alert',
            'rule_key' => $key,
            'section' => $rule['section'],
            'title' => $title,
            'description' => $description,
            'status' => null,
            'status_label' => null,
            'severity' => $severity,
            'priority' => $severity,
            'priority_label' => Config::SEVERITY_LABELS[$severity],
            'priority_score' => Config::clamp($severity, $score),
            'module' => $rule['module'],
            'detected_at' => $at,
            'timestamp' => $at,
            'due_at' => null,
            'actor' => null,
            'related_entity' => $entity,
            'url' => $url,
            'action_label' => $action,
            'suggested_action' => $suggested,
            'badge' => 'تنبيه ذكي',
            // Internal (stripped before the response): which base command-center items this replaces.
            '_supersedes' => $rule['supersedes'] ?? [],
            '_entity' => $entity !== null,
        ];
    }

    private function trim(?string $text, int $max = 80): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', (string) $text));

        return mb_strlen($t) > $max ? mb_substr($t, 0, $max - 1).'…' : $t;
    }

    /** "6 ساعات" / "ساعة واحدة" / "3 أيام". */
    private function age(int $hours): string
    {
        if ($hours >= 48) {
            return intdiv($hours, 24).' أيام';
        }
        if ($hours >= 24) {
            return 'يوم واحد';
        }

        return match (true) {
            $hours <= 1 => 'ساعة واحدة',
            $hours === 2 => 'ساعتين',
            $hours <= 10 => "{$hours} ساعات",
            default => "{$hours} ساعة",
        };
    }
}
