<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Services\DepartmentDuplicateDetector;
use App\Services\DepartmentMergeService;
use Illuminate\Console\Command;

/**
 * READ-ONLY audit of duplicate departments. Writes nothing, ever.
 *
 * Use departments:merge-duplicates to act on what this reports.
 */
class DepartmentsDuplicatesCommand extends Command
{
    protected $signature = 'departments:duplicates
        {--dry-run : Accepted for symmetry; this command is always read-only}
        {--include-archived : Also compare archived (already merged) departments}
        {--fail-on-duplicates : Exit non-zero when an exact/high-confidence duplicate exists (for CI/monitoring)}';

    protected $description = 'Read-only audit: list duplicate departments, their linked data, the recommended canonical row and merge risks.';

    public function handle(DepartmentDuplicateDetector $detector, DepartmentMergeService $merge): int
    {
        $groups = $detector->detect((bool) $this->option('include-archived'));

        if ($groups->isEmpty()) {
            $this->info('No duplicate departments found.');

            return self::SUCCESS;
        }

        $safe = $groups->filter(fn ($g) => $g['tier'] !== DepartmentDuplicateDetector::REVIEW);
        $review = $groups->filter(fn ($g) => $g['tier'] === DepartmentDuplicateDetector::REVIEW);

        $this->info("Found {$safe->count()} exact/high-confidence group(s) and {$review->count()} possible duplicate(s) needing review. No data was changed.");

        foreach ($groups as $i => $group) {
            $this->line('');
            $this->info('['.($i + 1).'] '.strtoupper($group['tier']).' — '.implode('; ', $group['reasons']));

            $rows = [];
            foreach ($group['departments'] as $d) {
                /** @var Department $d */
                $counts = $merge->linkedCounts($d);
                $rows[] = [
                    $d->id, $d->name_ar ?: $d->name, $d->name_en, $d->slug, $d->leader_id ?? 'NULL', $d->status,
                    $d->created_at?->toDateTimeString(), $d->updated_at?->toDateTimeString(),
                    array_sum($counts['move']), array_sum($counts['preserve']),
                ];
            }
            $this->table(['id', 'name_ar', 'name_en', 'slug', 'leader_id', 'status', 'created_at', 'updated_at', 'live links', 'historical links'], $rows);

            if ($group['tier'] === DepartmentDuplicateDetector::REVIEW) {
                $this->warn('Review only — never merged automatically.');

                continue;
            }

            $rec = $merge->recommendCanonical($group['departments']);
            /** @var Department $canonical */
            $canonical = $rec['department'];
            $this->line("Recommended canonical: #{$canonical->id} ({$canonical->slug}) — ".implode(', ', $rec['reasons']));

            foreach ($group['departments'] as $d) {
                if ($d->id === $canonical->id) {
                    continue;
                }

                $counts = $merge->linkedCounts($d);
                $detail = collect($counts['move'] + $counts['preserve'])->filter()->map(fn ($n, $t) => "$t=$n")->implode(', ');
                $this->line("  duplicate #{$d->id}: ".($detail ?: 'no linked records'));

                $plan = $merge->plan($canonical, $d);
                foreach ($plan['conflicts'] as $c) {
                    $this->error("    CONFLICT: $c");
                }
                foreach ($plan['warnings'] as $w) {
                    $this->warn("    risk: $w");
                }
            }
        }

        if ($safe->isNotEmpty() && $this->option('fail-on-duplicates')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
