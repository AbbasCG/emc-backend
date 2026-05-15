<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $grouped = Permissions::grouped();
        $count   = 0;

        foreach ($grouped as $group => $names) {
            foreach ($names as $name) {
                Permission::updateOrCreate(
                    ['name' => $name],
                    [
                        'display_name' => $this->displayName($name),
                        'group'        => $group,
                        'description'  => null,
                    ],
                );
                $count++;
            }
        }

        $this->command->info("✓ {$count} permissions seeded.");
    }

    private function displayName(string $name): string
    {
        return ucwords(str_replace('_', ' ', $name));
    }
}
