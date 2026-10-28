<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Support\DepartmentRoleMap;
use App\Support\Permissions;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Authorization guard for the dynamic department roles: department membership, a department's name/slug, or even leading a
 * department must never turn a `department_manager` into a technical or executive administrator.
 */
class DepartmentRoleEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    private function department(string $slug, string $status = 'active'): Department
    {
        return Department::create(['name' => $slug, 'name_ar' => $slug, 'slug' => $slug, 'status' => $status, 'is_active' => $status === 'active']);
    }

    private function manager(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => 'department_manager', 'is_active' => true], $attributes));
    }

    private function httpStatus(User $user, string $method, string $uri): int
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'sanctum')->json($method, $uri, $method === 'GET' ? [] : ['settings' => []])->status();
    }

    /** Admin-only surfaces that tech_admin / executive_admin / admin could reach but a department manager never can. */
    private function assertNoAdminReach(User $user): void
    {
        $this->assertSame(403, $this->httpStatus($user, 'PUT', '/api/admin/email-settings'), 'must not write admin settings');
        $this->assertSame(403, $this->httpStatus($user, 'GET', '/api/admin/users'), 'must not list all users');
    }

    private function assertNotPrivileged(User $user): void
    {
        $user = $user->fresh();
        $this->assertSame('department_manager', $user->role, 'the stored role is never rewritten');
        $this->assertNotContains($user->effectiveRole(), ['tech_admin', 'executive_admin', 'admin', 'super_admin']);
        $this->assertNotContains($user->getDepartmentSpecializedRole(), ['tech_admin', 'executive_admin', 'admin', 'super_admin']);
        foreach (['tech_admin', 'executive_admin', 'admin', 'super_admin'] as $role) {
            $this->assertFalse($user->hasRole($role), "hasRole($role)");
        }
        $this->assertFalse($user->isTechAdmin());
        $this->assertFalse($user->isExecutiveAdmin());
        $this->assertFalse($user->isAdmin());
        $this->assertLessThan(count(Permissions::all()), count($user->allPermissions()), 'must not hold every permission');
    }

    public function test_plain_member_of_technology_department_gets_no_tech_admin(): void
    {
        $dept = $this->department('technology');
        $user = $this->manager();
        $user->departments()->attach($dept->id, ['is_primary' => true]);

        $this->assertNull($user->fresh()->getDepartmentSpecializedRole());
        $this->assertSame('department_manager', $user->fresh()->effectiveRole());
        $this->assertNotPrivileged($user);
        $this->assertNoAdminReach($user);
    }

    public function test_plain_member_of_executive_management_department_gets_no_executive_admin(): void
    {
        $dept = $this->department('executive-management');
        $user = $this->manager();
        $user->departments()->attach($dept->id, ['is_primary' => true]);

        $this->assertNull($user->fresh()->getDepartmentSpecializedRole());
        $this->assertNotPrivileged($user);
        $this->assertNoAdminReach($user);
    }

    public function test_free_text_department_value_grants_nothing(): void
    {
        $this->department('technology');
        $this->department('finance');
        foreach (['technology', 'executive-management', 'finance'] as $text) {
            $user = $this->manager(['department' => $text]);
            $this->assertNull($user->fresh()->getDepartmentSpecializedRole(), $text);
            $this->assertNotPrivileged($user);
        }
    }

    public function test_leader_of_technology_department_still_gets_no_tech_admin(): void
    {
        $dept = $this->department('technology');
        $user = $this->manager(['department' => 'technology']);
        $dept->update(['leader_id' => $user->id]);
        $user->departments()->sync([$dept->id => ['is_primary' => true]]);

        $this->assertNull($user->fresh()->getDepartmentSpecializedRole());
        $this->assertNotPrivileged($user);
        $this->assertNoAdminReach($user);
    }

    public function test_leader_of_executive_management_department_still_gets_no_executive_admin(): void
    {
        $dept = $this->department('executive-management');
        $user = $this->manager(['department' => 'executive-management']);
        $dept->update(['leader_id' => $user->id]);

        $this->assertNull($user->fresh()->getDepartmentSpecializedRole());
        $this->assertNotPrivileged($user);
        $this->assertNoAdminReach($user);
    }

    public function test_deputy_leader_gets_no_specialised_role(): void
    {
        $dept = $this->department('finance');
        $deputy = $this->manager();
        $dept->update(['deputy_leader_id' => $deputy->id]);

        $this->assertNull($deputy->fresh()->getDepartmentSpecializedRole());
    }

    public function test_plain_member_of_finance_does_not_inherit_finance_manager(): void
    {
        $dept = $this->department('finance');
        $user = $this->manager();
        $user->departments()->attach($dept->id, ['is_primary' => true]);

        $user = $user->fresh();
        $this->assertNull($user->getDepartmentSpecializedRole());
        $this->assertFalse($user->hasRole('finance_manager'));
        $this->assertFalse($user->hasPermission(Permissions::VIEW_PAYMENTS));
    }

    public function test_recorded_leader_of_an_active_finance_department_inherits_finance_manager_only(): void
    {
        $dept = $this->department('finance');
        $user = $this->manager();
        $dept->update(['leader_id' => $user->id]);

        $user = $user->fresh();
        $this->assertSame('finance_manager', $user->getDepartmentSpecializedRole());
        $this->assertTrue($user->hasRole('finance_manager'));
        $this->assertTrue($user->hasPermission(Permissions::VIEW_PAYMENTS));
        $this->assertNotPrivileged($user);
        $this->assertNoAdminReach($user);
    }

    public function test_leader_of_an_archived_department_inherits_nothing(): void
    {
        $dept = $this->department('finance', 'archived');
        $user = $this->manager();
        $dept->update(['leader_id' => $user->id]);

        $this->assertNull($user->fresh()->getDepartmentSpecializedRole());
    }

    public function test_leading_a_department_without_the_department_manager_role_grants_nothing(): void
    {
        $dept = $this->department('finance');
        $user = User::factory()->create(['role' => 'instructor', 'is_active' => true]);
        $dept->update(['leader_id' => $user->id]);

        $this->assertNull($user->fresh()->getDepartmentSpecializedRole());
        $this->assertSame('instructor', $user->fresh()->effectiveRole());
        $this->assertFalse($user->fresh()->hasRole('finance_manager'));
    }

    public function test_a_poisoned_map_cannot_hand_out_privileged_roles(): void
    {
        config(['department_roles.leader_roles.technology' => 'tech_admin', 'department_roles.leader_roles.executive-management' => 'executive_admin']);
        $this->assertNull(DepartmentRoleMap::roleForSlug('technology'));
        $this->assertNull(DepartmentRoleMap::roleForSlug('executive-management'));

        $dept = $this->department('technology');
        $user = $this->manager();
        $dept->update(['leader_id' => $user->id]);
        $this->assertNull($user->fresh()->getDepartmentSpecializedRole());
        $this->assertNoAdminReach($user);
    }

    public function test_privileged_roles_need_an_explicit_system_rule(): void
    {
        config(['department_roles.leader_roles.technology' => 'tech_admin']);
        $this->assertNull(DepartmentRoleMap::roleForSlug('technology'), 'blocked by default');

        config(['department_roles.allow_privileged_roles' => true]);
        $this->assertSame('tech_admin', DepartmentRoleMap::roleForSlug('technology'), 'only an explicit rule lifts the block');
    }

    public function test_config_map_never_contains_a_privileged_role(): void
    {
        $privileged = config('department_roles.privileged_roles');
        foreach (config('department_roles.leader_roles') as $slug => $role) {
            $this->assertNotContains($role, $privileged, "$slug maps to a privileged role");
        }
        $this->assertFalse((bool) config('department_roles.allow_privileged_roles'));
    }

    public function test_the_api_never_reports_a_privileged_role_for_a_department_manager(): void
    {
        $dept = $this->department('technology');
        $user = $this->manager(['department' => 'technology']);
        $user->departments()->attach($dept->id, ['is_primary' => true]);
        $dept->update(['leader_id' => $user->id]);

        $this->app['auth']->forgetGuards();
        $response = $this->actingAs($user, 'sanctum')->getJson('/api/auth/me')->assertOk();
        $payload = $response->json('data') ?? $response->json();

        $this->assertSame('department_manager', $payload['role']);
        $this->assertSame('department_manager', $payload['user']['effective_role']);
        $this->assertNull($payload['user']['specialized_role']);
    }
}
