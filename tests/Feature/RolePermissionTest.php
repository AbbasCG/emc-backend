<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Role-based access control tests.
 *
 * Verifies that each role can only reach its own route group and is blocked
 * from all others. Also covers the teacher/instructor alias and the
 * super_admin bypass.
 */
class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    // ─── Super Admin ──────────────────────────────────────────────────────────

    public function test_super_admin_can_access_admin_dashboard(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_super_admin_can_access_admin_registrations(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/admin/registrations')
            ->assertOk();
    }

    public function test_super_admin_can_access_admin_users(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/admin/users')
            ->assertOk();
    }

    public function test_super_admin_can_access_admin_courses(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/admin/courses')
            ->assertOk();
    }

    public function test_super_admin_can_access_admin_payments(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/admin/payments')
            ->assertOk();
    }

    // ─── Admin ────────────────────────────────────────────────────────────────

    public function test_admin_can_access_admin_dashboard(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/dashboard')
            ->assertOk();
    }

    public function test_admin_can_access_admin_courses(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/courses')
            ->assertOk();
    }

    // ─── Instructor / Teacher alias ───────────────────────────────────────────

    public function test_instructor_role_can_access_instructor_dashboard(): void
    {
        $instructor = User::factory()->create(['role' => 'instructor']);

        $this->actingAs($instructor, 'sanctum')
            ->getJson('/api/instructor/dashboard')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_teacher_role_is_alias_for_instructor(): void
    {
        // 'teacher' is the legacy slug; it must reach the same routes as 'instructor'
        $teacher = User::factory()->create(['role' => 'teacher']);

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/instructor/dashboard')
            ->assertOk();
    }

    public function test_instructor_cannot_access_admin_registrations(): void
    {
        $instructor = User::factory()->create(['role' => 'instructor']);

        $this->actingAs($instructor, 'sanctum')
            ->getJson('/api/admin/registrations')
            ->assertStatus(403);
    }

    public function test_instructor_cannot_access_admin_dashboard(): void
    {
        $instructor = User::factory()->create(['role' => 'instructor']);

        $this->actingAs($instructor, 'sanctum')
            ->getJson('/api/admin/dashboard')
            ->assertStatus(403);
    }

    public function test_instructor_cannot_access_admin_users(): void
    {
        $instructor = User::factory()->create(['role' => 'instructor']);

        $this->actingAs($instructor, 'sanctum')
            ->getJson('/api/admin/users')
            ->assertStatus(403);
    }

    // ─── Student ──────────────────────────────────────────────────────────────

    public function test_student_cannot_access_instructor_dashboard(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/instructor/dashboard')
            ->assertStatus(403);
    }

    public function test_student_cannot_access_admin_registrations(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/admin/registrations')
            ->assertStatus(403);
    }

    public function test_student_cannot_access_admin_courses(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/admin/courses')
            ->assertStatus(403);
    }

    public function test_student_cannot_access_admin_payments(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/admin/payments')
            ->assertStatus(403);
    }

    public function test_student_cannot_access_admin_users(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/admin/users')
            ->assertStatus(403);
    }

    // ─── Finance ──────────────────────────────────────────────────────────────

    public function test_finance_role_cannot_access_general_admin_dashboard(): void
    {
        // 'finance' is not in the admin group (admin/super_admin/executive_admin)
        $finance = User::factory()->create(['role' => 'finance']);

        $this->actingAs($finance, 'sanctum')
            ->getJson('/api/admin/dashboard')
            ->assertStatus(403);
    }

    public function test_finance_role_cannot_access_admin_registrations(): void
    {
        $finance = User::factory()->create(['role' => 'finance']);

        $this->actingAs($finance, 'sanctum')
            ->getJson('/api/admin/registrations')
            ->assertStatus(403);
    }

    public function test_finance_role_cannot_access_admin_users(): void
    {
        $finance = User::factory()->create(['role' => 'finance']);

        $this->actingAs($finance, 'sanctum')
            ->getJson('/api/admin/users')
            ->assertStatus(403);
    }

    // ─── Partner ──────────────────────────────────────────────────────────────

    public function test_partner_cannot_access_admin_routes(): void
    {
        $partner = User::factory()->create(['role' => 'partner']);

        $routes = [
            '/api/admin/dashboard',
            '/api/admin/registrations',
            '/api/admin/users',
        ];

        foreach ($routes as $route) {
            $this->actingAs($partner, 'sanctum')
                ->getJson($route)
                ->assertStatus(403, "Partner should be blocked from {$route}");
        }
    }

    public function test_partner_cannot_access_instructor_routes(): void
    {
        $partner = User::factory()->create(['role' => 'partner']);

        $this->actingAs($partner, 'sanctum')
            ->getJson('/api/instructor/dashboard')
            ->assertStatus(403);
    }

    public function test_partner_cannot_access_admin_knowledge_categories(): void
    {
        $partner = User::factory()->create(['role' => 'partner']);

        $this->actingAs($partner, 'sanctum')
            ->getJson('/api/admin/knowledge/categories')
            ->assertStatus(403);
    }

    // ─── Unauthenticated ─────────────────────────────────────────────────────

    public function test_unauthenticated_cannot_access_any_protected_route(): void
    {
        $routes = [
            '/api/auth/me',
            '/api/student/dashboard',
            '/api/instructor/dashboard',
            '/api/admin/dashboard',
            '/api/notifications',
        ];

        foreach ($routes as $route) {
            $this->getJson($route)
                ->assertStatus(401, "Expected 401 on {$route} without auth");
        }
    }
}
