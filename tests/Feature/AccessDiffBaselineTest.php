<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use App\Services\DepartmentPageDefaultService;
use App\Services\EffectivePageAccessService;
use App\Services\RolePageDefaultService;
use App\Services\UserPageAccessOverrideService;
use App\Support\PageAccess\DepartmentPageAudience;
use App\Support\PageAccess\EffectivePageAccess;
use App\Support\PageAccess\PageAccessCatalog;
use App\Support\PageAccess\PageAccessEntry;
use App\Support\PageAccess\UserPageAccessState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Phase 2F — EFFECTIVE ACCESS BASELINE GENERATOR.
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION, and this file changes NOTHING about
 * either. It is a read-only measurement harness: it builds personas, runs the
 * REAL EffectivePageAccessService against them, and emits a deterministic JSON
 * baseline that the frontend diff harness compares against the REAL legacy
 * canAccessDashboardPath(). Both sides of the comparison are therefore the
 * actual production code, never a re-implementation.
 *
 * TWO SCENARIOS are emitted, because they answer different questions:
 *
 *   `unseeded` — mirrors PRODUCTION TODAY. All three Phase 2A/2B/2C tables are
 *                empty in the live database (verified: 0 rows each), so this is
 *                literally what a cutover today would produce.
 *
 *   `seeded`   — a representative configuration, so the harness can be shown to
 *                detect leadership, membership, overrides, protection and the
 *                AI separation correctly. Without seeded rows those paths are
 *                untestable, since every non-root persona resolves to nothing.
 *
 * The emitted file is consumed by the frontend as a committed fixture. This
 * test both GENERATES and ASSERTS it, so the two repositories cannot drift
 * silently: if the resolver changes, this test rewrites the baseline and the
 * frontend diff test fails until the new baseline is reviewed.
 */
class AccessDiffBaselineTest extends TestCase
{
    use RefreshDatabase;

    private const OUT_DIR = 'access-diff';

    /** Roles covered by the persona matrix — every role slug in the roles table. */
    private const ROLES = [
        'super_admin', 'tech_admin', 'admin', 'executive_admin',
        'operations_manager', 'programs_manager', 'finance_manager', 'quality_manager',
        'hr_manager', 'marketing_manager', 'partnerships_manager', 'community_manager',
        'support_agent', 'instructor', 'student', 'partner', 'volunteer',
        'department_manager', 'section_lead', 'ai_manager',
        'strategy_planning_manager', 'digital_ambassadors_manager', 'advisors_manager',
    ];

    /** Roles that also get member/leader department-context variants. */
    private const DEPARTMENT_ROLES = [
        'operations_manager', 'programs_manager', 'department_manager', 'ai_manager',
        'strategy_planning_manager', 'quality_manager', 'volunteer',
    ];

    // Representative capabilities used for the seeded scenario.
    private const MEMBER_PAGE = 'operations.weekly_reports';
    private const MEMBER_PAGE_2 = 'operations.meeting_reports';
    private const LEADER_PAGE = 'organizational_departments.structure';
    private const ROLE_PAGE_FINANCE = 'finance.dashboard';
    private const ROLE_PAGE_QUALITY = 'quality.dashboard';
    /**
     * The AI SEPARATION, as the catalog itself encodes it:
     *   organizational_departments.ai_workspace  /dashboard/ai-department  SAFE_DELEGATABLE
     *   technical_system.ai_command_center       /dashboard/admin/ai       ADMIN_ONLY
     * An ai_manager legitimately reaches the first and must never reach the second.
     *
     * Phase 2G.1C reclassified the platform SYSTEM_PROTECTED -> ADMIN_ONLY (its
     * backend gate is role:admin,super_admin,tech_admin — admin-tier, not root).
     * The separation is unaffected: ADMIN_ONLY is still never auto-delegated to a
     * department leader, and the entry remains delegatable=false, so no
     * department default can ever hand it to ai_manager.
     */
    private const AI_DEPARTMENT_PAGE = 'organizational_departments.ai_workspace';
    private const AI_PLATFORM_PAGE = 'technical_system.ai_command_center';
    private const PROTECTED_PAGE = 'administration.roles';

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function resolver(): EffectivePageAccessService
    {
        return app(EffectivePageAccessService::class);
    }

