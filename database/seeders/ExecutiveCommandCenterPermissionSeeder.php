<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

/**
 * Opt-in seeder — grants executive.command_center.view to the super_admin role ONLY.
 *
 * NOT wired into DatabaseSeeder::run(). Run explicitly, once:
 *
 *   php artisan db:seed --class=ExecutiveCommandCenterPermissionSeeder
 *
 * Idempotent (syncWithoutDetaching) and touches no other permission. tech_admin and every
 * other role stay without it until an owner grants it through the role/permission UI or a
 * per-user permission assignment; to revoke, do the same in reverse.
 */
class ExecutiveCommandCenterPermissionSeeder extends Seeder
{
    public function run(): void
    {
        Permission::query()->firstOrCreate(
            ['name' => Permissions::EXECUTIVE_COMMAND_CENTER_VIEW],
            ['display_name' => 'Executive Command Center View', 'group' => 'executive', 'description' => null],
        );

        $role = Role::where('name', 'super_admin')->first();
        if (! $role) {
            $this->command?->warn('super_admin role not found — run RoleSeeder first.');

            return;
        }

        $role->grantPermission(Permissions::EXECUTIVE_COMMAND_CENTER_VIEW);

        $this->command?->info('✓ executive.command_center.view granted to super_admin.');
        $this->command?->info('  Run php artisan optimize:clear to flush the permission cache.');
    }
}
