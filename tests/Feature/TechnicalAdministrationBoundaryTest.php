<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\DepartmentPageDefaultService;
use App\Services\RolePageDefaultService;
use App\Services\UserPageAccessOverrideService;
use App\Support\PageAccess\DepartmentPageAudience;
use App\Support\PageAccess\PageAccessCatalog;
use App\Support\PageAccess\PageAccessRiskLevel;
use App\Support\PageAccess\UserPageAccessState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Phase 2G.1C — TECHNICAL ADMINISTRATION BOUNDARY.
 *
 * Two corrections are locked in here, and they are deliberately separate things:
 *
 *  1. CATALOG CLASSIFICATION — nine capabilities move
 *     SYSTEM_PROTECTED -> ADMIN_ONLY. PageAccessRiskLevel defines ADMIN_ONLY as
 *     "admin-tier roles (admin/tech_admin/super_admin)" and SYSTEM_PROTECTED as
 *     "root-authority / platform-security surface". The nine are gated
 *     role:admin(,super_admin,tech_admin) in the backend, which is admin-tier —
 *     so SYSTEM_PROTECTED was one level too high. platform_scale and
 *     mobile_readiness stay SYSTEM_PROTECTED: they have no backend endpoint at
 *     all, so there is no evidence they are ordinary administrative surfaces.
 *
 *  2. BACKEND AUTHORIZATION — operations_manager loses technical platform
 *     administration. Those routes inherited an operations_manager grant purely
 *     because they sat inside a group that exists for DEPARTMENT routes. The
 *     aliases the frontend actually calls were already role:admin.
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION: reclassifying a capability to
 * ADMIN_ONLY changes only what may be stored as a page default. It grants no
 * backend permission to anyone — asserted explicitly below.
 */
class TechnicalAdministrationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    /** The exact nine corrected by Phase 2G.1C. */
    private const NINE_ADMIN_ONLY = [
        'administration.integrations',
        'administration.webhooks',
        'administration.catalog_import',
        'technical_system.ai_command_center',
        'technical_system.ai_automations',
        'technical_system.ai_insights',
        'technical_system.ai_usage',
        'technical_system.automations',
        'technical_system.api_tokens',
    ];

    /** Must remain root-only — no backend endpoint exists for either. */
    private const STILL_PROTECTED = [
        'technical_system.platform_scale',
        'technical_system.mobile_readiness',
    ];

    /** The six root surfaces that were never in scope and must not have moved. */
    private const UNTOUCHED_PROTECTED = [
        'administration.super_admin_overview',
        'administration.roles',
        'administration.permission_delegation_config',
        'administration.email_settings',
        'administration.attendance_settings',
        'administration.email_logs',
        // Added after 2G.1C, not reclassified by it: the central Access Control
        // page EDITS the access-control tables, so it is a root-authority
        // surface by the same logic as administration.roles above.
        'administration.access_control',
    ];

    /** The corrected technical-administration endpoints (canonical spellings). */
    private const TECHNICAL_ENDPOINTS = [
        ['GET', '/api/admin/integrations'],
        ['GET', '/api/admin/api-tokens'],
        ['GET', '/api/admin/webhook-endpoints'],
        ['GET', '/api/admin/webhook-deliveries'],
    ];

    private function user(string $role): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role, 'is_system' => false]);

        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    // ── 1. Catalog classification ────────────────────────────────────────────

    public function test_the_nine_corrected_capabilities_are_admin_only(): void
    {
        foreach (self::NINE_ADMIN_ONLY as $key) {
            $entry = PageAccessCatalog::find($key);

            $this->assertNotNull($entry, "Capability [{$key}] must exist in the catalog.");
            $this->assertSame(
                PageAccessRiskLevel::AdminOnly,
                $entry->riskLevel,
                "[{$key}] must be ADMIN_ONLY after Phase 2G.1C."
            );
        }
    }

    public function test_the_nine_corrected_capabilities_are_no_longer_system_protected(): void
    {
        foreach (self::NINE_ADMIN_ONLY as $key) {
            $entry = PageAccessCatalog::find($key);

            $this->assertFalse($entry->isProtected(), "[{$key}] must no longer be SYSTEM_PROTECTED.");
            $this->assertNotSame(PageAccessRiskLevel::SystemProtected, $entry->riskLevel);
        }
    }

    /**
     * ADMIN_ONLY is NOT SAFE_DELEGATABLE. The correction moved exactly one step,
     * and none of the nine may become delegatable to a department leader.
     */
    public function test_the_nine_did_not_become_delegatable(): void
    {
        $deptSvc = app(DepartmentPageDefaultService::class);

        foreach (self::NINE_ADMIN_ONLY as $key) {
            $entry = PageAccessCatalog::find($key);

            $this->assertFalse($entry->delegatable, "[{$key}] must not be delegatable.");
            $this->assertFalse($entry->departmentScoped, "[{$key}] must not be department-scoped.");
            $this->assertNotSame(PageAccessRiskLevel::SafeDelegatable, $entry->riskLevel);

            // Neither department audience may accept them.
            $this->assertFalse($deptSvc->isEligible($entry, DepartmentPageAudience::Member));
            $this->assertFalse($deptSvc->isEligible($entry, DepartmentPageAudience::Leader));
        }
    }

    public function test_platform_scale_and_mobile_readiness_remain_system_protected(): void
    {
        foreach (self::STILL_PROTECTED as $key) {
            $entry = PageAccessCatalog::find($key);

            $this->assertNotNull($entry);
            $this->assertSame(PageAccessRiskLevel::SystemProtected, $entry->riskLevel, "[{$key}] must stay protected.");
            $this->assertTrue($entry->isProtected());
            $this->assertFalse($entry->delegatable);
        }
    }

    public function test_the_six_root_surfaces_were_not_touched(): void
    {
        foreach (self::UNTOUCHED_PROTECTED as $key) {
            $entry = PageAccessCatalog::find($key);

            $this->assertNotNull($entry);
            $this->assertSame(PageAccessRiskLevel::SystemProtected, $entry->riskLevel, "[{$key}] must stay protected.");
        }
    }

    public function test_the_protected_set_is_exactly_the_expected_capabilities(): void
    {
        $protected = array_values(array_map(
            fn ($e) => $e->key,
            array_filter(PageAccessCatalog::all(), fn ($e) => $e->isProtected())
        ));

        sort($protected);
        $expected = array_merge(self::STILL_PROTECTED, self::UNTOUCHED_PROTECTED);
        sort($expected);

        $this->assertSame($expected, $protected, 'The protected set must be exactly these capabilities.');
        $this->assertCount(count($expected), $protected);
    }

    /** Nothing but the risk level changed — keys, routes and labels are stable identity. */
    public function test_keys_routes_and_labels_are_unchanged(): void
    {
        $expected = [
            'administration.integrations' => ['/dashboard/admin/integrations', 'التكاملات', 3],
            'administration.webhooks' => ['/dashboard/admin/webhooks', 'الويب هوك', 2],
            'administration.catalog_import' => ['/dashboard/admin/catalog-import', 'استيراد الكتالوج', 1],
            // 1 pattern: /dashboard/admin/ai/expert-applications moved to its own
            // capability, organizational_departments.ai_expert_applications.
            'technical_system.ai_command_center' => ['/dashboard/admin/ai', 'مركز قيادة الذكاء الاصطناعي', 1],
            'technical_system.ai_automations' => ['/dashboard/admin/ai/automations', 'أتمتة الذكاء الاصطناعي', 1],
            'technical_system.ai_insights' => ['/dashboard/admin/ai/insights', 'رؤى الذكاء الاصطناعي', 1],
            'technical_system.ai_usage' => ['/dashboard/admin/ai/usage', 'استخدام الذكاء الاصطناعي', 1],
            'technical_system.automations' => ['/dashboard/admin/automations', 'الأتمتة العامة', 2],
            'technical_system.api_tokens' => ['/dashboard/admin/developer/api-tokens', 'رموز واجهة البرمجة', 1],
        ];

        foreach ($expected as $key => [$route, $label, $patternCount]) {
            $entry = PageAccessCatalog::find($key);

            $this->assertSame($route, $entry->primaryRoute, "[{$key}] primary route must not change.");
            $this->assertSame($label, $entry->labelAr, "[{$key}] label must not change.");
            $this->assertCount($patternCount, $entry->routePatterns, "[{$key}] route patterns must not change.");
            $this->assertSame($route, $entry->routePatterns[0]);
        }
    }

    public function test_catalog_size_and_other_risk_levels_are_unchanged(): void
    {
        $all = PageAccessCatalog::all();
        $this->assertCount(141, $all);

        $byRisk = [];
        foreach ($all as $e) {
            $byRisk[$e->riskLevel->value] = ($byRisk[$e->riskLevel->value] ?? 0) + 1;
        }

        // Before Phase 2G.1C: 17 SYSTEM_PROTECTED + 66 ADMIN_ONLY + 46 SAFE_DELEGATABLE.
        // After:               8 SYSTEM_PROTECTED + 75 ADMIN_ONLY + 46 SAFE_DELEGATABLE.
        // The nine moved exactly one step, and SAFE_DELEGATABLE is untouched —
        // which is why department member/leader eligibility does not change.
        //
        // administration.access_control was added later as a ninth protected
        // surface. That is the ONLY reason SYSTEM_PROTECTED is 9 rather than 8,
        // and the two assertions that actually carry the 2G.1C invariant —
        // ADMIN_ONLY holding the nine reclassified entries, and SAFE_DELEGATABLE
        // being untouched — are unchanged.
        $this->assertSame(count(self::STILL_PROTECTED) + count(self::UNTOUCHED_PROTECTED), $byRisk['SYSTEM_PROTECTED']);
        // +1 ADMIN_ONLY afterwards: organizational_departments.ai_expert_applications,
        // a NEW capability (not a reclassification), so the 2G.1C invariant —
        // the nine moved entries plus an untouched SAFE_DELEGATABLE — still holds.
        // +2 ADMIN_ONLY afterwards: finance.expenses and finance.revenues, also new
        // capabilities rather than reclassifications.
        // +1 ADMIN_ONLY afterwards: technical_system.department_dashboards, the read-only
        // cross-department monitoring page, and executive.command_center the private follow-up hub
        // and hr.volunteer_opportunities the volunteer-opportunity publish workflow
        // (new capabilities, not reclassifications).
        // +1 ADMIN_ONLY afterwards: finance.payment_reconciliations, the settlement list for confirmed payments whose
        // enrollment was withheld (a new capability, not a reclassification).
        // +1 ADMIN_ONLY afterwards: organizational_departments.ai_trainer_applications, the trainer-application review
        // list (a new capability, not a reclassification).
        // +1 ADMIN_ONLY afterwards: hr.volunteer_points_approval, the volunteer-points review queue that used to ride on
        // the hr.dashboard prefix (a new registry row, not a reclassification).
        // Operations-hub update (2026-11-12): operations.my_tasks and operations.volunteers are new SAFE_DELEGATABLE
        // capabilities, and operations.volunteers_management moved from ADMIN_ONLY to SAFE_DELEGATABLE
        // (ADMIN_ONLY 84 -> 83, SAFE_DELEGATABLE 46 -> 49).
        $this->assertSame(83, $byRisk['ADMIN_ONLY']);
        $this->assertSame(49, $byRisk['SAFE_DELEGATABLE']);
        $this->assertSame(141, array_sum($byRisk));
    }

    // ── 2. PAGE ACCESS != BUSINESS AUTHORIZATION ─────────────────────────────

    /**
     * The reclassification makes the nine STORABLE as role defaults. It does NOT
     * grant anyone backend permission — the HTTP assertions below prove the
     * backend still refuses operations_manager even with a role default stored.
     */
    public function test_reclassification_makes_them_storable_as_role_defaults_only(): void
    {
        $roleSvc = app(RolePageDefaultService::class);
        $eligible = $roleSvc->eligibleKeys();

        foreach (self::NINE_ADMIN_ONLY as $key) {
            $this->assertContains($key, $eligible, "[{$key}] should now be storable as a role default.");
        }

        foreach (self::STILL_PROTECTED as $key) {
            $this->assertNotContains($key, $eligible, "[{$key}] must remain unstorable.");
        }
    }

    public function test_still_protected_capabilities_cannot_be_stored_in_any_mutable_table(): void
    {
        $superAdmin = $this->user('super_admin');
        $target = $this->user('volunteer');
        $role = Role::firstOrCreate(['name' => 'operations_manager'], ['display_name' => 'ops', 'is_system' => false]);

        foreach (self::STILL_PROTECTED as $key) {
            $this->assertThrows(
                fn () => app(RolePageDefaultService::class)->replaceDefaults($role, [$key], $superAdmin),
                InvalidArgumentException::class
            );

            $this->assertThrows(
                fn () => app(UserPageAccessOverrideService::class)
                    ->setOverride($superAdmin, $target, $key, UserPageAccessState::Allow),
                InvalidArgumentException::class
            );
        }
    }

    // ── 3. Backend authorization boundary (real HTTP) ────────────────────────

    public function test_operations_manager_is_refused_technical_administration(): void
    {
        $actor = $this->user('operations_manager');

        foreach (self::TECHNICAL_ENDPOINTS as [$method, $uri]) {
            $this->actingAs($actor)
                ->json($method, $uri)
                ->assertForbidden();
        }

        $this->actingAs($actor)
            ->postJson('/api/admin/catalog-import', [])
            ->assertForbidden();
    }

    public function test_operations_manager_is_refused_even_with_a_role_default_stored(): void
    {
        // PAGE ACCESS != BUSINESS AUTHORIZATION, proven directly: store the page
        // default the reclassification now permits, then confirm the backend
        // still refuses the request.
        $superAdmin = $this->user('super_admin');
        $role = Role::firstOrCreate(['name' => 'operations_manager'], ['display_name' => 'ops', 'is_system' => false]);
        app(RolePageDefaultService::class)->replaceDefaults($role, self::NINE_ADMIN_ONLY, $superAdmin);

        $actor = $this->user('operations_manager');

        $this->actingAs($actor)->getJson('/api/admin/integrations')->assertForbidden();
        $this->actingAs($actor)->getJson('/api/admin/api-tokens')->assertForbidden();
    }

    public function test_admin_and_root_retain_technical_administration(): void
    {
        foreach (['admin', 'super_admin', 'tech_admin'] as $role) {
            $actor = $this->user($role);

            foreach (self::TECHNICAL_ENDPOINTS as [$method, $uri]) {
                $response = $this->actingAs($actor)->json($method, $uri);

                $this->assertNotSame(
                    403,
                    $response->getStatusCode(),
                    "[{$role}] must not be refused by the authorization boundary at {$uri}."
                );
            }
        }
    }

    public function test_an_ordinary_role_is_refused_technical_administration(): void
    {
        foreach (['volunteer', 'instructor', 'finance_manager'] as $role) {
            $actor = $this->user($role);

            $this->actingAs($actor)->getJson('/api/admin/integrations')->assertForbidden();
            $this->actingAs($actor)->getJson('/api/admin/api-tokens')->assertForbidden();
        }
    }

    // ── 4. Legitimate operations access preserved ────────────────────────────

    /**
     * The narrowing must not cost operations_manager the DEPARTMENT routes the
     * group exists for. Without this, removing the over-grant could silently
     * break real Operations Management functionality.
     */
    public function test_operations_manager_retains_legitimate_department_access(): void
    {
        $actor = $this->user('operations_manager');

        $response = $this->actingAs($actor)->getJson('/api/admin/departments');

        $this->assertNotSame(
            403,
            $response->getStatusCode(),
            'operations_manager must keep the department routes this group exists for.'
        );
    }

    public function test_operations_manager_retains_the_operations_surface(): void
    {
        $actor = $this->user('operations_manager');

        // The marketing board is the operations surface this role keeps.
        $response = $this->actingAs($actor)->getJson('/api/operations/marketing');

        $this->assertNotSame(403, $response->getStatusCode(), 'operations_manager must keep /api/operations/marketing.');
    }

    /**
     * Volunteer Hub reads carry personal data, so since the 2026-11-12 operations-hub update they are limited to
     * HR manager and above (admin, super_admin, tech_admin, executive_admin, hr_manager). operations_manager and
     * community_manager used to be in that group and no longer are — on purpose.
     */
    public function test_volunteer_hub_routes_are_limited_to_hr_manager_and_above(): void
    {
        foreach (['operations_manager', 'community_manager', 'department_manager', 'volunteer', 'student'] as $role) {
            $this->actingAs($this->user($role))->getJson('/api/operations/volunteers')->assertForbidden();
            $this->actingAs($this->user($role))->getJson('/api/operations/volunteers/hub')->assertForbidden();
        }

        foreach (['hr_manager', 'admin', 'executive_admin'] as $role) {
            $status = $this->actingAs($this->user($role))->getJson('/api/operations/volunteers')->getStatusCode();
            $this->assertNotSame(403, $status, "{$role} must keep the volunteer hub reads.");
        }
    }

    // ── 5. AI separation is unaffected by the reclassification ───────────────

    public function test_ai_separation_still_holds_after_reclassification(): void
    {
        $platform = PageAccessCatalog::find('technical_system.ai_command_center');
        $workspace = PageAccessCatalog::find('organizational_departments.ai_workspace');

        // Different capabilities, different categories, different routes.
        $this->assertNotSame($platform->key, $workspace->key);
        $this->assertNotSame($platform->category, $workspace->category);
        $this->assertSame([], array_intersect($platform->routePatterns, $workspace->routePatterns));

        // The platform is admin-tier and NEVER delegatable; the workspace is.
        $this->assertSame(PageAccessRiskLevel::AdminOnly, $platform->riskLevel);
        $this->assertFalse($platform->delegatable);
        $this->assertTrue($workspace->delegatable);

        // ai_manager is still refused the technical platform by the backend.
        $this->actingAs($this->user('ai_manager'))
            ->getJson('/api/admin/ai/prompts')
            ->assertForbidden();
    }
}
