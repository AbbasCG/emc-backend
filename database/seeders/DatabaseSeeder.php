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
            ['slug' => 'executive-management',  'name' => 'الإدارة العليا',              'name_ar' => 'الإدارة العليا',              'name_en' => 'Executive Management',          'color' => '#1a1a2e', 'icon' => 'crown',          'sort_order' => 1],
            ['slug' => 'programs-tracks',        'name' => 'البرامج والمسارات',           'name_ar' => 'البرامج والمسارات',           'name_en' => 'Programs & Tracks',             'color' => '#16213e', 'icon' => 'academic-cap',   'sort_order' => 2],
            ['slug' => 'operations',             'name' => 'التشغيل والعمليات',           'name_ar' => 'التشغيل والعمليات',           'name_en' => 'Operations',                    'color' => '#0f3460', 'icon' => 'cog',            'sort_order' => 3],
            ['slug' => 'marketing-media',        'name' => 'التسويق والإعلام',            'name_ar' => 'التسويق والإعلام',            'name_en' => 'Marketing & Media',             'color' => '#e94560', 'icon' => 'megaphone',      'sort_order' => 4],
            ['slug' => 'partnerships',           'name' => 'الشراكات والعلاقات',          'name_ar' => 'الشراكات والعلاقات',          'name_en' => 'Partnerships & Public Relations','color' => '#533483', 'icon' => 'handshake',     'sort_order' => 5],
            ['slug' => 'finance',                'name' => 'المالية',                     'name_ar' => 'المالية',                     'name_en' => 'Finance',                       'color' => '#2d6a4f', 'icon' => 'currency-dollar','sort_order' => 6],
            ['slug' => 'technology',             'name' => 'التقنية',                     'name_ar' => 'التقنية',                     'name_en' => 'Technology & Technical Support', 'color' => '#1b4332', 'icon' => 'chip',           'sort_order' => 7],
            ['slug' => 'human-resources',        'name' => 'الموارد البشرية',             'name_ar' => 'الموارد البشرية',             'name_en' => 'Human Resources',               'color' => '#6d6875', 'icon' => 'users',          'sort_order' => 8],
            ['slug' => 'community-health',       'name' => 'المجتمع والصحة',              'name_ar' => 'المجتمع والصحة',              'name_en' => 'Community, Wellbeing & Awareness','color' => '#b5838d', 'icon' => 'heart',         'sort_order' => 9],
            ['slug' => 'quality-governance',     'name' => 'الجودة والحوكمة',             'name_ar' => 'الجودة والحوكمة',             'name_en' => 'Quality & Governance',          'color' => '#457b9d', 'icon' => 'shield-check',  'sort_order' => 10],
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

        // ── Team profiles (public-facing EMC V3 team page) ────────────────────
        $this->call(TeamMemberSeeder::class);

        // ── English placement test + demo accounts / course / slots ──────────
        $this->call(PlacementDemoSeeder::class);

        // ── Development seed data (local / testing only) ──────────────────────
        if (app()->environment(['local', 'testing'])) {
            $this->call(EMCDevelopmentSeeder::class);
        }
    }
}
