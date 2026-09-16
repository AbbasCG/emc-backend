<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Role;
use App\Models\RolePageDefault;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use App\Services\EffectivePageAccessService;
use App\Services\RolePageDefaultService;
use App\Support\PageAccess\EffectivePageAccess;
use App\Support\PageAccess\PageAccessCatalog;
use App\Support\PageAccess\PageAccessEntry;
use App\Support\PageAccess\RoleDefaultMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Phase 2G.3A — ROLE DEFAULT SIMULATION.
 *
 * Applies the canonical RoleDefaultMap inside an ISOLATED in-memory SQLite
 * database (RefreshDatabase) and emits a baseline in the same shape as the
 * Phase 2F generator, so the compatibility impact can be measured WITHOUT
 * writing a single row to any persistent database.
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION — the simulation measures page exposure.
 *
 * Nothing here runs `access:sync-role-defaults --apply`; the mapping is applied
 * through RolePageDefaultService, which owns the eligibility rule, so a
 * protected key could not be seeded even if the map contained one.
 */
class RoleDefaultSimulationTest extends TestCase
{
    use RefreshDatabase;

    private const OUT_DIR = 'access-diff';

    /** Same persona matrix as the Phase 2F generator, so the two are comparable. */
    private const ROLES = [
        'super_admin', 'tech_admin', 'admin', 'executive_admin',
        'operations_manager', 'programs_manager', 'finance_manager', 'quality_manager',
        'hr_manager', 'marketing_manager', 'partnerships_manager', 'community_manager',
        'support_agent', 'instructor', 'student', 'partner', 'volunteer',
        'department_manager', 'section_lead', 'ai_manager',
        'strategy_planning_manager', 'digital_ambassadors_manager', 'advisors_manager',
    ];

    private const DEPARTMENT_ROLES = [
        'operations_manager', 'programs_manager', 'department_manager', 'ai_manager',
        'strategy_planning_manager', 'quality_manager', 'volunteer',
    ];

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

    private function makeLeader(User $user, Department $dept): void
    {
        $dept->forceFill(['leader_id' => $user->id])->save();
    }

    /** @param array<string, EffectivePageAccess> $resolved */
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

    /** Apply the canonical map through the owning service. Returns rows written. */
    private function applyMap(): int
    {
        $svc = app(RolePageDefaultService::class);
        $actor = $this->makeUser('super_admin');
        $written = 0;

        foreach (RoleDefaultMap::all() as $roleName => $keys) {
            $role = Role::firstOrCreate(['name' => $roleName], ['display_name' => $roleName, 'is_system' => false]);
            $svc->replaceDefaults($role, $keys, $actor);
            $written += count($keys);
        }

        return $written;
    }

