<?php

namespace Tests\Feature;

use App\Models\DelegatablePermission;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionAssignment;
use App\Services\PermissionDelegationService;
use App\Support\Permissions;
use App\Support\ProtectedAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 0 security hardening regression suite — proves the confirmed
 * privilege-escalation gaps are closed via real HTTP requests (not just
 * service-method unit calls), and that nothing legitimate regressed:
 *
 *   1. RoleController::setPermissions() — tech_admin could overwrite
 *      super_admin's entire permission set. FIXED.
 *   2. AdminUserController::destroy()/restore() — tech_admin could
 *      deactivate/restore a super_admin account. FIXED.
 *   3. PermissionDelegationService::canDelegate() — tech_admin could
 *      delegate users.impersonate without holding it itself. FIXED.
 *
 * Scoped exactly to the audited business rule: only super_admin is
 * protected root authority — executive_admin and other admin-tier roles
 * are unaffected by this pass.
 */
class ProtectedSuperAdminAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private function makeSuperAdminRole(): Role
    {
        return Role::firstOrCreate(
            ['name' => 'super_admin'],
            ['display_name' => 'Super Admin', 'is_system' => true],
        );
    }

    private function makeOrdinaryRole(string $name = 'operations_manager'): Role
    {
        return Role::firstOrCreate(
            ['name' => $name],
            ['display_name' => ucfirst($name), 'is_system' => true],
        );
    }

    private function makePermission(string $name): Permission
    {
        return Permission::firstOrCreate(['name' => $name], ['display_name' => 'Test', 'group' => 'test']);
    }

    // ── 1 & 12 — super_admin can manage ordinary role permissions, and its own ──

    public function test_super_admin_can_manage_an_ordinary_roles_permissions(): void
    {
        $this->makeOrdinaryRole();
        $perm = $this->makePermission('test.ordinary_perm');
        $actor = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $response = $this->actingAs($actor)->putJson('/api/admin/roles/operations_manager/permissions', [
            'permissions' => [$perm->name],
        ]);

        $response->assertOk();
    }

    public function test_super_admin_retains_root_behavior_can_modify_super_admin_role_permissions(): void
    {
        $this->makeSuperAdminRole();
        $perm = $this->makePermission('test.root_perm');
        $actor = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $response = $this->actingAs($actor)->putJson('/api/admin/roles/super_admin/permissions', [
            'permissions' => [$perm->name],
        ]);

        $response->assertOk();
    }

    // ── 2 — tech_admin can manage an allowed ordinary role ──────────────────

    public function test_tech_admin_can_manage_an_ordinary_roles_permissions(): void
    {
        $this->makeOrdinaryRole();
        $perm = $this->makePermission(Permissions::VIEW_REPORTS);
        $actor = User::factory()->create(['role' => 'tech_admin', 'is_active' => true]);

        $response = $this->actingAs($actor)->putJson('/api/admin/roles/operations_manager/permissions', [
            'permissions' => [$perm->name],
        ]);

        $response->assertOk();
    }

    // ── 3 — tech_admin CANNOT modify super_admin permissions (the confirmed fix) ─

    public function test_tech_admin_cannot_modify_super_admin_role_permissions(): void
    {
        $superAdminRole = $this->makeSuperAdminRole();
        $originalPermissionIds = $superAdminRole->permissions()->pluck('permissions.id')->all();
        $perm = $this->makePermission('test.escalation_attempt');
        $actor = User::factory()->create(['role' => 'tech_admin', 'is_active' => true]);

        $response = $this->actingAs($actor)->putJson('/api/admin/roles/super_admin/permissions', [
            'permissions' => [$perm->name],
        ]);

        $response->assertStatus(403);
        $this->assertSame($originalPermissionIds, $superAdminRole->fresh()->permissions()->pluck('permissions.id')->all(), 'super_admin permission set must be untouched');
    }

    // ── 4 & 5 — tech_admin cannot assign or remove the super_admin role (pre-existing, verify no regression) ─

    public function test_tech_admin_cannot_assign_super_admin_role(): void
    {
        $this->makeSuperAdminRole();
        $actor = User::factory()->create(['role' => 'tech_admin', 'is_active' => true]);
        $target = User::factory()->create(['role' => 'student', 'is_active' => true]);

        $response = $this->actingAs($actor)->putJson("/api/admin/users/{$target->id}/role", ['role' => 'super_admin']);

        $response->assertStatus(403);
        $this->assertSame('student', $target->fresh()->role);
    }

    public function test_tech_admin_cannot_remove_super_admin_role(): void
    {
        $actor = User::factory()->create(['role' => 'tech_admin', 'is_active' => true]);
        $superAdminUser = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $response = $this->actingAs($actor)->putJson("/api/admin/users/{$superAdminUser->id}/role", ['role' => 'admin']);

        $response->assertStatus(403);
        $this->assertSame('super_admin', $superAdminUser->fresh()->role);
    }

    // ── 6 — tech_admin cannot deactivate (or restore) super_admin (the confirmed fix) ─

    public function test_tech_admin_cannot_deactivate_super_admin(): void
    {
        $actor = User::factory()->create(['role' => 'tech_admin', 'is_active' => true]);
        $superAdminUser = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $response = $this->actingAs($actor)->deleteJson("/api/admin/users/{$superAdminUser->id}");

        $response->assertStatus(403);
        $this->assertNotSoftDeleted($superAdminUser);
        $this->assertTrue($superAdminUser->fresh()->is_active);
    }

    public function test_tech_admin_cannot_restore_super_admin(): void
    {
        $actor = User::factory()->create(['role' => 'tech_admin', 'is_active' => true]);
        $superAdminUser = User::factory()->create(['role' => 'super_admin', 'is_active' => false]);
        $superAdminUser->delete();

        $response = $this->actingAs($actor)->postJson("/api/admin/users/{$superAdminUser->id}/restore");

        $response->assertStatus(403);
        $this->assertSoftDeleted($superAdminUser);
    }

    public function test_super_admin_can_still_deactivate_and_restore_a_super_admin(): void
    {
        $actor = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $superAdminUser = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($actor)->deleteJson("/api/admin/users/{$superAdminUser->id}")->assertOk();
        $this->assertSoftDeleted($superAdminUser);

        $this->actingAs($actor)->postJson("/api/admin/users/{$superAdminUser->id}/restore")->assertOk();
        $this->assertNotSoftDeleted($superAdminUser->fresh());
    }

    // ── 7 — tech_admin cannot impersonate super_admin (pre-existing, verify no regression) ─

    public function test_tech_admin_cannot_impersonate_super_admin(): void
    {
        $actor = User::factory()->create(['role' => 'tech_admin', 'is_active' => true]);
        UserPermissionAssignment::create([
            'user_id' => $actor->id, 'permission_id' => $this->makePermission('users.impersonate')->id,
            'state' => 'allow', 'scope_type' => 'global', 'source_type' => 'direct_admin',
        ]);
        $superAdminUser = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $response = $this->actingAs($actor)->postJson("/api/admin/impersonate/{$superAdminUser->id}");

        $response->assertStatus(403);
    }

    // ── 8 — tech_admin cannot delegate users.impersonate without holding it (the confirmed fix) ─

    public function test_tech_admin_cannot_delegate_users_impersonate_without_holding_it(): void
    {
        $this->makePermission('users.impersonate');
        $actor = User::factory()->create(['role' => 'tech_admin', 'is_active' => true]);
        $target = User::factory()->create(['is_active' => true]);

        $svc = app(PermissionDelegationService::class);
        $this->assertFalse($svc->canDelegate($actor, $target, 'users.impersonate'), 'tech_admin without an explicit grant must not be able to delegate users.impersonate');

        $response = $this->actingAs($actor)->postJson("/api/department/employees/{$target->id}/permissions/grant", [
            'permission_name' => 'users.impersonate',
        ]);
        $response->assertStatus(403);
        $this->assertSame(0, UserPermissionAssignment::where('user_id', $target->id)->count());
    }

    public function test_tech_admin_can_delegate_users_impersonate_once_explicitly_granted_it(): void
    {
        $perm = $this->makePermission('users.impersonate');
        $actor = User::factory()->create(['role' => 'tech_admin', 'is_active' => true]);
        UserPermissionAssignment::create([
            'user_id' => $actor->id, 'permission_id' => $perm->id,
            'state' => 'allow', 'scope_type' => 'global', 'source_type' => 'direct_admin',
        ]);
        $target = User::factory()->create(['is_active' => true]);

        $svc = app(PermissionDelegationService::class);
        $this->assertTrue($svc->canDelegate($actor, $target, 'users.impersonate'), 'once tech_admin actually holds it, delegation is allowed like any other admin-tier delegation');
    }

    public function test_super_admin_delegating_users_impersonate_still_requires_actual_possession(): void
    {
        // Even super_admin must actually hold users.impersonate to delegate it —
        // matches Gate::before()'s own carve-out (it is never auto-granted to
        // anyone, super_admin included, without an explicit DB row).
        $actor = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->makePermission('users.impersonate');
        $target = User::factory()->create(['is_active' => true]);

        $svc = app(PermissionDelegationService::class);
        $this->assertFalse($svc->canDelegate($actor, $target, 'users.impersonate'));
    }

    // ── 9 — tech_admin cannot self-escalate into protected authority ───────

    public function test_tech_admin_cannot_self_assign_super_admin_role(): void
    {
        $this->makeSuperAdminRole();
        $actor = User::factory()->create(['role' => 'tech_admin', 'is_active' => true]);

        $response = $this->actingAs($actor)->putJson("/api/admin/users/{$actor->id}/role", ['role' => 'super_admin']);

        // Blocked either as "cannot change own role" or the super_admin-assignment
        // guard — either way, must never succeed.
        $response->assertStatus(403);
        $this->assertSame('tech_admin', $actor->fresh()->role);
    }

    public function test_tech_admin_cannot_grant_itself_users_impersonate_via_delegation(): void
    {
        $this->makePermission('users.impersonate');
        $actor = User::factory()->create(['role' => 'tech_admin', 'is_active' => true]);

        $svc = app(PermissionDelegationService::class);
        // Self-delegation is blocked unconditionally regardless of the
        // permission key (existing rule, verified still in force).
        $this->assertFalse($svc->canDelegate($actor, $actor, 'users.impersonate'));
    }

    // ── 10 — department leaders remain unaffected ───────────────────────────

    public function test_department_leader_delegation_of_an_ordinary_permission_is_unaffected(): void
    {
        $dept = \App\Models\Department::create(['name' => 'D', 'name_ar' => 'إدارة', 'slug' => 'phase0-dept', 'status' => 'active', 'is_active' => true]);
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $dept->update(['leader_id' => $leader->id]);
        $target = User::factory()->create(['is_active' => true]);
        $target->departments()->attach($dept->id);

        $perm = $this->makePermission(Permissions::VIEW_KPI);
        UserPermissionAssignment::create([
            'user_id' => $leader->id, 'permission_id' => $perm->id,
            'state' => 'allow', 'scope_type' => 'department', 'source_type' => 'direct_admin',
        ]);
        DelegatablePermission::create(['permission_id' => $perm->id, 'user_id' => $leader->id, 'scope_type' => 'department', 'can_grant' => true]);

        $svc = app(PermissionDelegationService::class);
        $this->assertTrue($svc->canDelegate($leader, $target, Permissions::VIEW_KPI), 'ordinary department-leader delegation must be completely unaffected by the Phase 0 hardening');
    }

    // ── 11 — ordinary admin behavior remains unchanged unless explicitly protected ─

    public function test_ordinary_admin_can_still_edit_a_non_super_admin_user(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $target = User::factory()->create(['role' => 'student', 'is_active' => true]);

        $response = $this->actingAs($actor)->putJson("/api/admin/users/{$target->id}", ['name' => 'Updated Name']);

        $response->assertOk();
        $this->assertSame('Updated Name', $target->fresh()->name);
    }

    public function test_ordinary_admin_still_blocked_from_super_admin_same_as_before(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $superAdminUser = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $response = $this->actingAs($actor)->deleteJson("/api/admin/users/{$superAdminUser->id}");

        // 'admin' role doesn't even reach the destroy route (role:super_admin,tech_admin only).
        $response->assertStatus(403);
    }

    // ── ProtectedAuthority unit-level sanity (the new shared helper itself) ─

    public function test_protected_authority_helper_identifies_only_super_admin(): void
    {
        $superAdminRole = $this->makeSuperAdminRole();
        $ordinaryRole = $this->makeOrdinaryRole('executive_admin');

        $this->assertTrue(ProtectedAuthority::isProtectedRole($superAdminRole));
        $this->assertFalse(ProtectedAuthority::isProtectedRole($ordinaryRole), 'scoped to super_admin only, per the audited business rule — executive_admin is deliberately not included in this pass');

        $superAdminUser = User::factory()->make(['role' => 'super_admin']);
        $execAdminUser = User::factory()->make(['role' => 'executive_admin']);
        $this->assertTrue(ProtectedAuthority::isProtectedUser($superAdminUser));
        $this->assertFalse(ProtectedAuthority::isProtectedUser($execAdminUser));
    }
}
