<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /** All platform roles. is_system = cannot be deleted via API. */
    private const ROLES = [
        ['name' => 'super_admin',       'display_name' => 'Super Admin',        'description' => 'Full unrestricted access to everything.',                         'is_system' => true],
        ['name' => 'executive_admin',   'display_name' => 'Executive Admin',     'description' => 'Read-only view across all dashboards and reports.',               'is_system' => true],
        ['name' => 'admin',             'display_name' => 'Admin',               'description' => 'Manages platform operations, courses, users, and content.',        'is_system' => true],
        ['name' => 'department_manager','display_name' => 'Department Manager',  'description' => 'Manages tasks, meetings, and team within their department.',       'is_system' => true],
        ['name' => 'section_lead',      'display_name' => 'Section Lead',        'description' => 'Manages tasks within their section.',                              'is_system' => true],
        ['name' => 'finance_manager',   'display_name' => 'Finance Manager',     'description' => 'Manages payments, revenue, coupons, and financial reports.',       'is_system' => true],
        ['name' => 'marketing_manager', 'display_name' => 'Marketing Manager',   'description' => 'Manages marketing campaigns and program visibility.',              'is_system' => true],
        ['name' => 'quality_manager',   'display_name' => 'Quality Manager',     'description' => 'Reviews and approves quality evaluations.',                        'is_system' => true],
        ['name' => 'hr_manager',        'display_name' => 'HR Manager',          'description' => 'Manages volunteers and team members.',                             'is_system' => true],
        ['name' => 'support_agent',     'display_name' => 'Support Agent',       'description' => 'Handles support tickets and basic student queries.',               'is_system' => true],
        ['name' => 'instructor',        'display_name' => 'Instructor',          'description' => 'Manages assigned courses, sessions, assignments, and attendance.', 'is_system' => true],
        ['name' => 'student',           'display_name' => 'Student',             'description' => 'Enrolled learner with access to own data only.',                   'is_system' => true],
        ['name' => 'volunteer',         'display_name' => 'Volunteer',           'description' => 'Community contributor with limited operational access.',           'is_system' => true],
        ['name' => 'partner',           'display_name' => 'Partner',             'description' => 'External partner with access to own programs and reports.',        'is_system' => true],
        // ── New roles added in Phase 1 ────────────────────────────────────────
        ['name' => 'programs_manager',   'display_name' => 'Programs Manager — مدير البرامج والمسارات',   'description' => 'Manages courses, programs, learning paths, and instructor assignment.', 'is_system' => true],
        ['name' => 'operations_manager', 'display_name' => 'Operations Manager — مدير التشغيل والعمليات', 'description' => 'Manages tasks, meetings, departments, and operational workflows.',           'is_system' => true],
        ['name' => 'partnerships_manager','display_name' => 'Partnerships Manager — مدير الشراكات والعلاقات','description' => 'Manages partner relationships, partnership requests, and partner reports.', 'is_system' => true],
        ['name' => 'community_manager',  'display_name' => 'Community Manager — مدير المجتمع والصحة',     'description' => 'Manages volunteers, community events, forms, and community programs.',   'is_system' => true],
        ['name' => 'tech_admin',         'display_name' => 'Tech Admin — مدير التقنية',                   'description' => 'Technical platform administrator with full system access.',              'is_system' => true],
    ];

    public function run(): void
    {
        foreach (self::ROLES as $roleData) {
            Role::updateOrCreate(
                ['name' => $roleData['name']],
                $roleData,
            );
        }

        $this->command->info('✓ ' . count(self::ROLES) . ' roles seeded.');
    }
}
