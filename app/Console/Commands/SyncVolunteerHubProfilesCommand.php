<?php

namespace App\Console\Commands;

use App\Services\VolunteerHubSyncService;
use Illuminate\Console\Command;

/**
 * Backfill for the Operations Volunteer Hub: creates the missing `volunteer_profiles` rows (and fills a missing department) for every
 * volunteer — role `volunteer`, volunteer team profiles that have an account, and converted volunteer requests.
 *
 * Idempotent: a second run reports 0. Use --dry-run to see the counts without writing anything. The same logic runs on every hub load,
 * so this command is only needed to backfill production once (or to inspect the numbers).
 */
class SyncVolunteerHubProfilesCommand extends Command
{
    protected $signature   = 'volunteers:sync-hub-profiles {--dry-run : Only print what would be created, write nothing}';
    protected $description = 'Create the missing Volunteer Hub profiles (volunteer_profiles) for every volunteer account.';

    public function handle(VolunteerHubSyncService $sync): int
    {
        $dry = (bool) $this->option('dry-run');
        $this->info($dry ? 'DRY RUN — nothing will be written.' : 'Syncing volunteer hub profiles...');

        $r = $sync->ensureProfiles($dry);

        if ($r['skipped_locked']) {
            $this->warn('Another sync is running right now (lock held). Nothing was done; run it again in a minute.');

            return self::SUCCESS;
        }

        $verb = $dry ? 'would be created' : 'created';
        $this->table(['Item', 'Count'], [
            ["Profiles {$verb} (total)", $r['created']],
            ['  from users with role volunteer', $r['from_role']],
            ['  from volunteer team profiles with an account', $r['from_team_profile']],
            ['  from converted volunteer requests', $r['from_converted_request']],
            [$dry ? 'Departments that would be filled on existing profiles' : 'Departments filled on existing profiles', $r['departments_backfilled']],
            ['Active volunteer team profiles WITHOUT an account (cannot hold points)', $sync->withoutAccountCount()],
        ]);

        return self::SUCCESS;
    }
}
