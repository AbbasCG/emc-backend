<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset as PasswordResetEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * A temporary password (users.must_change_password = true) must be replaced before the account can use anything else.
 * The gate is one middleware in the `api` group; /auth/me, /auth/logout, /auth/change-password and the access manifests stay open.
 */
class ForcedPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = 'PASSWORD_CHANGE_REQUIRED';

    private function user(string $role, bool $flag, array $extra = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role, 'is_active' => true, 'must_change_password' => $flag, 'password' => Hash::make('Temp-Pass-123'),
        ], $extra));
    }

    private function as(User $user)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'sanctum');
    }

    // ── the gate ─────────────────────────────────────────────────────────────

    public function test_a_flagged_user_is_blocked_on_representative_routes_of_every_group(): void
    {
        $routes = [
            ['admin',      'admin',       '/api/admin/users'],
            ['student',    'student',     '/api/student/dashboard'],
            ['student',    'student',     '/api/dashboard'],
            ['admin',      'operations',  '/api/operations/volunteers/hub'],
            ['hr_manager', 'hr',          '/api/hr/dashboard'],
            ['student',    'any-authenticated', '/api/job-titles'],
            ['student',    'any-authenticated', '/api/mobile/readiness'],
        ];

        foreach ($routes as [$role, $group, $uri]) {
            $this->as($this->user($role, true))->getJson($uri)
                ->assertStatus(403)
                ->assertJsonPath('success', false)
                ->assertJsonPath('code', self::CODE)
                ->assertJsonPath('message', 'يجب تغيير كلمة المرور المؤقتة قبل المتابعة.');
        }

        // writes too
        $this->as($this->user('student', true))->postJson('/api/student/evaluations', [])->assertStatus(403)->assertJsonPath('code', self::CODE);
    }

    public function test_the_allow_list_still_works_for_a_flagged_user(): void
    {
        $user = $this->user('department_member', true);

        $this->as($user)->getJson('/api/auth/me')->assertOk()
            ->assertJsonPath('data.must_change_password', true)
            ->assertJsonPath('data.user.must_change_password', true);
        $this->as($user)->getJson('/api/auth/me/access-manifest')->assertOk();
        $this->as($user)->getJson('/api/auth/me/page-access')->assertOk();
        $this->as($user)->postJson('/api/auth/logout')->assertOk();
    }

    public function test_public_and_optional_auth_routes_ignore_the_flag(): void
    {
        $user = $this->user('student', true);

        $this->as($user)->getJson('/api/team')->assertOk();
        $this->as($user)->getJson('/api/health')->assertOk();
    }

    public function test_a_user_without_the_flag_is_unaffected(): void
    {
        $this->as($this->user('admin', false))->getJson('/api/admin/users')->assertOk();
        $this->as($this->user('student', false))->getJson('/api/job-titles')->assertOk();
        $this->as($this->user('student', false))->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.must_change_password', false);
    }

    public function test_an_unauthenticated_request_still_gets_401_not_403(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/users')->assertStatus(401);
        $this->getJson('/api/job-titles')->assertStatus(401);
    }

    public function test_an_impersonation_session_is_never_gated(): void
    {
        $user = $this->user('student', true);
        $this->app['auth']->forgetGuards();
        Auth::guard('sanctum')->setUser($user);

        $request = Request::create('/api/job-titles', 'GET');
        $request->setRouteResolver(fn () => app('router')->getRoutes()->match($request));
        $request->attributes->set('is_impersonating', true);

        $response = (new EnsurePasswordIsChanged())->handle($request, fn () => response('through'));
        $this->assertSame('through', $response->getContent());

        $request->attributes->set('is_impersonating', false);
        $this->assertSame(403, (new EnsurePasswordIsChanged())->handle($request, fn () => response('through'))->getStatusCode());
    }

    // ── login / me payload ───────────────────────────────────────────────────

    public function test_login_returns_the_flag(): void
    {
        $flagged = $this->user('student', true, ['email' => 'flagged@example.com']);
        $normal  = $this->user('student', false, ['email' => 'normal@example.com']);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => $flagged->email, 'password' => 'Temp-Pass-123'])
            ->assertOk()->assertJsonPath('data.user.must_change_password', true);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => $normal->email, 'password' => 'Temp-Pass-123'])
            ->assertOk()->assertJsonPath('data.user.must_change_password', false);
    }

    // ── change-password ──────────────────────────────────────────────────────

    private function change(User $user, array $body)
    {
        return $this->as($user)->postJson('/api/auth/change-password', $body);
    }

    public function test_wrong_current_password_is_a_422_and_changes_nothing(): void
    {
        $user = $this->user('student', true);

        $this->change($user, ['current_password' => 'not-it', 'password' => 'Brand-New-Pass-9', 'password_confirmation' => 'Brand-New-Pass-9'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');

        $user->refresh();
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check('Temp-Pass-123', $user->password));
    }

    public function test_weak_mismatched_or_unchanged_new_passwords_are_refused(): void
    {
        $user = $this->user('student', true);

        $this->change($user, ['current_password' => 'Temp-Pass-123', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->change($user, ['current_password' => 'Temp-Pass-123', 'password' => 'Long-Enough-1', 'password_confirmation' => 'Different-1'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->change($user, ['current_password' => 'Temp-Pass-123', 'password' => 'Temp-Pass-123', 'password_confirmation' => 'Temp-Pass-123'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->change($user, [])->assertStatus(422)->assertJsonValidationErrors(['current_password', 'password']);

        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_success_clears_the_flag_revokes_other_tokens_and_keeps_the_current_one(): void
    {
        $user = $this->user('student', true);
        $other1  = $user->createToken('phone')->plainTextToken;
        $other2  = $user->createToken('laptop')->plainTextToken;
        $current = $user->createToken('this-one');

        // blocked before the change, with the real bearer token
        $this->app['auth']->forgetGuards();
        $this->withToken($current->plainTextToken)->getJson('/api/job-titles')->assertStatus(403)->assertJsonPath('code', self::CODE);

        $this->app['auth']->forgetGuards();
        $this->withToken($current->plainTextToken)
            ->postJson('/api/auth/change-password', [
                'current_password' => 'Temp-Pass-123', 'password' => 'Brand-New-Pass-9', 'password_confirmation' => 'Brand-New-Pass-9',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.must_change_password', false);

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('Brand-New-Pass-9', $user->password));
        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame($current->accessToken->id, $user->tokens()->first()->id, 'the token that made the call survives');
        $this->assertDatabaseHas('audit_logs', ['action' => 'PASSWORD_CHANGED', 'entity_id' => $user->id]);

        // the kept token now works everywhere; the revoked ones do not
        $this->app['auth']->forgetGuards();
        $this->withToken($current->plainTextToken)->getJson('/api/job-titles')->assertOk();
        foreach ([$other1, $other2] as $revoked) {
            $this->app['auth']->forgetGuards();
            $this->withToken($revoked)->getJson('/api/job-titles')->assertStatus(401);
        }
    }

    public function test_an_unflagged_user_can_change_their_password_too(): void
    {
        $user = $this->user('student', false);

        $this->change($user, ['current_password' => 'Temp-Pass-123', 'password' => 'Brand-New-Pass-9', 'password_confirmation' => 'Brand-New-Pass-9'])
            ->assertOk();

        $this->assertTrue(Hash::check('Brand-New-Pass-9', $user->fresh()->password));
    }

    public function test_change_password_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/change-password', ['current_password' => 'x', 'password' => 'y'])->assertStatus(401);
    }

    public function test_change_password_is_throttled(): void
    {
        $user = $this->user('student', true);
        $body = ['current_password' => 'wrong-one', 'password' => 'Brand-New-Pass-9', 'password_confirmation' => 'Brand-New-Pass-9'];

        for ($i = 0; $i < 5; $i++) {
            $this->change($user, $body)->assertStatus(422);
        }
        $this->change($user, $body)->assertStatus(429);
    }

    public function test_changing_the_password_is_refused_while_impersonating(): void
    {
        $user = $this->user('student', true);
        $this->app['auth']->forgetGuards();

        $request = Request::create('/api/auth/change-password', 'POST', [
            'current_password' => 'Temp-Pass-123', 'password' => 'Brand-New-Pass-9', 'password_confirmation' => 'Brand-New-Pass-9',
        ]);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('is_impersonating', true);

        $response = app(\App\Http\Controllers\AuthController::class)->changePassword($request);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check('Temp-Pass-123', $user->fresh()->password));
    }

    // ── password reset retires the flag ──────────────────────────────────────

    public function test_resetting_the_password_through_the_e_mail_link_clears_the_flag(): void
    {
        $user = $this->user('student', true);
        $token = Password::broker()->createToken($user);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/reset-password', [
            'token' => $token, 'email' => $user->email, 'password' => 'Brand-New-Pass-9', 'password_confirmation' => 'Brand-New-Pass-9',
        ])->assertOk();

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('Brand-New-Pass-9', $user->password));
    }

    // ── round 2: optional-auth writes, the instructor file route, named limiter, audit, session ──

    public function test_a_flagged_user_cannot_write_on_optional_auth_routes_but_can_still_browse_them(): void
    {
        $user = $this->user('student', true);

        $this->as($user)->getJson('/api/workshops')->assertOk();                       // GET stays open
        $this->as($user)->postJson('/api/register', [])->assertStatus(403)->assertJsonPath('code', self::CODE);
        $this->as($user)->postJson('/api/forms/some-form/submit', [])->assertStatus(403)->assertJsonPath('code', self::CODE);
        $this->as($user)->postJson('/api/support-tickets', [])->assertStatus(403)->assertJsonPath('code', self::CODE);
        $this->as($user)->postJson('/api/contact', [])->assertStatus(403)->assertJsonPath('code', self::CODE);
        $this->as($user)->postJson('/api/ambassador-applications', [])->assertStatus(403)->assertJsonPath('code', self::CODE);

        // an unflagged user reaches validation instead
        $this->as($this->user('student', false))->postJson('/api/support-tickets', [])->assertStatus(422);
    }

    public function test_the_instructor_submission_file_route_requires_authentication_and_is_gated(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/instructor/submissions/1/file')->assertStatus(401);

        $this->as($this->user('instructor', true))->getJson('/api/instructor/submissions/1/file')
            ->assertStatus(403)->assertJsonPath('code', self::CODE);
    }

    public function test_change_password_allows_five_attempts_per_fifteen_minutes_per_user(): void
    {
        $user = $this->user('student', true);
        $other = $this->user('student', true);
        $body = ['current_password' => 'wrong-one', 'password' => 'Brand-New-Pass-9', 'password_confirmation' => 'Brand-New-Pass-9'];

        for ($i = 0; $i < 5; $i++) {
            $this->change($user, $body)->assertStatus(422);
        }
        $this->change($user, $body)->assertStatus(429);
        $this->change($other, $body)->assertStatus(422);                                    // per user, not shared

        $this->travel(14)->minutes();
        $this->change($user, $body)->assertStatus(429);
        $this->travel(2)->minutes();
        $this->change($user, ['current_password' => 'Temp-Pass-123'] + $body)->assertOk();   // the window has passed
    }

    public function test_a_failed_attempt_is_audited_without_any_password_value(): void
    {
        $user = $this->user('student', true);

        $this->change($user, ['current_password' => 'Guess-Number-One', 'password' => 'Brand-New-Pass-9', 'password_confirmation' => 'Brand-New-Pass-9'])
            ->assertStatus(422);

        $row = \App\Models\AuditLog::where('action', 'PASSWORD_CHANGE_FAILED')->where('entity_id', $user->id)->firstOrFail();
        $dump = json_encode($row->toArray(), JSON_UNESCAPED_UNICODE);
        foreach (['Guess-Number-One', 'Brand-New-Pass-9', 'Temp-Pass-123'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
        $this->assertDatabaseMissing('audit_logs', ['action' => 'PASSWORD_CHANGED', 'entity_id' => $user->id]);
    }

    public function test_an_overlong_password_is_refused(): void
    {
        $user = $this->user('student', true);
        $long = str_repeat('a', 129) . 'A1';

        $this->change($user, ['current_password' => 'Temp-Pass-123', 'password' => $long, 'password_confirmation' => $long])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->change($user, ['current_password' => str_repeat('x', 129), 'password' => 'Brand-New-Pass-9', 'password_confirmation' => 'Brand-New-Pass-9'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
    }

    public function test_a_cookie_session_user_stays_signed_in_after_changing_the_password(): void
    {
        // Stateful (cookie) requests run Sanctum's AuthenticateSession: it logs a session out when the account's password hash no longer
        // matches the one stored in the session. Drive that middleware around the real controller action, as the SPA's two requests would.
        $user = $this->user('student', true);
        $guard = Auth::guard('web');
        $store = $this->app['session']->driver();
        $store->start();
        $guard->setUser($user);
        $store->put('password_hash_web', $guard->hashPasswordForCookie($user->getAuthPassword()));

        $middleware = $this->app->make(\Laravel\Sanctum\Http\Middleware\AuthenticateSession::class);
        $makeRequest = function (User $asUser, string $uri, array $body = []) use ($store) {
            $request = Request::create($uri, 'POST', $body);
            $request->setLaravelSession($store);
            $request->setUserResolver(fn () => $asUser);

            return $request;
        };

        // 1st request: the change itself
        $response = $middleware->handle(
            $makeRequest($user, '/api/auth/change-password', ['current_password' => 'Temp-Pass-123', 'password' => 'Brand-New-Pass-9', 'password_confirmation' => 'Brand-New-Pass-9']),
            fn ($request) => app(\App\Http\Controllers\AuthController::class)->changePassword($request),
        );
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(Hash::check('Brand-New-Pass-9', $user->fresh()->password));

        // 2nd request, same session, the account as it is now in the database: must NOT be logged out
        $fresh = $user->fresh();
        $guard->setUser($fresh);
        $second = $middleware->handle($makeRequest($fresh, '/api/job-titles'), fn () => response('still signed in'));
        $this->assertSame('still signed in', $second->getContent());
    }

    public function test_the_change_password_429_is_arabic_with_retry_after(): void
    {
        $user = $this->user('student', true);
        $body = ['current_password' => 'wrong-one', 'password' => 'Brand-New-Pass-9', 'password_confirmation' => 'Brand-New-Pass-9'];

        for ($i = 0; $i < 5; $i++) {
            $this->change($user, $body)->assertStatus(422);
        }
        $res = $this->change($user, $body)->assertStatus(429)->assertJsonPath('code', 'too_many_requests');

        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $res->json('message'));
        $this->assertStringContainsString('أعد المحاولة بعد', $res->json('message'));
        $this->assertStringContainsString('15', $res->json('message'));
        $this->assertGreaterThan(0, $res->json('retry_after'));
        $this->assertSame((string) $res->json('retry_after'), $res->headers->get('Retry-After'));
    }
}
