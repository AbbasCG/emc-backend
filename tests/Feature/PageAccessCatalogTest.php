<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PageAccess\PageAccessCatalog;
use App\Support\PageAccess\PageAccessRiskLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 / 1.1 — Page Access Catalog foundation + coverage regression suite.
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION: these tests only verify the catalog
 * itself (shape, uniqueness, classification invariants) and its read-only
 * API — never that a user can actually perform a business action. Business
 * authorization remains the job of policies/controllers/DepartmentAccessService.
 */
class PageAccessCatalogTest extends TestCase
{
    use RefreshDatabase;

    // ── Catalog structure ───────────────────────────────────────────────────

    public function test_catalog_keys_are_unique(): void
    {
        $keys = array_map(fn ($e) => $e->key, PageAccessCatalog::all());

        $this->assertSame(count($keys), count(array_unique($keys)), 'Duplicate catalog keys found.');
        $this->assertNotEmpty($keys);
    }

    public function test_every_entry_has_required_fields(): void
    {
        foreach (PageAccessCatalog::all() as $entry) {
            $this->assertNotEmpty($entry->key, 'Entry missing key.');
            $this->assertNotEmpty($entry->primaryRoute, "Entry [{$entry->key}] missing primary_route.");
            $this->assertNotEmpty($entry->labelAr, "Entry [{$entry->key}] missing label_ar.");
            $this->assertStringStartsWith('/dashboard', $entry->primaryRoute, "Entry [{$entry->key}] primary_route must be a dashboard path.");
            // key must stay stable even if the route changes later — it must
            // not simply mirror the route.
            $this->assertNotSame($entry->key, $entry->primaryRoute);
        }
    }

    public function test_route_patterns_are_structurally_valid_and_include_the_primary_route(): void
    {
        foreach (PageAccessCatalog::all() as $entry) {
            $this->assertNotEmpty($entry->routePatterns, "Entry [{$entry->key}] has no route patterns.");
            $this->assertContains($entry->primaryRoute, $entry->routePatterns, "Entry [{$entry->key}] route_patterns must include its own primary_route.");
            $this->assertSame($entry->primaryRoute, $entry->routePatterns[0], "Entry [{$entry->key}] primary_route must be the first route pattern.");

            foreach ($entry->routePatterns as $pattern) {
                $this->assertNotEmpty($pattern, "Entry [{$entry->key}] has an empty route pattern.");
                $this->assertStringStartsWith('/dashboard', $pattern, "Entry [{$entry->key}] route pattern [{$pattern}] must be a dashboard path.");
            }

            // No duplicate patterns within the same entry.
            $this->assertSame(count($entry->routePatterns), count(array_unique($entry->routePatterns)), "Entry [{$entry->key}] has duplicate route patterns.");
        }
    }

    public function test_no_route_pattern_is_claimed_by_two_different_capabilities(): void
    {
        $owner = [];
        $conflicts = [];

        foreach (PageAccessCatalog::all() as $entry) {
            foreach ($entry->routePatterns as $pattern) {
                if (isset($owner[$pattern]) && $owner[$pattern] !== $entry->key) {
                    $conflicts[] = "{$pattern} claimed by both [{$owner[$pattern]}] and [{$entry->key}]";
                }
                $owner[$pattern] = $entry->key;
            }
        }

        $this->assertEmpty($conflicts, 'Conflicting route pattern ownership: ' . implode('; ', $conflicts));
    }

    public function test_every_entry_has_a_valid_risk_level(): void
    {
        $valid = array_map(fn (PageAccessRiskLevel $r) => $r->value, PageAccessRiskLevel::cases());

        foreach (PageAccessCatalog::all() as $entry) {
            $this->assertContains($entry->riskLevel->value, $valid, "Entry [{$entry->key}] has an invalid risk_level.");
        }
    }

    public function test_protected_entries_are_never_delegatable(): void
    {
        foreach (PageAccessCatalog::all() as $entry) {
            if ($entry->isProtected()) {
                $this->assertFalse($entry->delegatable, "SYSTEM_PROTECTED entry [{$entry->key}] must not be delegatable.");
            }
        }
    }

    public function test_admin_only_entries_default_to_not_delegatable(): void
    {
        foreach (PageAccessCatalog::all() as $entry) {
            if ($entry->riskLevel === PageAccessRiskLevel::AdminOnly) {
                $this->assertFalse($entry->delegatable, "ADMIN_ONLY entry [{$entry->key}] is delegatable without a documented exception — ADMIN_ONLY defaults to non-delegatable.");
            }
        }
    }

