<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserPageAccessOverride;
use App\Support\PageAccess\PageAccessCatalog;
use App\Support\PageAccess\PageAccessEntry;
use App\Support\PageAccess\UserPageAccessState;
use App\Support\ProtectedAuthority;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 2C — USER PAGE ACCESS OVERRIDES.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * PAGE ACCESS != BUSINESS AUTHORIZATION
 * ─────────────────────────────────────────────────────────────────────────
 * An override decides only whether a dashboard page is exposed to one user.
 * An ALLOW may deliberately expose a page that neither the user's role
 * defaults (Phase 2B) nor their department defaults (Phase 2A) include — that
 * is the whole point of an override — but it grants NO business capability:
 * creating, editing, approving or deleting anything on that page still runs
 * through Permissions / policies / Gates / DepartmentAccessService, all
 * untouched by this service.
 *
 * DEFAULT IS THE ABSENCE OF A ROW. resetOverride() deletes; there is no
 * 'default' state.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * TWO SEPARATE RULES, deliberately not conflated
 * ─────────────────────────────────────────────────────────────────────────
 * 1. PAGE ELIGIBILITY — actor-independent. A page may be stored in this table
 *    (in EITHER state) iff it is NOT SYSTEM_PROTECTED.
 *
 *    SYSTEM_PROTECTED is excluded for ALLOW because this table must never be
 *    able to manufacture root-equivalent access — not for a department leader,
 *    not for tech_admin, not even for super_admin. Protected access continues
 *    to come only from canonical protected-authority rules.
 *
 *    SYSTEM_PROTECTED is ALSO excluded for DENY, on purpose: protected access
 *    never flows through this table, so a deny row against it would be
 *    inert-but-misleading — it would imply this table governs protected pages.
 *    Keeping the rule symmetric means "no SYSTEM_PROTECTED row can exist here,
 *    ever", which is trivially auditable.
 *
 * 2. ACTOR AUTHORITY — who may write, and which subset they may write.
 *      - target is a super_admin  -> only super_admin may configure at all
 *                                    (mirrors ProtectedAuthority usage in
 *                                    AdminUserController).
 *      - super_admin / tech_admin -> any eligible (non-protected) page, any user.
 *      - canonical department leader (departments.leader_id via
 *        DepartmentAccessService) -> only pages flagged delegatable=true, and
 *        only for users belonging to a department they ACTUALLY lead, and never
 *        for themselves.
 *      - anyone else -> refused.
 *
 *    The delegatable set is 37 pages, every one SAFE_DELEGATABLE and none in
 *    the administration/technical_system categories, so a department leader
 *    structurally cannot grant Access Control, Role Management, Super Admin,
 *    Technical AI or security settings even if a client asks for it.
 *
 * Phase 2C stores overrides only. It does NOT resolve effective access across
 * role/department/user layers — that is Phase 2D.
 */
class UserPageAccessOverrideService
{
    public function __construct(private readonly DepartmentAccessService $departmentAccess)
    {
    }

    // ── 1. Page eligibility (actor-independent) ──────────────────────────────

    public function isPageEligible(PageAccessEntry $entry): bool
    {
        return ! $entry->isProtected();
    }

    /** @return array<int, PageAccessEntry> */
    public function eligibleEntries(): array
    {
        return array_values(array_filter(
            PageAccessCatalog::all(),
            fn (PageAccessEntry $e) => $this->isPageEligible($e)
        ));
    }

    /** @return array<int, string> */
    public function eligibleKeys(): array
    {
        return array_map(fn (PageAccessEntry $e) => $e->key, $this->eligibleEntries());
    }

    // ── 2. Actor authority ───────────────────────────────────────────────────

    /** Global admins may configure page overrides for anyone (protected target aside). */
    private function isGlobalConfigurator(User $actor): bool
    {
        return $actor->isSuperAdmin() || $actor->isTechAdmin();
    }

    /**
     * Departments the actor CANONICALLY leads (departments.leader_id), never a
     * role slug, and never team_profiles.is_leader.
     */
    private function ledDepartmentIdsOf(User $actor)
    {
        return $this->departmentAccess->getLedDepartmentIds($actor);
    }

