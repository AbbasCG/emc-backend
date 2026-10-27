<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentPageDefault;
use App\Models\Role;
use App\Models\RolePageDefault;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use App\Services\DepartmentPageDefaultService;
use App\Services\EffectivePageAccessService;
use App\Services\RolePageDefaultService;
use App\Support\PageAccess\DepartmentDefaultMap;
use App\Support\PageAccess\DepartmentPageAudience;
use App\Support\PageAccess\EffectivePageAccess;
use App\Support\PageAccess\PageAccessCatalog;
use App\Support\PageAccess\PageAccessEntry;
use App\Support\PageAccess\RoleDefaultMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Phase 2G.4A — DEPARTMENT DEFAULT SIMULATION.
 *
 * Emits a baseline in the Phase 2F shape so the compatibility impact of the
 * proposed Department Defaults can be measured WITHOUT writing a single row to
 * any persistent database. Everything happens in in-memory SQLite.
 *
 * STARTING STATE is the CURRENT effective state, not an empty one:
 *   - the 190 canonical Role Defaults (RoleDefaultMap: the 163 verified in
 *     Phase 2G.4A §2 to match the live role_page_defaults table exactly — 163
 *     persisted, 0 missing, 0 unexpected — plus the 16 operations.impact_points
 *     role defaults, the 2 ai_expert_applications defaults, the 4
 *     finance.expenses / finance.revenues defaults and the 2 ai_trainer_applications defaults added afterwards), and
 *   - the 6 catalog-declared Authenticated Baseline capabilities, which are
 *     metadata and need no rows at all.
 *
 * PERSONA/DEPARTMENT MODELLING: the Phase 2F matrix builds one synthetic
 * department per department-capable role. Here each of those is given a REAL
 * canonical slug so the mapping can actually resolve. Which slug a persona
 * receives cannot skew the result, because the mapping is uniform — every one
 * of the 14 departments carries exactly the same LEADER set and an empty
 * MEMBER set. A test below asserts that uniformity, so this remains true.
 */
