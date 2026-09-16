<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Support\PageAccess\EffectivePageAccess;
use App\Support\PageAccess\PageAccessCatalog;
use App\Support\PageAccess\PageAccessEntry;
use App\Support\PageAccess\PageAccessSource;
use App\Support\PageAccess\UserPageAccessState;
use Illuminate\Support\Collection;

/**
 * Phase 2D — THE canonical EFFECTIVE PAGE ACCESS resolver.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * PAGE ACCESS != BUSINESS AUTHORIZATION
 * ─────────────────────────────────────────────────────────────────────────
 * This service answers exactly one question: "may this user OPEN this
 * dashboard capability?" It is not a permission system and must never be used
 * as one. It does not replace, and is never consulted by:
 *   - Permissions / Gate / policies          (business capability)
 *   - DepartmentAccessService                (department scoping/authority)
 *   - departments.leader_id                  (leadership authority)
 *   - controller authorization / validation  (the actual operation)
 *
 * Concretely: a user resolving `allowed = true` for operations.weekly_reports
 * may open that page. Creating or editing a weekly report there still runs
 * through DepartmentAccessService::canManageDepartment() exactly as before,
 * and this service changes nothing about that.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * IT OWNS NO DATA AND NO RULES OF ITS OWN
 * ─────────────────────────────────────────────────────────────────────────
 * Phase 2D adds NO table and NO migration. It is pure composition of the
 * three committed layers, each still the single source of its own rule:
 *   Phase 2B  RolePageDefaultService        — role defaults
 *   Phase 2A  DepartmentPageDefaultService  — department member/leader defaults
 *   Phase 2C  UserPageAccessOverrideService — per-user ALLOW/DENY
 *   canonical DepartmentAccessService       — membership and leadership
 * None of those services are modified, and none of their tables are queried
 * directly here — a stale or reclassified key is already filtered out by the
 * owning service, so this resolver inherits that safety for free.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * PRECEDENCE (highest first)
 * ─────────────────────────────────────────────────────────────────────────
 *   1. ROOT AUTHORITY         — decides alone, for every page
 *   2. SYSTEM PROTECTION      — decides alone for everyone else (deny)
 *   3. USER DENY
 *   4. USER ALLOW
 *   5. DEPARTMENT LEADER DEFAULT
 *   6. DEPARTMENT MEMBER DEFAULT
 *   7. ROLE DEFAULT
 *   8. AUTHENTICATED BASELINE — catalog-declared, no stored row
 *   9. NO SOURCE              — deny (default-deny)
 *
 * AUTHENTICATED BASELINE (Phase 2G.2) sits at the BOTTOM of the positive
 * sources on purpose. It grants the handful of capabilities that belong to any
 * authenticated account — a user's own profile, notifications, notification
 * preferences, their own tickets and HR requests, the volunteer application
 * form — without a row in any mutable table, so a NEW role inherits them
 * automatically and no role edit can take someone's own profile away.
 *
 * Being lowest means it never masks a more specific provenance: if a role also
 * grants the page, primary_source still reads `role`.
 *
 * It cannot reach SYSTEM_PROTECTED: PageAccessEntry's constructor refuses the
 * combination outright, and resolveEntry() short-circuits protected pages
 * before any source is collected. USER DENY still outranks it (rung 3) —
 * Phase 2G.2 deliberately did NOT invent per-page denyability, leaving the
 * existing override semantics exactly as they were.
 *
 * SYSTEM PROTECTION is deliberately NOT the naive "always false for everyone".
 * The 17 SYSTEM_PROTECTED pages are reachable SOLELY through canonical ROOT
 * AUTHORITY — super_admin / tech_admin, the exact pair that already bypasses
 * every Gate in AppServiceProvider's Gate::before, receives Permissions::all()
 * in User::basePermissions(), and is short-circuited to `true` for the whole
 * /dashboard namespace by the frontend's own canAccessDashboardPath().
 *
 * ROOT AUTHORITY IS UNRESTRICTED ACROSS THE WHOLE CATALOG, not just the
 * protected slice. That matches the three canonical surfaces above exactly. It
 * is also load-bearing: resolving root against the mutable layers instead would
 * leave a fresh super_admin with only the pages someone had remembered to seed
 * as role/department defaults, so the moment a later phase switches the sidebar
 * onto this resolver, root would silently lose most of the dashboard.
 *
 * Root's authority is not mutable through any page-access table, in EITHER
 * direction: no ALLOW is needed to grant a page, and no DENY can strip one —
 * which also means a tech_admin cannot use Phase 2C to lock a super_admin out
 * of anything. For SYSTEM_PROTECTED pages that is guaranteed twice over: all
 * three Phase 2A/2B/2C services already refuse to store a protected key at all
 * (shared `! isProtected()` eligibility rule), so no such row can exist, and
 * even if one somehow did, resolveEntry() never consults a layer for them.
 *
 * Root authority is read in ONE place here (hasRootPageAuthority) rather than
 * sprinkled through the resolver. ProtectedAuthority is deliberately NOT
 * widened for this: its documented scope is "only super_admin may act on the
 * protected super_admin identity/role", which is a different question from
 * "who has unrestricted dashboard reach", and changing it would alter Phase 0
 * hardening semantics.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * READ-ONLY
 * ─────────────────────────────────────────────────────────────────────────
 * This service never creates, updates or deletes a row.
 */
