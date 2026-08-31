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
            GranularPermissionsSeeder::class,   // granular dotted-notation permissions
            DemoUserSeeder::class,
            TrackSeeder::class,
            LearningPathSeeder::class,
        ]);

        // ── Finance: system-managed payment-gateway accounts & Chart of Accounts ──
        $this->call([
            FinanceSystemAccountsSeeder::class,
            AccountsSeeder::class,
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
        // المسميات الرسمية للإدارات الـ13 — مصدر واحد: OfficialDepartmentsSeeder
        $this->call(OfficialDepartmentsSeeder::class);
        Department::query()->update(['leader_id' => $admin->id]);

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
