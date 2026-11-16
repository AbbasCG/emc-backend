<?php

namespace Tests\Feature;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * CI guard for the temporary-password gate (EnsurePasswordIsChanged).
 *
 * The gate blocks a flagged user on routes that carry `auth` / `auth:*`, and on the write methods of `optional.sanctum` routes.
 * A route that resolves the signed-in user BY HAND (Auth::guard('sanctum')->user(), $request->user() ...) without either of those
 * would let a flagged account straight through, so this walks every registered api route, reads the source of its action, and fails
 * when it reads the user but has no authentication middleware.
 *
 * It is a heuristic (the action's own source only, not helpers it calls), which is why adding a route to ALLOWED needs a reason.
 */
class RoutesAuthenticationGuardTest extends TestCase
{
    /**
     * uri => why this route may read a user without an auth middleware.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        // Every entry must be justified. Keep this list short.
        'api/knowledge' => 'GET only: shows or hides internal articles by role. A safe method on a public route is never gated, so a flagged user may browse.',
        'api/search'    => 'GET only: filters results by role. Same reason: browsing stays open for a flagged user.',
        'api/analytics/events' => 'sendBeacon telemetry: reads the user only to attribute a page-view event; it acts for nobody, and a 403 would only lose the beacon.',
    ];

    private const USER_READING = [
        '/Auth::guard\(/',
        '/\bauth\(\)\s*->\s*(user|id|check)\b/',
        '/\bauth\(\s*[\'"][a-z]+[\'"]\s*\)/',
        '/\bAuth::(user|id|check|guard)\b/',
        '/->user\(\)/',
        '/->user\(\s*[\'"][a-z]+[\'"]\s*\)/',
    ];

    public function test_no_route_reads_the_signed_in_user_without_an_authentication_middleware(): void
    {
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            /** @var LaravelRoute $route */
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            $middleware = array_filter($route->gatherMiddleware(), 'is_string');
            $guarded = collect($middleware)->contains(fn (string $m) => $m === 'auth' || str_starts_with($m, 'auth:') || $m === 'optional.sanctum');
            if ($guarded) {
                continue;
            }

            $source = $this->actionSource($route);
            if ($source === null) {
                continue;
            }

            foreach (self::USER_READING as $pattern) {
                if (preg_match($pattern, $source)) {
                    $offenders[$route->uri()] = ($offenders[$route->uri()] ?? '') . implode('|', $route->methods()) . ' ' . $route->getActionName() . '  ';
                    break;
                }
            }
        }

        $offenders = array_diff_key($offenders, self::ALLOWED);

        $this->assertSame(
            [],
            $offenders,
            "These routes read the signed-in user but carry no auth / auth:* / optional.sanctum middleware, so the temporary-password gate cannot cover them.\n"
            . "Add auth:sanctum (or optional.sanctum for a public route that may know the user), or justify an entry in ALLOWED:\n" . print_r($offenders, true)
        );
    }

    public function test_every_allowed_entry_still_exists_so_the_list_cannot_rot(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())->map(fn (LaravelRoute $r) => $r->uri())->all();

        foreach (array_keys(self::ALLOWED) as $uri) {
            $this->assertContains($uri, $uris, "ALLOWED lists {$uri} but no such route exists any more: remove it.");
        }
    }

    private function actionSource(LaravelRoute $route): ?string
    {
        try {
            $action = $route->getAction('uses');

            if ($action instanceof \Closure) {
                $reflection = new \ReflectionFunction($action);
            } else {
                [$class, $method] = array_pad(explode('@', (string) $action), 2, '__invoke');
                if (! class_exists($class) || ! method_exists($class, $method)) {
                    return null;
                }
                $reflection = new \ReflectionMethod($class, $method);
            }

            $file = $reflection->getFileName();
            if (! $file || str_contains(str_replace('\\', '/', $file), '/vendor/')) {
                return null;
            }

            $lines = file($file);

            return implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
        } catch (\Throwable) {
            return null;
        }
    }
}
