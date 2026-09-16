<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Role;
use App\Models\RolePageDefault;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use App\Models\UserPageAccessOverride;
use App\Services\DepartmentPageDefaultService;
use App\Services\RolePageDefaultService;
use App\Services\UserPageAccessOverrideService;
use App\Support\PageAccess\DepartmentPageAudience;
use App\Support\PageAccess\PageAccessCatalog;
use App\Support\PageAccess\UserPageAccessState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 2C — per-user PAGE ACCESS overrides.
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION: nothing here asserts a user may
 * perform a business action. These tests cover override storage, DEFAULT as
 * absence-of-row, page eligibility, actor authority, and provenance metadata.
 */
class UserPageAccessOverrideTest extends TestCase
{
    use RefreshDatabase;

    private const DELEGATABLE_PAGE = 'operations.weekly_reports';   // SAFE_DELEGATABLE, delegatable=true
    private const DELEGATABLE_PAGE_2 = 'operations.meeting_reports';// SAFE_DELEGATABLE, delegatable=true
    private const ADMIN_PAGE = 'quality.dashboard';                 // ADMIN_ONLY, delegatable=false
    private const PROTECTED_PAGE = 'administration.roles';          // SYSTEM_PROTECTED
    // Phase 2G.1C: ai_command_center is now ADMIN_ONLY (admin-tier backend
    // gate). platform_scale has no backend endpoint at all and remains
    // SYSTEM_PROTECTED, so it is the correct fixture for "root-only" here.
    private const PROTECTED_PAGE_2 = 'technical_system.platform_scale';

    private function service(): UserPageAccessOverrideService
    {
        return app(UserPageAccessOverrideService::class);
    }

    private function makeDepartment(array $overrides = []): Department
    {
        return Department::create(array_merge([
            'name' => 'Dept', 'name_ar' => 'إدارة', 'slug' => 'user-override-test-'.uniqid(),
            'status' => 'active', 'is_active' => true,
        ], $overrides));
    }

