<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use App\Services\EffectivePageAccessService;
use App\Services\RolePageDefaultService;
use App\Services\UserPageAccessOverrideService;
use App\Support\PageAccess\PageAccessCatalog;
use App\Support\PageAccess\PageAccessCategory;
use App\Support\PageAccess\PageAccessEntry;
use App\Support\PageAccess\PageAccessRiskLevel;
use App\Support\PageAccess\PageAccessSource;
use App\Support\PageAccess\UserPageAccessState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Phase 2G.2 — AUTHENTICATED BASELINE.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * PAGE ACCESS != BUSINESS AUTHORIZATION
 * ─────────────────────────────────────────────────────────────────────────
 * Baseline means "any authenticated account may OPEN this page". It grants no
 * backend permission and no data visibility — every endpoint behind these
 * pages still scopes its own results. Asserted directly below.
 *
 * The baseline set is declared on PageAccessCatalog entries, NOT stored in
 * role_page_defaults / department_page_defaults / user_page_access_overrides.
 * That is the whole point: a new role inherits it automatically, and no admin
 * can remove a user's own profile page by editing a role.
 */
class AuthenticatedBaselineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The six approved in Phase 2G.1B and re-verified against current code in 2G.2, plus the learner space (دوراتي), added when
     * learning stopped being tied to the student role: every authenticated account can learn, and a new role inherits it.
     */
    private const BASELINE = [
        'personal.profile',
        'personal.notifications',
        'personal.notification_preferences',
        'operations.tickets',
        'hr.my_requests',
        'personal.volunteer_hr_profile',
        'personal.student_learning',
    ];

    /** Deliberately excluded — see the excluded-regression section. */
    private const EXCLUDED = [
        'operations.meeting_lounge',
        'organizational_departments.members',
    ];

    private function resolver(): EffectivePageAccessService
    {
        return app(EffectivePageAccessService::class);
    }

    private function user(string $role): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role, 'is_system' => false]);

        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    // ── 1/2. Catalog declares the baseline set ───────────────────────────────

    public function test_approved_baseline_entries_are_flagged(): void
    {
        foreach (self::BASELINE as $key) {
            $entry = PageAccessCatalog::find($key);

            $this->assertNotNull($entry, "Capability [{$key}] must exist.");
            $this->assertTrue($entry->authenticatedBaseline, "[{$key}] must be an authenticated baseline.");
        }
    }

    public function test_the_baseline_set_is_exactly_these_seven(): void
    {
        $flagged = array_values(array_map(
            fn (PageAccessEntry $e) => $e->key,
            array_filter(PageAccessCatalog::all(), fn (PageAccessEntry $e) => $e->authenticatedBaseline)
        ));

        sort($flagged);
        $expected = self::BASELINE;
        sort($expected);

        $this->assertSame($expected, $flagged, 'Only the seven approved capabilities may be baseline.');
    }

    public function test_every_other_capability_defaults_to_false(): void
    {
        $nonBaseline = array_filter(
            PageAccessCatalog::all(),
            fn (PageAccessEntry $e) => ! in_array($e->key, self::BASELINE, true)
        );

        $this->assertCount(131, $nonBaseline);

        foreach ($nonBaseline as $entry) {
            $this->assertFalse($entry->authenticatedBaseline, "[{$entry->key}] must not be baseline.");
        }
    }

    // ── 3. SYSTEM_PROTECTED can never be baseline ────────────────────────────

    public function test_no_system_protected_capability_is_baseline(): void
    {
        $protected = array_filter(PageAccessCatalog::all(), fn (PageAccessEntry $e) => $e->isProtected());

        // Derived from the catalog, not hardcoded to a pair of keys.
        $this->assertCount(9, $protected);

        foreach ($protected as $entry) {
            $this->assertFalse(
                $entry->authenticatedBaseline,
                "SYSTEM_PROTECTED [{$entry->key}] must never be an authenticated baseline."
            );
        }
    }

    /** The combination is refused structurally, so no future edit can introduce it. */
    public function test_the_combination_is_rejected_by_the_entry_constructor(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PageAccessEntry(
            key: 'test.protected_baseline',
            primaryRoute: '/dashboard/test',
            labelAr: 'اختبار',
            category: PageAccessCategory::Administration,
            riskLevel: PageAccessRiskLevel::SystemProtected,
            delegatable: false,
            requiredPermission: null,
            departmentScoped: false,
            routePatterns: [],
            authenticatedBaseline: true,
        );
    }

    // ── 4/5. Excluded capabilities ───────────────────────────────────────────

    public function test_meeting_lounge_and_members_are_not_baseline(): void
    {
        foreach (self::EXCLUDED as $key) {
            $entry = PageAccessCatalog::find($key);

            $this->assertNotNull($entry);
            $this->assertFalse($entry->authenticatedBaseline, "[{$key}] must stay excluded from baseline.");
        }
    }

    public function test_an_ordinary_user_does_not_gain_the_excluded_capabilities(): void
    {
        $keys = $this->resolver()->allowedKeys($this->user('volunteer'));

        foreach (self::EXCLUDED as $excluded) {
            $this->assertNotContains($excluded, $keys, "[{$excluded}] must not come from authentication alone.");
        }
    }

    // ── 6/7/8/9/10. Resolution with NO stored rows anywhere ──────────────────

    public function test_an_ordinary_user_gains_exactly_the_baseline_with_no_stored_rows(): void
    {
        $user = $this->user('volunteer');

        // No role default, no department default, no override — verified, not assumed.
        $this->assertSame(0, DB::table('role_page_defaults')->count());
        $this->assertSame(0, DB::table('department_page_defaults')->count());
        $this->assertSame(0, DB::table('user_page_access_overrides')->count());

        $allowed = $this->resolver()->allowedKeys($user);

        sort($allowed);
        $expected = self::BASELINE;
        sort($expected);

        $this->assertSame($expected, $allowed);
    }

    public function test_provenance_is_authenticated_baseline(): void
    {
        $user = $this->user('student');

        foreach (self::BASELINE as $key) {
            $result = $this->resolver()->resolve($user, $key);

            $this->assertTrue($result->allowed, "[{$key}] must be allowed.");
            $this->assertSame(PageAccessSource::AuthenticatedBaseline, $result->primarySource);
            $this->assertSame('authenticated_baseline', $result->reason);
            $this->assertSame('متاح لجميع المستخدمين المسجلين', $result->primarySource->labelAr());
            $this->assertContains(PageAccessSource::AuthenticatedBaseline, $result->sources);
            $this->assertNull($result->overrideState);
        }
    }

    /** Every role gets it, including roles with no defaults configured at all. */
    public function test_every_role_receives_the_baseline(): void
    {
        foreach (['student', 'partner', 'volunteer', 'instructor', 'finance_manager', 'support_agent'] as $role) {
            $allowed = $this->resolver()->allowedKeys($this->user($role));

            foreach (self::BASELINE as $key) {
                $this->assertContains($key, $allowed, "[{$role}] must receive baseline [{$key}].");
            }
        }
    }

    /** A brand-new role with no rows anywhere inherits the baseline automatically. */
    public function test_a_brand_new_role_inherits_the_baseline_without_configuration(): void
    {
        $user = $this->user('some_future_role_2g2');

        $this->assertSame(0, DB::table('role_page_defaults')->count());

        foreach (self::BASELINE as $key) {
            $this->assertTrue($this->resolver()->canAccess($user, $key));
        }
    }

    // ── A more specific source still wins primary_source ─────────────────────

    public function test_a_role_default_still_wins_the_primary_source_slot(): void
    {
        $superAdmin = $this->user('super_admin');
        $user = $this->user('volunteer');
        $role = Role::firstOrCreate(['name' => 'volunteer'], ['display_name' => 'v', 'is_system' => false]);

        app(RolePageDefaultService::class)->replaceDefaults($role, ['operations.tickets'], $superAdmin);

        $result = $this->resolver()->resolve($user, 'operations.tickets');

        $this->assertTrue($result->allowed);
        $this->assertSame(PageAccessSource::Role, $result->primarySource, 'Baseline must not mask a more specific source.');
        // Both contributors remain visible.
        $this->assertContains(PageAccessSource::Role, $result->sources);
        $this->assertContains(PageAccessSource::AuthenticatedBaseline, $result->sources);
    }

    public function test_a_department_member_default_still_wins_the_primary_source_slot(): void
    {
        $superAdmin = $this->user('super_admin');
        $dept = Department::create([
            'name' => 'Ops', 'name_ar' => 'ع', 'slug' => 'baseline-precedence',
            'status' => 'active', 'is_active' => true,
        ]);
        $user = $this->user('volunteer');
        $profile = TeamProfile::create([
            'department_id' => $dept->id, 'user_id' => $user->id,
            'name_ar' => $user->name, 'position_ar' => 'عضو', 'is_active' => true,
        ]);
        TeamMember::create([
            'user_id' => $user->id, 'team_profile_id' => $profile->id,
            'department_id' => $dept->id, 'status' => 'active',
        ]);

        app(\App\Services\DepartmentPageDefaultService::class)->replaceDefaults(
            $dept,
            \App\Support\PageAccess\DepartmentPageAudience::Member,
            ['hr.my_requests'],
            $superAdmin,
        );

        $result = $this->resolver()->resolve($user, 'hr.my_requests');

        $this->assertSame(PageAccessSource::DepartmentMember, $result->primarySource);
        $this->assertContains(PageAccessSource::AuthenticatedBaseline, $result->sources);
    }

    // ── 11. Root authority unchanged ─────────────────────────────────────────

    public function test_root_authority_is_unchanged(): void
    {
        $catalogSize = count(PageAccessCatalog::all());

        foreach (['super_admin', 'tech_admin'] as $role) {
            $user = $this->user($role);

            $this->assertCount($catalogSize, $this->resolver()->allowedKeys($user));

            // Root provenance is still root, never baseline.
            $result = $this->resolver()->resolve($user, 'personal.profile');
            $this->assertSame(PageAccessSource::RootAuthority, $result->primarySource);
        }
    }

    public function test_a_user_deny_cannot_strip_root_authority(): void
    {
        $root = $this->user('super_admin');

        DB::table('user_page_access_overrides')->insert([
            'user_id' => $root->id, 'page_key' => 'personal.profile', 'state' => 'deny',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->resolver()->resolve($root, 'personal.profile');

        $this->assertTrue($result->allowed);
        $this->assertSame(PageAccessSource::RootAuthority, $result->primarySource);
    }

    // ── 12. SYSTEM_PROTECTED still unreachable ───────────────────────────────

    public function test_ordinary_user_still_cannot_reach_any_protected_capability(): void
    {
        $keys = $this->resolver()->allowedKeys($this->user('volunteer'));

        foreach (PageAccessCatalog::all() as $entry) {
            if ($entry->isProtected()) {
                $this->assertNotContains($entry->key, $keys, "[{$entry->key}] must stay out of reach.");
            }
        }
    }

    // ── User DENY semantics preserved (no speculative denyability) ───────────

    /**
     * Phase 2G.2 deliberately did NOT introduce per-page denyability. The
     * existing rule — USER DENY beats every positive source — is preserved
     * unchanged, and now also applies to baseline. Whether an administrator
     * SHOULD be able to deny someone their own profile is an open product
     * decision, reported rather than silently invented.
     */
    public function test_user_deny_still_outranks_the_baseline(): void
    {
        $superAdmin = $this->user('super_admin');
        $user = $this->user('volunteer');

        app(UserPageAccessOverrideService::class)
            ->setOverride($superAdmin, $user, 'operations.tickets', UserPageAccessState::Deny);

        $result = $this->resolver()->resolve($user, 'operations.tickets');

        $this->assertFalse($result->allowed);
        $this->assertSame(PageAccessSource::UserDeny, $result->primarySource);
        // The inherited baseline stays visible so the UI can explain the block.
        $this->assertContains(PageAccessSource::AuthenticatedBaseline, $result->sources);
    }

    // ── 13. PAGE ACCESS != BUSINESS AUTHORIZATION ────────────────────────────

    /**
     * Baseline opens the Tickets page. It must NOT widen the ticket dataset:
     * /api/v1/tickets authorizes per object inside TicketController via
     * DepartmentAccessService, and that is untouched here.
     */
    public function test_ticket_baseline_does_not_grant_ticket_management(): void
    {
        $user = $this->user('volunteer');

        $this->assertTrue($this->resolver()->canAccess($user, 'operations.tickets'));

        // The admin/management ticket surfaces are separate capabilities and
        // remain denied.
        $this->assertFalse($this->resolver()->canAccess($user, 'administration.tickets_admin'));
        $this->assertFalse($this->resolver()->canAccess($user, 'operations.tickets_workspace'));

        // And the backend still refuses the admin ticket endpoint.
        $this->actingAs($user)->getJson('/api/admin/support-tickets')->assertForbidden();
    }

    /**
     * Baseline opens the personal HR requests page. It must NOT grant HR
     * management — that is a different capability with its own permission.
     */
    public function test_hr_baseline_does_not_grant_hr_management(): void
    {
        $user = $this->user('volunteer');

        $this->assertTrue($this->resolver()->canAccess($user, 'hr.my_requests'));
        $this->assertFalse($this->resolver()->canAccess($user, 'hr.incoming_requests'));
        $this->assertFalse($this->resolver()->canAccess($user, 'hr.dashboard'));

        $this->actingAs($user)->getJson('/api/hr/dashboard')->assertForbidden();
    }

    /**
     * Phase 2G.2 review — the hr.my_requests MULTI-ROUTE shape.
     *
     * This capability owns TWO frontend routes that render the SAME
     * self-service page (App.tsx: both -> HrMyRequestsPage):
     *
     *   /dashboard/hr/my-requests          legacy rule: [hr_manager]
     *   /dashboard/department/hr-requests  legacy rule: 'authenticated'
     *
     * The Phase 2F harness samples only `primary_route`, so it reports the
     * capability as legacy-denied for 34 personas even though the legacy system
     * already exposed the very same page to every authenticated user via the
     * second route. Those 34 NEWLY_ALLOWED rows are therefore a HARNESS MODEL
     * DIFFERENCE, not new access — asserted on the frontend side, and anchored
     * here so the route pair cannot be changed silently.
     */
    public function test_hr_my_requests_owns_both_self_service_routes(): void
    {
        $entry = PageAccessCatalog::find('hr.my_requests');

        $this->assertNotNull($entry);
        $this->assertSame('/dashboard/hr/my-requests', $entry->primaryRoute);
        $this->assertSame(
            ['/dashboard/hr/my-requests', '/dashboard/department/hr-requests'],
            $entry->routePatterns,
            'Both self-service routes must stay on this one capability.'
        );
        $this->assertTrue($entry->authenticatedBaseline);
        $this->assertNull($entry->requiredPermission, 'Opening the page requires no business permission.');
    }

    /** Management is a DIFFERENT capability, with its own permission and no baseline. */
    public function test_hr_management_is_a_separate_non_baseline_capability(): void
    {
        $management = PageAccessCatalog::find('hr.incoming_requests');
        $selfService = PageAccessCatalog::find('hr.my_requests');

        $this->assertNotNull($management);
        $this->assertFalse($management->authenticatedBaseline, 'HR management must never be baseline.');
        $this->assertNotSame($management->key, $selfService->key);
        $this->assertSame([], array_intersect($management->routePatterns, $selfService->routePatterns));
        $this->assertNotNull(
            $management->requiredPermission,
            'HR management still carries a business permission requirement.'
        );
    }

    public function test_notification_baseline_does_not_grant_notification_administration(): void
    {
        $user = $this->user('student');

        $this->assertTrue($this->resolver()->canAccess($user, 'personal.notifications'));
        $this->assertTrue($this->resolver()->canAccess($user, 'personal.notification_preferences'));

        // Administrative notification/email surfaces stay protected.
        $this->assertFalse($this->resolver()->canAccess($user, 'administration.email_settings'));
        $this->assertFalse($this->resolver()->canAccess($user, 'administration.email_logs'));
    }

    // ── 14. The three public methods stay consistent ─────────────────────────

    public function test_resolve_resolve_all_and_can_access_agree(): void
    {
        $user = $this->user('instructor');
        $svc = $this->resolver();
        $all = $svc->resolveAll($user);

        $this->assertCount(138, $all);

        foreach ($all as $key => $row) {
            $this->assertSame($row->allowed, $svc->canAccess($user, $key), "canAccess disagreed for [{$key}].");
            $this->assertSame($row->allowed, $svc->resolve($user, $key)->allowed, "resolve disagreed for [{$key}].");
        }
    }

    // ── 15. Catalog API exposes the metadata ─────────────────────────────────

    public function test_catalog_api_exposes_authenticated_baseline(): void
    {
        $response = $this->actingAs($this->user('super_admin'))
            ->getJson('/api/admin/access-control/pages')
            ->assertOk();

        $pages = collect($response->json('data.pages'))->keyBy('key');

        foreach (self::BASELINE as $key) {
            $this->assertTrue($pages[$key]['authenticated_baseline'], "[{$key}] must serialize as baseline.");
        }

        foreach (self::EXCLUDED as $key) {
            $this->assertFalse($pages[$key]['authenticated_baseline']);
        }

        // Existing fields are unchanged.
        $profile = $pages['personal.profile'];
        foreach (['key', 'primary_route', 'route_patterns', 'label_ar', 'category', 'risk_level', 'protected', 'delegatable', 'required_permission', 'department_scoped'] as $field) {
            $this->assertArrayHasKey($field, $profile, "Catalog field [{$field}] must remain.");
        }
        $this->assertSame('/dashboard/profile', $profile['primary_route']);
    }

    // ── 16. Nothing is written ───────────────────────────────────────────────

    public function test_the_baseline_writes_no_rows(): void
    {
        $user = $this->user('volunteer');

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)\s/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $this->resolver()->resolveAll($user);
        $this->resolver()->allowedKeys($user);
        $this->resolver()->canAccess($user, 'personal.profile');

        $this->assertSame([], $writes, 'Baseline resolution must write nothing.');
        $this->assertSame(0, DB::table('role_page_defaults')->count());
        $this->assertSame(0, DB::table('department_page_defaults')->count());
        $this->assertSame(0, DB::table('user_page_access_overrides')->count());
    }

    public function test_baseline_adds_no_queries(): void
    {
        $user = $this->user('volunteer');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->resolver()->resolveAll($user);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Baseline is read off the already-loaded catalog entry, so the bounded
        // query pattern from Phase 2D is unchanged.
        $this->assertLessThan(32, $count, "resolveAll() used {$count} queries — baseline must add none.");
    }
}
