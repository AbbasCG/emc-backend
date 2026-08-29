<?php

namespace App\Console\Commands;

use App\Models\TeamProfile;
use Illuminate\Console\Command;

/**
 * Read-only report for the "one User = one TeamProfile" business rule
 * correction (see the architecture audit). Never modifies data — this is
 * strictly a diagnostic to inform a manual reconciliation decision before
 * any UNIQUE constraint on team_profiles.user_id can safely be added.
 */
class AuditDuplicateTeamProfilesCommand extends Command
{
    protected $signature   = 'team-profiles:audit-duplicates';
    protected $description = 'Report users with more than one TeamProfile row (read-only — makes no changes).';

    public function handle(): int
    {
        $duplicateUserIds = TeamProfile::query()
            ->whereNotNull('user_id')
            ->selectRaw('user_id, COUNT(*) as profile_count')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('user_id');

        if ($duplicateUserIds->isEmpty()) {
            $this->info('No users with multiple TeamProfile rows found. Safe to consider adding a UNIQUE constraint on team_profiles.user_id.');

            return self::SUCCESS;
        }

        $this->error("{$duplicateUserIds->count()} user(s) have more than one TeamProfile row:");
        $this->line('');

        foreach ($duplicateUserIds as $userId) {
            $profiles = TeamProfile::where('user_id', $userId)
                ->with(['department:id,name_ar', 'teamMember:id,team_profile_id,unit_id,status'])
                ->get();

            $this->line("User #{$userId} — {$profiles->count()} profiles:");
            foreach ($profiles as $p) {
                $this->line(sprintf(
                    '  profile #%d | name_ar=%s | department=%s | position_ar=%s | linked TeamMember=%s',
                    $p->id,
                    $p->name_ar,
                    $p->department?->name_ar ?? '(none)',
                    $p->position_ar,
                    $p->teamMember ? "#{$p->teamMember->id} (unit_id={$p->teamMember->unit_id}, status={$p->teamMember->status})" : '(none)',
                ));
            }
            $this->line('');
        }

        $this->warn('No data was changed. Resolve each of the above manually (pick the correct canonical profile per user, then reassign/delete the others) before adding a UNIQUE constraint on team_profiles.user_id.');

        return self::SUCCESS;
    }
}
