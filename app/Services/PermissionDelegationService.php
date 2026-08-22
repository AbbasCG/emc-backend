<?php

namespace App\Services;

use App\Models\User;
use App\Models\DelegatablePermission;
use App\Models\UserPermissionAssignment;

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
        
        if (in_array($permissionKey, $systemPermissions)) {
            return false; // Department managers cannot delegate these
        }

        // For department manager, ensure target is in their department
        if ($actor->hasRole('department_manager')) {
            // Support both direct department_id and user_departments table 
            $actorDepartments = $actor->departments()->pluck('departments.id')->toArray();
            if ($actor->department_id && !in_array($actor->department_id, $actorDepartments)) {
                $actorDepartments[] = $actor->department_id;
            }
            
            $targetDepartments = $target->departments()->pluck('departments.id')->toArray();
            if ($target->department_id && !in_array($target->department_id, $targetDepartments)) {
                $targetDepartments[] = $target->department_id;
            }
            
            // Check intersection (target must belong to at least one department the actor manages)
            $commonDepartments = array_intersect($actorDepartments, $targetDepartments);
            
            if (empty($commonDepartments)) {
                return false;
            }
            
            // The actor MUST possess the permission to delegate it (permission.use)
            $actorEffective = $this->getEffectivePermissions($actor);
            if (!in_array($permissionKey, $actorEffective)) {
                return false;
            }
            
            // The actor MUST be explicitly allowed to delegate it (permission.delegate)
            $role = \App\Models\Role::where('name', $actor->role)->first();
            $roleId = $role ? $role->id : null;
            
            $canDelegate = DelegatablePermission::whereHas('permission', function ($query) use ($permissionKey) {
                $query->where('name', $permissionKey);
            })->where(function ($query) use ($actor, $roleId) {
                $query->where('user_id', $actor->id);
                if ($roleId) {
                    $query->orWhere('role_id', $roleId);
                }
            })->where('can_grant', true)->exists();

            return $canDelegate;
        }
        
        return false;
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