    public function test_protected_flag_agrees_with_risk_level(): void
    {
        foreach (PageAccessCatalog::all() as $entry) {
            $this->assertSame(
                $entry->riskLevel === PageAccessRiskLevel::SystemProtected,
                $entry->isProtected(),
                "Entry [{$entry->key}] protected flag disagrees with its risk_level."
            );
        }
    }

    public function test_find_returns_entry_by_stable_key(): void
    {
        $entry = PageAccessCatalog::find('operations.weekly_reports');

        $this->assertNotNull($entry);
        $this->assertSame('/dashboard/operations/weekly-reports', $entry->primaryRoute);
        $this->assertNull(PageAccessCatalog::find('does.not.exist'));
    }

    public function test_find_by_route_resolves_an_alternate_mount_point_to_its_capability(): void
    {
        // /dashboard/admin/finance/payments is an alternate mount point of the
        // same finance.payments capability as /dashboard/finance/payments.
        $entry = PageAccessCatalog::findByRoute('/dashboard/admin/finance/payments');

        $this->assertNotNull($entry);
        $this->assertSame('finance.payments', $entry->key);
    }

    // ── Known critical capabilities must exist (drift protection) ───────────

    public function test_known_critical_capabilities_exist(): void
    {
        $criticalKeys = [
            'administration.roles',
            'administration.permission_delegation_config',
            'administration.users',
            'administration.webhooks',
            'administration.integrations',
            'technical_system.ai_command_center',
            'organizational_departments.ai_workspace',
            'operations.workshop_requests',
            'finance.dashboard',
            'personal.profile',
        ];

        foreach ($criticalKeys as $key) {
            $this->assertNotNull(PageAccessCatalog::find($key), "Critical capability [{$key}] is missing from the catalog.");
        }
    }

    // ── AI department vs AI platform separation (Step 9) ────────────────────

    public function test_technical_ai_platform_is_protected(): void
    {
        $entry = PageAccessCatalog::find('technical_system.ai_command_center');

        $this->assertNotNull($entry);
        $this->assertTrue($entry->isProtected());
        $this->assertFalse($entry->delegatable);
        $this->assertSame(PageAccessRiskLevel::SystemProtected, $entry->riskLevel);
    }

    public function test_organizational_ai_department_remains_separate_and_not_system_protected(): void
    {
        $orgEntry = PageAccessCatalog::find('organizational_departments.ai_workspace');
        $platformEntry = PageAccessCatalog::find('technical_system.ai_command_center');

        $this->assertNotNull($orgEntry);
        $this->assertNotNull($platformEntry);
        $this->assertNotSame(PageAccessRiskLevel::SystemProtected, $orgEntry->riskLevel);
        $this->assertNotSame($orgEntry->key, $platformEntry->key);
        $this->assertNotSame($orgEntry->primaryRoute, $platformEntry->primaryRoute);
        $this->assertNotSame($orgEntry->category, $platformEntry->category);
        // Route sets must not overlap either.
        $this->assertEmpty(array_intersect($orgEntry->routePatterns, $platformEntry->routePatterns));
    }

    // ── API ──────────────────────────────────────────────────────────────────

    public function test_super_admin_can_read_the_catalog_api(): void
    {
        $actor = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $response = $this->actingAs($actor)->getJson('/api/admin/access-control/pages');

        $response->assertOk()->assertJsonStructure([
            'success',
            'data' => [
                'pages' => [
                    ['key', 'primary_route', 'route_patterns', 'label_ar', 'category', 'category_label_ar', 'risk_level', 'protected', 'delegatable', 'required_permission', 'department_scoped'],
                ],
                'categories' => [['key', 'label_ar']],
            ],
        ]);
    }

    public function test_tech_admin_can_read_the_catalog_api(): void
    {
        $actor = User::factory()->create(['role' => 'tech_admin', 'is_active' => true]);

        $response = $this->actingAs($actor)->getJson('/api/admin/access-control/pages');

        $response->assertOk();
    }

    public function test_ordinary_admin_cannot_read_the_catalog_api(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $response = $this->actingAs($actor)->getJson('/api/admin/access-control/pages');

        $response->assertStatus(403);
    }

    public function test_department_manager_cannot_read_the_catalog_api(): void
    {
        $actor = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);

        $response = $this->actingAs($actor)->getJson('/api/admin/access-control/pages');

        $response->assertStatus(403);
    }

    public function test_unauthenticated_request_cannot_read_the_catalog_api(): void
    {
        $response = $this->getJson('/api/admin/access-control/pages');

        $response->assertStatus(401);
    }
}