class DepartmentDefaultSimulationTest extends TestCase
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

    /** Each department-capable persona gets a real canonical slug. */
    private const PERSONA_DEPARTMENT = [
        'operations_manager' => 'operations',
        'programs_manager' => 'programs-tracks',
        'department_manager' => 'technology',
        'ai_manager' => 'ai-transformation',
        'strategy_planning_manager' => 'strategy-planning',
        'quality_manager' => 'quality-governance',
        'volunteer' => 'community-health',
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

    /** The CURRENT persisted state: the 190 canonical Role Defaults. */
    private function applyRoleDefaults(User $actor): int
    {
        $svc = app(RolePageDefaultService::class);
        $written = 0;

        foreach (RoleDefaultMap::all() as $roleName => $keys) {
            $role = Role::firstOrCreate(['name' => $roleName], ['display_name' => $roleName, 'is_system' => false]);
            $svc->replaceDefaults($role, $keys, $actor);
            $written += count($keys);
        }

        return $written;
    }

    /** The PROPOSED Department Defaults, through the owning service. */
    private function applyDepartmentDefaults(User $actor): int
    {
        $svc = app(DepartmentPageDefaultService::class);
        $written = 0;

        foreach (DepartmentDefaultMap::all() as $slug => $audiences) {
            $department = Department::where('slug', $slug)->first() ?? $this->makeDepartment($slug);

            foreach ($audiences as $audienceValue => $keys) {
                if ($keys === []) {
                    continue;
                }

                $svc->replaceDefaults($department, DepartmentPageAudience::from($audienceValue), $keys, $actor);
                $written += count($keys);
            }
        }

        return $written;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildPersonas(string $note): array
    {
        $personas = [];

        foreach (self::ROLES as $role) {
            $user = $this->makeUser($role);
            $personas[$role.'__none'] = [
                'role' => $role, 'context' => 'none', 'note' => $note,
            ] + $this->summarize($this->resolver()->resolveAll($user));
        }

        foreach (self::DEPARTMENT_ROLES as $role) {
            $slug = self::PERSONA_DEPARTMENT[$role];
            $dept = Department::where('slug', $slug)->first() ?? $this->makeDepartment($slug);

            $member = $this->makeUser($role);
            $this->makeMember($member, $dept);
            $personas[$role.'__member'] = [
                'role' => $role, 'context' => 'member', 'note' => "member of [{$slug}]",
            ] + $this->summarize($this->resolver()->resolveAll($member));

            $leader = $this->makeUser($role);
            $this->makeMember($leader, $dept);
            $dept->forceFill(['leader_id' => $leader->id])->save();
            $personas[$role.'__leader'] = [
                'role' => $role, 'context' => 'leader', 'note' => "canonical leader of [{$slug}]",
            ] + $this->summarize($this->resolver()->resolveAll($leader));
        }

        return $personas;
    }

    /** @return array<int, array<string, mixed>> */
    private function catalogShape(): array
    {
        return array_map(static fn (PageAccessEntry $e) => [
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
    }

    public function test_it_emits_the_simulated_department_default_baseline(): void
    {
        $actor = $this->makeUser('super_admin');

        $roleRows = $this->applyRoleDefaults($actor);
        $this->assertSame(190, $roleRows);
        $this->assertSame(190, RolePageDefault::query()->count());

        $deptRows = $this->applyDepartmentDefaults($actor);
        $this->assertSame(DepartmentDefaultMap::pairCount(), $deptRows);
        $this->assertSame(28, DepartmentPageDefault::query()->count());

        $personas = $this->buildPersonas('role defaults + proposed department defaults');

        $dir = storage_path('app/'.self::OUT_DIR);
        File::ensureDirectoryExists($dir);
        File::put(
            $dir.'/baseline-department-simulated.json',
            json_encode([
                'generated_by' => 'tests/Feature/DepartmentDefaultSimulationTest.php',
                'role_pairs' => $roleRows,
                'department_pairs' => $deptRows,
                'catalog_count' => count(PageAccessCatalog::all()),
                'catalog' => $this->catalogShape(),
                'scenarios' => ['department_simulated' => $personas],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
        );

        $this->assertFileExists($dir.'/baseline-department-simulated.json');
        $this->assertCount(37, $personas);
    }

    public function test_the_mapping_is_uniform_across_every_department(): void
    {
        $sets = [];

        foreach (DepartmentDefaultMap::all() as $slug => $audiences) {
            $sets[] = json_encode([$audiences['member'], $audiences['leader']]);
        }

        $this->assertCount(
            1, array_unique($sets),
            'Persona-to-department assignment can only be neutral while every department maps identically.'
        );
    }

    public function test_the_simulation_grants_the_leader_pages_only_to_canonical_leaders(): void
    {
        $actor = $this->makeUser('super_admin');
        $this->applyRoleDefaults($actor);
        $this->applyDepartmentDefaults($actor);

        $personas = $this->buildPersonas('assertion run');

        foreach ($personas as $id => $persona) {
            $allowed = $persona['allowed'];

            foreach (DepartmentDefaultMap::LEADER_OWNED as $key) {
                if ($persona['context'] === 'leader') {
                    $this->assertContains($key, $allowed, "[{$id}] is a canonical leader and must receive [{$key}].");
                    $this->assertSame('department_leader', $persona['provenance'][$key]);

                    continue;
                }

                if (in_array($persona['role'], RoleDefaultMap::ROOT_ROLES, true)) {
                    continue; // root reaches everything by root authority
                }

                $this->assertNotContains($key, $allowed, "[{$id}] is not a canonical leader but received [{$key}].");
            }
        }
    }

    public function test_the_simulation_writes_no_user_overrides_and_keeps_role_defaults_intact(): void
    {
        $actor = $this->makeUser('super_admin');
        $this->applyRoleDefaults($actor);
        $this->applyDepartmentDefaults($actor);

        $this->assertSame(190, RolePageDefault::query()->count());
        $this->assertSame(28, DepartmentPageDefault::query()->count());
        $this->assertSame(0, DB::table('user_page_access_overrides')->count());
    }

    public function test_no_persona_gains_a_protected_page_from_the_department_defaults(): void
    {
        $actor = $this->makeUser('super_admin');
        $this->applyRoleDefaults($actor);
        $this->applyDepartmentDefaults($actor);

        $protected = array_map(fn (PageAccessEntry $e) => $e->key, array_filter(
            PageAccessCatalog::all(), fn (PageAccessEntry $e) => $e->isProtected()
        ));

        foreach ($this->buildPersonas('protection run') as $id => $persona) {
            if (in_array($persona['role'], RoleDefaultMap::ROOT_ROLES, true)) {
                continue;
            }

            foreach ($protected as $p) {
                $this->assertNotContains($p, $persona['allowed'], "[{$id}] gained protected [{$p}].");
            }
        }
    }
}
