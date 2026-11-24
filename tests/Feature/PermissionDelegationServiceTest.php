<?php

namespace Tests\Feature;

use App\Models\DelegatablePermission;
use App\Models\Department;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermissionAssignment;
use App\Services\PermissionDelegationService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the ported, corrected delegation policy: DEFAULT-DENY. A
 * permission is delegatable only when an explicit
 * DelegatablePermission(can_grant: true) row exists for the actor's user_id
 * or role_id — a permission with no configured row at all must never become
 * delegatable automatically. Leadership is resolved exclusively via
 * DepartmentAccessService (departments.leader_id), never role slug.
 */
class PermissionDelegationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function grantActorThePermission(User $actor, Permission $permission): void
    {
        UserPermissionAssignment::create([
            'user_id' => $actor->id, 'permission_id' => $permission->id, 'state' => 'allow',
            'scope_type' => 'global', 'source_type' => 'direct',
        ]);
    }

    private function makePermission(string $name): Permission
    {
        return Permission::create(['name' => $name, 'display_name' => 'Test', 'group' => 'test']);
    }

    /** Places $user "in" $dept per DepartmentAccessService::getMemberDepartmentIds() (the pivot). */
    private function placeInDepartment(User $user, Department $dept): void
    {
        $user->departments()->attach($dept->id);
    }

    public function test_explicitly_delegatable_permission_can_be_delegated(): void
    {
        $dept = Department::create(['name' => 'D', 'name_ar' => 'إدارة', 'slug' => 'd1', 'status' => 'active', 'is_active' => true]);
        $actor = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $dept->update(['leader_id' => $actor->id]);
        $target = User::factory()->create(['is_active' => true]);
        $this->placeInDepartment($target, $dept);
        // An ordinary (implicit, in Permissions::all()) key: keys outside all() are explicit-only and super_admin-governed, never delegatable by a leader.
        $permission = $this->makePermission(Permissions::VIEW_REPORTS);
        $this->grantActorThePermission($actor, $permission);
        DelegatablePermission::create(['permission_id' => $permission->id, 'user_id' => $actor->id, 'scope_type' => 'department', 'can_grant' => true]);

        $svc = app(PermissionDelegationService::class);
        $this->assertTrue($svc->canDelegate($actor, $target, Permissions::VIEW_REPORTS));
    }

    public function test_unconfigured_permission_is_denied_by_default(): void
    {
        $dept = Department::create(['name' => 'D', 'name_ar' => 'إدارة', 'slug' => 'd2', 'status' => 'active', 'is_active' => true]);
        $actor = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $dept->update(['leader_id' => $actor->id]);
        $target = User::factory()->create(['is_active' => true]);
        $this->placeInDepartment($target, $dept);
        $permission = $this->makePermission('test.unconfigured');
        $this->grantActorThePermission($actor, $permission);
        // No DelegatablePermission row created at all for this permission.

        $svc = app(PermissionDelegationService::class);
        $this->assertFalse($svc->canDelegate($actor, $target, 'test.unconfigured'), 'a permission with zero configuration must never be delegatable');
    }

    public function test_actor_without_the_permission_cannot_delegate_it(): void
    {
        $dept = Department::create(['name' => 'D', 'name_ar' => 'إدارة', 'slug' => 'd3', 'status' => 'active', 'is_active' => true]);
        $actor = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $dept->update(['leader_id' => $actor->id]);
        $target = User::factory()->create(['is_active' => true]);
        $this->placeInDepartment($target, $dept);
        $permission = $this->makePermission('test.not_held');
        DelegatablePermission::create(['permission_id' => $permission->id, 'user_id' => $actor->id, 'scope_type' => 'department', 'can_grant' => true]);
        // Actor is NOT granted the permission itself.

        $svc = app(PermissionDelegationService::class);
        $this->assertFalse($svc->canDelegate($actor, $target, 'test.not_held'));
    }

    public function test_target_outside_actors_department_is_denied(): void
    {
        $deptA = Department::create(['name' => 'A', 'name_ar' => 'أ', 'slug' => 'd4a', 'status' => 'active', 'is_active' => true]);
        $deptB = Department::create(['name' => 'B', 'name_ar' => 'ب', 'slug' => 'd4b', 'status' => 'active', 'is_active' => true]);
        $actor = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $deptA->update(['leader_id' => $actor->id]);
        $target = User::factory()->create(['is_active' => true]);
        $this->placeInDepartment($target, $deptB);
        $permission = $this->makePermission('test.cross_dept');
        $this->grantActorThePermission($actor, $permission);
        DelegatablePermission::create(['permission_id' => $permission->id, 'user_id' => $actor->id, 'scope_type' => 'department', 'can_grant' => true]);

        $svc = app(PermissionDelegationService::class);
        $this->assertFalse($svc->canDelegate($actor, $target, 'test.cross_dept'));
    }

    public function test_role_alone_does_not_grant_delegation_authority(): void
    {
        // operations_manager role, but NOT departments.leader_id of anything.
        $actor = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $target = User::factory()->create(['is_active' => true]);
        $permission = $this->makePermission('test.role_alone');
        $this->grantActorThePermission($actor, $permission);
        DelegatablePermission::create(['permission_id' => $permission->id, 'user_id' => $actor->id, 'scope_type' => 'department', 'can_grant' => true]);

        $svc = app(PermissionDelegationService::class);
        $this->assertFalse($svc->canDelegate($actor, $target, 'test.role_alone'), 'role slug alone must never substitute for departments.leader_id');
    }

    public function test_cross_department_escalation_denied_even_with_role_based_delegatable_permission(): void
    {
        $deptA = Department::create(['name' => 'A', 'name_ar' => 'أ', 'slug' => 'd6a', 'status' => 'active', 'is_active' => true]);
        $deptB = Department::create(['name' => 'B', 'name_ar' => 'ب', 'slug' => 'd6b', 'status' => 'active', 'is_active' => true]);
        $actor = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $deptA->update(['leader_id' => $actor->id]);
        $target = User::factory()->create(['is_active' => true]);
        $this->placeInDepartment($target, $deptB);
        $permission = $this->makePermission('test.role_delegatable');
        $this->grantActorThePermission($actor, $permission);
        $role = \App\Models\Role::firstOrCreate(['name' => 'operations_manager'], ['display_name' => 'Operations Manager', 'is_system' => true]);
        DelegatablePermission::create(['permission_id' => $permission->id, 'role_id' => $role->id, 'scope_type' => 'department', 'can_grant' => true]);

        $svc = app(PermissionDelegationService::class);
        $this->assertFalse($svc->canDelegate($actor, $target, 'test.role_delegatable'), 'a role-wide delegatable permission still must not cross department boundaries');
    }

    public function test_cannot_delegate_to_self(): void
    {
        $dept = Department::create(['name' => 'D', 'name_ar' => 'إدارة', 'slug' => 'd7', 'status' => 'active', 'is_active' => true]);
        $actor = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $dept->update(['leader_id' => $actor->id]);
        $permission = $this->makePermission('test.self');
        $this->grantActorThePermission($actor, $permission);
        DelegatablePermission::create(['permission_id' => $permission->id, 'user_id' => $actor->id, 'scope_type' => 'department', 'can_grant' => true]);

        $svc = app(PermissionDelegationService::class);
        $this->assertFalse($svc->canDelegate($actor, $actor, 'test.self'));
    }

    public function test_system_permissions_never_delegatable_even_if_explicitly_configured(): void
    {
        $dept = Department::create(['name' => 'D', 'name_ar' => 'إدارة', 'slug' => 'd8', 'status' => 'active', 'is_active' => true]);
        $actor = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $dept->update(['leader_id' => $actor->id]);
        $target = User::factory()->create(['is_active' => true]);
        $this->placeInDepartment($target, $dept);
        $permission = $this->makePermission('roles.manage');
        $this->grantActorThePermission($actor, $permission);
        DelegatablePermission::create(['permission_id' => $permission->id, 'user_id' => $actor->id, 'scope_type' => 'department', 'can_grant' => true]);

        $svc = app(PermissionDelegationService::class);
        $this->assertFalse($svc->canDelegate($actor, $target, 'roles.manage'));
    }

    public function test_super_admin_can_delegate_anything_without_configuration(): void
    {
        $actor = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $target = User::factory()->create(['is_active' => true]);
        $this->makePermission('test.super_admin_case');

        $svc = app(PermissionDelegationService::class);
        $this->assertTrue($svc->canDelegate($actor, $target, 'test.super_admin_case'));
    }
}
