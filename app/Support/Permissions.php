<?php

namespace App\Support;

/**
 * Canonical permission constants for the EMC platform.
 *
 * Single source of truth: every permission string used anywhere in the app
 * (Gates, policies, seeders, middleware) must reference a constant here.
 *
 * Usage:
 *   $user->can(Permissions::MANAGE_COURSES)
 *   Gate::authorize(Permissions::VIEW_FINANCE)
 *   middleware('permission:manage_courses')
 */
final class Permissions
{
    // ── Users ────────────────────────────────────────────────────────────────
    const VIEW_USERS    = 'view_users';
    const CREATE_USERS  = 'create_users';
    const UPDATE_USERS  = 'update_users';
    const DELETE_USERS  = 'delete_users';
    const MANAGE_ROLES  = 'manage_roles';
    /**
     * Impersonate / login-as-user.
     * Intentionally excluded from all() so no role inherits it automatically.
     * Must be granted explicitly via the role/permission management UI or seeder.
     * Gate::before(super_admin bypass) is also excluded for this permission.
     */
    const IMPERSONATE_USERS = 'users.impersonate';
    /** @deprecated Use granular view/create/update/delete_users */
    const MANAGE_USERS  = 'manage_users';

    /**
     * Users Management hub — aggregate analytics tabs (/dashboard/super-admin/crud/users?tab=…).
     * Aggregates only (no emails, phones or IPs), each tab behind its OWN key: being allowed to see the users
     * table never implies seeing growth, login or acquisition figures. No role gets them by default; super_admin and
     * tech_admin hold them through Permissions::all(), anyone else needs an explicit grant (Access Control → roles).
     */
    const USERS_ANALYTICS_VIEW   = 'users.analytics.view';
    const USERS_ACTIVITY_VIEW    = 'users.activity.view';
    const USERS_ACQUISITION_VIEW = 'users.acquisition.view';

    // ── Education / Programs ─────────────────────────────────────────────────
    const VIEW_PROGRAMS    = 'view_programs';
    const MANAGE_PROGRAMS  = 'manage_programs';
    const VIEW_COURSES     = 'view_courses';
    const MANAGE_COURSES   = 'manage_courses';
    const VIEW_WORKSHOPS   = 'view_workshops';
    const MANAGE_WORKSHOPS = 'manage_workshops';
    const VIEW_TRACKS      = 'view_tracks';
    const MANAGE_TRACKS    = 'manage_tracks';
    const VIEW_REGISTRATIONS   = 'view_registrations';
    const MANAGE_REGISTRATIONS = 'manage_registrations';

    // ── LMS ──────────────────────────────────────────────────────────────────
    const VIEW_SESSIONS        = 'view_sessions';
    const MANAGE_SESSIONS      = 'manage_sessions';
    const VIEW_ATTENDANCE      = 'view_attendance';
    const MANAGE_ATTENDANCE    = 'manage_attendance';
    const VIEW_ASSIGNMENTS     = 'view_assignments';
    const MANAGE_ASSIGNMENTS   = 'manage_assignments';
    const REVIEW_SUBMISSIONS   = 'review_submissions';
    const VIEW_MATERIALS       = 'view_materials';
    const MANAGE_MATERIALS     = 'manage_materials';
    /** @deprecated Use granular LMS permissions */
    const MANAGE_LMS           = 'manage_lms';

    // ── Finance ──────────────────────────────────────────────────────────────
    const VIEW_PAYMENTS          = 'view_payments';
    const MANAGE_PAYMENTS        = 'manage_payments';
    const VIEW_REVENUE           = 'view_revenue';
    const MANAGE_COUPONS         = 'manage_coupons';
    const MANAGE_SCHOLARSHIPS    = 'manage_scholarships';
    const VIEW_FINANCIAL_REPORTS = 'view_financial_reports';
    /** @deprecated Use VIEW_PAYMENTS / VIEW_REVENUE */
    const VIEW_FINANCE           = 'view_finance';

    // ── Certificates ─────────────────────────────────────────────────────────
    const VIEW_CERTIFICATES    = 'view_certificates';
    const MANAGE_CERTIFICATES  = 'manage_certificates';
    const ISSUE_CERTIFICATES   = 'issue_certificates';
    const REVOKE_CERTIFICATES  = 'revoke_certificates';

    // ── Operations ───────────────────────────────────────────────────────────
    const VIEW_DEPARTMENTS   = 'view_departments';
    const MANAGE_DEPARTMENTS = 'manage_departments';
    const VIEW_TASKS         = 'view_tasks';
    const MANAGE_TASKS       = 'manage_tasks';
    const VIEW_MEETINGS      = 'view_meetings';
    const MANAGE_MEETINGS    = 'manage_meetings';
    const VIEW_FORMS         = 'view_forms';
    const MANAGE_FORMS       = 'manage_forms';

