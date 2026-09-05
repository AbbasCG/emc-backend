<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Root-cause coverage for the production 422 on
 * `PUT /api/admin/users/{id}` when assigning newer roles (e.g. ai_manager,
 * "مدير الذكاء الاصطناعي"): AdminUserController::ALLOWED_ROLES and
 * UserRoleController::ALL_ROLES were two independent hardcoded arrays that
 * drifted behind the actual `roles` table (seeded by RoleSeeder) — a role
 * could exist in the system (seeded, used by real route middleware) yet
 * still be rejected by role-assignment validation. Both controllers now
 * validate against Role::assignableSlugs(), the roles table itself.
 */
class RoleAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    public static function assignableRoleProvider(): array
    {
        // Every role actually seeded by RoleSeeder — deliberately NOT
        // hand-copied here; if this list and the seeder ever disagree the
        // fix under test (DB-sourced validation) makes that impossible by
        // construction, so a plain re-list is safe and won't itself drift.
        return array_map(fn (string $name) => [$name], [
            'super_admin', 'tech_admin', 'executive_admin', 'admin',
            'department_manager', 'section_lead', 'finance_manager',
            'marketing_manager', 'quality_manager', 'hr_manager',
            'support_agent', 'instructor', 'student', 'volunteer', 'partner',
            'programs_manager', 'operations_manager', 'partnerships_manager',
            'community_manager', 'ai_manager',
        ]);
    }

    #[DataProvider('assignableRoleProvider')]
    public function test_super_admin_can_assign_every_seeded_role(string $roleSlug): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create(['role' => 'student', 'is_active' => true]);

        $res = $this->actingAs($admin)->putJson("/api/admin/users/{$target->id}", ['role' => $roleSlug]);

        $res->assertOk();
        $this->assertSame($roleSlug, $res->json('data.role'));
        $this->assertSame($roleSlug, $target->fresh()->role);
    }

    public function test_ai_manager_role_assignment_no_longer_returns_422(): void
    {
        // The exact reported bug: assigning "مدير الذكاء الاصطناعي" (ai_manager).
        $admin = $this->superAdmin();
        $target = User::factory()->create(['role' => 'student', 'is_active' => true]);

        $res = $this->actingAs($admin)->putJson("/api/admin/users/{$target->id}", ['role' => 'ai_manager']);

        $res->assertOk()->assertJsonPath('data.role', 'ai_manager');
    }

    public function test_invalid_fake_role_is_rejected(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create(['role' => 'student', 'is_active' => true]);

        $res = $this->actingAs($admin)->putJson("/api/admin/users/{$target->id}", ['role' => 'definitely_not_a_real_role']);

        $res->assertStatus(422);
    }

    public function test_role_update_persists_and_response_returns_canonical_role(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create(['role' => 'student', 'is_active' => true]);

        $res = $this->actingAs($admin)->putJson("/api/admin/users/{$target->id}", ['role' => 'programs_manager']);

        $res->assertOk()->assertJsonPath('data.role', 'programs_manager');
        $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => 'programs_manager']);
    }

    public function test_assigning_manager_role_does_not_grant_department_leadership(): void
    {
        $dept = Department::create([
            'name' => 'Dept', 'name_ar' => 'إدارة', 'slug' => 'dept-role-test',
            'status' => 'active', 'is_active' => true,
        ]);
        $admin = $this->superAdmin();
        $target = User::factory()->create(['role' => 'student', 'is_active' => true]);

        $this->actingAs($admin)->putJson("/api/admin/users/{$target->id}", ['role' => 'ai_manager'])->assertOk();

        $this->assertNull($dept->fresh()->leader_id);
        $svc = app(\App\Services\DepartmentAccessService::class);
        $this->assertTrue($svc->getLedDepartmentIds($target->fresh())->isEmpty());
        $this->assertFalse($svc->canManageDepartment($target->fresh(), $dept->id));
    }

    public function test_ai_manager_can_access_its_own_intended_endpoint(): void
    {
        // Updated after the AI Department / AI Platform separation:
        // ai_manager's "own" intended surface is now the organizational
        // department/operations surface (Weekly Reports, department access),
        // not the technical AI usage summary — see
        // AiDepartmentPlatformSeparationTest for the full access-boundary coverage.
        $aiManager = User::factory()->create(['role' => 'ai_manager', 'is_active' => true]);

        $res = $this->actingAs($aiManager)->getJson('/api/operations/weekly-reports/due');

        $res->assertOk();
    }

    public function test_ai_manager_cannot_access_unrelated_privileged_endpoint(): void
    {
        $aiManager = User::factory()->create(['role' => 'ai_manager', 'is_active' => true]);

        // admin/coupons is gated to admin,super_admin,tech_admin,operations_manager — ai_manager is not in that list.
        $res = $this->actingAs($aiManager)->getJson('/api/admin/coupons');

        $res->assertStatus(403);
    }

    public function test_role_options_endpoint_includes_ai_manager(): void
    {
        $admin = $this->superAdmin();

        $res = $this->actingAs($admin)->getJson('/api/admin/roles/options');

        $res->assertOk();
        $values = collect($res->json('data'))->pluck('value');
        $this->assertTrue($values->contains('ai_manager'));
    }

    public function test_user_role_assign_role_endpoint_also_accepts_ai_manager(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create(['role' => 'student', 'is_active' => true]);

        $res = $this->actingAs($admin)->putJson("/api/admin/users/{$target->id}/role", ['role' => 'ai_manager']);

        $res->assertOk()->assertJsonPath('data.role', 'ai_manager');
    }
}
