<?php

namespace App\Http\Middleware;

use App\Support\ApiCredential;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail-closed gate for scoped API tokens (developer / integration tokens from ApiTokenController).
 *
 * Runs on every api/* request (bootstrap/app.php). A SCOPED token is refused (403) on every route that does not
 * declare the scope it needs with `token.scope:<scope>[,<scope>...]`, and on a route that does, unless the token
 * holds EVERY listed scope. A scope name alone therefore never unlocks anything: a route has to opt in.
 *
 * Every other credential passes untouched, so role / permission / department checks stay the only authority:
 *   - SPA cookie session and first-party "*" login tokens;
 *   - impersonation tokens (they act as the target user; sensitive routes refuse them with `credential.first_party`);
 *   - guests (each route's own auth middleware answers them).
 *
 * The same class is also the `token.scope` route alias, so a declared route re-checks the scope even if this group
 * entry were ever removed. For non-scoped credentials the alias is a no-op.
 */
class EnforceScopedTokenAccess
{
    public const ALIAS = 'token.scope';

    public function handle(Request $request, Closure $next, string ...$declared): Response
    {
        if (ApiCredential::kind($request) !== ApiCredential::SCOPED) {
            return $next($request);
        }

        $required = $declared !== [] ? $declared : $this->declaredOnRoute($request);

        if ($required === []) {
            return $this->deny('This API token is not allowed to call this endpoint.', 'هذا الرمز البرمجي غير مصرّح له باستخدام هذه الواجهة.');
        }

        $held = ApiCredential::scopes($request);

        foreach ($required as $scope) {
            if (! in_array($scope, $held, true)) {
                return $this->deny('This API token lacks the scope required by this endpoint.', 'هذا الرمز البرمجي لا يملك الصلاحية المطلوبة لهذه الواجهة.');
            }
        }

        return $next($request);
    }

    /**
     * Every scope declared on the matched route through the `token.scope:` alias (all of them are required).
     *
     * @return array<int, string>
     */
    private function declaredOnRoute(Request $request): array
    {
        $route = $request->route();

        if (! $route || ! method_exists($route, 'gatherMiddleware')) {
            return [];
        }

        $scopes = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            // Alias form (`token.scope:a,b`) or class form (EnforceScopedTokenAccess::class.':a,b'). A declaration hidden
            // inside a named middleware group is not seen here and fails closed (403): declare scopes on the route itself.
            $prefix = match (true) {
                str_starts_with($middleware, self::ALIAS.':') => self::ALIAS.':',
                str_starts_with($middleware, self::class.':') => self::class.':',
                default => null,
            };

            if ($prefix !== null) {
                foreach (explode(',', substr($middleware, strlen($prefix))) as $scope) {
                    $scope = trim($scope);
                    if ($scope !== '') {
                        $scopes[] = $scope;
                    }
                }
            }
        }

        return array_values(array_unique($scopes));
    }

    private function deny(string $message, string $messageAr): Response
    {
        return response()->json([
            'success' => false,
            'code' => 'token_scope_denied',
            'message' => $messageAr,
            'message_en' => $message,
        ], 403);
    }
}