    // ── HR ───────────────────────────────────────────────────────────────────
    const VIEW_VOLUNTEERS   = 'view_volunteers';
    const MANAGE_VOLUNTEERS = 'manage_volunteers';
    const VIEW_TEAM         = 'view_team';
    const MANAGE_TEAM       = 'manage_team';

    // ── Partnerships ─────────────────────────────────────────────────────────
    const VIEW_PARTNERS        = 'view_partners';
    const MANAGE_PARTNERS      = 'manage_partners';
    const VIEW_PARTNER_REPORTS = 'view_partner_reports';

    // ── Marketing ────────────────────────────────────────────────────────────
    const VIEW_MARKETING          = 'view_marketing';
    const MANAGE_MARKETING        = 'manage_marketing';
    const MANAGE_PRODUCT_UPDATES  = 'manage_product_updates';

    // ── Quality ──────────────────────────────────────────────────────────────
    const VIEW_QUALITY            = 'view_quality';
    const MANAGE_QUALITY          = 'manage_quality';
    const APPROVE_QUALITY_REVIEWS = 'approve_quality_reviews';

    // ── Support ──────────────────────────────────────────────────────────────
    const VIEW_SUPPORT_TICKETS   = 'view_support_tickets';
    const MANAGE_SUPPORT_TICKETS = 'manage_support_tickets';

    // ── Reports & KPI ────────────────────────────────────────────────────────
    const VIEW_KPI        = 'view_kpi';
    const VIEW_REPORTS    = 'view_reports';
    const GENERATE_REPORTS = 'generate_reports';
    const EXPORT_REPORTS  = 'export_reports';
    /** @deprecated Use VIEW_REPORTS */
    const MANAGE_REPORTS  = 'manage_reports';

    // ── Knowledge ────────────────────────────────────────────────────────────
    const VIEW_KNOWLEDGE   = 'view_knowledge';
    const MANAGE_KNOWLEDGE = 'manage_knowledge';

    // ── AI ───────────────────────────────────────────────────────────────────
    const USE_AI_ASSISTANT  = 'use_ai_assistant';
    const MANAGE_AI_PROMPTS = 'manage_ai_prompts';
    const VIEW_AI_USAGE     = 'view_ai_usage';

    // ── System ───────────────────────────────────────────────────────────────
    const VIEW_AUDIT_LOGS     = 'view_audit_logs';
    const MANAGE_SETTINGS     = 'manage_settings';
    const MANAGE_INTEGRATIONS = 'manage_integrations';
    const MANAGE_WEBHOOKS     = 'manage_webhooks';
    const MANAGE_API_TOKENS   = 'manage_api_tokens';

    /**
     * Read-only cross-department monitoring dashboard (Technology Management).
     * Aggregated counts only; never grants any action on department data.
     */
    const TECH_DEPARTMENT_DASHBOARDS_VIEW = 'tech.department_dashboards.view';

    /**
     * Private executive follow-up hub (cross-platform feed).
     * Intentionally excluded from all(), like users.impersonate: no role inherits it, not
     * even tech_admin, and the Gate::before root bypass skips it. It must be granted
     * explicitly: ExecutiveCommandCenterPermissionSeeder grants it to super_admin only;
     * anyone else is added through the role/permission UI or a per-user assignment.
     */
    const EXECUTIVE_COMMAND_CENTER_VIEW = 'executive.command_center.view';

    /**
     * Ticket completion review (the work queue's final approval): approve completion, approve for deployment and return
     * for rework (TicketAccess::canReviewStage). Scope still comes from department leadership / the global roles
     * (TicketAccess::canManage); this key is the PERMISSION half of the rule, so ticket view/assign alone never approves.
     *
     * Part of all(): super_admin and tech_admin keep reviewing, like every other permission. Nothing else is granted it
     * by code or by migration; TicketReviewPermissionSeeder (opt-in) grants it to the roles that review today.
     */
    const TICKETS_REVIEW_COMPLETION = 'tickets.review_completion';

    /**
     * Review a ticket the reviewer PARTICIPATES in (created it, is its assignee, or submitted its completion). Needed IN
     * ADDITION to TICKETS_REVIEW_COMPLETION for such tickets.
     *
     * Intentionally excluded from all(), like users.impersonate and executive.command_center.view: no role inherits it,
     * super_admin / tech_admin included, and the Gate::before root bypass skips it. It exists only through an explicit
     * DB grant (role editor or a per-user assignment, via the delegation endpoints) and a per-user deny removes it again.
     */
    const TICKETS_REVIEW_PARTICIPATED = 'tickets.review_participated';

