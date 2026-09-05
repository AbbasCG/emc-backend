<?php

namespace Tests\Feature;

use App\Models\AiAutomationRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Root-cause coverage for "AI Command Center fails to load in production":
 * the page's own contract to `GET /ai/generations` had no backend
 * implementation at all (AiContentGenerationController is entirely
 * stateless — nothing is ever persisted to list), which is why that one
 * call 404'd. The other four Command Center contracts (insights,
 * automations, usage, recommendations) were already correct — verified
 * here so a future regression on any of them is caught immediately.
 *
 * Actor updated to `admin` (was `ai_manager`) after the AI Department /
 * AI Platform separation: the technical AI Command Center routes this file
 * verifies are no longer granted to ai_manager by role — see
 * AiDepartmentPlatformSeparationTest for that access-boundary coverage.
 * This file's job is unchanged: prove the routes/contracts themselves work.
 */
class AiCommandCenterAccessTest extends TestCase
{
    use RefreshDatabase;

    private function technicalAiAdmin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    public function test_admin_can_access_ai_insights(): void
    {
        $this->actingAs($this->technicalAiAdmin())->getJson('/api/admin/ai/insights')->assertOk();
    }

    public function test_admin_can_access_ai_automations(): void
    {
        $this->actingAs($this->technicalAiAdmin())->getJson('/api/admin/ai/automations')->assertOk();
    }

    public function test_admin_can_access_ai_automation_executions(): void
    {
        $rule = AiAutomationRule::create([
            'name' => 'Test rule', 'trigger' => 'manual', 'conditions' => [],
            'ai_action' => 'notify', 'action_config' => [], 'status' => 'active',
        ]);

        $this->actingAs($this->technicalAiAdmin())
            ->getJson("/api/admin/ai/automations/{$rule->id}/executions")
            ->assertOk();
    }

    public function test_admin_can_access_ai_usage(): void
    {
        $this->actingAs($this->technicalAiAdmin())->getJson('/api/admin/ai/usage')->assertOk();
    }

    public function test_admin_can_access_ai_usage_summary(): void
    {
        $this->actingAs($this->technicalAiAdmin())->getJson('/api/admin/ai/usage/summary')->assertOk();
    }

    public function test_ai_manager_can_access_ai_recommendations(): void
    {
        // /ai/recommendations only requires auth:sanctum (any authenticated
        // role) — not restricted to ai_manager, but must not 403/404 for it
        // either. This one endpoint genuinely is open to any authenticated
        // role, unrelated to the Domain A/B separation.
        $aiManager = User::factory()->create(['role' => 'ai_manager', 'is_active' => true]);
        $this->actingAs($aiManager)
            ->getJson('/api/ai/recommendations?audience=admin')
            ->assertOk();
    }

    public function test_generations_endpoint_genuinely_does_not_exist_on_the_backend(): void
    {
        // Documents the confirmed root cause: no route, no controller method,
        // no persistence layer — this is not a typo to "fix" by adding a route.
        $this->actingAs($this->technicalAiAdmin())->getJson('/api/ai/generations')->assertStatus(404);
    }

    public function test_unrelated_normal_user_cannot_access_privileged_ai_endpoints(): void
    {
        $student = User::factory()->create(['role' => 'student', 'is_active' => true]);

        $this->actingAs($student)->getJson('/api/admin/ai/insights')->assertStatus(403);
        $this->actingAs($student)->getJson('/api/admin/ai/automations')->assertStatus(403);
        $this->actingAs($student)->getJson('/api/admin/ai/usage')->assertStatus(403);
    }

    public function test_route_urls_exactly_match_the_frontend_contract(): void
    {
        // Mirrors src/api/aiInsightsApi.ts, aiAutomationsApi.ts, aiUsageApi.ts,
        // aiRecommendationsApi.ts — the exact paths those modules call.
        $admin = $this->technicalAiAdmin();

        $this->actingAs($admin)->getJson('/api/admin/ai/insights')->assertOk();
        $this->actingAs($admin)->getJson('/api/admin/ai/automations')->assertOk();
        $this->actingAs($admin)->getJson('/api/admin/ai/usage')->assertOk();
        $this->actingAs($admin)->getJson('/api/ai/recommendations?audience=admin')->assertOk();
    }
}
