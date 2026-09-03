<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\TeamProfile;
use App\Services\DepartmentLeadershipService;
use Illuminate\Console\Command;

/**
 * Repairs the exact production bug audited earlier: team_profiles.is_leader
 * (the "قائد" badge the member-management UI shows) can drift away from
 * departments.leader_id (the ONLY column DepartmentAccessService actually
 * reads for Weekly/Meeting Report creation rights) whenever a leadership
 * flag was ever written outside the now-fixed admin write paths — most
 * commonly by a seeder using TeamProfile::updateOrCreate() directly.
 *
 * Dry-run by default. Only repairs UNAMBIGUOUS cases with --apply: exactly
 * one active, user-linked, is_leader=true TeamProfile per department, whose
 * linked User actually exists. Everything else is reported for manual
 * review — this command never guesses.
 */
class RepairDepartmentLeadershipCommand extends Command
{
    protected $signature = 'departments:repair-leadership {--apply : Actually write the unambiguous repairs (default is dry-run, no writes)}';

    protected $description = 'Detect and (with --apply) repair departments.leader_id mismatches against team_profiles.is_leader.';

    public function handle(DepartmentLeadershipService $leadership): int
    {
        $apply = (bool) $this->option('apply');

        $departments = Department::query()->orderBy('sort_order')->get(['id', 'name', 'name_ar', 'leader_id']);

        $repaired = [];
        $ok = [];
        $manualReview = [];

        foreach ($departments as $department) {
            $leaderProfiles = TeamProfile::query()
                ->where('department_id', $department->id)
                ->where('is_leader', true)
                ->where('is_active', true)
                ->get(['id', 'user_id', 'name_ar']);

            if ($leaderProfiles->isEmpty()) {
                // No is_leader signal at all for this department — leader_id
                // (if set) may legitimately have come from the Department
                // settings screen instead. Nothing to compare against, so
                // this command makes no claim about it either way.
                continue;
            }

            $withValidUser = $leaderProfiles->filter(fn (TeamProfile $p) => $p->user_id !== null);
            $invalidUserProfiles = $leaderProfiles->filter(fn (TeamProfile $p) => $p->user_id === null);

            if ($leaderProfiles->count() > 1 || $invalidUserProfiles->isNotEmpty()) {
                $manualReview[] = [
                    'department_id'   => $department->id,
                    'department'      => $department->name_ar ?: $department->name,
                    'current_leader_id' => $department->leader_id,
                    'conflict_type'   => $leaderProfiles->count() > 1
                        ? 'multiple is_leader=true profiles (' . $leaderProfiles->count() . ')'
                        : 'is_leader=true profile has no linked user_id',
                    'candidates'      => $leaderProfiles->map(fn (TeamProfile $p) => "TeamProfile#{$p->id} ({$p->name_ar}) user_id=" . ($p->user_id ?? 'NULL'))->implode('; '),
                ];

                continue;
            }

            // Exactly one candidate with a user_id — verify the user actually exists.
            $profile = $withValidUser->first();
            $userExists = \App\Models\User::query()->whereKey($profile->user_id)->exists();

            if (! $userExists) {
                $manualReview[] = [
                    'department_id'   => $department->id,
                    'department'      => $department->name_ar ?: $department->name,
                    'current_leader_id' => $department->leader_id,
                    'conflict_type'   => "TeamProfile#{$profile->id} user_id={$profile->user_id} does not reference an existing user",
                    'candidates'      => "TeamProfile#{$profile->id} ({$profile->name_ar})",
                ];

                continue;
            }

            if ($department->leader_id === $profile->user_id) {
                $ok[] = [
                    'department_id' => $department->id,
                    'department'    => $department->name_ar ?: $department->name,
                    'leader_id'     => $department->leader_id,
                ];

                continue;
            }

            $row = [
                'department_id'       => $department->id,
                'department'          => $department->name_ar ?: $department->name,
                'current_leader_id'   => $department->leader_id ?? 'NULL',
                'expected_leader_id'  => $profile->user_id,
                'team_profile_id'     => $profile->id,
                'user_name'           => $profile->name_ar,
                'status'              => $apply ? null : 'WOULD_REPAIR',
            ];

            if ($apply) {
                $leadership->syncFromTeamProfile($profile->fresh());
                $row['status'] = 'REPAIRED';
            }

            $repaired[] = $row;
        }

        $this->line('');
        $this->info($apply ? 'Applying unambiguous leadership repairs...' : 'Dry run — no database writes will be made. Pass --apply to repair unambiguous cases.');
        $this->line('');

        if ($repaired !== []) {
            $this->line(($apply ? 'Repaired' : 'Would repair') . ' ' . count($repaired) . ' department(s):');
            $this->table(
                ['department_id', 'department', 'current_leader_id', 'expected_leader_id', 'team_profile_id', 'user_name', 'status'],
                $repaired,
            );
        } else {
            $this->info('No unambiguous mismatches found.');
        }

        $this->line('');

        if ($manualReview !== []) {
            $this->error(count($manualReview) . ' department(s) require MANUAL REVIEW — not touched:');
            $this->table(
                ['department_id', 'department', 'current_leader_id', 'conflict_type', 'candidates'],
                $manualReview,
            );
        } else {
            $this->info('No ambiguous cases found.');
        }

        $this->line('');
        $this->info(count($ok) . ' department(s) already consistent — no action needed.');

        return self::SUCCESS;
    }
}