    // ── Workshop Requests (granular) ─────────────────────────────────────────
    const WR_VIEW_OWN        = 'workshop_requests.view_own';
    const WR_VIEW_DEPARTMENT = 'workshop_requests.view_department';
    const WR_VIEW_ALL        = 'workshop_requests.view_all';
    const WR_CREATE          = 'workshop_requests.create';
    const WR_REVIEW          = 'workshop_requests.review';
    const WR_APPROVE         = 'workshop_requests.approve';
    const WR_REJECT          = 'workshop_requests.reject';
    const WR_ASSIGN          = 'workshop_requests.assign';
    const WR_EDIT            = 'workshop_requests.edit';
    const WR_DELETE          = 'workshop_requests.delete';
    const WR_EXPORT          = 'workshop_requests.export';
    const WR_MANAGE          = 'workshop_requests.manage';

    // ── Members (granular) ───────────────────────────────────────────────────
    const MEMBERS_VIEW            = 'members.view';
    const MEMBERS_VIEW_DEPARTMENT = 'members.view_department';
    const MEMBERS_CREATE          = 'members.create';
    const MEMBERS_UPDATE          = 'members.update';
    const MEMBERS_DELETE          = 'members.delete';
    const MEMBERS_RECOGNIZE       = 'members.recognize';
    const MEMBERS_MANAGE          = 'members.manage';

    // ── Departments (granular) ───────────────────────────────────────────────
    const DEPTS_VIEW        = 'departments.view';
    const DEPTS_VIEW_OWN    = 'departments.view_own';
    const DEPTS_UPDATE_OWN  = 'departments.update_own';
    const DEPTS_MANAGE      = 'departments.manage';

    // ── Courses (granular) ───────────────────────────────────────────────────
    const COURSES_VIEW              = 'courses.view';
    const COURSES_CREATE            = 'courses.create';
    const COURSES_UPDATE            = 'courses.update';
    const COURSES_DELETE            = 'courses.delete';
    const COURSES_ASSIGN_INSTRUCTOR = 'courses.assign_instructor';
    const COURSES_MANAGE            = 'courses.manage';

    // ── Attendance (granular) ────────────────────────────────────────────────
    const ATTENDANCE_VIEW   = 'attendance.view';
    const ATTENDANCE_MARK   = 'attendance.mark';
    const ATTENDANCE_UPDATE = 'attendance.update';
    const ATTENDANCE_MANAGE = 'attendance.manage';

    // ── Assignments (granular) ───────────────────────────────────────────────
    const ASSIGNMENTS_VIEW   = 'assignments.view';
    const ASSIGNMENTS_CREATE = 'assignments.create';
    const ASSIGNMENTS_UPDATE = 'assignments.update';
    const ASSIGNMENTS_DELETE = 'assignments.delete';
    const ASSIGNMENTS_REVIEW = 'assignments.review';
    const ASSIGNMENTS_MANAGE = 'assignments.manage';

    // ─────────────────────────────────────────────────────────────────────────

