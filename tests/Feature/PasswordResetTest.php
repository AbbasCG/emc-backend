<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    // ─── Forgot Password ──────────────────────────────────────────────────────

    public function test_forgot_password_always_returns_200_to_prevent_enumeration(): void
    {
        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'no-such-user@example.com',
        ]);

        // Must return 200 regardless — prevents leaking registered emails
        $response->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_forgot_password_sends_reset_notification_to_existing_user(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->postJson('/api/auth/forgot-password', [
            'email' => $user->email,
        ])->assertOk();

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_forgot_password_does_not_send_notification_for_unknown_email(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/forgot-password', [
            'email' => 'ghost@example.com',
        ])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_forgot_password_requires_valid_email(): void
    {
        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_forgot_password_requires_email_field(): void
    {
        $response = $this->postJson('/api/auth/forgot-password', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    // ─── Reset Password ───────────────────────────────────────────────────────

    public function test_reset_password_with_valid_token_succeeds(): void
    {
        $user  = User::factory()->create(['password' => bcrypt('OldPassword123!')]);
        $token = Password::createToken($user);

        $response = $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'email'                 => $user->email,
            'password'              => 'NewPassword456!',
            'password_confirmation' => 'NewPassword456!',
        ]);

        $response->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_reset_password_new_password_works_for_login(): void
    {
        $user  = User::factory()->create(['password' => bcrypt('OldPassword123!')]);
        $token = Password::createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'email'                 => $user->email,
            'password'              => 'BrandNewPass789!',
            'password_confirmation' => 'BrandNewPass789!',
        ])->assertOk();

        $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'BrandNewPass789!',
        ])->assertOk();
    }

    public function test_reset_password_revokes_all_existing_sanctum_tokens(): void
    {
        $user    = User::factory()->create(['password' => bcrypt('OldPass123!')]);
        $oldToken = $user->createToken('old-device')->plainTextToken;

        $resetToken = Password::createToken($user);
        $this->postJson('/api/auth/reset-password', [
            'token'                 => $resetToken,
            'email'                 => $user->email,
            'password'              => 'AfterReset456!',
            'password_confirmation' => 'AfterReset456!',
        ])->assertOk();

        // The previously issued Sanctum token must now be invalid
        $this->withToken($oldToken)
            ->getJson('/api/auth/me')
            ->assertStatus(401);
    }

    public function test_reset_password_with_invalid_token_returns_422(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/auth/reset-password', [
            'token'                 => 'totally-invalid-token',
            'email'                 => $user->email,
            'password'              => 'NewPass123!',
            'password_confirmation' => 'NewPass123!',
        ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_reset_password_with_wrong_email_for_token_returns_422(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $token = Password::createToken($userA);

        // Token belongs to userA but submitting userB's email
        $response = $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'email'                 => $userB->email,
            'password'              => 'NewPass123!',
            'password_confirmation' => 'NewPass123!',
        ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_reset_password_requires_password_confirmation(): void
    {
        $user  = User::factory()->create();
        $token = Password::createToken($user);

        $response = $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'email'                 => $user->email,
            'password'              => 'NewPass123!',
            'password_confirmation' => 'MismatchPass!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_reset_password_enforces_minimum_length(): void
    {
        $user  = User::factory()->create();
        $token = Password::createToken($user);

        $response = $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'email'                 => $user->email,
            'password'              => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }
}
