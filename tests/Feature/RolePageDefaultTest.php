<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePageDefault;
use App\Models\User;
use App\Services\DepartmentPageDefaultService;
use App\Services\RolePageDefaultService;
use App\Support\PageAccess\DepartmentPageAudience;
use App\Support\PageAccess\PageAccessCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Phase 2B — Role default PAGE ACCESS.
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION: nothing here asserts a user may
 * perform a business action. These tests cover which pages a role hands out by
 * default, who may configure them, and that no SYSTEM_PROTECTED page can ever
 * become a role default.
 */
class RolePageDefaultTest extends TestCase
{
    use RefreshDatabase;

    private const SAFE_PAGE = 'organizational_departments.members';       // SAFE_DELEGATABLE
    private const ADMIN_PAGE = 'finance.transactions';                    // ADMIN_ONLY (the finance_manager use case)
    private const ADMIN_PAGE_2 = 'finance.manual_payments';               // ADMIN_ONLY
    private const PROTECTED_PAGE = 'administration.roles';                // SYSTEM_PROTECTED
    // Phase 2G.1C: ai_command_center is now ADMIN_ONLY (admin-tier backend
    // gate). platform_scale has no backend endpoint at all and remains
    // SYSTEM_PROTECTED, so it is the correct fixture for "root-only" here.
    private const PROTECTED_PAGE_2 = 'technical_system.platform_scale'; // SYSTEM_PROTECTED

    private function service(): RolePageDefaultService
    {
        return app(RolePageDefaultService::class);
    }

    private function makeRole(string $name = 'finance_manager'): Role
    {
        return Role::firstOrCreate(
            ['name' => $name],
            ['display_name' => ucfirst($name), 'is_system' => true],
        );
    }

    private function makeSuperAdminRole(): Role
    {
        return Role::firstOrCreate(
            ['name' => 'super_admin'],
            ['display_name' => 'Super Admin', 'is_system' => true],
        );
    }

    /** Give an admin-tier actor the manage_roles permission its role needs. */
    private function grantManageRoles(User $user): void
    {
        $role = Role::firstOrCreate(['name' => $user->role], ['display_name' => $user->role, 'is_system' => true]);
        $perm = Permission::firstOrCreate(['name' => 'manage_roles'], ['display_name' => 'Manage Roles', 'group' => 'users']);
        $role->permissions()->syncWithoutDetaching([$perm->id]);
    }

    // ── 1. Storage ───────────────────────────────────────────────────────────

    public function test_role_default_page_can_be_stored(): void
    {
        $role = $this->makeRole();

        $this->service()->replaceDefaults($role, [self::ADMIN_PAGE]);

        $this->assertSame([self::ADMIN_PAGE], $this->service()->getDefaultsForRole($role));
        $this->assertDatabaseHas('role_page_defaults', ['role_id' => $role->id, 'page_key' => self::ADMIN_PAGE]);
    }

    public function test_admin_only_pages_are_assignable_so_the_finance_manager_use_case_works(): void
    {
        $role = $this->makeRole('finance_manager');

        $stored = $this->service()->replaceDefaults($role, [self::ADMIN_PAGE, self::ADMIN_PAGE_2]);

        $this->assertCount(2, $stored);
        $this->assertContains(self::ADMIN_PAGE, $stored);
        $this->assertContains(self::ADMIN_PAGE_2, $stored);
    }

    public function test_is_page_default_for_role(): void
    {
        $role = $this->makeRole();
        $this->service()->replaceDefaults($role, [self::SAFE_PAGE]);

        $this->assertTrue($this->service()->isPageDefaultForRole($role, self::SAFE_PAGE));
        $this->assertFalse($this->service()->isPageDefaultForRole($role, self::ADMIN_PAGE));
    }

    // ── 2. Duplicates ────────────────────────────────────────────────────────

