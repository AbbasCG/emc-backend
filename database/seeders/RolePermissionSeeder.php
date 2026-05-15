<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->roleMap() as $roleName => $permissionNames) {
            $role = Role::query()->where('name', $roleName)->first();

            if (! $role) {
                $this->command->warn("Role [{$roleName}] not found — skipping.");
                continue;
            }

            // Remove duplicates then sync
            $role->syncPermissions(array_unique($permissionNames));

            $this->command->info("  ✓ [{$roleName}] → " . count(array_unique($permissionNames)) . ' permissions');
        }

        $this->command->info('✓ Role-permission mappings complete.');
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function roleMap(): array
    {
        return [

            'super_admin' => Permissions::all(),

            'executive_admin' => [
                Permissions::VIEW_USERS,
                Permissions::VIEW_PROGRAMS, Permissions::VIEW_COURSES, Permissions::VIEW_WORKSHOPS, Permissions::VIEW_TRACKS,
                Permissions::VIEW_REGISTRATIONS,
                Permissions::VIEW_SESSIONS, Permissions::VIEW_ATTENDANCE, Permissions::VIEW_ASSIGNMENTS, Permissions::VIEW_MATERIALS,
                Permissions::VIEW_PAYMENTS, Permissions::VIEW_REVENUE, Permissions::VIEW_FINANCIAL_REPORTS,
                Permissions::VIEW_CERTIFICATES,
                Permissions::VIEW_DEPARTMENTS, Permissions::VIEW_TASKS, Permissions::VIEW_MEETINGS, Permissions::VIEW_FORMS,
                Permissions::VIEW_VOLUNTEERS, Permissions::VIEW_TEAM,
                Permissions::VIEW_PARTNERS, Permissions::VIEW_PARTNER_REPORTS,
                Permissions::VIEW_QUALITY, Permissions::APPROVE_QUALITY_REVIEWS,
                Permissions::VIEW_KPI, Permissions::VIEW_REPORTS, Permissions::EXPORT_REPORTS,
                Permissions::VIEW_KNOWLEDGE,
                Permissions::VIEW_AUDIT_LOGS,
                Permissions::VIEW_AI_USAGE,
            ],

            'admin' => [
                // Users (not manage_roles — only super_admin)
                Permissions::VIEW_USERS, Permissions::CREATE_USERS, Permissions::UPDATE_USERS, Permissions::DELETE_USERS,
                // Education
                Permissions::VIEW_PROGRAMS, Permissions::MANAGE_PROGRAMS,
                Permissions::VIEW_COURSES, Permissions::MANAGE_COURSES,
                Permissions::VIEW_WORKSHOPS, Permissions::MANAGE_WORKSHOPS,
                Permissions::VIEW_TRACKS, Permissions::MANAGE_TRACKS,
                Permissions::VIEW_REGISTRATIONS, Permissions::MANAGE_REGISTRATIONS,
                // LMS
                Permissions::VIEW_SESSIONS, Permissions::MANAGE_SESSIONS,
                Permissions::VIEW_ATTENDANCE, Permissions::MANAGE_ATTENDANCE,
                Permissions::VIEW_ASSIGNMENTS, Permissions::MANAGE_ASSIGNMENTS, Permissions::REVIEW_SUBMISSIONS,
                Permissions::VIEW_MATERIALS, Permissions::MANAGE_MATERIALS,
                // Finance
                Permissions::VIEW_PAYMENTS, Permissions::MANAGE_PAYMENTS,
                Permissions::VIEW_REVENUE, Permissions::MANAGE_COUPONS, Permissions::MANAGE_SCHOLARSHIPS,
                // Certificates
                Permissions::VIEW_CERTIFICATES, Permissions::MANAGE_CERTIFICATES,
                Permissions::ISSUE_CERTIFICATES, Permissions::REVOKE_CERTIFICATES,
                // Operations
                Permissions::VIEW_DEPARTMENTS, Permissions::MANAGE_DEPARTMENTS,
                Permissions::VIEW_TASKS, Permissions::MANAGE_TASKS,
                Permissions::VIEW_MEETINGS, Permissions::MANAGE_MEETINGS,
                Permissions::VIEW_FORMS, Permissions::MANAGE_FORMS,
                // HR
                Permissions::VIEW_VOLUNTEERS, Permissions::MANAGE_VOLUNTEERS,
                Permissions::VIEW_TEAM, Permissions::MANAGE_TEAM,
                // Partnerships
                Permissions::VIEW_PARTNERS, Permissions::MANAGE_PARTNERS, Permissions::VIEW_PARTNER_REPORTS,
                // Reports
                Permissions::VIEW_KPI, Permissions::VIEW_REPORTS, Permissions::GENERATE_REPORTS,
                // Knowledge
                Permissions::VIEW_KNOWLEDGE, Permissions::MANAGE_KNOWLEDGE,
                // AI
                Permissions::USE_AI_ASSISTANT, Permissions::VIEW_AI_USAGE,
                // System
                Permissions::VIEW_AUDIT_LOGS,
            ],

            'department_manager' => [
                Permissions::VIEW_USERS,
                Permissions::VIEW_PROGRAMS, Permissions::VIEW_COURSES,
                Permissions::VIEW_TASKS, Permissions::MANAGE_TASKS,
                Permissions::VIEW_MEETINGS, Permissions::MANAGE_MEETINGS,
                Permissions::VIEW_FORMS,
                Permissions::VIEW_TEAM,
                Permissions::VIEW_KPI, Permissions::VIEW_REPORTS,
                Permissions::VIEW_KNOWLEDGE,
                Permissions::USE_AI_ASSISTANT,
            ],

            'section_lead' => [
                Permissions::VIEW_TASKS, Permissions::MANAGE_TASKS,
                Permissions::VIEW_MEETINGS,
                Permissions::VIEW_PROGRAMS, Permissions::VIEW_COURSES,
                Permissions::VIEW_KNOWLEDGE,
            ],

            'finance_manager' => [
                Permissions::VIEW_PAYMENTS, Permissions::MANAGE_PAYMENTS,
                Permissions::VIEW_REVENUE, Permissions::MANAGE_COUPONS, Permissions::MANAGE_SCHOLARSHIPS,
                Permissions::VIEW_FINANCIAL_REPORTS,
                Permissions::VIEW_REGISTRATIONS,
                Permissions::VIEW_KPI, Permissions::VIEW_REPORTS, Permissions::GENERATE_REPORTS, Permissions::EXPORT_REPORTS,
            ],

            'marketing_manager' => [
                Permissions::VIEW_MARKETING, Permissions::MANAGE_MARKETING,
                Permissions::VIEW_PROGRAMS, Permissions::VIEW_COURSES,
                Permissions::VIEW_WORKSHOPS, Permissions::VIEW_TRACKS,
                Permissions::VIEW_REGISTRATIONS,
                Permissions::VIEW_REPORTS,
            ],

            'quality_manager' => [
                Permissions::VIEW_QUALITY, Permissions::MANAGE_QUALITY, Permissions::APPROVE_QUALITY_REVIEWS,
                Permissions::VIEW_PROGRAMS, Permissions::VIEW_COURSES,
                Permissions::VIEW_SESSIONS, Permissions::VIEW_ATTENDANCE,
                Permissions::VIEW_KPI, Permissions::VIEW_REPORTS, Permissions::GENERATE_REPORTS,
            ],

            'hr_manager' => [
                Permissions::VIEW_VOLUNTEERS, Permissions::MANAGE_VOLUNTEERS,
                Permissions::VIEW_TEAM, Permissions::MANAGE_TEAM,
                Permissions::VIEW_USERS,
                Permissions::VIEW_REPORTS,
            ],

            'support_agent' => [
                Permissions::VIEW_SUPPORT_TICKETS, Permissions::MANAGE_SUPPORT_TICKETS,
                Permissions::VIEW_USERS,
                Permissions::VIEW_REGISTRATIONS,
            ],

            'instructor' => [
                Permissions::VIEW_COURSES,
                Permissions::VIEW_SESSIONS, Permissions::VIEW_ATTENDANCE, Permissions::MANAGE_ATTENDANCE,
                Permissions::VIEW_ASSIGNMENTS, Permissions::REVIEW_SUBMISSIONS,
                Permissions::VIEW_MATERIALS, Permissions::MANAGE_MATERIALS,
                Permissions::VIEW_CERTIFICATES,
                Permissions::VIEW_KNOWLEDGE,
                Permissions::USE_AI_ASSISTANT,
            ],

            'student' => [
                Permissions::VIEW_PROGRAMS, Permissions::VIEW_COURSES,
                Permissions::VIEW_WORKSHOPS, Permissions::VIEW_TRACKS,
                Permissions::VIEW_REGISTRATIONS,
                Permissions::VIEW_SESSIONS, Permissions::VIEW_ASSIGNMENTS, Permissions::VIEW_MATERIALS,
                Permissions::VIEW_CERTIFICATES,
                Permissions::VIEW_KNOWLEDGE,
                Permissions::USE_AI_ASSISTANT,
            ],

            'volunteer' => [
                Permissions::VIEW_TASKS,
                Permissions::VIEW_MEETINGS,
                Permissions::VIEW_FORMS,
                Permissions::VIEW_KNOWLEDGE,
            ],

            'partner' => [
                Permissions::VIEW_PARTNERS,
                Permissions::VIEW_PARTNER_REPORTS,
                Permissions::VIEW_PROGRAMS,
                Permissions::VIEW_REPORTS,
            ],
        ];
    }
}
