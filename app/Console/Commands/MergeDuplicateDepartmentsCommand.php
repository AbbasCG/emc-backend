<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Services\DepartmentDuplicateDetector;
use App\Services\DepartmentMergeService;
use App\Support\DepartmentNameNormalizer;
use Illuminate\Console\Command;

/**
 * Merges duplicate departments into one canonical department.
 *
 * DRY-RUN BY DEFAULT: nothing is written unless --apply is passed. Every
 * planned pair is validated first; if ANY pair has a conflict, nothing is
 * written for any pair and the command exits non-zero.
 *
 *   departments:merge-duplicates --name="..."                 dry-run one group
 *   departments:merge-duplicates --name="..." --apply         apply it
 *   departments:merge-duplicates --all                        dry-run every group
 *   departments:merge-duplicates --canonical-id=13 --duplicate-id=11
 *
 * Leadership is never guessed: if the rows disagree, pass --leader=canonical
 * or --leader=duplicate. Historical records (tickets, reports, ...) stay put
 * unless --move-historical is passed. See DepartmentMergeService.
 *
 * Do not run `--all --apply` against production without reviewing the dry-run.
 */
class MergeDuplicateDepartmentsCommand extends Command
{
    protected $signature = 'departments:merge-duplicates
        {--name= : Department name (Arabic or English); selects its duplicate group}
        {--all : Every exact/high-confidence group (review-tier pairs are skipped)}
        {--canonical-id= : Keep this department (override the recommendation)}
        {--duplicate-id= : Merge only this department into the canonical one}
        {--leader= : Resolve a leadership difference: canonical|duplicate}
        {--move-historical : Also re-point tickets/reports/requests (rewrites history)}
        {--force : Allow ids that are not in a detected duplicate group}
        {--apply : Perform the merge (default is a dry-run with no writes)}
        {--dry-run : Explicit dry-run (the default)}';

    protected $description = 'Safely merge duplicate departments into one canonical department (dry-run by default).';

    public function handle(DepartmentDuplicateDetector $detector, DepartmentMergeService $merge): int
    {
        $apply = (bool) $this->option('apply');

        if ($apply && $this->option('dry-run')) {
            $this->error('Use either --apply or --dry-run, not both.');

            return self::FAILURE;
        }

        $leader = $this->option('leader');
        if ($leader !== null && ! in_array($leader, ['canonical', 'duplicate'], true)) {
            $this->error('--leader must be "canonical" or "duplicate".');

            return self::FAILURE;
        }

        // --canonical-id / --duplicate-id are overrides when --name is given,
        // and the whole selection when they are used on their own.
        $hasName = filled($this->option('name'));
        $hasAll = (bool) $this->option('all');
        $hasIds = ! $hasName && ! $hasAll && (filled($this->option('canonical-id')) || filled($this->option('duplicate-id')));
        if ((int) $hasName + (int) $hasAll + (int) $hasIds !== 1) {
            $this->error('Choose exactly one of: --name=, --all, or --canonical-id with --duplicate-id.');

            return self::FAILURE;
        }
        if ($hasAll && (filled($this->option('canonical-id')) || filled($this->option('duplicate-id')))) {
            $this->error('--canonical-id/--duplicate-id cannot be combined with --all.');

            return self::FAILURE;
        }

        $pairs = $this->resolvePairs($detector, $merge);
        if ($pairs === null) {
            return self::FAILURE;
        }
        if ($pairs === []) {
            $this->info('No duplicate departments to merge — nothing to do.');

            return self::SUCCESS;
        }

        $options = ['leader' => $leader, 'moveHistorical' => (bool) $this->option('move-historical')];

        $this->info($apply ? 'APPLY mode — validating every pair before any write.' : 'Dry run — no writes will be made. Pass --apply to merge.');

        $plans = [];
        $blocked = false;
        foreach ($pairs as [$canonical, $duplicate]) {
            $plan = $merge->plan($canonical, $duplicate, $options);
            $plans[] = $plan;
            $this->renderPlan($plan);
            $blocked = $blocked || $plan['conflicts'] !== [];
        }

        if ($blocked) {
            $this->line('');
            $this->error('Conflicts found — no changes were made. Resolve them and re-run.');

            return self::FAILURE;
        }

        if (! $apply) {
            $this->line('');
            $this->info('Dry run complete — no writes made.');

            return self::SUCCESS;
        }

        foreach ($plans as $plan) {
            $result = $merge->apply($plan['canonical'], $plan['duplicate'], $options);

            $this->line('');
            if ($result['conflicts'] !== []) {
                // A concurrent change introduced a conflict after the dry-run.
                foreach ($result['conflicts'] as $c) {
                    $this->error("CONFLICT: $c");
                }

                return self::FAILURE;
            }
            if ($result['outcome'] === 'NOOP') {
                $this->info("Department #{$plan['duplicate']->id} is already merged — nothing changed.");

                continue;
            }

            $this->info("Merged #{$plan['duplicate']->id} into #{$plan['canonical']->id} — duplicate {$result['outcome']}.");
            $rows = collect($result['moved'])->filter()->map(fn ($n, $t) => [$t, $n])->values()->all();
            if ($rows !== []) {
                $this->table(['table', 'rows moved'], $rows);
            }
            if ($plan['displaced'] !== []) {
                $this->warn('Displaced leadership (no longer on the canonical department): '.json_encode($plan['displaced']));
            }
        }

        return self::SUCCESS;
    }

