<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentPageDefault;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use App\Services\DepartmentPageDefaultService;
use App\Support\PageAccess\DepartmentPageAudience;
use App\Support\PageAccess\PageAccessCatalog;
use App\Support\PageAccess\PageAccessRiskLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Phase 2A — Department default PAGE ACCESS.
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION: nothing here asserts that a user may
 * perform a business action. These tests only cover which pages a department
 * hands out by default, who receives them, who may configure them, and that
 * no protected/ineligible page can ever be stored.
 */
class DepartmentPageDefaultTest extends TestCase
{
    use RefreshDatabase;

    private const MEMBER_PAGE = 'organizational_departments.workspace';   // SAFE_DELEGATABLE
    private const MEMBER_PAGE_2 = 'personal.notifications';               // SAFE_DELEGATABLE
    private const LEADER_PAGE = 'organizational_departments.structure';   // ADMIN_ONLY + department_scoped
    private const PROTECTED_PAGE = 'administration.roles';                // SYSTEM_PROTECTED
    private const ADMIN_ONLY_GLOBAL = 'administration.users';             // ADMIN_ONLY, not department_scoped

    private function service(): DepartmentPageDefaultService
    {
        return app(DepartmentPageDefaultService::class);
    }

    private function makeDepartment(array $overrides = []): Department
    {
        return Department::create(array_merge([
            'name' => 'Dept', 'name_ar' => 'إدارة', 'slug' => 'page-defaults-test-'.uniqid(),
            'status' => 'active', 'is_active' => true,
        ], $overrides));
    }

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

    // ── 1/2. Storage ─────────────────────────────────────────────────────────

