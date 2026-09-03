<?php

namespace App\Services;

use App\Models\Department;
use App\Models\TeamProfile;
use Illuminate\Support\Facades\DB;

/**
 * SINGLE reusable synchronizer for the "قائد" display flag
 * (team_profiles.is_leader) and the canonical authorization relation
 * (departments.leader_id → users.id) that DepartmentAccessService actually
 * reads for Weekly/Meeting Report creation rights. Every real write path
 * that can change department leadership — the Team Profile screen, the
 * Department settings screen, or any future one — must call this service
 * instead of writing either column directly, so the two can never drift
 * apart again the way they did for the "قائد badge shown but report
 * creation denied" bug.
 *
 * departments.leader_id remains the sole authorization signal; is_leader
 * is kept consistent with it, never the other way around as a second
 * authority.
 */
class DepartmentLeadershipService
{
    /**
     * Call after a TeamProfile's is_leader flag changes (or is set on
     * create). Keeps departments.leader_id and any other profile in the
     * same department's is_leader flag consistent with this one profile.
     */
    public function syncFromTeamProfile(TeamProfile $profile): void
    {
        if (! $profile->department_id) {
            return;
        }

        DB::transaction(function () use ($profile) {
            $department = Department::query()->lockForUpdate()->find($profile->department_id);
            if (! $department) {
                return;
            }

            if ($profile->is_leader && $profile->user_id) {
                // Exactly one قائد per department: demote any other profile in
                // this department currently flagged is_leader, so the badge
                // and the canonical leader_id never disagree about who leads.
                TeamProfile::where('department_id', $profile->department_id)
                    ->where('id', '!=', $profile->id)
                    ->where('is_leader', true)
                    ->update(['is_leader' => false]);

                if ($department->leader_id !== $profile->user_id) {
                    $department->update(['leader_id' => $profile->user_id]);
                }
            } elseif (! $profile->is_leader && $profile->user_id && $department->leader_id === $profile->user_id) {
                // This profile was explicitly un-flagged as قائد and it was the
                // recorded leader — it loses leadership authority immediately.
                // Historical reports already submitted keep their original
                // submitted_by/submitter attribution untouched — leadership is
                // only checked at creation time, never rewritten onto past rows.
                $department->update(['leader_id' => null]);
            }
        });
    }

    /**
     * Call after departments.leader_id is set directly (e.g. the Department
     * settings screen, distinct from the Team Profile "قائد" toggle). Keeps
     * every TeamProfile in this department's is_leader flag consistent with
     * the new leader_id, in both directions (promote the matching profile if
     * one exists, demote any other currently-flagged profile).
     */
    public function syncFromDepartmentLeaderId(Department $department, ?int $newLeaderId): void
    {
        DB::transaction(function () use ($department, $newLeaderId) {
            $department = Department::query()->lockForUpdate()->find($department->id);
            if (! $department) {
                return;
            }

            TeamProfile::where('department_id', $department->id)
                ->where('is_leader', true)
                ->when($newLeaderId, fn ($q) => $q->where('user_id', '!=', $newLeaderId))
                ->update(['is_leader' => false]);

            if ($newLeaderId) {
                TeamProfile::where('department_id', $department->id)
                    ->where('user_id', $newLeaderId)
                    ->update(['is_leader' => true]);
            }
        });
    }
}
