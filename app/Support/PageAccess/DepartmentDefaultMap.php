<?php

namespace App\Support\PageAccess;

/**
 * Phase 2G.4A — CANONICAL DEPARTMENT DEFAULT MAPPING.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * PAGE ACCESS != BUSINESS AUTHORIZATION
 * ─────────────────────────────────────────────────────────────────────────
 * Every pair below decides only whether a dashboard PAGE opens. It never
 * grants a business permission. The two leader surfaces mapped here keep
 * their own backend authority: DepartmentStructureController re-checks
 * DepartmentAccessService::getLedDepartmentIds(), and
 * DepartmentPermissionController routes every grant/revoke through
 * PermissionDelegationService. Opening the page proves nothing to either.
 *
 * DEPARTMENT IDENTITY IS THE SLUG, never the numeric id. `departments.slug`
 * carries a DB-level UNIQUE index (departments_slug_unique), is non-null for
 * every row, and is already used as a canonical literal in production code
 * (WeeklyReportController checks `inDepartment('quality-governance')`).
 * Numeric ids are local-database artifacts and must never appear here.
 *
 * AUDIENCE SEMANTICS ARE ADDITIVE, and the SERVICE owns that rule:
 * EffectivePageAccessService::departmentLayers() merges member defaults into a
 * leader's set, so a LEADER entry must never restate a MEMBER key to
 * "emulate" inheritance — that would double-store the same grant and hide a
 * future change to the inheritance rule.
 *
 * ELIGIBILITY IS NOT RE-DERIVED HERE. DepartmentPageDefaultService owns the
 * single rule (MEMBER: SAFE_DELEGATABLE; LEADER: SAFE_DELEGATABLE or
 * department-scoped non-protected). Both keys below are department-scoped
 * ADMIN_ONLY, which is exactly the narrow leader carve-out that rule admits,
 * and neither is eligible for the MEMBER audience — so the deliberately empty
 * MEMBER set is enforced by the service, not merely by convention here.
 *
 * WHY THE MEMBER SET IS EMPTY. Every capability that a department-membership
 * default might plausibly cover was audited in Phase 2G.4A and found NOT to be
 * department-gated at the backend; see DEFERRED_PRODUCT_DECISION below. A
 * department default is the wrong mechanism for a page the backend opens by
 * role or by bare authentication, and mapping one would invent department
 * scoping the API does not have.
 */
final class DepartmentDefaultMap
{
    /**
     * DEPARTMENT_SLUG => ['member' => page_key[], 'leader' => page_key[]].
     *
     * Every ACTIVE department is enumerated explicitly, exactly as
     * RoleDefaultMap enumerates roles. A department created later is reported
     * by the dry-run as UNMAPPED_DEPARTMENT rather than silently inheriting a
     * wildcard — a new department must be reviewed, not auto-granted.
     *
     * A department with no leader_id still carries its LEADER rows: the rows
     * are inert until a canonical leader is assigned, and the resolver reads
     * leadership from departments.leader_id at request time.
     */
    private const MAP = [
        'advisors' => ['member' => [], 'leader' => self::LEADER_OWNED],
        'ai-transformation' => ['member' => [], 'leader' => self::LEADER_OWNED],
        'community-health' => ['member' => [], 'leader' => self::LEADER_OWNED],
        'digital-ambassadors' => ['member' => [], 'leader' => self::LEADER_OWNED],
        'executive-management' => ['member' => [], 'leader' => self::LEADER_OWNED],
        'finance' => ['member' => [], 'leader' => self::LEADER_OWNED],
        'human-resources' => ['member' => [], 'leader' => self::LEADER_OWNED],
        'marketing-media' => ['member' => [], 'leader' => self::LEADER_OWNED],
        'operations' => ['member' => [], 'leader' => self::LEADER_OWNED],
        'partnerships' => ['member' => [], 'leader' => self::LEADER_OWNED],
        'programs-tracks' => ['member' => [], 'leader' => self::LEADER_OWNED],
        'quality-governance' => ['member' => [], 'leader' => self::LEADER_OWNED],
        'strategy-planning' => ['member' => [], 'leader' => self::LEADER_OWNED],
        'technology' => ['member' => [], 'leader' => self::LEADER_OWNED],
    ];

