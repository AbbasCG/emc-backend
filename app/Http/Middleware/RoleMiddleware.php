<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage on routes:
 *   role:admin
 *   role:admin,teacher
 *   role:super_admin,admin
 *   role:instructor          (also accepts 'teacher' — backward compat)
 *
 * auth:sanctum must run before this middleware so $request->user() is populated.
 */
class RoleMiddleware
{
    /** Legacy slug aliases kept for backward compatibility. */
    private const ALIASES = [
        'instructor' => ['teacher', 'instructor'],
        'teacher'    => ['teacher', 'instructor'],
    ];

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return $this->deny();
        }

        return self::passes($user, $roles) ? $next($request) : $this->deny();
    }

    /**
     * The very rule `role:<roles>` enforces, as a boolean (so a controller can tell the UI what the route would answer):
     * super_admin / tech_admin pass every role check; otherwise the user's role (or specialised department role) must be listed.
     *
     * @param  array<int, string>  $roles
     */
    public static function passes(object $user, array $roles): bool
    {
        if ($user->isSuperAdmin() || $user->isTechAdmin()) {
            return true;
        }

        $specializedRole = method_exists($user, 'getDepartmentSpecializedRole') ? $user->getDepartmentSpecializedRole() : null;

        foreach ($roles as $role) {
            $accepted = self::ALIASES[$role] ?? [$role];
            if (in_array($user->role, $accepted, true) || ($specializedRole && in_array($specializedRole, $accepted, true))) {
                return true;
            }
        }

        return false;
    }

    private function deny(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Forbidden. You do not have permission to perform this action.',
        ], 403);
    }
}
