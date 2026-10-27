<?php

namespace App\Services;

use App\Models\DepartmentalUnit;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\VolunteerHrProfile;

/**
 * Keeps a volunteer's HR profile and the team record made from it saying the same thing.
 *
 * Approval (VolunteerApprovalService::approve) copies the HR profile into a `team_profiles` row once and links the two
 * (`volunteer_hr_profiles.team_profile_id`, and `team_profiles.source = 'volunteer_hr_profile'` + `source_id`). Before this
 * service, nothing kept them aligned afterwards: an edit on either side left the other stale.
 *
 * The fields below are the ones both records hold. The HR profile is their source of truth (it is the volunteer's own record,
 * reviewed by HR); an edit there is pushed to the linked team row, and an edit of the same field from Team Management is written
 * back so the two never contradict each other. Everything else stays where it belongs: team-only facts (visibility, leadership,
 * order, role key, membership) are never touched from the HR side, and HR-only facts (date of birth, education, emergency
 * contact, ...) never leave the HR profile. The user account (`users`) is not changed: its name, e-mail and phone are the
 * person's sign-in identity and are edited by the person or an administrator, not by an HR profile edit.
 *
 * A team row counts as linked only when BOTH sides point at each other; a team row merely sharing an e-mail or user is not.
 */
class VolunteerProfileSyncService
{
    /** HR profile column => team_profiles column */
    public const FIELD_MAP = [
        'full_name'     => 'name_ar',
        'job_title'     => 'position_ar',
        'department_id' => 'department_id',
        'phone'         => 'phone',
        'email'         => 'email',
        'country'       => 'country',
        'city'          => 'city',
        'gender'        => 'gender',
        'skills'        => 'skills',
        'join_date'     => 'joined_at',
    ];

    /** HR profile columns that are required there: an empty value from the team side never erases them. */
    private const HR_REQUIRED = ['full_name', 'job_title', 'department_id', 'phone', 'email', 'join_date'];

    /** The team row made from this HR profile, when the two are linked both ways. */
    public function linkedTeamProfile(VolunteerHrProfile $profile): ?TeamProfile
    {
        if ($profile->team_profile_id === null) {
            return null;
        }

        return TeamProfile::query()
            ->whereKey($profile->team_profile_id)
            ->where('source', 'volunteer_hr_profile')
            ->where('source_id', $profile->id)
            ->first();
    }

    /** The HR profile this team row was made from, when the two are linked both ways. */
    public function linkedHrProfile(TeamProfile $teamProfile): ?VolunteerHrProfile
    {
        if ($teamProfile->source !== 'volunteer_hr_profile' || $teamProfile->source_id === null) {
            return null;
        }

        return VolunteerHrProfile::query()
            ->whereKey($teamProfile->source_id)
            ->where('team_profile_id', $teamProfile->id)
            ->first();
    }

    /**
     * After an HR edit: copy the changed shared fields to the linked team row (and its operational membership).
     *
     * @param  list<string>  $changedHrFields  HR profile columns that changed
     * @return list<string> team_profiles columns that were updated
     */
    public function pushToTeam(VolunteerHrProfile $profile, array $changedHrFields): array
    {
        $team = $this->linkedTeamProfile($profile);
        if ($team === null) {
            return [];
        }

        $updates = [];
        foreach (array_intersect_key(self::FIELD_MAP, array_flip($changedHrFields)) as $hrField => $teamField) {
            $updates[$teamField] = $profile->getAttribute($hrField);
        }
        if ($updates === []) {
            return [];
        }

        $departmentChanging = array_key_exists('department_id', $updates) && (int) $updates['department_id'] !== (int) $team->department_id;
        $positionChanging = array_key_exists('position_ar', $updates) && $updates['position_ar'] !== $team->position_ar;

        $team->fill($updates);
        $changed = array_keys($team->getDirty());
        $team->save();

        self::followOperationalMember($team, $departmentChanging, $positionChanging);

        return $changed;
    }

    /**
     * After a Team Management edit of a volunteer-sourced row: write the shared fields that changed back to the HR profile.
     *
     * @param  list<string>  $changedTeamFields  team_profiles columns that changed
     * @return list<string> HR profile columns that were updated
     */
    public function pullFromTeam(TeamProfile $teamProfile, array $changedTeamFields): array
    {
        $profile = $this->linkedHrProfile($teamProfile);
        if ($profile === null) {
            return [];
        }

        $updates = [];
        foreach (array_intersect(self::FIELD_MAP, $changedTeamFields) as $hrField => $teamField) {
            $value = $teamProfile->getAttribute($teamField);
            if (($value === null || $value === '') && in_array($hrField, self::HR_REQUIRED, true)) {
                continue;
            }
            $updates[$hrField] = $hrField === 'join_date' && $value !== null ? $teamProfile->joined_at?->toDateString() : $value;
        }
        if (array_key_exists('email', $updates)) {
            $updates['normalized_email'] = VolunteerHrProfile::normalizeEmail((string) $updates['email']);
        }
        if ($updates === []) {
            return [];
        }

        $profile->fill($updates);
        $changed = array_values(array_diff(array_keys($profile->getDirty()), ['normalized_email']));
        $profile->save();

        return $changed;
    }

    /**
     * Keep the operational `team_members` row (if any) in step with its canonical team profile: department_id and role_title are
     * still duplicated there (Stage 1 of the non-destructive migration). A unit of the old department cannot be kept.
     * Moved here from AdminTeamProfileController::update so the team page and the HR edit apply the same rule.
     */
    public static function followOperationalMember(TeamProfile $teamProfile, bool $departmentChanging, bool $positionChanging): void
    {
        if (! $departmentChanging && ! $positionChanging) {
            return;
        }
        $member = TeamMember::where('team_profile_id', $teamProfile->id)->first();
        if (! $member) {
            return;
        }

        $updates = [];
        if ($departmentChanging) {
            $updates['department_id'] = $teamProfile->department_id;
            if (
                $member->unit_id && (
                    ! $teamProfile->department_id
                    || ! DepartmentalUnit::where('id', $member->unit_id)->where('department_id', $teamProfile->department_id)->exists()
                )
            ) {
                $updates['unit_id'] = null;
            }
        }
        if ($positionChanging) {
            $updates['role_title'] = $teamProfile->position_ar;
        }
        $member->update($updates);
    }
}
