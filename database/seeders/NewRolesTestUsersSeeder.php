<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds one test user per new EMC role added in Phase 1.
 *
 * Safe to run multiple times — uses updateOrCreate on email.
 * Skipped automatically in production.
 *
 * Usage:
 *   php artisan db:seed --class=NewRolesTestUsersSeeder
 */
class NewRolesTestUsersSeeder extends Seeder
{
    /** @var array<int, array<string, string>> */
    private const USERS = [
        [
            'name'     => 'Tech Admin',
            'email'    => 'techadmin@emc.test',
            'role'     => 'tech_admin',
            'phone'    => '+966501234001',
            'city'     => 'Riyadh',
            'country'  => 'Saudi Arabia',
        ],
        [
            'name'     => 'Programs Manager',
            'email'    => 'programs@emc.test',
            'role'     => 'programs_manager',
            'phone'    => '+966501234002',
            'city'     => 'Jeddah',
            'country'  => 'Saudi Arabia',
        ],
        [
            'name'     => 'Operations Manager',
            'email'    => 'operations@emc.test',
            'role'     => 'operations_manager',
            'phone'    => '+966501234003',
            'city'     => 'Dammam',
            'country'  => 'Saudi Arabia',
        ],
        [
            'name'     => 'Partnerships Manager',
            'email'    => 'partnerships@emc.test',
            'role'     => 'partnerships_manager',
            'phone'    => '+966501234004',
            'city'     => 'Riyadh',
            'country'  => 'Saudi Arabia',
        ],
        [
            'name'     => 'Community Manager',
            'email'    => 'community@emc.test',
            'role'     => 'community_manager',
            'phone'    => '+966501234005',
            'city'     => 'Medina',
            'country'  => 'Saudi Arabia',
        ],
        [
            'name'     => 'Section Lead',
            'email'    => 'sectionlead@emc.test',
            'role'     => 'section_lead',
            'phone'    => '+966501234006',
            'city'     => 'Khobar',
            'country'  => 'Saudi Arabia',
        ],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->warn('NewRolesTestUsersSeeder skipped — will not seed test accounts in production.');
            return;
        }

        $password = Hash::make('password');

        /** @var array<string, array{user: User, permCount: int}> $report */
        $report = [];

        foreach (self::USERS as $data) {
            // 1. Upsert the user ─────────────────────────────────────────────
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name'      => $data['name'],
                    'password'  => $password,
                    'role'      => $data['role'],
                    'phone'     => $data['phone'],
                    'city'      => $data['city'],
                    'country'   => $data['country'],
                    'avatar'    => null,
                    'is_active' => true,
                ],
            );

            // 2. Verify role exists in DB ─────────────────────────────────────
            $role = Role::with('permissions')->where('name', $data['role'])->first();

            if (! $role) {
                $this->command->warn("  ⚠ Role [{$data['role']}] not found in roles table — run RoleSeeder first.");
                $report[$data['email']] = ['user' => $user, 'permCount' => 0, 'missing' => true];
                continue;
            }

            // 3. Count permissions assigned to the role ───────────────────────
            //    Permissions live on the Role, not on the User directly.
            //    User::allPermissions() resolves them at runtime via Role->permissions.
            $permCount = $role->permissions->count();

            $this->command->info("  ✓ {$data['name']} created");

            $report[$data['email']] = [
                'user'      => $user,
                'role'      => $data['role'],
                'permCount' => $permCount,
                'missing'   => false,
            ];
        }

        // ── Seeder Report ────────────────────────────────────────────────────
        $this->command->newLine();
        $this->command->line('┌─────────────────────────────────────────────┬──────────────────────┬──────────────┐');
        $this->command->line('│ Role                                        │ Email                │ Permissions  │');
        $this->command->line('├─────────────────────────────────────────────┼──────────────────────┼──────────────┤');

        foreach ($report as $email => $entry) {
            $rolePad  = str_pad($entry['role'] ?? '—', 43);
            $emailPad = str_pad($email, 20);
            $count    = $entry['missing'] ? '⚠ role missing' : str_pad((string) $entry['permCount'], 12);
            $this->command->line("│ {$rolePad} │ {$emailPad} │ {$count} │");
        }

        $this->command->line('└─────────────────────────────────────────────┴──────────────────────┴──────────────┘');
        $this->command->newLine();
        $this->command->info('✓ NewRolesTestUsersSeeder complete. Password for all accounts: password');
    }
}