    public function test_member_default_can_be_stored(): void
    {
        $dept = $this->makeDepartment();

        $this->service()->replaceDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE]);

        $this->assertSame([self::MEMBER_PAGE], $this->service()->getMemberDefaults($dept));
        $this->assertDatabaseHas('department_page_defaults', [
            'department_id' => $dept->id, 'page_key' => self::MEMBER_PAGE, 'audience' => 'member',
        ]);
    }

    public function test_leader_default_can_be_stored(): void
    {
        $dept = $this->makeDepartment();

        $this->service()->replaceDefaults($dept, DepartmentPageAudience::Leader, [self::LEADER_PAGE]);

        $this->assertSame([self::LEADER_PAGE], $this->service()->getLeaderDefaults($dept));
    }

    // ── 3. Duplicates ────────────────────────────────────────────────────────

    public function test_duplicate_page_defaults_cannot_exist_at_database_level(): void
    {
        $dept = $this->makeDepartment();
        DepartmentPageDefault::create([
            'department_id' => $dept->id, 'page_key' => self::MEMBER_PAGE, 'audience' => 'member',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DepartmentPageDefault::create([
            'department_id' => $dept->id, 'page_key' => self::MEMBER_PAGE, 'audience' => 'member',
        ]);
    }

    public function test_same_page_may_exist_once_per_audience(): void
    {
        $dept = $this->makeDepartment();

        $this->service()->replaceDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE]);
        $this->service()->replaceDefaults($dept, DepartmentPageAudience::Leader, [self::MEMBER_PAGE]);

        $this->assertSame(2, DepartmentPageDefault::where('department_id', $dept->id)->count());
    }

    public function test_repeated_keys_in_one_request_are_deduped(): void
    {
        $dept = $this->makeDepartment();

        $this->service()->replaceDefaults($dept, DepartmentPageAudience::Member, [
            self::MEMBER_PAGE, self::MEMBER_PAGE, self::MEMBER_PAGE,
        ]);

        $this->assertSame(1, DepartmentPageDefault::where('department_id', $dept->id)->count());
    }

    // ── 4/5/6. Validation & eligibility ──────────────────────────────────────

    public function test_invalid_page_key_is_rejected(): void
    {
        $dept = $this->makeDepartment();

        $this->expectException(InvalidArgumentException::class);
        $this->service()->replaceDefaults($dept, DepartmentPageAudience::Member, ['not.a.real.page']);
    }

    public function test_system_protected_page_is_rejected_for_both_audiences(): void
    {
        $dept = $this->makeDepartment();

        foreach ([DepartmentPageAudience::Member, DepartmentPageAudience::Leader] as $audience) {
            try {
                $this->service()->replaceDefaults($dept, $audience, [self::PROTECTED_PAGE]);
                $this->fail("SYSTEM_PROTECTED page must be rejected for [{$audience->value}]");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('not eligible', $e->getMessage());
            }
        }

        $this->assertSame(0, DepartmentPageDefault::count());
    }

    public function test_no_system_protected_catalog_entry_is_ever_eligible(): void
    {
        foreach (PageAccessCatalog::all() as $entry) {
            if (! $entry->isProtected()) {
                continue;
            }
            foreach ([DepartmentPageAudience::Member, DepartmentPageAudience::Leader] as $audience) {
                $this->assertFalse(
                    $this->service()->isEligible($entry, $audience),
                    "SYSTEM_PROTECTED [{$entry->key}] must never be eligible for [{$audience->value}]"
                );
            }
        }
    }

    public function test_org_wide_admin_only_page_is_rejected_for_both_audiences(): void
    {
        $dept = $this->makeDepartment();

        foreach ([DepartmentPageAudience::Member, DepartmentPageAudience::Leader] as $audience) {
            try {
                $this->service()->replaceDefaults($dept, $audience, [self::ADMIN_ONLY_GLOBAL]);
                $this->fail("Org-wide ADMIN_ONLY page must be rejected for [{$audience->value}]");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('not eligible', $e->getMessage());
            }
        }
    }

    public function test_department_scoped_admin_only_page_is_leader_only(): void
    {
        $dept = $this->makeDepartment();

        // Allowed for leader…
        $this->service()->replaceDefaults($dept, DepartmentPageAudience::Leader, [self::LEADER_PAGE]);
        $this->assertSame([self::LEADER_PAGE], $this->service()->getLeaderDefaults($dept));

        // …but never for member.
        $this->expectException(InvalidArgumentException::class);
        $this->service()->replaceDefaults($dept, DepartmentPageAudience::Member, [self::LEADER_PAGE]);
    }

    public function test_eligible_sets_match_the_documented_rule(): void
    {
        $memberKeys = $this->service()->eligibleKeys(DepartmentPageAudience::Member);
        $leaderKeys = $this->service()->eligibleKeys(DepartmentPageAudience::Leader);

        // Member set is exactly the SAFE_DELEGATABLE entries.
        $safe = array_map(
            fn ($e) => $e->key,
            array_filter(PageAccessCatalog::all(), fn ($e) => $e->riskLevel === PageAccessRiskLevel::SafeDelegatable)
        );
        $this->assertEqualsCanonicalizing(array_values($safe), $memberKeys);

        // Leader set is a strict superset, adding only department-scoped entries.
        $this->assertEmpty(array_diff($memberKeys, $leaderKeys), 'leader set must include every member-eligible page');
        foreach (array_diff($leaderKeys, $memberKeys) as $extra) {
            $entry = PageAccessCatalog::find($extra);
            $this->assertTrue($entry->departmentScoped, "leader-only extra [{$extra}] must be department_scoped");
            $this->assertFalse($entry->isProtected());
        }
    }

    // ── 7/8/9. Who receives what ─────────────────────────────────────────────

    public function test_member_receives_only_member_defaults(): void
    {
        $dept = $this->makeDepartment();
        $member = User::factory()->create(['role' => 'volunteer', 'is_active' => true]);
        $this->makeMember($member, $dept);

        $this->service()->replaceDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE]);
        $this->service()->replaceDefaults($dept, DepartmentPageAudience::Leader, [self::LEADER_PAGE]);

        $effective = $this->service()->getEffectiveDepartmentDefaults($member, $dept);

        $this->assertSame([self::MEMBER_PAGE], $effective);
        $this->assertNotContains(self::LEADER_PAGE, $effective, 'ordinary member must NOT receive leader-only defaults');
    }

    public function test_leader_receives_member_plus_leader_defaults(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $dept = $this->makeDepartment(['leader_id' => $leader->id]);

        $this->service()->replaceDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE]);
        $this->service()->replaceDefaults($dept, DepartmentPageAudience::Leader, [self::LEADER_PAGE]);

        $effective = $this->service()->getEffectiveDepartmentDefaults($leader, $dept);

        $this->assertContains(self::MEMBER_PAGE, $effective, 'leader must ALSO receive member defaults (additive)');
        $this->assertContains(self::LEADER_PAGE, $effective);
        $this->assertCount(2, $effective);
    }

    public function test_leadership_is_resolved_from_leader_id_not_role_slug(): void
    {
        // A manager-sounding role that does NOT lead this department.
        $pretender = User::factory()->create(['role' => 'department_manager', 'is_active' => true]);
        $dept = $this->makeDepartment(); // leader_id is null
        $this->makeMember($pretender, $dept);

        $this->service()->replaceDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE]);
        $this->service()->replaceDefaults($dept, DepartmentPageAudience::Leader, [self::LEADER_PAGE]);

        $effective = $this->service()->getEffectiveDepartmentDefaults($pretender, $dept);

        $this->assertSame([self::MEMBER_PAGE], $effective, 'role slug must never confer leader defaults');
    }

    public function test_unrelated_user_receives_nothing(): void
    {
        $dept = $this->makeDepartment();
        $outsider = User::factory()->create(['role' => 'volunteer', 'is_active' => true]);

        $this->service()->replaceDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE]);

        $this->assertSame([], $this->service()->getEffectiveDepartmentDefaults($outsider, $dept));
    }

    // ── 10. No cross-department leakage ──────────────────────────────────────

    public function test_department_a_defaults_do_not_leak_to_department_b(): void
    {
        $deptA = $this->makeDepartment();
        $deptB = $this->makeDepartment();
        $memberB = User::factory()->create(['role' => 'volunteer', 'is_active' => true]);
        $this->makeMember($memberB, $deptB);

        $this->service()->replaceDefaults($deptA, DepartmentPageAudience::Member, [self::MEMBER_PAGE]);

        $this->assertSame([], $this->service()->getMemberDefaults($deptB));
        $this->assertSame([], $this->service()->getEffectiveDepartmentDefaults($memberB, $deptB));
    }

    // ── 11. Archived departments ─────────────────────────────────────────────

    public function test_archived_department_keeps_its_defaults(): void
    {
        $dept = $this->makeDepartment();
        $this->service()->replaceDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE]);

        $dept->update(['status' => 'archived']);

        $this->assertSame([self::MEMBER_PAGE], $this->service()->getMemberDefaults($dept->fresh()),
            'archiving is not deletion — stored defaults must survive');
        $this->assertDatabaseHas('department_page_defaults', [
            'department_id' => $dept->id, 'page_key' => self::MEMBER_PAGE,
        ]);
    }

    public function test_archived_department_defaults_still_resolve_for_its_leader(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $dept = $this->makeDepartment(['leader_id' => $leader->id]);
        $this->service()->replaceDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE]);

        $dept->update(['status' => 'archived']);

        $this->assertSame([self::MEMBER_PAGE], $this->service()->getEffectiveDepartmentDefaults($leader, $dept->fresh()));
    }

    // ── 12. Deletion cascade ─────────────────────────────────────────────────

    public function test_deleting_a_department_removes_its_defaults(): void
    {
        $dept = $this->makeDepartment();
        $this->service()->replaceDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE]);
        $this->assertSame(1, DepartmentPageDefault::where('department_id', $dept->id)->count());

        $deptId = $dept->id;
        $dept->delete();

        $this->assertSame(0, DepartmentPageDefault::where('department_id', $deptId)->count());
    }

    // ── 13/14. Bulk replace: transactional + idempotent ──────────────────────

    public function test_bulk_replace_is_transactional_and_aborts_with_zero_writes(): void
    {
        $dept = $this->makeDepartment();
        $this->service()->replaceDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE]);

        try {
            $this->service()->replaceDefaults($dept, DepartmentPageAudience::Member, [
                self::MEMBER_PAGE_2, self::PROTECTED_PAGE,
            ]);
            $this->fail('expected rejection');
        } catch (InvalidArgumentException $e) {
            // expected
        }

        $this->assertSame([self::MEMBER_PAGE], $this->service()->getMemberDefaults($dept),
            'a rejected bulk replace must leave the previous set untouched');
        $this->assertSame(1, DepartmentPageDefault::where('department_id', $dept->id)->count());
    }

    public function test_repeated_identical_update_is_idempotent(): void
    {
        $dept = $this->makeDepartment();
        $keys = [self::MEMBER_PAGE, self::MEMBER_PAGE_2];

        $first = $this->service()->replaceDefaults($dept, DepartmentPageAudience::Member, $keys);
        $rowIds = DepartmentPageDefault::where('department_id', $dept->id)->orderBy('id')->pluck('id')->all();

        $second = $this->service()->replaceDefaults($dept, DepartmentPageAudience::Member, $keys);

        $this->assertSame($first, $second);
        $this->assertSame(2, DepartmentPageDefault::where('department_id', $dept->id)->count());
        $this->assertSame($rowIds, DepartmentPageDefault::where('department_id', $dept->id)->orderBy('id')->pluck('id')->all(),
            'unchanged rows must not be churned/recreated');
    }

    public function test_replace_removes_keys_no_longer_present(): void
    {
        $dept = $this->makeDepartment();
        $this->service()->replaceDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE, self::MEMBER_PAGE_2]);

        $this->service()->replaceDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE_2]);

        $this->assertSame([self::MEMBER_PAGE_2], $this->service()->getMemberDefaults($dept));
    }

    public function test_replace_with_empty_array_clears_that_audience_only(): void
    {
        $dept = $this->makeDepartment();
        $this->service()->replaceDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE]);
        $this->service()->replaceDefaults($dept, DepartmentPageAudience::Leader, [self::LEADER_PAGE]);

        $this->service()->replaceDefaults($dept, DepartmentPageAudience::Member, []);

        $this->assertSame([], $this->service()->getMemberDefaults($dept));
        $this->assertSame([self::LEADER_PAGE], $this->service()->getLeaderDefaults($dept), 'other audience untouched');
    }

    // ── 15. API authorization ────────────────────────────────────────────────

    public function test_super_admin_can_read_and_configure(): void
    {
        $actor = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $dept = $this->makeDepartment();

        $this->actingAs($actor)->getJson("/api/admin/departments/{$dept->id}/page-access")
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'department' => ['id', 'name', 'status'],
                    'member_defaults', 'leader_defaults',
                    'eligible' => ['member', 'leader'],
                ],
            ]);

        $this->actingAs($actor)->putJson("/api/admin/departments/{$dept->id}/page-access", [
            'member_defaults' => [self::MEMBER_PAGE],
            'leader_defaults' => [self::LEADER_PAGE],
        ])->assertOk();

        $this->assertSame([self::MEMBER_PAGE], $this->service()->getMemberDefaults($dept));
        $this->assertSame([self::LEADER_PAGE], $this->service()->getLeaderDefaults($dept));
    }

    public function test_tech_admin_can_configure(): void
    {
        $actor = User::factory()->create(['role' => 'tech_admin', 'is_active' => true]);
        $dept = $this->makeDepartment();

        $this->actingAs($actor)->putJson("/api/admin/departments/{$dept->id}/page-access", [
            'member_defaults' => [self::MEMBER_PAGE],
            'leader_defaults' => [],
        ])->assertOk();
    }

    public function test_ordinary_admin_cannot_configure(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $dept = $this->makeDepartment();

        $this->actingAs($actor)->getJson("/api/admin/departments/{$dept->id}/page-access")->assertStatus(403);
        $this->actingAs($actor)->putJson("/api/admin/departments/{$dept->id}/page-access", [
            'member_defaults' => [], 'leader_defaults' => [],
        ])->assertStatus(403);
    }

    public function test_department_leader_cannot_configure_their_own_department_defaults(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $dept = $this->makeDepartment(['leader_id' => $leader->id]);

        $this->actingAs($leader)->putJson("/api/admin/departments/{$dept->id}/page-access", [
            'member_defaults' => [self::MEMBER_PAGE], 'leader_defaults' => [],
        ])->assertStatus(403);

        $this->assertSame(0, DepartmentPageDefault::count());
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $dept = $this->makeDepartment();

        $this->getJson("/api/admin/departments/{$dept->id}/page-access")->assertStatus(401);
    }

    public function test_api_rejects_protected_page_with_422_and_writes_nothing(): void
    {
        $actor = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $dept = $this->makeDepartment();

        $this->actingAs($actor)->putJson("/api/admin/departments/{$dept->id}/page-access", [
            'member_defaults' => [self::MEMBER_PAGE],
            'leader_defaults' => [self::PROTECTED_PAGE],
        ])->assertStatus(422);

        $this->assertSame(0, DepartmentPageDefault::count(),
            'an invalid leader key must not leave a half-applied member set behind');
    }

    // ── 16/17/18. Non-regression ─────────────────────────────────────────────

    public function test_protected_authority_rules_remain_intact(): void
    {
        $this->assertTrue(\App\Support\ProtectedAuthority::isProtectedRoleName('super_admin'));
        $this->assertFalse(\App\Support\ProtectedAuthority::isProtectedRoleName('tech_admin'));
    }

    public function test_department_access_service_behavior_does_not_regress(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $dept = $this->makeDepartment(['leader_id' => $leader->id]);
        $other = $this->makeDepartment();

        $access = app(\App\Services\DepartmentAccessService::class);

        $this->assertTrue($access->canManageDepartment($leader, $dept->id));
        $this->assertFalse($access->canManageDepartment($leader, $other->id));
        $this->assertTrue($access->getLedDepartmentIds($leader)->contains($dept->id));
    }

    public function test_page_access_catalog_behavior_does_not_regress(): void
    {
        $this->assertNotNull(PageAccessCatalog::find(self::MEMBER_PAGE));
        $this->assertNotNull(PageAccessCatalog::find(self::LEADER_PAGE));
        $this->assertTrue(PageAccessCatalog::find(self::PROTECTED_PAGE)->isProtected());
        $this->assertNull(PageAccessCatalog::find('does.not.exist'));
    }

    public function test_storing_defaults_creates_an_audit_trail(): void
    {
        $dept = $this->makeDepartment();

        $before = DB::table('audit_logs')->count();
        $this->service()->replaceDefaults($dept, DepartmentPageAudience::Member, [self::MEMBER_PAGE]);

        $this->assertGreaterThan($before, DB::table('audit_logs')->count(),
            'DepartmentPageDefault uses Auditable — writes must be recorded');
    }
}
