<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Department-scoped reporting authorization — membership and leadership are
 * deliberately separate concepts (see DepartmentAccessService):
 *   - MEMBERSHIP (user_departments pivot / active team_members) grants READ
 *     access only (view reports, view the roster).
 *   - LEADERSHIP (departments.leader_id) is the ONLY thing that grants
 *     report-creation rights for non-global roles. A department-scoped role
 *     name (e.g. programs_manager) never implies leadership by itself.
 * Must work generically for every real role slug and every department —
 * no per-user or per-role hardcoding anywhere in the assertions below.
 */
class DepartmentScopedReportingTest extends TestCase
{
    use RefreshDatabase;

    /** Every department-scoped manager role from RoleSeeder that may lead a department. */
    public static function departmentScopedRoles(): array
    {
        return [
            ['programs_manager'],
            ['operations_manager'],
            ['marketing_manager'],
            ['hr_manager'],
            ['finance_manager'],
            ['quality_manager'],
            ['partnerships_manager'],
            ['community_manager'],
            ['support_agent'],
            ['department_manager'],
            ['section_lead'],
        ];
    }

    private function makeDepartment(array $overrides = []): Department
    {
        return Department::create(array_merge([
            'name' => 'Test Dept ' . Str::random(6),
            'name_ar' => 'إدارة تجريبية',
            'slug' => 'dept-' . Str::random(8),
            'status' => 'active',
            'is_active' => true,
        ], $overrides));
    }

    /** A user who merely BELONGS to the department (membership pivot) — no leadership. */
    private function makeMemberUser(string $role, Department $department): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        $user->departments()->attach($department->id, ['is_primary' => true]);

