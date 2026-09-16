<?php

namespace App\Support\PageAccess;

/**
 * Phase 2G.3A — CANONICAL ROLE DEFAULT MAPPING.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * PAGE ACCESS != BUSINESS AUTHORIZATION
 * ─────────────────────────────────────────────────────────────────────────
 * A mapping entry proposes that a role's users should be able to OPEN a
 * dashboard page by default. It grants no permission, no policy and no data
 * visibility — every endpoint behind these pages still authorizes itself.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHY THIS LIVES IN THE BACKEND, AS DATA
 * ─────────────────────────────────────────────────────────────────────────
 * The proposal was DERIVED from the legacy frontend rules, but it is not read
 * from them at runtime: TypeScript is not a backend authorization source, and a
 * seeding plan must be reviewable as a diff. So the derivation was done once,
 * offline, and its result is frozen here as an explicit, auditable list keyed by
 * stable page_key — never by URL.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHAT IS DELIBERATELY ABSENT
 * ─────────────────────────────────────────────────────────────────────────
 *   - super_admin / tech_admin — root authority already covers every page;
 *     duplicating it into role defaults would create a second, mutable copy of
 *     root that an admin could edit.
 *   - The 6 AUTHENTICATED BASELINE pages — already granted to everyone by the
 *     catalog (Phase 2G.2). A role row would be redundant and would wrongly
 *     imply the role is what grants them.
 *   - Every SYSTEM_PROTECTED page — structurally unstorable anyway
 *     (RolePageDefaultService rejects them), listed here as intent too.
 *   - The 4 shared operations surfaces (weekly_reports, meeting_reports, board,
 *     impact_points) — DEFERRED_PRODUCT_DECISION: role default vs department
 *     member default is unresolved.
 *   - organizational_departments.members — DEFERRED pending the data-exposure
 *     decision.
 *   - The 3 leader-owned surfaces (structure, permissions_delegation,
 *     department_financial_requests) — those belong to canonical leadership via
 *     departments.leader_id, and a "*_manager" role slug is NOT leadership.
 */
final class RoleDefaultMap
{
    /**
     * Capabilities intentionally excluded from every role, with the reason.
     * Consumed by the dry-run command so each skip is reported, not silent.
     */
    public const DEFERRED_PRODUCT_DECISION = [
        'operations.weekly_reports',
        'operations.meeting_reports',
        'operations.board',
        'operations.impact_points',
        'organizational_departments.members',
    ];

    /** Owned by canonical department leadership, never by a role slug. */
    public const LEADER_OWNED = [
        'organizational_departments.structure',
        'organizational_departments.permissions_delegation',
        'operations.department_financial_requests',
    ];

    /** Root roles are covered by root authority and must never be seeded. */
    public const ROOT_ROLES = ['super_admin', 'tech_admin'];

