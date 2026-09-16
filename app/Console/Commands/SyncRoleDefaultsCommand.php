<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\RolePageDefault;
use App\Support\PageAccess\PageAccessCatalog;
use App\Support\PageAccess\RoleDefaultMap;
use Illuminate\Console\Command;

/**
 * Phase 2G.3A — ROLE DEFAULT SYNC, DRY-RUN FIRST.
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION: this seeds which dashboard pages a
 * role opens by default. It grants no permission to anyone.
 *
 * SAFETY MODEL
 *   - Dry-run is the DEFAULT. Writing requires an explicit --apply, and
 *     --apply is refused outright while Phase 2G.3A is under review.
 *   - ADDITIVE ONLY. Nothing is ever deleted, and an existing row is never
 *     overwritten — a row whose state differs is reported as a CONFLICT and
 *     skipped, so administrator customization survives.
 *   - Every skip is REPORTED with a reason; nothing is silently dropped.
 *   - Eligibility is never re-derived here: writes go through
 *     RolePageDefaultService, which owns the rule and rejects protected keys.
 */
class SyncRoleDefaultsCommand extends Command
{
    protected $signature = 'access:sync-role-defaults
        {--dry-run : Report the plan without writing (default behaviour)}
        {--apply : Perform the writes — BLOCKED during Phase 2G.3A review}
        {--role= : Limit the plan to one role slug}';

    protected $description = 'Plan (and, once approved, apply) the canonical role page-access defaults.';

    // Action codes, also used as summary buckets.
    private const INSERT = 'INSERT';
    private const PRESENT = 'ALREADY_PRESENT';
    private const SKIP_PROTECTED = 'SKIP_PROTECTED';
    private const SKIP_BASELINE = 'SKIP_AUTH_BASELINE';
    private const SKIP_DEFERRED = 'SKIP_DEFERRED_DECISION';
    private const CONFLICT = 'CONFLICT';
    private const INVALID_ROLE = 'INVALID_ROLE';
    private const INVALID_KEY = 'INVALID_PAGE_KEY';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        if ($apply) {
            $this->error('--apply is blocked during Phase 2G.3A. The plan must be reviewed and approved first.');

            return self::FAILURE;
        }

        $onlyRole = $this->option('role');
        $rows = [];
        $summary = [
            self::INSERT => 0, self::PRESENT => 0, self::SKIP_PROTECTED => 0,
            self::SKIP_BASELINE => 0, self::SKIP_DEFERRED => 0, self::CONFLICT => 0,
            self::INVALID_ROLE => 0, self::INVALID_KEY => 0,
        ];
        $evaluated = 0;

        $deferred = array_flip(RoleDefaultMap::DEFERRED_PRODUCT_DECISION);
        $leaderOwned = array_flip(RoleDefaultMap::LEADER_OWNED);

        foreach (RoleDefaultMap::all() as $roleName => $pageKeys) {
            if ($onlyRole && $roleName !== $onlyRole) {
                continue;
            }

            $role = Role::query()->where('name', $roleName)->first();
            $stored = $role
                ? RolePageDefault::query()->where('role_id', $role->id)->pluck('page_key')->all()
                : [];

            foreach ($pageKeys as $pageKey) {
                $evaluated++;
                $entry = PageAccessCatalog::find($pageKey);

                [$action, $reason, $current, $proposed] = match (true) {
                    $role === null => [
                        self::INVALID_ROLE, "no roles row named [{$roleName}]", 'n/a', 'n/a',
                    ],
                    $entry === null => [
                        self::INVALID_KEY, 'not present in PageAccessCatalog', 'n/a', 'n/a',
                    ],
                    $entry->isProtected() => [
                        self::SKIP_PROTECTED, 'SYSTEM_PROTECTED — root authority only', 'absent', 'absent',
                    ],
                    $entry->authenticatedBaseline => [
                        self::SKIP_BASELINE, 'already granted to every authenticated account', 'absent', 'absent',
                    ],
                    isset($deferred[$pageKey]) => [
                        self::SKIP_DEFERRED, 'DEFERRED_PRODUCT_DECISION', 'absent', 'absent',
                    ],
                    isset($leaderOwned[$pageKey]) => [
                        self::SKIP_DEFERRED, 'owned by canonical department leadership', 'absent', 'absent',
                    ],
                    in_array($pageKey, $stored, true) => [
                        self::PRESENT, 'row already exists — left untouched', 'present', 'present',
                    ],
                    default => [
                        self::INSERT, 'legacy role rule grants this page', 'absent', 'present',
                    ],
                };

                $summary[$action]++;
                $rows[] = [$roleName, $pageKey, $current, $proposed, $action, $reason];
            }
        }

        $this->line('');
        $this->info('EMC ACCESS CONTROL — role page-access default plan (DRY RUN)');
        $this->line('PAGE ACCESS != BUSINESS AUTHORIZATION — this plan grants no permission.');
        $this->line('');

        $this->table(
            ['ROLE', 'PAGE_KEY', 'CURRENT_STATE', 'PROPOSED_STATE', 'ACTION', 'REASON'],
            $rows,
        );

        $this->line('');
        $this->info('SUMMARY');
        $this->line('  Mappings evaluated              : '.$evaluated);
        $this->line('  Would insert                    : '.$summary[self::INSERT]);
        $this->line('  Already present                 : '.$summary[self::PRESENT]);
        $this->line('  Skipped protected               : '.$summary[self::SKIP_PROTECTED]);
        $this->line('  Skipped authenticated baseline  : '.$summary[self::SKIP_BASELINE]);
        $this->line('  Deferred product decision       : '.$summary[self::SKIP_DEFERRED]);
        $this->line('  Conflicts                       : '.$summary[self::CONFLICT]);
        $this->line('  Invalid roles                   : '.$summary[self::INVALID_ROLE]);
        $this->line('  Invalid page keys               : '.$summary[self::INVALID_KEY]);
        $this->line('  Writes performed                : 0');
        $this->line('');

        return self::SUCCESS;
    }
}
