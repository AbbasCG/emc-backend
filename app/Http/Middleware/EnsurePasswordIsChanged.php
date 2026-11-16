<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces an account that still holds a temporary password (users.must_change_password = true) to choose its own before it can use
 * anything else.
 *
 * Registered once, in the `api` middleware group (bootstrap/app.php), so it covers EVERY authenticated API route without editing them.
 * It resolves the Sanctum user itself because it runs before the route's own `auth:sanctum`; a request without a valid token passes
 * straight through to that guard (401), exactly as before.
 *
 * What it does NOT touch:
 *  - public routes and the READ side of optional-auth routes (browsing keeps working for a flagged user). The WRITE side of an
 *    optional-auth route (POST/PUT/PATCH/DELETE with `optional.sanctum`) is gated: those routes act as the signed-in user (enrol,
 *    submit a form...), so a flagged account must not use them either;
 *  - an impersonation session (the admin acting as that user must still be able to stop it);
 *  - the allow-list below: the calls the SPA needs to learn the flag, change the password, or log out.
 */
class EnsurePasswordIsChanged
{
    public const CODE = 'PASSWORD_CHANGE_REQUIRED';

    public const MESSAGE = 'يجب تغيير كلمة المرور المؤقتة قبل المتابعة.';

    /** Request paths (no leading slash) a flagged user may still call. */
    public const ALLOWED_PATHS = [
        'api/auth/me',
        'api/auth/logout',
        'api/auth/change-password',
        'api/auth/me/access-manifest',
        'api/auth/me/page-access',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('sanctum')->user();

        if (! $user || ! $user->must_change_password) {
            return $next($request);
        }

        if ($request->attributes->get('is_impersonating', false)) {
            return $next($request);
        }

        if ($request->is(self::ALLOWED_PATHS)) {
            return $next($request);
        }

        $gated = $this->routeHasMiddleware($request, fn (string $m) => $m === 'auth' || str_starts_with($m, 'auth:'))
            || (! $request->isMethodSafe() && $this->routeHasMiddleware($request, fn (string $m) => $m === 'optional.sanctum'));

        if (! $gated) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'code'    => self::CODE,
            'message' => self::MESSAGE,
        ], 403);
    }

    /** True when the matched route carries a middleware accepted by $match. */
    private function routeHasMiddleware(Request $request, \Closure $match): bool
    {
        $route = $request->route();
        if ($route === null) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && $match($middleware)) {
                return true;
            }
        }

        return false;
    }
}