        return $user;
    }

    /** A user who LEADS the department (departments.leader_id) — the only thing granting report creation. */
    private function makeLeaderUser(string $role, Department $department): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        $department->update(['leader_id' => $user->id]);

        return $user;
    }

    /** An active team_members row for $user in $department, with its now-required canonical TeamProfile. */
    private function makeTeamMemberRow(User $user, Department $department, string $status = 'active'): TeamMember
    {
        $profile = TeamProfile::create([
            'user_id' => $user->id,
            'name_ar' => $user->name,
            'position_ar' => 'عضو',
            'department_id' => $department->id,
            'member_type' => 'staff',
            'is_active' => true,
        ]);

        return TeamMember::create([
            'user_id' => $user->id, 'team_profile_id' => $profile->id,
            'department_id' => $department->id, 'status' => $status,
        ]);
    }

    // ── CASE A: ordinary active member (no leadership) → 403 on create ───────

    #[DataProvider('departmentScopedRoles')]
    public function test_case_a_ordinary_member_cannot_create_meeting_report(string $role): void
    {
        $dept = $this->makeDepartment();
        $user = $this->makeMemberUser($role, $dept);

        $response = $this->actingAs($user)->postJson('/api/operations/meeting-reports', [
            'department_id' => $dept->id,
            'title' => 'اجتماع',
            'meeting_date' => now()->toDateString(),
            'ratings' => [],
        ]);

        $response->assertForbidden();
    }

    #[DataProvider('departmentScopedRoles')]
    public function test_case_a_ordinary_member_cannot_create_weekly_report(string $role): void
    {
        $dept = $this->makeDepartment();
        $user = $this->makeMemberUser($role, $dept);

        $response = $this->actingAs($user)->postJson('/api/operations/weekly-reports', [
            'department_id' => $dept->id,
            'achievements' => 'x',
            'planned' => 'y',
        ]);

        $response->assertForbidden();
    }

    /** Membership must still grant read access even though it no longer grants creation. */
    #[DataProvider('departmentScopedRoles')]
    public function test_ordinary_member_can_still_read_own_department_reports_and_manifest(string $role): void
    {
        $dept = $this->makeDepartment();
        $user = $this->makeMemberUser($role, $dept);

        $this->actingAs($user)->getJson('/api/operations/meeting-reports')->assertOk();
        $this->actingAs($user)->getJson("/api/operations/departments/{$dept->id}/members")->assertOk();

        // But the report-creation manifest is leadership-scoped: a non-leader gets nothing to create for.
        $response = $this->actingAs($user)->getJson('/api/operations/departments/my-access');
        $response->assertOk();
        $response->assertJsonPath('data.allowed_departments', []);
    }

    // ── CASE B: actual department leader → 201 on create ──────────────────────

    #[DataProvider('departmentScopedRoles')]
    public function test_case_b_actual_leader_can_create_meeting_report(string $role): void
    {
        $dept = $this->makeDepartment();
        $leader = $this->makeLeaderUser($role, $dept);

        $response = $this->actingAs($leader)->postJson('/api/operations/meeting-reports', [
            'department_id' => $dept->id,
            'title' => 'اجتماع القائد',
            'meeting_date' => now()->toDateString(),
            'ratings' => [],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.department.id', $dept->id);
    }

    #[DataProvider('departmentScopedRoles')]
    public function test_case_b_actual_leader_can_create_weekly_report(string $role): void
    {
        $dept = $this->makeDepartment();
        $leader = $this->makeLeaderUser($role, $dept);

        $response = $this->actingAs($leader)->postJson('/api/operations/weekly-reports', [
            'department_id' => $dept->id,
            'achievements' => 'أنجزنا كذا',
            'planned' => 'سننجز كذا',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.department.id', $dept->id);
    }

    /** The leader's own manifest exposes exactly the department(s) they lead. */
    public function test_leader_manifest_lists_only_led_department(): void
    {
        $dept = $this->makeDepartment();
        $otherDept = $this->makeDepartment();
        $leader = $this->makeLeaderUser('programs_manager', $dept);

        $response = $this->actingAs($leader)->getJson('/api/operations/departments/my-access');

        $response->assertOk();
        $response->assertJsonPath('data.department_scope', 'restricted');
        $ids = collect($response->json('data.allowed_departments'))->pluck('id');
        $this->assertTrue($ids->contains($dept->id));
        $this->assertFalse($ids->contains($otherDept->id));
    }

    // ── CASE C: leader of dept A tries to create for dept B → 403 ────────────

    #[DataProvider('departmentScopedRoles')]
    public function test_case_c_leader_of_department_a_gets_403_for_department_b(string $role): void
    {
        $deptA = $this->makeDepartment();
        $deptB = $this->makeDepartment();
        $leader = $this->makeLeaderUser($role, $deptA);

        $response = $this->actingAs($leader)->postJson('/api/operations/meeting-reports', [
            'department_id' => $deptB->id,
            'title' => 'اجتماع إدارة أخرى',
            'meeting_date' => now()->toDateString(),
            'ratings' => [],
        ]);

        $response->assertForbidden();
    }

    // ── CASE D: leader who is ALSO an active team member → still allowed (leadership, not membership) ──

    public function test_case_d_leader_who_is_also_team_member_is_allowed(): void
    {
        $dept = $this->makeDepartment();
        $leader = $this->makeLeaderUser('programs_manager', $dept);
        $this->makeTeamMemberRow($leader, $dept);

        $response = $this->actingAs($leader)->postJson('/api/operations/meeting-reports', [
            'department_id' => $dept->id,
            'title' => 'اجتماع',
            'meeting_date' => now()->toDateString(),
            'ratings' => [],
        ]);

        $response->assertCreated();
    }

    // ── CASE E: manager-looking role, active team member, but NOT the configured leader → 403 ──

    public function test_case_e_manager_role_active_member_without_leadership_still_403(): void
    {
        $dept = $this->makeDepartment();
        // A different user holds actual leadership — this user is merely an active member.
        $realLeader = User::factory()->create(['role' => 'programs_manager', 'is_active' => true]);
        $dept->update(['leader_id' => $realLeader->id]);

        $lookalike = User::factory()->create(['role' => 'programs_manager', 'is_active' => true]);
        $this->makeTeamMemberRow($lookalike, $dept);

        $response = $this->actingAs($lookalike)->postJson('/api/operations/meeting-reports', [
            'department_id' => $dept->id,
            'title' => 'اجتماع',
            'meeting_date' => now()->toDateString(),
            'ratings' => [],
        ]);

        $response->assertForbidden();
    }

    // ── CASE F / G: privileged system roles override ─────────────────────────

    #[DataProvider('privilegedRoles')]
    public function test_case_fg_privileged_role_can_create_reports_for_any_department(string $role): void
    {
        $dept = $this->makeDepartment();
        $admin = User::factory()->create(['role' => $role, 'is_active' => true]);

        $meeting = $this->actingAs($admin)->postJson('/api/operations/meeting-reports', [
            'department_id' => $dept->id, 'title' => 'اجتماع', 'meeting_date' => now()->toDateString(), 'ratings' => [],
        ]);
        $meeting->assertCreated();

        $weekly = $this->actingAs($admin)->postJson('/api/operations/weekly-reports', [
            'department_id' => $dept->id, 'achievements' => 'x', 'planned' => 'y',
        ]);
        $weekly->assertCreated();
    }

    public static function privilegedRoles(): array
    {
        return [
            ['super_admin'],
            ['tech_admin'],
            ['executive_admin'],
            ['admin'],
        ];
    }

    // ── CASE H: department with no leader configured → nobody (non-global) can create ──

    public function test_case_h_department_with_no_leader_blocks_ordinary_members(): void
    {
        $dept = $this->makeDepartment(); // leader_id stays null
        $member = $this->makeMemberUser('programs_manager', $dept);

        $response = $this->actingAs($member)->postJson('/api/operations/meeting-reports', [
            'department_id' => $dept->id,
            'title' => 'اجتماع',
            'meeting_date' => now()->toDateString(),
            'ratings' => [],
        ]);

        $response->assertForbidden();

        // The manifest reflects "nothing to create for" so the frontend can hide the create action.
        $manifest = $this->actingAs($member)->getJson('/api/operations/departments/my-access');
        $manifest->assertOk();
        $manifest->assertJsonPath('data.allowed_departments', []);
    }

    // ── CASE I: leadership reassigned A → B, live, no code change ────────────

    public function test_case_i_leadership_reassignment_takes_effect_immediately(): void
    {
        $dept = $this->makeDepartment();
        $userA = User::factory()->create(['role' => 'programs_manager', 'is_active' => true]);
        $userB = User::factory()->create(['role' => 'programs_manager', 'is_active' => true]);
        $dept->update(['leader_id' => $userA->id]);

        // A can create.
        $this->actingAs($userA)->postJson('/api/operations/meeting-reports', [
            'department_id' => $dept->id, 'title' => 'أول', 'meeting_date' => now()->toDateString(), 'ratings' => [],
        ])->assertCreated();

        // Reassign leadership — this is the ONLY change, via the canonical column.
        $dept->update(['leader_id' => $userB->id]);

        // A immediately loses the ability.
        $this->actingAs($userA)->postJson('/api/operations/meeting-reports', [
            'department_id' => $dept->id, 'title' => 'ثانٍ', 'meeting_date' => now()->toDateString(), 'ratings' => [],
        ])->assertForbidden();

        // B immediately gains it.
        $this->actingAs($userB)->postJson('/api/operations/meeting-reports', [
            'department_id' => $dept->id, 'title' => 'ثالث', 'meeting_date' => now()->toDateString(), 'ratings' => [],
        ])->assertCreated();
    }

    // ── Member loading (unchanged — still membership-based, read-only concern) ──

    public function test_member_loading_still_works_for_active_team_members(): void
    {
        $dept = $this->makeDepartment();
        $leader = $this->makeLeaderUser('programs_manager', $dept);
        $member = User::factory()->create(['role' => 'instructor']);
        $this->makeTeamMemberRow($member, $dept);

        $response = $this->actingAs($leader)->getJson("/api/operations/departments/{$dept->id}/members");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($member->id));
    }

    public function test_inactive_team_member_excluded_from_department_members(): void
    {
        $dept = $this->makeDepartment();
        $leader = $this->makeLeaderUser('programs_manager', $dept);
        $inactive = User::factory()->create(['role' => 'instructor']);
        $this->makeTeamMemberRow($inactive, $dept, 'inactive');

        $response = $this->actingAs($leader)->getJson("/api/operations/departments/{$dept->id}/members");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($inactive->id));
    }

    public function test_member_or_leader_gets_403_loading_other_department_members(): void
    {
        $dept = $this->makeDepartment();
        $otherDept = $this->makeDepartment();
        $leader = $this->makeLeaderUser('programs_manager', $dept);

        $response = $this->actingAs($leader)->getJson("/api/operations/departments/{$otherDept->id}/members");

        $response->assertForbidden();
    }

    // ── List scoping (read — membership OR leadership OR global) ─────────────

    public function test_meeting_report_list_is_scoped_to_accessible_departments(): void
    {
        $dept = $this->makeDepartment();
        $otherDept = $this->makeDepartment();
        $member = $this->makeMemberUser('programs_manager', $dept);
        // Official reports are read by whoever MANAGES the department (its leader), not by every member.
        $dept->update(['leader_id' => $member->id]);
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($admin)->postJson('/api/operations/meeting-reports', [
            'department_id' => $dept->id, 'title' => 'own', 'meeting_date' => now()->toDateString(), 'ratings' => [],
        ])->assertCreated();
        $this->actingAs($admin)->postJson('/api/operations/meeting-reports', [
            'department_id' => $otherDept->id, 'title' => 'other', 'meeting_date' => now()->toDateString(), 'ratings' => [],
        ])->assertCreated();

        $response = $this->actingAs($member)->getJson('/api/operations/meeting-reports');

        $response->assertOk();
        $titles = collect($response->json('data.data'))->pluck('title');
        $this->assertTrue($titles->contains('own'));
        $this->assertFalse($titles->contains('other'));
    }

    // ── Fully unassigned user (no role-based shortcut of any kind) ───────────

    public function test_department_scoped_role_with_no_assignment_gets_403(): void
    {
        $dept = $this->makeDepartment();
        $user = User::factory()->create(['role' => 'programs_manager', 'is_active' => true]);

        $response = $this->actingAs($user)->postJson('/api/operations/meeting-reports', [
            'department_id' => $dept->id,
            'title' => 'x',
            'meeting_date' => now()->toDateString(),
            'ratings' => [],
        ]);

        $response->assertForbidden();
    }
}
