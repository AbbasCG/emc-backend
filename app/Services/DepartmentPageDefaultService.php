<?php

namespace App\Services;

use App\Models\Department;
use App\Models\DepartmentPageDefault;
use App\Models\User;
use App\Support\PageAccess\DepartmentPageAudience;
use App\Support\PageAccess\PageAccessCatalog;
use App\Support\PageAccess\PageAccessEntry;
use App\Support\PageAccess\PageAccessRiskLevel;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Phase 2A — DEPARTMENT DEFAULT PAGE ACCESS.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * PAGE ACCESS != BUSINESS AUTHORIZATION
 * ─────────────────────────────────────────────────────────────────────────
 * Everything here describes which dashboard pages a department exposes BY
 * DEFAULT to its members and its canonical leader. It never grants a business
 * permission, and it is never consulted by Permissions/policies/Gates.
 *
 * SINGLE SOURCE OF THE ELIGIBILITY RULE. No controller, request or test may
 * re-derive "which pages may be a department default" — they all call
 * isEligible()/eligibleEntries() here. The rule is:
 *
 *   MEMBER audience: risk_level === SAFE_DELEGATABLE
 *   LEADER audience: risk_level === SAFE_DELEGATABLE
 *                    OR (department_scoped AND not SYSTEM_PROTECTED)
 *
 * `! isProtected()` is asserted explicitly for BOTH audiences even though no
 * SYSTEM_PROTECTED catalog entry is currently department_scoped — belt and
 * braces, so a future catalog edit can never quietly make a protected page
 * (role administration, access-control administration, the technical AI
 * platform, security settings) assignable as a department default.
 *
 * The leader carve-out is deliberately narrow: it admits exactly the
 * department-scoped ADMIN_ONLY surfaces a real department leader legitimately
 * owns (their own department's structure and their own department's permission
 * delegation screen) while excluding every org-wide ADMIN_ONLY page such as
 * user administration or the finance dashboards.
 *
 * Leadership is ALWAYS resolved through DepartmentAccessService
 * (departments.leader_id) — never a role slug, never team_profiles.is_leader.
 *
 * Phase 2A implements the DEPARTMENT DEFAULT layer only. Role defaults
 * (Phase 2B) and per-user ALLOW/DENY overrides (Phase 2C) are deliberately
 * NOT implemented here, and this service must not be extended to guess them.
 */
class DepartmentPageDefaultService
{
    public function __construct(private readonly DepartmentAccessService $departmentAccess)
    {
    }

    // ── Eligibility (the one canonical rule) ─────────────────────────────────

    public function isEligible(PageAccessEntry $entry, DepartmentPageAudience $audience): bool
    {
        if ($entry->isProtected()) {
            return false;
        }

        if ($entry->riskLevel === PageAccessRiskLevel::SafeDelegatable) {
            return true;
        }

        return $audience === DepartmentPageAudience::Leader && $entry->departmentScoped;
    }

    /** @return array<int, PageAccessEntry> */
    public function eligibleEntries(DepartmentPageAudience $audience): array
    {
        return array_values(array_filter(
            PageAccessCatalog::all(),
            fn (PageAccessEntry $e) => $this->isEligible($e, $audience)
        ));
    }

    /** @return array<int, string> */
    public function eligibleKeys(DepartmentPageAudience $audience): array
    {
        return array_map(fn (PageAccessEntry $e) => $e->key, $this->eligibleEntries($audience));
    }

