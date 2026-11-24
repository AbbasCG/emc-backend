<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route entry for a READ surface that was role-gated and is now also open to an explicit permission.
 *
 * Usage on routes (first parameter = the permission key, every following one = a role slug):
 *   role.or.permission:consultant_applications.view,admin,super_admin,tech_admin,operations_manager
 *
 * Semantics: pass when the user's role (or specialised department role) is in the list, super_admin / tech_admin (root, exactly
 * as RoleMiddleware and PermissionMiddleware define it), OR the user effectively holds the permission (role grant + per-user allow,
 * minus a per-user deny: User::hasPermission()). It adds a way IN for the listed permission only; the roles keep what they had.
 *
 * It is deliberately NOT a general "any of". Put the sibling write routes of the same resource in a plain `role:` group so the
 * permission can never reach them.
 *
 * auth:sanctum must run before this middleware.
 */
class RoleOrPermissionMiddleware
{
    /** Legacy slug aliases, mirroring RoleMiddleware. */
    private const ALIASES = [
        'instructor' => ['teacher', 'instructor'],
        'teacher' => ['teacher', 'instructor'],
    ];

    public function handle(Request $request, Closure $next, string $permission, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return $this->deny();
        }

        if ($user->isSuperAdmin() || $user->isTechAdmin()) {
            return $next($request);
        }

        $specializedRole = method_exists($user, 'getDepartmentSpecializedRole') ? $user->getDepartmentSpecializedRole() : null;

        foreach ($roles as $role) {
            $accepted = self::ALIASES[$role] ?? [$role];
            if (in_array($user->role, $accepted, true) || ($specializedRole && in_array($specializedRole, $accepted, true))) {
                return $next($request);
            }
        }

        if ($user->hasPermission($permission)) {
            return $next($request);
        }

        return $this->deny();
    }

    private function deny(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Forbidden. You do not have permission to perform this action.',
        ], 403);
    }
}
