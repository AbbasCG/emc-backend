<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\RolePageDefault;
use App\Models\User;
use App\Services\RolePageDefaultService;
use App\Support\PageAccess\PageAccessCatalog;
use App\Support\PageAccess\RoleDefaultMap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 2G.3A/B — CANONICAL ROLE DEFAULT SYNC.
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION: this seeds which dashboard pages a
 * role opens by default. It grants no permission, policy or data visibility.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * ONE CANONICAL DECISION PATH
 * ─────────────────────────────────────────────────────────────────────────
 *      RoleDefaultMap  ──▶  buildPlan()  ──▶  action list
 *                                 │
 *                    ┌────────────┴────────────┐
 *                render (dry-run)         execute (--apply)
 *
 * Dry-run and apply share buildPlan() exactly — there is deliberately no
 * second calculator, so the plan you review is the plan that executes.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * SAFETY MODEL
 * ─────────────────────────────────────────────────────────────────────────
 *   - READ-ONLY BY DEFAULT. Only --apply may write; there is no implicit apply.
 *   - VALIDATE THE WHOLE PLAN FIRST. Any CONFLICT / INVALID_ROLE /
 *     INVALID_PAGE_KEY aborts before a single row is written.
 *   - ONE TRANSACTION. Every insert runs inside it; any failure rolls the whole
 *     apply back, so a partial 40-of-163 state is impossible.
 *   - ADDITIVE ONLY, NEVER A PRUNE. Rows absent from RoleDefaultMap are left
 *     completely untouched — this is compatibility synchronisation, not
 *     desired-state replacement. That is why it calls the service's
 *     addDefaults() and not replaceDefaults() (which deletes unlisted keys).
 *   - DEFENCE IN DEPTH. Writes go through RolePageDefaultService, which
 *     re-validates every key against the one canonical eligibility rule, so a
 *     malformed plan still cannot store a SYSTEM_PROTECTED page.
 *
 * PROVENANCE: role_page_defaults has created_by/updated_by and no source
 * column. Those are populated with the acting user; the Git-owned
 * RoleDefaultMap remains the canonical record of *why* a row exists. No schema
 * change is introduced for provenance.
 */
class SyncRoleDefaultsCommand extends Command
{
    protected $signature = 'access:sync-role-defaults
        {--dry-run : Report the plan without writing (default behaviour)}
        {--apply : Execute the planned inserts inside one transaction}
        {--role= : Limit the plan to one role slug}
        {--page= : Limit the plan to one PageAccessCatalog page key}';

    protected $description = 'Plan and (with --apply) additively synchronise the canonical role page-access defaults.';

    // Action codes, also used as summary buckets.
    public const INSERT = 'INSERT';
    public const PRESENT = 'ALREADY_PRESENT';
    public const CONFLICT = 'CONFLICT';
    public const SKIP_PROTECTED = 'SKIPPED_PROTECTED';
    public const SKIP_BASELINE = 'SKIPPED_AUTHENTICATED_BASELINE';
    public const SKIP_DEFERRED = 'DEFERRED_PRODUCT_DECISION';
    public const INVALID_ROLE = 'INVALID_ROLE';
    public const INVALID_KEY = 'INVALID_PAGE_KEY';

    /** Actions that must abort an apply before any write. */
    private const BLOCKING = [self::CONFLICT, self::INVALID_ROLE, self::INVALID_KEY];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $onlyPage = $this->option('page');

        // A page filter must name a real catalog key. Refused BEFORE planning,
        // so a typo can never silently resolve to "nothing to do" — or, worse,
        // be mistaken for a successful scoped sync.
        if ($onlyPage !== null && PageAccessCatalog::find($onlyPage) === null) {
            $this->error("Unknown page key [{$onlyPage}] — not present in PageAccessCatalog.");
            $this->line('  Writes performed                : 0');

            return self::FAILURE;
        }

        $plan = $this->buildPlan($this->option('role'), $onlyPage);
        $summary = $this->summarize($plan);

        $this->render($plan, $summary, $apply);

        $blocking = array_values(array_filter($plan, fn (array $a) => in_array($a['action'], self::BLOCKING, true)));

        if ($apply && $blocking !== []) {
            $this->line('');
            $this->error('ABORTED before writing — the plan contains '.count($blocking).' blocking action(s):');
            $this->table(
                ['ROLE', 'PAGE_KEY', 'ACTION', 'REASON'],
                array_map(fn (array $a) => [$a['role'], $a['page_key'], $a['action'], $a['reason']], $blocking),
            );
            $this->line('  Writes performed                : 0');

            return self::FAILURE;
        }

