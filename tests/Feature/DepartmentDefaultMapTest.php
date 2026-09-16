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
use App\Support\PageAccess\DepartmentDefaultMap;
use App\Support\PageAccess\DepartmentPageAudience;
use App\Support\PageAccess\PageAccessCatalog;
use App\Support\PageAccess\PageAccessEntry;
use App\Support\PageAccess\PageAccessSource;
use App\Support\PageAccess\RoleDefaultMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 2G.4A — CANONICAL DEPARTMENT DEFAULT MAPPING + DRY-RUN.
 *
 * Every write here happens in an isolated in-memory SQLite database
 * (RefreshDatabase). Nothing touches the persistent local database, and the
 * dry-run command is asserted to write zero rows even when invoked directly.
 */
class DepartmentDefaultMapTest extends TestCase
{
    use RefreshDatabase;

    private const SLUGS = [
        'advisors', 'ai-transformation', 'community-health', 'digital-ambassadors',
        'executive-management', 'finance', 'human-resources', 'marketing-media',
        'operations', 'partnerships', 'programs-tracks', 'quality-governance',
        'strategy-planning', 'technology',
    ];

    private function makeDepartment(string $slug, string $status = 'active'): Department
    {
        return Department::create([
            'name' => $slug, 'name_ar' => $slug, 'slug' => $slug,
            'status' => $status, 'is_active' => $status === 'active',
        ]);
    }

    /** Create every canonical department so the plan resolves fully. */
    private function seedDepartments(): void
    {
        foreach (self::SLUGS as $slug) {
            $this->makeDepartment($slug);
        }
    }

