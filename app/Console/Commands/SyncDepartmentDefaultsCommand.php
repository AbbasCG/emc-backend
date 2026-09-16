<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\DepartmentPageDefault;
use App\Services\DepartmentPageDefaultService;
use App\Support\PageAccess\DepartmentDefaultMap;
use App\Support\PageAccess\DepartmentPageAudience;
use App\Support\PageAccess\PageAccessCatalog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 2G.4 — CANONICAL DEPARTMENT DEFAULT SYNC.
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION: this plans which dashboard pages a
 * department opens by default for its members and its canonical leader. It
 * grants no permission, and the two mapped leader surfaces keep their own
 * backend authority (DepartmentAccessService / PermissionDelegationService).
 *
 * ─────────────────────────────────────────────────────────────────────────
 * ONE CANONICAL DECISION PATH — mirrors SyncRoleDefaultsCommand exactly
 * ─────────────────────────────────────────────────────────────────────────
 *      DepartmentDefaultMap  ──▶  buildPlan()  ──▶  action list
 *                                      │
 *                                 render (dry-run)
 *
 * ─────────────────────────────────────────────────────────────────────────
 * SAFETY MODEL
 * ─────────────────────────────────────────────────────────────────────────
 *   - READ-ONLY BY DEFAULT. Only --apply may write; there is no implicit apply.
 *   - VALIDATE THE WHOLE PLAN FIRST. Any CONFLICT / INVALID_DEPARTMENT /
 *     INVALID_PAGE_KEY aborts before a single row is written.
 *   - ONE TRANSACTION. Every insert runs inside it; any failure rolls the whole
 *     apply back, so a partial state is impossible.
 *   - ADDITIVE ONLY, NEVER A PRUNE. Rows absent from DepartmentDefaultMap are
 *     left untouched - this is compatibility synchronisation, not desired-state
 *     replacement. That is why it calls the service's addDefaults() and not
 *     replaceDefaults(), which deletes unlisted keys.
 *   - DEFENCE IN DEPTH. Writes go through DepartmentPageDefaultService, which
 *     re-validates every key against the one canonical eligibility rule, so a
 *     malformed plan still cannot store a SYSTEM_PROTECTED page.
 *   - ROLE DEFAULTS AND USER OVERRIDES ARE NEVER TOUCHED.
 *
 * DEPARTMENT IDENTITY IS THE SLUG. Numeric ids never appear in the map; the
 * slug is resolved to a row here, at execution time, against the live table.
 */
class SyncDepartmentDefaultsCommand extends Command
{
    protected $signature = 'access:sync-department-defaults
        {--dry-run : Report the plan without writing (default behaviour)}
        {--apply : Execute the planned inserts inside one transaction}
        {--department= : Limit the plan to one department slug}';

    protected $description = 'Plan and (with --apply) additively synchronise the canonical department page-access defaults.';

    // Action codes, also used as summary buckets.
    public const INSERT = 'INSERT';
    public const PRESENT = 'ALREADY_PRESENT';
    public const CONFLICT = 'CONFLICT';
    public const SKIP_PROTECTED = 'SKIPPED_PROTECTED';
    public const SKIP_DEFERRED = 'DEFERRED_PRODUCT_DECISION';
    public const INVALID_DEPARTMENT = 'INVALID_DEPARTMENT';
    public const INVALID_KEY = 'INVALID_PAGE_KEY';
    public const INACTIVE_DEPARTMENT = 'SKIPPED_INACTIVE_DEPARTMENT';

    /** Actions that would abort a future apply before any write. */
    private const BLOCKING = [self::CONFLICT, self::INVALID_DEPARTMENT, self::INVALID_KEY];