    /** Make $user an actual member of $dept (TeamProfile -> TeamMember). */
    private function makeMember(User $user, Department $dept): TeamMember
    {
        $profile = TeamProfile::create([
            'department_id' => $dept->id, 'user_id' => $user->id,
            'name_ar' => $user->name, 'position_ar' => 'عضو', 'is_active' => true,
        ]);

        return TeamMember::create([
            'user_id' => $user->id, 'team_profile_id' => $profile->id,
            'department_id' => $dept->id, 'status' => 'active',
        ]);
    }

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => 'super_admin'], ['display_name' => 'Super Admin', 'is_system' => true]);

        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function techAdmin(): User
    {
        return User::factory()->create(['role' => 'tech_admin', 'is_active' => true]);
    }

    private function ordinaryUser(): User
    {
        return User::factory()->create(['role' => 'volunteer', 'is_active' => true]);
    }

    // ── 1/2/9/10. Creating ALLOW and DENY ────────────────────────────────────

    public function test_allow_override_can_be_created(): void
    {
        $actor = $this->superAdmin();
        $target = $this->ordinaryUser();

        $this->service()->setOverride($actor, $target, self::DELEGATABLE_PAGE, UserPageAccessState::Allow);

        $this->assertSame([self::DELEGATABLE_PAGE => 'allow'], $this->service()->getOverrides($target));
        $this->assertDatabaseHas('user_page_access_overrides', [
            'user_id' => $target->id, 'page_key' => self::DELEGATABLE_PAGE, 'state' => 'allow',
        ]);
    }

    public function test_deny_override_can_be_created_for_eligible_page(): void
    {
        $actor = $this->superAdmin();
        $target = $this->ordinaryUser();

        $this->service()->setOverride($actor, $target, self::DELEGATABLE_PAGE, UserPageAccessState::Deny);

        $this->assertSame([self::DELEGATABLE_PAGE => 'deny'], $this->service()->getOverrides($target));
    }

    public function test_allow_works_for_admin_only_page_for_global_admin(): void
    {
        $actor = $this->superAdmin();
        $target = $this->ordinaryUser();

        // The documented business rule: an ALLOW may expose a page the user's
        // role/department defaults do not include.
        $this->service()->setOverride($actor, $target, self::ADMIN_PAGE, UserPageAccessState::Allow);

        $this->assertSame([self::ADMIN_PAGE => 'allow'], $this->service()->getOverrides($target));
    }

    // ── 3/4. Uniqueness & state transition ───────────────────────────────────

    public function test_only_one_override_per_user_and_page_at_database_level(): void
    {
        $target = $this->ordinaryUser();
        UserPageAccessOverride::create([
            'user_id' => $target->id, 'page_key' => self::DELEGATABLE_PAGE, 'state' => 'allow',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        UserPageAccessOverride::create([
            'user_id' => $target->id, 'page_key' => self::DELEGATABLE_PAGE, 'state' => 'deny',
        ]);
    }

    public function test_allow_to_deny_updates_the_same_row(): void
    {
        $actor = $this->superAdmin();
        $target = $this->ordinaryUser();

        $first = $this->service()->setOverride($actor, $target, self::DELEGATABLE_PAGE, UserPageAccessState::Allow);
        $second = $this->service()->setOverride($actor, $target, self::DELEGATABLE_PAGE, UserPageAccessState::Deny);

        $this->assertSame($first->id, $second->id, 'ALLOW -> DENY must update the same logical override row');
        $this->assertSame(1, UserPageAccessOverride::where('user_id', $target->id)->count());
        $this->assertSame([self::DELEGATABLE_PAGE => 'deny'], $this->service()->getOverrides($target));
    }

    // ── 5/6. Reset and DEFAULT-as-absence ────────────────────────────────────

    public function test_reset_removes_the_row_rather_than_storing_default(): void
    {
        $actor = $this->superAdmin();
        $target = $this->ordinaryUser();
        $this->service()->setOverride($actor, $target, self::DELEGATABLE_PAGE, UserPageAccessState::Allow);

        $this->service()->resetOverride($actor, $target, self::DELEGATABLE_PAGE);

        $this->assertSame(0, UserPageAccessOverride::where('user_id', $target->id)->count(),
            'reset must DELETE the row, never write a default state');
        $this->assertDatabaseMissing('user_page_access_overrides', [
            'user_id' => $target->id, 'page_key' => self::DELEGATABLE_PAGE,
        ]);
    }

    public function test_missing_row_represents_default(): void
    {
        $target = $this->ordinaryUser();

        $this->assertSame([], $this->service()->getOverrides($target));
        $this->assertNull($this->service()->getState($target, self::DELEGATABLE_PAGE),
            'no row means DEFAULT — neither allow nor deny');
    }

    public function test_no_default_state_exists_in_the_enum(): void
    {
        $values = array_map(fn ($c) => $c->value, UserPageAccessState::cases());

        $this->assertEqualsCanonicalizing(['allow', 'deny'], $values);
        $this->assertNotContains('default', $values);
    }

    // ── 7/8. Validation & SYSTEM_PROTECTED ───────────────────────────────────

    public function test_invalid_page_key_is_rejected(): void
    {
        $actor = $this->superAdmin();
        $target = $this->ordinaryUser();

        $this->expectException(InvalidArgumentException::class);
        $this->service()->setOverride($actor, $target, 'not.a.real.page', UserPageAccessState::Allow);
    }

    public function test_system_protected_allow_is_rejected_even_for_super_admin(): void
    {
        $actor = $this->superAdmin();
        $target = $this->ordinaryUser();

        try {
            $this->service()->setOverride($actor, $target, self::PROTECTED_PAGE, UserPageAccessState::Allow);
            $this->fail('SYSTEM_PROTECTED ALLOW must be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('SYSTEM_PROTECTED', $e->getMessage());
        }

        $this->assertSame(0, UserPageAccessOverride::count());
    }

    public function test_system_protected_deny_is_also_rejected(): void
    {
        $actor = $this->superAdmin();
        $target = $this->ordinaryUser();

        $this->expectException(InvalidArgumentException::class);
        $this->service()->setOverride($actor, $target, self::PROTECTED_PAGE_2, UserPageAccessState::Deny);
    }

    public function test_no_system_protected_catalog_entry_is_ever_page_eligible(): void
    {
        foreach (PageAccessCatalog::all() as $entry) {
            if ($entry->isProtected()) {
                $this->assertFalse($this->service()->isPageEligible($entry),
                    "SYSTEM_PROTECTED [{$entry->key}] must never be storable as a user override");
            }
        }
    }

    // ── 11/12/13. Global admin authority ─────────────────────────────────────

    public function test_super_admin_can_manage_ordinary_user_override(): void
    {
        $actor = $this->superAdmin();
        $target = $this->ordinaryUser();

        $this->assertTrue($this->service()->canConfigure($actor, $target));
        $this->service()->setOverride($actor, $target, self::ADMIN_PAGE, UserPageAccessState::Allow);
        $this->assertSame([self::ADMIN_PAGE => 'allow'], $this->service()->getOverrides($target));
    }

    public function test_tech_admin_can_manage_ordinary_user_override(): void
    {
        $actor = $this->techAdmin();
        $target = $this->ordinaryUser();

        $this->assertTrue($this->service()->canConfigure($actor, $target));
        $this->service()->setOverride($actor, $target, self::ADMIN_PAGE, UserPageAccessState::Allow);
        $this->assertSame([self::ADMIN_PAGE => 'allow'], $this->service()->getOverrides($target));
    }

    public function test_tech_admin_cannot_create_root_equivalent_access(): void
    {
        $actor = $this->techAdmin();
        $target = $this->ordinaryUser();

        foreach ([self::PROTECTED_PAGE, self::PROTECTED_PAGE_2] as $protected) {
            try {
                $this->service()->setOverride($actor, $target, $protected, UserPageAccessState::Allow);
                $this->fail("tech_admin must not grant [{$protected}]");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('SYSTEM_PROTECTED', $e->getMessage());
            }
        }

        $this->assertSame(0, UserPageAccessOverride::count());
    }

    public function test_tech_admin_cannot_configure_a_super_admin_target(): void
    {
        $actor = $this->techAdmin();
        $target = $this->superAdmin();

        $this->assertFalse($this->service()->canConfigure($actor, $target),
            'the protected super_admin identity may only be configured by a super_admin');

        $this->expectException(RuntimeException::class);
        $this->service()->setOverride($actor, $target, self::DELEGATABLE_PAGE, UserPageAccessState::Deny);
    }

    public function test_super_admin_may_configure_a_super_admin_target(): void
    {
        $actor = $this->superAdmin();
        $target = $this->superAdmin();

        $this->assertTrue($this->service()->canConfigure($actor, $target));
    }

    // ── 14/15/16. Department leader scope ────────────────────────────────────

    public function test_department_leader_can_manage_user_in_own_department(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $dept = $this->makeDepartment(['leader_id' => $leader->id]);
        $target = $this->ordinaryUser();
        $this->makeMember($target, $dept);

        $this->assertTrue($this->service()->canConfigure($leader, $target));

        $this->service()->setOverride($leader, $target, self::DELEGATABLE_PAGE, UserPageAccessState::Allow);
        $this->assertSame([self::DELEGATABLE_PAGE => 'allow'], $this->service()->getOverrides($target));
    }

    public function test_department_leader_cannot_manage_user_in_another_department(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $this->makeDepartment(['leader_id' => $leader->id]);
        $otherDept = $this->makeDepartment();
        $outsider = $this->ordinaryUser();
        $this->makeMember($outsider, $otherDept);

        $this->assertFalse($this->service()->canConfigure($leader, $outsider));

        $this->expectException(RuntimeException::class);
        $this->service()->setOverride($leader, $outsider, self::DELEGATABLE_PAGE, UserPageAccessState::Allow);
    }

    public function test_role_slug_alone_does_not_make_an_actor_a_department_leader(): void
    {
        // Manager-sounding role, but leads NO department (leader_id is null).
        $pretender = User::factory()->create(['role' => 'department_manager', 'is_active' => true]);
        $dept = $this->makeDepartment();
        $target = $this->ordinaryUser();
        $this->makeMember($target, $dept);
        $this->makeMember($pretender, $dept);

        $this->assertFalse($this->service()->canConfigure($pretender, $target),
            'leadership must come from departments.leader_id, never a role slug');
    }

    public function test_department_leader_cannot_grant_non_delegatable_page(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $dept = $this->makeDepartment(['leader_id' => $leader->id]);
        $target = $this->ordinaryUser();
        $this->makeMember($target, $dept);

        // quality.dashboard is ADMIN_ONLY / delegatable=false.
        try {
            $this->service()->setOverride($leader, $target, self::ADMIN_PAGE, UserPageAccessState::Allow);
            $this->fail('department leader must not grant a non-delegatable page');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('outside your authority', $e->getMessage());
        }

        $this->assertSame(0, UserPageAccessOverride::count());
    }

    public function test_department_leader_grantable_set_excludes_forbidden_surfaces(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $this->makeDepartment(['leader_id' => $leader->id]);

        $grantable = $this->service()->grantableKeysForActor($leader);

        foreach ($grantable as $key) {
            $entry = PageAccessCatalog::find($key);
            $this->assertTrue($entry->delegatable, "[{$key}] must be delegatable");
            $this->assertFalse($entry->isProtected(), "[{$key}] must not be protected");
            $this->assertNotContains($entry->category->value, ['administration', 'technical_system'],
                "department leader must never be able to grant [{$key}]");
        }

        $this->assertNotEmpty($grantable);
    }

    public function test_department_leader_cannot_configure_their_own_overrides(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $this->makeDepartment(['leader_id' => $leader->id]);

        $this->assertFalse($this->service()->canConfigure($leader, $leader),
            'mirrors PermissionDelegationService refusing self-delegation');
    }

    // ── 17/18/19. Provenance metadata ────────────────────────────────────────

    public function test_source_department_id_is_recorded_for_department_leader_grant(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $dept = $this->makeDepartment(['leader_id' => $leader->id]);
        $target = $this->ordinaryUser();
        $this->makeMember($target, $dept);

        $override = $this->service()->setOverride($leader, $target, self::DELEGATABLE_PAGE, UserPageAccessState::Allow);

        $this->assertSame($dept->id, $override->source_department_id);
    }

    public function test_source_department_id_is_null_for_global_admin_grant(): void
    {
        $actor = $this->superAdmin();
        $target = $this->ordinaryUser();

        $override = $this->service()->setOverride($actor, $target, self::DELEGATABLE_PAGE, UserPageAccessState::Allow);

        $this->assertNull($override->source_department_id,
            'a global admin grant is not department-scoped');
    }

    public function test_granted_by_is_recorded(): void
    {
        $actor = $this->superAdmin();
        $target = $this->ordinaryUser();

        $override = $this->service()->setOverride($actor, $target, self::DELEGATABLE_PAGE, UserPageAccessState::Allow);

        $this->assertSame($actor->id, $override->granted_by);
    }

    // ── 20/21/22. Bulk replace ───────────────────────────────────────────────

    public function test_bulk_update_is_atomic(): void
    {
        $actor = $this->superAdmin();
        $target = $this->ordinaryUser();

        $result = $this->service()->replaceOverrides(
            $actor, $target,
            [self::DELEGATABLE_PAGE, self::ADMIN_PAGE],
            [self::DELEGATABLE_PAGE_2],
        );

        $this->assertSame('allow', $result[self::DELEGATABLE_PAGE]);
        $this->assertSame('allow', $result[self::ADMIN_PAGE]);
        $this->assertSame('deny', $result[self::DELEGATABLE_PAGE_2]);
        $this->assertSame(3, UserPageAccessOverride::where('user_id', $target->id)->count());
    }

    public function test_invalid_item_causes_full_bulk_rollback(): void
    {
        $actor = $this->superAdmin();
        $target = $this->ordinaryUser();
        $this->service()->setOverride($actor, $target, self::DELEGATABLE_PAGE, UserPageAccessState::Allow);

        try {
            $this->service()->replaceOverrides($actor, $target, [self::ADMIN_PAGE, self::PROTECTED_PAGE], []);
            $this->fail('expected rejection');
        } catch (InvalidArgumentException $e) {
            // expected
        }

        $this->assertSame([self::DELEGATABLE_PAGE => 'allow'], $this->service()->getOverrides($target),
            'a rejected bulk replace must leave the previous set untouched');
        $this->assertSame(1, UserPageAccessOverride::where('user_id', $target->id)->count());
    }

    public function test_bulk_rejects_a_page_that_is_both_allowed_and_denied(): void
    {
        $actor = $this->superAdmin();
        $target = $this->ordinaryUser();

        $this->expectException(InvalidArgumentException::class);
        $this->service()->replaceOverrides($actor, $target, [self::DELEGATABLE_PAGE], [self::DELEGATABLE_PAGE]);
    }

    public function test_repeated_identical_update_is_idempotent(): void
    {
        $actor = $this->superAdmin();
        $target = $this->ordinaryUser();

        $first = $this->service()->replaceOverrides($actor, $target, [self::DELEGATABLE_PAGE], [self::ADMIN_PAGE]);
        $rowIds = UserPageAccessOverride::where('user_id', $target->id)->orderBy('id')->pluck('id')->all();

        $second = $this->service()->replaceOverrides($actor, $target, [self::DELEGATABLE_PAGE], [self::ADMIN_PAGE]);

        $this->assertSame($first, $second);
        $this->assertSame($rowIds, UserPageAccessOverride::where('user_id', $target->id)->orderBy('id')->pluck('id')->all(),
            'unchanged rows must not be churned/recreated');
    }

    public function test_bulk_replace_resets_omitted_pages_to_default(): void
    {
        $actor = $this->superAdmin();
        $target = $this->ordinaryUser();
        $this->service()->replaceOverrides($actor, $target, [self::DELEGATABLE_PAGE, self::ADMIN_PAGE], []);

        // "Reset all": omit everything.
        $result = $this->service()->replaceOverrides($actor, $target, [], []);

        $this->assertSame([], $result);
        $this->assertSame(0, UserPageAccessOverride::where('user_id', $target->id)->count());
    }

    // ── 23/24. No leakage ────────────────────────────────────────────────────

    public function test_user_a_override_never_leaks_to_user_b(): void
    {
        $actor = $this->superAdmin();
        $userA = $this->ordinaryUser();
        $userB = $this->ordinaryUser();

        $this->service()->setOverride($actor, $userA, self::DELEGATABLE_PAGE, UserPageAccessState::Allow);

        $this->assertSame([self::DELEGATABLE_PAGE => 'allow'], $this->service()->getOverrides($userA));
        $this->assertSame([], $this->service()->getOverrides($userB));
    }

    public function test_department_a_leader_override_never_affects_department_b_user(): void
    {
        $leaderA = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $deptA = $this->makeDepartment(['leader_id' => $leaderA->id]);
        $deptB = $this->makeDepartment();

        $userA = $this->ordinaryUser();
        $userB = $this->ordinaryUser();
        $this->makeMember($userA, $deptA);
        $this->makeMember($userB, $deptB);

        $this->service()->setOverride($leaderA, $userA, self::DELEGATABLE_PAGE, UserPageAccessState::Allow);

        $this->assertSame([], $this->service()->getOverrides($userB));
        $this->assertFalse($this->service()->canConfigure($leaderA, $userB));
    }

    // ── API surface ──────────────────────────────────────────────────────────

    public function test_super_admin_can_read_and_replace_via_api(): void
    {
        $actor = $this->superAdmin();
        $target = $this->ordinaryUser();

        $this->actingAs($actor)->getJson("/api/admin/users/{$target->id}/page-access-overrides")
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => ['user' => ['id', 'name', 'email', 'role'], 'overrides', 'grantable'],
            ]);

        $this->actingAs($actor)->putJson("/api/admin/users/{$target->id}/page-access-overrides", [
            'allow' => [self::ADMIN_PAGE],
            'deny' => [self::DELEGATABLE_PAGE],
        ])->assertOk();

        $this->assertSame(
            [self::DELEGATABLE_PAGE => 'deny', self::ADMIN_PAGE => 'allow'],
            $this->service()->getOverrides($target)
        );
    }

    public function test_api_rejects_protected_page_with_422_and_writes_nothing(): void
    {
        $actor = $this->superAdmin();
        $target = $this->ordinaryUser();

        $this->actingAs($actor)->putJson("/api/admin/users/{$target->id}/page-access-overrides", [
            'allow' => [self::ADMIN_PAGE, self::PROTECTED_PAGE],
            'deny' => [],
        ])->assertStatus(422);

        $this->assertSame(0, UserPageAccessOverride::count());
    }

    public function test_api_returns_403_for_department_leader_targeting_outsider(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $this->makeDepartment(['leader_id' => $leader->id]);
        $otherDept = $this->makeDepartment();
        $outsider = $this->ordinaryUser();
        $this->makeMember($outsider, $otherDept);

        $this->actingAs($leader)->getJson("/api/admin/users/{$outsider->id}/page-access-overrides")
            ->assertStatus(403);

        $this->actingAs($leader)->putJson("/api/admin/users/{$outsider->id}/page-access-overrides", [
            'allow' => [self::DELEGATABLE_PAGE], 'deny' => [],
        ])->assertStatus(403);
    }

    public function test_api_department_leader_grantable_set_is_narrowed(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $dept = $this->makeDepartment(['leader_id' => $leader->id]);
        $target = $this->ordinaryUser();
        $this->makeMember($target, $dept);

        $response = $this->actingAs($leader)->getJson("/api/admin/users/{$target->id}/page-access-overrides");

        $response->assertOk();
        $keys = array_column($response->json('data.grantable'), 'key');

        $this->assertNotContains(self::ADMIN_PAGE, $keys, 'non-delegatable page must not be offered to a leader');
        $this->assertContains(self::DELEGATABLE_PAGE, $keys);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $target = $this->ordinaryUser();

        $this->getJson("/api/admin/users/{$target->id}/page-access-overrides")->assertStatus(401);
    }

    public function test_ordinary_user_cannot_reach_the_endpoint(): void
    {
        $actor = $this->ordinaryUser();
        $target = $this->ordinaryUser();

        $this->actingAs($actor)->getJson("/api/admin/users/{$target->id}/page-access-overrides")
            ->assertStatus(403);
    }

    // ── Cascades & audit ─────────────────────────────────────────────────────

    public function test_deleting_the_user_removes_their_overrides(): void
    {
        $actor = $this->superAdmin();
        $target = $this->ordinaryUser();
        $this->service()->setOverride($actor, $target, self::DELEGATABLE_PAGE, UserPageAccessState::Allow);
        $targetId = $target->id;

        $target->forceDelete();

        $this->assertSame(0, UserPageAccessOverride::where('user_id', $targetId)->count());
    }

    public function test_setting_an_override_creates_an_audit_trail(): void
    {
        $actor = $this->superAdmin();
        $target = $this->ordinaryUser();

        $before = DB::table('audit_logs')->count();
        $this->service()->setOverride($actor, $target, self::DELEGATABLE_PAGE, UserPageAccessState::Allow);

        $this->assertGreaterThan($before, DB::table('audit_logs')->count(),
            'UserPageAccessOverride uses Auditable — writes must be recorded');
    }

    // ── 25/26/27/28. Non-regression ──────────────────────────────────────────

    public function test_role_page_default_behavior_does_not_regress(): void
    {
        $role = Role::firstOrCreate(['name' => 'finance_manager'], ['display_name' => 'Finance', 'is_system' => true]);
        $roleService = app(RolePageDefaultService::class);

        $roleService->replaceDefaults($role, [self::ADMIN_PAGE]);
        $this->assertSame([self::ADMIN_PAGE], $roleService->getDefaultsForRole($role));

        // User overrides live in a completely separate store.
        $actor = $this->superAdmin();
        $target = $this->ordinaryUser();
        $this->service()->setOverride($actor, $target, self::DELEGATABLE_PAGE, UserPageAccessState::Allow);

        $this->assertSame([self::ADMIN_PAGE], $roleService->getDefaultsForRole($role), 'role defaults untouched');
        $this->assertSame(1, RolePageDefault::where('role_id', $role->id)->count());
    }

    public function test_department_page_default_behavior_does_not_regress(): void
    {
        $dept = $this->makeDepartment();
        $deptService = app(DepartmentPageDefaultService::class);

        $deptService->replaceDefaults($dept, DepartmentPageAudience::Member, [self::DELEGATABLE_PAGE]);
        $this->assertSame([self::DELEGATABLE_PAGE], $deptService->getMemberDefaults($dept));

        $actor = $this->superAdmin();
        $target = $this->ordinaryUser();
        $this->service()->setOverride($actor, $target, self::DELEGATABLE_PAGE, UserPageAccessState::Deny);

        $this->assertSame([self::DELEGATABLE_PAGE], $deptService->getMemberDefaults($dept),
            'a user DENY must not mutate the department default layer');
    }

    public function test_protected_authority_behavior_does_not_regress(): void
    {
        $this->assertTrue(\App\Support\ProtectedAuthority::isProtectedRoleName('super_admin'));
        $this->assertFalse(\App\Support\ProtectedAuthority::isProtectedRoleName('tech_admin'));
    }

    public function test_page_access_catalog_behavior_does_not_regress(): void
    {
        $this->assertNotNull(PageAccessCatalog::find(self::DELEGATABLE_PAGE));
        $this->assertNotNull(PageAccessCatalog::find(self::ADMIN_PAGE));
        $this->assertTrue(PageAccessCatalog::find(self::PROTECTED_PAGE)->isProtected());
        $this->assertNull(PageAccessCatalog::find('does.not.exist'));
    }
}
