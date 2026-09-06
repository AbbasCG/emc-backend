<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\TeamProfile;
use App\Models\User;
use App\Services\DepartmentAccessService;
use Database\Seeders\OfficialDepartmentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration coverage for the 3 newly-added official department manager
 * roles (strategy_planning_manager, digital_ambassadors_manager,
 * advisors_manager) alongside the existing ai_manager — proving the
 * canonical rule holds across all 4 official departments (strategy-planning,
 * digital-ambassadors, advisors, ai-transformation): a manager role is
 * navigation/visibility only, never department leadership, membership, or
 * cross-department privilege. Only departments.leader_id (via
 * DepartmentAccessService) grants authority, and only a TeamProfile whose
 * department_id points at the right department makes someone appear as a
 * member in Team Management — never the role alone.
 */
class OfficialDepartmentRolesIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_ROLES = ['strategy_planning_manager', 'digital_ambassadors_manager', 'advisors_manager'];

    private function seedOfficialDepartments(): void
    {
        (new OfficialDepartmentsSeeder())->run();
    }

    public function test_role_registry_contains_the_new_organizational_roles(): void
    {
        $names = array_column(\Database\Seeders\RoleSeeder::ROLES, 'name');

        foreach (self::NEW_ROLES as $role) {
            $this->assertContains($role, $names, "role '$role' must be registered in RoleSeeder::ROLES");
        }
    }

    public function test_role_assignment_alone_grants_no_department_leadership(): void
    {
        $this->seedOfficialDepartments();
        $svc = app(DepartmentAccessService::class);

        foreach (self::NEW_ROLES as $role) {
            $user = User::factory()->create(['role' => $role, 'is_active' => true]);

            $this->assertTrue($svc->getLedDepartmentIds($user)->isEmpty(), "role '$role' alone must lead nothing");
            $this->assertFalse($svc->canManageDepartment($user, 1), "role '$role' alone must not grant manage rights on any department");
        }
    }

    public function test_role_assignment_alone_creates_no_department_membership(): void
    {
        $user = User::factory()->create(['role' => 'strategy_planning_manager', 'is_active' => true]);

        $this->assertSame(0, \App\Models\UserDepartment::where('user_id', $user->id)->count());
        $this->assertSame(0, TeamProfile::where('user_id', $user->id)->count());
    }

    public function test_all_four_official_departments_are_listed_as_active(): void
    {
        $this->seedOfficialDepartments();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $response = $this->actingAs($admin)->getJson('/api/operations/departments');

        $response->assertOk();
        $slugs = collect($response->json('data'))->pluck('slug');

        foreach (['strategy-planning', 'digital-ambassadors', 'advisors', 'ai-transformation'] as $slug) {
            $this->assertTrue($slugs->contains($slug), "official department '$slug' must be listed as active");
        }
    }

    public function test_manager_with_correct_team_profile_appears_under_correct_department_in_team_management(): void
    {
        $this->seedOfficialDepartments();
        $dept = Department::where('slug', 'strategy-planning')->firstOrFail();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $manager = User::factory()->create(['role' => 'strategy_planning_manager', 'is_active' => true]);

        TeamProfile::create([
            'department_id' => $dept->id, 'user_id' => $manager->id,
            'name_ar' => 'مدير الاستراتيجية', 'position_ar' => 'مدير الإدارة', 'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/admin/team');
        $response->assertOk();

        $deptRow = collect($response->json('data'))->firstWhere('slug', 'strategy-planning');
        $this->assertNotNull($deptRow);
        $memberUserIds = collect($deptRow['members'])->pluck('user_id');
        $this->assertTrue($memberUserIds->contains($manager->id), 'manager with a correct TeamProfile must appear under their department');
    }

    public function test_manager_without_team_profile_does_not_appear_as_member_anywhere(): void
    {
        $this->seedOfficialDepartments();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $manager = User::factory()->create(['role' => 'digital_ambassadors_manager', 'is_active' => true]);

        $response = $this->actingAs($admin)->getJson('/api/admin/team');
        $response->assertOk();

        $allMemberUserIds = collect($response->json('data'))
            ->flatMap(fn ($dept) => collect($dept['members'])->pluck('user_id'));

        $this->assertFalse($allMemberUserIds->contains($manager->id), 'a manager role alone must never fabricate a TeamProfile/membership row');
    }

    public function test_no_cross_department_privilege_escalation(): void
    {
        $this->seedOfficialDepartments();
        $deptA = Department::where('slug', 'strategy-planning')->firstOrFail();
        $deptB = Department::where('slug', 'advisors')->firstOrFail();

        $leaderA = User::factory()->create(['role' => 'strategy_planning_manager', 'is_active' => true]);
        $deptA->update(['leader_id' => $leaderA->id]);

        $svc = app(DepartmentAccessService::class);

        $this->assertTrue($svc->canManageDepartment($leaderA, $deptA->id));
        $this->assertFalse($svc->canManageDepartment($leaderA, $deptB->id), 'leading department A must never grant manage rights on department B');
        $this->assertFalse($svc->getLedDepartmentIds($leaderA)->contains($deptB->id));
    }

    public function test_global_admin_override_still_works_across_all_official_departments(): void
    {
        $this->seedOfficialDepartments();
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $svc = app(DepartmentAccessService::class);

        foreach (['strategy-planning', 'digital-ambassadors', 'advisors', 'ai-transformation'] as $slug) {
            $dept = Department::where('slug', $slug)->firstOrFail();
            $this->assertTrue($svc->canManageDepartment($admin, $dept->id), "super_admin must retain override on '$slug'");
        }
    }
}
