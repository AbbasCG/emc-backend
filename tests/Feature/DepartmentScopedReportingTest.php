<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Generic department-scoping for meeting reports / weekly reports / member
 * loading — must work for every real department-scoped manager role, not
 * just programs_manager, and must not trust a client-supplied department_id.
 */
class DepartmentScopedReportingTest extends TestCase
{
    use RefreshDatabase;

    /** Every department-scoped manager role from RoleSeeder that may create reports. */
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

    private function makeScopedUser(string $role, Department $department): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        $user->departments()->attach($department->id, ['is_primary' => true]);

        return $user;
    }

    // ── 1. Own department visible, others hidden (manifest) ──────────────────

    #[DataProvider('departmentScopedRoles')]
    public function test_scoped_manager_manifest_only_lists_own_department(string $role): void
    {
        $dept = $this->makeDepartment();
        $otherDept = $this->makeDepartment();
        $user = $this->makeScopedUser($role, $dept);

        $response = $this->actingAs($user)->getJson('/api/operations/departments/my-access');

        $response->assertOk();
        $response->assertJsonPath('data.department_scope', 'restricted');
        $response->assertJsonPath('data.can_select_any_department', false);
        $ids = collect($response->json('data.allowed_departments'))->pluck('id');
        $this->assertTrue($ids->contains($dept->id));
        $this->assertFalse($ids->contains($otherDept->id));
    }

    public function test_privileged_admin_manifest_is_global(): void
    {
        $this->makeDepartment();
        $this->makeDepartment();
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $response = $this->actingAs($admin)->getJson('/api/operations/departments/my-access');

        $response->assertOk();
        $response->assertJsonPath('data.department_scope', 'global');
        $response->assertJsonPath('data.can_select_any_department', true);
        $this->assertGreaterThanOrEqual(2, count($response->json('data.allowed_departments')));
    }

    // ── 2. Meeting report creation ────────────────────────────────────────────

    #[DataProvider('departmentScopedRoles')]
    public function test_scoped_manager_can_create_meeting_report_for_own_department(string $role): void
    {
        $dept = $this->makeDepartment();
        $user = $this->makeScopedUser($role, $dept);

        $response = $this->actingAs($user)->postJson('/api/operations/meeting-reports', [
            'department_id' => $dept->id,
            'title' => 'اجتماع تجريبي',
            'meeting_date' => now()->toDateString(),
            'ratings' => [],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.department.id', $dept->id);
    }

    #[DataProvider('departmentScopedRoles')]
    public function test_scoped_manager_gets_403_creating_meeting_report_for_other_department(string $role): void
    {
        $dept = $this->makeDepartment();
        $otherDept = $this->makeDepartment();
        $user = $this->makeScopedUser($role, $dept);

        $response = $this->actingAs($user)->postJson('/api/operations/meeting-reports', [
            'department_id' => $otherDept->id,
            'title' => 'اجتماع إدارة أخرى',
            'meeting_date' => now()->toDateString(),
            'ratings' => [],
        ]);

        $response->assertForbidden();
    }

    public function test_privileged_admin_can_create_meeting_report_for_any_department(): void
    {
        $dept = $this->makeDepartment();
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $response = $this->actingAs($admin)->postJson('/api/operations/meeting-reports', [
            'department_id' => $dept->id,
            'title' => 'اجتماع',
            'meeting_date' => now()->toDateString(),
            'ratings' => [],
        ]);

        $response->assertCreated();
    }

    // ── 3. Weekly report creation ─────────────────────────────────────────────

    #[DataProvider('departmentScopedRoles')]
    public function test_scoped_manager_can_create_weekly_report_for_own_department(string $role): void
    {
        $dept = $this->makeDepartment();
        $user = $this->makeScopedUser($role, $dept);

        $response = $this->actingAs($user)->postJson('/api/operations/weekly-reports', [
            'department_id' => $dept->id,
            'achievements' => 'أنجزنا كذا',
            'planned' => 'سننجز كذا',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.department.id', $dept->id);
    }

    public function test_scoped_manager_gets_403_creating_weekly_report_for_other_department(): void
    {
        $dept = $this->makeDepartment();
        $otherDept = $this->makeDepartment();
        $user = $this->makeScopedUser('programs_manager', $dept);

        $response = $this->actingAs($user)->postJson('/api/operations/weekly-reports', [
            'department_id' => $otherDept->id,
            'achievements' => 'x',
            'planned' => 'y',
        ]);

        $response->assertForbidden();
    }

    // ── 4. Member loading ─────────────────────────────────────────────────────

    public function test_scoped_manager_loads_own_department_members(): void
    {
        $dept = $this->makeDepartment();
        $user = $this->makeScopedUser('programs_manager', $dept);
        $member = User::factory()->create(['role' => 'instructor']);
        TeamMember::create([
            'user_id' => $member->id,
            'department_id' => $dept->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)->getJson("/api/operations/departments/{$dept->id}/members");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($member->id));
    }

    public function test_inactive_team_member_excluded_from_department_members(): void
    {
        $dept = $this->makeDepartment();
        $user = $this->makeScopedUser('programs_manager', $dept);
        $inactive = User::factory()->create(['role' => 'instructor']);
        TeamMember::create([
            'user_id' => $inactive->id,
            'department_id' => $dept->id,
            'status' => 'inactive',
        ]);

        $response = $this->actingAs($user)->getJson("/api/operations/departments/{$dept->id}/members");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($inactive->id));
    }

    public function test_scoped_manager_gets_403_loading_other_department_members(): void
    {
        $dept = $this->makeDepartment();
        $otherDept = $this->makeDepartment();
        $user = $this->makeScopedUser('programs_manager', $dept);

        $response = $this->actingAs($user)->getJson("/api/operations/departments/{$otherDept->id}/members");

        $response->assertForbidden();
    }

    // ── 5. List scoping ───────────────────────────────────────────────────────

    public function test_meeting_report_list_is_scoped_to_allowed_departments(): void
    {
        $dept = $this->makeDepartment();
        $otherDept = $this->makeDepartment();
        $user = $this->makeScopedUser('programs_manager', $dept);
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($admin)->postJson('/api/operations/meeting-reports', [
            'department_id' => $dept->id, 'title' => 'own', 'meeting_date' => now()->toDateString(), 'ratings' => [],
        ])->assertCreated();
        $this->actingAs($admin)->postJson('/api/operations/meeting-reports', [
            'department_id' => $otherDept->id, 'title' => 'other', 'meeting_date' => now()->toDateString(), 'ratings' => [],
        ])->assertCreated();

        $response = $this->actingAs($user)->getJson('/api/operations/meeting-reports');

        $response->assertOk();
        $titles = collect($response->json('data.data'))->pluck('title');
        $this->assertTrue($titles->contains('own'));
        $this->assertFalse($titles->contains('other'));
    }

    // ── 6. Leader-based access (Department::leader_id) still works ───────────

    public function test_department_leader_without_pivot_row_can_manage_own_department(): void
    {
        $leader = User::factory()->create(['role' => 'department_manager', 'is_active' => true]);
        $dept = $this->makeDepartment(['leader_id' => $leader->id]);

        $response = $this->actingAs($leader)->postJson('/api/operations/meeting-reports', [
            'department_id' => $dept->id,
            'title' => 'اجتماع القائد',
            'meeting_date' => now()->toDateString(),
            'ratings' => [],
        ]);

        $response->assertCreated();
    }

    // ── 7. Unassigned user has zero access regardless of role ────────────────

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