class EffectivePageAccessService
{
    public function __construct(
        private readonly RolePageDefaultService $roleDefaults,
        private readonly DepartmentPageDefaultService $departmentDefaults,
        private readonly UserPageAccessOverrideService $userOverrides,
        private readonly DepartmentAccessService $departmentAccess,
    ) {
    }

    // ── Public API ───────────────────────────────────────────────────────────

    /**
     * Every catalog capability with its verdict and provenance, in canonical
     * catalog order. This is the mode the future Access Control UI needs.
     *
     * @return array<string, EffectivePageAccess> keyed by page key
     */
    public function resolveAll(User $user): array
    {
        $layers = $this->collectLayers($user);
        $root = $this->hasRootPageAuthority($user);

        $result = [];

        foreach (PageAccessCatalog::all() as $entry) {
            $result[$entry->key] = $this->resolveEntry($entry, $layers, $root);
        }

        return $result;
    }

    /**
     * Only the keys the user may actually open — the efficient mode for a
     * future sidebar/manifest.
     *
     * @return array<int, string>
     */
    public function allowedKeys(User $user): array
    {
        return array_values(array_map(
            fn (EffectivePageAccess $r) => $r->key,
            array_filter($this->resolveAll($user), fn (EffectivePageAccess $r) => $r->allowed)
        ));
    }

    /**
     * One page. Returns null for a key that is not in the catalog — an unknown
     * key is not an error, and is never treated as access.
     */
    public function resolve(User $user, string $pageKey): ?EffectivePageAccess
    {
        $entry = PageAccessCatalog::find($pageKey);

        if (! $entry) {
            return null;
        }

        return $this->resolveEntry($entry, $this->collectLayers($user), $this->hasRootPageAuthority($user));
    }

    /**
     * Simple backend check. Delegates to the SAME resolver — there is
     * deliberately no second access-calculation path anywhere in Phase 2D.
     * An unknown page key is denied.
     */
    public function canAccess(User $user, string $pageKey): bool
    {
        return $this->resolve($user, $pageKey)?->allowed ?? false;
    }

    // ── Root authority (the single place super_admin/tech_admin is read) ─────

    /**
     * Canonical unrestricted-dashboard authority. Matches the platform's
     * existing root pair exactly: Gate::before (AppServiceProvider),
     * User::basePermissions(), and the frontend's own canAccessDashboardPath()
     * bypass. executive_admin and admin are NOT root — executive_admin is
     * read-only by design and admin is an ordinary admin tier, and neither
     * bypasses Gates today.
     */
    private function hasRootPageAuthority(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isTechAdmin();
    }

    // ── Layer collection (all DB work happens here, once) ────────────────────