        if (! $apply) {
            $this->line('  Writes performed                : 0');

            return self::SUCCESS;
        }

        try {
            $written = $this->executePlan($plan);
        } catch (Throwable $e) {
            // DB::transaction() has already rolled everything back.
            $this->line('');
            $this->error('APPLY FAILED — transaction rolled back: '.$e->getMessage());
            $this->line('  Writes performed                : 0');

            return self::FAILURE;
        }

        $this->line('  Writes performed                : '.$written);

        return self::SUCCESS;
    }

    /**
     * THE single canonical decision path. Classifies every mapped pair, and
     * additionally scans the stored table for rows that have drifted out of
     * canonical eligibility.
     *
     * $onlyPage narrows the MAPPED pairs to one page key — it filters this same
     * canonical plan, it is not a second calculator. Every classification rule
     * (protected, baseline, deferred, invalid role/key, present) still applies
     * to the pairs that remain. The drift scan is deliberately NOT narrowed: a
     * stored row that has fallen out of eligibility anywhere in the table still
     * blocks an apply, scoped or not, exactly as before.
     *
     * @return array<int, array{role: string, page_key: string, current: string, proposed: string, action: string, reason: string}>
     */
    protected function buildPlan(?string $onlyRole = null, ?string $onlyPage = null): array
    {
        $plan = [];
        $deferred = array_flip(array_merge(RoleDefaultMap::DEFERRED_PRODUCT_DECISION, RoleDefaultMap::LEADER_OWNED));

        foreach ($this->canonicalMap() as $roleName => $pageKeys) {
            if ($onlyRole && $roleName !== $onlyRole) {
                continue;
            }

            $role = Role::query()->where('name', $roleName)->first();
            $stored = $role
                ? RolePageDefault::query()->where('role_id', $role->id)->pluck('page_key')->all()
                : [];

            foreach ($pageKeys as $pageKey) {
                if ($onlyPage !== null && $pageKey !== $onlyPage) {
                    continue;
                }

                $entry = PageAccessCatalog::find($pageKey);

                [$action, $reason, $current, $proposed] = match (true) {
                    $role === null => [self::INVALID_ROLE, "no roles row named [{$roleName}]", 'n/a', 'n/a'],
                    $entry === null => [self::INVALID_KEY, 'not present in PageAccessCatalog', 'n/a', 'n/a'],
                    $entry->isProtected() => [self::SKIP_PROTECTED, 'SYSTEM_PROTECTED — root authority only', 'absent', 'absent'],
                    $entry->authenticatedBaseline => [self::SKIP_BASELINE, 'already granted to every authenticated account', 'absent', 'absent'],
                    isset($deferred[$pageKey]) => [self::SKIP_DEFERRED, 'deferred / owned by canonical leadership', 'absent', 'absent'],
                    in_array($pageKey, $stored, true) => [self::PRESENT, 'row already exists — left untouched', 'allow', 'allow'],
                    default => [self::INSERT, 'legacy role rule grants this page', 'absent', 'allow'],
                };

                $plan[] = compact('action', 'reason') + [
                    'role' => $roleName, 'page_key' => $pageKey, 'current' => $current, 'proposed' => $proposed,
                ];
            }
        }

        return array_merge($plan, $this->driftScan());
    }

    /**
     * Table-wide integrity scan.
     *
     * The schema is POSITIVE-ONLY — there is no state column, so a row either
     * exists or does not, and "existing row in a different state" cannot occur.
     * The real divergence this command must refuse to build on is a STORED ROW
     * WHOSE PAGE IS NO LONGER CANONICALLY ELIGIBLE: a key that has since left
     * the catalog, been reclassified SYSTEM_PROTECTED, or become an
     * authenticated baseline. Such a row is invisible to
     * RolePageDefaultService::getDefaultsForRole() (which filters through
     * eligibleKeys()) yet still occupies its unique slot.
     *
     * Reported as CONFLICT, never modified and never deleted — no prune.
     *
     * @return array<int, array<string, string>>
     */
    protected function driftScan(): array
    {
        $conflicts = [];

        foreach (RolePageDefault::query()->with('role')->get() as $row) {
            $entry = PageAccessCatalog::find($row->page_key);

            $reason = match (true) {
                $entry === null => 'stored row references a page_key absent from PageAccessCatalog',
                $entry->isProtected() => 'stored row references a page since reclassified SYSTEM_PROTECTED',
                $entry->authenticatedBaseline => 'stored row references a page since made an authenticated baseline',
                default => null,
            };

            if ($reason === null) {
                continue;
            }

            $conflicts[] = [
                'role' => $row->role?->name ?? ('role_id='.$row->role_id),
                'page_key' => $row->page_key,
                'current' => 'allow (stored)',
                'proposed' => 'untouched',
                'action' => self::CONFLICT,
                'reason' => $reason,
            ];
        }

        return $conflicts;
    }

    /**
     * Executes ONLY INSERT actions, all inside ONE transaction.
     *
     * @param  array<int, array<string, string>>  $plan
     */
    protected function executePlan(array $plan): int
    {
        $service = app(RolePageDefaultService::class);
        $actor = $this->resolveActor();

        $byRole = [];
        foreach ($plan as $action) {
            if ($action['action'] === self::INSERT) {
                $byRole[$action['role']][] = $action['page_key'];
            }
        }

        if ($byRole === []) {
            return 0;
        }

        return DB::transaction(function () use ($byRole, $service, $actor): int {
            $written = 0;

            foreach ($byRole as $roleName => $keys) {
                $role = Role::query()->where('name', $roleName)->firstOrFail();

                foreach ($keys as $key) {
                    // One pair at a time through the canonical service, so every
                    // key is re-validated against the single eligibility rule
                    // even though the planner already classified it.
                    $written += count($this->applyPair($service, $role, $key, $actor));
                }
            }

            return $written;
        });
    }

    /**
     * Seam for one pair's write. Isolated so a test can inject a deterministic
     * mid-transaction failure without touching the production schema.
     *
     * @return array<int, string> keys actually inserted
     */
    protected function applyPair(RolePageDefaultService $service, Role $role, string $pageKey, ?User $actor): array
    {
        return $service->addDefaults($role, [$pageKey], $actor);
    }

    /** Seam so a test can plan against a deliberately malformed map. */
    protected function canonicalMap(): array
    {
        return RoleDefaultMap::all();
    }

    /** Best-effort attribution for created_by/updated_by; null is acceptable. */
    protected function resolveActor(): ?User
    {
        return User::query()->where('role', 'super_admin')->orderBy('id')->first();
    }

    /** @param array<int, array<string, string>> $plan */
    private function summarize(array $plan): array
    {
        $buckets = [
            self::INSERT => 0, self::PRESENT => 0, self::SKIP_PROTECTED => 0,
            self::SKIP_BASELINE => 0, self::SKIP_DEFERRED => 0, self::CONFLICT => 0,
            self::INVALID_ROLE => 0, self::INVALID_KEY => 0,
        ];

        foreach ($plan as $action) {
            $buckets[$action['action']]++;
        }

        return $buckets;
    }

    /** @param array<int, array<string, string>> $plan */
    private function render(array $plan, array $summary, bool $apply): void
    {
        $evaluated = count(array_filter($plan, fn (array $a) => $a['action'] !== self::CONFLICT));

        $this->line('');
        $this->info('EMC ACCESS CONTROL — role page-access default plan'.($apply ? ' (APPLY)' : ' (DRY RUN)'));
        $this->line('PAGE ACCESS != BUSINESS AUTHORIZATION — this plan grants no permission.');
        $this->line('');

        $this->table(
            ['ROLE', 'PAGE_KEY', 'CURRENT_STATE', 'PROPOSED_STATE', 'ACTION', 'REASON'],
            array_map(
                fn (array $a) => [$a['role'], $a['page_key'], $a['current'], $a['proposed'], $a['action'], $a['reason']],
                $plan,
            ),
        );

        $this->line('');
        $this->info('SUMMARY');
        $this->line('  Mappings evaluated              : '.$evaluated);
        $this->line('  Planned inserts                 : '.$summary[self::INSERT]);
        $this->line('  Would insert                    : '.$summary[self::INSERT]);
        $this->line('  Already present                 : '.$summary[self::PRESENT]);
        $this->line('  Skipped protected               : '.$summary[self::SKIP_PROTECTED]);
        $this->line('  Skipped authenticated baseline  : '.$summary[self::SKIP_BASELINE]);
        $this->line('  Deferred product decision       : '.$summary[self::SKIP_DEFERRED]);
        $this->line('  Conflicts                       : '.$summary[self::CONFLICT]);
        $this->line('  Invalid roles                   : '.$summary[self::INVALID_ROLE]);
        $this->line('  Invalid page keys               : '.$summary[self::INVALID_KEY]);
    }
}
