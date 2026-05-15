<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\DepartmentSection;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // ── RBAC: Roles, Permissions, Mappings ────────────────────────────────
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
            DemoUserSeeder::class,
        ]);

        // ── Users ────────────────────────────────────────────────────────────
        $admin = User::where('email', 'admin@emc.test')->first()
            ?? User::where('role', 'admin')->first();

        User::updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'name'     => 'Test User',
                'password' => Hash::make('password'),
                'role'     => 'student',
            ]
        );

        // ── Departments ───────────────────────────────────────────────────────
        $departments = [
            ['name' => 'الإدارة العليا',           'slug' => 'executive-management',    'color' => '#1a1a2e', 'icon' => 'crown',         'sort_order' => 1],
            ['name' => 'البرامج والمسارات',         'slug' => 'programs-tracks',         'color' => '#16213e', 'icon' => 'academic-cap',  'sort_order' => 2],
            ['name' => 'التشغيل والعمليات',         'slug' => 'operations',              'color' => '#0f3460', 'icon' => 'cog',           'sort_order' => 3],
            ['name' => 'التسويق والإعلام',          'slug' => 'marketing-media',         'color' => '#e94560', 'icon' => 'megaphone',     'sort_order' => 4],
            ['name' => 'الشراكات والعلاقات',        'slug' => 'partnerships',            'color' => '#533483', 'icon' => 'handshake',     'sort_order' => 5],
            ['name' => 'المالية',                   'slug' => 'finance',                 'color' => '#2d6a4f', 'icon' => 'currency-dollar','sort_order' => 6],
            ['name' => 'التقنية',                   'slug' => 'technology',              'color' => '#1b4332', 'icon' => 'chip',          'sort_order' => 7],
            ['name' => 'الموارد البشرية',           'slug' => 'human-resources',         'color' => '#6d6875', 'icon' => 'users',         'sort_order' => 8],
            ['name' => 'المجتمع والصحة',            'slug' => 'community-health',        'color' => '#b5838d', 'icon' => 'heart',         'sort_order' => 9],
            ['name' => 'الجودة والحوكمة',           'slug' => 'quality-governance',      'color' => '#457b9d', 'icon' => 'shield-check',  'sort_order' => 10],
        ];

        foreach ($departments as $dept) {
            Department::updateOrCreate(
                ['slug' => $dept['slug']],
                array_merge($dept, ['leader_id' => $admin->id, 'status' => 'active'])
            );
        }

        // ── Sections for "Programs & Tracks" department ───────────────────────
        $programs = Department::where('slug', 'programs-tracks')->first();
        if ($programs) {
            $sections = [
                ['name' => 'القسم الأكاديمي',        'sort_order' => 1],
                ['name' => 'مسار الذكاء الاصطناعي', 'sort_order' => 2],
                ['name' => 'معهد اللغات',            'sort_order' => 3],
                ['name' => 'برنامج رواد',            'sort_order' => 4],
                ['name' => 'تطوير البرامج',          'sort_order' => 5],
            ];
            foreach ($sections as $section) {
                DepartmentSection::updateOrCreate(
                    ['department_id' => $programs->id, 'name' => $section['name']],
                    array_merge($section, ['status' => 'active', 'department_id' => $programs->id])
                );
            }
        }
    }
}
