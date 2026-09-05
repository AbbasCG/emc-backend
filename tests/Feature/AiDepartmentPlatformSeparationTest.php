<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the separation of two previously-coupled concerns:
 *
 *   Domain A — the organizational "إدارة الذكاء الاصطناعي والتحول الرقمي"
 *   department. ai_manager gets the SAME generic department-surface access
 *   every other manager role gets (operations board, department members,
 *   Weekly/Meeting Reports) — never more, never coupled to any technical
 *   AI system.
 *
 *   Domain B — the technical AI Platform (/api/admin/ai/*: prompts,
 *   insights, usage, automations). ai_manager no longer gets this
 *   automatically; only admin/super_admin/tech_admin (and, for usage
 *   specifically, whoever holds the pre-existing `view_ai_usage`
 *   permission) do.
 *
 * Department leadership itself remains governed exclusively by
 * departments.leader_id via DepartmentAccessService — the role slug
 * ai_manager grants nothing here either.
 */
class AiDepartmentPlatformSeparationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            \Database\Seeders\RoleSeeder::class,
            \Database\Seeders\PermissionSeeder::class,
            \Database\Seeders\RolePermissionSeeder::class,
        ]);
    }

    private function makeDepartment(array $overrides = []): Department
    {
        return Department::create(array_merge([
            'name' => 'Dept', 'name_ar' => 'إدارة تجريبية', 'slug' => 'dept-ai-sep-test',
            'status' => 'active', 'is_active' => true,
        ], $overrides));
    }

    // ── Domain A: organizational department access ─────────────────────────

    public function test_ai_manager_can_access_ai_department_workspace_generic_endpoints(): void
    {
        $aiManager = User::factory()->create(['role' => 'ai_manager', 'is_active' => true]);

        $this->actingAs($aiManager)->getJson('/api/operations/departments/my-access')->assertOk();
        $this->actingAs($aiManager)->getJson('/api/operations/weekly-reports/due')->assertOk();
        $this->actingAs($aiManager)->getJson('/api/operations/meeting-reports')->assertOk();
    }

    public function test_ai_department_leader_gets_department_leadership_via_department_access_service_not_role(): void
    {
        $dept = $this->makeDepartment();
        $aiManager = User::factory()->create(['role' => 'ai_manager', 'is_active' => true]);
        $dept->update(['leader_id' => $aiManager->id]);

        $svc = app(\App\Services\DepartmentAccessService::class);
        $this->assertTrue($svc->getLedDepartmentIds($aiManager)->contains($dept->id));
        $this->assertTrue($svc->canManageDepartment($aiManager, $dept->id));

        $res = $this->actingAs($aiManager)->getJson('/api/operations/weekly-reports/due');
        $res->assertOk();
        $ids = collect($res->json('data.led_departments'))->pluck('department.id');
        $this->assertTrue($ids->contains($dept->id));
    }

    public function test_ai_manager_role_alone_without_leader_id_grants_no_department_leadership(): void
    {
        $dept = $this->makeDepartment();
        // Role only — never assigned as departments.leader_id for anything.
        $aiManager = User::factory()->create(['role' => 'ai_manager', 'is_active' => true]);

        $svc = app(\App\Services\DepartmentAccessService::class);
        $this->assertFalse($svc->canManageDepartment($aiManager, $dept->id));
        $this->assertTrue($svc->getLedDepartmentIds($aiManager)->isEmpty());
    }

    // ── Domain B: technical AI platform is no longer role-coupled ──────────

    public function test_ai_manager_cannot_access_technical_ai_usage_without_explicit_permission(): void
    {
        $aiManager = User::factory()->create(['role' => 'ai_manager', 'is_active' => true]);

        $this->actingAs($aiManager)->getJson('/api/admin/ai/usage')->assertStatus(403);
        $this->actingAs($aiManager)->getJson('/api/admin/ai/usage/summary')->assertStatus(403);
    }

    public function test_ai_manager_cannot_access_technical_insights_automations_prompts_by_role_alone(): void
    {
        $aiManager = User::factory()->create(['role' => 'ai_manager', 'is_active' => true]);

        $this->actingAs($aiManager)->getJson('/api/admin/ai/insights')->assertStatus(403);
        $this->actingAs($aiManager)->getJson('/api/admin/ai/automations')->assertStatus(403);
        $this->actingAs($aiManager)->getJson('/api/admin/ai/prompts')->assertStatus(403);
    }

    public function test_ai_manager_granted_the_existing_view_ai_usage_permission_can_then_view_usage(): void
    {
        $aiManager = User::factory()->create(['role' => 'ai_manager', 'is_active' => true]);
        $role = \App\Models\Role::where('name', 'ai_manager')->first();
        $role?->grantPermission('view_ai_usage');
        $aiManager->flushPermissionCache();

        $this->actingAs($aiManager)->getJson('/api/admin/ai/usage')->assertOk();
        $this->actingAs($aiManager)->getJson('/api/admin/ai/usage/summary')->assertOk();
        // Still no automations/insights/prompts — that permission only covers usage.
        $this->actingAs($aiManager)->getJson('/api/admin/ai/automations')->assertStatus(403);
    }

    public function test_super_admin_retains_full_technical_ai_platform_access(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($superAdmin)->getJson('/api/admin/ai/insights')->assertOk();
        $this->actingAs($superAdmin)->getJson('/api/admin/ai/usage')->assertOk();
        $this->actingAs($superAdmin)->getJson('/api/admin/ai/automations')->assertOk();
    }

    public function test_tech_admin_retains_full_technical_ai_platform_access(): void
    {
        $techAdmin = User::factory()->create(['role' => 'tech_admin', 'is_active' => true]);

        $this->actingAs($techAdmin)->getJson('/api/admin/ai/insights')->assertOk();
        $this->actingAs($techAdmin)->getJson('/api/admin/ai/usage')->assertOk();
        $this->actingAs($techAdmin)->getJson('/api/admin/ai/automations')->assertOk();
    }

    public function test_admin_retains_technical_ai_platform_access(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->getJson('/api/admin/ai/insights')->assertOk();
        $this->actingAs($admin)->getJson('/api/admin/ai/usage')->assertOk();
    }

    public function test_ai_manager_no_longer_has_expert_applications_access(): void
    {
        // ExpertApplication's real fields (trainer/consultant/mentor/evaluator
        // application data, LinkedIn, WhatsApp, bio) confirm this is a talent/
        // expert-recruitment intake, not an AI system feature — it was only
        // ever nested under /admin/ai/* historically, with no other
        // justification for ai_manager to hold it. Reverted to admin-tier
        // access only; permanent ownership is an open product decision.
        $aiManager = User::factory()->create(['role' => 'ai_manager', 'is_active' => true]);

        $this->actingAs($aiManager)->getJson('/api/admin/expert-applications')->assertStatus(403);
    }

    public function test_admin_tier_roles_retain_expert_applications_access(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->getJson('/api/admin/expert-applications')->assertOk();
    }
}