    private function makeUser(string $role): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role, 'is_system' => false]);

        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function makeDepartment(string $slug): Department
    {
        return Department::create([
            'name' => $slug, 'name_ar' => $slug, 'slug' => $slug,
            'status' => 'active', 'is_active' => true,
        ]);
    }

    /** Real membership: TeamProfile -> TeamMember, the source DepartmentAccessService reads. */
    private function makeMember(User $user, Department $dept): void
    {
        $profile = TeamProfile::create([
            'department_id' => $dept->id, 'user_id' => $user->id,
            'name_ar' => $user->name, 'position_ar' => 'عضو', 'is_active' => true,
        ]);

        TeamMember::create([
            'user_id' => $user->id, 'team_profile_id' => $profile->id,
            'department_id' => $dept->id, 'status' => 'active',
        ]);
    }

    /** Canonical leadership — departments.leader_id ONLY. */
    private function makeLeader(User $user, Department $dept): void
    {
        $dept->forceFill(['leader_id' => $user->id])->save();
    }

    /**
     * @param  array<string, EffectivePageAccess>  $resolved
     * @return array<string, mixed>
     */
    private function summarize(array $resolved): array
    {
        $allowed = [];
        $provenance = [];

        foreach ($resolved as $key => $row) {
            if ($row->allowed) {
                $allowed[] = $key;
                $provenance[$key] = $row->primarySource->value;
            }
        }

        return ['allowed' => $allowed, 'provenance' => $provenance];
    }

    // ── Scenario builders ────────────────────────────────────────────────────

    /**
     * PRODUCTION TODAY: schema migrated, zero rows in every page-access table.
     *
     * @return array<string, mixed>
     */
    private function buildUnseededScenario(): array
    {
        $personas = [];

        foreach (self::ROLES as $role) {
            $user = $this->makeUser($role);
            $personas[$role.'__none'] = [
                'role' => $role,
                'context' => 'none',
                'note' => 'role only — no department, no override',
            ] + $this->summarize($this->resolver()->resolveAll($user));
        }

        // Department contexts still resolve to nothing while the tables are empty —
        // which is the point: membership and leadership have nothing to inherit.
        foreach (self::DEPARTMENT_ROLES as $role) {
            $dept = $this->makeDepartment('unseeded-'.$role);

            $member = $this->makeUser($role);
            $this->makeMember($member, $dept);
            $personas[$role.'__member'] = [
                'role' => $role, 'context' => 'member', 'note' => 'department member, no defaults stored',
            ] + $this->summarize($this->resolver()->resolveAll($member));

            $leader = $this->makeUser($role);
            $this->makeMember($leader, $dept);
            $this->makeLeader($leader, $dept);
            $personas[$role.'__leader'] = [
                'role' => $role, 'context' => 'leader', 'note' => 'canonical departments.leader_id leader, no defaults stored',
            ] + $this->summarize($this->resolver()->resolveAll($leader));
        }

        return $personas;
    }

    /**
     * A representative seeded configuration, so leadership / membership /
     * override / protection / AI-separation behaviour is observable.
     *
     * @return array<string, mixed>
     */
    private function buildSeededScenario(): array
    {
        $roleSvc = app(RolePageDefaultService::class);
        $deptSvc = app(DepartmentPageDefaultService::class);
        $overrideSvc = app(UserPageAccessOverrideService::class);

        $superAdmin = $this->makeUser('super_admin');

        // ── Role defaults (POSITIVE ONLY — Phase 2B has no role-level DENY) ──
        $roleDefaults = [
            'finance_manager' => [self::ROLE_PAGE_FINANCE, self::MEMBER_PAGE],
            'quality_manager' => [self::ROLE_PAGE_QUALITY, self::MEMBER_PAGE],
            'instructor' => [self::MEMBER_PAGE],
            'volunteer' => [self::MEMBER_PAGE],
            'ai_manager' => [self::AI_DEPARTMENT_PAGE],
        ];

        foreach ($roleDefaults as $roleName => $keys) {
            $role = Role::firstOrCreate(['name' => $roleName], ['display_name' => $roleName, 'is_system' => false]);
            $roleSvc->replaceDefaults($role, $keys, $superAdmin);
        }

        // ── Department defaults: members get one page, the leader gets one MORE ──
        $dept = $this->makeDepartment('seeded-operations');
        $deptSvc->replaceDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE_2], $superAdmin);
        $deptSvc->replaceDefaults($dept, DepartmentPageAudience::Leader, [self::LEADER_PAGE], $superAdmin);

        $personas = [];

        // Root pair — unrestricted by canonical root authority, not by any table.
        foreach (['super_admin', 'tech_admin'] as $rootRole) {
            $root = $rootRole === 'super_admin' ? $superAdmin : $this->makeUser('tech_admin');
            $personas[$rootRole.'__none'] = [
                'role' => $rootRole, 'context' => 'none', 'note' => 'canonical root authority',
            ] + $this->summarize($this->resolver()->resolveAll($root));
        }

        // Role-only personas (no department relationship at all).
        foreach (['admin', 'executive_admin', 'finance_manager', 'quality_manager', 'instructor', 'student', 'partner', 'support_agent', 'hr_manager'] as $role) {
            $u = $this->makeUser($role);
            $personas[$role.'__none'] = [
                'role' => $role, 'context' => 'none', 'note' => 'role defaults only',
            ] + $this->summarize($this->resolver()->resolveAll($u));
        }

        // ── Department context matrix on ONE department ──────────────────────
        // role-only: a "manager" slug with NO leader_id must gain nothing extra.
        $roleOnlyManager = $this->makeUser('operations_manager');
        $personas['operations_manager__none'] = [
            'role' => 'operations_manager', 'context' => 'none',
            'note' => 'manager ROLE SLUG only — no TeamProfile, no leader_id',
        ] + $this->summarize($this->resolver()->resolveAll($roleOnlyManager));

        $memberManager = $this->makeUser('operations_manager');
        $this->makeMember($memberManager, $dept);
        $personas['operations_manager__member'] = [
            'role' => 'operations_manager', 'context' => 'member',
            'note' => 'real department member (TeamProfile -> TeamMember)',
        ] + $this->summarize($this->resolver()->resolveAll($memberManager));

        $leaderManager = $this->makeUser('operations_manager');
        $this->makeMember($leaderManager, $dept);
        $this->makeLeader($leaderManager, $dept);
        $personas['operations_manager__leader'] = [
            'role' => 'operations_manager', 'context' => 'leader',
            'note' => 'canonical departments.leader_id leader — member + leader defaults',
        ] + $this->summarize($this->resolver()->resolveAll($leaderManager));

        // ── AI separation: ai_manager leads the AI DEPARTMENT, and that must
        //    never imply the technical AI Platform (SYSTEM_PROTECTED).
        $aiDept = $this->makeDepartment('seeded-ai-department');
        $deptSvc->replaceDefaults($aiDept, DepartmentPageAudience::Member, [self::MEMBER_PAGE_2], $superAdmin);
        $deptSvc->replaceDefaults($aiDept, DepartmentPageAudience::Leader, [self::LEADER_PAGE], $superAdmin);

        $aiLeader = $this->makeUser('ai_manager');
        $this->makeMember($aiLeader, $aiDept);
        $this->makeLeader($aiLeader, $aiDept);
        $personas['ai_manager__leader'] = [
            'role' => 'ai_manager', 'context' => 'leader',
            'note' => 'leads the organizational AI Department — NOT the technical AI Platform',
        ] + $this->summarize($this->resolver()->resolveAll($aiLeader));

        // ── User override variants ───────────────────────────────────────────
        $allowUser = $this->makeUser('volunteer');
        $overrideSvc->setOverride($superAdmin, $allowUser, self::ROLE_PAGE_FINANCE, UserPageAccessState::Allow);
        $personas['volunteer__override_allow'] = [
            'role' => 'volunteer', 'context' => 'none',
            'note' => 'explicit ALLOW on a page no role/department grants',
        ] + $this->summarize($this->resolver()->resolveAll($allowUser));

        $denyUser = $this->makeUser('volunteer');
        $overrideSvc->setOverride($superAdmin, $denyUser, self::MEMBER_PAGE, UserPageAccessState::Deny);
        $personas['volunteer__override_deny'] = [
            'role' => 'volunteer', 'context' => 'none',
            'note' => 'explicit DENY removing a page the role default grants',
        ] + $this->summarize($this->resolver()->resolveAll($denyUser));

        return $personas;
    }

    // ── The generator ────────────────────────────────────────────────────────

    public function test_it_emits_the_effective_access_baseline(): void
    {
        $catalog = array_map(static fn (PageAccessEntry $e) => [
            'key' => $e->key,
            'primary_route' => $e->primaryRoute,
            'route_patterns' => $e->routePatterns,
            'label_ar' => $e->labelAr,
            'category' => $e->category->value,
            'risk_level' => $e->riskLevel->value,
            'protected' => $e->isProtected(),
            'delegatable' => $e->delegatable,
            'department_scoped' => $e->departmentScoped,
        ], PageAccessCatalog::all());

        // ORDER MATTERS: the unseeded scenario is resolved while all three
        // page-access tables are still empty (RefreshDatabase gives this test a
        // clean database), and only afterwards does the seeded scenario write
        // rows. The two therefore cannot contaminate each other, and no
        // mid-test database reset is needed — one would open a nested
        // transaction inside RefreshDatabase's own and break the connection.
        $unseeded = $this->buildUnseededScenario();
        $seeded = $this->buildSeededScenario();

        $baseline = [
            'generated_by' => 'tests/Feature/AccessDiffBaselineTest.php',
            'catalog_count' => count($catalog),
            'catalog' => $catalog,
            'scenarios' => [
                'unseeded' => $unseeded,
                'seeded' => $seeded,
            ],
        ];

        $dir = storage_path('app/'.self::OUT_DIR);
        File::ensureDirectoryExists($dir);
        File::put(
            $dir.'/baseline.json',
            json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
        );

        $this->assertFileExists($dir.'/baseline.json');
        $this->assertCount(129, $catalog);
        $this->assertNotEmpty($unseeded);
        $this->assertNotEmpty($seeded);
    }

    // ── Invariants the baseline must satisfy ─────────────────────────────────

    public function test_production_today_grants_nothing_to_non_root_personas(): void
    {
        // Sanity check of the live shape: the three tables are empty, so every
        // non-root persona resolves to ZERO pages. This is the single most
        // important Phase 2F finding and must not regress silently.
        $this->assertSame(0, DB::table('role_page_defaults')->count());
        $this->assertSame(0, DB::table('department_page_defaults')->count());
        $this->assertSame(0, DB::table('user_page_access_overrides')->count());

        $ordinary = $this->makeUser('finance_manager');
        $this->assertSame([], $this->resolver()->allowedKeys($ordinary));

        $dept = $this->makeDepartment('empty-tables');
        $leader = $this->makeUser('operations_manager');
        $this->makeMember($leader, $dept);
        $this->makeLeader($leader, $dept);
        $this->assertSame([], $this->resolver()->allowedKeys($leader));
    }

    public function test_root_pair_is_unrestricted_with_empty_tables(): void
    {
        $catalogSize = count(PageAccessCatalog::all());

        $this->assertCount($catalogSize, $this->resolver()->allowedKeys($this->makeUser('super_admin')));
        $this->assertCount($catalogSize, $this->resolver()->allowedKeys($this->makeUser('tech_admin')));
    }

    public function test_manager_role_slug_alone_never_produces_leader_pages(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $dept = $this->makeDepartment('slug-proof');
        app(DepartmentPageDefaultService::class)
            ->replaceDefaults($dept, DepartmentPageAudience::Leader, [self::LEADER_PAGE], $superAdmin);

        // Every "manager"-shaped slug, with no leader_id assignment anywhere.
        foreach (['operations_manager', 'programs_manager', 'department_manager', 'ai_manager', 'finance_manager'] as $role) {
            $u = $this->makeUser($role);
            $this->assertNotContains(
                self::LEADER_PAGE,
                $this->resolver()->allowedKeys($u),
                "Role slug [{$role}] must not imply department leadership."
            );
        }
    }

    public function test_canonical_leader_gets_member_plus_leader_and_member_does_not(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $dept = $this->makeDepartment('leader-proof');
        $svc = app(DepartmentPageDefaultService::class);
        $svc->replaceDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE_2], $superAdmin);
        $svc->replaceDefaults($dept, DepartmentPageAudience::Leader, [self::LEADER_PAGE], $superAdmin);

        $member = $this->makeUser('operations_manager');
        $this->makeMember($member, $dept);

        $leader = $this->makeUser('operations_manager');
        $this->makeMember($leader, $dept);
        $this->makeLeader($leader, $dept);

        $memberKeys = $this->resolver()->allowedKeys($member);
        $leaderKeys = $this->resolver()->allowedKeys($leader);

        $this->assertContains(self::MEMBER_PAGE_2, $memberKeys);
        $this->assertNotContains(self::LEADER_PAGE, $memberKeys);

        $this->assertContains(self::MEMBER_PAGE_2, $leaderKeys, 'A leader must also inherit member defaults.');
        $this->assertContains(self::LEADER_PAGE, $leaderKeys);
    }

    public function test_ai_department_leadership_never_grants_the_technical_ai_platform(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $aiDept = $this->makeDepartment('ai-separation-proof');
        $svc = app(DepartmentPageDefaultService::class);
        $svc->replaceDefaults($aiDept, DepartmentPageAudience::Member, [self::MEMBER_PAGE_2], $superAdmin);
        $svc->replaceDefaults($aiDept, DepartmentPageAudience::Leader, [self::LEADER_PAGE], $superAdmin);

        $aiManager = $this->makeUser('ai_manager');
        $this->makeMember($aiManager, $aiDept);
        $this->makeLeader($aiManager, $aiDept);

        $keys = $this->resolver()->allowedKeys($aiManager);

        // Leads a real department…
        $this->assertContains(self::LEADER_PAGE, $keys);

        // …but every technical_system capability stays out of reach.
        foreach (PageAccessCatalog::all() as $entry) {
            if ($entry->category->value === 'technical_system') {
                $this->assertNotContains(
                    $entry->key,
                    $keys,
                    "ai_manager must not reach technical AI Platform page [{$entry->key}]."
                );
            }
        }
    }

    public function test_no_ordinary_persona_can_reach_a_system_protected_page(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $dept = $this->makeDepartment('protected-proof');

        $protectedKeys = array_values(array_map(
            fn (PageAccessEntry $e) => $e->key,
            array_filter(PageAccessCatalog::all(), fn (PageAccessEntry $e) => $e->isProtected())
        ));

        $this->assertCount(8, $protectedKeys);

        foreach (['admin', 'executive_admin', 'operations_manager', 'ai_manager', 'instructor', 'student'] as $role) {
            $u = $this->makeUser($role);
            $this->makeMember($u, $dept);
            $this->makeLeader($u, $dept);

            $keys = $this->resolver()->allowedKeys($u);
            foreach ($protectedKeys as $protected) {
                $this->assertNotContains($protected, $keys, "[{$role}] must not reach protected page [{$protected}].");
            }
        }

        // Root remains able to reach them — protection is not "deny everyone".
        $this->assertContains($protectedKeys[0], $this->resolver()->allowedKeys($superAdmin));
    }

    public function test_user_overrides_change_the_outcome_as_expected(): void
    {
        $superAdmin = $this->makeUser('super_admin');
        $roleSvc = app(RolePageDefaultService::class);
        $role = Role::firstOrCreate(['name' => 'volunteer'], ['display_name' => 'volunteer', 'is_system' => false]);
        $roleSvc->replaceDefaults($role, [self::MEMBER_PAGE], $superAdmin);

        $plain = $this->makeUser('volunteer');
        $this->assertContains(self::MEMBER_PAGE, $this->resolver()->allowedKeys($plain));

        $denied = $this->makeUser('volunteer');
        app(UserPageAccessOverrideService::class)
            ->setOverride($superAdmin, $denied, self::MEMBER_PAGE, UserPageAccessState::Deny);
        $this->assertNotContains(self::MEMBER_PAGE, $this->resolver()->allowedKeys($denied));

        $allowed = $this->makeUser('volunteer');
        app(UserPageAccessOverrideService::class)
            ->setOverride($superAdmin, $allowed, self::ROLE_PAGE_FINANCE, UserPageAccessState::Allow);
        $this->assertContains(self::ROLE_PAGE_FINANCE, $this->resolver()->allowedKeys($allowed));
    }

    public function test_the_generator_writes_no_rows_of_its_own_outside_its_fixtures(): void
    {
        // The resolver itself must stay read-only even while the harness runs.
        $u = $this->makeUser('instructor');

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)\s/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $this->resolver()->resolveAll($u);

        $this->assertSame([], $writes);
    }
}
