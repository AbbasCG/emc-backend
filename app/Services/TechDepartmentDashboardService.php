<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Department;
use App\Models\FinancialRequest;
use App\Models\HrRequest;
use App\Models\Meeting;
use App\Models\MeetingReport;
use App\Models\Task;
use App\Models\TeamProfile;
use App\Models\Ticket;
use App\Models\VolunteerHrProfile;
use App\Models\WeeklyDepartmentReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Read-only cross-department monitoring summary for Technology Management.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * AGGREGATES ONLY
 * ─────────────────────────────────────────────────────────────────────────
 * Every figure here is a COUNT (or a date) taken straight from the department's own
 * rows. No titles, descriptions, names, notes, amounts, emails, attachments or message
 * bodies are ever selected, so there is nothing sensitive to leak by accident: a new
 * caller cannot widen the payload without editing this file. The only person shown is
 * the department leader's id and name, which the org chart already publishes.
 *
 * Authorization is NOT decided here. The route requires the
 * `tech.department_dashboards.view` permission; this class assumes it has been checked.
 * It never writes, never impersonates and never changes anyone's role.
 *
 * Each section is computed in isolation: one failing table degrades that section to a
 * warning instead of failing the whole page, so a broken module is exactly the kind of
 * thing this view can surface.
 */
class TechDepartmentDashboardService
{
    private const TICKET_CLOSED = ['RESOLVED', 'REJECTED_BY_ADMIN', 'UNRESOLVED'];
    private const FINANCIAL_PENDING = ['submitted', 'finance_review', 'finance_approved', 'executive_review'];
    private const TASK_CLOSED = ['completed', 'cancelled'];
    private const STALE_PENDING_TICKET_DAYS = 3;

