<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use App\Models\UserPageAccessOverride;
use App\Services\DepartmentPageDefaultService;
use App\Services\EffectivePageAccessService;
use App\Services\RolePageDefaultService;
use App\Services\UserPageAccessOverrideService;
use App\Support\PageAccess\DepartmentPageAudience;
use App\Support\PageAccess\PageAccessCatalog;
use App\Support\PageAccess\PageAccessSource;
use App\Support\PageAccess\UserPageAccessState;
use App\Support\ProtectedAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 2D — EFFECTIVE PAGE ACCESS RESOLVER.
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION: nothing here asserts that a user may
 * perform a business action. Every assertion is about whether a dashboard page
 * is exposed, and why.
 */
class EffectivePageAccessTest extends TestCase
{
    use RefreshDatabase;

    /** SAFE_DELEGATABLE — eligible for member defaults, leader defaults, role defaults and overrides. */
    private const MEMBER_PAGE = 'operations.weekly_reports';

    private const MEMBER_PAGE_2 = 'operations.meeting_reports';

    /** ADMIN_ONLY + department_scoped — eligible for the LEADER audience only. */
    private const LEADER_ONLY_PAGE = 'organizational_departments.structure';

    /** ADMIN_ONLY, not department_scoped — role-eligible, never a member default. */
    private const ROLE_ONLY_PAGE = 'quality.dashboard';

    /** SYSTEM_PROTECTED — may never be stored in any Phase 2A/2B/2C table. */
    private const PROTECTED_PAGE = 'administration.roles';

    /**
     * Phase 2G.2 — every authenticated account receives these seven from
     * PageAccessCatalog, with no stored row anywhere. Assertions below add them
     * explicitly rather than loosening to "contains", so an unexpected NINTH
     * baseline entry would still fail the suite.
     */
    private const AUTHENTICATED_BASELINE = [
        'operations.tickets',
        'hr.my_requests',
        'personal.profile',
        'personal.notifications',
        'personal.notification_preferences',
        'personal.volunteer_hr_profile',
        'personal.student_learning',
        'operations.my_tasks',
    ];

    /** @param array<int,string> $extra */
    private function baselinePlus(array $extra = []): array
    {
        $keys = array_merge(self::AUTHENTICATED_BASELINE, $extra);
        sort($keys);

        return $keys;
    }