    /** Is the target a member/leader of any department this actor actually leads? */
    private function targetIsInActorsLedDepartment(User $actor, User $target): bool
    {
        $led = $this->ledDepartmentIdsOf($actor);

        if ($led->isEmpty()) {
            return false;
        }

        $targetDepartments = $this->departmentAccess->getAccessibleDepartmentIds($target);

        return $led->intersect($targetDepartments)->isNotEmpty();
    }

    public function canConfigure(User $actor, User $target): bool
    {
        // The protected super_admin identity may only be acted on by a super_admin.
        if (ProtectedAuthority::isProtectedUser($target) && ! $actor->isSuperAdmin()) {
            return false;
        }

        if ($this->isGlobalConfigurator($actor)) {
            return true;
        }

        // A department leader never configures their own overrides — mirrors
        // PermissionDelegationService's refusal of self-delegation.
        if ($actor->id === $target->id) {
            return false;
        }

        return $this->targetIsInActorsLedDepartment($actor, $target);
    }

    public function assertCanConfigure(User $actor, User $target): void
    {
        if (! $this->canConfigure($actor, $target)) {
            throw new RuntimeException('You are not authorized to configure page access overrides for this user.');
        }
    }

    /**
     * The subset of eligible pages THIS actor may write for THIS target.
     *
     * @return array<int, string>
     */
    public function grantableKeysForActor(User $actor): array
    {
        if ($this->isGlobalConfigurator($actor)) {
            return $this->eligibleKeys();
        }

        // Department leaders: delegatable pages only.
        return array_map(
            fn (PageAccessEntry $e) => $e->key,
            array_values(array_filter(
                $this->eligibleEntries(),
                fn (PageAccessEntry $e) => $e->delegatable
            ))
        );
    }