    public function test_duplicate_default_is_prevented_at_database_level(): void
    {
        $role = $this->makeRole();
        RolePageDefault::create(['role_id' => $role->id, 'page_key' => self::SAFE_PAGE]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        RolePageDefault::create(['role_id' => $role->id, 'page_key' => self::SAFE_PAGE]);
    }

    public function test_repeated_keys_in_one_request_are_deduped(): void
    {
        $role = $this->makeRole();

        $this->service()->replaceDefaults($role, [self::SAFE_PAGE, self::SAFE_PAGE, self::SAFE_PAGE]);

        $this->assertSame(1, RolePageDefault::where('role_id', $role->id)->count());
    }

    // ── 3/4. Validation & eligibility ────────────────────────────────────────

    public function test_invalid_page_key_is_rejected(): void
    {
        $role = $this->makeRole();

        $this->expectException(InvalidArgumentException::class);
        $this->service()->replaceDefaults($role, ['not.a.real.page']);
    }

    public function test_system_protected_page_is_rejected_for_ordinary_role(): void
    {
        $role = $this->makeRole();

        try {
            $this->service()->replaceDefaults($role, [self::PROTECTED_PAGE]);
            $this->fail('SYSTEM_PROTECTED page must be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('SYSTEM_PROTECTED', $e->getMessage());
        }

        $this->assertSame(0, RolePageDefault::count());
    }

    public function test_system_protected_page_is_rejected_even_for_the_super_admin_role(): void
    {
        $superRole = $this->makeSuperAdminRole();

        $this->expectException(InvalidArgumentException::class);
        $this->service()->replaceDefaults($superRole, [self::PROTECTED_PAGE_2]);
    }

    public function test_no_system_protected_catalog_entry_is_ever_eligible(): void
    {
        foreach (PageAccessCatalog::all() as $entry) {
            if ($entry->isProtected()) {
                $this->assertFalse($this->service()->isEligible($entry),
                    "SYSTEM_PROTECTED [{$entry->key}] must never be eligible as a role default");
            }
        }
    }

    public function test_eligible_set_is_exactly_the_non_protected_catalog(): void
    {
        $eligible = $this->service()->eligibleKeys();
        $expected = array_map(fn ($e) => $e->key, array_filter(PageAccessCatalog::all(), fn ($e) => ! $e->isProtected()));

        $this->assertEqualsCanonicalizing(array_values($expected), $eligible);
        $this->assertCount(count(PageAccessCatalog::all()) - 8, $eligible, 'all 8 SYSTEM_PROTECTED entries must be excluded');
    }

    // ── 5/6. Protected authority ─────────────────────────────────────────────

    public function test_super_admin_role_page_access_is_only_editable_by_super_admin(): void
    {
        $superRole = $this->makeSuperAdminRole();
        $techAdmin = User::factory()->create(['role' => 'tech_admin', 'is_active' => true]);
        $this->grantManageRoles($techAdmin);

        $this->actingAs($techAdmin)->putJson("/api/admin/roles/super_admin/page-access", [
            'page_defaults' => [self::SAFE_PAGE],
        ])->assertStatus(403);

        $this->assertSame(0, RolePageDefault::where('role_id', $superRole->id)->count());
    }

    public function test_super_admin_can_edit_the_super_admin_role_page_access(): void
    {
        $this->makeSuperAdminRole();
        $actor = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($actor)->putJson("/api/admin/roles/super_admin/page-access", [
            'page_defaults' => [self::SAFE_PAGE],
        ])->assertOk();
    }

    public function test_tech_admin_cannot_create_root_equivalent_access(): void
    {
        $role = $this->makeRole('tech_admin');
        $techAdmin = User::factory()->create(['role' => 'tech_admin', 'is_active' => true]);
        $this->grantManageRoles($techAdmin);

        // Every SYSTEM_PROTECTED page is refused, so there is no page capable of
        // conferring root-equivalent access available to assign at all.
        foreach ([self::PROTECTED_PAGE, self::PROTECTED_PAGE_2] as $protected) {
            $this->actingAs($techAdmin)->putJson("/api/admin/roles/tech_admin/page-access", [
                'page_defaults' => [$protected],
            ])->assertStatus(422);
        }

        $this->assertSame(0, RolePageDefault::where('role_id', $role->id)->count());
    }

    public function test_tech_admin_may_manage_ordinary_role_defaults(): void
    {
        $this->makeRole('finance_manager');
        $techAdmin = User::factory()->create(['role' => 'tech_admin', 'is_active' => true]);
        $this->grantManageRoles($techAdmin);

        $this->actingAs($techAdmin)->putJson("/api/admin/roles/finance_manager/page-access", [
            'page_defaults' => [self::ADMIN_PAGE],
        ])->assertOk();
    }

    // ── 7. No cross-role leakage ─────────────────────────────────────────────

    public function test_role_a_defaults_do_not_leak_to_role_b(): void
    {
        $roleA = $this->makeRole('finance_manager');
        $roleB = $this->makeRole('quality_manager');

        $this->service()->replaceDefaults($roleA, [self::ADMIN_PAGE]);

        $this->assertSame([self::ADMIN_PAGE], $this->service()->getDefaultsForRole($roleA));
        $this->assertSame([], $this->service()->getDefaultsForRole($roleB));
    }

    // ── 8/9. Atomic + idempotent ─────────────────────────────────────────────

    public function test_replacing_defaults_is_atomic_and_aborts_with_zero_writes(): void
    {
        $role = $this->makeRole();
        $this->service()->replaceDefaults($role, [self::SAFE_PAGE]);

        try {
            $this->service()->replaceDefaults($role, [self::ADMIN_PAGE, self::PROTECTED_PAGE]);
            $this->fail('expected rejection');
        } catch (InvalidArgumentException $e) {
            // expected
        }

        $this->assertSame([self::SAFE_PAGE], $this->service()->getDefaultsForRole($role),
            'a rejected replace must leave the previous set untouched');
    }

    public function test_repeated_identical_update_is_idempotent(): void
    {
        $role = $this->makeRole();
        $keys = [self::SAFE_PAGE, self::ADMIN_PAGE];

        $first = $this->service()->replaceDefaults($role, $keys);
        $rowIds = RolePageDefault::where('role_id', $role->id)->orderBy('id')->pluck('id')->all();

        $second = $this->service()->replaceDefaults($role, $keys);

        $this->assertSame($first, $second);
        $this->assertSame(2, RolePageDefault::where('role_id', $role->id)->count());
        $this->assertSame($rowIds, RolePageDefault::where('role_id', $role->id)->orderBy('id')->pluck('id')->all(),
            'unchanged rows must not be churned/recreated');
    }

    public function test_replace_removes_keys_no_longer_present(): void
    {
        $role = $this->makeRole();
        $this->service()->replaceDefaults($role, [self::SAFE_PAGE, self::ADMIN_PAGE]);

        $this->service()->replaceDefaults($role, [self::ADMIN_PAGE]);

        $this->assertSame([self::ADMIN_PAGE], $this->service()->getDefaultsForRole($role));
    }

    public function test_replace_with_empty_array_clears_all_defaults(): void
    {
        $role = $this->makeRole();
        $this->service()->replaceDefaults($role, [self::SAFE_PAGE]);

        $this->service()->replaceDefaults($role, []);

        $this->assertSame([], $this->service()->getDefaultsForRole($role));
    }

    // ── 10. Role deletion cascade ────────────────────────────────────────────

    public function test_deleting_a_role_removes_its_page_defaults(): void
    {
        $role = Role::create(['name' => 'temp_role', 'display_name' => 'Temp', 'is_system' => false]);
        $this->service()->replaceDefaults($role, [self::SAFE_PAGE]);
        $roleId = $role->id;

        $role->delete();

        $this->assertSame(0, RolePageDefault::where('role_id', $roleId)->count());
    }

    // ── API surface ──────────────────────────────────────────────────────────

    public function test_super_admin_can_read_role_page_access(): void
    {
        $this->makeRole('finance_manager');
        $actor = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($actor)->getJson('/api/admin/roles/finance_manager/page-access')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'role' => ['id', 'name', 'display_name', 'is_system'],
                    'page_defaults',
                    'eligible' => [['key', 'label_ar', 'category', 'category_label_ar', 'risk_level', 'department_scoped']],
                ],
            ]);
    }

    public function test_admin_without_manage_roles_cannot_configure(): void
    {
        $this->makeRole('finance_manager');
        $actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($actor)->putJson('/api/admin/roles/finance_manager/page-access', [
            'page_defaults' => [self::ADMIN_PAGE],
        ])->assertStatus(403);
    }

    public function test_non_admin_role_cannot_configure(): void
    {
        $this->makeRole('finance_manager');
        $actor = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);

        $this->actingAs($actor)->putJson('/api/admin/roles/finance_manager/page-access', [
            'page_defaults' => [self::ADMIN_PAGE],
        ])->assertStatus(403);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->makeRole('finance_manager');

        $this->getJson('/api/admin/roles/finance_manager/page-access')->assertStatus(401);
    }

    public function test_api_rejects_protected_page_with_422_and_writes_nothing(): void
    {
        $role = $this->makeRole('finance_manager');
        $actor = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

        $this->actingAs($actor)->putJson('/api/admin/roles/finance_manager/page-access', [
            'page_defaults' => [self::ADMIN_PAGE, self::PROTECTED_PAGE],
        ])->assertStatus(422);

        $this->assertSame(0, RolePageDefault::where('role_id', $role->id)->count());
    }

    public function test_storing_defaults_creates_an_audit_trail(): void
    {
        $role = $this->makeRole();

        $before = DB::table('audit_logs')->count();
        $this->service()->replaceDefaults($role, [self::SAFE_PAGE]);

        $this->assertGreaterThan($before, DB::table('audit_logs')->count());
    }

    // ── 11/12/13. Non-regression ─────────────────────────────────────────────

    public function test_existing_role_permissions_behavior_does_not_regress(): void
    {
        $role = $this->makeRole('finance_manager');
        $perm = Permission::firstOrCreate(['name' => 'view_payments'], ['display_name' => 'View Payments', 'group' => 'finance']);

        $role->syncPermissions(['view_payments']);
        $this->assertTrue($role->fresh()->permissions->contains('id', $perm->id));

        // Page defaults and permissions are independent stores: setting one
        // must not touch the other.
        $this->service()->replaceDefaults($role, [self::ADMIN_PAGE]);

        $this->assertTrue($role->fresh()->permissions->contains('id', $perm->id), 'permissions untouched by page defaults');
        $this->assertSame([self::ADMIN_PAGE], $this->service()->getDefaultsForRole($role));

        $role->syncPermissions([]);
        $this->assertSame([self::ADMIN_PAGE], $this->service()->getDefaultsForRole($role),
            'clearing permissions must not clear page defaults');
    }

    public function test_department_page_default_behavior_does_not_regress(): void
    {
        $dept = Department::create([
            'name' => 'Dept', 'name_ar' => 'إدارة', 'slug' => 'role-defaults-regression-'.uniqid(),
            'status' => 'active', 'is_active' => true,
        ]);
        $deptService = app(DepartmentPageDefaultService::class);

        $deptService->replaceDefaults($dept, DepartmentPageAudience::Member, [self::SAFE_PAGE]);

        $this->assertSame([self::SAFE_PAGE], $deptService->getMemberDefaults($dept));

        // Role defaults live in a completely separate store.
        $role = $this->makeRole();
        $this->service()->replaceDefaults($role, [self::ADMIN_PAGE]);

        $this->assertSame([self::SAFE_PAGE], $deptService->getMemberDefaults($dept), 'department defaults untouched');
        $this->assertSame([self::ADMIN_PAGE], $this->service()->getDefaultsForRole($role));
    }

    public function test_page_access_catalog_behavior_does_not_regress(): void
    {
        $this->assertNotNull(PageAccessCatalog::find(self::SAFE_PAGE));
        $this->assertNotNull(PageAccessCatalog::find(self::ADMIN_PAGE));
        $this->assertTrue(PageAccessCatalog::find(self::PROTECTED_PAGE)->isProtected());
        $this->assertNull(PageAccessCatalog::find('does.not.exist'));
    }

    public function test_protected_authority_rules_remain_intact(): void
    {
        $this->assertTrue(\App\Support\ProtectedAuthority::isProtectedRoleName('super_admin'));
        $this->assertFalse(\App\Support\ProtectedAuthority::isProtectedRoleName('tech_admin'));
    }
}
