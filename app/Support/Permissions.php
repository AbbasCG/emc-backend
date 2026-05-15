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
    /** @deprecated Use granular view/create/update/delete_users */
    const MANAGE_USERS  = 'manage_users';

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
    const VIEW_MARKETING   = 'view_marketing';
    const MANAGE_MARKETING = 'manage_marketing';

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

    // ─────────────────────────────────────────────────────────────────────────

    /** Every permission in the system (used by seeders and Gate registration). */
    public static function all(): array
    {
        return [
            // Users
            self::VIEW_USERS, self::CREATE_USERS, self::UPDATE_USERS, self::DELETE_USERS, self::MANAGE_ROLES,
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
            self::VIEW_MARKETING, self::MANAGE_MARKETING,
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
            self::MANAGE_WEBHOOKS, self::MANAGE_API_TOKENS,
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
            'super_admin' => self::all(),

            'executive_admin' => [
                self::VIEW_USERS, self::VIEW_PROGRAMS, self::VIEW_COURSES, self::VIEW_WORKSHOPS,
                self::VIEW_TRACKS, self::VIEW_REGISTRATIONS, self::VIEW_SESSIONS, self::VIEW_ATTENDANCE,
                self::VIEW_ASSIGNMENTS, self::VIEW_MATERIALS, self::VIEW_PAYMENTS, self::VIEW_REVENUE,
                self::VIEW_FINANCIAL_REPORTS, self::VIEW_CERTIFICATES, self::VIEW_DEPARTMENTS,
                self::VIEW_TASKS, self::VIEW_MEETINGS, self::VIEW_FORMS, self::VIEW_VOLUNTEERS,
                self::VIEW_TEAM, self::VIEW_PARTNERS, self::VIEW_PARTNER_REPORTS, self::VIEW_QUALITY,
                self::APPROVE_QUALITY_REVIEWS, self::VIEW_KPI, self::VIEW_REPORTS, self::EXPORT_REPORTS,
                self::VIEW_KNOWLEDGE, self::VIEW_AUDIT_LOGS, self::VIEW_AI_USAGE,
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
                self::VIEW_AUDIT_LOGS,
                // Legacy
                self::MANAGE_USERS, self::MANAGE_LMS, self::VIEW_FINANCE,
            ],

            'department_manager' => [
                self::VIEW_USERS, self::VIEW_PROGRAMS, self::VIEW_COURSES,
                self::VIEW_TASKS, self::MANAGE_TASKS, self::VIEW_MEETINGS, self::MANAGE_MEETINGS, self::VIEW_FORMS,
                self::VIEW_TEAM, self::VIEW_KPI, self::VIEW_REPORTS, self::VIEW_KNOWLEDGE, self::USE_AI_ASSISTANT,
            ],

            'section_lead' => [
                self::VIEW_TASKS, self::MANAGE_TASKS, self::VIEW_MEETINGS, self::VIEW_PROGRAMS, self::VIEW_COURSES,
                self::VIEW_KNOWLEDGE,
            ],

            'finance_manager' => [
                self::VIEW_PAYMENTS, self::MANAGE_PAYMENTS, self::VIEW_REVENUE, self::MANAGE_COUPONS,
                self::MANAGE_SCHOLARSHIPS, self::VIEW_FINANCIAL_REPORTS,
                self::VIEW_REGISTRATIONS,
                self::VIEW_KPI, self::VIEW_REPORTS, self::GENERATE_REPORTS, self::EXPORT_REPORTS,
                // Legacy
                self::VIEW_FINANCE, self::MANAGE_PAYMENTS, self::MANAGE_REPORTS,
            ],

            'marketing_manager' => [
                self::VIEW_MARKETING, self::MANAGE_MARKETING, self::VIEW_PROGRAMS, self::VIEW_COURSES,
                self::VIEW_WORKSHOPS, self::VIEW_TRACKS, self::VIEW_REGISTRATIONS, self::VIEW_REPORTS,
            ],

            'quality_manager' => [
                self::VIEW_QUALITY, self::MANAGE_QUALITY, self::APPROVE_QUALITY_REVIEWS,
                self::VIEW_PROGRAMS, self::VIEW_COURSES, self::VIEW_SESSIONS, self::VIEW_ATTENDANCE,
                self::VIEW_KPI, self::VIEW_REPORTS, self::GENERATE_REPORTS,
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
                // Legacy
                self::MANAGE_COURSES, self::MANAGE_LMS, self::MANAGE_KNOWLEDGE,
            ],

            'student' => [
                self::VIEW_PROGRAMS, self::VIEW_COURSES, self::VIEW_WORKSHOPS, self::VIEW_TRACKS,
                self::VIEW_REGISTRATIONS, self::VIEW_SESSIONS, self::VIEW_ASSIGNMENTS, self::VIEW_MATERIALS,
                self::VIEW_CERTIFICATES, self::VIEW_KNOWLEDGE, self::USE_AI_ASSISTANT,
            ],

            'volunteer' => [
                self::VIEW_TASKS, self::VIEW_MEETINGS, self::VIEW_FORMS, self::VIEW_KNOWLEDGE,
            ],

            'partner' => [
                self::VIEW_PARTNERS, self::VIEW_PARTNER_REPORTS, self::VIEW_PROGRAMS, self::VIEW_REPORTS,
                // Legacy
                self::MANAGE_PARTNERS,
            ],

            default => [],
        };
    }

    /** Grouped permissions array (for permission seeder display_name and group). */
    public static function grouped(): array
    {
        return [
            'users'        => [self::VIEW_USERS, self::CREATE_USERS, self::UPDATE_USERS, self::DELETE_USERS, self::MANAGE_ROLES],
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
            'system'       => [self::VIEW_AUDIT_LOGS, self::MANAGE_SETTINGS, self::MANAGE_INTEGRATIONS, self::MANAGE_WEBHOOKS, self::MANAGE_API_TOKENS],
        ];
    }
}
