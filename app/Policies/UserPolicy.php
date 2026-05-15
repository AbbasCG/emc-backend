<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /** super_admin passes every check */
    public function before(User $actor, string $ability): ?bool
    {
        if ($actor->isSuperAdmin()) {
            return true;
        }

        return null;
    }

    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('view_users');
    }

    public function view(User $actor, User $target): bool
    {
        // Users can always view themselves
        return $actor->id === $target->id || $actor->hasPermission('view_users');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('create_users');
    }

    public function update(User $actor, User $target): bool
    {
        // Cannot edit a super_admin if not super_admin yourself
        if ($target->isSuperAdmin()) {
            return false;
        }

        // Users can update themselves (limited fields)
        return $actor->id === $target->id || $actor->hasPermission('update_users');
    }

    public function delete(User $actor, User $target): bool
    {
        // Cannot delete yourself or a super_admin
        if ($actor->id === $target->id || $target->isSuperAdmin()) {
            return false;
        }

        return $actor->hasPermission('delete_users');
    }

    public function manageRoles(User $actor): bool
    {
        return $actor->hasPermission('manage_roles');
    }
}
