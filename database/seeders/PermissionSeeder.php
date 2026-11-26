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
                // Rows written (labels, descriptions) by their own migration: never overwrite them here.
                if (in_array($name, [Permissions::TICKETS_REVIEW_COMPLETION, Permissions::TICKETS_REVIEW_PARTICIPATED, Permissions::CONSULTANT_APPLICATIONS_VIEW, Permissions::HR_TASKS_VIEW, Permissions::TASKS_CREATE, Permissions::TASKS_ASSIGN, Permissions::TASKS_UPDATE], true)) {
                    Permission::firstOrCreate(['name' => $name], ['display_name' => $this->displayName($name), 'group' => $group, 'description' => null]);
                    $count++;

                    continue;
                }

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
