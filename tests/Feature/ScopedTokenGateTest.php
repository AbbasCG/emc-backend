<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\ApiTokenController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Middleware\EnforceScopedTokenAccess;
use App\Http\Middleware\RequireFirstPartyCredential;
use App\Models\User;
use Database\Seeders\ImpersonatePermissionSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Scoped API tokens are fail-closed, and credential management / impersonation accept only the real user's own
 * first-party credential (EnforceScopedTokenAccess + RequireFirstPartyCredential).
 *
 * Regressions locked in here: before this gate a token created with ['read:courses'] by an admin acted as that admin
 * everywhere, could mint new tokens and start an impersonation; an impersonator could mint a durable token on the
 * target account or revoke the target's tokens.
 */
class ScopedTokenGateTest extends TestCase
{
    use RefreshDatabase;

    private const SPA = [
        'Origin' => 'http://localhost:5173',
        'Referer' => 'http://localhost:5173/login',
        'X-EMC-Auth-Mode' => 'cookie',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, ImpersonatePermissionSeeder::class]);

        Route::middleware(['api', 'auth:sanctum'])
            ->get('/api/__scope-probe/undeclared', fn () => response()->json(['ok' => true]));
        Route::middleware(['api', 'auth:sanctum', 'token.scope:read:courses'])
            ->get('/api/__scope-probe/courses', fn () => response()->json(['ok' => true]));
        Route::middleware(['api', 'auth:sanctum', 'token.scope:read:courses,read:reports'])
            ->get('/api/__scope-probe/courses-and-reports', fn () => response()->json(['ok' => true]));
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true, 'password' => bcrypt('secret123')]);
    }

    /** Each call starts with no cached guard user, exactly like separate HTTP requests. */
    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    // ── Scoped tokens: refused unless a route opts in ──────────────────────────────────────────────────────────

    public function test_a_scoped_token_is_refused_on_routes_that_do_not_declare_a_scope(): void
    {
        $token = $this->superAdmin()->createToken('integration', ['read:courses'])->plainTextToken;

        $this->as($token)->getJson('/api/__scope-probe/undeclared')->assertForbidden()->assertJsonPath('code', 'token_scope_denied');
        $this->as($token)->getJson('/api/me')->assertForbidden()->assertJsonPath('code', 'token_scope_denied');
        // An owner with admin power does not lend it to the token.
        $this->as($token)->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_a_scoped_token_passes_only_a_route_declaring_every_scope_it_needs(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $courses = $owner->createToken('i1', ['read:courses'])->plainTextToken;
        $both = $owner->createToken('i2', ['read:courses', 'read:reports'])->plainTextToken;
        $webhooks = $owner->createToken('i3', ['write:webhooks'])->plainTextToken;

        $this->as($courses)->getJson('/api/__scope-probe/courses')->assertOk();
        $this->as($courses)->getJson('/api/__scope-probe/courses-and-reports')->assertForbidden();
        $this->as($both)->getJson('/api/__scope-probe/courses-and-reports')->assertOk();
        $this->as($webhooks)->getJson('/api/__scope-probe/courses')->assertForbidden();
    }

    public function test_a_token_with_no_or_unknown_abilities_is_treated_as_scoped(): void
    {
        $owner = $this->superAdmin();

        foreach ([[], ['unknown:thing'], ['via-impersonation', 'read:courses']] as $abilities) {
            $token = $owner->createToken('odd', $abilities)->plainTextToken;
            $this->as($token)->getJson('/api/me')->assertForbidden();
        }
    }

    public function test_a_scoped_token_can_never_mint_tokens_even_holding_every_allowed_scope(): void
    {
        $owner = $this->superAdmin();
        $token = $owner->createToken('all-scopes', ApiTokenController::ALLOWED_SCOPES)->plainTextToken;
        $before = $owner->tokens()->count();

        foreach (['/api/developer/api-tokens', '/api/admin/api-tokens'] as $url) {
            $this->as($token)->postJson($url, ['name' => 'x', 'abilities' => ['read:courses']])->assertForbidden();
            $this->as($token)->getJson($url)->assertForbidden();
        }
        $victim = $owner->createToken('victim')->accessToken;
        $this->as($token)->deleteJson('/api/developer/api-tokens/'.$victim->id)->assertForbidden();

        $this->assertSame($before + 1, $owner->tokens()->count());
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $victim->id]);
    }

    public function test_a_scoped_token_cannot_start_an_impersonation(): void
    {
        $owner = $this->superAdmin();
        $target = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $token = $owner->createToken('all-scopes', ApiTokenController::ALLOWED_SCOPES)->plainTextToken;

        $this->as($token)->postJson("/api/admin/impersonate/{$target->id}")->assertForbidden();

        $this->assertSame(0, $target->tokens()->count());
        $this->assertDatabaseMissing('security_logs', ['event' => 'impersonation_started']);
    }

    // ── First-party credentials keep working ─────────────────────────────────────────────────────────────────

    public function test_a_first_party_login_token_keeps_full_access(): void
    {
        $admin = $this->superAdmin();
        $token = $admin->createToken('api-token')->plainTextToken; // AuthController's call: abilities ["*"]
        $target = User::factory()->create(['role' => 'student', 'is_active' => true]);

        $this->as($token)->getJson('/api/me')->assertOk();
        $this->as($token)->getJson('/api/__scope-probe/undeclared')->assertOk();
        $this->as($token)->getJson('/api/__scope-probe/courses-and-reports')->assertOk();
        $this->as($token)->getJson('/api/developer/api-tokens')->assertOk();
        $this->as($token)->postJson('/api/developer/api-tokens', ['name' => 'ci', 'abilities' => ['read:courses']])->assertCreated();
        $this->as($token)->postJson("/api/admin/impersonate/{$target->id}")->assertOk()->assertJsonStructure(['token']);
    }

    public function test_the_spa_cookie_session_keeps_full_access_and_can_manage_tokens(): void
    {
        $admin = $this->superAdmin();

        $this->withHeaders(self::SPA)->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'secret123'])->assertOk();

        $this->withHeaders(self::SPA)->getJson('/api/__scope-probe/undeclared')->assertOk();
        $this->withHeaders(self::SPA)->getJson('/api/developer/api-tokens')->assertOk();
        $created = $this->withHeaders(self::SPA)
            ->postJson('/api/developer/api-tokens', ['name' => 'ci', 'abilities' => ['read:courses']])
            ->assertCreated();
        $this->withHeaders(self::SPA)->deleteJson('/api/developer/api-tokens/'.$created->json('data.id'))->assertOk();
    }

    // ── Impersonation: no token management, no chained impersonation ─────────────────────────────────────────

    public function test_an_impersonation_token_cannot_manage_tokens_or_impersonate_again(): void
    {
        $admin = $this->superAdmin();
        // An admin-tier target, so the role gate alone would let these calls through: the refusal must be the credential gate.
        $target = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $other = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $existing = $target->createToken('targets-own')->accessToken;

        $imp = $this->as($admin->createToken('api-token')->plainTextToken)
            ->postJson("/api/admin/impersonate/{$target->id}")->assertOk()->json('token');

        $this->as($imp)->getJson('/api/me')->assertOk(); // the preview itself still works
        $this->as($imp)->getJson('/api/developer/api-tokens')->assertForbidden()->assertJsonPath('code', 'impersonation_forbidden');
        $this->as($imp)->postJson('/api/developer/api-tokens', ['name' => 'backdoor'])->assertForbidden()->assertJsonPath('code', 'impersonation_forbidden');
        $this->as($imp)->postJson('/api/admin/api-tokens', ['name' => 'backdoor'])->assertForbidden();
        $this->as($imp)->deleteJson('/api/developer/api-tokens/'.$existing->id)->assertForbidden();
        $this->as($imp)->postJson("/api/admin/impersonate/{$other->id}")->assertForbidden()->assertJsonPath('code', 'impersonation_forbidden');

        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'backdoor']);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $existing->id]);
        $this->assertSame(0, $other->tokens()->count());

        $this->as($imp)->postJson('/api/admin/impersonate/stop')->assertOk();
    }

    public function test_a_cookie_mode_impersonation_cannot_manage_tokens(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->withHeaders(self::SPA)->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'secret123'])->assertOk();
        $this->withHeaders(self::SPA)->postJson("/api/admin/impersonate/{$target->id}")->assertOk()->assertJsonPath('token', null);

        $this->app['auth']->forgetGuards();
        $this->withHeaders(self::SPA)->getJson('/api/developer/api-tokens')->assertForbidden()->assertJsonPath('code', 'impersonation_forbidden');
        $this->withHeaders(self::SPA)->postJson('/api/developer/api-tokens', ['name' => 'backdoor'])->assertForbidden();
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // After stopping, the admin's own session manages tokens again.
        $this->withHeaders(self::SPA)->postJson('/api/admin/impersonate/stop')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(self::SPA)->getJson('/api/developer/api-tokens')->assertOk();
    }

    // ── Expiry of a cookie-mode preview: the crossing request never runs as the target ───────────────────────

    public function test_the_request_that_crosses_the_preview_expiry_is_stopped_and_the_admin_restored(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->withHeaders(self::SPA)->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'secret123'])->assertOk();
        $this->withHeaders(self::SPA)->postJson("/api/admin/impersonate/{$target->id}")->assertOk();

        $this->travel(31)->minutes();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(self::SPA)->postJson('/api/developer/api-tokens', ['name' => 'backdoor'])
            ->assertStatus(409)->assertJsonPath('code', 'impersonation_expired');
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->app['auth']->forgetGuards();
        $this->withHeaders(self::SPA)->getJson('/api/me')->assertOk()->assertJsonPath('data.user.id', $admin->id);
    }

    public function test_an_expired_preview_whose_admin_was_suspended_ends_the_session(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->withHeaders(self::SPA)->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'secret123'])->assertOk();
        $this->withHeaders(self::SPA)->postJson("/api/admin/impersonate/{$target->id}")->assertOk();
        $admin->forceFill(['is_active' => false])->save();

        $this->travel(31)->minutes();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(self::SPA)->getJson('/api/developer/api-tokens')->assertUnauthorized();

        // The session no longer belongs to the target either.
        $this->app['auth']->forgetGuards();
        $this->withHeaders(self::SPA)->getJson('/api/me')->assertUnauthorized();
    }

    // ── Other durable account changes during impersonation ──────────────────────────────────────────────────

    public function test_an_impersonator_cannot_change_the_sign_in_email_or_delete_the_account(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create(['role' => 'student', 'is_active' => true, 'email' => 'target@example.test']);

        $imp = $this->as($admin->createToken('api-token')->plainTextToken)
            ->postJson("/api/admin/impersonate/{$target->id}")->assertOk()->json('token');

        $this->as($imp)->putJson('/api/profile', ['email' => 'attacker@example.test'])
            ->assertForbidden()->assertJsonPath('code', 'impersonation_forbidden');
        $this->as($imp)->putJson('/api/profile', ['name' => 'Renamed in preview'])->assertOk();
        $this->as($imp)->deleteJson('/api/profile')->assertForbidden();

        $fresh = User::withTrashed()->find($target->id);
        $this->assertSame('target@example.test', $fresh->email);
        $this->assertNull($fresh->deleted_at);
    }

    public function test_the_owner_can_still_change_their_own_email(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->as($user->createToken('api-token')->plainTextToken)
            ->putJson('/api/profile', ['email' => 'new-address@example.test'])->assertOk();

        $this->assertSame('new-address@example.test', $user->fresh()->email);
    }

    // ── Edges ────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_scoped_token_is_refused_even_on_a_public_route_that_does_not_opt_in(): void
    {
        Route::middleware(['api'])->get('/api/__scope-probe/public', fn () => response()->json(['ok' => true]));
        $token = User::factory()->create(['is_active' => true])->createToken('integration', ['read:courses'])->plainTextToken;

        $this->getJson('/api/__scope-probe/public')->assertOk(); // anonymous
        $this->as($token)->getJson('/api/__scope-probe/public')->assertForbidden()->assertJsonPath('code', 'token_scope_denied');
    }

    public function test_a_scoped_token_on_an_optional_auth_route_is_still_seen_as_scoped(): void
    {
        Route::middleware(['api', 'optional.sanctum', 'credential.first_party'])
            ->get('/api/__scope-probe/optional', fn () => response()->json(['ok' => true]));
        $user = User::factory()->create(['is_active' => true]);

        $this->as($user->createToken('integration', ['read:courses'])->plainTextToken)
            ->getJson('/api/__scope-probe/optional')->assertForbidden();
        $this->as($user->createToken('api-token')->plainTextToken)
            ->getJson('/api/__scope-probe/optional')->assertOk();
    }

    public function test_a_scoped_token_is_refused_before_route_model_binding(): void
    {
        $token = $this->superAdmin()->createToken('integration', ['read:courses'])->plainTextToken;

        // 403 for a missing id too: the answer must not reveal which user ids exist.
        $this->as($token)->postJson('/api/admin/impersonate/999999')->assertForbidden();
    }

    public function test_the_class_form_of_a_scope_declaration_is_honoured(): void
    {
        Route::middleware(['api', 'auth:sanctum', EnforceScopedTokenAccess::class.':read:courses'])
            ->get('/api/__scope-probe/class-form', fn () => response()->json(['ok' => true]));
        $owner = User::factory()->create(['is_active' => true]);

        $this->as($owner->createToken('i', ['read:courses'])->plainTextToken)->getJson('/api/__scope-probe/class-form')->assertOk();
        $this->as($owner->createToken('i', ['read:reports'])->plainTextToken)->getJson('/api/__scope-probe/class-form')->assertForbidden();
    }

    public function test_a_guest_on_a_first_party_route_gets_401(): void
    {
        Route::middleware(['api', 'credential.first_party'])->get('/api/__scope-probe/first-party', fn () => response()->json(['ok' => true]));

        $this->getJson('/api/__scope-probe/first-party')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    }

    // ── Wiring sweeps: a new route can not silently skip the gates ───────────────────────────────────────────

    /** Middleware that will really run on the route: aliases resolved, groups expanded, withoutMiddleware() applied. */
    private function resolvedMiddleware(\Illuminate\Routing\Route $route): array
    {
        return array_map(
            fn ($m) => is_string($m) ? explode(':', $m, 2)[0] : $m,
            $this->app['router']->gatherRouteMiddleware($route),
        );
    }

    public function test_every_credential_changing_route_requires_a_first_party_credential(): void
    {
        $found = 0;

        foreach ($this->app['router']->getRoutes() as $route) {
            $uses = $route->getActionName();
            $sensitive = str_starts_with($uses, ApiTokenController::class.'@')
                || $uses === ImpersonationController::class.'@start'
                || $uses === \App\Http\Controllers\ProfileController::class.'@destroy';

            if (! $sensitive) {
                continue;
            }

            $found++;
            $this->assertContains(
                RequireFirstPartyCredential::class,
                $this->resolvedMiddleware($route),
                $route->methods()[0].' '.$route->uri().' must run '.RequireFirstPartyCredential::ALIAS,
            );
        }

        $this->assertGreaterThanOrEqual(8, $found, 'api-tokens (two spellings x3), impersonate start, profile delete');
    }

    public function test_the_fail_closed_gate_really_runs_on_every_api_route(): void
    {
        $checked = 0;

        foreach ($this->app['router']->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/') || str_starts_with($route->uri(), 'api/__')) {
                continue;
            }

            $checked++;
            $this->assertContains(
                EnforceScopedTokenAccess::class,
                $this->resolvedMiddleware($route),
                $route->methods()[0].' '.$route->uri().' does not run '.EnforceScopedTokenAccess::class,
            );
        }

        $this->assertGreaterThan(100, $checked);
    }
}
