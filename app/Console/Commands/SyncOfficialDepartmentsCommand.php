<?php

namespace App\Console\Commands;

use App\Models\Department;
use Database\Seeders\OfficialDepartmentsSeeder;
use Illuminate\Console\Command;

/**
 * Safely inserts any of the 13 canonical official departments
 * (OfficialDepartmentsSeeder::DEPARTMENTS) missing from the `departments`
 * table, WITHOUT touching any department that already exists — unlike
 * OfficialDepartmentsSeeder::run(), which uses updateOrCreate() and would
 * overwrite name/name_ar/name_en/color/icon/sort_order/status on every one
 * of the 13 rows on every run, even if an admin has since customized any of
 * them via the Department Management UI.
 *
 * This is the production-safe path for exactly the gap production
 * verification found: `ai-transformation` (إدارة الذكاء الاصطناعي والتحول
 * الرقمي) has no row at all. Re-running the full seeder to fix that risks
 * clobbering the other 12 departments' metadata; this command only ever
 * creates rows that don't exist yet, and never assigns leader_id — that
 * remains governed exclusively by DepartmentLeadershipService/
 * DepartmentAccessService, never inferred from a role.
 *
 * Dry-run by default. Idempotent and re-runnable: once every canonical
 * department exists, running this again (with or without --apply) reports
 * 0 missing and makes no changes.
 */
class SyncOfficialDepartmentsCommand extends Command
{
    protected $signature = 'departments:sync-official {--apply : Actually insert missing canonical official departments (default is dry-run, no writes)}';

    protected $description = 'Detect and (with --apply) insert canonical official departments missing from the departments table, without overwriting existing rows.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $existingSlugs = Department::query()->pluck('slug')->all();

        $missing = [];
        $ok = [];

        foreach (OfficialDepartmentsSeeder::DEPARTMENTS as $slug => [$nameAr, $nameEn, $color, $icon, $sort]) {
            if (in_array($slug, $existingSlugs, true)) {
                $ok[] = ['slug' => $slug, 'name_ar' => Department::query()->where('slug', $slug)->value('name_ar')];

                continue;
            }

            $row = [
                'slug'    => $slug,
                'name_ar' => $nameAr,
                'status'  => $apply ? null : 'WOULD_CREATE',
            ];

            if ($apply) {
                // leader_id intentionally omitted — stays NULL. Leadership is
                // assigned only through the normal Department Management /
                // Team Profile flow (DepartmentLeadershipService), never
                // inferred from a role slug here.
                Department::create([
                    'name'       => $nameAr,
                    'name_ar'    => $nameAr,
                    'name_en'    => $nameEn,
                    'slug'       => $slug,
                    'color'      => $color,
                    'icon'       => $icon,
                    'sort_order' => $sort,
                    'status'     => 'active',
                ]);
                $row['status'] = 'CREATED';
            }

            $missing[] = $row;
        }

        $this->line('');
        $this->info($apply ? 'Applying — inserting missing canonical official departments...' : 'Dry run — no database writes will be made. Pass --apply to insert missing departments.');
        $this->line('');

        if ($missing !== []) {
            $this->line(($apply ? 'Created' : 'Would create') . ' ' . count($missing) . ' department(s):');
            $this->table(['slug', 'name_ar', 'status'], $missing);
        } else {
            $this->info('No missing canonical official departments — nothing to create.');
        }

        $this->line('');
        $this->info(count($ok) . ' canonical department(s) already exist and were left unchanged.');

        return self::SUCCESS;
    }
}
