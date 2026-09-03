<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\TeamProfile;
use App\Models\User;
use App\Models\WeeklyDepartmentReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Root-cause coverage for "قائد badge shown but weekly-report creation
 * denied": AdminTeamProfileController::update()/store() previously toggled
 * TeamProfile.is_leader (what the member-management UI displays) without
 * ever touching the canonical departments.leader_id column (what
 * DepartmentAccessService::canManageDepartment() — and therefore Weekly/
 * Meeting Report creation — actually reads). syncDepartmentLeadership() now
 * keeps the two in sync on every write. departments.leader_id remains the
 * SOLE authorization signal; is_leader is purely a display flag kept
 * consistent with it, not a second authority.
 */
class TeamProfileLeadershipSyncTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

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

    // ── The core bug: is_leader toggle must sync departments.leader_id ─────

    public function test_marking_team_profile_as_leader_syncs_departments_leader_id(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->admin();
        $user = User::factory()->create(['role' => 'programs_manager', 'is_active' => true]);
        $profile = TeamProfile::create([
            'user_id' => $user->id, 'department_id' => $dept->id,
            'name_ar' => 'إيمان', 'position_ar' => 'مديرة برامج', 'member_type' => 'staff', 'is_active' => true,
        ]);
        $this->assertNull($dept->fresh()->leader_id);

        $this->actingAs($admin)->putJson("/api/admin/team-profiles/{$profile->id}", [
            'is_leader' => true,
        ])->assertOk();

        $this->assertSame($user->id, $dept->fresh()->leader_id);
    }

    public function test_leader_can_create_weekly_report_immediately_after_being_flagged_leader(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->admin();
        // A role name that grants nothing by itself — only the leader_id sync will.
        $user = User::factory()->create(['role' => 'programs_manager', 'is_active' => true]);
        $profile = TeamProfile::create([
            'user_id' => $user->id, 'department_id' => $dept->id,
            'name_ar' => 'إيمان', 'position_ar' => 'مديرة برامج', 'member_type' => 'staff', 'is_active' => true,
        ]);

        // Before the sync: role slug alone must not grant creation.
        $this->actingAs($user)->postJson('/api/operations/weekly-reports', [
            'department_id' => $dept->id, 'achievements' => 'x', 'planned' => 'y',
        ])->assertStatus(403);

        $this->actingAs($admin)->putJson("/api/admin/team-profiles/{$profile->id}", ['is_leader' => true])->assertOk();

        // After the sync: same user, same role, now genuinely the leader — must succeed.
        $this->actingAs($user)->postJson('/api/operations/weekly-reports', [
            'department_id' => $dept->id, 'achievements' => 'x', 'planned' => 'y',
        ])->assertStatus(201);
    }

    public function test_leader_can_create_meeting_report_immediately_after_being_flagged_leader(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->admin();
        $user = User::factory()->create(['role' => 'programs_manager', 'is_active' => true]);
        $profile = TeamProfile::create([
            'user_id' => $user->id, 'department_id' => $dept->id,
            'name_ar' => 'إيمان', 'position_ar' => 'مديرة برامج', 'member_type' => 'staff', 'is_active' => true,
        ]);

        $this->actingAs($user)->postJson('/api/operations/meeting-reports', [
            'department_id' => $dept->id, 'title' => 'اجتماع', 'meeting_date' => now()->toDateString(),
        ])->assertStatus(403);

        $this->actingAs($admin)->putJson("/api/admin/team-profiles/{$profile->id}", ['is_leader' => true])->assertOk();

        $this->actingAs($user)->postJson('/api/operations/meeting-reports', [
            'department_id' => $dept->id, 'title' => 'اجتماع', 'meeting_date' => now()->toDateString(),
        ])->assertStatus(201);
    }

    public function test_setting_department_leader_id_directly_syncs_team_profile_is_leader(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->admin();
        $user = User::factory()->create(['role' => 'department_manager', 'is_active' => true]);
        $profile = TeamProfile::create([
            'user_id' => $user->id, 'department_id' => $dept->id,
            'name_ar' => 'ز', 'position_ar' => 'عضو', 'member_type' => 'staff', 'is_active' => true,
        ]);

        $this->actingAs($admin)->putJson("/api/admin/departments/{$dept->id}", [
            'leader_id' => $user->id,
        ])->assertOk();

        $this->assertTrue($profile->fresh()->is_leader);
        $this->assertSame($user->id, $dept->fresh()->leader_id);
    }

    public function test_setting_new_leader_demotes_previous_is_leader_profile_in_same_department(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->admin();
        $oldLeaderUser = User::factory()->create(['role' => 'department_manager', 'is_active' => true]);
        $newLeaderUser = User::factory()->create(['role' => 'department_manager', 'is_active' => true]);

        $oldProfile = TeamProfile::create([
            'user_id' => $oldLeaderUser->id, 'department_id' => $dept->id,
            'name_ar' => 'أ', 'position_ar' => 'قائد', 'member_type' => 'staff', 'is_active' => true, 'is_leader' => true,
        ]);
        $dept->update(['leader_id' => $oldLeaderUser->id]);

        $newProfile = TeamProfile::create([
            'user_id' => $newLeaderUser->id, 'department_id' => $dept->id,
            'name_ar' => 'ب', 'position_ar' => 'قائد جديد', 'member_type' => 'staff', 'is_active' => true,
        ]);

        $this->actingAs($admin)->putJson("/api/admin/team-profiles/{$newProfile->id}", ['is_leader' => true])->assertOk();

        $this->assertSame($newLeaderUser->id, $dept->fresh()->leader_id);
        $this->assertFalse($oldProfile->fresh()->is_leader);
        // Previous leader immediately loses creation authority.
        $this->actingAs($oldLeaderUser)->postJson('/api/operations/weekly-reports', [
            'department_id' => $dept->id, 'achievements' => 'x', 'planned' => 'y',
        ])->assertStatus(403);
    }

    public function test_unflagging_the_recorded_leader_clears_department_leader_id(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->admin();
        $user = User::factory()->create(['role' => 'quality_manager', 'is_active' => true]);
        $profile = TeamProfile::create([
            'user_id' => $user->id, 'department_id' => $dept->id,
            'name_ar' => 'ج', 'position_ar' => 'قائد', 'member_type' => 'staff', 'is_active' => true, 'is_leader' => true,
        ]);
        $dept->update(['leader_id' => $user->id]);

        $this->actingAs($admin)->putJson("/api/admin/team-profiles/{$profile->id}", ['is_leader' => false])->assertOk();

        $this->assertNull($dept->fresh()->leader_id);
    }

    public function test_unflagging_an_unrelated_profile_never_clears_a_different_users_leader_id(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->admin();
        $leaderUser = User::factory()->create(['role' => 'hr_manager', 'is_active' => true]);
        $dept->update(['leader_id' => $leaderUser->id]);

        $otherUser = User::factory()->create(['role' => 'hr_manager', 'is_active' => true]);
        $otherProfile = TeamProfile::create([
            'user_id' => $otherUser->id, 'department_id' => $dept->id,
            'name_ar' => 'د', 'position_ar' => 'عضو', 'member_type' => 'staff', 'is_active' => true,
        ]);

        $this->actingAs($admin)->putJson("/api/admin/team-profiles/{$otherProfile->id}", ['is_leader' => false])->assertOk();

        $this->assertSame($leaderUser->id, $dept->fresh()->leader_id);
    }

    // ── ID-domain: TeamProfile.id / TeamMember.id must never stand in for users.id ──

    public function test_team_profile_id_is_never_confused_with_user_id_for_leadership(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->admin();
        $user = User::factory()->create(['role' => 'marketing_manager', 'is_active' => true]);
        $profile = TeamProfile::create([
            'user_id' => $user->id, 'department_id' => $dept->id,
            'name_ar' => 'هـ', 'position_ar' => 'قائد', 'member_type' => 'staff', 'is_active' => true,
        ]);

        $this->actingAs($admin)->putJson("/api/admin/team-profiles/{$profile->id}", ['is_leader' => true])->assertOk();

        $fresh = $dept->fresh();
        $this->assertSame($user->id, $fresh->leader_id);
        // The TeamProfile's own primary key must never leak into leader_id.
        if ($profile->id !== $user->id) {
            $this->assertNotSame($profile->id, $fresh->leader_id);
        }
    }

    // ── due()/summary() expose led_departments consistently, multi-department leaders included ──

    public function test_due_endpoint_reports_led_departments_with_can_create(): void
    {
        $dept = $this->makeDepartment(['name_ar' => 'إدارة البرامج والمسارات']);
        $admin = $this->admin();
        $user = User::factory()->create(['role' => 'programs_manager', 'is_active' => true]);
        $profile = TeamProfile::create([
            'user_id' => $user->id, 'department_id' => $dept->id,
            'name_ar' => 'إيمان', 'position_ar' => 'مديرة برامج', 'member_type' => 'staff', 'is_active' => true,
        ]);
        $this->actingAs($admin)->putJson("/api/admin/team-profiles/{$profile->id}", ['is_leader' => true])->assertOk();

        $res = $this->actingAs($user)->getJson('/api/operations/weekly-reports/due');
        $res->assertOk();
        $led = collect($res->json('data.led_departments'));
        $mine = $led->firstWhere('department.id', $dept->id);
        $this->assertNotNull($mine);
        $this->assertTrue($mine['can_create']);
        $this->assertFalse($mine['can_edit']);
        $this->assertNull($mine['current_report']);
    }

    public function test_led_departments_reflects_submitted_report_as_can_edit(): void
    {
        $dept = $this->makeDepartment();
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $dept->update(['leader_id' => $leader->id]);

        $this->actingAs($leader)->postJson('/api/operations/weekly-reports', [
            'department_id' => $dept->id, 'achievements' => 'x', 'planned' => 'y',
        ])->assertStatus(201);

        $res = $this->actingAs($leader)->getJson('/api/operations/weekly-reports/summary');
        $res->assertOk();
        $led = collect($res->json('data.led_departments'));
        $mine = $led->firstWhere('department.id', $dept->id);
        $this->assertSame('submitted', $mine['state']);
        $this->assertFalse($mine['can_create']);
        $this->assertTrue($mine['can_edit']);
    }

    public function test_overdue_missing_report_still_allows_create(): void
    {
        $dept = $this->makeDepartment();
        $leader = User::factory()->create(['role' => 'finance_manager', 'is_active' => true]);
        $dept->update(['leader_id' => $leader->id]);

        CarbonImmutable::setTestNow($this->currentWeekStart()->addDays(3));
        $res = $this->actingAs($leader)->getJson('/api/operations/weekly-reports/due');
        CarbonImmutable::setTestNow();

        $res->assertOk();
        $led = collect($res->json('data.led_departments'));
        $mine = $led->firstWhere('department.id', $dept->id);
        $this->assertSame('overdue', $mine['state']);
        $this->assertTrue($mine['can_create']);
    }

    public function test_user_leading_multiple_departments_sees_all_of_them_not_just_first(): void
    {
        $deptA = $this->makeDepartment();
        $deptB = $this->makeDepartment();
        $leader = User::factory()->create(['role' => 'community_manager', 'is_active' => true]);
        $deptA->update(['leader_id' => $leader->id]);
        $deptB->update(['leader_id' => $leader->id]);

        $res = $this->actingAs($leader)->getJson('/api/operations/weekly-reports/due');
        $res->assertOk();
        $ids = collect($res->json('data.led_departments'))->pluck('department.id');
        $this->assertTrue($ids->contains($deptA->id));
        $this->assertTrue($ids->contains($deptB->id));
        $this->assertCount(2, $ids);
    }

    // ── Historical attribution survives a leadership change ────────────────

    public function test_historical_report_keeps_original_submitter_after_leadership_change(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->admin();
        $oldLeader = User::factory()->create(['role' => 'partnerships_manager', 'is_active' => true]);
        $dept->update(['leader_id' => $oldLeader->id]);

        $pastWeek = $this->currentWeekStart()->subWeeks(2);
        $report = WeeklyDepartmentReport::create([
            'department_id' => $dept->id, 'week_start' => $pastWeek->toDateString(),
            'achievements' => 'x', 'planned' => 'y',
            'submitted_by' => $oldLeader->id, 'submitted_at' => $pastWeek,
        ]);

        // Leadership changes via the profile sync path.
        $newLeader = User::factory()->create(['role' => 'partnerships_manager', 'is_active' => true]);
        $newProfile = TeamProfile::create([
            'user_id' => $newLeader->id, 'department_id' => $dept->id,
            'name_ar' => 'و', 'position_ar' => 'قائد', 'member_type' => 'staff', 'is_active' => true,
        ]);
        $this->actingAs($admin)->putJson("/api/admin/team-profiles/{$newProfile->id}", ['is_leader' => true])->assertOk();

        $this->assertSame($oldLeader->id, $report->fresh()->submitted_by);
        $this->assertSame($newLeader->id, $dept->fresh()->leader_id);

        // New leader can create the CURRENT week's report; old week is untouched.
        $this->actingAs($newLeader)->postJson('/api/operations/weekly-reports', [
            'department_id' => $dept->id, 'achievements' => 'a', 'planned' => 'b',
        ])->assertStatus(201);
        $this->assertSame($oldLeader->id, $report->fresh()->submitted_by);
    }
}
