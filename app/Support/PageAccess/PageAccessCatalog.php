<?php

namespace App\Support\PageAccess;

use App\Support\Permissions as P;

/**
 * Canonical, deterministic registry of dashboard pages/capabilities.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * PAGE ACCESS != BUSINESS AUTHORIZATION
 * ─────────────────────────────────────────────────────────────────────────
 * This catalog describes whether a dashboard capability/page MAY be exposed
 * to a user. It is intentionally shallow and must NEVER replace:
 *   - backend permissions (App\Support\Permissions)
 *   - policies
 *   - DepartmentAccessService / departments.leader_id
 *   - controller authorization
 *   - business-specific validation
 *
 * Example: a user may have catalog access to `operations.weekly_reports`,
 * but creating/editing a report still goes through the canonical backend
 * authorization for that action.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * PHASE 1.1 — full route-coverage pass
 * ─────────────────────────────────────────────────────────────────────────
 * Every active `/dashboard/*` route declared in src/App.tsx (315 <Route>
 * elements as of this pass) was inventoried and classified. Routes that are
 * index/show/create/edit variants of the same access surface — or alternate
 * mount points rendering the identical page component — are normalized into
 * ONE capability with multiple `routePatterns` rather than one entry per
 * URL (see PageAccessEntry). Routes that are dead client-side redirects
 * (`<Navigate>`), feature-flagged "coming soon" placeholders with zero
 * backend capability behind them, or shared content-consumption routes with
 * no distinct access boundary of their own, are deliberately NOT cataloged
 * — see the Phase 1.1 audit report for the full route-by-route reasoning.
 *
 * No per-user overrides, DB storage, or ALLOW/DENY state live here — that is
 * explicitly deferred to a later phase. This class has no database
 * dependency, so it is deterministic and safe to use anywhere without a query.
 */
final class PageAccessCatalog
{
    /** @var array<int, PageAccessEntry>|null */
    private static ?array $entries = null;

    /** @return array<int, PageAccessEntry> */
    public static function all(): array
    {
        return self::$entries ??= self::build();
    }

    public static function find(string $key): ?PageAccessEntry
    {
        foreach (self::all() as $entry) {
            if ($entry->key === $key) {
                return $entry;
            }
        }

        return null;
    }

