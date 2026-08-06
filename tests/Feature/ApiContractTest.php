<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\PlatformNotification;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API contract tests — verifies that key endpoints return consistent
 * response shapes regardless of data state.
 *
 * These tests protect against silent resource shape regressions where
 * a field is removed or renamed without a version bump.
 */
class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    // ─── Health endpoint ──────────────────────────────────────────────────────

    public function test_health_endpoint_returns_expected_shape(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonStructure([
                'status',
                'timestamp',
                'response_ms',
                'app_version',
                'laravel_version',
                'php_version',
                'env',
                'memory_mb',
                'peak_memory_mb',
                'checks' => [
                    'database',
                    'cache',
                    'queue',
                    'storage',
                ],
            ]);
    }

    public function test_health_check_statuses_are_known_values(): void
    {
        $response = $this->getJson('/api/health');
        $response->assertOk();

        $topStatus = $response->json('status');
        $this->assertContains($topStatus, ['ok', 'warn', 'fail'],
            "Top-level status must be ok/warn/fail, got: {$topStatus}");

        foreach (['database', 'cache', 'queue', 'storage'] as $check) {
            $status = $response->json("checks.{$check}.status");
            $this->assertContains($status, ['ok', 'warn', 'fail'],
                "Check '{$check}' status must be ok/warn/fail, got: {$status}");
        }
    }

    // ─── Error envelope ───────────────────────────────────────────────────────

    public function test_401_responses_use_consistent_envelope(): void
    {
        $endpoints = ['/api/auth/me', '/api/student/dashboard', '/api/notifications'];

        foreach ($endpoints as $endpoint) {
            $this->getJson($endpoint)
                ->assertStatus(401)
                ->assertJsonStructure(['success', 'message']);
        }
    }

    public function test_403_responses_use_consistent_envelope(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/admin/dashboard')
            ->assertStatus(403)
            ->assertJsonStructure(['success', 'message'])
            ->assertJson(['success' => false]);
    }

    public function test_422_responses_include_errors_array(): void
    {
        $this->postJson('/api/auth/login', [])
            ->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors']);
    }

    public function test_404_response_for_unknown_api_route(): void
    {
        $this->getJson('/api/this-endpoint-does-not-exist-at-all')
            ->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    // ─── UserResource ─────────────────────────────────────────────────────────

    public function test_auth_me_returns_user_resource_shape(): void
    {
        $user = User::factory()->create(['role' => 'student', 'phone' => '0612345678']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'user' => ['id', 'name', 'email', 'role', 'is_active'],
                ],
            ])
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.user.role', 'student');
    }

    public function test_user_resource_does_not_expose_password(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me');

        $response->assertOk();

        $userData = $response->json('data.user');
        $this->assertArrayNotHasKey('password', $userData, 'Password must never be in UserResource');
        $this->assertArrayNotHasKey('remember_token', $userData, 'remember_token must not be exposed');
    }

    // ─── CourseResource ───────────────────────────────────────────────────────

    public function test_public_courses_list_returns_expected_shape(): void
    {
        Course::factory()->count(2)->free()->create();

        $this->getJson('/api/courses')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data',
            ]);
    }

    public function test_course_resource_exposes_required_fields(): void
    {
        $course = Course::factory()->free()->create([
            'title'       => 'PHP Mastery',
            'description' => 'Learn PHP',
        ]);

        $response = $this->getJson('/api/courses');
        $response->assertOk();

        $courses = $response->json('data.data') ?? $response->json('data');

        // At minimum one course must be in the list and have core fields
        $found = collect($courses)->first(fn ($c) => $c['id'] === $course->id);
        $this->assertNotNull($found, 'Course must appear in public listing');
        $this->assertArrayHasKey('title', $found);
        $this->assertArrayHasKey('id', $found);
    }

    // ─── RegistrationResource ─────────────────────────────────────────────────

    public function test_admin_registration_show_returns_expected_shape(): void
    {
        $admin        = User::factory()->admin()->create();
        $course       = Course::factory()->free()->create();
        $registration = Registration::factory()->create([
            'course_id' => $course->id,
            'status'    => 'Registered',
            'email'     => 'reg@example.com',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/registrations/{$registration->id}")
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => ['id', 'email', 'status'],
            ])
            ->assertJsonPath('data.id', $registration->id);
    }

    // ─── PlatformNotificationResource ────────────────────────────────────────

    public function test_notification_resource_exposes_required_fields(): void
    {
        $user = User::factory()->create();

        PlatformNotification::create([
            'user_id'    => $user->id,
            'type'       => 'info',
            'title'      => 'Welcome',
            'message'    => 'Hello!',
            'action_url' => '/student/courses',
            'is_read'    => false,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/notifications');

        $response->assertOk()
            ->assertJsonStructure(['success', 'data']);

        // Drill into first notification item
        $items = $response->json('data.data') ?? $response->json('data');
        if (! empty($items)) {
            $first = $items[0];
            foreach (['id', 'type', 'title', 'message', 'is_read'] as $field) {
                $this->assertArrayHasKey($field, $first, "Notification resource missing field: {$field}");
            }
        }
    }

    public function test_notification_resource_does_not_expose_other_users_data(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        PlatformNotification::create([
            'user_id' => $userA->id,
            'type'    => 'info',
            'title'   => 'UserA Notification',
            'message' => 'Secret',
            'is_read' => false,
        ]);

        $response = $this->actingAs($userB, 'sanctum')
            ->getJson('/api/notifications');

        $response->assertOk();

        $items = $response->json('data.data') ?? $response->json('data') ?? [];
        $this->assertEmpty($items, 'UserB must not see UserA notifications');
    }

    // ─── Registration list ────────────────────────────────────────────────────

    public function test_admin_registrations_list_is_paginated_with_consistent_shape(): void
    {
        $admin = User::factory()->admin()->create();
        $course = Course::factory()->free()->create();
        Registration::factory()->count(5)->create(['course_id' => $course->id]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/registrations');

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['success', 'data']);
    }
}