    /**
     * Validate a set of catalog keys for an audience.
     *
     * @param  array<int, string>  $pageKeys
     * @return array<int, string> the deduped, canonical-ordered, valid keys
     *
     * @throws InvalidArgumentException on an unknown or ineligible key
     */
    public function validateKeys(array $pageKeys, DepartmentPageAudience $audience): array
    {
        $unique = array_values(array_unique(array_map('strval', $pageKeys)));

        foreach ($unique as $key) {
            $entry = PageAccessCatalog::find($key);

            if (! $entry) {
                throw new InvalidArgumentException("Unknown page key [{$key}] — not present in PageAccessCatalog.");
            }

            if (! $this->isEligible($entry, $audience)) {
                throw new InvalidArgumentException(
                    "Page [{$key}] ({$entry->riskLevel->value}) is not eligible as a [{$audience->value}] department default."
                );
            }
        }

        // Return in canonical catalog order so stored/returned sets are stable.
        $eligible = $this->eligibleKeys($audience);

        return array_values(array_filter($eligible, fn (string $k) => in_array($k, $unique, true)));
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /** @return array<int, string> */
    public function getMemberDefaults(Department $department): array
    {
        return $this->storedKeys($department, DepartmentPageAudience::Member);
    }

    /** @return array<int, string> */
    public function getLeaderDefaults(Department $department): array
    {
        return $this->storedKeys($department, DepartmentPageAudience::Leader);
    }

    /**
     * What this user actually receives by default from THIS department.
     *
     * ADDITIVE: a canonical leader (departments.leader_id, resolved via
     * DepartmentAccessService) receives member defaults PLUS leader defaults.
     * Leader defaults never replace member defaults.
     *
     * A user with no relationship to the department receives nothing — a
     * department's defaults never leak to another department's people.
     * Global roles are NOT special-cased here: this layer answers "what does
     * this department hand out", not "what may this admin reach" (admin reach
     * is a different layer entirely, and Phase 2A must not conflate them).
     *
     * Archived departments still return their stored defaults: archiving is
     * not deletion, and callers decide what to do with an archived department.
     *
     * @return array<int, string>
     */
    public function getEffectiveDepartmentDefaults(User $user, Department $department): array
    {
        $isLeader = $this->departmentAccess->getLedDepartmentIds($user)->contains($department->id);

        if ($isLeader) {
            $keys = array_merge(
                $this->getMemberDefaults($department),
                $this->getLeaderDefaults($department),
            );

            return array_values(array_unique($keys));
        }

        $isMember = $this->departmentAccess->getMemberDepartmentIds($user)->contains($department->id);

        return $isMember ? $this->getMemberDefaults($department) : [];
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    /**
     * Atomically REPLACE one audience's defaults for a department.
     *
     * Validation runs fully before any write, so an invalid key aborts with
     * zero rows changed. Repeating the same call is idempotent: the resulting
     * row set is identical, and rows that are already correct are left in
     * place (so created_at/audit history is not churned on no-op saves).
     *
     * @param  array<int, string>  $pageKeys
     * @return array<int, string> the stored keys
     */
    public function replaceDefaults(
        Department $department,
        DepartmentPageAudience $audience,
        array $pageKeys,
        ?User $actor = null,
    ): array {
        $valid = $this->validateKeys($pageKeys, $audience);

        DB::transaction(function () use ($department, $audience, $valid, $actor) {
            $existing = DepartmentPageDefault::query()
                ->where('department_id', $department->id)
                ->forAudience($audience)
                ->get();

            $existingKeys = $existing->pluck('page_key')->all();

            $toDelete = array_diff($existingKeys, $valid);
            $toInsert = array_diff($valid, $existingKeys);

            if ($toDelete) {
                DepartmentPageDefault::query()
                    ->where('department_id', $department->id)
                    ->forAudience($audience)
                    ->whereIn('page_key', $toDelete)
                    ->delete();
            }

            foreach ($toInsert as $key) {
                DepartmentPageDefault::create([
                    'department_id' => $department->id,
                    'page_key' => $key,
                    'audience' => $audience->value,
                    'created_by' => $actor?->id,
                    'updated_by' => $actor?->id,
                ]);
            }
        });

        return $valid;
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /** @return array<int, string> */
    private function storedKeys(Department $department, DepartmentPageAudience $audience): array
    {
        $stored = DepartmentPageDefault::query()
            ->where('department_id', $department->id)
            ->forAudience($audience)
            ->pluck('page_key')
            ->all();

        // Canonical catalog order, and silently ignore any key whose catalog
        // entry was removed/reclassified since it was stored — a stale row must
        // never resurrect an ineligible page.
        return array_values(array_filter(
            $this->eligibleKeys($audience),
            fn (string $k) => in_array($k, $stored, true)
        ));
    }
}
