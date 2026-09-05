<?php

namespace App\Console\Commands;

use App\Models\Role;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;

/**
 * Safely inserts any canonical system role (RoleSeeder::ROLES) that is
 * missing from the `roles` table, WITHOUT touching any role that already
 * exists — unlike RoleSeeder::run(), which uses updateOrCreate() and would
 * overwrite display_name/description on an existing row even if an admin
 * has since customized it via RoleController.
 *
 * This is the production-safe path for the exact class of bug that caused
 * PUT /api/admin/users/{id} to 422 on ai_manager: the role was fully wired
 * everywhere (route middleware, frontend) but had simply never been
 * inserted into this environment's `roles` table. Re-running the full
 * RoleSeeder to fix that risks clobbering customized metadata on the other
 * 19 rows; this command only ever creates rows that don't exist yet.
 *
 * Dry-run by default. Idempotent and re-runnable: once every canonical
 * role exists, running this again (with or without --apply) reports 0
 * missing roles and makes no changes.
 */
class SyncSystemRolesCommand extends Command
{
    protected $signature = 'roles:sync-system {--apply : Actually insert missing canonical system roles (default is dry-run, no writes)}';

    protected $description = 'Detect and (with --apply) insert canonical system roles missing from the roles table, without overwriting existing rows.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $existingNames = Role::query()->pluck('name')->all();

        $missing = [];
        $ok = [];
        $incomplete = [];

        foreach (RoleSeeder::ROLES as $roleData) {
            if (in_array($roleData['name'], $existingNames, true)) {
                $existing = Role::query()->where('name', $roleData['name'])->first();

                if (blank($existing->display_name)) {
                    // Existing row, but missing a required structural field —
                    // report for manual repair rather than silently filling it
                    // in (that would be an unrequested overwrite of a row that
                    // already exists).
                    $incomplete[] = [
                        'name'   => $roleData['name'],
                        'issue'  => 'existing row has an empty display_name',
                    ];
                } else {
                    $ok[] = ['name' => $roleData['name'], 'display_name' => $existing->display_name];
                }

                continue;
            }

            $row = [
                'name'         => $roleData['name'],
                'display_name' => $roleData['display_name'],
                'status'       => $apply ? null : 'WOULD_CREATE',
            ];

            if ($apply) {
                Role::create($roleData);
                $row['status'] = 'CREATED';
            }

            $missing[] = $row;
        }

        $this->line('');
        $this->info($apply ? 'Applying — inserting missing canonical system roles...' : 'Dry run — no database writes will be made. Pass --apply to insert missing roles.');
        $this->line('');

        if ($missing !== []) {
            $this->line(($apply ? 'Created' : 'Would create') . ' ' . count($missing) . ' role(s):');
            $this->table(['name', 'display_name', 'status'], $missing);
        } else {
            $this->info('No missing canonical system roles — nothing to create.');
        }

        $this->line('');

        if ($incomplete !== []) {
            $this->error(count($incomplete) . ' existing role(s) need MANUAL REPAIR — not touched:');
            $this->table(['name', 'issue'], $incomplete);
        }

        $this->line('');
        $this->info(count($ok) . ' canonical role(s) already exist and were left unchanged.');

        return self::SUCCESS;
    }
}
