<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Role;
use App\Models\RolePageDefault;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use App\Services\DepartmentPageDefaultService;
use App\Services\EffectivePageAccessService;
use App\Services\PermissionDelegationService;
use App\Services\UserPageAccessOverrideService;
use App\Support\PageAccess\DepartmentDefaultMap;
use App\Support\PageAccess\DepartmentPageAudience;
use App\Support\PageAccess\PageAccessCatalog;
use App\Support\PageAccess\PageAccessEntry;
use App\Support\PageAccess\PageAccessSource;
use App\Support\PageAccess\RoleDefaultMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * PHASE J — SECURITY MATRIX.
 *
 * Exercises the finished access system across representative personas and
 * asserts the invariants that must hold no matter which layers combine.
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION, and the final section proves it:
 * every page a persona can open still leaves permissions, delegation and
 * protected authority exactly where they were.
 */
class AccessControlSecurityMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const PERSONAS = [
        'super_admin', 'tech_admin', 'admin', 'executive_admin',
        'operations_manager', 'finance_manager', 'quality_manager', 'hr_manager',
        'marketing_manager', 'support_agent', 'partner', 'instructor',
        'student', 'volunteer',
    ];

    private const ROOT = ['super_admin', 'tech_admin'];

    private function makeUser(string $role): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role, 'is_system' => false]);

        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function makeDepartment(string $slug, string $status = 'active'): Department
    {
        return Department::create([
            'name' => $slug, 'name_ar' => $slug, 'slug' => $slug,
            'status' => $status, 'is_active' => $status === 'active',
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

    private function makeLeader(string $role, Department $dept): User
    {
        $user = $this->makeUser($role);
        $this->makeMember($user, $dept);
        $dept->forceFill(['leader_id' => $user->id])->save();

        return $user;
    }

    /** The full canonical configuration: 188 role pairs + 28 department pairs. */
    private function seedCanonical(): Department
    {
        $actor = $this->makeUser('super_admin');

        foreach (RoleDefaultMap::all() as $roleName => $keys) {
            $role = Role::firstOrCreate(['name' => $roleName], ['display_name' => $roleName, 'is_system' => false]);
            foreach ($keys as $key) {
                RolePageDefault::create(['role_id' => $role->id, 'page_key' => $key, 'created_by' => $actor->id]);
            }
        }

        $svc = app(DepartmentPageDefaultService::class);
        $first = null;

        foreach (DepartmentDefaultMap::all() as $slug => $audiences) {
            $dept = $this->makeDepartment($slug);
            $first ??= $dept;

            foreach ($audiences as $audienceValue => $keys) {
                if ($keys === []) {
                    continue;
                }
                $svc->replaceDefaults($dept, DepartmentPageAudience::from($audienceValue), $keys, $actor);
            }
        }

        return $first;
    }

    /** @return array<int, string> */
    private function protectedKeys(): array
    {
        return array_values(array_map(fn (PageAccessEntry $e) => $e->key, array_filter(
            PageAccessCatalog::all(), fn (PageAccessEntry $e) => $e->isProtected()
        )));
    }

    private function resolver(): EffectivePageAccessService
    {
        return app(EffectivePageAccessService::class);
    }

    // ── 1. SYSTEM_PROTECTED is unreachable through any mutable layer ─────────

    public function test_no_non_root_persona_reaches_a_protected_page_under_the_canonical_configuration(): void
    {
        $this->seedCanonical();
        $protected = $this->protectedKeys();
        $this->assertNotEmpty($protected);

        foreach (self::PERSONAS as $role) {
            if (in_array($role, self::ROOT, true)) {
                continue;
            }

            $allowed = $this->resolver()->allowedKeys($this->makeUser($role));

            foreach ($protected as $key) {
                $this->assertNotContains($key, $allowed, "[{$role}] reached protected [{$key}].");
            }
        }
    }

    public function test_a_protected_page_cannot_be_granted_by_any_mutable_layer(): void
    {
        $dept = $this->makeDepartment('technology');
        $actor = $this->makeUser('super_admin');
        $target = $this->makeUser('volunteer');
        $protected = $this->protectedKeys()[0];

        // Role default layer refuses it.
        try {
            app(\App\Services\RolePageDefaultService::class)->addDefaults(
                Role::firstOrCreate(['name' => 'volunteer'], ['display_name' => 'v', 'is_system' => false]),
                [$protected],
                $actor,
            );
            $this->fail('A role default accepted a SYSTEM_PROTECTED page.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        // Department layer refuses it, for both audiences.
        foreach (DepartmentPageAudience::cases() as $audience) {
            try {
                app(DepartmentPageDefaultService::class)->addDefaults($dept, $audience, [$protected], $actor);
                $this->fail("A {$audience->value} department default accepted a SYSTEM_PROTECTED page.");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        // User ALLOW override refuses it.
        try {
            app(UserPageAccessOverrideService::class)->replaceOverrides($actor, $target, [$protected], []);
            $this->fail('A user ALLOW override accepted a SYSTEM_PROTECTED page.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
    }

    // ── 2. Root authority ────────────────────────────────────────────────────

    public function test_root_reaches_every_page_and_a_user_deny_cannot_strip_it(): void
    {
        $this->seedCanonical();
        $catalogSize = count(PageAccessCatalog::all());
        $actor = $this->makeUser('super_admin');

        foreach (self::ROOT as $role) {
            $root = $this->makeUser($role);
            $this->assertCount($catalogSize, $this->resolver()->allowedKeys($root));

            $key = 'operations.meeting_lounge';
            app(UserPageAccessOverrideService::class)->replaceOverrides($actor, $root, [], [$key]);

            $resolved = $this->resolver()->resolveAll($root->fresh());

            $this->assertTrue(
                $resolved[$key]->allowed,
                "A user DENY stripped [{$key}] from [{$role}] - root authority must outrank it."
            );
            $this->assertSame(PageAccessSource::RootAuthority, $resolved[$key]->primarySource);
        }
    }

    public function test_no_non_root_persona_gains_the_full_catalog(): void
    {
        $this->seedCanonical();
        $catalogSize = count(PageAccessCatalog::all());

        foreach (self::PERSONAS as $role) {
            if (in_array($role, self::ROOT, true)) {
                continue;
            }

            $this->assertLessThan(
                $catalogSize,
                count($this->resolver()->allowedKeys($this->makeUser($role))),
                "[{$role}] resolved the entire catalog - only root may."
            );
        }
    }

    // ── 3. Department scoping ────────────────────────────────────────────────

    public function test_a_leader_receives_leader_pages_only_for_their_own_department(): void
    {
        $this->seedCanonical();

        $own = Department::where('slug', 'technology')->firstOrFail();
        $other = Department::where('slug', 'finance')->firstOrFail();

        $leader = $this->makeLeader('partner', $own);
        $otherLeader = $this->makeLeader('department_manager', $other);

        $resolved = $this->resolver()->resolveAll($leader);

        foreach (DepartmentDefaultMap::LEADER_OWNED as $key) {
            $this->assertTrue($resolved[$key]->allowed);
            $this->assertSame(PageAccessSource::DepartmentLeader, $resolved[$key]->primarySource);
        }

        // Removing the OTHER department's rows must not affect this leader,
        // proving the grant came from their own department.
        app(DepartmentPageDefaultService::class)->replaceDefaults(
            $other, DepartmentPageAudience::Leader, [], $otherLeader,
        );

        $after = $this->resolver()->resolveAll($leader->fresh());
        foreach (DepartmentDefaultMap::LEADER_OWNED as $key) {
            $this->assertTrue($after[$key]->allowed);
        }
    }

    public function test_a_role_slug_never_fakes_leadership(): void
    {
        $this->seedCanonical();
        $dept = Department::where('slug', 'technology')->firstOrFail();
        $this->makeLeader('department_manager', $dept);

        foreach (['operations_manager', 'quality_manager', 'hr_manager', 'finance_manager', 'marketing_manager'] as $role) {
            $manager = $this->makeUser($role);
            $this->makeMember($manager, $dept);

            $allowed = $this->resolver()->allowedKeys($manager);

            foreach (DepartmentDefaultMap::LEADER_OWNED as $key) {
                $this->assertNotContains($key, $allowed, "[{$role}] gained [{$key}] from a slug.");
            }
        }
    }

    public function test_an_archived_department_grants_nothing(): void
    {
        $actor = $this->makeUser('super_admin');
        $archived = $this->makeDepartment('operations', 'archived');

        app(DepartmentPageDefaultService::class)->replaceDefaults(
            $archived, DepartmentPageAudience::Leader, DepartmentDefaultMap::LEADER_OWNED, $actor,
        );

        $leader = $this->makeLeader('volunteer', $archived);
        $allowed = $this->resolver()->allowedKeys($leader);

        foreach (DepartmentDefaultMap::LEADER_OWNED as $key) {
            $this->assertNotContains($key, $allowed, "An archived department granted [{$key}].");
        }
    }

    // ── 4. User override precedence ──────────────────────────────────────────

    public function test_a_user_deny_outranks_role_and_department_grants(): void
    {
        $this->seedCanonical();
        $actor = $this->makeUser('super_admin');
        $dept = Department::where('slug', 'technology')->firstOrFail();

        $leader = $this->makeLeader('department_manager', $dept);
        $key = DepartmentDefaultMap::LEADER_OWNED[0];

        $this->assertTrue($this->resolver()->resolveAll($leader)[$key]->allowed);

        app(UserPageAccessOverrideService::class)->replaceOverrides($actor, $leader, [], [$key]);

        $resolved = $this->resolver()->resolveAll($leader->fresh());
        $this->assertFalse($resolved[$key]->allowed);
        $this->assertSame(PageAccessSource::UserDeny, $resolved[$key]->primarySource);
    }

    public function test_a_user_allow_respects_the_protection_boundary(): void
    {
        $actor = $this->makeUser('super_admin');
        $target = $this->makeUser('student');

        // A non-protected page may be granted individually.
        app(UserPageAccessOverrideService::class)->replaceOverrides($actor, $target, ['operations.meeting_lounge'], []);
        $resolved = $this->resolver()->resolveAll($target->fresh());
        $this->assertTrue($resolved['operations.meeting_lounge']->allowed);
        $this->assertSame(PageAccessSource::UserAllow, $resolved['operations.meeting_lounge']->primarySource);

        // A protected one may not, at any privilege level.
        $this->expectException(InvalidArgumentException::class);
        app(UserPageAccessOverrideService::class)->replaceOverrides($actor, $target, [$this->protectedKeys()[0]], []);
    }

    // ── 5. Authenticated baseline stays narrow ───────────────────────────────

    public function test_every_persona_receives_exactly_the_eight_baseline_capabilities(): void
    {
        $this->seedCanonical();

        $baseline = array_map(fn (PageAccessEntry $e) => $e->key, array_filter(
            PageAccessCatalog::all(), fn (PageAccessEntry $e) => $e->authenticatedBaseline
        ));
        $this->assertCount(8, $baseline);

        foreach (self::PERSONAS as $role) {
            if (in_array($role, self::ROOT, true)) {
                continue;
            }

            $resolved = $this->resolver()->resolveAll($this->makeUser($role));
            $fromBaseline = array_keys(array_filter(
                $resolved,
                fn ($r) => $r->allowed && $r->primarySource === PageAccessSource::AuthenticatedBaseline,
            ));

            sort($fromBaseline);
            $expected = array_values($baseline);
            sort($expected);

            $this->assertSame($expected, $fromBaseline, "[{$role}] baseline set drifted.");
        }
    }

    // ── 6. PAGE ACCESS != BUSINESS AUTHORIZATION ─────────────────────────────

    public function test_opening_a_page_confers_no_permission_and_no_delegation_right(): void
    {
        $this->seedCanonical();
        $dept = Department::where('slug', 'technology')->firstOrFail();

        $leader = $this->makeLeader('volunteer', $dept);
        $employee = $this->makeUser('student');
        $this->makeMember($employee, $dept);

        // The leader CAN open the delegation page.
        $resolved = $this->resolver()->resolveAll($leader);
        $this->assertTrue($resolved['organizational_departments.permissions_delegation']->allowed);

        // It buys them nothing: they hold the permission neither before nor after.
        $delegation = app(PermissionDelegationService::class);
        $this->assertFalse($delegation->canDelegate($leader, $employee, 'users.impersonate'));
        $this->assertFalse($delegation->canDelegate($leader, $leader, 'users.impersonate'));

        // And a cross-department target stays out of reach.
        $outside = $this->makeUser('student');
        $this->makeMember($outside, Department::where('slug', 'finance')->firstOrFail());
        $this->assertFalse($delegation->canDelegate($leader, $outside, 'users.impersonate'));
    }

    public function test_page_access_rows_never_appear_in_effective_permissions(): void
    {
        $this->seedCanonical();
        $dept = Department::where('slug', 'technology')->firstOrFail();
        $leader = $this->makeLeader('volunteer', $dept);

        $permissions = app(PermissionDelegationService::class)->getEffectivePermissions($leader);
        $pageKeys = array_map(fn (PageAccessEntry $e) => $e->key, PageAccessCatalog::all());

        foreach ($pageKeys as $key) {
            $this->assertNotContains($key, $permissions, "Page key [{$key}] leaked into effective permissions.");
        }
    }
}
