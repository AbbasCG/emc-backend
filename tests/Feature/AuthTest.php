<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\ImpersonatePermissionSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    /** Full valid payload used across register tests. */
    private function validRegisterPayload(array $overrides = []): array
    {
        return array_merge([
            'name'                  => 'Test User',
            'email'                 => 'test@example.com',
            'password'              => 'Password123!',
            'password_confirmation' => 'Password123!',
            'country_code'          => 'NL',
            'phone_country_code'    => '+31',
            'phone'                 => '+31612345678',
            'city'                  => 'Breda',
            'gender'                => 'male',
        ], $overrides);
    }

    public function test_user_can_register(): void
    {
        $response = $this->postJson('/api/auth/register', $this->validRegisterPayload());

        $response->assertCreated()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['user', 'token']]);

        $this->assertDatabaseHas('users', [
            'email'              => 'test@example.com',
            'country_code'       => 'NL',
            'phone_country_code' => '+31',
            'phone'              => '+31612345678',
            'city'               => 'Breda',
            'gender'             => 'male',
        ]);
    }

    public function test_register_requires_valid_email(): void
    {
        $response = $this->postJson('/api/auth/register', $this->validRegisterPayload([
            'email' => 'not-an-email',
        ]));

        $response->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_register_with_country_phone_city_gender_stores_all_fields(): void
    {
        $response = $this->postJson('/api/auth/register', $this->validRegisterPayload([
            'email'              => 'profile@example.com',
            'country_code'       => 'SA',
            'phone_country_code' => '+966',
            'phone'              => '+966512345678',
            'city'               => 'Riyadh',
            'gender'             => 'female',
        ]));

        $response->assertCreated()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.user.country_code', 'SA')
            ->assertJsonPath('data.user.phone_country_code', '+966')
            ->assertJsonPath('data.user.phone', '+966512345678')
            ->assertJsonPath('data.user.city', 'Riyadh')
            ->assertJsonPath('data.user.gender', 'female');

        $this->assertDatabaseHas('users', [
            'email'              => 'profile@example.com',
            'country_code'       => 'SA',
            'phone_country_code' => '+966',
            'phone'              => '+966512345678',
            'city'               => 'Riyadh',
            'gender'             => 'female',
        ]);
    }

    public function test_register_accepts_the_three_field_quick_join_payload(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name'                  => 'Quick Join User',
            'email'                 => 'quick-join@example.com',
            'password'              => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertCreated()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.user.email', 'quick-join@example.com');

        $this->assertDatabaseHas('users', [
            'email'              => 'quick-join@example.com',
            'country_code'       => null,
            'phone_country_code' => null,
            'phone'              => null,
            'city'               => null,
            'gender'             => null,
        ]);
    }

    public function test_register_invalid_gender_returns_validation_error(): void
    {
        $response = $this->postJson('/api/auth/register', $this->validRegisterPayload([
            'email'  => 'badgender@example.com',
            'gender' => 'unknown',
        ]));

        $response->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonStructure(['errors' => ['gender']]);
    }

    public function test_user_can_login(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123')]);

        $response = $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['user', 'token']]);
    }

    public function test_first_party_web_login_uses_session_cookie_without_exposing_token(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret123')]);
        $headers = [
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/login',
            'X-EMC-Auth-Mode' => 'cookie',
        ];

        $this->withHeaders($headers)
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'secret123',
            ])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.token', null);

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->withHeaders($headers)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);

        $this->withHeaders($headers)
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertGuest('web');
        $this->app['auth']->forgetGuards();

        $this->withHeaders($headers)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_cookie_session_impersonation_never_exposes_a_token_and_restores_admin(): void
    {
        $this->seed([RoleSeeder::class, PermissionSeeder::class, ImpersonatePermissionSeeder::class]);

        $admin = User::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
            'password' => bcrypt('secret123'),
        ]);
        $student = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $headers = [
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/login',
            'X-EMC-Auth-Mode' => 'cookie',
        ];

        $this->withHeaders($headers)->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'secret123',
        ])->assertOk()->assertJsonPath('data.token', null);

        $this->withHeaders($headers)
            ->postJson("/api/admin/impersonate/{$student->id}")
            ->assertOk()
            ->assertJsonPath('token', null)
            ->assertJsonPath('user.id', $student->id);

        $this->app['auth']->forgetGuards();
        $this->withHeaders($headers)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $student->id)
            ->assertJsonPath('data.is_impersonating', true)
            ->assertJsonPath('data.impersonator.id', $admin->id);

        $this->withHeaders($headers)
            ->postJson('/api/admin/impersonate/stop')
            ->assertOk()
            ->assertJsonPath('data.token', null)
            ->assertJsonPath('data.user.id', $admin->id);

        $this->app['auth']->forgetGuards();
        $this->withHeaders($headers)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $admin->id)
            ->assertJsonPath('data.is_impersonating', false);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('security_logs', [
            'actor_id' => $admin->id,
            'target_user_id' => $student->id,
            'event' => 'impersonation_started',
            'sanctum_token_id' => null,
        ]);
        $this->assertDatabaseHas('security_logs', [
            'actor_id' => $admin->id,
            'target_user_id' => $student->id,
            'event' => 'impersonation_stopped',
            'sanctum_token_id' => null,
        ]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct')]);

        $response = $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/auth/logout');

        $response->assertOk()->assertJson(['success' => true]);
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me');

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.user.id', $user->id);
    }

    public function test_protected_route_requires_authentication(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(401)
            ->assertJson(['success' => false]);
    }

    public function test_admin_route_blocked_for_unauthenticated(): void
    {
        $response = $this->getJson('/api/admin/registrations');

        $response->assertStatus(401);
    }
}
