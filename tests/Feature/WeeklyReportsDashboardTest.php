<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WeeklyDepartmentReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The dashboard/summary layer added on top of the existing weekly-report
 * write path (WeeklyReportController::summary/index search) — leadership and
 * duplicate-prevention are already covered by DepartmentScopedReportingTest;
 * this file covers the new read-side aggregation and search additions only.
 */
class WeeklyReportsDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function makeDepartment(array $overrides = []): Department
    {
        return Department::create(array_merge([
            'name' => 'Dept ' . Str::random(6),
            'name_ar' => 'إدارة تجريبية',
            'slug' => 'dept-' . Str::random(8),
            'status' => 'active',
            'is_active' => true,
        ], $overrides));
    }

    private function currentWeekStart(): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfWeek(CarbonImmutable::MONDAY);
    }

    public function test_summary_counts_are_scoped_and_accurate_for_leader(): void
    {
        $ownDept = $this->makeDepartment(['name_ar' => 'إدارتي']);
        $otherDept = $this->makeDepartment(['name_ar' => 'إدارة أخرى']);
        $leader = User::factory()->create(['role' => 'department_manager', 'is_active' => true]);
        $ownDept->update(['leader_id' => $leader->id]);

        // Another department's report must not leak into this leader's summary counts.
        WeeklyDepartmentReport::create([
            'department_id' => $otherDept->id,
            'week_start' => $this->currentWeekStart()->toDateString(),
            'achievements' => 'x', 'planned' => 'y',
            'submitted_by' => $leader->id, 'submitted_at' => now(),
        ]);

        $res = $this->actingAs($leader)->getJson('/api/operations/weekly-reports/summary');
        $res->assertOk();
        $this->assertSame(1, $res->json('data.expected'));
        $this->assertSame(0, $res->json('data.submitted'));
    }

    public function test_summary_reflects_submission_and_completion_rate(): void
    {
        $dept = $this->makeDepartment();
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $leader = User::factory()->create(['role' => 'department_manager', 'is_active' => true]);
        $dept->update(['leader_id' => $leader->id]);

        WeeklyDepartmentReport::create([
            'department_id' => $dept->id,
            'week_start' => $this->currentWeekStart()->toDateString(),
            'achievements' => 'x', 'planned' => 'y',
            'submitted_by' => $leader->id, 'submitted_at' => now(),
        ]);

        $res = $this->actingAs($admin)->getJson('/api/operations/weekly-reports/summary');
        $res->assertOk();
        $this->assertSame(100, $res->json('data.completion_rate'));
        $this->assertSame(0, $res->json('data.overdue') + $res->json('data.in_progress'));
    }

    public function test_index_search_matches_department_name(): void
    {
        $dept = $this->makeDepartment(['name_ar' => 'إدارة التسويق والإعلام']);
        $other = $this->makeDepartment(['name_ar' => 'إدارة أخرى غير ذات صلة']);
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        foreach ([$dept, $other] as $d) {
            WeeklyDepartmentReport::create([
                'department_id' => $d->id,
                'week_start' => $this->currentWeekStart()->toDateString(),
                'achievements' => 'x', 'planned' => 'y',
                'submitted_by' => $admin->id, 'submitted_at' => now(),
            ]);
        }

        $res = $this->actingAs($admin)->getJson('/api/operations/weekly-reports?q=' . urlencode('التسويق'));
        $res->assertOk();
        $names = collect($res->json('data.data'))->pluck('department.name');
        $this->assertTrue($names->contains($dept->name));
        $this->assertFalse($names->contains($other->name));
    }

    public function test_search_cannot_bypass_department_scoping(): void
    {
        $ownDept = $this->makeDepartment(['name_ar' => 'إدارتي']);
        $otherDept = $this->makeDepartment(['name_ar' => 'الإدارة الأخرى']);
        $leader = User::factory()->create(['role' => 'department_manager', 'is_active' => true]);
        $ownDept->update(['leader_id' => $leader->id]);

        WeeklyDepartmentReport::create([
            'department_id' => $otherDept->id,
            'week_start' => $this->currentWeekStart()->toDateString(),
            'achievements' => 'x', 'planned' => 'y',
            'submitted_by' => $leader->id, 'submitted_at' => now(),
        ]);

        $res = $this->actingAs($leader)->getJson('/api/operations/weekly-reports?q=' . urlencode('الأخرى'));
        $res->assertOk();
        $this->assertEmpty($res->json('data.data'));
    }

    public function test_due_endpoint_reports_deadline_passed_flag(): void
    {
        $dept = $this->makeDepartment();
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $res = $this->actingAs($admin)->getJson('/api/operations/weekly-reports/due');
        $res->assertOk();
        $this->assertIsBool($res->json('data.deadline_passed'));
    }

    // ── led_departments: coverage gaps closed alongside the leadership repair ──

    public function test_non_leader_manager_role_sees_empty_led_departments(): void
    {
        $dept = $this->makeDepartment();
        $otherLeader = User::factory()->create(['role' => 'department_manager', 'is_active' => true]);
        $dept->update(['leader_id' => $otherLeader->id]);

        // Same manager-style role slug as a real leader, but not actually assigned as leader anywhere.
        $nonLeader = User::factory()->create(['role' => 'programs_manager', 'is_active' => true]);

        $res = $this->actingAs($nonLeader)->getJson('/api/operations/weekly-reports/due');
        $res->assertOk();
        $this->assertSame([], $res->json('data.led_departments'));
    }

    public function test_global_admin_sees_empty_led_departments_but_endpoint_still_works(): void
    {
        // Global admins get create access via can_select_any_department (the manifest),
        // not via led_departments — getLedDepartmentIds is leader_id-only and does not
        // special-case global roles, so this must stay empty rather than error or leak.
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $res = $this->actingAs($admin)->getJson('/api/operations/weekly-reports/due');
        $res->assertOk();
        $this->assertSame([], $res->json('data.led_departments'));

        $res2 = $this->actingAs($admin)->getJson('/api/operations/weekly-reports/summary');
        $res2->assertOk();
        $this->assertSame([], $res2->json('data.led_departments'));
    }

    // ── Monday deadline business rule ───────────────────────────────────────
    // The confirmed rule: each department submits its weekly report on Monday
    // itself — the reporting week's own Monday is the deadline (end of that
    // day), not some later weekday. All assertions below anchor to a
    // deterministically-computed Monday (via startOfWeek) rather than a
    // hardcoded date, so they hold regardless of which real calendar date the
    // suite runs on.

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_before_monday_deadline_end_of_day_is_not_overdue(): void
    {
        $monday = CarbonImmutable::parse('2026-06-01')->startOfWeek(CarbonImmutable::MONDAY);
        $dept = $this->makeDepartment();
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        CarbonImmutable::setTestNow($monday->setTime(9, 0)); // Monday morning — no report yet
        $res = $this->actingAs($admin)->getJson('/api/operations/weekly-reports/due');
        $res->assertOk();
        $this->assertFalse($res->json('data.deadline_passed'));
    }

    public function test_monday_23_59_59_deadline_is_still_within_bounds(): void
    {
        $monday = CarbonImmutable::parse('2026-06-01')->startOfWeek(CarbonImmutable::MONDAY);
        $dept = $this->makeDepartment();
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        CarbonImmutable::setTestNow($monday->endOfDay());
        $res = $this->actingAs($admin)->getJson('/api/operations/weekly-reports/due');
        $res->assertOk();
        $this->assertFalse($res->json('data.deadline_passed'));
    }

    public function test_after_monday_deadline_with_no_report_is_overdue(): void
    {
        $monday = CarbonImmutable::parse('2026-06-01')->startOfWeek(CarbonImmutable::MONDAY);
        $dept = $this->makeDepartment();
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        CarbonImmutable::setTestNow($monday->addDay()->setTime(0, 0, 1)); // Tuesday just after midnight
        $res = $this->actingAs($admin)->getJson('/api/operations/weekly-reports/due');
        $res->assertOk();
        $this->assertTrue($res->json('data.deadline_passed'));
        $this->assertContains($dept->id, collect($res->json('data.missing'))->pluck('id'));
    }

    public function test_submitted_report_never_shows_as_overdue_even_after_deadline(): void
    {
        $monday = CarbonImmutable::parse('2026-06-01')->startOfWeek(CarbonImmutable::MONDAY);
        $dept = $this->makeDepartment();
        $leader = User::factory()->create(['role' => 'department_manager', 'is_active' => true]);
        $dept->update(['leader_id' => $leader->id]);

        CarbonImmutable::setTestNow($monday->setTime(10, 0));
        WeeklyDepartmentReport::create([
            'department_id' => $dept->id,
            'week_start' => $monday->toDateString(),
            'achievements' => 'x', 'planned' => 'y',
            'submitted_by' => $leader->id, 'submitted_at' => now(),
        ]);

        // Travel well past the Monday deadline — the submitted department must
        // never reappear as missing/overdue for that same week.
        CarbonImmutable::setTestNow($monday->addDays(5));
        $res = $this->actingAs($leader)->getJson('/api/operations/weekly-reports/summary');
        $res->assertOk();
        $this->assertSame(0, $res->json('data.overdue'));
        $this->assertSame(1, $res->json('data.submitted'));
    }

    public function test_summary_overdue_after_monday_deadline_for_missing_department(): void
    {
        $monday = CarbonImmutable::parse('2026-06-01')->startOfWeek(CarbonImmutable::MONDAY);
        $dept = $this->makeDepartment();
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        CarbonImmutable::setTestNow($monday->addDay()->setTime(0, 0, 1));
        $res = $this->actingAs($admin)->getJson('/api/operations/weekly-reports/summary');
        $res->assertOk();
        $this->assertSame(1, $res->json('data.overdue'));
        $this->assertSame(0, $res->json('data.in_progress'));
    }

    public function test_current_week_calculation_still_starts_on_monday(): void
    {
        $someWednesday = CarbonImmutable::parse('2026-06-01')->startOfWeek(CarbonImmutable::MONDAY)->addDays(2);
        CarbonImmutable::setTestNow($someWednesday);
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $res = $this->actingAs($admin)->getJson('/api/operations/weekly-reports/due');
        $res->assertOk();
        $this->assertSame(
            $someWednesday->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
            $res->json('data.week_start'),
        );
    }

    public function test_historical_week_report_remains_associated_with_its_original_week(): void
    {
        $pastMonday = CarbonImmutable::parse('2026-01-05')->startOfWeek(CarbonImmutable::MONDAY);
        $dept = $this->makeDepartment();
        $leader = User::factory()->create(['role' => 'department_manager', 'is_active' => true]);
        $dept->update(['leader_id' => $leader->id]);

        CarbonImmutable::setTestNow($pastMonday);
        WeeklyDepartmentReport::create([
            'department_id' => $dept->id,
            'week_start' => $pastMonday->toDateString(),
            'achievements' => 'x', 'planned' => 'y',
            'submitted_by' => $leader->id, 'submitted_at' => now(),
        ]);

        CarbonImmutable::setTestNow($pastMonday->addMonths(3));
        $res = $this->actingAs($leader)->getJson('/api/operations/weekly-reports?week_start=' . $pastMonday->toDateString());
        $res->assertOk();
        $rows = collect($res->json('data.data'));
        $this->assertTrue($rows->contains(fn ($r) => $r['week_start'] === $pastMonday->toDateString() . 'T00:00:00.000000Z' || str_starts_with($r['week_start'], $pastMonday->toDateString())));
    }
}
