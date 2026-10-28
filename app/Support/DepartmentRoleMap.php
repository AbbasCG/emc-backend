<?php

namespace App\Support;

/**
 * Which specialised role (if any) the leader of a department acts with. Reads config/department_roles.php and refuses
 * privileged roles, so no department slug can ever be turned into admin-tier authority.
 */
final class DepartmentRoleMap
{
    public static function roleForSlug(?string $slug): ?string
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        $role = config('department_roles.leader_roles.'.$slug);
        if (! is_string($role) || $role === '') {
            return null;
        }

        if (! config('department_roles.allow_privileged_roles', false)
            && in_array($role, (array) config('department_roles.privileged_roles', []), true)) {
            return null;
        }

        return $role;
    }
}