    /**
     * The two surfaces a canonical department leader owns for their OWN
     * department, both proven against the backend in Phase 2G.4A:
     *
     *   structure                — DepartmentStructureController::getStructure()
     *                              returns 403 to a genuine non-leader and
     *                              resolves leadership via getLedDepartmentIds().
     *   permissions_delegation   — DepartmentPermissionController resolves scope
     *                              via getLedDepartmentIds() and delegates every
     *                              grant/revoke to PermissionDelegationService,
     *                              which blocks self-delegation, requires the
     *                              actor to already hold the permission, and
     *                              forbids cross-department delegation.
     *
     * ROUTE ENTRY AGREES WITH THIS MAPPING. The department route group formerly
     * admitted a fixed role list, so a canonical leader holding a non-manager
     * role would have had the page opened here while the API refused them. The
     * role.or.leader middleware resolved that: it admits "a listed role OR the
     * canonical leader of an ACTIVE department" - the same condition this
     * mapping grants on, filtered for active departments exactly as
     * EffectivePageAccessService::departmentLayers() filters. Page access and
     * route entry therefore cannot disagree for these two surfaces.
     */
    public const LEADER_OWNED = [
        'organizational_departments.structure',
        'organizational_departments.permissions_delegation',
    ];

    /**
     * Audited in Phase 2G.4A and deliberately NOT mapped, each with the
     * evidence that rules out a department default.
     *
     * The four shared Operations surfaces and the members directory are not
     * department-gated at the backend at all; the financial-requests page is
     * department-gated but by a DIFFERENT leadership signal than the canonical
     * one, which is an unresolved architectural question, not a mapping gap.
     */
    public const DEFERRED_PRODUCT_DECISION = [
        // Route group gates 19 role slugs; OperationsBoardController::board()
        // applies NO department scoping — department_id is an optional filter.
        'operations.board',
        // VolunteerPointsController is entirely personal (mySummary/myAwards/
        // leaderboard). No department is consulted anywhere in the controller.
        'operations.impact_points',
        // MeetingReportController::index() applies no department scoping; only
        // show()/store() re-check the department, i.e. per-record, not per-page.
        'operations.meeting_reports',
        // WeeklyReportController scopes the DATA by department, but the PAGE is
        // opened by the 19-role route list, and quality-governance members are
        // special-cased to read every department.
        'operations.weekly_reports',
        // /dashboard/members calls GET /api/admin/members, registered under
        // auth:sanctum ONLY and deliberately kept outside the admin role group.
        // AdminMemberController applies NO department scoping at all - it never
        // calls canAccessDepartment, and department_id is only an output field -
        // so this is the org-wide directory, not "members of my department".
        // It returns name/email/phone to every authenticated user, which is a
        // PII question that must be answered before it becomes any kind of
        // default. Withholding it is the safer current behaviour.
        'organizational_departments.members',
        // FinancialRequestController gates creation on TeamProfile.is_leader —
        // a synced display flag — while the canonical authority is
        // departments.leader_id. The two genuinely disagree in real data, so
        // mapping to canonical leaders would diverge from the backend's own gate
        // in both directions. Reconciling them changes who may CREATE a financial
        // request, a business-workflow decision; the divergence is pinned by
        // FinancialRequestAuthorityBoundaryTest instead of silently changed.
        'operations.department_financial_requests',
    ];

    /** Intentionally denied forever; ineligible for either audience by rule. */
    public const INTENTIONAL_SYSTEM_PROTECTED = [
        'technical_system.platform_scale',
        'technical_system.mobile_readiness',
    ];

    /** @return array<string, array{member: array<int,string>, leader: array<int,string>}> */
    public static function all(): array
    {
        return self::MAP;
    }

    /** @return array<int, string> */
    public static function departments(): array
    {
        return array_keys(self::MAP);
    }

    /** @return array<int, string> */
    public static function forDepartment(string $slug, DepartmentPageAudience $audience): array
    {
        return self::MAP[$slug][$audience->value] ?? [];
    }

    public static function pairCount(): int
    {
        $n = 0;

        foreach (self::MAP as $audiences) {
            $n += count($audiences['member']) + count($audiences['leader']);
        }

        return $n;
    }

    public static function pairCountFor(DepartmentPageAudience $audience): int
    {
        $n = 0;

        foreach (self::MAP as $audiences) {
            $n += count($audiences[$audience->value]);
        }

        return $n;
    }
}