    /** Every permission in the system (used by seeders and Gate registration). */
    public static function all(): array
    {
        return [
            // Users
            self::VIEW_USERS, self::CREATE_USERS, self::UPDATE_USERS, self::DELETE_USERS, self::MANAGE_ROLES,
            self::USERS_ANALYTICS_VIEW, self::USERS_ACTIVITY_VIEW, self::USERS_ACQUISITION_VIEW,
            // Education
            self::VIEW_PROGRAMS, self::MANAGE_PROGRAMS, self::VIEW_COURSES, self::MANAGE_COURSES,
            self::VIEW_WORKSHOPS, self::MANAGE_WORKSHOPS, self::VIEW_TRACKS, self::MANAGE_TRACKS,
            self::VIEW_REGISTRATIONS, self::MANAGE_REGISTRATIONS,
            // LMS
            self::VIEW_SESSIONS, self::MANAGE_SESSIONS, self::VIEW_ATTENDANCE, self::MANAGE_ATTENDANCE,
            self::VIEW_ASSIGNMENTS, self::MANAGE_ASSIGNMENTS, self::REVIEW_SUBMISSIONS,
            self::VIEW_MATERIALS, self::MANAGE_MATERIALS,
            // Finance
            self::VIEW_PAYMENTS, self::MANAGE_PAYMENTS, self::VIEW_REVENUE, self::MANAGE_COUPONS,
            self::MANAGE_SCHOLARSHIPS, self::VIEW_FINANCIAL_REPORTS,
            // Certificates
            self::VIEW_CERTIFICATES, self::MANAGE_CERTIFICATES, self::ISSUE_CERTIFICATES, self::REVOKE_CERTIFICATES,
            // Operations
            self::VIEW_DEPARTMENTS, self::MANAGE_DEPARTMENTS, self::VIEW_TASKS, self::MANAGE_TASKS,
            self::VIEW_MEETINGS, self::MANAGE_MEETINGS, self::VIEW_FORMS, self::MANAGE_FORMS,
            // HR
            self::VIEW_VOLUNTEERS, self::MANAGE_VOLUNTEERS, self::VIEW_TEAM, self::MANAGE_TEAM,
            // Partnerships
            self::VIEW_PARTNERS, self::MANAGE_PARTNERS, self::VIEW_PARTNER_REPORTS,
            // Marketing
            self::VIEW_MARKETING, self::MANAGE_MARKETING, self::MANAGE_PRODUCT_UPDATES,
            // Quality
            self::VIEW_QUALITY, self::MANAGE_QUALITY, self::APPROVE_QUALITY_REVIEWS,
            // Support
            self::VIEW_SUPPORT_TICKETS, self::MANAGE_SUPPORT_TICKETS,
            // Reports & KPI
            self::VIEW_KPI, self::VIEW_REPORTS, self::GENERATE_REPORTS, self::EXPORT_REPORTS,
            // Knowledge
            self::VIEW_KNOWLEDGE, self::MANAGE_KNOWLEDGE,
            // AI
            self::USE_AI_ASSISTANT, self::MANAGE_AI_PROMPTS, self::VIEW_AI_USAGE,
            // System
            self::VIEW_AUDIT_LOGS, self::MANAGE_SETTINGS, self::MANAGE_INTEGRATIONS,
            self::MANAGE_WEBHOOKS, self::MANAGE_API_TOKENS, self::TECH_DEPARTMENT_DASHBOARDS_VIEW,
            // Tickets (TICKETS_REVIEW_PARTICIPATED is deliberately NOT here: explicit grant only)
            self::TICKETS_REVIEW_COMPLETION,
            // Workshop Requests (granular)
            self::WR_VIEW_OWN, self::WR_VIEW_DEPARTMENT, self::WR_VIEW_ALL,
            self::WR_CREATE, self::WR_REVIEW, self::WR_APPROVE, self::WR_REJECT,
            self::WR_ASSIGN, self::WR_EDIT, self::WR_DELETE, self::WR_EXPORT, self::WR_MANAGE,
            // Members (granular)
            self::MEMBERS_VIEW, self::MEMBERS_VIEW_DEPARTMENT, self::MEMBERS_CREATE,
            self::MEMBERS_UPDATE, self::MEMBERS_DELETE, self::MEMBERS_RECOGNIZE, self::MEMBERS_MANAGE,
            // Departments (granular)
            self::DEPTS_VIEW, self::DEPTS_VIEW_OWN, self::DEPTS_UPDATE_OWN, self::DEPTS_MANAGE,
            // Courses (granular)
            self::COURSES_VIEW, self::COURSES_CREATE, self::COURSES_UPDATE, self::COURSES_DELETE,
            self::COURSES_ASSIGN_INSTRUCTOR, self::COURSES_MANAGE,
            // Attendance (granular)
            self::ATTENDANCE_VIEW, self::ATTENDANCE_MARK, self::ATTENDANCE_UPDATE, self::ATTENDANCE_MANAGE,
            // Assignments (granular)
            self::ASSIGNMENTS_VIEW, self::ASSIGNMENTS_CREATE, self::ASSIGNMENTS_UPDATE,
            self::ASSIGNMENTS_DELETE, self::ASSIGNMENTS_REVIEW, self::ASSIGNMENTS_MANAGE,
        ];
    }

