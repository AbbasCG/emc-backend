<?php

use App\Support\Permissions;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes sure every canonical system role (RoleSeeder::ROLES) has a row in `roles` in EVERY environment that runs migrations.
 *
 * Why: `roles` rows are created only by RoleSeeder, which no deploy step runs. Role assignment (PUT /admin/users/{id},
 * POST /admin/users, PUT /admin/users/{id}/role) validates against that table (Role::assignableSlugs()), so a role the code and
 * the frontend already offer, but the seeder added after the last manual seeding (department_member 2026-10-25, the three newest
 * managers 2026-09-06, ai_manager earlier), is refused with 422 «The selected role is invalid» on that environment.
 *
 * Deliberately INSERT-ONLY (never update): a row an admin already customised (display_name / description) is left as it is, and
 * `role_page_defaults`, users and departments are never touched. This is the migration form of `roles:sync-system --apply`,
 * with ONE addition that the command lacks: grants for the rows it creates.
 *
 * Why grants: User::basePermissions() uses the DB grants of a role when its row exists and Permissions::forRole() ONLY when the
 * row is missing. A role with no row therefore already works through that static list; creating an EMPTY or SHORTER row would
 * silently strip permissions from every existing holder of the role (e.g. the volunteers already converted to department_member).
 * So the migration is parity-safe: for each role it creates, it first makes sure EVERY permission name in
 * Permissions::forRole(name) has a row in `permissions` (idempotent insert by name with the PermissionSeeder defaults for
 * display_name and group; an existing row, including its label_ar / description_ar, is never touched; nothing is granted to any
 * other role), then inserts the role row and exactly those grants, all in ONE transaction per role. A crash can therefore never
 * leave a role row with missing grants (a rerun would skip an existing row and never repair it). The effective permissions of the
 * role's holders are identical before and after; only role assignment starts to work.
 *
 * Skipped under the PHPUnit run: a large part of the suite starts from an empty `roles` table on purpose and creates its own
 * rows; the logic is exercised directly through ensureRoles() (tests/Feature/EnsureCanonicalSystemRolesMigrationTest).
 *
 * down() is a no-op on purpose: the rows may already be referenced by users and grants.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $this->ensureRoles();
    }

    /** @return list<string> names of the roles inserted by this call */
    public function ensureRoles(): array
    {
        if (! Schema::hasTable('roles')) {
            return [];
        }

        $existing = DB::table('roles')->pluck('name')->all();
        $now = now();
        $created = [];

        foreach (RoleSeeder::ROLES as $role) {
            if (in_array($role['name'], $existing, true)) {
                continue;
            }

            DB::transaction(function () use ($role, $now) {
                $permissionIds = $this->ensureCatalogRows(Permissions::forRole($role['name']), $now);

                $roleId = DB::table('roles')->insertGetId($role + ['created_at' => $now, 'updated_at' => $now]);

                if ($permissionIds !== [] && Schema::hasTable('permission_role')) {
                    DB::table('permission_role')->insert(array_map(
                        fn (int $permissionId) => ['permission_id' => $permissionId, 'role_id' => $roleId],
                        $permissionIds,
                    ));
                }
            });
            $created[] = $role['name'];
        }

        return $created;
    }

    /**
     * Makes sure every given permission name has a `permissions` row and returns their ids. Existing rows are left exactly as
     * they are; new rows are catalog entries only (same display_name / group as PermissionSeeder).
     *
     * @param  array<int, string>  $names
     * @return list<int>
     */
    private function ensureCatalogRows(array $names, $now): array
    {
        $names = array_values(array_unique($names));
        if ($names === [] || ! Schema::hasTable('permissions')) {
            return [];
        }

        $groupOf = [];
        foreach (Permissions::grouped() as $group => $groupNames) {
            foreach ($groupNames as $name) {
                $groupOf[$name] = $group;
            }
        }

        $ids = DB::table('permissions')->whereIn('name', $names)->pluck('id', 'name')->all();

        foreach ($names as $name) {
            if (isset($ids[$name])) {
                continue;
            }

            $ids[$name] = DB::table('permissions')->insertGetId([
                'name' => $name,
                'display_name' => ucwords(str_replace('_', ' ', $name)),
                'group' => $groupOf[$name] ?? 'general',
                'description' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return array_map('intval', array_values($ids));
    }

    public function down(): void
    {
        // Intentionally empty — see the class comment.
    }
};
