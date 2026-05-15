<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Master seeder for the complete EMC V3 team structure.
 *
 * Runs all three seeders in the correct order:
 *   1. DepartmentSeeder   — creates/updates the 10 departments
 *   2. TeamMemberSeeder   — creates/updates the 26 team profiles
 *
 * Usage:
 *   php artisan db:seed --class=EMCV3TeamSeeder
 */
class EMCV3TeamSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('EMC V3 Team Seeder starting...');

        $this->call([
            DepartmentSeeder::class,
            TeamMemberSeeder::class,
        ]);

        $this->command->info('EMC V3 Team Seeder complete.');
    }
}
