<?php

namespace Tests\Feature;

use App\Models\User;
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

    public function test_register_missing_profile_fields_returns_validation_errors(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name'                  => 'Incomplete User',
            'email'                 => 'incomplete@example.com',
            'password'              => 'Password123!',
            'password_confirmation' => 'Password123!',
            // country_code, phone_country_code, phone, city, gender intentionally omitted
        ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonStructure(['errors' => [
                'country_code',
                'phone_country_code',
                'phone',
                'city',
                'gender',
            ]]);
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