    public function test_it_emits_the_simulated_role_default_baseline(): void
    {
        $written = $this->applyMap();

        $this->assertSame(RoleDefaultMap::pairCount(), $written);
        $this->assertSame($written, RolePageDefault::query()->count(), 'Every mapped pair must have stored.');

        $personas = [];

        foreach (self::ROLES as $role) {
            $user = $this->makeUser($role);
            $personas[$role.'__none'] = [
                'role' => $role, 'context' => 'none', 'note' => 'role defaults applied (simulated)',
            ] + $this->summarize($this->resolver()->resolveAll($user));
        }

        foreach (self::DEPARTMENT_ROLES as $role) {
            $dept = $this->makeDepartment('sim-'.$role);

            $member = $this->makeUser($role);
            $this->makeMember($member, $dept);
            $personas[$role.'__member'] = [
                'role' => $role, 'context' => 'member', 'note' => 'member; no department defaults stored',
            ] + $this->summarize($this->resolver()->resolveAll($member));

            $leader = $this->makeUser($role);
            $this->makeMember($leader, $dept);
            $this->makeLeader($leader, $dept);
            $personas[$role.'__leader'] = [
                'role' => $role, 'context' => 'leader', 'note' => 'canonical leader; no department defaults stored',
            ] + $this->summarize($this->resolver()->resolveAll($leader));
        }

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
            'authenticated_baseline' => $e->authenticatedBaseline,
        ], PageAccessCatalog::all());

        $dir = storage_path('app/'.self::OUT_DIR);
        File::ensureDirectoryExists($dir);
        File::put(
            $dir.'/baseline-simulated.json',
            json_encode([
                'generated_by' => 'tests/Feature/RoleDefaultSimulationTest.php',
                'proposed_pairs' => RoleDefaultMap::pairCount(),
                'catalog_count' => count($catalog),
                'catalog' => $catalog,
                'scenarios' => ['simulated' => $personas],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
        );

        $this->assertFileExists($dir.'/baseline-simulated.json');
        $this->assertCount(37, $personas);
    }

    // ── Safety invariants of the proposed map ────────────────────────────────

    public function test_the_map_excludes_every_protected_capability(): void
    {
        $protected = array_map(fn (PageAccessEntry $e) => $e->key, array_filter(
            PageAccessCatalog::all(), fn (PageAccessEntry $e) => $e->isProtected()
        ));

        foreach (RoleDefaultMap::all() as $role => $keys) {
            foreach ($protected as $p) {
                $this->assertNotContains($p, $keys, "[{$role}] must not map protected [{$p}].");
            }
        }
    }

    public function test_the_map_excludes_every_authenticated_baseline_capability(): void
    {
        $baseline = array_map(fn (PageAccessEntry $e) => $e->key, array_filter(
            PageAccessCatalog::all(), fn (PageAccessEntry $e) => $e->authenticatedBaseline
        ));

        $this->assertCount(6, $baseline);

        foreach (RoleDefaultMap::all() as $role => $keys) {
            foreach ($baseline as $b) {
                $this->assertNotContains($b, $keys, "[{$role}] must not duplicate baseline [{$b}].");
            }
        }
    }

    public function test_the_map_excludes_the_deferred_and_leader_owned_capabilities(): void
    {
        foreach (RoleDefaultMap::all() as $role => $keys) {
            foreach (RoleDefaultMap::DEFERRED_PRODUCT_DECISION as $d) {
                $this->assertNotContains($d, $keys, "[{$role}] must not map deferred [{$d}].");
            }
            foreach (RoleDefaultMap::LEADER_OWNED as $l) {
                $this->assertNotContains($l, $keys, "[{$role}] must not map leader-owned [{$l}].");
            }
        }
    }

    public function test_the_map_never_seeds_root_roles(): void
    {
        foreach (RoleDefaultMap::ROOT_ROLES as $root) {
            $this->assertArrayNotHasKey($root, RoleDefaultMap::all(), "[{$root}] must not be seeded.");
        }
    }

    public function test_every_mapped_key_exists_in_the_catalog(): void
    {
        foreach (RoleDefaultMap::all() as $role => $keys) {
            foreach ($keys as $key) {
                $this->assertNotNull(PageAccessCatalog::find($key), "[{$role}] maps unknown key [{$key}].");
            }
        }
    }

    public function test_root_authority_is_unaffected_by_the_simulation(): void
    {
        $this->applyMap();

        $catalogSize = count(PageAccessCatalog::all());

        foreach (RoleDefaultMap::ROOT_ROLES as $root) {
            $this->assertCount($catalogSize, $this->resolver()->allowedKeys($this->makeUser($root)));
        }
    }

    public function test_no_persona_gains_a_protected_capability_after_seeding(): void
    {
        $this->applyMap();

        $protected = array_map(fn (PageAccessEntry $e) => $e->key, array_filter(
            PageAccessCatalog::all(), fn (PageAccessEntry $e) => $e->isProtected()
        ));

        foreach (['admin', 'executive_admin', 'operations_manager', 'ai_manager', 'volunteer', 'student'] as $role) {
            $keys = $this->resolver()->allowedKeys($this->makeUser($role));
            foreach ($protected as $p) {
                $this->assertNotContains($p, $keys, "[{$role}] gained protected [{$p}] after seeding.");
            }
        }
    }

    public function test_ai_manager_gains_no_technical_platform_page_after_seeding(): void
    {
        $this->applyMap();

        $keys = $this->resolver()->allowedKeys($this->makeUser('ai_manager'));

        foreach (PageAccessCatalog::all() as $entry) {
            if ($entry->category->value === 'technical_system') {
                $this->assertNotContains($entry->key, $keys, "ai_manager gained [{$entry->key}].");
            }
        }
    }

    public function test_a_role_slug_still_does_not_grant_leader_pages_after_seeding(): void
    {
        $this->applyMap();

        foreach (['operations_manager', 'programs_manager', 'department_manager', 'ai_manager'] as $role) {
            $keys = $this->resolver()->allowedKeys($this->makeUser($role));

            foreach (RoleDefaultMap::LEADER_OWNED as $leaderKey) {
                $this->assertNotContains($leaderKey, $keys, "[{$role}] must not gain [{$leaderKey}] from a slug.");
            }
        }
    }

    public function test_the_simulation_touches_only_role_page_defaults(): void
    {
        $this->applyMap();

        $this->assertSame(0, DB::table('department_page_defaults')->count());
        $this->assertSame(0, DB::table('user_page_access_overrides')->count());
        $this->assertGreaterThan(0, DB::table('role_page_defaults')->count());
    }
}
