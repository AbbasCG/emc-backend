<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoUserSeeder extends Seeder
{
    private const DEMO_USERS = [
        ['name' => 'Super Admin',       'email' => 'superadmin@emc.test',  'role' => 'super_admin'],
        ['name' => 'Executive Admin',   'email' => 'executive@emc.test',   'role' => 'executive_admin'],
        ['name' => 'Admin',             'email' => 'admin@emc.test',       'role' => 'admin'],
        ['name' => 'Instructor',        'email' => 'instructor@emc.test',  'role' => 'instructor'],
        ['name' => 'Student',           'email' => 'student@emc.test',     'role' => 'student'],
        ['name' => 'Finance Manager',   'email' => 'finance@emc.test',     'role' => 'finance_manager'],
        ['name' => 'Quality Manager',   'email' => 'quality@emc.test',     'role' => 'quality_manager'],
        ['name' => 'HR Manager',        'email' => 'hr@emc.test',          'role' => 'hr_manager'],
        ['name' => 'Partner',           'email' => 'partner@emc.test',     'role' => 'partner'],
        ['name' => 'Marketing Manager', 'email' => 'marketing@emc.test',   'role' => 'marketing_manager'],
        ['name' => 'Support Agent',     'email' => 'support@emc.test',     'role' => 'support_agent'],
        ['name' => 'Volunteer',         'email' => 'volunteer@emc.test',   'role' => 'volunteer'],
        ['name' => 'Dept. Manager',     'email' => 'deptmanager@emc.test', 'role' => 'department_manager'],
        ['name' => 'AI Manager',        'email' => 'ai@emc.test',          'role' => 'ai_manager'],
        ['name' => 'Ambassadors Mgr',   'email' => 'ambassadors@emc.test', 'role' => 'department_manager'],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->warn('DemoUserSeeder skipped — will not seed demo accounts in production.');
            return;
        }

        $password = Hash::make('password');

        foreach (self::DEMO_USERS as $userData) {
            User::updateOrCreate(
                ['email' => $userData['email']],
                [
                    'name'     => $userData['name'],
                    'password' => $password,
                    'role'     => $userData['role'],
                ],
            );

            $this->command->info("  ✓ {$userData['email']} ({$userData['role']})");
        }

        // Upgrade any existing user with email admin@emc.* to admin role if not already elevated
        User::query()
            ->whereIn('email', ['admin@emc.test', 'admin@example.com'])
            ->where('role', 'student')
            ->update(['role' => 'admin']);

        $this->command->info('✓ Demo users seeded. Password: password');
    }
}
