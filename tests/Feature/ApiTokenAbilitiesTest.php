<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Tests\TestCase;

/**
 * The `abilities` / `ability` aliases exist and behave as Sanctum documents, so scoped API tokens CAN be enforced.
 *
 * They are not attached to any production route yet (that is a separate, reviewed decision). What is locked in here
 * is the contract any such route will rely on: a first-party login token (created with no abilities = ["*"]) passes
 * every check, a scoped token passes only its own scopes, an impersonation token passes no scope.
 */
class ApiTokenAbilitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum', 'abilities:read:courses'])
            ->get('/api/__ability-probe/all', fn () => response()->json(['ok' => true]));
        Route::middleware(['api', 'auth:sanctum', 'abilities:read:courses,read:reports'])
            ->get('/api/__ability-probe/both', fn () => response()->json(['ok' => true]));
        Route::middleware(['api', 'auth:sanctum', 'ability:read:courses,read:reports'])
            ->get('/api/__ability-probe/any', fn () => response()->json(['ok' => true]));
    }

    private function probe(string $token, string $probe): int
    {
        // The sanctum guard caches the resolved user between requests in one test; start each call clean.
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->getJson('/api/__ability-probe/'.$probe)->getStatusCode();
    }

    public function test_the_aliases_point_at_sanctums_middleware(): void
    {
        $aliases = $this->app['router']->getMiddleware();

        $this->assertSame(CheckAbilities::class, $aliases['abilities'] ?? null);
        $this->assertSame(CheckForAnyAbility::class, $aliases['ability'] ?? null);
    }

    public function test_a_first_party_login_token_passes_every_scope(): void
    {
        // Same call as AuthController: createToken('api-token') with no abilities stores ["*"].
        $token = User::factory()->create(['is_active' => true])->createToken('api-token')->plainTextToken;

        $this->assertSame(200, $this->probe($token, 'all'));
        $this->assertSame(200, $this->probe($token, 'both'));
        $this->assertSame(200, $this->probe($token, 'any'));
    }

    public function test_a_scoped_token_passes_only_its_own_scopes(): void
    {
        $token = User::factory()->create(['is_active' => true])->createToken('integration', ['read:courses'])->plainTextToken;

        $this->assertSame(200, $this->probe($token, 'all'));
        $this->assertSame(403, $this->probe($token, 'both'));
        $this->assertSame(200, $this->probe($token, 'any'));

        $other = User::factory()->create(['is_active' => true])->createToken('integration', ['write:webhooks'])->plainTextToken;

        $this->assertSame(403, $this->probe($other, 'all'));
        $this->assertSame(403, $this->probe($other, 'any'));
    }

    public function test_an_impersonation_token_passes_no_scope(): void
    {
        $token = User::factory()->create(['is_active' => true])
            ->createToken('impersonation-by-1', ['via-impersonation'], now()->addMinutes(30))->plainTextToken;

        $this->assertSame(403, $this->probe($token, 'all'));
        $this->assertSame(403, $this->probe($token, 'any'));
    }

    public function test_a_request_without_a_token_is_unauthenticated(): void
    {
        // Answered by auth:sanctum, which every scoped route will carry in front of the ability check.
        $this->getJson('/api/__ability-probe/all')->assertUnauthorized();
    }

    public function test_the_ability_check_itself_refuses_a_request_without_a_sanctum_token(): void
    {
        // No auth:sanctum in front: CheckAbilities alone answers 401 when there is no current access token.
        Route::middleware(['api', 'abilities:read:courses'])
            ->get('/api/__ability-probe/bare', fn () => response()->json(['ok' => true]));

        $this->getJson('/api/__ability-probe/bare')->assertUnauthorized();
    }
}