    /** @return list<array{0: Department, 1: Department}>|null null = error already printed */
    private function resolvePairs(DepartmentDuplicateDetector $detector, DepartmentMergeService $merge): ?array
    {
        $force = (bool) $this->option('force');
        $canonicalId = $this->option('canonical-id');
        $duplicateId = $this->option('duplicate-id');

        // ── explicit ids (standalone selection) ─────────────────────────────
        if (! filled($this->option('name')) && ! $this->option('all')) {
            if (! filled($canonicalId) || ! filled($duplicateId)) {
                $this->error('--canonical-id and --duplicate-id must be given together.');

                return null;
            }

            $canonical = Department::find((int) $canonicalId);
            $duplicate = Department::find((int) $duplicateId);
            foreach ([['canonical', $canonicalId, $canonical], ['duplicate', $duplicateId, $duplicate]] as [$label, $id, $found]) {
                if (! $found) {
                    $this->error("Unknown {$label} department id {$id}.");

                    return null;
                }
            }
            if ($canonical->id === $duplicate->id) {
                $this->error('Refusing to merge a department into itself.');

                return null;
            }

            if (! $force && ! $this->inSameSafeGroup($detector, $canonical, $duplicate)) {
                $this->error("Departments #{$canonical->id} and #{$duplicate->id} are not in the same detected duplicate group. Use --force only if you are certain.");

                return null;
            }

            return [[$canonical, $duplicate]];
        }

        // ── by name ─────────────────────────────────────────────────────────
        if (filled($this->option('name'))) {
            $name = (string) $this->option('name');
            $key = DepartmentNameNormalizer::key($name);

            $matching = Department::query()->where('status', '!=', 'archived')->get()
                ->filter(fn (Department $d) => DepartmentNameNormalizer::key($d->name_ar ?: $d->name) === $key
                    || DepartmentNameNormalizer::key($d->name_en) === $key);

            if ($matching->isEmpty()) {
                $this->error("No department matches \"{$name}\".");

                return null;
            }

            $group = $detector->groupForName($name);
            if ($group === null) {
                $this->info("Only one department matches \"{$name}\" — no duplicate to merge.");

                return [];
            }

            return $this->pairsForGroup($group['departments'], $merge, $force);
        }

        // ── --all ───────────────────────────────────────────────────────────
        $pairs = [];
        foreach ($detector->detect() as $group) {
            if ($group['tier'] === DepartmentDuplicateDetector::REVIEW) {
                $names = $group['departments']->map(fn ($d) => "#{$d->id}")->implode(' / ');
                $this->warn("Skipping review-only pair {$names}: ".implode('; ', $group['reasons']));

                continue;
            }
            $groupPairs = $this->pairsForGroup($group['departments'], $merge, $force);
            if ($groupPairs === null) {
                return null;
            }
            array_push($pairs, ...$groupPairs);
        }

        return $pairs;
    }