    private function makeUser(string $role): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role, 'is_system' => false]);

        return User::factory()->create(['role' => $role, 'is_active' => true]);
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

    private function service(): DepartmentPageDefaultService
    {
        return app(DepartmentPageDefaultService::class);
    }

    // ── 30. Mapping invariants ───────────────────────────────────────────────

    public function test_department_identity_is_the_slug_and_never_a_numeric_id(): void
    {
        foreach (DepartmentDefaultMap::departments() as $key) {
            $this->assertIsString($key, 'Department keys must be slugs.');
            $this->assertFalse(
                ctype_digit($key),
                "[{$key}] looks like a numeric id — department identity must be the slug."
            );
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9-]*$/', $key,
                "[{$key}] is not a slug-shaped canonical identity."
            );
        }
    }

    public function test_the_map_source_file_hardcodes_no_numeric_database_id(): void
    {
        $source = file_get_contents(app_path('Support/PageAccess/DepartmentDefaultMap.php'));
        $mapBody = substr($source, (int) strpos($source, 'private const MAP'));
        $mapBody = substr($mapBody, 0, (int) strpos($mapBody, '];'));

        $this->assertDoesNotMatchRegularExpression(
            "/=>\s*\d+/", $mapBody,
            'The mapping must not key or value any numeric database id.'
        );
    }

    public function test_every_mapped_slug_exists_as_a_real_department(): void
    {
        $this->seedDepartments();

        foreach (DepartmentDefaultMap::departments() as $slug) {
            $this->assertNotNull(
                Department::where('slug', $slug)->first(),
                "Mapped slug [{$slug}] has no department row."
            );
        }
    }

    public function test_every_mapped_page_key_exists_in_the_catalog(): void
    {
        foreach (DepartmentDefaultMap::all() as $slug => $audiences) {
            foreach ($audiences as $audience => $keys) {
                foreach ($keys as $key) {
                    $this->assertNotNull(
                        PageAccessCatalog::find($key),
                        "[{$slug}/{$audience}] maps unknown key [{$key}]."
                    );
                }
            }
        }
    }

    public function test_the_map_never_contains_a_system_protected_page(): void
    {
        $protected = array_map(fn (PageAccessEntry $e) => $e->key, array_filter(
            PageAccessCatalog::all(), fn (PageAccessEntry $e) => $e->isProtected()
        ));

        $this->assertNotEmpty($protected, 'Guard would be vacuous with no protected entries.');

        foreach (DepartmentDefaultMap::all() as $slug => $audiences) {
            foreach ($audiences as $audience => $keys) {
                foreach ($protected as $p) {
                    $this->assertNotContains($p, $keys, "[{$slug}/{$audience}] maps protected [{$p}].");
                }
            }
        }
    }

    public function test_every_audience_key_is_a_valid_department_page_audience(): void
    {
        $valid = array_map(fn (DepartmentPageAudience $a) => $a->value, DepartmentPageAudience::cases());

        foreach (DepartmentDefaultMap::all() as $slug => $audiences) {
            foreach (array_keys($audiences) as $audience) {
                $this->assertContains($audience, $valid, "[{$slug}] uses unknown audience [{$audience}].");
            }
        }
    }

    public function test_every_mapped_pair_is_eligible_for_its_audience(): void
    {
        foreach (DepartmentDefaultMap::all() as $slug => $audiences) {
            foreach ($audiences as $audienceValue => $keys) {
                $audience = DepartmentPageAudience::from($audienceValue);

                foreach ($keys as $key) {
                    $this->assertTrue(
                        $this->service()->isEligible(PageAccessCatalog::find($key), $audience),
                        "[{$slug}] maps [{$key}] which is ineligible for [{$audienceValue}]."
                    );
                }
            }
        }
    }

    public function test_there_is_no_duplicate_department_audience_page_tuple(): void
    {
        $seen = [];

        foreach (DepartmentDefaultMap::all() as $slug => $audiences) {
            foreach ($audiences as $audience => $keys) {
                foreach ($keys as $key) {
                    $tuple = "{$slug}|{$audience}|{$key}";
                    $this->assertArrayNotHasKey($tuple, $seen, "Duplicate tuple [{$tuple}].");
                    $seen[$tuple] = true;
                }
            }
        }

        $this->assertCount(DepartmentDefaultMap::pairCount(), $seen);
    }

    public function test_leader_entries_never_restate_a_member_key(): void
    {
        foreach (DepartmentDefaultMap::all() as $slug => $audiences) {
            $overlap = array_intersect($audiences['member'], $audiences['leader']);

            $this->assertSame(
                [], array_values($overlap),
                "[{$slug}] duplicates member keys into leader — inheritance is the service's job."
            );
        }
    }

    public function test_the_deferred_capabilities_are_never_mapped(): void
    {
        $withheld = array_merge(
            DepartmentDefaultMap::DEFERRED_PRODUCT_DECISION,
            DepartmentDefaultMap::INTENTIONAL_SYSTEM_PROTECTED,
        );

        foreach (DepartmentDefaultMap::all() as $slug => $audiences) {
            foreach ($audiences as $audience => $keys) {
                foreach ($withheld as $w) {
                    $this->assertNotContains($w, $keys, "[{$slug}/{$audience}] maps withheld [{$w}].");
                }
            }
        }
    }

    public function test_an_inactive_department_contributes_nothing(): void
    {
        $active = $this->makeDepartment('technology');
        $archived = $this->makeDepartment('operations', 'archived');

        $svc = $this->service();
        $actor = $this->makeUser('super_admin');

        foreach ([$active, $archived] as $dept) {
            $svc->replaceDefaults($dept, DepartmentPageAudience::Leader, DepartmentDefaultMap::LEADER_OWNED, $actor);
        }

        $leaderOfBoth = $this->makeUser('department_manager');
        $active->forceFill(['leader_id' => $leaderOfBoth->id])->save();
        $archived->forceFill(['leader_id' => $leaderOfBoth->id])->save();

        $resolved = app(EffectivePageAccessService::class)->resolveAll($leaderOfBoth);

        // Stored for both, but only the ACTIVE department may grant.
        foreach (DepartmentDefaultMap::LEADER_OWNED as $key) {
            $this->assertTrue($resolved[$key]->allowed, "[{$key}] should come from the active department.");
            $this->assertSame(PageAccessSource::DepartmentLeader, $resolved[$key]->primarySource);
        }

        // Prove the archived one really is inert: remove the active grant and
        // the same user must lose the pages entirely.
        $svc->replaceDefaults($active, DepartmentPageAudience::Leader, [], $actor);
        $after = app(EffectivePageAccessService::class)->resolveAll($leaderOfBoth->fresh());

        foreach (DepartmentDefaultMap::LEADER_OWNED as $key) {
            $this->assertFalse($after[$key]->allowed, "Archived department must not grant [{$key}].");
        }
    }

    public function test_leader_authority_is_never_inferred_from_a_role_slug(): void
    {
        $dept = $this->makeDepartment('technology');
        $this->service()->replaceDefaults(
            $dept, DepartmentPageAudience::Leader, DepartmentDefaultMap::LEADER_OWNED, $this->makeUser('super_admin'),
        );

        // Every "manager"-shaped slug, as a MEMBER of the department but not
        // its canonical leader. None may receive a leader default.
        foreach (['department_manager', 'operations_manager', 'ai_manager', 'programs_manager', 'section_lead'] as $role) {
            $user = $this->makeUser($role);
            $this->makeMember($user, $dept);

            $resolved = app(EffectivePageAccessService::class)->resolveAll($user);

            foreach (DepartmentDefaultMap::LEADER_OWNED as $key) {
                $this->assertFalse(
                    $resolved[$key]->allowed,
                    "[{$role}] gained leader page [{$key}] from a role slug."
                );
            }
        }

        // The SAME role becomes a real leader via departments.leader_id only.
        $leader = $this->makeUser('department_manager');
        $this->makeMember($leader, $dept);
        $dept->forceFill(['leader_id' => $leader->id])->save();

        $resolved = app(EffectivePageAccessService::class)->resolveAll($leader);

        foreach (DepartmentDefaultMap::LEADER_OWNED as $key) {
            $this->assertTrue($resolved[$key]->allowed, "Canonical leader must receive [{$key}].");
            $this->assertSame(PageAccessSource::DepartmentLeader, $resolved[$key]->primarySource);
        }
    }

    public function test_the_mapping_does_not_modify_role_defaults(): void
    {
        $this->seedDepartments();

        $role = Role::firstOrCreate(['name' => 'volunteer'], ['display_name' => 'volunteer', 'is_system' => false]);
        RolePageDefault::create(['role_id' => $role->id, 'page_key' => 'operations.meeting_lounge']);

        $before = RolePageDefault::query()->get(['role_id', 'page_key'])->toArray();

        $this->artisan('access:sync-department-defaults --dry-run')->assertExitCode(0);

        $this->assertSame($before, RolePageDefault::query()->get(['role_id', 'page_key'])->toArray());
        $this->assertSame(1, RolePageDefault::query()->count());
    }

    public function test_the_member_audience_is_deliberately_empty(): void
    {
        $this->assertSame(0, DepartmentDefaultMap::pairCountFor(DepartmentPageAudience::Member));
        $this->assertSame(28, DepartmentDefaultMap::pairCountFor(DepartmentPageAudience::Leader));
        $this->assertSame(28, DepartmentDefaultMap::pairCount());
        $this->assertCount(14, DepartmentDefaultMap::departments());
    }
}
