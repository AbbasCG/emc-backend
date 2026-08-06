<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies that EnsureUserIsActive middleware blocks suspended accounts
 * (is_active = false) on every authenticated route.
 */
class IsActiveMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    // ─── Active users pass through ─────────────────────────────────────────

    public function test_active_student_can_access_protected_routes(): void
    {
        $student = User::factory()->create(['role' => 'student', 'is_active' => true]);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    public function test_active_admin_can_access_admin_routes(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => true]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/dashboard')
            ->assertOk();
    }

    // ─── Inactive users are blocked ────────────────────────────────────────

    public function test_inactive_user_gets_403_on_auth_me(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertStatus(403)
            ->assertJson(['success' => false]);
    }

    public function test_inactive_student_cannot_access_student_dashboard(): void
    {
        $student = User::factory()->create(['role' => 'student', 'is_active' => false]);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/student/dashboard')
            ->assertStatus(403);
    }

    public function test_inactive_admin_cannot_access_admin_dashboard(): void
    {
        $admin = User::factory()->admin()->create(['is_active' => false]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/dashboard')
            ->assertStatus(403);
    }

    public function test_inactive_instructor_cannot_access_instructor_routes(): void
    {
        $instructor = User::factory()->create(['role' => 'instructor', 'is_active' => false]);

        $this->actingAs($instructor, 'sanctum')
            ->getJson('/api/instructor/dashboard')
            ->assertStatus(403);
    }

    public function test_inactive_user_cannot_access_notifications(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/notifications')
            ->assertStatus(403);
    }

    // ─── Blocking message is informative ───────────────────────────────────

    public function test_inactive_user_response_contains_error_message(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me');

        $response->assertStatus(403)
            ->assertJson(['success' => false])
            ->assertJsonStructure(['message']);

        // The message must not be a generic 403 — it must explain the account is suspended
        $message = strtolower($response->json('message') ?? '');
        $this->assertTrue(
            str_contains($message, 'suspend') || str_contains($message, 'inactive') || str_contains($message, 'active'),
            "Expected suspension message, got: {$message}"
        );
    }

    // ─── Public routes unaffected ──────────────────────────────────────────

    public function test_public_routes_do_not_require_active_flag(): void
    {
        // /api/health is public — no authentication or is_active check
        $this->getJson('/api/health')
            ->assertOk();
    }

    // ─── Re-activation works ───────────────────────────────────────────────

    public function test_reactivated_user_can_access_routes_again(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        // Blocked initially
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertStatus(403);

        // Admin re-activates the user
        $user->update(['is_active' => true]);
        $user->refresh();

        // Now accessible
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk();
    }
}
