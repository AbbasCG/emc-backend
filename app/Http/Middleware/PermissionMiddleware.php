<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Check that the authenticated user holds a specific permission.
 *
 * Usage on routes:
 *   permission:manage_courses
 *   permission:view_finance
 *   permission:manage_courses,view_finance  (user needs ALL listed permissions)
 *
 * auth:sanctum must run before this middleware.
 */
class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user) {
            return $this->deny('Unauthenticated.');
        }

        // super_admin bypasses all permission checks
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        foreach ($permissions as $permission) {
            if (! $user->hasPermission($permission)) {
                return $this->deny("Missing permission: {$permission}.");
            }
        }

        return $next($request);
    }

    private function deny(string $reason): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Forbidden. ' . $reason,
        ], 403);
    }
}