    /**
     * ROLE -> PAGE_KEYS. Identity is the stable catalog key, never a URL.
     *
     * @var array<string, array<int, string>>
     */
    private const MAP = [
        'admin' => [
            'administration.admin_audit_logs',
            'administration.admin_overview',
            'administration.catalog_import',
            'administration.documents',
            'administration.integrations',
            'administration.knowledge_hub',
            'administration.quality_admin_overview',
            'administration.team_members',
            'administration.tickets_admin',
            'administration.visitor_analytics',
            'administration.webhooks',
            'finance.chart_of_accounts',
            'finance.coupons',
            'finance.program_approvals',
            'finance.scholarships',
            'lms.assignments',
            'lms.attendance',
            'lms.certificates',
            'lms.evaluations',
            'lms.institute_admin',
            'lms.lessons',
            'lms.materials',
            'lms.modules',
            'lms.programs',
            'lms.progress',
            'lms.quizzes',
            'lms.sessions',
            'operations.accepted_volunteers',
            'operations.calendar',
            'operations.forms',
            'operations.meeting_lounge',
            'operations.meetings',
            'operations.registrations_requests',
            'operations.tasks',
            'operations.tickets_workspace',
            'operations.volunteers_management',
            'operations.workshop_requests',
            'organizational_departments.consultant_applications',
            'organizational_departments.department_units',
            'organizational_departments.partners',
            'organizational_departments.partnership_requests',
            'personal.resource_center_courses',
            'technical_system.ai_automations',
            'technical_system.ai_command_center',
            'technical_system.ai_insights',
            'technical_system.ai_usage',
            'technical_system.api_tokens',
            'technical_system.automations',
        ],
        'advisors_manager' => [
            'operations.meeting_lounge',
            'organizational_departments.workspace',
        ],
        'ai_manager' => [
            'operations.meeting_lounge',
            'organizational_departments.ai_workspace',
        ],
        'community_manager' => [
            'operations.forms',
            'operations.meeting_lounge',
            'operations.meetings',
            'operations.tasks',
            'operations.volunteers_management',
            'operations.workshop_requests',
            'organizational_departments.community_manager',
            'personal.resource_center_courses',
        ],
        'department_manager' => [
            'operations.meeting_lounge',
            'operations.workshop_requests',
            'organizational_departments.department_overview',
        ],
        'digital_ambassadors_manager' => [
            'operations.meeting_lounge',
            'organizational_departments.workspace',
        ],
        'executive_admin' => [
            'finance.executive_financial_requests',
            'operations.dashboard_overview',
            'operations.kpi_dashboard',
            'operations.meeting_lounge',
            'operations.reports_dashboard',
            'operations.workshop_requests',
            'personal.resource_center_courses',
        ],
        'finance_manager' => [
            'finance.accounts',
            'finance.chart_of_accounts',
            'finance.coupons',
            'finance.dashboard',
            'finance.financial_requests',
            'finance.invoices',
            'finance.manual_payments',
            'finance.orders',
            'finance.payments',
            'finance.program_approvals',
            'finance.transactions',
            'operations.meeting_lounge',
            'operations.workshop_requests',
            'personal.resource_center_courses',
        ],
        'hr_manager' => [
            'hr.ambassador_applications',
            'hr.dashboard',
            'hr.departments_overview',
            'hr.documents',
            'hr.incoming_requests',
            'hr.instructors',
            'hr.onboarding',
            'hr.tasks',
            'hr.team',
            'hr.volunteer_requests',
            'hr.volunteers',
            'operations.accepted_volunteers',
            'operations.meeting_lounge',
            'operations.workshop_requests',
            'personal.resource_center_courses',
        ],
        'instructor' => [
            'operations.meeting_lounge',
            'personal.instructor_workspace',
            'personal.resource_center_courses',
        ],
        'marketing_manager' => [
            'operations.meeting_lounge',
            'operations.workshop_requests',
            'organizational_departments.marketing',
            'personal.resource_center_courses',
        ],
        'operations_manager' => [
            'operations.forms',
            'operations.meeting_lounge',
            'operations.meetings',
            'operations.tasks',
            'operations.workshop_requests',
            'organizational_departments.operations_manager',
            'personal.resource_center_courses',
        ],
        'partner' => [
            'operations.meeting_lounge',
            'personal.partner_portal',
        ],
        'partnerships_manager' => [
            'lms.programs',
            'operations.meeting_lounge',
            'operations.workshop_requests',
            'organizational_departments.partners',
            'organizational_departments.partnership_requests',
            'organizational_departments.partnerships_manager',
            'personal.resource_center_courses',
        ],
        'programs_manager' => [
            'administration.knowledge_hub',
            'lms.assignments',
            'lms.attendance',
            'lms.evaluations',
            'lms.materials',
            'lms.programs',
            'lms.progress',
            'lms.sessions',
            'operations.meeting_lounge',
            'operations.registrations_requests',
            'operations.workshop_requests',
            'organizational_departments.programs_manager',
            'personal.resource_center_courses',
        ],
        'quality_manager' => [
            'operations.meeting_lounge',
            'operations.workshop_requests',
            'personal.resource_center_courses',
            'quality.audit_logs',
            'quality.checklists',
            'quality.compliance',
            'quality.corrective_actions',
            'quality.dashboard',
            'quality.governance',
            'quality.incidents',
            'quality.reports',
            'quality.reviews',
            'quality.team',
        ],
        'section_lead' => [
            'operations.meeting_lounge',
            'organizational_departments.section_lead',
        ],
        'strategy_planning_manager' => [
            'operations.meeting_lounge',
            'organizational_departments.workspace',
        ],
        'student' => [
            'operations.meeting_lounge',
            'personal.student_learning',
        ],
        'support_agent' => [
            'operations.meeting_lounge',
            'operations.support_tickets',
            'operations.tickets_workspace',
            'operations.workshop_requests',
            'personal.resource_center_courses',
        ],
        'volunteer' => [
            'operations.meeting_lounge',
            'personal.resource_center_courses',
        ],
    ];

    /** @return array<string, array<int, string>> */
    public static function all(): array
    {
        return self::MAP;
    }

    /** @return array<int, string> */
    public static function forRole(string $role): array
    {
        return self::MAP[$role] ?? [];
    }

    /** @return array<int, string> */
    public static function roles(): array
    {
        return array_keys(self::MAP);
    }

    /** Total proposed (role, page) pairs. */
    public static function pairCount(): int
    {
        return array_sum(array_map('count', self::MAP));
    }
}
