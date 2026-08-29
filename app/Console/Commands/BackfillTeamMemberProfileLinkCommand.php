<?php

namespace App\Console\Commands;

use App\Models\TeamMember;
use App\Models\TeamProfile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Stage 2 of the TeamProfile/TeamMember identity correction: link any
 * pre-existing team_members row (created before team_profile_id existed) to
 * its canonical TeamProfile, by matching user_id — safe now that
 * `team-profiles:audit-duplicates` has confirmed no user has more than one
 * TeamProfile in this environment.
 *
 * Dry-run by default (reports only, changes nothing). Pass --apply to write.
 * Idempotent: already-linked rows are reported and skipped; running it twice
 * with --apply is a no-op the second time.
 */
class BackfillTeamMemberProfileLinkCommand extends Command
{
    protected $signature   = 'team-members:backfill-profile-link {--apply : Actually write team_profile_id (default is a dry-run report only)}';
    protected $description = 'Link legacy team_members rows to their canonical team_profiles row via user_id. Reports orphans/conflicts instead of guessing.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'LIVE RUN — writing team_profile_id.' : 'DRY RUN — no changes will be written. Pass --apply to write.');
        $this->line('');

        $rows = TeamMember::whereNull('team_profile_id')->get();

        if ($rows->isEmpty()) {
            $this->info('No team_members rows need backfilling — team_profile_id is already set everywhere.');

            return self::SUCCESS;
        }

        $linked = 0;
        $conflicts = [];
        $orphans = [];

        DB::transaction(function () use ($rows, $apply, &$linked, &$conflicts, &$orphans) {
            foreach ($rows as $member) {
                $profile = TeamProfile::where('user_id', $member->user_id)->first();

                if (! $profile) {
                    $orphans[] = $member->id;

                    continue;
                }

                if ($profile->department_id != $member->department_id) {
                    $conflicts[] = [
                        'team_member_id' => $member->id,
                        'user_id' => $member->user_id,
                        'team_member_department_id' => $member->department_id,
                        'team_profile_id' => $profile->id,
                        'team_profile_department_id' => $profile->department_id,
                    ];

                    continue;
                }

                if ($apply) {
                    $member->update(['team_profile_id' => $profile->id]);
                }
                $linked++;
            }
        });

        $this->info("Linked (department matched): {$linked}");

        if ($conflicts !== []) {
            $this->warn(count($conflicts) . ' conflict(s) — TeamProfile exists but department differs. NOT linked:');
            foreach ($conflicts as $c) {
                $this->line("  team_members#{$c['team_member_id']} (user #{$c['user_id']}, dept #{$c['team_member_department_id']}) vs team_profiles#{$c['team_profile_id']} (dept #{$c['team_profile_department_id']})");
            }
        }

        if ($orphans !== []) {
            $this->warn(count($orphans) . ' orphan(s) — no TeamProfile found for this user_id at all:');
            $this->line('  team_members IDs: ' . implode(', ', $orphans));
        }

        if ($conflicts === [] && $orphans === []) {
            $this->info('All rows resolved cleanly — safe to proceed to the constraint migration.');
        } else {
            $this->error('Unresolved rows remain — DO NOT run the constraint-enforcing migration until these are manually reviewed and fixed (or the offending team_members rows are corrected/removed).');
        }

        if (! $apply) {
            $this->line('');
            $this->comment('This was a dry run. Re-run with --apply once you have reviewed the above.');
        }

        return self::SUCCESS;
    }
}
