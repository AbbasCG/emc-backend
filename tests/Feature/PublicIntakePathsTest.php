<?php

namespace Tests\Feature;

use App\Support\PublicIntakePaths;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicIntakePathsTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<Route> */
    private function routesMatching(string $pattern): array
    {
        return array_values(array_filter(
            iterator_to_array(RouteFacade::getRoutes()),
            fn (Route $route) => Str::is($pattern, $route->uri()),
        ));
    }

    public function test_every_exempt_path_matches_a_route_and_none_of_them_requires_authentication(): void
    {
        foreach (PublicIntakePaths::CSRF_EXEMPT as $pattern) {
            $routes = $this->routesMatching($pattern);
            $this->assertNotEmpty($routes, "$pattern matches no route (dead exemption)");

            foreach ($routes as $route) {
                $writes = array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']);
                if ($writes === []) {
                    continue;
                }
                foreach ($route->gatherMiddleware() as $middleware) {
                    $middleware = is_string($middleware) ? $middleware : 'closure';
                    $this->assertFalse(
                        Str::startsWith($middleware, ['auth', 'role', 'learner', 'Illuminate\\Auth\\Middleware\\Authenticate']),
                        "$pattern exempts ".implode('|', $writes)." {$route->uri()}, which requires authentication ($middleware)"
                    );
                }
            }
        }
    }

    public function test_exemptions_are_never_open_ended_prefixes(): void
    {
        foreach (PublicIntakePaths::CSRF_EXEMPT as $pattern) {
            $this->assertStringStartsWith('api/', $pattern);
            $this->assertFalse(str_ends_with($pattern, '*'), "$pattern would exempt everything below it");
        }
    }

    public function test_csrf_is_enforced_for_the_spa_origin_except_on_the_listed_guest_endpoints(): void
    {
        // The test environment skips CSRF verification entirely; present as a real one for this request.
        $this->app->instance('env', 'local');
        config(['sanctum.stateful' => ['localhost:3000']]);
        $spa = ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/', 'Accept' => 'application/json'];

        $this->postJson('/api/auth/login', [], $spa)->assertStatus(419);          // not exempt: CSRF still enforced
        $this->postJson('/api/contact', [], $spa)->assertStatus(422);              // exempt: reaches validation
        $this->postJson('/api/volunteer-requests', [], $spa)->assertStatus(422);
        $this->postJson('/api/forms/does-not-exist/submit', [], $spa)->assertNotFound();
    }
}
