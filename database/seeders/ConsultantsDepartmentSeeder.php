<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

/** «إدارة المستشارين» — تستقبل المقبولين من طلبات الاستشاريين وتديرهم. */
class ConsultantsDepartmentSeeder extends Seeder
{
    public function run(): void
    {
        Department::updateOrCreate(
            ['slug' => 'advisors'],
            [
                'name'       => 'إدارة المستشارين',
                'name_ar'    => 'إدارة المستشارين',
                'name_en'    => 'Advisors & Consultants',
                'color'      => '#0E5A8A',
                'icon'       => 'user-check',
                'sort_order' => 11,
            ],
        );

        $this->command?->info('✓ إدارة المستشارين seeded.');
    }
}