    /** Find the capability whose route patterns include this exact dashboard URL (static match only). */
    public static function findByRoute(string $route): ?PageAccessEntry
    {
        foreach (self::all() as $entry) {
            if ($entry->matchesRoute($route)) {
                return $entry;
            }
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    public static function toArray(): array
    {
        return array_map(static fn (PageAccessEntry $e) => $e->toArray(), self::all());
    }

    /** @return array<int, PageAccessEntry> */
    private static function build(): array
    {
        $C = PageAccessCategory::class;
        $R = PageAccessRiskLevel::class;

        // [key, primaryRoute, labelAr, category, riskLevel, delegatable, requiredPermission, departmentScoped, extraPatterns[]]
        $rows = [
            // ── Operations ──────────────────────────────────────────────────
            ['operations.board', '/dashboard/operations/board', 'لوحة العمليات', $C::Operations, $R::SafeDelegatable, true, P::VIEW_TASKS, true, []],
            ['operations.weekly_reports', '/dashboard/operations/weekly-reports', 'التقارير الأسبوعية', $C::Operations, $R::SafeDelegatable, true, P::VIEW_REPORTS, true, []],
            ['operations.meeting_reports', '/dashboard/operations/meeting-reports', 'محاضر الاجتماعات', $C::Operations, $R::SafeDelegatable, true, P::VIEW_MEETINGS, true, []],
            ['operations.impact_points', '/dashboard/operations/impact-points', 'نقاط الأثر', $C::Operations, $R::SafeDelegatable, true, P::VIEW_REPORTS, true, []],
            ['operations.workshop_requests', '/dashboard/admin/workshop-requests', 'طلبات الورش', $C::Operations, $R::AdminOnly, false, P::WR_VIEW_ALL, false, [
                '/dashboard/admin/workshop-requests/:id',
            ]],
            ['operations.tasks', '/dashboard/admin/tasks', 'المهام', $C::Operations, $R::SafeDelegatable, true, P::VIEW_TASKS, true, [
                '/dashboard/admin/tasks/kanban', '/dashboard/admin/tasks/my', '/dashboard/admin/tasks/overdue',
            ]],
            ['operations.meetings', '/dashboard/admin/meetings', 'الاجتماعات', $C::Operations, $R::SafeDelegatable, true, P::VIEW_MEETINGS, true, [
                '/dashboard/admin/meetings/:id',
            ]],
            ['operations.forms', '/dashboard/admin/forms', 'النماذج', $C::Operations, $R::SafeDelegatable, true, P::VIEW_FORMS, true, [
                '/dashboard/admin/forms/create', '/dashboard/admin/forms/:id',
            ]],
            ['operations.dashboard_overview', '/dashboard/executive/operations', 'نظرة عامة على العمليات', $C::Operations, $R::AdminOnly, false, null, false, [
                '/dashboard/executive', '/dashboard/admin/operations',
            ]],
            ['operations.kpi_dashboard', '/dashboard/executive/kpi', 'مؤشرات الأداء', $C::Operations, $R::AdminOnly, false, P::VIEW_KPI, false, [
                '/dashboard/admin/kpi',
            ]],
            ['operations.reports_dashboard', '/dashboard/executive/reports', 'التقارير الإدارية', $C::Operations, $R::AdminOnly, false, P::VIEW_REPORTS, false, [
                '/dashboard/admin/reports',
            ]],
            ['operations.department_financial_requests', '/dashboard/department/financial-requests', 'الطلبات المالية للإدارة', $C::Operations, $R::SafeDelegatable, true, P::VIEW_FINANCIAL_REPORTS, true, []],
            ['operations.meeting_lounge', '/dashboard/department/meeting-lounge', 'صالة اجتماعات الإدارة', $C::Operations, $R::SafeDelegatable, true, P::VIEW_MEETINGS, true, [
                '/dashboard/department/meeting-lounge/:id',
            ]],
            ['operations.accepted_volunteers', '/dashboard/volunteer', 'المتطوعون المقبولون', $C::Operations, $R::SafeDelegatable, true, P::VIEW_VOLUNTEERS, true, [
                '/dashboard/volunteer/:id',
            ]],
            ['operations.volunteers_management', '/dashboard/admin/volunteers', 'إدارة المتطوعين', $C::Operations, $R::AdminOnly, false, P::MANAGE_VOLUNTEERS, false, [
                '/dashboard/admin/volunteers/:id', '/dashboard/ops/volunteers',
            ]],
            ['operations.support_tickets', '/dashboard/support', 'تذاكر الدعم الفني', $C::Operations, $R::SafeDelegatable, true, P::VIEW_SUPPORT_TICKETS, true, [
                '/dashboard/support/:id', '/dashboard/admin/support-tickets', '/dashboard/admin/support-tickets/:id',
            ]],
            ['operations.tickets', '/dashboard/tickets', 'تذاكر EMC', $C::Operations, $R::SafeDelegatable, false, null, false, [
                '/dashboard/tickets/new', '/dashboard/tickets/:id',
            ], true],
            ['operations.tickets_workspace', '/dashboard/tickets/workspace', 'مساحة عمل التذاكر', $C::Operations, $R::SafeDelegatable, true, null, false, []],
            ['operations.registrations_requests', '/dashboard/admin/registrations', 'طلبات التسجيل', $C::Operations, $R::AdminOnly, false, P::VIEW_REGISTRATIONS, false, []],
            ['operations.calendar', '/dashboard/admin/calendar', 'التقويم الإداري', $C::Operations, $R::AdminOnly, false, null, false, []],

            // ── Finance ──────────────────────────────────────────────────────
            ['finance.dashboard', '/dashboard/finance', 'لوحة المالية', $C::Finance, $R::AdminOnly, false, P::VIEW_PAYMENTS, false, [
                '/dashboard/admin/finance',
            ]],
            ['finance.payments', '/dashboard/finance/payments', 'المدفوعات', $C::Finance, $R::AdminOnly, false, P::VIEW_PAYMENTS, false, [
                '/dashboard/admin/finance/payments',
            ]],
            ['finance.transactions', '/dashboard/finance/transactions', 'المعاملات المالية', $C::Finance, $R::AdminOnly, false, P::VIEW_REVENUE, false, [
                '/dashboard/admin/finance/transactions',
            ]],
            ['finance.chart_of_accounts', '/dashboard/finance/chart-of-accounts', 'دليل الحسابات', $C::Finance, $R::AdminOnly, false, P::MANAGE_PAYMENTS, false, []],
            ['finance.financial_requests', '/dashboard/finance/financial-requests', 'الطلبات المالية', $C::Finance, $R::SafeDelegatable, true, P::VIEW_FINANCIAL_REPORTS, true, [
                '/dashboard/admin/finance/financial-requests',
            ]],
            ['finance.manual_payments', '/dashboard/finance/manual-payments', 'الدفعات اليدوية', $C::Finance, $R::AdminOnly, false, P::MANAGE_PAYMENTS, false, [
                '/dashboard/admin/finance/manual-payments',
            ]],
            ['finance.coupons', '/dashboard/admin/coupons', 'الكوبونات', $C::Finance, $R::AdminOnly, false, P::MANAGE_COUPONS, false, []],
            ['finance.scholarships', '/dashboard/admin/scholarships', 'المنح الدراسية', $C::Finance, $R::AdminOnly, false, P::MANAGE_SCHOLARSHIPS, false, []],
            ['finance.orders', '/dashboard/finance/orders', 'طلبات الشراء', $C::Finance, $R::AdminOnly, false, P::VIEW_PAYMENTS, false, [
                '/dashboard/admin/finance/orders',
            ]],
            ['finance.invoices', '/dashboard/finance/invoices', 'الفواتير', $C::Finance, $R::AdminOnly, false, P::VIEW_PAYMENTS, false, [
                '/dashboard/admin/finance/invoices',
            ]],
            ['finance.accounts', '/dashboard/finance/accounts', 'الحسابات المالية', $C::Finance, $R::AdminOnly, false, P::VIEW_REVENUE, false, [
                '/dashboard/admin/finance/accounts',
            ]],
            ['finance.program_approvals', '/dashboard/finance/program-approvals', 'اعتماد البرامج', $C::Finance, $R::AdminOnly, false, P::MANAGE_PROGRAMS, false, []],
            ['finance.executive_financial_requests', '/dashboard/executive/financial-requests', 'الطلبات المالية التنفيذية', $C::Finance, $R::AdminOnly, false, P::VIEW_FINANCIAL_REPORTS, false, []],

            // ── HR ───────────────────────────────────────────────────────────
            ['hr.dashboard', '/dashboard/hr', 'لوحة الموارد البشرية', $C::Hr, $R::AdminOnly, false, P::VIEW_TEAM, false, []],
            ['hr.my_requests', '/dashboard/hr/my-requests', 'طلباتي', $C::Hr, $R::SafeDelegatable, true, null, true, [
                '/dashboard/department/hr-requests',
            ], true],
            ['hr.incoming_requests', '/dashboard/hr/incoming-requests', 'الطلبات الواردة', $C::Hr, $R::SafeDelegatable, true, P::MANAGE_TEAM, true, []],
            ['hr.volunteers', '/dashboard/hr/volunteers', 'المتطوعون', $C::Hr, $R::SafeDelegatable, true, P::VIEW_VOLUNTEERS, true, [
                '/dashboard/hr/volunteers/:id',
            ]],
            ['hr.instructors', '/dashboard/hr/instructors', 'المدربون', $C::Hr, $R::AdminOnly, false, P::VIEW_TEAM, false, []],
            ['hr.onboarding', '/dashboard/hr/onboarding', 'التهيئة', $C::Hr, $R::SafeDelegatable, true, P::MANAGE_TEAM, true, []],
            ['hr.volunteer_requests', '/dashboard/hr/volunteer-requests', 'طلبات المتطوعين', $C::Hr, $R::SafeDelegatable, true, P::MANAGE_VOLUNTEERS, true, [
                '/dashboard/hr/volunteer-requests/:id', '/dashboard/super-admin/volunteer-requests', '/dashboard/super-admin/volunteer-requests/:id',
            ]],
            ['hr.ambassador_applications', '/dashboard/hr/ambassador-applications', 'طلبات السفراء', $C::Hr, $R::SafeDelegatable, true, P::MANAGE_VOLUNTEERS, true, [
                '/dashboard/hr/ambassador-applications/:id', '/dashboard/super-admin/ambassador-applications', '/dashboard/super-admin/ambassador-applications/:id',
            ]],
            ['hr.team', '/dashboard/hr/team', 'فريق الموارد البشرية', $C::Hr, $R::AdminOnly, false, P::VIEW_TEAM, false, []],
            ['hr.departments_overview', '/dashboard/hr/departments', 'الإدارات (موارد بشرية)', $C::Hr, $R::AdminOnly, false, P::DEPTS_VIEW, false, []],
            ['hr.tasks', '/dashboard/hr/tasks', 'مهام الموارد البشرية', $C::Hr, $R::SafeDelegatable, true, P::VIEW_TASKS, true, []],
            ['hr.documents', '/dashboard/hr/documents', 'مستندات الموارد البشرية', $C::Hr, $R::AdminOnly, false, null, false, []],

            // ── Quality ──────────────────────────────────────────────────────
            ['quality.dashboard', '/dashboard/quality', 'لوحة الجودة', $C::Quality, $R::AdminOnly, false, P::VIEW_QUALITY, false, []],
            ['quality.reviews', '/dashboard/quality/reviews', 'المراجعات', $C::Quality, $R::SafeDelegatable, true, P::APPROVE_QUALITY_REVIEWS, true, []],
            ['quality.incidents', '/dashboard/quality/incidents', 'الحوادث', $C::Quality, $R::AdminOnly, false, P::MANAGE_QUALITY, false, []],
            ['quality.corrective_actions', '/dashboard/quality/corrective-actions', 'الإجراءات التصحيحية', $C::Quality, $R::AdminOnly, false, P::MANAGE_QUALITY, false, []],
            ['quality.compliance', '/dashboard/quality/compliance', 'الامتثال', $C::Quality, $R::AdminOnly, false, P::MANAGE_QUALITY, false, []],
            ['quality.governance', '/dashboard/quality/governance', 'الحوكمة', $C::Quality, $R::AdminOnly, false, P::MANAGE_QUALITY, false, []],
            ['quality.audit_logs', '/dashboard/quality/audit-logs', 'سجلات التدقيق', $C::Quality, $R::AdminOnly, false, P::VIEW_AUDIT_LOGS, false, []],
            ['quality.reports', '/dashboard/quality/reports', 'تقارير الجودة', $C::Quality, $R::SafeDelegatable, true, P::VIEW_REPORTS, true, []],
            ['quality.checklists', '/dashboard/quality/checklists', 'قوائم الجودة', $C::Quality, $R::AdminOnly, false, P::MANAGE_QUALITY, false, []],
            ['quality.team', '/dashboard/quality/team', 'فريق الجودة', $C::Quality, $R::AdminOnly, false, P::VIEW_TEAM, false, []],

            // ── LMS ──────────────────────────────────────────────────────────
            ['lms.sessions', '/dashboard/admin/lms/sessions', 'الجلسات', $C::Lms, $R::SafeDelegatable, true, P::VIEW_SESSIONS, true, []],
            ['lms.attendance', '/dashboard/admin/lms/attendance', 'الحضور', $C::Lms, $R::SafeDelegatable, true, P::VIEW_ATTENDANCE, true, []],
            ['lms.assignments', '/dashboard/admin/lms/assignments', 'الواجبات', $C::Lms, $R::SafeDelegatable, true, P::VIEW_ASSIGNMENTS, true, []],
            ['lms.materials', '/dashboard/admin/lms/materials', 'المواد التعليمية', $C::Lms, $R::SafeDelegatable, true, P::VIEW_MATERIALS, true, [
                '/dashboard/admin/courses',
            ]],
            ['lms.evaluations', '/dashboard/admin/lms/evaluations', 'التقييمات', $C::Lms, $R::AdminOnly, false, P::MANAGE_MATERIALS, false, [
                '/dashboard/admin/evaluations',
            ]],
            ['lms.certificates', '/dashboard/admin/certificates', 'الشهادات', $C::Lms, $R::AdminOnly, false, P::MANAGE_CERTIFICATES, false, [
                '/dashboard/admin/certificates/issue', '/dashboard/admin/certificates/templates', '/dashboard/admin/certificates/templates/:id/designer',
                '/dashboard/admin/certificates/batches', '/dashboard/admin/certificates/batches/:batchId', '/dashboard/admin/certificates/overview',
                '/dashboard/admin/certificates/analytics', '/dashboard/admin/certificates/:id',
                '/dashboard/programs-manager/certificates', '/dashboard/programs-manager/certificates/issue',
            ]],
            ['lms.programs', '/dashboard/admin/programs', 'البرامج', $C::Lms, $R::SafeDelegatable, true, P::MANAGE_PROGRAMS, true, [
                '/dashboard/super-admin/crud/programs', '/dashboard/executive/programs', '/dashboard/department/programs',
            ]],
            ['lms.learning_paths', '/dashboard/super-admin/crud/learning-paths', 'المسارات التعليمية', $C::Lms, $R::SafeDelegatable, true, P::MANAGE_TRACKS, true, [
                '/dashboard/tech-admin/learning-paths', '/dashboard/programs-manager/learning-paths',
            ]],
            ['lms.progress', '/dashboard/admin/lms/progress', 'تقدم الطلاب', $C::Lms, $R::AdminOnly, false, null, false, [
                '/dashboard/admin/progress',
            ]],
            ['lms.institute_admin', '/dashboard/admin/institute', 'إدارة المعهد', $C::Lms, $R::AdminOnly, false, null, false, [
                '/dashboard/admin/institute/levels/new', '/dashboard/admin/institute/levels/:id/edit',
                '/dashboard/admin/institute/classes/new', '/dashboard/admin/institute/classes/:id/edit',
            ]],
            ['lms.modules', '/dashboard/admin/modules', 'الوحدات التعليمية', $C::Lms, $R::AdminOnly, false, null, false, []],
            ['lms.lessons', '/dashboard/admin/lessons', 'الدروس', $C::Lms, $R::AdminOnly, false, null, false, []],
            ['lms.quizzes', '/dashboard/admin/quizzes', 'الاختبارات القصيرة', $C::Lms, $R::AdminOnly, false, null, false, []],

            // ── Organizational Departments ─────────────────────────────────
            // Includes the organizational "AI Manager" workspace — kept
            // deliberately separate from technical_system.ai_* below (Step 9).
            ['organizational_departments.workspace', '/dashboard/department-workspace', 'مساحة عمل الإدارة', $C::OrganizationalDepartments, $R::SafeDelegatable, true, null, true, []],
            ['organizational_departments.members', '/dashboard/members', 'الأعضاء', $C::OrganizationalDepartments, $R::SafeDelegatable, true, P::MEMBERS_VIEW, true, []],
            ['organizational_departments.structure', '/dashboard/department/structure', 'هيكل الإدارة', $C::OrganizationalDepartments, $R::AdminOnly, false, P::DEPTS_MANAGE, true, []],
            ['organizational_departments.permissions_delegation', '/dashboard/department/permissions', 'تفويض صلاحيات الإدارة', $C::OrganizationalDepartments, $R::AdminOnly, false, null, true, []],
            ['organizational_departments.ai_workspace', '/dashboard/ai-department', 'مساحة عمل إدارة الذكاء الاصطناعي', $C::OrganizationalDepartments, $R::SafeDelegatable, true, null, true, []],
            // Expert / trainer applications intake, managed by the AI department
            // (ai_manager) and the admin tier. Its OWN capability — formerly a
            // route pattern of technical_system.ai_command_center, which tied it
            // to the technical AI platform and kept ai_manager out of both.
            ['organizational_departments.ai_expert_applications', '/dashboard/admin/ai/expert-applications', 'طلبات انضمام الخبراء', $C::OrganizationalDepartments, $R::AdminOnly, false, null, false, []],
            ['organizational_departments.programs_manager', '/dashboard/programs-manager', 'لوحة مدير البرامج', $C::OrganizationalDepartments, $R::SafeDelegatable, true, null, true, []],
            ['organizational_departments.operations_manager', '/dashboard/operations-manager', 'لوحة مدير العمليات', $C::OrganizationalDepartments, $R::SafeDelegatable, true, null, true, []],
            ['organizational_departments.partnerships_manager', '/dashboard/partnerships-manager', 'لوحة مدير الشراكات', $C::OrganizationalDepartments, $R::SafeDelegatable, true, null, true, []],
            ['organizational_departments.community_manager', '/dashboard/community-manager', 'لوحة مدير المجتمع', $C::OrganizationalDepartments, $R::SafeDelegatable, true, null, true, []],
            ['organizational_departments.section_lead', '/dashboard/section-lead', 'لوحة قائد القسم', $C::OrganizationalDepartments, $R::SafeDelegatable, true, null, true, []],
            ['organizational_departments.marketing', '/dashboard/marketing', 'التسويق', $C::OrganizationalDepartments, $R::SafeDelegatable, true, P::VIEW_MARKETING, true, [
                '/dashboard/admin/marketing',
            ]],
            ['organizational_departments.department_overview', '/dashboard/department', 'نظرة عامة على الإدارات', $C::OrganizationalDepartments, $R::AdminOnly, false, P::VIEW_DEPARTMENTS, false, [
                '/dashboard/department/:id', '/dashboard/executive/departments', '/dashboard/admin/departments', '/dashboard/admin/departments/:id',
            ]],
            ['organizational_departments.partners', '/dashboard/admin/partners', 'الشركاء (عمليات)', $C::OrganizationalDepartments, $R::AdminOnly, false, P::VIEW_PARTNERS, false, []],
            ['organizational_departments.partnership_requests', '/dashboard/admin/partnership-requests', 'طلبات الشراكة', $C::OrganizationalDepartments, $R::AdminOnly, false, null, false, []],
            ['organizational_departments.consultant_applications', '/dashboard/admin/consultant-applications', 'طلبات الاستشاريين', $C::OrganizationalDepartments, $R::AdminOnly, false, null, false, []],
            ['organizational_departments.department_units', '/dashboard/admin/department-units', 'وحدات الإدارة', $C::OrganizationalDepartments, $R::AdminOnly, false, P::MANAGE_DEPARTMENTS, false, []],

            // ── Administration ───────────────────────────────────────────────
            ['administration.super_admin_overview', '/dashboard/super-admin', 'لوحة السوبر أدمن', $C::Administration, $R::SystemProtected, false, null, false, []],
            ['administration.admin_overview', '/dashboard/admin', 'لوحة الإدارة', $C::Administration, $R::AdminOnly, false, null, false, []],
            ['administration.tech_admin_overview', '/dashboard/tech-admin', 'لوحة المشرف التقني', $C::Administration, $R::AdminOnly, false, null, false, []],
            ['administration.users', '/dashboard/super-admin/crud/users', 'إدارة المستخدمين', $C::Administration, $R::AdminOnly, false, P::VIEW_USERS, false, []],
            ['administration.roles', '/dashboard/super-admin/crud/roles', 'الأدوار والصلاحيات', $C::Administration, $R::SystemProtected, false, P::MANAGE_ROLES, false, []],
            ['administration.permission_delegation_config', '/dashboard/super-admin/crud/permissions', 'إعدادات تفويض الصلاحيات', $C::Administration, $R::SystemProtected, false, P::MANAGE_ROLES, false, []],
            // Central Access Control management surface. SYSTEM_PROTECTED for the
            // same reason as the two rows above: it EDITS the access-control tables
            // themselves (role defaults, department defaults, user overrides), and
            // its most restrictive backend tab - department defaults - is gated
            // role:super_admin,tech_admin. Classifying it any lower would let a page
            // that configures access be delegated through the very mechanism it
            // configures. delegatable=false and SYSTEM_PROTECTED together mean it can
            // never be stored as a role default, a department default, or a user
            // ALLOW override - every one of those layers rejects it by rule.
            ['administration.access_control', '/dashboard/super-admin/access-control', 'إدارة الوصول والصلاحيات', $C::Administration, $R::SystemProtected, false, P::MANAGE_ROLES, false, []],
            ['administration.departments', '/dashboard/super-admin/crud/departments', 'إدارة الأقسام', $C::Administration, $R::AdminOnly, false, P::MANAGE_DEPARTMENTS, false, []],
            ['administration.team', '/dashboard/super-admin/crud/team', 'إدارة الفريق', $C::Administration, $R::AdminOnly, false, P::MANAGE_TEAM, false, []],
            ['administration.students', '/dashboard/super-admin/crud/students', 'إدارة الطلاب', $C::Administration, $R::AdminOnly, false, null, false, [
                '/dashboard/students',
            ]],
            ['administration.instructors', '/dashboard/super-admin/crud/instructors', 'إدارة المدربين', $C::Administration, $R::AdminOnly, false, null, false, []],
            ['administration.workshops_catalog', '/dashboard/super-admin/crud/workshops', 'إدارة الورش', $C::Administration, $R::AdminOnly, false, P::MANAGE_WORKSHOPS, false, []],
            ['administration.registrations', '/dashboard/super-admin/crud/registrations', 'إدارة التسجيلات', $C::Administration, $R::AdminOnly, false, P::VIEW_REGISTRATIONS, false, []],
            ['administration.partners_catalog', '/dashboard/super-admin/crud/partners', 'إدارة الشركاء', $C::Administration, $R::AdminOnly, false, P::MANAGE_PARTNERS, false, []],
            ['administration.audit_logs', '/dashboard/super-admin/audit-logs', 'سجلات التدقيق (سوبر أدمن)', $C::Administration, $R::AdminOnly, false, P::VIEW_AUDIT_LOGS, false, []],
            ['administration.admin_audit_logs', '/dashboard/admin/audit-logs', 'سجلات التدقيق (إداري)', $C::Administration, $R::AdminOnly, false, P::VIEW_AUDIT_LOGS, false, []],
            ['administration.integrations', '/dashboard/admin/integrations', 'التكاملات', $C::Administration, $R::AdminOnly, false, P::MANAGE_INTEGRATIONS, false, [
                '/dashboard/admin/integrations/whatsapp', '/dashboard/admin/integrations/email',
            ]],
            ['administration.webhooks', '/dashboard/admin/webhooks', 'الويب هوك', $C::Administration, $R::AdminOnly, false, P::MANAGE_WEBHOOKS, false, [
                '/dashboard/admin/webhooks/:id',
            ]],
            ['administration.email_settings', '/dashboard/super-admin/email-settings', 'إعدادات البريد', $C::Administration, $R::SystemProtected, false, P::MANAGE_SETTINGS, false, []],
            ['administration.attendance_settings', '/dashboard/super-admin/attendance-settings', 'إعدادات الحضور', $C::Administration, $R::SystemProtected, false, P::MANAGE_SETTINGS, false, []],
            ['administration.email_logs', '/dashboard/super-admin/email-logs', 'سجلات البريد', $C::Administration, $R::SystemProtected, false, P::MANAGE_SETTINGS, false, []],
            ['administration.product_updates', '/dashboard/super-admin/product-updates', 'تحديثات المنتج', $C::Administration, $R::AdminOnly, false, P::MANAGE_PRODUCT_UPDATES, false, []],
            ['administration.financial_requests_oversight', '/dashboard/super-admin/financial-requests', 'الإشراف على الطلبات المالية', $C::Administration, $R::AdminOnly, false, P::VIEW_FINANCIAL_REPORTS, false, []],
            ['administration.quality_admin_overview', '/dashboard/admin/quality', 'إشراف الجودة (إداري)', $C::Administration, $R::AdminOnly, false, P::MANAGE_QUALITY, false, []],
            ['administration.visitor_analytics', '/dashboard/admin/visitor-analytics', 'تحليلات الزوار', $C::Administration, $R::AdminOnly, false, null, false, []],
            ['administration.catalog_import', '/dashboard/admin/catalog-import', 'استيراد الكتالوج', $C::Administration, $R::AdminOnly, false, null, false, []],
            ['administration.tickets_admin', '/dashboard/tickets/admin', 'إدارة التذاكر', $C::Administration, $R::AdminOnly, false, null, false, []],
            ['administration.team_members', '/dashboard/admin/team-members', 'أعضاء الفريق', $C::Administration, $R::AdminOnly, false, P::MANAGE_TEAM, false, []],
            ['administration.knowledge_hub', '/dashboard/admin/knowledge', 'قاعدة المعرفة', $C::Administration, $R::AdminOnly, false, P::MANAGE_KNOWLEDGE, false, [
                '/dashboard/admin/knowledge/categories', '/dashboard/admin/knowledge/articles/create', '/dashboard/admin/knowledge/articles/:id/edit',
            ]],
            ['administration.documents', '/dashboard/admin/documents', 'المستندات الإدارية', $C::Administration, $R::AdminOnly, false, null, false, []],

            // ── Technical System — AI Platform (distinct from AI dept above) ─
            ['technical_system.ai_command_center', '/dashboard/admin/ai', 'مركز قيادة الذكاء الاصطناعي', $C::TechnicalSystem, $R::AdminOnly, false, P::USE_AI_ASSISTANT, false, []],
            ['technical_system.ai_automations', '/dashboard/admin/ai/automations', 'أتمتة الذكاء الاصطناعي', $C::TechnicalSystem, $R::AdminOnly, false, P::MANAGE_AI_PROMPTS, false, []],
            ['technical_system.ai_insights', '/dashboard/admin/ai/insights', 'رؤى الذكاء الاصطناعي', $C::TechnicalSystem, $R::AdminOnly, false, P::MANAGE_AI_PROMPTS, false, []],
            ['technical_system.ai_usage', '/dashboard/admin/ai/usage', 'استخدام الذكاء الاصطناعي', $C::TechnicalSystem, $R::AdminOnly, false, P::VIEW_AI_USAGE, false, []],
            ['technical_system.automations', '/dashboard/admin/automations', 'الأتمتة العامة', $C::TechnicalSystem, $R::AdminOnly, false, null, false, [
                '/dashboard/admin/automations/runs',
            ]],
            ['technical_system.platform_scale', '/dashboard/admin/platform-scale', 'قياس نمو المنصة', $C::TechnicalSystem, $R::SystemProtected, false, null, false, []],
            ['technical_system.api_tokens', '/dashboard/admin/developer/api-tokens', 'رموز واجهة البرمجة', $C::TechnicalSystem, $R::AdminOnly, false, P::MANAGE_API_TOKENS, false, []],
            ['technical_system.mobile_readiness', '/dashboard/admin/mobile-readiness', 'جاهزية التطبيق المحمول', $C::TechnicalSystem, $R::SystemProtected, false, null, false, []],

            // ── Personal (self-service / role-home surfaces) ───────────────
            // Always self-scoped to the signed-in user; "delegatable" does not
            // meaningfully apply, so it's false throughout this category.
            ['personal.profile', '/dashboard/profile', 'الملف الشخصي', $C::Personal, $R::SafeDelegatable, false, null, false, [], true],
            ['personal.notifications', '/dashboard/notifications', 'الإشعارات', $C::Personal, $R::SafeDelegatable, false, null, false, [], true],
            ['personal.notification_preferences', '/dashboard/settings/notifications', 'تفضيلات الإشعارات', $C::Personal, $R::SafeDelegatable, false, null, false, [], true],
            ['personal.volunteer_hr_profile', '/dashboard/volunteer/hr-profile', 'ملف المتطوع', $C::Personal, $R::SafeDelegatable, false, null, false, [], true],
            ['personal.resource_center_courses', '/dashboard/resources/courses', 'مركز الموارد', $C::Personal, $R::SafeDelegatable, false, null, false, []],
            ['personal.student_learning', '/dashboard/student', 'مساحة الطالب التعليمية', $C::Personal, $R::SafeDelegatable, false, null, false, [
                '/dashboard/student/learning-paths', '/dashboard/student/learning-paths/:id', '/dashboard/student/courses', '/dashboard/student/learn/:courseId',
                '/dashboard/student/registrations', '/dashboard/student/available-courses', '/dashboard/student/exams',
                '/dashboard/student/classes/:groupId/announcements', '/dashboard/student/calendar', '/dashboard/student/sessions/:sessionId',
                '/dashboard/student/courses/:courseId/placement-test', '/dashboard/student/courses/:courseId/placement-result',
                '/dashboard/student/courses/:courseId/oral-booking', '/dashboard/student/orders', '/dashboard/student/payment-success',
                '/dashboard/student/institute', '/dashboard/student/sessions', '/dashboard/student/attendance', '/dashboard/student/materials',
                '/dashboard/student/assignments/:submissionId?', '/dashboard/student/progress',
                '/dashboard/certificates', '/dashboard/student/my-certificates', '/dashboard/learning',
            ]],
            ['personal.instructor_workspace', '/dashboard/instructor', 'مساحة عمل المدرب', $C::Personal, $R::SafeDelegatable, false, null, false, [
                '/dashboard/instructor/learning-paths', '/dashboard/instructor/learning-paths/:id', '/dashboard/instructor/courses',
                '/dashboard/instructor/courses/:courseId/content', '/dashboard/instructor/courses/:courseId/placement-students',
                '/dashboard/instructor/oral-assessments', '/dashboard/instructor/availability', '/dashboard/instructor/students',
                '/dashboard/instructor/courses/:courseId/students', '/dashboard/instructor/quizzes', '/dashboard/instructor/courses/:courseId/quizzes',
                '/dashboard/instructor/placement-tests', '/dashboard/instructor/classes', '/dashboard/instructor/classes/:groupId/:tab',
                '/dashboard/instructor/calendar', '/dashboard/instructor/institute', '/dashboard/instructor/classes/:groupId/sessions/:sessionId',
                '/dashboard/instructor/workshops', '/dashboard/instructor/sessions', '/dashboard/instructor/attendance',
                '/dashboard/instructor/attendance/dashboard', '/dashboard/instructor/attendance/reports',
                '/dashboard/instructor/submissions', '/dashboard/instructor/submissions/:submissionId?', '/dashboard/instructor/assignments/dashboard',
                '/dashboard/instructor/assignments/missing-submissions',
            ]],
            ['personal.partner_portal', '/dashboard/partner', 'بوابة الشريك', $C::Personal, $R::SafeDelegatable, false, null, false, [
                '/dashboard/partner/programs', '/dashboard/partner/reports', '/dashboard/partner/documents',
            ]],
        ];

        return array_map(
            static fn (array $r) => new PageAccessEntry(
                key: $r[0],
                primaryRoute: $r[1],
                labelAr: $r[2],
                category: $r[3],
                riskLevel: $r[4],
                delegatable: $r[5],
                requiredPermission: $r[6],
                departmentScoped: $r[7],
                routePatterns: $r[8],
                // Phase 2G.2 — optional 10th element; every existing row keeps its
                // 9-element shape and defaults to false.
                authenticatedBaseline: $r[9] ?? false,
            ),
            $rows
        );
    }
}