    /**
     * Validate keys for page eligibility AND this actor's authority.
     *
     * @param  array<int, string>  $pageKeys
     * @return array<int, string> deduped, canonical-ordered, valid keys
     *
     * @throws InvalidArgumentException
     */
    public function validateKeys(array $pageKeys, User $actor): array
    {
        $unique = array_values(array_unique(array_map('strval', $pageKeys)));
        $grantable = $this->grantableKeysForActor($actor);

        foreach ($unique as $key) {
            $entry = PageAccessCatalog::find($key);

            if (! $entry) {
                throw new InvalidArgumentException("Unknown page key [{$key}] — not present in PageAccessCatalog.");
            }

            if (! $this->isPageEligible($entry)) {
                throw new InvalidArgumentException(
                    "Page [{$key}] is SYSTEM_PROTECTED and can never be set as a user page access override."
                );
            }

            if (! in_array($key, $grantable, true)) {
                throw new InvalidArgumentException(
                    "Page [{$key}] is outside your authority to set as a user page access override."
                );
            }
        }

        return array_values(array_filter(
            $this->eligibleKeys(),
            fn (string $k) => in_array($k, $unique, true)
        ));
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * Current overrides for a user, as page_key => 'allow'|'deny'.
     * Pages absent from this map are DEFAULT (inherit role/department layers).
     *
     * @return array<string, string>
     */
    public function getOverrides(User $user): array
    {
        $stored = UserPageAccessOverride::query()
            ->where('user_id', $user->id)
            ->pluck('state', 'page_key')
            ->all();

        $result = [];

        // Canonical catalog order, matching RolePageDefaultService and
        // DepartmentPageDefaultService, so API output is deterministic. Walking
        // the eligible list also drops any stale row whose page was removed or
        // reclassified as SYSTEM_PROTECTED after it was written.
        foreach ($this->eligibleKeys() as $key) {
            if (isset($stored[$key])) {
                $state = $stored[$key];
                $result[$key] = $state instanceof UserPageAccessState ? $state->value : (string) $state;
            }
        }

        return $result;
    }

    public function getState(User $user, string $pageKey): ?UserPageAccessState
    {
        $value = $this->getOverrides($user)[$pageKey] ?? null;

        return $value ? UserPageAccessState::from($value) : null;
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    /**
     * Create or update a single override. ALLOW -> DENY updates the same row
     * (guaranteed by the unique(user_id, page_key) constraint).
     */
    public function setOverride(
        User $actor,
        User $target,
        string $pageKey,
        UserPageAccessState $state,
    ): UserPageAccessOverride {
        $this->assertCanConfigure($actor, $target);
        $this->validateKeys([$pageKey], $actor);

        return UserPageAccessOverride::updateOrCreate(
            ['user_id' => $target->id, 'page_key' => $pageKey],
            [
                'state' => $state->value,
                'granted_by' => $actor->id,
                'source_department_id' => $this->resolveSourceDepartmentId($actor, $target),
            ],
        );
    }

    /** Reset to DEFAULT — deletes the row. Never writes a 'default' state. */
    public function resetOverride(User $actor, User $target, string $pageKey): void
    {
        $this->assertCanConfigure($actor, $target);

        UserPageAccessOverride::query()
            ->where('user_id', $target->id)
            ->where('page_key', $pageKey)
            ->delete();
    }

    /**
     * Atomically REPLACE a user's whole override set.
     *
     * Any page not present in $allowKeys or $denyKeys is reset to DEFAULT (its
     * row deleted), which is what makes "Reset All" and per-category bulk
     * actions expressible in one request. Everything is validated before any
     * write, so an invalid or out-of-authority key rolls the whole call back
     * with zero changes.
     *
     * @param  array<int, string>  $allowKeys
     * @param  array<int, string>  $denyKeys
     * @return array<string, string> the resulting page_key => state map
     */
    public function replaceOverrides(User $actor, User $target, array $allowKeys, array $denyKeys): array
    {
        $this->assertCanConfigure($actor, $target);

        $allow = $this->validateKeys($allowKeys, $actor);
        $deny = $this->validateKeys($denyKeys, $actor);

        $conflict = array_intersect($allow, $deny);
        if ($conflict) {
            throw new InvalidArgumentException(
                'A page cannot be both allowed and denied: ' . implode(', ', $conflict)
            );
        }

        $sourceDepartmentId = $this->resolveSourceDepartmentId($actor, $target);

        DB::transaction(function () use ($target, $allow, $deny, $actor, $sourceDepartmentId) {
            $desired = [];
            foreach ($allow as $key) {
                $desired[$key] = UserPageAccessState::Allow->value;
            }
            foreach ($deny as $key) {
                $desired[$key] = UserPageAccessState::Deny->value;
            }

            $existing = UserPageAccessOverride::query()
                ->where('user_id', $target->id)
                ->get()
                ->keyBy('page_key');

            // Delete rows no longer wanted (reset to DEFAULT).
            $toDelete = array_diff($existing->keys()->all(), array_keys($desired));
            if ($toDelete) {
                UserPageAccessOverride::query()
                    ->where('user_id', $target->id)
                    ->whereIn('page_key', $toDelete)
                    ->delete();
            }

            foreach ($desired as $key => $state) {
                $row = $existing->get($key);

                // Leave an already-correct row untouched so repeated identical
                // saves stay idempotent and do not churn audit history.
                if ($row && $row->state->value === $state) {
                    continue;
                }

                UserPageAccessOverride::updateOrCreate(
                    ['user_id' => $target->id, 'page_key' => $key],
                    [
                        'state' => $state,
                        'granted_by' => $actor->id,
                        'source_department_id' => $sourceDepartmentId,
                    ],
                );
            }
        });

        return $this->getOverrides($target);
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * Department-scoped provenance: the department the acting leader actually
     * leads and through which they reach this target. NULL for global admins,
     * so a future department-transfer cleanup can revoke department-scoped
     * overrides while leaving global ones intact (Phase 2D+).
     */
    private function resolveSourceDepartmentId(User $actor, User $target): ?int
    {
        if ($this->isGlobalConfigurator($actor)) {
            return null;
        }

        $shared = $this->ledDepartmentIdsOf($actor)
            ->intersect($this->departmentAccess->getAccessibleDepartmentIds($target));

        return $shared->first();
    }
}