    public function handle(): int
    {
        $plan = $this->buildPlan($this->option('department'));
        $summary = $this->summarize($plan);

        $this->render($plan, $summary);

        $apply = (bool) $this->option('apply');

        $blocking = array_values(array_filter(
            $plan,
            fn (array $a) => in_array($a['action'], self::BLOCKING, true),
        ));

        if ($apply && $blocking !== []) {
            $this->line('');
            $this->error('ABORTED before writing - the plan contains '.count($blocking).' blocking action(s):');
            $this->table(
                ['DEPARTMENT', 'AUDIENCE', 'PAGE_KEY', 'ACTION', 'REASON'],
                array_map(fn (array $a) => [
                    $a['department'], $a['audience'], $a['page_key'], $a['action'], $a['reason'],
                ], $blocking),
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
            $this->error('APPLY FAILED - transaction rolled back: '.$e->getMessage());
            $this->line('  Writes performed                : 0');

            return self::FAILURE;
        }

        $this->line('  Writes performed                : '.$written);

        return self::SUCCESS;
    }

    /**
     * Executes ONLY INSERT actions, all inside ONE transaction.
     *
     * @param  array<int, array<string, string>>  $plan
     */
    protected function executePlan(array $plan): int
    {
        $service = app(DepartmentPageDefaultService::class);
        $actor = $this->resolveActor();

        $grouped = [];
        foreach ($plan as $action) {
            if ($action['action'] === self::INSERT) {
                $grouped[$action['department']][$action['audience']][] = $action['page_key'];
            }
        }

        if ($grouped === []) {
            return 0;
        }

        return DB::transaction(function () use ($grouped, $service, $actor): int {
            $written = 0;

            foreach ($grouped as $slug => $byAudience) {
                $department = Department::query()->where('slug', $slug)->firstOrFail();

                foreach ($byAudience as $audienceValue => $keys) {
                    $audience = DepartmentPageAudience::from($audienceValue);

                    foreach ($keys as $key) {
                        // One pair at a time through the canonical service, so every
                        // key is re-validated against the single eligibility rule
                        // even though the planner already classified it.
                        $written += count($this->applyPair($service, $department, $audience, $key, $actor));
                    }
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
    protected function applyPair(
        DepartmentPageDefaultService $service,
        Department $department,
        DepartmentPageAudience $audience,
        string $pageKey,
        ?User $actor,
    ): array {
        return $service->addDefaults($department, $audience, [$pageKey], $actor);
    }

    /** Provenance actor for created_by/updated_by. Null is acceptable. */
    protected function resolveActor(): ?User
    {
        return User::query()
            ->whereIn('role', ['super_admin', 'tech_admin'])
            ->orderBy('id')
            ->first();
    }

    /**
     * THE single canonical decision path. Classifies every mapped
     * department/audience/page triple, and additionally scans the stored table
     * for rows that have drifted out of canonical eligibility.
     *
     * @return array<int, array{department: string, audience: string, page_key: string, current: string, proposed: string, action: string, reason: string}>
     */
    protected function buildPlan(?string $onlySlug = null): array
    {
        $service = app(DepartmentPageDefaultService::class);
        $plan = [];

        // Resolve slugs to rows ONCE. Missing slug => INVALID_DEPARTMENT.
        $departments = Department::query()
            ->whereIn('slug', DepartmentDefaultMap::departments())
            ->get()
            ->keyBy('slug');

        $stored = DepartmentPageDefault::query()
            ->get()
            ->groupBy(fn (DepartmentPageDefault $r) => $r->department_id.'|'.$r->audience->value);

        foreach ($this->canonicalMap() as $slug => $audiences) {
            if ($onlySlug !== null && $slug !== $onlySlug) {
                continue;
            }

            $department = $departments->get($slug);

            foreach ($audiences as $audienceValue => $keys) {
                $audience = DepartmentPageAudience::from($audienceValue);

                foreach ($keys as $pageKey) {
                    $entry = PageAccessCatalog::find($pageKey);
                    $existing = $department
                        ? ($stored->get($department->id.'|'.$audienceValue) ?? collect())->pluck('page_key')->all()
                        : [];

                    [$action, $reason] = match (true) {
                        $department === null => [
                            self::INVALID_DEPARTMENT,
                            "no department row carries slug [{$slug}]",
                        ],
                        $department->status !== 'active' => [
                            self::INACTIVE_DEPARTMENT,
                            "department is [{$department->status}] — inactive departments grant nothing",
                        ],
                        $entry === null => [
                            self::INVALID_KEY,
                            'page key is absent from PageAccessCatalog',
                        ],
                        $entry->isProtected() => [
                            self::SKIP_PROTECTED,
                            'SYSTEM_PROTECTED is never a department default',
                        ],
                        ! $service->isEligible($entry, $audience) => [
                            self::INVALID_KEY,
                            "not eligible for the [{$audienceValue}] audience ({$entry->riskLevel->value})",
                        ],
                        in_array($pageKey, $existing, true) => [
                            self::PRESENT,
                            'already stored for this department and audience',
                        ],
                        default => [
                            self::INSERT,
                            'canonical leader-owned surface for this department',
                        ],
                    };

                    $plan[] = [
                        'department' => $slug,
                        'audience' => $audienceValue,
                        'page_key' => $pageKey,
                        'current' => in_array($pageKey, $existing, true) ? 'allow' : 'absent',
                        'proposed' => 'allow',
                        'action' => $action,
                        'reason' => $reason,
                    ];
                }
            }
        }

        return array_merge($plan, $this->deferredRows(), $this->driftScan());
    }

    /**
     * The audited-and-withheld capabilities, surfaced so the plan states what
     * it deliberately does NOT map instead of staying silent about it. These
     * never write and never block.
     *
     * @return array<int, array<string, string>>
     */
    protected function deferredRows(): array
    {
        $rows = [];

        foreach (DepartmentDefaultMap::DEFERRED_PRODUCT_DECISION as $pageKey) {
            $rows[] = [
                'department' => '(all)',
                'audience' => '(none)',
                'page_key' => $pageKey,
                'current' => 'absent',
                'proposed' => 'absent',
                'action' => self::SKIP_DEFERRED,
                'reason' => 'audited in 2G.4A — not department-gated at the backend',
            ];
        }

        foreach (DepartmentDefaultMap::INTENTIONAL_SYSTEM_PROTECTED as $pageKey) {
            $rows[] = [
                'department' => '(all)',
                'audience' => '(none)',
                'page_key' => $pageKey,
                'current' => 'absent',
                'proposed' => 'absent',
                'action' => self::SKIP_PROTECTED,
                'reason' => 'intentional root-authority surface — must remain denied',
            ];
        }

        return $rows;
    }

    /**
     * Table-wide integrity sweep. Because department_page_defaults is
     * positive-only (row existence IS allow, there is no state column), a
     * stored row cannot disagree about STATE. What it CAN do is drift out of
     * eligibility — an unknown key, a key reclassified SYSTEM_PROTECTED, or a
     * key no longer eligible for the audience it is stored under. Such a row is
     * invisible to the service's reads yet still occupies its unique slot, so
     * building on top of it is unsafe.
     *
     * @return array<int, array<string, string>>
     */
    protected function driftScan(): array
    {
        $service = app(DepartmentPageDefaultService::class);
        $slugs = Department::query()->pluck('slug', 'id');
        $rows = [];

        foreach (DepartmentPageDefault::query()->get() as $row) {
            $entry = PageAccessCatalog::find($row->page_key);
            $audience = $row->audience;

            $reason = match (true) {
                $audience === null => 'stored audience is not a valid DepartmentPageAudience',
                $entry === null => 'stored page key is absent from PageAccessCatalog',
                $entry->isProtected() => 'stored page is now SYSTEM_PROTECTED',
                ! $service->isEligible($entry, $audience) => "stored page is no longer eligible for the [{$audience->value}] audience",
                default => null,
            };

            if ($reason === null) {
                continue;
            }

            $rows[] = [
                'department' => (string) ($slugs[$row->department_id] ?? "#{$row->department_id}"),
                'audience' => $audience?->value ?? '(invalid)',
                'page_key' => $row->page_key,
                'current' => 'allow',
                'proposed' => 'review',
                'action' => self::CONFLICT,
                'reason' => $reason,
            ];
        }

        return $rows;
    }

    /** Seam so a test can plan against a deliberately malformed map. */
    protected function canonicalMap(): array
    {
        return DepartmentDefaultMap::all();
    }

    /** @param array<int, array<string, string>> $plan */
    protected function summarize(array $plan): array
    {
        $counts = [];

        foreach ($plan as $action) {
            $counts[$action['action']] = ($counts[$action['action']] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  array<int, array<string, string>>  $plan
     * @param  array<string, int>  $summary
     */
    protected function render(array $plan, array $summary): void
    {
        $mapped = array_values(array_filter(
            $plan,
            fn (array $a) => ! in_array($a['action'], [self::SKIP_DEFERRED], true) || $a['department'] !== '(all)'
        ));

        $this->table(
            ['DEPARTMENT', 'AUDIENCE', 'PAGE_KEY', 'CURRENT_STATE', 'PROPOSED_STATE', 'ACTION', 'REASON'],
            array_map(fn (array $a) => [
                $a['department'], $a['audience'], $a['page_key'],
                $a['current'], $a['proposed'], $a['action'], $a['reason'],
            ], $plan),
        );

        $evaluated = count(array_filter($plan, fn (array $a) => $a['department'] !== '(all)'));
        $blocking = count(array_filter($plan, fn (array $a) => in_array($a['action'], self::BLOCKING, true)));

        $this->line('');
        $this->info('SUMMARY');
        $this->line('  Mappings evaluated              : '.$evaluated);
        $this->line('  Would insert                    : '.($summary[self::INSERT] ?? 0));
        $this->line('  Already present                 : '.($summary[self::PRESENT] ?? 0));
        $this->line('  Conflicts                       : '.($summary[self::CONFLICT] ?? 0));
        $this->line('  Invalid departments             : '.($summary[self::INVALID_DEPARTMENT] ?? 0));
        $this->line('  Invalid page keys               : '.($summary[self::INVALID_KEY] ?? 0));
        $this->line('  Skipped protected               : '.($summary[self::SKIP_PROTECTED] ?? 0));
        $this->line('  Skipped inactive departments    : '.($summary[self::INACTIVE_DEPARTMENT] ?? 0));
        $this->line('  Deferred product decision       : '.($summary[self::SKIP_DEFERRED] ?? 0));
        $this->line('  Blocking actions                : '.$blocking);

        unset($mapped);
    }
}