    /**
     * Load every layer in a bounded number of queries, independent of catalog
     * size: roughly 7 + 2·D, where D is the number of ACTIVE departments this
     * user belongs to or leads (normally 0 or 1). Never 1 query per catalog
     * entry.
     *
     * @return array{role: array<int,string>, member: array<int,string>, leader: array<int,string>, overrides: array<string,string>}
     */
    private function collectLayers(User $user): array
    {
        [$memberKeys, $leaderKeys] = $this->departmentLayers($user);

        return [
            'role' => $this->roleLayer($user),
            'member' => $memberKeys,
            'leader' => $leaderKeys,
            'overrides' => $this->userOverrides->getOverrides($user),
        ];
    }

    /**
     * Role defaults for the user's CANONICAL role name.
     *
     * effectiveRole() is used so the legacy 'teacher' slug resolves to the
     * 'instructor' role row rather than silently matching nothing. A user whose
     * role has no row in `roles` simply contributes no role defaults.
     *
     * @return array<int, string>
     */
    private function roleLayer(User $user): array
    {
        $role = Role::query()->where('name', $user->effectiveRole())->first();

        return $role ? $this->roleDefaults->getDefaultsForRole($role) : [];
    }

    /**
     * Department member/leader defaults, kept as SEPARATE lists.
     *
     * DepartmentPageDefaultService::getEffectiveDepartmentDefaults() already
     * expresses the additive member+leader rule, but returns one FLAT key list
     * — which loses whether a key came from the member or the leader audience.
     * Phase 2D must report that distinction as provenance (`department_member`
     * vs `department_leader`), so the two audiences are read separately here
     * through that same service's canonical readers. The additive rule itself
     * is NOT re-invented: a canonical leader receives member defaults PLUS
     * leader defaults, exactly as Phase 2A defines it.
     *
     * MEMBERSHIP and LEADERSHIP both come from DepartmentAccessService — the
     * one canonical source, and the same one Phase 2A consumes. Leadership is
     * departments.leader_id ONLY: never a role slug (ai_manager,
     * finance_manager, department_manager …) and never team_profiles.is_leader,
     * which is a display badge synced FROM the authority, not the authority.
     *
     * ARCHIVED/INACTIVE departments contribute NOTHING. Phase 2A deliberately
     * keeps returning an archived department's stored defaults ("archiving is
     * not deletion — callers decide"); Phase 2D is that caller, and it decides
     * that a department which is no longer active must not keep handing out NEW
     * page exposure. Filtering uses the canonical Department::active() scope
     * (status === 'active'), so both 'archived' and 'inactive' are excluded.
     * Historical business data is untouched — this changes only what a
     * page-access layer grants going forward.
     *
     * @return array{0: array<int,string>, 1: array<int,string>}
     */
    private function departmentLayers(User $user): array
    {
        $ledIds = $this->departmentAccess->getLedDepartmentIds($user);
        $memberIds = $this->departmentAccess->getMemberDepartmentIds($user);

        $relevant = $ledIds->merge($memberIds)->unique()->values();

        if ($relevant->isEmpty()) {
            return [[], []];
        }

        $departments = Department::query()
            ->whereIn('id', $relevant->all())
            ->active()
            ->get();

        $member = [];
        $leader = [];

        // ONE query for every relevant department's defaults, rather than two
        // per department: a user leading several departments is ordinary here.
        $defaults = $this->departmentDefaults->getDefaultsForDepartments(
            $departments->pluck('id')->all()
        );

        foreach ($departments as $department) {
            $isLeader = $ledIds->contains($department->id);
            $stored = $defaults[$department->id] ?? ['member' => [], 'leader' => []];

            // Phase 2A additive rule: a leader also receives member defaults.
            if ($isLeader || $memberIds->contains($department->id)) {
                $member = array_merge($member, $stored['member']);
            }

            if ($isLeader) {
                $leader = array_merge($leader, $stored['leader']);
            }
        }

        return [
            array_values(array_unique($member)),
            array_values(array_unique($leader)),
        ];
    }

    // ── Per-entry resolution (pure — no queries) ─────────────────────────────

