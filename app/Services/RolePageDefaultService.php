<?php

namespace App\Services;

use App\Models\Role;
use App\Models\RolePageDefault;
use App\Models\User;
use App\Support\PageAccess\PageAccessCatalog;
use App\Support\PageAccess\PageAccessEntry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Phase 2B — ROLE DEFAULT PAGE ACCESS.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * PAGE ACCESS != BUSINESS AUTHORIZATION
 * ─────────────────────────────────────────────────────────────────────────
 * A role default answers exactly one question: "if a user holds this role, is
 * this dashboard capability part of their DEFAULT page access?" It proves
 * nothing about department leadership, department membership, or the right to
 * perform any backend action. finance_manager having a page default for
 * finance.transactions does not let anyone create, approve or delete a
 * transaction — that still runs through Permissions/policies/Gates untouched.
 *
 * SINGLE SOURCE OF THE ELIGIBILITY RULE. No controller, request or test may
 * re-derive "which pages may be a role default" — they all call
 * isEligible()/eligibleEntries() here. The rule is:
 *
 *   A catalog entry is eligible iff it is NOT SYSTEM_PROTECTED.
 *
 * That admits SAFE_DELEGATABLE and ADMIN_ONLY pages (112 of 129 entries) and
 * excludes all 17 SYSTEM_PROTECTED ones. ADMIN_ONLY must be admitted because
 * the primary use case needs it: a finance_manager's own dashboards
 * (finance.dashboard, finance.transactions, finance.manual_payments, …) are
 * all classified ADMIN_ONLY. SYSTEM_PROTECTED is excluded so that role
 * administration, access-control administration, the technical AI platform and
 * security/system settings can never be handed to a role through this API —
 * which is also what structurally prevents tech_admin from using role defaults
 * to recreate root authority.
 *
 * Phase 2B is POSITIVE DEFAULTS ONLY. There is deliberately no role-level
 * DENY: per-user ALLOW/DENY belongs to Phase 2C, and adding a second negative
 * layer here would make inheritance ambiguous before the merge semantics are
 * settled.
 *
 * This service owns ROLE defaults only. Department defaults are owned by
 * DepartmentPageDefaultService (Phase 2A) and must not be duplicated here.
 */
class RolePageDefaultService
{
    // ── Eligibility (the one canonical rule) ─────────────────────────────────

    public function isEligible(PageAccessEntry $entry): bool
    {
        return ! $entry->isProtected();
    }

    /** @return array<int, PageAccessEntry> */
    public function eligibleEntries(): array
    {
        return array_values(array_filter(
            PageAccessCatalog::all(),
            fn (PageAccessEntry $e) => $this->isEligible($e)
        ));
    }

    /** @return array<int, string> */
    public function eligibleKeys(): array
    {
        return array_map(fn (PageAccessEntry $e) => $e->key, $this->eligibleEntries());
    }

    /**
     * Validate a set of catalog keys.
     *
     * @param  array<int, string>  $pageKeys
     * @return array<int, string> deduped, canonical-ordered, valid keys
     *
     * @throws InvalidArgumentException on an unknown or ineligible key
     */
    public function validateKeys(array $pageKeys): array
    {
        $unique = array_values(array_unique(array_map('strval', $pageKeys)));

        foreach ($unique as $key) {
            $entry = PageAccessCatalog::find($key);

            if (! $entry) {
                throw new InvalidArgumentException("Unknown page key [{$key}] — not present in PageAccessCatalog.");
            }

            if (! $this->isEligible($entry)) {
                throw new InvalidArgumentException(
                    "Page [{$key}] is SYSTEM_PROTECTED and cannot be assigned as a role default."
                );
            }
        }

        $eligible = $this->eligibleKeys();

        return array_values(array_filter($eligible, fn (string $k) => in_array($k, $unique, true)));
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /** @return array<int, string> */
    public function getDefaultsForRole(Role $role): array
    {
        $stored = RolePageDefault::query()
            ->where('role_id', $role->id)
            ->pluck('page_key')
            ->all();

        // Canonical catalog order, and silently ignore any key whose catalog
        // entry was removed or reclassified as SYSTEM_PROTECTED since it was
        // stored — a stale row must never resurrect an ineligible page.
        return array_values(array_filter(
            $this->eligibleKeys(),
            fn (string $k) => in_array($k, $stored, true)
        ));
    }

    public function isPageDefaultForRole(Role $role, string $pageKey): bool
    {
        return in_array($pageKey, $this->getDefaultsForRole($role), true);
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    /**
     * ADDITIVELY grant page defaults to a role — insert what is missing, never
     * delete, never overwrite.
     *
     * Phase 2G.3A/B counterpart to replaceDefaults(). The distinction matters:
     * replaceDefaults() is DESIRED-STATE REPLACEMENT and deletes any stored key
     * absent from the submitted list, which would silently prune a default an
     * administrator added by hand. Canonical compatibility synchronisation must
     * be purely additive, so it needs this method rather than a flag on the
     * other one — the two have genuinely different contracts.
     *
     * Validation is the SAME canonical rule: keys run through validateKeys(), so
     * a SYSTEM_PROTECTED (or otherwise ineligible) key is rejected here exactly
     * as it is there. Callers never re-derive eligibility.
     *
     * No transaction is opened here: a bulk caller wraps many roles in ONE
     * transaction, and nesting would defeat its all-or-nothing guarantee.
     *
     * @param  array<int, string>  $pageKeys
     * @return array<int, string> the keys actually inserted (already-present keys are not returned)
     */
    public function addDefaults(Role $role, array $pageKeys, ?User $actor = null): array
    {
        $valid = $this->validateKeys($pageKeys);

        $existing = RolePageDefault::query()
            ->where('role_id', $role->id)
            ->pluck('page_key')
            ->all();

        $toInsert = array_values(array_diff($valid, $existing));

        foreach ($toInsert as $key) {
            RolePageDefault::create([
                'role_id' => $role->id,
                'page_key' => $key,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ]);
        }

        return $toInsert;
    }

    /**
     * Atomically REPLACE a role's page defaults.
     *
     * Validation runs fully before any write, so an invalid key aborts with
     * zero rows changed. Repeating the same call is idempotent: the resulting
     * row set is identical and already-correct rows are left in place, so
     * audit history is not churned on no-op saves.
     *
     * @param  array<int, string>  $pageKeys
     * @return array<int, string> the stored keys
     */
    public function replaceDefaults(Role $role, array $pageKeys, ?User $actor = null): array
    {
        $valid = $this->validateKeys($pageKeys);

        DB::transaction(function () use ($role, $valid, $actor) {
            $existingKeys = RolePageDefault::query()
                ->where('role_id', $role->id)
                ->pluck('page_key')
                ->all();

            $toDelete = array_diff($existingKeys, $valid);
            $toInsert = array_diff($valid, $existingKeys);

            if ($toDelete) {
                RolePageDefault::query()
                    ->where('role_id', $role->id)
                    ->whereIn('page_key', $toDelete)
                    ->delete();
            }

            foreach ($toInsert as $key) {
                RolePageDefault::create([
                    'role_id' => $role->id,
                    'page_key' => $key,
                    'created_by' => $actor?->id,
                    'updated_by' => $actor?->id,
                ]);
            }
        });

        return $valid;
    }
}
