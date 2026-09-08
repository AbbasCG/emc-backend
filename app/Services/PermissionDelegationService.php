<?php

namespace App\Services;

use App\Models\User;
use App\Models\DelegatablePermission;
use App\Models\UserPermissionAssignment;

/**
 * Delegation policy is DEFAULT-DENY: a permission is delegatable only if an
 * explicit `DelegatablePermission(can_grant: true)` row exists for the
 * actor's user_id or role_id. A permission with NO configured
 * DelegatablePermission row at all is NOT delegatable — new/unconfigured
 * permissions must never become delegatable automatically. (A prior branch
 * experimented with a default-allow fallback when no row existed; that
 * policy was rejected on product review and must not be reintroduced.)
 *
 * Leadership is resolved exclusively via DepartmentAccessService
 * (departments.leader_id) — never role slug — so any actual department
 * leader, regardless of role name, can delegate permissions they hold
 * within their own department(s) once those permissions are explicitly
 * marked delegatable.
 */
class PermissionDelegationService
{
    /**
     * Check if a user can delegate a specific permission to another user.
     */
    public function canDelegate(User $actor, User $target, string $permissionKey): bool
    {
        // System level restricted permissions (cannot be delegated by managers)
        $systemPermissions = ['system.manage', 'roles.manage', 'users.impersonate', 'technology.manage', 'delegations.manage_all'];

        // Super admin and Tech admin can delegate anything
        if ($actor->isSuperAdmin() || $actor->isTechAdmin()) {
            return true;
        }

        // Cannot delegate to oneself
        if ($actor->id === $target->id) {
            return false;
        }

        if (in_array($permissionKey, $systemPermissions, true)) {
            return false; // Department managers cannot delegate these, ever
        }

        // Canonical leadership check — departments.leader_id via DepartmentAccessService,
        // never role slug. Any actual leader qualifies, not just role=department_manager.
        $deptAccess = app(DepartmentAccessService::class);
        $managedDepartmentIds = $deptAccess->getLedDepartmentIds($actor);
        $isGlobal = $deptAccess->isGlobal($actor);

        if (!$isGlobal && $managedDepartmentIds->isEmpty()) {
            return false;
        }

        if (!$isGlobal) {
            // Target must be in a department the actor manages — either accessible
            // (leader/member) or an operational team member of it. No cross-department delegation.
            $targetDepartmentIds = $deptAccess->getAccessibleDepartmentIds($target);
            $intersection = $managedDepartmentIds->intersect($targetDepartmentIds);

            if ($intersection->isEmpty()) {
                $targetMemberDeptIds = $deptAccess->getMemberDepartmentIds($target);
                if ($managedDepartmentIds->intersect($targetMemberDeptIds)->isEmpty()) {
                    return false;
                }
            }
        }

        // The actor MUST possess the permission themselves to delegate it.
        $actorEffective = $this->getEffectivePermissions($actor);
        if (!in_array($permissionKey, $actorEffective, true)) {
            return false;
        }

        // DEFAULT-DENY: the permission must be EXPLICITLY configured as
        // delegatable (by user_id or role_id) with can_grant=true. No matching
        // row => not delegatable, regardless of what the actor personally holds.
        $role = \App\Models\Role::where('name', $actor->role)->first();
        $roleId = $role ? $role->id : null;

        return DelegatablePermission::whereHas('permission', function ($query) use ($permissionKey) {
            $query->where('name', $permissionKey);
        })->where(function ($query) use ($actor, $roleId) {
            $query->where('user_id', $actor->id);
            if ($roleId) {
                $query->orWhere('role_id', $roleId);
            }
        })->where('can_grant', true)->exists();
    }

    /**
     * Get the effective permissions for a user taking into account assignments.
     */
    public function getEffectivePermissions(User $user): array
    {
        // DO NOT call basePermissions directly if it causes a loop, but User::basePermissions is safe
        $basePermissions = $user->basePermissions();
        
        // Let's resolve from assignments
        $assignments = UserPermissionAssignment::where('user_id', $user->id)
            ->where(function($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->with('permission')
            ->get();

        $effective = array_flip($basePermissions);

        foreach ($assignments as $assignment) {
            if (!$assignment->permission) continue;
            
            $permName = $assignment->permission->name;
            if ($assignment->state === 'deny') {
                unset($effective[$permName]);
            } elseif ($assignment->state === 'allow') {
                $effective[$permName] = true;
            }
        }

        return array_keys($effective);
    }
}