    /**
     * Active departments only. Archived and inactive ones are excluded: their data is
     * history, not something to monitor.
     *
     * @return array<int, array<string, mixed>>
     */
    public function departments(): array
    {
        return Department::query()
            ->active()
            ->with('leader:id,name')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Department $d) => $this->departmentRow($d))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function departmentRow(Department $d): array
    {
        return [
            'id' => $d->id,
            'name' => $d->name_ar ?: $d->name,
            'slug' => $d->slug,
            'status' => $d->status ?? 'active',
            'leader' => $d->leader ? ['id' => $d->leader->id, 'name' => $d->leader->name] : null,
        ];
    }

    /**
     * The dashboard summary for one department.
     *
     * @return array<string, mixed>
     */
    public function dashboard(Department $department): array
    {
        $department->loadMissing('leader:id,name');
        $warnings = [];

        $members = $this->section('members', 'الأعضاء', $warnings, fn () => $this->members($department));
        $operations = $this->section('operations', 'العمليات والمهام', $warnings, fn () => $this->operations($department));
        $support = $this->section('support', 'التذاكر والبلاغات', $warnings, fn () => $this->support($department));
        $requests = $this->section('requests', 'الطلبات المعلقة', $warnings, fn () => $this->requests($department));
        $reports = $this->section('reports', 'التقارير', $warnings, fn () => $this->reports($department));

        $sections = array_values(array_filter([$operations, $support, $members, $requests, $reports]));
        $metric = fn (?array $section, string $key) => $this->metricValue($section, $key);

        $openItems = array_sum([
            (int) $metric($operations, 'open_tasks'),
            (int) $metric($support, 'open_tickets'),
            (int) $metric($requests, 'pending_financial_requests'),
            (int) $metric($requests, 'pending_hr_requests'),
            (int) $metric($requests, 'pending_volunteer_applications'),
        ]);

        $lastActivity = $this->lastActivity($department);

        $warnings = array_merge($warnings, $this->rules($department, $members, $operations, $support, $reports));

        return [
            'department' => $this->departmentRow($department),
            'summary' => [
                'cards' => [
                    $this->metricRow('open_items', 'إجمالي العناصر المفتوحة', $openItems, $openItems > 0 ? 'info' : 'good'),
                    $this->metricRow('pending_tasks', 'المهام المعلقة', (int) $metric($operations, 'open_tasks'), (int) $metric($operations, 'overdue_tasks') > 0 ? 'warning' : 'neutral'),
                    $this->metricRow('active_tickets', 'البلاغات النشطة', (int) $metric($support, 'open_tickets'), 'neutral'),
                    $this->metricRow('active_members', 'الأعضاء النشطون', (int) $metric($members, 'active_members'), (int) $metric($members, 'active_members') === 0 ? 'warning' : 'good'),
                    $this->metricRow('last_activity', 'آخر نشاط', $lastActivity, 'neutral'),
                ],
            ],
            'modules' => array_map(
                fn (array $s) => ['key' => $s['key'], 'label' => $s['label'], 'available' => $s['available']],
                array_merge(
                    [['key' => 'overview', 'label' => 'نظرة عامة', 'available' => true]],
                    $sections,
                ),
            ),
            'sections' => $sections,
            'warnings' => $warnings,
            'links' => $this->links($department),
            'diagnostics' => [
                'generated_at' => now()->toIso8601String(),
                'last_activity_at' => $lastActivity,
                'data_source' => 'تجميعات مباشرة من قاعدة البيانات (قراءة فقط)',
                'stale' => $lastActivity !== null && CarbonImmutable::parse($lastActivity)->lt(now()->subDays(30)),
                'sections_failed' => count(array_filter($sections, fn (array $s) => ! $s['available'])),
            ],
            'capabilities' => self::capabilities(),
        ];
    }

    /** @return array<string, bool> */
    public static function capabilities(): array
    {
        return [
            'can_view' => true,
            'can_refresh' => true,
            'can_view_sensitive' => false,
            'read_only' => true,
        ];
    }

    // ── Sections ─────────────────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    private function members(Department $d): array
    {
        $q = TeamProfile::query()->where('department_id', $d->id);
        $total = (clone $q)->count();
        $active = (clone $q)->where('is_active', true)->count();

        return [
            $this->metricRow('total_members', 'إجمالي الأعضاء', $total),
            $this->metricRow('active_members', 'الأعضاء النشطون', $active, $active === 0 ? 'warning' : 'good'),
            $this->metricRow('inactive_members', 'الأعضاء غير النشطين', $total - $active),
            $this->metricRow('courses_linked', 'دورات وبرامج مرتبطة', Course::query()->where('department_id', $d->id)->count()),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function operations(Department $d): array
    {
        $open = Task::query()->where('department_id', $d->id)->whereNotIn('status', self::TASK_CLOSED);
        $overdue = (clone $open)->whereNotNull('due_date')->whereDate('due_date', '<', today())->count();
        $upcomingMeetings = Meeting::query()
            ->where('department_id', $d->id)
            ->where('status', 'scheduled')
            ->whereBetween('meeting_date', [today()->toDateString(), today()->addDays(7)->toDateString()])
            ->count();

        return [
            $this->metricRow('open_tasks', 'مهام مفتوحة', (clone $open)->count()),
            $this->metricRow('overdue_tasks', 'مهام متأخرة', $overdue, $overdue > 0 ? 'warning' : 'good'),
            $this->metricRow('completed_tasks_month', 'مهام أُنجزت هذا الشهر', Task::query()->where('department_id', $d->id)->where('status', 'completed')->where('updated_at', '>=', now()->startOfMonth())->count()),
            $this->metricRow('upcoming_meetings', 'اجتماعات خلال 7 أيام', $upcomingMeetings),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function support(Department $d): array
    {
        $base = Ticket::query()->where('department_id', $d->id);
        $open = (clone $base)->whereNotIn('status', self::TICKET_CLOSED);
        $stale = (clone $base)->where('status', 'PENDING_APPROVAL')->where('created_at', '<', now()->subDays(self::STALE_PENDING_TICKET_DAYS))->count();

        return [
            $this->metricRow('open_tickets', 'تذاكر نشطة', (clone $open)->count()),
            $this->metricRow('pending_approval_tickets', 'بانتظار الاعتماد', (clone $base)->where('status', 'PENDING_APPROVAL')->count()),
            $this->metricRow('in_progress_tickets', 'قيد المعالجة', (clone $base)->whereIn('status', ['ASSIGNED', 'IN_PROGRESS'])->count()),
            $this->metricRow('stale_pending_tickets', 'بانتظار الاعتماد منذ أكثر من '.self::STALE_PENDING_TICKET_DAYS.' أيام', $stale, $stale > 0 ? 'warning' : 'good'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function requests(Department $d): array
    {
        return [
            // Counts only: no title, amount, requester or note is ever read.
            $this->metricRow('pending_financial_requests', 'طلبات مالية معلقة', FinancialRequest::query()->where('department_id', $d->id)->whereIn('status', self::FINANCIAL_PENDING)->count()),
            $this->metricRow('pending_hr_requests', 'طلبات موارد بشرية معلقة', HrRequest::query()->where('department_id', $d->id)->whereIn('status', ['pending', 'in_progress'])->count()),
            $this->metricRow('pending_volunteer_applications', 'طلبات تطوع قيد المراجعة', VolunteerHrProfile::query()->where('department_id', $d->id)->whereIn('status', ['submitted', 'under_review'])->count()),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function reports(Department $d): array
    {
        $weekStart = CarbonImmutable::now()->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
        $weeklySubmitted = WeeklyDepartmentReport::query()->where('department_id', $d->id)->whereDate('week_start', $weekStart)->exists();

        return [
            $this->metricRow('meeting_reports_30d', 'تقارير اجتماعات (آخر 30 يومًا)', MeetingReport::query()->where('department_id', $d->id)->where('meeting_date', '>=', now()->subDays(30)->toDateString())->count()),
            $this->metricRow('weekly_report_submitted', 'تقرير الأسبوع الحالي', $weeklySubmitted ? 'مُسلَّم' : 'غير مُسلَّم', $weeklySubmitted ? 'good' : 'warning'),
            $this->metricRow('weekly_reports_total', 'إجمالي التقارير الأسبوعية', WeeklyDepartmentReport::query()->where('department_id', $d->id)->count()),
        ];
    }

    // ── Diagnostics ──────────────────────────────────────────────────────────

    /**
     * Rule-based warnings. Each rule reads a figure already computed above and says
     * so in plain Arabic; none of them is a guess.
     *
     * @return array<int, array<string, string>>
     */
    private function rules(Department $d, ?array $members, ?array $operations, ?array $support, ?array $reports): array
    {
        $w = [];
        if (! $d->leader_id) {
            $w[] = $this->warning('no_leader', 'warning', 'لا يوجد مدير معيَّن لهذه الإدارة.');
        }
        if ($members && (int) $this->metricValue($members, 'active_members') === 0) {
            $w[] = $this->warning('no_active_members', 'warning', 'لا يوجد أعضاء نشطون في هذه الإدارة.');
        }
        if ($operations && (int) $this->metricValue($operations, 'overdue_tasks') > 0) {
            $w[] = $this->warning('overdue_tasks', 'warning', 'توجد مهام متأخرة عن موعدها.');
        }
        if ($support && (int) $this->metricValue($support, 'stale_pending_tickets') > 0) {
            $w[] = $this->warning('stale_tickets', 'warning', 'توجد تذاكر بانتظار الاعتماد منذ فترة طويلة.');
        }
        if ($reports && $this->metricValue($reports, 'weekly_report_submitted') === 'غير مُسلَّم') {
            $w[] = $this->warning('weekly_report_missing', 'info', 'لم يُسلَّم تقرير الأسبوع الحالي بعد.');
        }

        return $w;
    }

    private function lastActivity(Department $d): ?string
    {
        try {
            $stamps = array_filter([
                Task::query()->where('department_id', $d->id)->max('updated_at'),
                Ticket::query()->where('department_id', $d->id)->max('updated_at'),
                MeetingReport::query()->where('department_id', $d->id)->max('created_at'),
                WeeklyDepartmentReport::query()->where('department_id', $d->id)->max('submitted_at'),
            ]);

            return $stamps === [] ? null : CarbonImmutable::parse(max($stamps))->toIso8601String();
        } catch (Throwable $e) {
            Log::warning('TechDepartmentDashboardService: last activity failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Links to the department's real dashboards. The page key lets the frontend enable a
     * link only when the viewer's own page access allows it; a link never bypasses access.
     *
     * @return array<int, array<string, string>>
     */
    private function links(Department $d): array
    {
        return [[
            'key' => 'department_workspace',
            'label' => 'فتح لوحة الإدارة الأصلية',
            'path' => '/dashboard/department/'.$d->id,
            'page_key' => 'organizational_departments.department_overview',
        ]];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Run one section; on failure keep the page alive and record why.
     *
     * @param  array<int, array<string, string>>  $warnings
     * @return array<string, mixed>
     */
    private function section(string $key, string $label, array &$warnings, callable $build): array
    {
        try {
            return ['key' => $key, 'label' => $label, 'available' => true, 'metrics' => $build()];
        } catch (Throwable $e) {
            Log::warning('TechDepartmentDashboardService: section failed', ['section' => $key, 'error' => $e->getMessage()]);
            $warnings[] = $this->warning("section_{$key}_failed", 'critical', "تعذّر تحميل قسم «{$label}». راجع سجلات النظام.");

            return ['key' => $key, 'label' => $label, 'available' => false, 'metrics' => []];
        }
    }

    /** @param array<string, mixed>|null $section */
    private function metricValue(?array $section, string $key): mixed
    {
        foreach ($section['metrics'] ?? [] as $m) {
            if ($m['key'] === $key) {
                return $m['value'];
            }
        }

        return 0;
    }

    /** @return array<string, mixed> */
    private function metricRow(string $key, string $label, mixed $value, string $tone = 'neutral'): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'tone' => $tone];
    }

    /** @return array<string, string> */
    private function warning(string $code, string $severity, string $message): array
    {
        return ['code' => $code, 'severity' => $severity, 'message' => $message];
    }
}
