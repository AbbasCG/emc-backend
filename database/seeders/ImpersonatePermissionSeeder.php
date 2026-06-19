<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

/**
 * Opt-in seeder — grants users.impersonate to the super_admin role.
 *
 * NOT wired into DatabaseSeeder::run(), so a plain `php artisan db:seed`
 * leaves the permission unassigned (OFF by default). Run explicitly:
 *
 *   php artisan db:seed --class=ImpersonatePermissionSeeder
 *
 * Uses syncWithoutDetaching internally, so no existing permissions are touched.
 * To revoke, use the role/permission management UI or detach via tinker.
 */
class ImpersonatePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $role = Role::where('name', 'super_admin')->first();

        if (! $role) {
            $this->command->warn('super_admin role not found — run RoleSeeder first.');
            return;
        }

        $role->grantPermission(Permissions::IMPERSONATE_USERS);

        $this->command->info('✓ users.impersonate granted to super_admin role.');
        $this->command->info('  Run php artisan optimize:clear to flush the permission cache.');
    }
}