    /**
     * @param \Illuminate\Support\Collection<int, Department> $departments
     * @return list<array{0: Department, 1: Department}>|null
     */
    private function pairsForGroup($departments, DepartmentMergeService $merge, bool $force): ?array
    {
        $canonicalOverride = $this->option('canonical-id');
        if (filled($canonicalOverride)) {
            $canonical = $departments->firstWhere('id', (int) $canonicalOverride);
            if (! $canonical && ! $force) {
                $this->error("Department #{$canonicalOverride} is not in this duplicate group. Use --force to override.");

                return null;
            }
            $canonical ??= Department::find((int) $canonicalOverride);
            if (! $canonical) {
                $this->error("Unknown canonical department id {$canonicalOverride}.");

                return null;
            }
        } else {
            $canonical = $merge->recommendCanonical($departments)['department'];
        }

        $duplicates = $departments->reject(fn ($d) => $d->id === $canonical->id);

        $onlyDuplicate = $this->option('duplicate-id');
        if (filled($onlyDuplicate)) {
            $duplicates = $duplicates->where('id', (int) $onlyDuplicate);
            if ($duplicates->isEmpty()) {
                $this->error("Department #{$onlyDuplicate} is not a duplicate in this group (or is the canonical one).");

                return null;
            }
        }

        return $duplicates->map(fn ($d) => [$canonical, $d])->values()->all();
    }

    private function inSameSafeGroup(DepartmentDuplicateDetector $detector, Department $a, Department $b): bool
    {
        // Archived departments are included so an already-merged duplicate
        // still resolves (and then reports NOOP) instead of being "unknown".
        return $detector->detect(true)
            ->filter(fn ($g) => $g['tier'] !== DepartmentDuplicateDetector::REVIEW)
            ->contains(fn ($g) => $g['departments']->contains('id', $a->id) && $g['departments']->contains('id', $b->id));
    }

    private function renderPlan(array $plan): void
    {
        /** @var Department $canonical */
        $canonical = $plan['canonical'];
        /** @var Department $duplicate */
        $duplicate = $plan['duplicate'];

        $this->line('');
        $this->info("KEEP   #{$canonical->id} {$canonical->slug} — ".($canonical->name_ar ?: $canonical->name)." (leader_id=".($canonical->leader_id ?? 'NULL').", status={$canonical->status})");
        $this->info("MERGE  #{$duplicate->id} {$duplicate->slug} — ".($duplicate->name_ar ?: $duplicate->name)." (leader_id=".($duplicate->leader_id ?? 'NULL').", status={$duplicate->status})");

        if ($plan['noop']) {
            $this->info('Already merged: the duplicate is archived and holds no live records — nothing to do.');

            return;
        }

        $rows = [];
        foreach ($plan['counts']['move'] as $table => $n) {
            if ($n > 0) {
                $rows[] = [$table, 'MOVE', $n, 'WILL_MOVE'];
            }
        }
        foreach ($plan['counts']['preserve'] as $table => $n) {
            if ($n > 0) {
                $rows[] = [$table, $plan['move_historical'] ? 'HISTORICAL' : 'PRESERVE', $n, $plan['move_historical'] ? 'WILL_MOVE (--move-historical)' : 'STAYS (historical)'];
            }
        }
        $this->table(['table', 'category', 'rows', 'action'], $rows ?: [['—', '—', 0, 'no linked records']]);

        $lead = [];
        foreach ($plan['leadership'] as $field => $row) {
            $lead[] = [$field, $row['canonical'] ?? 'NULL', $row['duplicate'] ?? 'NULL', $row['state']];
        }
        $this->table(['leadership field', 'canonical', 'duplicate', 'state'], $lead);

        foreach ($plan['warnings'] as $w) {
            $this->warn("risk: $w");
        }
        foreach ($plan['conflicts'] as $c) {
            $this->error("CONFLICT: $c");
        }

        $this->line("Predicted outcome for #{$duplicate->id}: {$plan['outcome']}");
    }
}
