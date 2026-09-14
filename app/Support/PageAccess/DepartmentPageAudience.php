<?php

namespace App\Support\PageAccess;

/**
 * Who, inside a department, a default page grant applies to.
 *
 * Leadership is ALWAYS resolved canonically from departments.leader_id via
 * DepartmentAccessService — never from a role slug and never from
 * team_profiles.is_leader (a synced display flag, see
 * DepartmentLeadershipService). This enum only labels stored default rows;
 * it never itself decides who is a leader.
 *
 * Semantics are ADDITIVE: an actual department leader receives the Member
 * defaults PLUS the Leader defaults. Leader defaults never replace Member
 * defaults — see DepartmentPageDefaultService::getEffectiveDepartmentDefaults().
 */
enum DepartmentPageAudience: string
{
    case Member = 'member';
    case Leader = 'leader';

    public function labelAr(): string
    {
        return match ($this) {
            self::Member => 'أعضاء الإدارة',
            self::Leader => 'قائد الإدارة',
        };
    }
}