    /**
     * Static fallback for when the DB is not yet seeded.
     * The User model's hasPermission() uses this when no DB role is found.
     * Kept for backward compatibility — DB seeder is the authoritative source.
     */
    public static function forRole(string $role): array
    {
        return match ($role) {
            'super_admin', 'tech_admin' => self::all(),

            'executive_admin' => [
                self::VIEW_USERS, self::VIEW_PROGRAMS, self::VIEW_COURSES, self::VIEW_WORKSHOPS,
                self::VIEW_TRACKS, self::VIEW_REGISTRATIONS, self::VIEW_SESSIONS, self::VIEW_ATTENDANCE,
                self::VIEW_ASSIGNMENTS, self::VIEW_MATERIALS, self::VIEW_PAYMENTS, self::VIEW_REVENUE,
                self::VIEW_FINANCIAL_REPORTS, self::VIEW_CERTIFICATES, self::VIEW_DEPARTMENTS,
                self::VIEW_TASKS, self::VIEW_MEETINGS, self::VIEW_FORMS, self::VIEW_VOLUNTEERS,
                self::VIEW_TEAM, self::VIEW_PARTNERS, self::VIEW_PARTNER_REPORTS, self::VIEW_QUALITY,
                self::APPROVE_QUALITY_REVIEWS, self::VIEW_KPI, self::VIEW_REPORTS, self::EXPORT_REPORTS,
                self::VIEW_KNOWLEDGE, self::VIEW_AUDIT_LOGS, self::VIEW_AI_USAGE,
                self::TECH_DEPARTMENT_DASHBOARDS_VIEW,
                // Granular
                self::WR_VIEW_ALL, self::WR_APPROVE, self::WR_ASSIGN,
                self::MEMBERS_VIEW, self::DEPTS_VIEW, self::COURSES_VIEW,
                self::ATTENDANCE_VIEW, self::ASSIGNMENTS_VIEW,
            ],

            'admin' => [
                self::VIEW_USERS, self::CREATE_USERS, self::UPDATE_USERS, self::DELETE_USERS,
                self::MANAGE_PROGRAMS, self::MANAGE_COURSES, self::MANAGE_WORKSHOPS, self::MANAGE_TRACKS,
                self::VIEW_REGISTRATIONS, self::MANAGE_REGISTRATIONS,
                self::VIEW_SESSIONS, self::MANAGE_SESSIONS, self::VIEW_ATTENDANCE, self::MANAGE_ATTENDANCE,
                self::VIEW_ASSIGNMENTS, self::MANAGE_ASSIGNMENTS, self::REVIEW_SUBMISSIONS,
                self::VIEW_MATERIALS, self::MANAGE_MATERIALS,
                self::VIEW_PAYMENTS, self::MANAGE_PAYMENTS, self::VIEW_REVENUE, self::MANAGE_COUPONS,
                self::MANAGE_SCHOLARSHIPS,
                self::VIEW_CERTIFICATES, self::MANAGE_CERTIFICATES, self::ISSUE_CERTIFICATES, self::REVOKE_CERTIFICATES,
                self::VIEW_DEPARTMENTS, self::MANAGE_DEPARTMENTS, self::VIEW_TASKS, self::MANAGE_TASKS,
                self::VIEW_MEETINGS, self::MANAGE_MEETINGS, self::VIEW_FORMS, self::MANAGE_FORMS,
                self::VIEW_VOLUNTEERS, self::MANAGE_VOLUNTEERS, self::VIEW_TEAM, self::MANAGE_TEAM,
                self::VIEW_PARTNERS, self::MANAGE_PARTNERS, self::VIEW_PARTNER_REPORTS,
                self::VIEW_KPI, self::VIEW_REPORTS, self::GENERATE_REPORTS,
                self::VIEW_KNOWLEDGE, self::MANAGE_KNOWLEDGE,
                self::USE_AI_ASSISTANT, self::VIEW_AI_USAGE,
                self::VIEW_AUDIT_LOGS, self::MANAGE_PRODUCT_UPDATES, self::TECH_DEPARTMENT_DASHBOARDS_VIEW,
                // Granular workshop requests
                self::WR_VIEW_ALL, self::WR_MANAGE, self::WR_ASSIGN, self::WR_EXPORT, self::WR_DELETE,
                // Granular members / departments / courses / attendance / assignments
                self::MEMBERS_MANAGE, self::DEPTS_MANAGE, self::DEPTS_VIEW,
                self::COURSES_MANAGE, self::COURSES_VIEW, self::COURSES_CREATE,
                self::COURSES_UPDATE, self::COURSES_DELETE, self::COURSES_ASSIGN_INSTRUCTOR,
                self::ATTENDANCE_MANAGE, self::ATTENDANCE_VIEW,
                self::ASSIGNMENTS_MANAGE, self::ASSIGNMENTS_VIEW,
                // Legacy
                self::MANAGE_USERS, self::MANAGE_LMS, self::VIEW_FINANCE,
            ],

            'department_manager' => [
                self::VIEW_USERS, self::VIEW_PROGRAMS, self::VIEW_COURSES,
                self::VIEW_TASKS, self::MANAGE_TASKS, self::VIEW_MEETINGS, self::MANAGE_MEETINGS, self::VIEW_FORMS,
                self::VIEW_TEAM, self::VIEW_KPI, self::VIEW_REPORTS, self::VIEW_KNOWLEDGE, self::USE_AI_ASSISTANT,
                // Granular
                self::WR_VIEW_DEPARTMENT, self::WR_REVIEW, self::WR_APPROVE, self::WR_REJECT,
                self::DEPTS_VIEW_OWN, self::DEPTS_UPDATE_OWN, self::DEPTS_VIEW,
                self::MEMBERS_VIEW_DEPARTMENT, self::MEMBERS_RECOGNIZE, self::MEMBERS_VIEW,
                self::COURSES_VIEW, self::ATTENDANCE_VIEW, self::ASSIGNMENTS_VIEW,
            ],

            'department_member' => [
                self::VIEW_TASKS, self::VIEW_MEETINGS, self::VIEW_FORMS,
                self::VIEW_TEAM, self::VIEW_KNOWLEDGE, self::USE_AI_ASSISTANT,
                // Granular
                self::WR_VIEW_DEPARTMENT, self::WR_VIEW_OWN, self::WR_CREATE,
                self::DEPTS_VIEW_OWN, self::MEMBERS_VIEW_DEPARTMENT, self::MEMBERS_VIEW,
            ],

            'section_lead' => [
                self::VIEW_TASKS, self::MANAGE_TASKS, self::VIEW_MEETINGS, self::VIEW_PROGRAMS, self::VIEW_COURSES,
                self::VIEW_KNOWLEDGE,
            ],

            'operations_manager' => [
                self::VIEW_DEPARTMENTS, self::MANAGE_DEPARTMENTS,
                self::VIEW_TASKS, self::MANAGE_TASKS,
                self::VIEW_MEETINGS, self::MANAGE_MEETINGS,
                self::VIEW_FORMS, self::MANAGE_FORMS,
                self::VIEW_PROGRAMS, self::VIEW_COURSES,
                self::VIEW_SESSIONS, self::VIEW_ATTENDANCE, self::VIEW_ASSIGNMENTS,
                self::VIEW_KPI, self::VIEW_REPORTS, self::GENERATE_REPORTS,
                self::VIEW_KNOWLEDGE, self::USE_AI_ASSISTANT,
                // Granular
                self::WR_VIEW_ALL, self::WR_REVIEW, self::WR_APPROVE, self::WR_REJECT,
                self::WR_ASSIGN, self::WR_EXPORT,
                self::COURSES_VIEW, self::ATTENDANCE_VIEW, self::ASSIGNMENTS_VIEW,
                self::MEMBERS_VIEW, self::DEPTS_VIEW, self::DEPTS_MANAGE,
            ],

            'programs_manager' => [
                self::VIEW_PROGRAMS, self::MANAGE_PROGRAMS,
                self::VIEW_COURSES, self::MANAGE_COURSES,
                self::VIEW_WORKSHOPS, self::VIEW_TRACKS, self::MANAGE_TRACKS,
                self::VIEW_REGISTRATIONS,
                self::VIEW_SESSIONS, self::MANAGE_SESSIONS,
                self::VIEW_ATTENDANCE, self::VIEW_ASSIGNMENTS, self::MANAGE_ASSIGNMENTS, self::REVIEW_SUBMISSIONS,
                self::VIEW_MATERIALS, self::MANAGE_MATERIALS,
                self::VIEW_CERTIFICATES,
                self::VIEW_KPI, self::VIEW_REPORTS, self::GENERATE_REPORTS,
                self::VIEW_KNOWLEDGE, self::MANAGE_KNOWLEDGE, self::USE_AI_ASSISTANT,
                // Granular
                self::WR_VIEW_ALL, self::WR_REVIEW, self::WR_APPROVE, self::WR_REJECT,
                self::WR_ASSIGN, self::WR_EXPORT,
                self::COURSES_VIEW, self::COURSES_CREATE, self::COURSES_UPDATE,
                self::COURSES_DELETE, self::COURSES_ASSIGN_INSTRUCTOR, self::COURSES_MANAGE,
                self::ATTENDANCE_VIEW, self::ASSIGNMENTS_VIEW,
                self::ASSIGNMENTS_CREATE, self::ASSIGNMENTS_UPDATE, self::ASSIGNMENTS_MANAGE,
                self::MEMBERS_VIEW,
            ],

            'partnerships_manager' => [
                self::VIEW_PARTNERS, self::MANAGE_PARTNERS, self::VIEW_PARTNER_REPORTS,
                self::VIEW_PROGRAMS, self::VIEW_COURSES,
                self::VIEW_REGISTRATIONS,
                self::VIEW_REPORTS, self::GENERATE_REPORTS,
                self::VIEW_KNOWLEDGE, self::USE_AI_ASSISTANT,
                // Granular
                self::WR_VIEW_ALL, self::WR_REVIEW, self::WR_APPROVE, self::WR_REJECT,
                self::COURSES_VIEW, self::MEMBERS_VIEW,
            ],

            'community_manager' => [
                self::VIEW_VOLUNTEERS, self::MANAGE_VOLUNTEERS,
                self::VIEW_TASKS, self::MANAGE_TASKS,
                self::VIEW_MEETINGS, self::MANAGE_MEETINGS,
                self::VIEW_FORMS, self::MANAGE_FORMS,
                self::VIEW_REGISTRATIONS,
                self::VIEW_REPORTS,
                self::VIEW_KNOWLEDGE, self::USE_AI_ASSISTANT,
                // Granular
                self::WR_VIEW_ALL, self::WR_REVIEW, self::WR_APPROVE, self::WR_REJECT,
                self::MEMBERS_VIEW, self::MEMBERS_RECOGNIZE, self::MEMBERS_VIEW_DEPARTMENT,
            ],

            'finance_manager' => [
                self::VIEW_PAYMENTS, self::MANAGE_PAYMENTS, self::VIEW_REVENUE, self::MANAGE_COUPONS,
                self::MANAGE_SCHOLARSHIPS, self::VIEW_FINANCIAL_REPORTS,
                self::VIEW_REGISTRATIONS,
                self::VIEW_KPI, self::VIEW_REPORTS, self::GENERATE_REPORTS, self::EXPORT_REPORTS,
                // Granular
                self::WR_VIEW_DEPARTMENT, self::WR_REVIEW, self::WR_APPROVE, self::WR_REJECT,
                self::MEMBERS_VIEW,
                // Legacy
                self::VIEW_FINANCE, self::MANAGE_PAYMENTS, self::MANAGE_REPORTS,
            ],

            'marketing_manager' => [
                self::VIEW_MARKETING, self::MANAGE_MARKETING, self::MANAGE_PRODUCT_UPDATES, self::VIEW_PROGRAMS, self::VIEW_COURSES,
                self::VIEW_WORKSHOPS, self::VIEW_TRACKS, self::VIEW_REGISTRATIONS, self::VIEW_REPORTS,
                // Granular
                self::WR_VIEW_DEPARTMENT, self::WR_REVIEW, self::WR_APPROVE, self::WR_REJECT,
                self::MEMBERS_VIEW,
            ],

            'quality_manager' => [
                self::VIEW_QUALITY, self::MANAGE_QUALITY, self::APPROVE_QUALITY_REVIEWS,
                self::VIEW_PROGRAMS, self::VIEW_COURSES, self::VIEW_SESSIONS, self::VIEW_ATTENDANCE,
                self::VIEW_KPI, self::VIEW_REPORTS, self::GENERATE_REPORTS,
                // Granular
                self::WR_VIEW_DEPARTMENT, self::WR_REVIEW, self::WR_APPROVE, self::WR_REJECT,
                self::MEMBERS_VIEW, self::DEPTS_VIEW,
                // Legacy
                self::MANAGE_QUALITY, self::MANAGE_REPORTS, self::VIEW_KPI,
            ],

            'hr_manager' => [
                self::VIEW_VOLUNTEERS, self::MANAGE_VOLUNTEERS, self::VIEW_TEAM, self::MANAGE_TEAM,
                self::VIEW_USERS, self::VIEW_REPORTS,
            ],

            'support_agent' => [
                self::VIEW_SUPPORT_TICKETS, self::MANAGE_SUPPORT_TICKETS,
                self::VIEW_USERS, self::VIEW_REGISTRATIONS,
            ],

            'teacher', 'instructor' => [
                self::VIEW_COURSES, self::VIEW_SESSIONS, self::VIEW_ATTENDANCE, self::MANAGE_ATTENDANCE,
                self::VIEW_ASSIGNMENTS, self::REVIEW_SUBMISSIONS, self::VIEW_MATERIALS, self::MANAGE_MATERIALS,
                self::VIEW_CERTIFICATES, self::VIEW_KNOWLEDGE, self::USE_AI_ASSISTANT,
                // Granular
                self::ATTENDANCE_VIEW, self::ATTENDANCE_MARK, self::ATTENDANCE_UPDATE,
                self::ASSIGNMENTS_VIEW, self::ASSIGNMENTS_CREATE, self::ASSIGNMENTS_REVIEW,
                self::COURSES_VIEW,
            ],

            'student' => [
                self::VIEW_PROGRAMS, self::VIEW_COURSES, self::VIEW_WORKSHOPS, self::VIEW_TRACKS,
                self::VIEW_REGISTRATIONS, self::VIEW_SESSIONS, self::VIEW_ASSIGNMENTS, self::VIEW_MATERIALS,
                self::VIEW_CERTIFICATES, self::VIEW_KNOWLEDGE, self::USE_AI_ASSISTANT,
                // Granular
                self::WR_VIEW_OWN, self::WR_CREATE,
                self::COURSES_VIEW, self::ATTENDANCE_VIEW, self::ASSIGNMENTS_VIEW,
                self::MEMBERS_VIEW,
            ],

            'volunteer' => [
                self::VIEW_TASKS, self::VIEW_MEETINGS, self::VIEW_FORMS, self::VIEW_KNOWLEDGE,
            ],

            'partner' => [
                self::VIEW_PARTNERS, self::VIEW_PARTNER_REPORTS, self::VIEW_PROGRAMS, self::VIEW_REPORTS,
            ],

            default => [],
        };
    }

