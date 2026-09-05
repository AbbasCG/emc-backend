<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\DepartmentalUnit;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Services\DepartmentLeadershipService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off canonical correction for the exact production gap verified for
 * إدارة الذكاء الاصطناعي والتحول الرقمي (ai-transformation, Department #13):
 * TeamProfile #38 (user #2078, م/ايمان السماوي) is currently placed under
 * Department #2 (programs-tracks) with is_leader=false, and no department
 * has leader_id=2078.
 *
 * This performs the SAME writes AdminTeamProfileController::update() would
 * for a PATCH with {department_id: 13, is_leader: true} — TeamProfile
 * update -> DepartmentLeadershipService::syncFromTeamProfile() (the only
 * writer of departments.leader_id) -> linked TeamMember.department_id sync
 * — rather than duplicating or bypassing that logic. leader_id is never
 * written directly and is never inferred from the ai_manager role.
 *
 * Hardcoded to this one record on purpose (not a general-purpose mover):
 * guards abort with no writes if TeamProfile #38 isn't linked to user #2078
 * or if Department #13 isn't ai-transformation, so it can never silently
 * apply to the wrong row if IDs have shifted since this was written.
 *
 * Dry-run by default. Idempotent — re-running after a successful --apply
 * (or if the record was already corrected some other way) reports "already
 * correct" and makes no writes.
 */
class AssignAiTransformationLeaderCommand extends Command
{
    private const TEAM_PROFILE_ID = 38;
    private const TARGET_DEPARTMENT_ID = 13;
    private const TARGET_DEPARTMENT_SLUG = 'ai-transformation';
    private const EXPECTED_USER_ID = 2078;

    protected $signature = 'team-profiles:assign-ai-transformation-leader {--apply : Actually perform the move (default is dry-run, no writes)}';

    protected $description = 'One-off: move TeamProfile #38 (user #2078) into Department #13 (ai-transformation) and assign department leadership via the canonical write path.';

    public function handle(DepartmentLeadershipService $leadership): int
    {
        $apply = (bool) $this->option('apply');

        $profile = TeamProfile::find(self::TEAM_PROFILE_ID);
        if (! $profile) {
            $this->error('TeamProfile #'.self::TEAM_PROFILE_ID.' not found — aborting, no changes made.');

            return self::FAILURE;
        }

        if ($profile->user_id !== self::EXPECTED_USER_ID) {
            $this->error(
                'TeamProfile #'.self::TEAM_PROFILE_ID." has user_id={$profile->user_id}, expected ".self::EXPECTED_USER_ID.
                ' — aborting, no changes made (safety guard against acting on the wrong record).'
            );

            return self::FAILURE;
        }

        $targetDept = Department::find(self::TARGET_DEPARTMENT_ID);
        if (! $targetDept || $targetDept->slug !== self::TARGET_DEPARTMENT_SLUG) {
            $this->error(
                'Department #'.self::TARGET_DEPARTMENT_ID." is missing or is not slug=".self::TARGET_DEPARTMENT_SLUG.
                ' — aborting, no changes made.'
            );

            return self::FAILURE;
        }

        $alreadyCorrect = (int) $profile->department_id === self::TARGET_DEPARTMENT_ID
            && $profile->is_leader === true
            && (int) $targetDept->leader_id === self::EXPECTED_USER_ID;

        $this->line('');
        $this->table(['field', 'current', 'target'], [
            ['TeamProfile#'.self::TEAM_PROFILE_ID.'.department_id', $profile->department_id ?? 'NULL', self::TARGET_DEPARTMENT_ID],
            ['TeamProfile#'.self::TEAM_PROFILE_ID.'.is_leader', $profile->is_leader ? 'true' : 'false', 'true'],
            ['Department#'.self::TARGET_DEPARTMENT_ID.'.leader_id', $targetDept->leader_id ?? 'NULL', self::EXPECTED_USER_ID],
        ]);

        $member = TeamMember::where('team_profile_id', $profile->id)->first();
        $this->line('');
        $this->info($member
            ? "Linked TeamMember found: #{$member->id} (department_id={$member->department_id}, unit_id=".($member->unit_id ?? 'NULL').') — its department_id will be synced too.'
            : 'No linked TeamMember row for this TeamProfile — nothing else to sync.');
        $this->line('');

        if ($alreadyCorrect) {
            $this->info('Already correct — nothing to do.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->info('Dry run — no writes made. Pass --apply to perform this move.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($profile, $member, $leadership) {
            $profile->update(['department_id' => self::TARGET_DEPARTMENT_ID, 'is_leader' => true]);
            $leadership->syncFromTeamProfile($profile->fresh());

            if ($member) {
                $updates = ['department_id' => self::TARGET_DEPARTMENT_ID];

                if (
                    $member->unit_id
                    && ! DepartmentalUnit::where('id', $member->unit_id)
                        ->where('department_id', self::TARGET_DEPARTMENT_ID)
                        ->exists()
                ) {
                    $updates['unit_id'] = null;
                }

                $member->update($updates);
            }
        });

        $this->info('Applied.');

        return self::SUCCESS;
    }
}
