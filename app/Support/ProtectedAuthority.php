<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;

/**
 * The single reusable rule this Phase 0 hardening introduces: only
 * super_admin may exercise authority over the protected super_admin
 * identity/role. Centralizes what was previously a set of scattered,
 * inconsistently-applied `isSuperAdmin()`-only checks — present in some
 * controllers (AdminUserController::store()/update(), UserRoleController)
 * but MISSING in others (RoleController::setPermissions(),
 * AdminUserController::destroy()/restore()), which is exactly how those
 * gaps existed.
 *
 * Deliberately scoped to super_admin only — not executive_admin or any
 * other admin-tier role — matching the exact business requirement audited
 * ("SUPER_ADMIN is the protected root authority"). Widening this scope is
 * a separate product decision, not part of this hardening pass.
 *
 * This does NOT change tech_admin's identity or its existing broad
 * technical/Policy/Gate bypass (RoleMiddleware, PermissionMiddleware,
 * Gate::before) for everything else — it only adds an explicit boundary at
 * the specific operations that exercise authority over the super_admin
 * identity itself. See Gate::before's own docblock in AppServiceProvider
 * for why that blanket bypass is left untouched by this change.
 */
class ProtectedAuthority
{
    /** The one protected root role. */
    public const PROTECTED_ROLE = 'super_admin';

    public static function isProtectedRoleName(?string $roleName): bool
    {
        return $roleName === self::PROTECTED_ROLE;
    }

    public static function isProtectedRole(Role $role): bool
    {
        return self::isProtectedRoleName($role->name);
    }

    public static function isProtectedUser(User $user): bool
    {
        return self::isProtectedRoleName($user->role);
    }

    /**
     * Abort with 403 unless the actor is super_admin. Call this immediately
     * before any operation that would exercise authority over the protected
     * role/identity — never rely on the general Gate::before bypass for
     * these specific operations, since that bypass also covers tech_admin.
     */
    public static function requireSuperAdmin(User $actor, string $message = 'Only super_admin may perform this action.'): void
    {
        abort_unless($actor->isSuperAdmin(), 403, $message);
    }
}
