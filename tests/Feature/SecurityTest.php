<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Cross-user data access and authorization boundary tests.
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_cannot_view_another_users_registration(): void
    {
        Mail::fake();

        $studentA  = User::factory()->create(['role' => 'student']);
        $studentB  = User::factory()->create(['role' => 'student']);
        $course    = Course::factory()->free()->create();
        $registration = Registration::factory()->create([
            'course_id' => $course->id,
            'user_id'   => $studentA->id,
            'email'     => $studentA->email,
            'status'    => 'Registered',
        ]);

        // Student B tries to view Student A's registration
        $response = $this->actingAs($studentB, 'sanctum')
            ->getJson("/api/admin/registrations/{$registration->id}");

        // Either 403 (authorized but blocked) or 404 is acceptable
        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_student_cannot_access_admin_routes(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $adminOnlyRoutes = [
            '/api/admin/registrations',
            '/api/admin/payments',
            '/api/admin/courses',
        ];

        foreach ($adminOnlyRoutes as $route) {
            $response = $this->actingAs($student, 'sanctum')->getJson($route);
            $this->assertContains(
                $response->status(),
                [403, 404],
                "Expected 403/404 for student on {$route}, got {$response->status()}"
            );
        }
    }

    public function test_unauthenticated_cannot_access_protected_endpoints(): void
    {
        $protectedRoutes = [
            '/api/auth/me',
            '/api/admin/registrations',
            '/api/student/dashboard',
        ];

        foreach ($protectedRoutes as $route) {
            $response = $this->getJson($route);
            $this->assertEquals(
                401,
                $response->status(),
                "Expected 401 for unauthenticated on {$route}"
            );
        }
    }

    public function test_api_returns_json_for_invalid_endpoint(): void
    {
        $response = $this->getJson('/api/this-route-does-not-exist-ever');

        $response->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_registration_for_nonexistent_course_returns_422(): void
    {
        $response = $this->postJson('/api/register', [
            'course_id' => 99999,
            'full_name' => 'Ghost',
            'phone'     => '0612345678',
            'email'     => 'ghost@example.com',
            'city'      => 'Amsterdam',
        ]);

        $response->assertStatus(422);
    }

    public function test_partner_cannot_access_admin_knowledge_categories(): void
    {
        $partner = User::factory()->create(['role' => 'partner']);

        $response = $this->actingAs($partner, 'sanctum')
            ->getJson('/api/admin/knowledge/categories');

        $response->assertStatus(403);
    }
}