    /** @param array<int,string> $keys */
    private function sorted(array $keys): array
    {
        sort($keys);

        return $keys;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function service(): EffectivePageAccessService
    {
        return app(EffectivePageAccessService::class);
    }

    private function makeDepartment(array $overrides = []): Department
    {
        return Department::create(array_merge([
            'name' => 'Dept', 'name_ar' => 'إدارة', 'slug' => 'effective-access-'.uniqid(),
            'status' => 'active', 'is_active' => true,
        ], $overrides));
    }

    /** Real membership: TeamProfile -> TeamMember, the source DepartmentAccessService reads. */
    private function makeMember(User $user, Department $dept, array $profileOverrides = []): TeamMember
    {
        $profile = TeamProfile::create(array_merge([
            'department_id' => $dept->id, 'user_id' => $user->id,
            'name_ar' => $user->name, 'position_ar' => 'عضو', 'is_active' => true,
        ], $profileOverrides));

        return TeamMember::create([
            'user_id' => $user->id, 'team_profile_id' => $profile->id,
            'department_id' => $dept->id, 'status' => 'active',
        ]);
    }

    /** Canonical leadership: departments.leader_id — the ONLY leadership authority. */
    private function makeLeader(User $user, Department $dept): void
    {
        $dept->forceFill(['leader_id' => $user->id])->save();
    }

    private function ordinaryUser(string $role = 'volunteer'): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role, 'is_system' => false]);

        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin', 'is_system' => true]);

        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function techAdmin(): User
    {
        Role::firstOrCreate(['name' => 'tech_admin'], ['display_name' => 'Tech Admin', 'is_system' => true]);

        return User::factory()->create(['role' => 'tech_admin', 'is_active' => true]);
    }

    /** @param array<int,string> $keys */
    private function giveRoleDefaults(User $user, array $keys): void
    {
        $role = Role::firstOrCreate(['name' => $user->effectiveRole()], ['display_name' => $user->role, 'is_system' => false]);
        app(RolePageDefaultService::class)->replaceDefaults($role, $keys);
    }

    /** @param array<int,string> $keys */
    private function giveDepartmentDefaults(Department $dept, DepartmentPageAudience $audience, array $keys): void
    {
        app(DepartmentPageDefaultService::class)->replaceDefaults($dept, $audience, $keys);
    }

    private function setOverride(User $target, string $key, UserPageAccessState $state): void
    {
        app(UserPageAccessOverrideService::class)->setOverride($this->superAdmin(), $target, $key, $state);
    }

    /**
     * Write a row the write-side services would REFUSE, to prove the resolver
     * is safe on its own and not merely protected by upstream validation.
     */
    private function forceRawOverrideRow(User $user, string $key, string $state): void
    {
        DB::table('user_page_access_overrides')->insert([
            'user_id' => $user->id, 'page_key' => $key, 'state' => $state,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── 1. No sources => denied ──────────────────────────────────────────────

    public function test_user_with_no_sources_is_denied(): void
    {
        $user = $this->ordinaryUser();

        $result = $this->service()->resolve($user, self::MEMBER_PAGE);

        $this->assertFalse($result->allowed);
        $this->assertSame(PageAccessSource::None, $result->primarySource);
        $this->assertSame([], $result->sources);
        $this->assertSame('no_source', $result->reason);
        $this->assertNull($result->overrideState);
    }

    public function test_user_with_no_sources_gets_only_the_authenticated_baseline(): void
    {
        // Phase 2G.2: no role/department/override source means the baseline and
        // nothing else — not an empty set.
        $this->assertSame(
            $this->baselinePlus(),
            $this->sorted($this->service()->allowedKeys($this->ordinaryUser()))
        );
    }

    // ── 2. Role default => allowed ───────────────────────────────────────────

    public function test_role_default_grants_access(): void
    {
        $user = $this->ordinaryUser();
        $this->giveRoleDefaults($user, [self::ROLE_ONLY_PAGE]);

        $result = $this->service()->resolve($user, self::ROLE_ONLY_PAGE);

        $this->assertTrue($result->allowed);
        $this->assertSame(PageAccessSource::Role, $result->primarySource);
    }

    public function test_legacy_teacher_slug_resolves_instructor_role_defaults(): void
    {
        Role::firstOrCreate(['name' => 'instructor'], ['display_name' => 'Instructor', 'is_system' => true]);
        $user = User::factory()->create(['role' => 'teacher', 'is_active' => true]);

        app(RolePageDefaultService::class)->replaceDefaults(
            Role::where('name', 'instructor')->first(),
            [self::MEMBER_PAGE]
        );

        $this->assertTrue($this->service()->canAccess($user, self::MEMBER_PAGE));
    }

    // ── 3. Department MEMBER default => allowed ──────────────────────────────

    public function test_department_member_default_grants_access(): void
    {
        $user = $this->ordinaryUser();
        $dept = $this->makeDepartment();
        $this->makeMember($user, $dept);
        $this->giveDepartmentDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE]);

        $result = $this->service()->resolve($user, self::MEMBER_PAGE);

        $this->assertTrue($result->allowed);
        $this->assertSame(PageAccessSource::DepartmentMember, $result->primarySource);
    }

    // ── 4/5. Leader defaults ─────────────────────────────────────────────────

    public function test_canonical_leader_receives_member_and_leader_defaults(): void
    {
        $user = $this->ordinaryUser();
        $dept = $this->makeDepartment();
        $this->makeMember($user, $dept);
        $this->makeLeader($user, $dept);

        $this->giveDepartmentDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE]);
        $this->giveDepartmentDefaults($dept, DepartmentPageAudience::Leader, [self::LEADER_ONLY_PAGE]);

        $svc = $this->service();

        $this->assertTrue($svc->canAccess($user, self::MEMBER_PAGE));
        $this->assertTrue($svc->canAccess($user, self::LEADER_ONLY_PAGE));
        $this->assertSame(
            PageAccessSource::DepartmentLeader,
            $svc->resolve($user, self::LEADER_ONLY_PAGE)->primarySource
        );
    }

    public function test_ordinary_member_does_not_receive_leader_only_default(): void
    {
        $leader = $this->ordinaryUser();
        $member = $this->ordinaryUser();
        $dept = $this->makeDepartment();

        $this->makeMember($leader, $dept);
        $this->makeMember($member, $dept);
        $this->makeLeader($leader, $dept);

        $this->giveDepartmentDefaults($dept, DepartmentPageAudience::Leader, [self::LEADER_ONLY_PAGE]);

        $this->assertTrue($this->service()->canAccess($leader, self::LEADER_ONLY_PAGE));
        $this->assertFalse($this->service()->canAccess($member, self::LEADER_ONLY_PAGE));
    }

    // ── 14/15. Leadership can only come from departments.leader_id ───────────

    public function test_role_slug_alone_does_not_produce_leader_defaults(): void
    {
        // A department-manager-shaped role slug with no leader_id assignment.
        $user = $this->ordinaryUser('department_manager');
        $dept = $this->makeDepartment();
        $this->makeMember($user, $dept);

        $this->giveDepartmentDefaults($dept, DepartmentPageAudience::Leader, [self::LEADER_ONLY_PAGE]);

        $this->assertFalse($this->service()->canAccess($user, self::LEADER_ONLY_PAGE));
    }

    public function test_team_profile_is_leader_flag_alone_does_not_produce_leader_defaults(): void
    {
        $user = $this->ordinaryUser();
        $dept = $this->makeDepartment();
        // Display badge set, canonical departments.leader_id deliberately NOT set.
        $this->makeMember($user, $dept, ['is_leader' => true]);

        $this->giveDepartmentDefaults($dept, DepartmentPageAudience::Leader, [self::LEADER_ONLY_PAGE]);

        $this->assertTrue(TeamProfile::where('user_id', $user->id)->first()->is_leader);
        $this->assertNull($dept->fresh()->leader_id);
        $this->assertFalse($this->service()->canAccess($user, self::LEADER_ONLY_PAGE));
    }

    // ── 6/10. USER ALLOW ─────────────────────────────────────────────────────

    public function test_user_allow_grants_page_absent_from_role_and_department(): void
    {
        $user = $this->ordinaryUser();
        $this->setOverride($user, self::MEMBER_PAGE, UserPageAccessState::Allow);

        $result = $this->service()->resolve($user, self::MEMBER_PAGE);

        $this->assertTrue($result->allowed);
        $this->assertSame(PageAccessSource::UserAllow, $result->primarySource);
        $this->assertSame(UserPageAccessState::Allow, $result->overrideState);
        $this->assertSame([], array_diff($result->sourceValues(), ['user_allow']));
    }

    public function test_user_allow_on_admin_only_page_is_consumed_as_stored(): void
    {
        // Phase 2C permits a global admin to ALLOW any non-protected page,
        // including ADMIN_ONLY. Phase 2D consumes valid stored overrides as-is
        // and neither widens nor narrows that rule.
        $user = $this->ordinaryUser();
        $this->setOverride($user, self::ROLE_ONLY_PAGE, UserPageAccessState::Allow);

        $this->assertTrue($this->service()->canAccess($user, self::ROLE_ONLY_PAGE));
    }

    // ── 7/8/9. USER DENY ─────────────────────────────────────────────────────

    public function test_user_deny_removes_role_default(): void
    {
        $user = $this->ordinaryUser();
        $this->giveRoleDefaults($user, [self::ROLE_ONLY_PAGE]);
        $this->setOverride($user, self::ROLE_ONLY_PAGE, UserPageAccessState::Deny);

        $result = $this->service()->resolve($user, self::ROLE_ONLY_PAGE);

        $this->assertFalse($result->allowed);
        $this->assertSame(PageAccessSource::UserDeny, $result->primarySource);
        // The inherited source stays visible so the UI can explain the block.
        $this->assertContains('role', $result->sourceValues());
    }

    public function test_user_deny_removes_department_default(): void
    {
        $user = $this->ordinaryUser();
        $dept = $this->makeDepartment();
        $this->makeMember($user, $dept);
        $this->giveDepartmentDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE]);
        $this->setOverride($user, self::MEMBER_PAGE, UserPageAccessState::Deny);

        $this->assertFalse($this->service()->canAccess($user, self::MEMBER_PAGE));
    }

    public function test_user_deny_wins_over_multiple_positive_sources(): void
    {
        $user = $this->ordinaryUser();
        $dept = $this->makeDepartment();
        $this->makeMember($user, $dept);
        $this->makeLeader($user, $dept);

        $this->giveRoleDefaults($user, [self::MEMBER_PAGE]);
        $this->giveDepartmentDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE]);
        $this->giveDepartmentDefaults($dept, DepartmentPageAudience::Leader, [self::MEMBER_PAGE]);
        $this->setOverride($user, self::MEMBER_PAGE, UserPageAccessState::Deny);

        $result = $this->service()->resolve($user, self::MEMBER_PAGE);

        $this->assertFalse($result->allowed);
        $this->assertSame(PageAccessSource::UserDeny, $result->primarySource);
        $this->assertSame(['role', 'department_member', 'department_leader'], $result->sourceValues());
    }

    // ── 11/12/13/22. SYSTEM PROTECTION ───────────────────────────────────────

    public function test_protected_page_is_denied_to_ordinary_user_with_every_positive_source(): void
    {
        $user = $this->ordinaryUser();
        $dept = $this->makeDepartment();
        $this->makeMember($user, $dept);
        $this->makeLeader($user, $dept);

        $result = $this->service()->resolve($user, self::PROTECTED_PAGE);

        $this->assertFalse($result->allowed);
        $this->assertTrue($result->isProtected);
        $this->assertSame(PageAccessSource::SystemProtected, $result->primarySource);
        $this->assertSame('system_protected', $result->reason);
    }

    public function test_protected_page_cannot_be_unlocked_by_a_forced_user_allow_row(): void
    {
        $user = $this->ordinaryUser();
        // The Phase 2C service refuses this key outright; force the row anyway
        // to prove the resolver does not depend on that upstream validation.
        $this->forceRawOverrideRow($user, self::PROTECTED_PAGE, 'allow');

        $result = $this->service()->resolve($user, self::PROTECTED_PAGE);

        $this->assertFalse($result->allowed);
        $this->assertNull($result->overrideState);
        $this->assertSame([], $result->sources);
    }

    public function test_super_admin_keeps_protected_access(): void
    {
        $user = $this->superAdmin();

        $result = $this->service()->resolve($user, self::PROTECTED_PAGE);

        $this->assertTrue($result->allowed);
        $this->assertTrue($result->isProtected);
        $this->assertSame(PageAccessSource::RootAuthority, $result->primarySource);
        $this->assertSame('root_authority', $result->reason);
        $this->assertTrue(ProtectedAuthority::isProtectedUser($user));
    }

    public function test_tech_admin_keeps_protected_access(): void
    {
        $this->assertTrue($this->service()->canAccess($this->techAdmin(), self::PROTECTED_PAGE));
    }

    public function test_user_deny_cannot_strip_protected_access_from_root(): void
    {
        $root = $this->superAdmin();
        $this->forceRawOverrideRow($root, self::PROTECTED_PAGE, 'deny');

        $result = $this->service()->resolve($root, self::PROTECTED_PAGE);

        $this->assertTrue($result->allowed);
        $this->assertSame(PageAccessSource::RootAuthority, $result->primarySource);
    }

    /**
     * Root authority is unrestricted across the WHOLE catalog, not just the
     * protected slice — matching Gate::before, User::basePermissions() and the
     * frontend's canAccessDashboardPath(). Resolving root against the mutable
     * layers instead would leave a fresh super_admin holding only whatever
     * someone had remembered to seed, and silently strip most of the dashboard
     * the moment a later phase switches the sidebar onto this resolver.
     */
    public function test_root_resolves_every_catalog_page(): void
    {
        $catalogSize = count(PageAccessCatalog::all());

        $this->assertCount($catalogSize, $this->service()->allowedKeys($this->superAdmin()));
        $this->assertCount($catalogSize, $this->service()->allowedKeys($this->techAdmin()));
    }

    public function test_root_needs_no_role_or_department_default_for_an_ordinary_page(): void
    {
        $root = $this->superAdmin();

        $result = $this->service()->resolve($root, self::MEMBER_PAGE);

        $this->assertTrue($result->allowed);
        $this->assertSame(PageAccessSource::RootAuthority, $result->primarySource);
        $this->assertSame(['root_authority'], $result->sourceValues());
        $this->assertFalse($result->isProtected);
    }

    public function test_user_deny_cannot_strip_an_ordinary_page_from_root(): void
    {
        // A tech_admin must not be able to lock a super_admin out of anything
        // through Phase 2C. The row is still reported, so the UI can say the
        // override exists but is superseded.
        $root = $this->superAdmin();
        $this->setOverride($root, self::MEMBER_PAGE, UserPageAccessState::Deny);

        $result = $this->service()->resolve($root, self::MEMBER_PAGE);

        $this->assertTrue($result->allowed);
        $this->assertSame(PageAccessSource::RootAuthority, $result->primarySource);
        $this->assertSame(UserPageAccessState::Deny, $result->overrideState);
    }

    public function test_ordinary_admin_tier_is_not_root_for_protected_pages(): void
    {
        // executive_admin and admin are deliberately NOT root: neither bypasses
        // Gates today, and Phase 2D must not silently promote them.
        $this->assertFalse($this->service()->canAccess($this->ordinaryUser('admin'), self::PROTECTED_PAGE));
        $this->assertFalse($this->service()->canAccess($this->ordinaryUser('executive_admin'), self::PROTECTED_PAGE));
    }

    // ── 16/17. No cross-leakage ──────────────────────────────────────────────

    public function test_department_defaults_do_not_leak_between_departments(): void
    {
        $userA = $this->ordinaryUser();
        $userB = $this->ordinaryUser();
        $deptA = $this->makeDepartment();
        $deptB = $this->makeDepartment();

        $this->makeMember($userA, $deptA);
        $this->makeMember($userB, $deptB);
        $this->giveDepartmentDefaults($deptA, DepartmentPageAudience::Member, [self::MEMBER_PAGE]);

        $this->assertTrue($this->service()->canAccess($userA, self::MEMBER_PAGE));
        $this->assertFalse($this->service()->canAccess($userB, self::MEMBER_PAGE));
    }

    public function test_user_overrides_do_not_leak_between_users(): void
    {
        $userA = $this->ordinaryUser();
        $userB = $this->ordinaryUser();

        $this->setOverride($userA, self::MEMBER_PAGE, UserPageAccessState::Allow);

        $this->assertTrue($this->service()->canAccess($userA, self::MEMBER_PAGE));
        $this->assertFalse($this->service()->canAccess($userB, self::MEMBER_PAGE));
    }

    // ── 18/20. Archived + inactive departments ───────────────────────────────

    public function test_archived_department_does_not_grant_active_defaults(): void
    {
        $user = $this->ordinaryUser();
        $dept = $this->makeDepartment();
        $this->makeMember($user, $dept);
        $this->giveDepartmentDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE]);

        $this->assertTrue($this->service()->canAccess($user, self::MEMBER_PAGE));

        $dept->forceFill(['status' => 'archived'])->save();

        $this->assertFalse($this->service()->canAccess($user, self::MEMBER_PAGE));
        // The stored rows are untouched — archiving is not deletion.
        $this->assertSame(
            [self::MEMBER_PAGE],
            app(DepartmentPageDefaultService::class)->getMemberDefaults($dept->fresh())
        );
    }

    public function test_archived_department_does_not_grant_leader_defaults(): void
    {
        $user = $this->ordinaryUser();
        $dept = $this->makeDepartment(['status' => 'archived']);
        $this->makeMember($user, $dept);
        $this->makeLeader($user, $dept);
        $this->giveDepartmentDefaults($dept, DepartmentPageAudience::Leader, [self::LEADER_ONLY_PAGE]);

        $this->assertFalse($this->service()->canAccess($user, self::LEADER_ONLY_PAGE));
    }

    public function test_inactive_department_does_not_grant_defaults(): void
    {
        $user = $this->ordinaryUser();
        $dept = $this->makeDepartment(['status' => 'inactive']);
        $this->makeMember($user, $dept);
        $this->giveDepartmentDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE]);

        $this->assertFalse($this->service()->canAccess($user, self::MEMBER_PAGE));
    }

    // ── 19-25. Provenance and deterministic primary source ───────────────────

    public function test_provenance_reports_role_source(): void
    {
        $user = $this->ordinaryUser();
        $this->giveRoleDefaults($user, [self::ROLE_ONLY_PAGE]);

        $result = $this->service()->resolve($user, self::ROLE_ONLY_PAGE);

        $this->assertSame(['role'], $result->sourceValues());
        $this->assertSame('من الدور', $result->primarySource->labelAr());
    }

    public function test_provenance_reports_department_member_source(): void
    {
        $user = $this->ordinaryUser();
        $dept = $this->makeDepartment();
        $this->makeMember($user, $dept);
        $this->giveDepartmentDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE]);

        $result = $this->service()->resolve($user, self::MEMBER_PAGE);

        $this->assertSame(['department_member'], $result->sourceValues());
        $this->assertSame('من الإدارة', $result->primarySource->labelAr());
    }

    public function test_provenance_reports_department_leader_source(): void
    {
        $user = $this->ordinaryUser();
        $dept = $this->makeDepartment();
        $this->makeLeader($user, $dept);
        $this->giveDepartmentDefaults($dept, DepartmentPageAudience::Leader, [self::LEADER_ONLY_PAGE]);

        $result = $this->service()->resolve($user, self::LEADER_ONLY_PAGE);

        $this->assertSame(['department_leader'], $result->sourceValues());
        $this->assertSame('لأنه قائد الإدارة', $result->primarySource->labelAr());
    }

    public function test_provenance_reports_user_allow(): void
    {
        $user = $this->ordinaryUser();
        $this->setOverride($user, self::MEMBER_PAGE, UserPageAccessState::Allow);

        $result = $this->service()->resolve($user, self::MEMBER_PAGE);

        $this->assertContains('user_allow', $result->sourceValues());
        $this->assertSame('allow', $result->overrideState->value);
        $this->assertSame('إضافة خاصة', $result->primarySource->labelAr());
    }

    public function test_provenance_reports_user_deny(): void
    {
        $user = $this->ordinaryUser();
        $dept = $this->makeDepartment();
        $this->makeMember($user, $dept);
        $this->giveDepartmentDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE]);
        $this->setOverride($user, self::MEMBER_PAGE, UserPageAccessState::Deny);

        $result = $this->service()->resolve($user, self::MEMBER_PAGE);

        $this->assertSame('deny', $result->overrideState->value);
        $this->assertSame(PageAccessSource::UserDeny, $result->primarySource);
        // A DENY is not a positive contributor — the inherited source remains.
        $this->assertSame(['department_member'], $result->sourceValues());
    }

    public function test_all_positive_sources_are_visible_together(): void
    {
        $user = $this->ordinaryUser();
        $dept = $this->makeDepartment();
        $this->makeMember($user, $dept);
        $this->makeLeader($user, $dept);

        $this->giveRoleDefaults($user, [self::MEMBER_PAGE]);
        $this->giveDepartmentDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE]);
        $this->giveDepartmentDefaults($dept, DepartmentPageAudience::Leader, [self::MEMBER_PAGE]);

        $result = $this->service()->resolve($user, self::MEMBER_PAGE);

        $this->assertTrue($result->allowed);
        $this->assertSame(['role', 'department_member', 'department_leader'], $result->sourceValues());
    }

    public function test_primary_source_follows_deterministic_precedence(): void
    {
        $user = $this->ordinaryUser();
        $dept = $this->makeDepartment();
        $this->makeMember($user, $dept);
        $this->makeLeader($user, $dept);

        // role + member only -> member wins over role
        $this->giveRoleDefaults($user, [self::MEMBER_PAGE, self::MEMBER_PAGE_2]);
        $this->giveDepartmentDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE]);
        $this->assertSame(
            PageAccessSource::DepartmentMember,
            $this->service()->resolve($user, self::MEMBER_PAGE)->primarySource
        );

        // role only -> role
        $this->assertSame(
            PageAccessSource::Role,
            $this->service()->resolve($user, self::MEMBER_PAGE_2)->primarySource
        );

        // + leader -> leader wins over both
        $this->giveDepartmentDefaults($dept, DepartmentPageAudience::Leader, [self::MEMBER_PAGE]);
        $this->assertSame(
            PageAccessSource::DepartmentLeader,
            $this->service()->resolve($user, self::MEMBER_PAGE)->primarySource
        );

        // + user allow -> allow wins over every default
        $this->setOverride($user, self::MEMBER_PAGE, UserPageAccessState::Allow);
        $this->assertSame(
            PageAccessSource::UserAllow,
            $this->service()->resolve($user, self::MEMBER_PAGE)->primarySource
        );
    }

    public function test_precedence_ladder_is_strictly_ordered(): void
    {
        $ladder = [
            PageAccessSource::RootAuthority,
            PageAccessSource::SystemProtected,
            PageAccessSource::UserDeny,
            PageAccessSource::UserAllow,
            PageAccessSource::DepartmentLeader,
            PageAccessSource::DepartmentMember,
            PageAccessSource::Role,
            PageAccessSource::None,
        ];

        for ($i = 1; $i < count($ladder); $i++) {
            $this->assertGreaterThan(
                $ladder[$i]->precedence(),
                $ladder[$i - 1]->precedence(),
                "{$ladder[$i - 1]->value} must outrank {$ladder[$i]->value}"
            );
        }
    }

    // ── 16A/26/27/28. Service API surface ────────────────────────────────────

    public function test_resolve_all_covers_the_whole_catalog_in_canonical_order(): void
    {
        $resolved = $this->service()->resolveAll($this->ordinaryUser());

        $this->assertSame(
            array_map(fn ($e) => $e->key, PageAccessCatalog::all()),
            array_keys($resolved)
        );
    }

    public function test_allowed_keys_returns_only_allowed_keys(): void
    {
        $user = $this->ordinaryUser();
        $dept = $this->makeDepartment();
        $this->makeMember($user, $dept);
        $this->giveDepartmentDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE, self::MEMBER_PAGE_2]);
        $this->setOverride($user, self::MEMBER_PAGE_2, UserPageAccessState::Deny);

        $allowed = $this->service()->allowedKeys($user);

        // MEMBER_PAGE_2 is denied by the override; MEMBER_PAGE survives, and the
        // authenticated baseline is present for every user.
        $this->assertSame($this->baselinePlus([self::MEMBER_PAGE]), $this->sorted($allowed));
    }

    public function test_can_access_agrees_with_resolve_for_every_catalog_page(): void
    {
        $user = $this->ordinaryUser();
        $dept = $this->makeDepartment();
        $this->makeMember($user, $dept);
        $this->makeLeader($user, $dept);
        $this->giveRoleDefaults($user, [self::ROLE_ONLY_PAGE]);
        $this->giveDepartmentDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE]);
        $this->setOverride($user, self::MEMBER_PAGE_2, UserPageAccessState::Allow);

        $svc = $this->service();

        foreach ($svc->resolveAll($user) as $key => $result) {
            $this->assertSame($result->allowed, $svc->canAccess($user, $key), "canAccess disagreed for [{$key}]");
        }
    }

    public function test_unknown_page_key_is_handled_safely(): void
    {
        $user = $this->superAdmin();

        $this->assertNull($this->service()->resolve($user, 'not.a.real.page'));
        $this->assertFalse($this->service()->canAccess($user, 'not.a.real.page'));
        $this->assertFalse($this->service()->canAccess($user, ''));
        $this->assertFalse($this->service()->canAccess($user, '/dashboard/admin/roles'));
    }

    // ── 31. The resolver is read-only ────────────────────────────────────────

    public function test_resolver_never_writes_to_the_database(): void
    {
        $user = $this->ordinaryUser();
        $dept = $this->makeDepartment();
        $this->makeMember($user, $dept);
        $this->makeLeader($user, $dept);
        $this->giveRoleDefaults($user, [self::ROLE_ONLY_PAGE]);
        $this->giveDepartmentDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE]);
        $this->setOverride($user, self::MEMBER_PAGE_2, UserPageAccessState::Allow);

        $before = [
            'role' => DB::table('role_page_defaults')->count(),
            'department' => DB::table('department_page_defaults')->count(),
            'override' => DB::table('user_page_access_overrides')->count(),
            'audit' => DB::table('audit_logs')->count(),
        ];

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)\s/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $this->service()->resolveAll($user);
        $this->service()->allowedKeys($user);
        $this->service()->canAccess($user, self::MEMBER_PAGE);

        $this->assertSame([], $writes, 'The resolver issued a write query.');
        $this->assertSame($before, [
            'role' => DB::table('role_page_defaults')->count(),
            'department' => DB::table('department_page_defaults')->count(),
            'override' => DB::table('user_page_access_overrides')->count(),
            'audit' => DB::table('audit_logs')->count(),
        ]);
    }

    // ── 29. Performance: never one query per catalog entry ───────────────────

    public function test_resolve_all_does_not_query_per_catalog_entry(): void
    {
        $user = $this->ordinaryUser();
        $dept = $this->makeDepartment();
        $this->makeMember($user, $dept);
        $this->makeLeader($user, $dept);
        $this->giveRoleDefaults($user, [self::ROLE_ONLY_PAGE]);
        $this->giveDepartmentDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE]);

        $catalogSize = count(PageAccessCatalog::all());

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->service()->resolveAll($user);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Deliberately not pinned to an exact number (framework internals make
        // that brittle). What matters is that the cost is bounded by the user's
        // department count, not by the 136-entry catalog.
        $this->assertLessThan(
            (int) ($catalogSize / 4),
            $count,
            "resolveAll() used {$count} queries for a {$catalogSize}-entry catalog — it must not scale with the catalog."
        );
    }

    // ── 24/25. Read API: self manifest ───────────────────────────────────────

    public function test_self_page_access_endpoint_returns_allowed_keys_and_provenance(): void
    {
        $user = $this->ordinaryUser();
        $dept = $this->makeDepartment();
        $this->makeMember($user, $dept);
        $this->giveDepartmentDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE]);

        $response = $this->actingAs($user)->getJson('/api/auth/me/page-access');

        $response->assertOk()->assertJsonPath('success', true);

        // The endpoint returns canonical CATALOG order, so compare membership
        // exactly but order-insensitively rather than re-encoding that order here.
        $this->assertEqualsCanonicalizing(
            $this->baselinePlus([self::MEMBER_PAGE]),
            $response->json('data.allowed_keys'),
        );

        // Page keys contain dots, so assertJsonPath() cannot address them —
        // index the decoded payload directly instead.
        $pages = $response->json('data.pages');

        $this->assertSame('department_member', $pages[self::MEMBER_PAGE]['primary_source']);
        $this->assertSame('من الإدارة', $pages[self::MEMBER_PAGE]['primary_source_label_ar']);
        $this->assertTrue($pages[self::MEMBER_PAGE]['allowed']);

        $this->assertFalse($pages[self::PROTECTED_PAGE]['allowed']);
        $this->assertTrue($pages[self::PROTECTED_PAGE]['protected']);
        $this->assertSame('system_protected', $pages[self::PROTECTED_PAGE]['primary_source']);

        $this->assertCount(count(PageAccessCatalog::all()), $pages);
    }

    public function test_self_page_access_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/auth/me/page-access')->assertUnauthorized();
    }

    // ── 29/30. Admin inspection authorization (IDOR) ─────────────────────────

    public function test_super_admin_can_inspect_any_user(): void
    {
        $target = $this->ordinaryUser();
        $this->setOverride($target, self::MEMBER_PAGE, UserPageAccessState::Allow);

        $response = $this->actingAs($this->superAdmin())
            ->getJson("/api/admin/users/{$target->id}/effective-page-access")
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            $this->baselinePlus([self::MEMBER_PAGE]),
            $response->json('data.allowed_keys'),
        );
    }

    public function test_ordinary_user_cannot_inspect_another_user(): void
    {
        $actor = $this->ordinaryUser();
        $target = $this->ordinaryUser();

        // The coarse route role filter rejects an ordinary role outright.
        $this->actingAs($actor)
            ->getJson("/api/admin/users/{$target->id}/effective-page-access")
            ->assertForbidden();
    }

    public function test_department_leader_can_inspect_only_users_in_led_departments(): void
    {
        $leader = $this->ordinaryUser('department_manager');
        $ledDept = $this->makeDepartment();
        $otherDept = $this->makeDepartment();

        $this->makeMember($leader, $ledDept);
        $this->makeLeader($leader, $ledDept);

        $inside = $this->ordinaryUser();
        $outside = $this->ordinaryUser();
        $this->makeMember($inside, $ledDept);
        $this->makeMember($outside, $otherDept);

        $this->actingAs($leader)
            ->getJson("/api/admin/users/{$inside->id}/effective-page-access")
            ->assertOk();

        $this->actingAs($leader)
            ->getJson("/api/admin/users/{$outside->id}/effective-page-access")
            ->assertForbidden();
    }

    public function test_non_super_admin_cannot_inspect_a_super_admin(): void
    {
        $target = $this->superAdmin();

        $this->actingAs($this->techAdmin())
            ->getJson("/api/admin/users/{$target->id}/effective-page-access")
            ->assertForbidden();
    }

    public function test_admin_inspection_is_read_only(): void
    {
        $target = $this->ordinaryUser();
        $before = DB::table('user_page_access_overrides')->count();

        $this->actingAs($this->superAdmin())
            ->getJson("/api/admin/users/{$target->id}/effective-page-access")
            ->assertOk();

        $this->assertSame($before, DB::table('user_page_access_overrides')->count());
    }

    // ── 32-36. Regression: the layers Phase 2D composes are unchanged ────────

    public function test_role_page_default_behavior_does_not_regress(): void
    {
        $svc = app(RolePageDefaultService::class);

        $this->assertCount(132, $svc->eligibleKeys());
        $this->assertNotContains(self::PROTECTED_PAGE, $svc->eligibleKeys());
    }

    public function test_department_page_default_behavior_does_not_regress(): void
    {
        $svc = app(DepartmentPageDefaultService::class);

        $this->assertCount(49, $svc->eligibleKeys(DepartmentPageAudience::Member));
        $this->assertCount(51, $svc->eligibleKeys(DepartmentPageAudience::Leader));
        $this->assertNotContains(self::LEADER_ONLY_PAGE, $svc->eligibleKeys(DepartmentPageAudience::Member));
        $this->assertContains(self::LEADER_ONLY_PAGE, $svc->eligibleKeys(DepartmentPageAudience::Leader));
    }

    public function test_user_page_access_override_behavior_does_not_regress(): void
    {
        $svc = app(UserPageAccessOverrideService::class);
        $target = $this->ordinaryUser();

        $this->assertCount(132, $svc->eligibleKeys());
        $this->assertSame([], $svc->getOverrides($target));

        $svc->setOverride($this->superAdmin(), $target, self::MEMBER_PAGE, UserPageAccessState::Allow);
        $this->assertSame([self::MEMBER_PAGE => 'allow'], $svc->getOverrides($target));
        $this->assertDatabaseCount('user_page_access_overrides', 1);

        // DEFAULT is still the absence of a row.
        $svc->resetOverride($this->superAdmin(), $target, self::MEMBER_PAGE);
        $this->assertDatabaseCount('user_page_access_overrides', 0);
        $this->assertSame(0, UserPageAccessOverride::query()->count());
    }

    public function test_page_access_catalog_behavior_does_not_regress(): void
    {
        $all = PageAccessCatalog::all();

        $this->assertCount(141, $all);
        $this->assertTrue(PageAccessCatalog::find(self::PROTECTED_PAGE)->isProtected());
        $this->assertFalse(PageAccessCatalog::find(self::MEMBER_PAGE)->isProtected());
    }

    public function test_protected_authority_behavior_does_not_regress(): void
    {
        $this->assertSame('super_admin', ProtectedAuthority::PROTECTED_ROLE);
        $this->assertTrue(ProtectedAuthority::isProtectedUser($this->superAdmin()));
        $this->assertFalse(ProtectedAuthority::isProtectedUser($this->techAdmin()));
    }
}