    /**
     * @param array{role: array<int,string>, member: array<int,string>, leader: array<int,string>, overrides: array<string,string>} $layers
     */
    private function resolveEntry(PageAccessEntry $entry, array $layers, bool $root): EffectivePageAccess
    {
        // Positive contributors, in canonical ascending-precedence order.
        $sources = [];

        if (in_array($entry->key, $layers['role'], true)) {
            $sources[] = PageAccessSource::Role;
        }

        if (in_array($entry->key, $layers['member'], true)) {
            $sources[] = PageAccessSource::DepartmentMember;
        }

        if (in_array($entry->key, $layers['leader'], true)) {
            $sources[] = PageAccessSource::DepartmentLeader;
        }

        // Phase 2G.2 — AUTHENTICATED BASELINE. Declared on the catalog entry, so
        // it needs no query and no stored row: any authenticated account reaching
        // this resolver already satisfies it. Added as the LOWEST-precedence
        // positive source, so a role/department/allow grant still wins the
        // primary_source slot and the UI keeps reporting the more specific
        // reason.
        if ($entry->authenticatedBaseline) {
            $sources[] = PageAccessSource::AuthenticatedBaseline;
        }

        $stored = $layers['overrides'][$entry->key] ?? null;
        $state = $stored !== null ? UserPageAccessState::from($stored) : null;

        // 1. ROOT AUTHORITY — decides alone, for EVERY page, protected or not.
        //    No mutable table is consulted in either direction: a user DENY can
        //    never strip a page from root, and no ALLOW/default is needed to
        //    grant one. `override_state` is still reported so the Access
        //    Control UI can show "an override exists, but root authority
        //    supersedes it" instead of appearing to have lost the row.
        if ($root) {
            $sources[] = PageAccessSource::RootAuthority;

            return new EffectivePageAccess(
                key: $entry->key,
                allowed: true,
                primarySource: PageAccessSource::RootAuthority,
                sources: $sources,
                overrideState: $entry->isProtected() ? null : $state,
                isProtected: $entry->isProtected(),
                reason: 'root_authority',
            );
        }

        // 2. SYSTEM PROTECTION — for everyone else, decides alone and denies.
        //    No mutable layer is consulted, so a user ALLOW can never unlock a
        //    protected page.
        if ($entry->isProtected()) {
            return new EffectivePageAccess(
                key: $entry->key,
                allowed: false,
                primarySource: PageAccessSource::SystemProtected,
                sources: [],
                overrideState: null,
                isProtected: true,
                reason: 'system_protected',
            );
        }

        // 3. USER DENY — beats every positive source.
        if ($state === UserPageAccessState::Deny) {
            return new EffectivePageAccess(
                key: $entry->key,
                allowed: false,
                primarySource: PageAccessSource::UserDeny,
                sources: $sources,
                overrideState: $state,
                isProtected: false,
                reason: 'user_deny',
            );
        }

        // 4. USER ALLOW — grants even with no role/department source.
        if ($state === UserPageAccessState::Allow) {
            $sources[] = PageAccessSource::UserAllow;

            return new EffectivePageAccess(
                key: $entry->key,
                allowed: true,
                primarySource: PageAccessSource::UserAllow,
                sources: $sources,
                overrideState: $state,
                isProtected: false,
                reason: 'user_allow',
            );
        }

        // 5/6/7/8. Highest-precedence positive source wins as primary_source,
        //        while every contributing source stays visible.
        if ($sources !== []) {
            $primary = $this->highestPrecedence($sources);

            return new EffectivePageAccess(
                key: $entry->key,
                allowed: true,
                primarySource: $primary,
                sources: $sources,
                overrideState: null,
                isProtected: false,
                reason: $primary->value,
            );
        }

        // 9. Default-deny.
        return new EffectivePageAccess(
            key: $entry->key,
            allowed: false,
            primarySource: PageAccessSource::None,
            sources: [],
            overrideState: null,
            isProtected: false,
            reason: 'no_source',
        );
    }

    /** @param array<int, PageAccessSource> $sources */
    private function highestPrecedence(array $sources): PageAccessSource
    {
        return Collection::make($sources)
            ->sortByDesc(fn (PageAccessSource $s) => $s->precedence())
            ->first();
    }
}