    /** Grouped permissions array (for permission seeder display_name and group). */
    public static function grouped(): array
    {
        return [
            'users'        => [self::VIEW_USERS, self::CREATE_USERS, self::UPDATE_USERS, self::DELETE_USERS, self::MANAGE_ROLES, self::IMPERSONATE_USERS, self::USERS_ANALYTICS_VIEW, self::USERS_ACTIVITY_VIEW, self::USERS_ACQUISITION_VIEW],
            'education'    => [self::VIEW_PROGRAMS, self::MANAGE_PROGRAMS, self::VIEW_COURSES, self::MANAGE_COURSES, self::VIEW_WORKSHOPS, self::MANAGE_WORKSHOPS, self::VIEW_TRACKS, self::MANAGE_TRACKS, self::VIEW_REGISTRATIONS, self::MANAGE_REGISTRATIONS],
            'lms'          => [self::VIEW_SESSIONS, self::MANAGE_SESSIONS, self::VIEW_ATTENDANCE, self::MANAGE_ATTENDANCE, self::VIEW_ASSIGNMENTS, self::MANAGE_ASSIGNMENTS, self::REVIEW_SUBMISSIONS, self::VIEW_MATERIALS, self::MANAGE_MATERIALS],
            'finance'      => [self::VIEW_PAYMENTS, self::MANAGE_PAYMENTS, self::VIEW_REVENUE, self::MANAGE_COUPONS, self::MANAGE_SCHOLARSHIPS, self::VIEW_FINANCIAL_REPORTS],
            'certificates' => [self::VIEW_CERTIFICATES, self::MANAGE_CERTIFICATES, self::ISSUE_CERTIFICATES, self::REVOKE_CERTIFICATES],
            'operations'   => [self::VIEW_DEPARTMENTS, self::MANAGE_DEPARTMENTS, self::VIEW_TASKS, self::MANAGE_TASKS, self::VIEW_MEETINGS, self::MANAGE_MEETINGS, self::VIEW_FORMS, self::MANAGE_FORMS],
            'hr'           => [self::VIEW_VOLUNTEERS, self::MANAGE_VOLUNTEERS, self::VIEW_TEAM, self::MANAGE_TEAM],
            'partnerships' => [self::VIEW_PARTNERS, self::MANAGE_PARTNERS, self::VIEW_PARTNER_REPORTS],
            'marketing'    => [self::VIEW_MARKETING, self::MANAGE_MARKETING],
            'quality'      => [self::VIEW_QUALITY, self::MANAGE_QUALITY, self::APPROVE_QUALITY_REVIEWS],
            'support'      => [self::VIEW_SUPPORT_TICKETS, self::MANAGE_SUPPORT_TICKETS],
            'reports'      => [self::VIEW_KPI, self::VIEW_REPORTS, self::GENERATE_REPORTS, self::EXPORT_REPORTS],
            'knowledge'    => [self::VIEW_KNOWLEDGE, self::MANAGE_KNOWLEDGE],
            'ai'           => [self::USE_AI_ASSISTANT, self::MANAGE_AI_PROMPTS, self::VIEW_AI_USAGE],
            'system'           => [self::VIEW_AUDIT_LOGS, self::MANAGE_SETTINGS, self::MANAGE_INTEGRATIONS, self::MANAGE_WEBHOOKS, self::MANAGE_API_TOKENS],
            'tech'             => [self::TECH_DEPARTMENT_DASHBOARDS_VIEW],
            'executive'        => [self::EXECUTIVE_COMMAND_CENTER_VIEW],
            'tickets'          => [self::TICKETS_REVIEW_COMPLETION, self::TICKETS_REVIEW_PARTICIPATED],
            // Granular module groups
            'workshop_requests'=> [self::WR_VIEW_OWN, self::WR_VIEW_DEPARTMENT, self::WR_VIEW_ALL, self::WR_CREATE, self::WR_REVIEW, self::WR_APPROVE, self::WR_REJECT, self::WR_ASSIGN, self::WR_EDIT, self::WR_DELETE, self::WR_EXPORT, self::WR_MANAGE],
            'members'          => [self::MEMBERS_VIEW, self::MEMBERS_VIEW_DEPARTMENT, self::MEMBERS_CREATE, self::MEMBERS_UPDATE, self::MEMBERS_DELETE, self::MEMBERS_RECOGNIZE, self::MEMBERS_MANAGE],
            'departments_g'    => [self::DEPTS_VIEW, self::DEPTS_VIEW_OWN, self::DEPTS_UPDATE_OWN, self::DEPTS_MANAGE],
            'courses_g'        => [self::COURSES_VIEW, self::COURSES_CREATE, self::COURSES_UPDATE, self::COURSES_DELETE, self::COURSES_ASSIGN_INSTRUCTOR, self::COURSES_MANAGE],
            'attendance_g'     => [self::ATTENDANCE_VIEW, self::ATTENDANCE_MARK, self::ATTENDANCE_UPDATE, self::ATTENDANCE_MANAGE],
            'assignments_g'    => [self::ASSIGNMENTS_VIEW, self::ASSIGNMENTS_CREATE, self::ASSIGNMENTS_UPDATE, self::ASSIGNMENTS_DELETE, self::ASSIGNMENTS_REVIEW, self::ASSIGNMENTS_MANAGE],
        ];
    }
}
