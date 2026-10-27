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
                Permissions::TECH_DEPARTMENT_DASHBOARDS_VIEW,
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
                Permissions::TECH_DEPARTMENT_DASHBOARDS_VIEW,
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

            'department_member' => [
                Permissions::VIEW_TASKS,
                Permissions::VIEW_MEETINGS,
                Permissions::VIEW_FORMS,
                Permissions::VIEW_TEAM,
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

            // ── New roles (Phase 1–6) ─────────────────────────────────────────

            // Phase 2 — tech_admin: full access (mirrors super_admin minus system exclusions)
            'tech_admin' => Permissions::all(),

            // Phase 3 — programs_manager: مدير البرامج والمسارات
            'programs_manager' => [
                // Programs & courses
                Permissions::VIEW_PROGRAMS,
                Permissions::MANAGE_PROGRAMS,
                Permissions::VIEW_COURSES,
                Permissions::MANAGE_COURSES,
                Permissions::VIEW_WORKSHOPS,
                Permissions::VIEW_TRACKS,
                Permissions::MANAGE_TRACKS,
                // Registrations (read)
                Permissions::VIEW_REGISTRATIONS,
                // LMS (read + material management)
                Permissions::VIEW_SESSIONS,
                Permissions::MANAGE_SESSIONS,
                Permissions::VIEW_ATTENDANCE,
                Permissions::VIEW_ASSIGNMENTS,
                Permissions::MANAGE_ASSIGNMENTS,
                Permissions::REVIEW_SUBMISSIONS,
                Permissions::VIEW_MATERIALS,
                Permissions::MANAGE_MATERIALS,
                // Certificates (view only)
                Permissions::VIEW_CERTIFICATES,
                // Reports & KPI
                Permissions::VIEW_KPI,
                Permissions::VIEW_REPORTS,
                Permissions::GENERATE_REPORTS,
                // Knowledge & AI
                Permissions::VIEW_KNOWLEDGE,
                Permissions::MANAGE_KNOWLEDGE,
                Permissions::USE_AI_ASSISTANT,
                // Granular courses
                Permissions::COURSES_VIEW,
                Permissions::COURSES_CREATE,
                Permissions::COURSES_UPDATE,
                Permissions::COURSES_DELETE,
                Permissions::COURSES_ASSIGN_INSTRUCTOR,
                Permissions::COURSES_MANAGE,
                // Granular attendance/assignments (view)
                Permissions::ATTENDANCE_VIEW,
                Permissions::ASSIGNMENTS_VIEW,
                Permissions::ASSIGNMENTS_CREATE,
                Permissions::ASSIGNMENTS_UPDATE,
                Permissions::ASSIGNMENTS_MANAGE,
                // Workshop requests
                Permissions::WR_VIEW_ALL,
                Permissions::WR_REVIEW,
                Permissions::WR_APPROVE,
                Permissions::WR_REJECT,
                Permissions::WR_ASSIGN,
                Permissions::WR_EXPORT,
                // Members
                Permissions::MEMBERS_VIEW,
            ],

            // Phase 4 — operations_manager: مدير التشغيل والعمليات
            'operations_manager' => [
                // Operations core
                Permissions::VIEW_DEPARTMENTS,
                Permissions::MANAGE_DEPARTMENTS,
                Permissions::VIEW_TASKS,
                Permissions::MANAGE_TASKS,
                Permissions::VIEW_MEETINGS,
                Permissions::MANAGE_MEETINGS,
                Permissions::VIEW_FORMS,
                Permissions::MANAGE_FORMS,
                // Programs/courses (read only)
                Permissions::VIEW_PROGRAMS,
                Permissions::VIEW_COURSES,
                // LMS (read only)
                Permissions::VIEW_SESSIONS,
                Permissions::VIEW_ATTENDANCE,
                Permissions::VIEW_ASSIGNMENTS,
                // Reports & KPI
                Permissions::VIEW_KPI,
                Permissions::VIEW_REPORTS,
                Permissions::GENERATE_REPORTS,
                // Knowledge & AI
                Permissions::VIEW_KNOWLEDGE,
                Permissions::USE_AI_ASSISTANT,
                // Workshop requests
                Permissions::WR_VIEW_ALL,
                Permissions::WR_REVIEW,
                Permissions::WR_APPROVE,
                Permissions::WR_REJECT,
                Permissions::WR_ASSIGN,
                Permissions::WR_EXPORT,
                // Granular
                Permissions::COURSES_VIEW,
                Permissions::ATTENDANCE_VIEW,
                Permissions::ASSIGNMENTS_VIEW,
                Permissions::MEMBERS_VIEW,
                Permissions::DEPTS_VIEW,
                Permissions::DEPTS_MANAGE,
            ],

            // Phase 5 — partnerships_manager: مدير الشراكات والعلاقات
            'partnerships_manager' => [
                // Partnerships core
                Permissions::VIEW_PARTNERS,
                Permissions::MANAGE_PARTNERS,
                Permissions::VIEW_PARTNER_REPORTS,
                // Programs/courses (read only)
                Permissions::VIEW_PROGRAMS,
                Permissions::VIEW_COURSES,
                // Registrations (read)
                Permissions::VIEW_REGISTRATIONS,
                // Reports
                Permissions::VIEW_REPORTS,
                Permissions::GENERATE_REPORTS,
                // Knowledge & AI
                Permissions::VIEW_KNOWLEDGE,
                Permissions::USE_AI_ASSISTANT,
                // Workshop requests
                Permissions::WR_VIEW_ALL,
                Permissions::WR_REVIEW,
                Permissions::WR_APPROVE,
                Permissions::WR_REJECT,
                // Granular
                Permissions::COURSES_VIEW,
                Permissions::MEMBERS_VIEW,
            ],

            // Phase 6 — community_manager: مدير المجتمع والصحة
            'community_manager' => [
                // Volunteers
                Permissions::VIEW_VOLUNTEERS,
                Permissions::MANAGE_VOLUNTEERS,
                // Tasks & meetings
                Permissions::VIEW_TASKS,
                Permissions::MANAGE_TASKS,
                Permissions::VIEW_MEETINGS,
                Permissions::MANAGE_MEETINGS,
                // Forms
                Permissions::VIEW_FORMS,
                Permissions::MANAGE_FORMS,
                // Registrations (read)
                Permissions::VIEW_REGISTRATIONS,
                // Reports
                Permissions::VIEW_REPORTS,
                // Knowledge & AI
                Permissions::VIEW_KNOWLEDGE,
                Permissions::USE_AI_ASSISTANT,
                // Workshop requests
                Permissions::WR_VIEW_ALL,
                Permissions::WR_REVIEW,
                Permissions::WR_APPROVE,
                Permissions::WR_REJECT,
                // Granular members
                Permissions::MEMBERS_VIEW,
                Permissions::MEMBERS_RECOGNIZE,
                Permissions::MEMBERS_VIEW_DEPARTMENT,
            ],
        ];
    }
}
