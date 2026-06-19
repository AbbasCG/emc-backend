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

        // super_admin and tech_admin pass every role check
        if ($user->isSuperAdmin() || $user->isTechAdmin()) {
            return $next($request);
        }

        foreach ($roles as $role) {
            $accepted = self::ALIASES[$role] ?? [$role];
            if (in_array($user->role, $accepted, true)) {
                return $next($request);
            }
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
