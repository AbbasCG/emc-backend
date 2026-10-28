<?php

namespace App\Services;

use App\Models\Department;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use App\Models\VolunteerHrProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VolunteerApprovalService
{
    /** Thrown-as-array duplicate check result, or null when clear to proceed. */
    public function findDuplicate(string $email, ?int $userId, ?int $excludeProfileId = null): ?array
    {
        $normalized = VolunteerHrProfile::normalizeEmail($email);

        // 1) Already an approved/linked volunteer (has a synced team profile).
        $approved = VolunteerHrProfile::where('normalized_email', $normalized)
            ->where('status', 'approved')
            ->when($excludeProfileId, fn ($q) => $q->where('id', '!=', $excludeProfileId))
            ->first();
        if ($approved) {
            Log::info('volunteer_hr_profile.duplicate_check', [
                'duplicate_reason' => 'already_volunteer',
                'user_id'          => $userId,
                'matched_profile_id' => $approved->id,
            ]);

            return [
                'code'    => 'already_volunteer',
                'message' => 'يوجد بالفعل سجل لهذا المتطوع في النظام',
                'profile' => $approved,
            ];
        }

        // 2) An open (pending) application already exists for this email.
        $pending = VolunteerHrProfile::where('normalized_email', $normalized)
            ->whereIn('status', VolunteerHrProfile::OPEN_STATUSES)
            ->when($excludeProfileId, fn ($q) => $q->where('id', '!=', $excludeProfileId))
            ->first();
        if ($pending) {
            Log::info('volunteer_hr_profile.duplicate_check', [
                'duplicate_reason' => 'pending_exists',
                'user_id'          => $userId,
                'matched_profile_id' => $pending->id,
            ]);

            return [
                'code'    => 'pending_exists',
                'message' => 'يوجد طلب سابق قيد المراجعة لهذا البريد الإلكتروني',
                'profile' => $pending,
            ];
        }

        // 3) Linked user is already an active team member (defensive — should
        // already be caught by #1, but a member can also be added manually,
        // e.g. directly through admin Team CRUD, not via this approval flow).
        // Uses TeamProfile::scopeActive() (is_active=true) rather than mere row
        // existence — `is_active` on team_profiles is this app's only signal
        // for "currently a member" (the table has no soft-deletes; removal is
        // a hard delete via AdminTeamProfileController::destroy()), so an
        // inactive/hidden row must NOT block a fresh submission.
        if ($userId) {
            $existingMember = TeamProfile::active()->where('user_id', $userId)->first();
            if ($existingMember) {
                Log::info('volunteer_hr_profile.duplicate_check', [
                    'duplicate_reason'  => 'already_member',
                    'user_id'           => $userId,
                    'matched_member_id' => $existingMember->id,
                    'member_type'       => $existingMember->member_type,
                    'source'            => $existingMember->source,
                ]);

                return [
                    'code'    => 'already_member',
                    'message' => 'هذا المتطوع موجود بالفعل ضمن أعضاء الفريق',
                    'profile' => null,
                ];
            }
        }

        return null;
    }

    /**
     * @return array{profile: VolunteerHrProfile, team_profile: TeamProfile}
     */
    public function approve(VolunteerHrProfile $profile, User $reviewer, ?int $departmentId = null): array
    {
        return DB::transaction(function () use ($profile, $reviewer, $departmentId) {
            /** @var VolunteerHrProfile $locked */
            $locked = VolunteerHrProfile::whereKey($profile->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'approved') {
                // Idempotent: re-approving an already-approved profile just
                // returns the existing synced team profile, no double-write.
                return ['profile' => $locked, 'team_profile' => $locked->teamProfile];
            }

            $duplicate = $this->findDuplicate($locked->email, $locked->user_id, $locked->id);
            if ($duplicate && $duplicate['code'] !== 'pending_exists') {
                // 'pending_exists' would match itself pre-lock in edge cases; any
                // other duplicate code means someone else already holds this slot.
                throw new \RuntimeException($duplicate['message']);
            }

            $department = $departmentId ? Department::find($departmentId) : $locked->department;

            // Resolve or link a user account by normalized email — never create
            // an account with an insecure password here; if none exists the
            // team profile stays unlinked (same convention as the existing
            // VolunteerRequest::convertToMember flow), and HR can link/create
            // an account afterward from the Team page.
            $user = $locked->user ?? User::whereRaw('LOWER(email) = ?', [$locked->normalized_email])->first();

            $maxSort = $department
                ? (TeamProfile::where('department_id', $department->id)->max('sort_order') ?? 0)
                : (TeamProfile::whereNull('department_id')->max('sort_order') ?? 0);

            $teamProfileAttrs = [
                'department_id' => $department?->id,
                'user_id'       => $user?->id,
                'name_ar'       => $locked->full_name,
                'position_ar'   => $locked->job_title,
                'role_key'      => 'volunteer',
                'phone'         => $locked->phone,
                'email'         => $locked->email,
                'country'       => $locked->country,
                'city'          => $locked->city,
                'gender'        => $locked->gender,
                'skills'        => $locked->skills,
                'cv_file_path'  => $locked->cv_path,
                // Photo only propagates to the public-facing TeamProfile row when
                // the volunteer explicitly consented — otherwise TeamMemberResource
                // has nothing to show and falls back to initials, regardless of
                // whatever was uploaded/stored on the private volunteer record.
                'image'         => $locked->photo_publication_consent === true ? $locked->profile_photo_path : null,
                'is_active'     => true,
                'member_type'   => 'volunteer',
                'source'        => 'volunteer_hr_profile',
                'source_id'     => $locked->id,
                'joined_at'     => $locked->join_date,
                'photo_publication_consent'    => $locked->photo_publication_consent,
                'professional_profile_consent' => $locked->professional_profile_consent,
            ];

            // Reuse the existing synced row if this profile was already linked
            // to one (e.g. a resubmission after rejection was re-approved) —
            // never insert a second team_profiles row for the same person.
            if ($locked->team_profile_id) {
                $teamProfile = TeamProfile::findOrFail($locked->team_profile_id);
                $teamProfile->update($teamProfileAttrs);
            } elseif ($user) {
                // A user-linked profile is unique per user_id in team_profiles
                // convention-wise (not DB-enforced) — check before inserting.
                $teamProfile = TeamProfile::where('user_id', $user->id)->first();
                if ($teamProfile) {
                    $teamProfile->update($teamProfileAttrs);
                } else {
                    $teamProfile = TeamProfile::create(array_merge($teamProfileAttrs, [
                        'sort_order'    => $maxSort + 1,
                        'is_leader'     => false,
                        'is_executive'  => false,
                        'is_new_member' => true,
                    ]));
                }
            } else {
                $teamProfile = TeamProfile::create(array_merge($teamProfileAttrs, [
                    'sort_order'    => $maxSort + 1,
                    'is_leader'     => false,
                    'is_executive'  => false,
                    'is_new_member' => true,
                ]));
            }

            $locked->update([
                'status'          => 'approved',
                'department_id'   => $department?->id,
                'user_id'         => $user?->id ?? $locked->user_id,
                'reviewed_by'     => $reviewer->id,
                'reviewed_at'     => now(),
                'approved_by'     => $reviewer->id,
                'approved_at'     => now(),
                'team_profile_id' => $teamProfile->id,
                'rejection_reason' => null,
            ]);

            // Automatic role / membership changes need an explicit business decision (config/volunteer_onboarding.php);
            // with the switches off, approval only records the approval and the team profile, as before.
            $targetUser = $user ?? ($locked->user_id ? User::find($locked->user_id) : null);
            if ($targetUser && $department) {
                if (config('volunteer_onboarding.approval_assigns_department_member_role')
                    && in_array($targetUser->role, ['student', 'volunteer'], true)) {
                    $targetUser->update(['role' => 'department_member']);
                }
                if (config('volunteer_onboarding.approval_links_department_membership')) {
                    $targetUser->departments()->syncWithoutDetaching([$department->id => ['is_primary' => true]]);
                    TeamMember::updateOrCreate(
                        ['team_profile_id' => $teamProfile->id],
                        [
                            'user_id'       => $targetUser->id,
                            'department_id' => $department->id,
                            'section_id'    => null, // unassigned, waiting for manager to seat them
                            'role_title'    => $locked->job_title ?: 'عضو إدارة',
                            'status'        => 'active',
                            'joined_at'     => now(),
                        ]
                    );
                }
            }

            return ['profile' => $locked->fresh(), 'team_profile' => $teamProfile];
        });
    }

    public function reject(VolunteerHrProfile $profile, User $reviewer, string $reason): VolunteerHrProfile
    {
        $profile->update([
            'status'            => 'rejected',
            'reviewed_by'       => $reviewer->id,
            'reviewed_at'       => now(),
            'rejection_reason'  => $reason,
        ]);

        return $profile->fresh();
    }
}
