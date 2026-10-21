<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Role checks must answer BEFORE a route parameter is resolved to a record.
 *
 * Laravel resolves implicit route-model bindings in the `api` group's SubstituteBindings middleware; the `role:` middleware used to run
 * after it. So an account WITHOUT the role got 404 for an id that does not exist and 403 for one that does, and could enumerate which
 * financial records (manual payments, reconciliation cases, orders ...) exist. With the role middleware ahead of the bindings the
 * answer is a plain 403 either way.
 */
class AuthorizationBeforeBindingTest extends TestCase
{
    use RefreshDatabase;

    private const MISSING_ID = 987654321;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function user(string $role): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role, 'is_system' => false]);

        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    public function test_manual_payment_confirmation_does_not_reveal_which_ids_exist(): void
    {
        $url = '/api/finance/manual-payments/'.self::MISSING_ID.'/confirm';

        foreach (['volunteer', 'student', 'instructor', 'hr_manager', 'marketing_manager', 'partner'] as $role) {
            $this->actingAs($this->user($role), 'sanctum')->postJson($url)->assertStatus(403);
        }
        // the roles that ARE allowed to confirm get the honest answer for an id that does not exist
        $this->actingAs($this->user('finance_manager'), 'sanctum')->postJson($url)->assertStatus(404);
    }

    /**
     * Every route that is behind a plain `role:` list and has a bound parameter answers 403 (never 404) to an account outside the list,
     * whatever the id. The sweep covers all of them, so a new route cannot reintroduce the oracle.
     */
    public function test_every_role_protected_route_with_a_bound_parameter_refuses_before_looking_the_record_up(): void
    {
        $outsider = $this->user('volunteer');
        $checked = 0;
        $leaks = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/') || ! preg_match('/\{[a-zA-Z]+\??\}/', $uri)) {
                continue;
            }

            $roleLists = [];
            foreach ($route->gatherMiddleware() as $mw) {
                // `role:a,b` as written on the route, or the resolved class name followed by the same list
                if (is_string($mw) && preg_match('/^(?:role|'.preg_quote(\App\Http\Middleware\RoleMiddleware::class, '/').'):(.+)$/', $mw, $m)) {
                    $roleLists[] = explode(',', $m[1]);
                }
            }
            // only plain role lists that exclude the outsider (anything with extra gates is out of this sweep's scope)
            if ($roleLists === [] || array_filter($roleLists, fn (array $roles) => in_array('volunteer', $roles, true))) {
                continue;
            }
            $middleware = array_map(fn ($m) => is_string($m) ? $m : get_class($m), $route->gatherMiddleware());
            if (array_filter($middleware, fn (string $m) => str_contains($m, 'permission') || str_contains($m, 'role.or.leader') || str_contains($m, 'DepartmentLeaderOrRole'))) {
                continue;
            }

            $path = '/'.preg_replace('/\{[a-zA-Z]+\??\}/', (string) self::MISSING_ID, $uri);
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $response = $this->actingAs($outsider, 'sanctum')->json($method, $path);
                $checked++;
                if ($response->getStatusCode() === 404) {
                    $leaks[] = "{$method} {$uri}";
                }
            }
        }

        $this->assertGreaterThan(100, $checked, 'sanity: the sweep must cover the role-protected routes');
        $this->assertSame([], $leaks, "routes that answer 404 (record lookup) to an account outside the role list:\n".implode("\n", $leaks));
    }
}
