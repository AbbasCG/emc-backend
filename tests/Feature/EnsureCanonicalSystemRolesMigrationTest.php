<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Services\VolunteerConversionService;
use App\Support\Permissions;
use Database\Seeders\GranularPermissionsSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Production 422 «The selected role is invalid» on PUT /api/admin/users/{id} when moving a volunteer to `department_member`:
 * `roles` rows are only ever created by RoleSeeder, which no deploy step runs, so a role the code and the frontend offer can be
 * missing from an environment's `roles` table, and role assignment validates against that table (Role::assignableSlugs()).
 *
 * The migration 2026_11_29_000001_ensure_canonical_system_roles_exist heals that; its logic is called through ensureRoles()
 * because the migration itself is skipped under PHPUnit (most of the suite starts from an empty `roles` table on purpose).
 */
class EnsureCanonicalSystemRolesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require base_path('database/migrations/2026_11_29_000001_ensure_canonical_system_roles_exist.php');
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function hrDepartment(): Department
    {
        return Department::create(['name' => 'HR', 'name_ar' => 'إدارة الموارد البشرية', 'slug' => 'human-resources', 'status' => 'active', 'is_active' => true]);
    }

    private function putUser(User $actor, User $target, string $role, ?Department $department = null)
    {
        $this->app['auth']->forgetGuards();
        $body = ['name' => $target->name, 'email' => $target->email, 'role' => $role];
        if ($department) {
            $body += ['department' => $department->name_ar, 'department_id' => $department->id];
        }

        return $this->actingAs($actor)->putJson("/api/admin/users/{$target->id}", $body);
    }

    public function test_the_migration_is_a_no_op_under_phpunit_so_the_suite_keeps_its_empty_roles_table(): void
    {
        $this->assertSame(0, Role::count());
    }

    public function test_missing_canonical_role_is_refused_with_a_clear_arabic_error_next_to_the_field(): void
    {
        $this->seed(RoleSeeder::class);
        Role::where('name', 'department_member')->delete();
        $volunteer = User::factory()->create(['role' => 'volunteer']);

        $res = $this->putUser($this->superAdmin(), $volunteer, 'department_member', $this->hrDepartment());

        $res->assertStatus(422)->assertJsonValidationErrors(['role']);
        $message = $res->json('errors.role.0');
        $this->assertStringContainsString('department_member', $message);
        $this->assertStringContainsString('php artisan migrate', $message);
        $this->assertStringNotContainsString('The selected role is invalid', $message);
        $this->assertSame('volunteer', $volunteer->fresh()->role, 'a refused change stores nothing');
    }

    public function test_unknown_role_is_refused_with_a_generic_arabic_message(): void
    {
        $this->seed(RoleSeeder::class);
        $volunteer = User::factory()->create(['role' => 'volunteer']);

        $res = $this->putUser($this->superAdmin(), $volunteer, 'definitely_not_a_role');

        $res->assertStatus(422)->assertJsonValidationErrors(['role']);
        $this->assertSame('الدور المختار غير موجود في النظام. اختر دورًا من القائمة.', $res->json('errors.role.0'));
        $this->assertSame('volunteer', $volunteer->fresh()->role);
    }

    public function test_role_slug_matching_stays_exact_so_casing_is_not_normalised_server_side(): void
    {
        $this->seed(RoleSeeder::class);
        $volunteer = User::factory()->create(['role' => 'volunteer']);

        $this->putUser($this->superAdmin(), $volunteer, 'Department_Member')->assertStatus(422)->assertJsonValidationErrors(['role']);
        $this->assertSame('volunteer', $volunteer->fresh()->role);
    }

    public function test_the_same_clear_message_is_returned_by_create_and_by_the_dedicated_role_endpoint(): void
    {
        $this->seed(RoleSeeder::class);
        Role::where('name', 'department_member')->delete();
        $actor = $this->superAdmin();
        $volunteer = User::factory()->create(['role' => 'volunteer']);

        $this->app['auth']->forgetGuards();
        $create = $this->actingAs($actor)->postJson('/api/admin/users', ['name' => 'N', 'email' => 'new-member@example.test', 'role' => 'department_member']);
        $create->assertStatus(422)->assertJsonValidationErrors(['role']);
        $this->assertStringContainsString('php artisan migrate', $create->json('errors.role.0'));

        $this->app['auth']->forgetGuards();
        $assign = $this->actingAs($actor)->putJson("/api/admin/users/{$volunteer->id}/role", ['role' => 'department_member']);
        $assign->assertStatus(422)->assertJsonValidationErrors(['role']);
        $this->assertStringContainsString('php artisan migrate', $assign->json('errors.role.0'));
    }

    public function test_after_the_migration_logic_the_move_to_department_member_succeeds_with_its_usual_side_effects(): void
    {
        $this->seed(RoleSeeder::class);
        Role::where('name', 'department_member')->delete();
        $hr = $this->hrDepartment();
        $volunteer = User::factory()->create(['role' => 'volunteer']);
        $actor = $this->superAdmin();

        $this->putUser($actor, $volunteer, 'department_member', $hr)->assertStatus(422);

        $this->assertSame(['department_member'], $this->migration()->ensureRoles());

        $this->putUser($actor, $volunteer, 'department_member', $hr)->assertOk()->assertJsonPath('data.role', 'department_member');
        $fresh = $volunteer->fresh();
        $this->assertSame('department_member', $fresh->role);
        $this->assertSame([$hr->id], $fresh->departments()->pluck('departments.id')->all());
        $this->assertNull($hr->fresh()->leader_id, 'a department member never becomes the department leader');
    }

    public function test_empty_roles_table_gets_every_canonical_role_once_and_a_rerun_changes_nothing(): void
    {
        $migration = $this->migration();

        $first = $migration->ensureRoles();
        $this->assertCount(count(RoleSeeder::ROLES), $first);
        $this->assertSame(count(RoleSeeder::ROLES), Role::count());
        $this->assertTrue(Role::where('name', 'department_member')->where('is_system', true)->exists());

        $this->assertSame([], $migration->ensureRoles());
        $this->assertSame(count(RoleSeeder::ROLES), Role::count());
        foreach (RoleSeeder::ROLES as $role) {
            $this->assertContains($role['name'], Role::assignableSlugs());
        }
    }

    public function test_an_existing_customised_role_and_its_grants_are_never_touched(): void
    {
        $this->seed(PermissionSeeder::class);
        $custom = Role::create(['name' => 'hr_manager', 'display_name' => 'My HR title', 'description' => 'edited by an admin', 'is_system' => true]);
        $custom->grantPermission(Permissions::VIEW_TASKS);
        $before = DB::table('permission_role')->where('role_id', $custom->id)->pluck('permission_id')->sort()->values()->all();

        $created = $this->migration()->ensureRoles();

        $this->assertNotContains('hr_manager', $created);
        $row = $custom->fresh();
        $this->assertSame('My HR title', $row->display_name);
        $this->assertSame('edited by an admin', $row->description);
        $this->assertSame($before, DB::table('permission_role')->where('role_id', $custom->id)->pluck('permission_id')->sort()->values()->all());
    }

    public function test_creating_the_missing_row_does_not_change_the_effective_permissions_of_its_existing_holders(): void
    {
        // A role WITHOUT a row works through Permissions::forRole(); an EMPTY row would strip those permissions from every
        // existing holder. The migration therefore grants exactly forRole() to the rows it creates.
        $this->seed([PermissionSeeder::class, GranularPermissionsSeeder::class]);
        $this->seed(RoleSeeder::class);
        Role::where('name', 'department_member')->delete();
        $member = User::factory()->create(['role' => 'department_member', 'is_active' => true]);

        $before = $member->basePermissions();
        $this->assertEqualsCanonicalizing(Permissions::forRole('department_member'), $before, 'precondition: static fallback in effect');
        $this->assertNotEmpty($before);

        $this->migration()->ensureRoles();

        $after = User::find($member->id)->basePermissions();
        $this->assertEqualsCanonicalizing($before, $after);
        $this->assertTrue(Role::where('name', 'department_member')->exists());
    }

    public function test_forRole_names_missing_from_the_permissions_table_are_created_and_holders_keep_exactly_their_permissions(): void
    {
        $this->seed([PermissionSeeder::class, GranularPermissionsSeeder::class]);
        $this->seed(RoleSeeder::class);
        Role::where('name', 'department_member')->delete();
        $member = User::factory()->create(['role' => 'department_member', 'is_active' => true]);

        // Simulate an environment whose permissions table lacks some of the names the static list gives the role.
        $missing = [Permissions::WR_CREATE, Permissions::DEPTS_VIEW_OWN, Permissions::USE_AI_ASSISTANT];
        $this->assertSame(3, DB::table('permissions')->whereIn('name', $missing)->delete());
        DB::table('permissions')->where('name', Permissions::VIEW_TASKS)->update(['display_name' => 'Edited by admin']);

        $before = $member->basePermissions();
        $this->assertEqualsCanonicalizing(Permissions::forRole('department_member'), $before, 'precondition: static fallback');

        $this->assertContains('department_member', $this->migration()->ensureRoles());

        $this->assertSame(3, DB::table('permissions')->whereIn('name', $missing)->count(), 'missing catalog rows are created');
        $created = DB::table('permissions')->where('name', Permissions::WR_CREATE)->first();
        $this->assertSame('workshop_requests', $created->group);
        $this->assertSame(ucwords(str_replace('_', ' ', Permissions::WR_CREATE)), $created->display_name);
        $this->assertSame('Edited by admin', DB::table('permissions')->where('name', Permissions::VIEW_TASKS)->value('display_name'), 'existing rows are never touched');

        $after = User::find($member->id)->basePermissions();
        $this->assertEqualsCanonicalizing($before, $after, 'holders keep IDENTICAL effective permissions');
    }

    public function test_with_an_empty_permissions_table_the_catalog_rows_and_all_grants_are_created(): void
    {
        DB::table('permission_role')->delete();
        DB::table('permissions')->delete();
        $this->assertSame(0, DB::table('permissions')->count());
        $student = Role::create(['name' => 'student', 'display_name' => 'Student', 'is_system' => true]);
        $member = User::factory()->create(['role' => 'department_member', 'is_active' => true]);
        $before = $member->basePermissions();
        $this->assertNotEmpty($before);

        $this->migration()->ensureRoles();

        $role = Role::where('name', 'department_member')->first();
        $this->assertEqualsCanonicalizing(
            array_unique(Permissions::forRole('department_member')),
            $role->permissions()->pluck('name')->all(),
        );
        $this->assertEqualsCanonicalizing($before, User::find($member->id)->basePermissions());
        // Catalog rows only: a role that already existed received no grant.
        $this->assertSame(0, DB::table('permission_role')->where('role_id', $student->id)->count());
    }

    public function test_a_failure_while_granting_leaves_no_role_row_and_no_half_state(): void
    {
        $permissionsBefore = DB::table('permissions')->count();
        DB::unprepared("CREATE TRIGGER fail_grant BEFORE INSERT ON permission_role BEGIN SELECT RAISE(ABORT, 'simulated crash'); END");

        try {
            $this->migration()->ensureRoles();
            $this->fail('the simulated failure should have propagated');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('simulated crash', $e->getMessage());
        }

        $this->assertNull(Role::where('name', 'super_admin')->first(), 'no role row without its grants');
        $this->assertSame(0, Role::count());
        $this->assertSame($permissionsBefore, DB::table('permissions')->count(), 'the catalog rows of the failed role roll back with it');

        DB::unprepared('DROP TRIGGER fail_grant');
        $this->assertCount(count(RoleSeeder::ROLES), $this->migration()->ensureRoles(), 'a rerun after the failure completes everything');
        $this->assertSame([], $this->migration()->ensureRoles(), 'and is idempotent afterwards');
    }

    public function test_rerun_is_idempotent_for_roles_grants_and_catalog_rows(): void
    {
        $migration = $this->migration();
        $migration->ensureRoles();
        $counts = [Role::count(), DB::table('permissions')->count(), DB::table('permission_role')->count()];

        $this->assertSame([], $migration->ensureRoles());
        $this->assertSame($counts, [Role::count(), DB::table('permissions')->count(), DB::table('permission_role')->count()]);
    }

    public function test_privileged_role_rules_are_unchanged(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $target = User::factory()->create(['role' => 'volunteer']);

        $this->putUser($admin, $target, 'super_admin')->assertStatus(403);
        $this->assertSame('volunteer', $target->fresh()->role);

        $this->app['auth']->forgetGuards();
        $this->actingAs($admin)->putJson("/api/admin/users/{$target->id}/role", ['role' => 'super_admin'])->assertStatus(403);
        $this->assertSame('volunteer', $target->fresh()->role);
    }

    /**
     * Guard against the same gap reappearing: every role slug the backend itself writes or maps (volunteer conversion, department
     * leader map, the department-manager set) must be a canonical RoleSeeder role, otherwise the migration/sync can never create it.
     */
    public function test_every_role_slug_the_backend_writes_or_maps_is_a_canonical_seeder_role(): void
    {
        $canonical = array_column(RoleSeeder::ROLES, 'name');

        $slugs = array_merge(
            [VolunteerConversionService::ROLE],
            array_values((array) config('department_roles.leader_roles')),
            (array) config('department_roles.privileged_roles'),
        );
        foreach (array_unique($slugs) as $slug) {
            $this->assertContains($slug, $canonical, "role [$slug] is used by the backend but is not in RoleSeeder::ROLES");
        }

        $user = new User();
        foreach (RoleSeeder::ROLES as $role) {
            $user->role = $role['name'];
            if ($user->isDepartmentManager()) {
                $this->assertContains($role['name'], $canonical);
            }
        }
        foreach (['department_manager', 'department_member', 'section_lead', 'ai_manager', 'strategy_planning_manager', 'digital_ambassadors_manager', 'advisors_manager'] as $slug) {
            $this->assertContains($slug, $canonical);
        }
    }
}
