<?php

namespace App\Services;

use App\Models\TeamProfile;
use App\Models\VolunteerProfile;
use App\Models\VolunteerRequest;

/**
 * Finds where a hub volunteer came from: the original public application and the team profile created for it.
 *
 * Request lookup, stopping at the first hit:
 *   1. the account's team profile with source = 'volunteer_request'  ->  volunteer_requests.id = team_profiles.source_id
 *   2. volunteer_requests.converted_member_id = that team profile id
 *   3. the account e-mail, case-insensitive, among requests that HR already decided on (status accepted or converted_to_member) only:
 *      a pending / rejected / unreviewed request is never attached, because anybody can submit the public form with someone else's
 *      address. A request already converted to ANOTHER team profile (converted_member_id set and not one of this account's profiles)
 *      is never linked. A converted request first, then an accepted one, newest first. Not gated on e-mail verification: accounts made
 *      by the conversion flow have it NULL and are linked by steps 1 and 2 anyway.
 *
 * Steps 1 and 2 follow volunteer_profiles.user_id, so they still work when the user row is soft-deleted (the volunteer keeps its linked
 * request and team profile). Step 3 needs the account's e-mail, so a volunteer whose user is gone is never matched by e-mail.
 */
class VolunteerSourceResolver
{
    /**
     * @return array{request: ?VolunteerRequest, team_profile: ?TeamProfile, source: string, match: ?string} source is volunteer_request | team_profile | account_only; match (how the request was linked, null without a request) is team_source | converted_member | email
     */
    public function resolve(VolunteerProfile $volunteer): array
    {
        $userId = $volunteer->user_id ? (int) $volunteer->user_id : null;
        $user   = $userId ? $volunteer->user : null;

        $requestProfile = $userId
            ? TeamProfile::query()->where('user_id', $userId)->where('source', 'volunteer_request')->orderByDesc('id')->first()
            : null;

        $request = null;
        $match   = null;

        if ($requestProfile?->source_id) {
            $request = VolunteerRequest::query()->whereKey($requestProfile->source_id)->first();
            $match   = $request ? 'team_source' : null;
        }

        if ($request === null && $requestProfile !== null) {
            $request = VolunteerRequest::query()->where('converted_member_id', $requestProfile->id)->orderByDesc('id')->first();
            $match   = $request ? 'converted_member' : null;
        }

        if ($request === null && $user !== null && trim((string) $user->email) !== '') {
            $ownProfileIds = TeamProfile::query()->where('user_id', $userId)->pluck('id')->all();

            $request = VolunteerRequest::query()
                ->whereIn('status', ['accepted', 'converted_to_member'])
                ->where(function ($q) use ($ownProfileIds) {
                    $q->whereNull('converted_member_id')->orWhereIn('converted_member_id', $ownProfileIds);
                })
                ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $user->email))])
                ->orderByRaw("CASE status WHEN 'converted_to_member' THEN 0 WHEN 'accepted' THEN 1 ELSE 2 END")
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();
            $match = $request ? 'email' : null;
        }

        // The team profile shown: the one made from the request, otherwise any the account has (a volunteer one, an active one first).
        $teamProfile = $requestProfile ?? ($userId
            ? TeamProfile::query()
                ->where('user_id', $userId)
                ->orderByRaw("CASE WHEN member_type = 'volunteer' THEN 0 ELSE 1 END")
                ->orderByDesc('is_active')
                ->orderBy('id')
                ->first()
            : null);

        return [
            'request'      => $request,
            'team_profile' => $teamProfile,
            'source'       => $request ? 'volunteer_request' : ($teamProfile ? 'team_profile' : 'account_only'),
            'match'        => $request ? $match : null,
        ];
    }
}
