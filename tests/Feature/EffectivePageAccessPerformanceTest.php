<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentPageDefault;
use App\Models\Role;
use App\Models\RolePageDefault;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use App\Services\EffectivePageAccessService;
use App\Support\PageAccess\DepartmentDefaultMap;
use App\Support\PageAccess\DepartmentPageAudience;
use App\Support\PageAccess\PageAccessCatalog;
use App\Support\PageAccess\RoleDefaultMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase G — RESOLVER QUERY-COST REGRESSION.
 *
 * The resolver walks a 129-entry catalog and several access layers. Two N+1
 * shapes could creep in: one per catalog page, and one per department a user
 * belongs to or leads. Both are pinned here with hard ceilings, because a user
 * leading several departments is ordinary in this platform rather than
 * exceptional.
 *
 * These are COST assertions only. Correctness lives in EffectivePageAccessTest.
 */
class EffectivePageAccessPerformanceTest extends TestCase
{
    use RefreshDatabase;

    /** Generous enough not to be brittle, tight enough to catch a real N+1. */
    private const CEILING = 12;

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

    private function countQueries(callable $fn): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $fn();

        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    public function test_resolving_the_whole_catalog_does_not_scale_with_page_count(): void
    {
        $user = $this->makeUser('volunteer');
        $svc = app(EffectivePageAccessService::class);

        $queries = $this->countQueries(fn () => $svc->resolveAll($user));

        $this->assertGreaterThan(50, count(PageAccessCatalog::all()), 'Guard would be vacuous on a tiny catalog.');
        $this->assertLessThanOrEqual(
            self::CEILING, $queries,
            "Resolving {$this->catalogSize()} pages cost {$queries} queries - an N+1 over the catalog."
        );
    }

    public function test_query_cost_is_flat_as_a_user_leads_more_departments(): void
    {
        $svc = app(EffectivePageAccessService::class);
        $defaults = app(\App\Services\DepartmentPageDefaultService::class);

        // team_profiles.user_id is UNIQUE, so a user has at most one membership
        // profile. Leadership is NOT limited that way: departments.leader_id is
        // a plain column, and a real user in this database leads seven
        // departments. That is the shape this test scales.
        $single = $this->makeUser('department_manager');
        $first = $this->makeDepartment('dept-single');
        $this->makeMember($single, $first);
        $first->forceFill(['leader_id' => $single->id])->save();
        $defaults->replaceDefaults($first, DepartmentPageAudience::Leader, DepartmentDefaultMap::LEADER_OWNED, $single);

        $oneDepartment = $this->countQueries(fn () => $svc->resolveAll($single->fresh()));

        // Same user, now leading ten more departments.
        for ($i = 0; $i < 10; $i++) {
            $dept = $this->makeDepartment('dept-many-'.$i);
            $dept->forceFill(['leader_id' => $single->id])->save();
            $defaults->replaceDefaults($dept, DepartmentPageAudience::Leader, DepartmentDefaultMap::LEADER_OWNED, $single);
        }

        $elevenDepartments = $this->countQueries(fn () => $svc->resolveAll($single->fresh()));

        $this->assertSame(
            $oneDepartment, $elevenDepartments,
            "Leading 11 departments cost {$elevenDepartments} queries vs {$oneDepartment} for one - an N+1 over departments."
        );
        $this->assertLessThanOrEqual(self::CEILING, $elevenDepartments);

        // The extra departments genuinely participated, so the assertion above
        // is not vacuous.
        $this->assertSame(11, Department::query()->where('leader_id', $single->id)->count());
    }

    public function test_the_manifest_endpoint_is_a_single_efficient_request(): void
    {
        $user = $this->makeUser('volunteer');
        $dept = $this->makeDepartment('technology');
        $this->makeMember($user, $dept);

        $role = Role::firstOrCreate(['name' => 'volunteer'], ['display_name' => 'volunteer', 'is_system' => false]);
        foreach (RoleDefaultMap::forRole('volunteer') ?? [] as $key) {
            RolePageDefault::create(['role_id' => $role->id, 'page_key' => $key]);
        }

        Sanctum::actingAs($user);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson('/api/auth/me/page-access');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();

        // The whole manifest in one request, including auth resolution.
        $this->assertLessThanOrEqual(
            self::CEILING + 6, $queries,
            "The manifest request cost {$queries} queries."
        );

        $this->assertCount(count(PageAccessCatalog::all()), $response->json('data.pages'));
        $this->assertIsArray($response->json('data.allowed_keys'));
    }

    public function test_a_root_user_resolving_every_page_stays_cheap(): void
    {
        $root = $this->makeUser('super_admin');
        $svc = app(EffectivePageAccessService::class);

        $queries = $this->countQueries(fn () => $svc->resolveAll($root));

        $this->assertLessThanOrEqual(self::CEILING, $queries);
        $this->assertCount(
            count(PageAccessCatalog::all()),
            array_filter($svc->resolveAll($root), fn ($r) => $r->allowed),
        );
    }

    private function catalogSize(): int
    {
        return count(PageAccessCatalog::all());
    }
}
