<?php

namespace App\Support\PageAccess;

/**
 * Phase 2D — WHICH LAYER DECIDED a page's effective access.
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION. A source explains why a dashboard
 * page is (or is not) exposed to a user. It never explains, grants or proves
 * the right to perform an action on that page.
 *
 * The cases double as the deterministic precedence ladder used by
 * EffectivePageAccessService: exactly one source is the `primary_source` of a
 * result, and it is always the highest-precedence source that applies.
 */
enum PageAccessSource: string
{
    /**
     * Canonical unrestricted platform authority (super_admin / tech_admin).
     * Outranks everything, including SYSTEM_PROTECTED, and is never stored in
     * or mutable through any page-access table.
     */
    case RootAuthority = 'root_authority';

    /** SYSTEM_PROTECTED — reachable ONLY through root authority, never by a table. */
    case SystemProtected = 'system_protected';

    /** Explicit per-user DENY (Phase 2C). */
    case UserDeny = 'user_deny';

    /** Explicit per-user ALLOW (Phase 2C). */
    case UserAllow = 'user_allow';

    /** Department LEADER default (Phase 2A), for a canonical departments.leader_id leader. */
    case DepartmentLeader = 'department_leader';

    /** Department MEMBER default (Phase 2A). */
    case DepartmentMember = 'department_member';

    /** Role default (Phase 2B). */
    case Role = 'role';

    /** No layer granted the page — default deny. */
    case None = 'none';

    /**
     * Precedence, highest wins. Mirrors the approved Phase 2D ladder, with
     * canonical root authority seated above it:
     * root authority > system protection > user deny > user allow >
     * department leader > department member > role > none.
     */
    public function precedence(): int
    {
        return match ($this) {
            self::RootAuthority => 70,
            self::SystemProtected => 60,
            self::UserDeny => 50,
            self::UserAllow => 40,
            self::DepartmentLeader => 30,
            self::DepartmentMember => 20,
            self::Role => 10,
            self::None => 0,
        };
    }

    /** Label for the future Access Control UI — page exposure wording only. */
    public function labelAr(): string
    {
        return match ($this) {
            self::RootAuthority => 'صلاحية النظام الكاملة',
            self::SystemProtected => 'محمية من النظام',
            self::UserDeny => 'محجوبة بشكل خاص',
            self::UserAllow => 'إضافة خاصة',
            self::DepartmentLeader => 'لأنه قائد الإدارة',
            self::DepartmentMember => 'من الإدارة',
            self::Role => 'من الدور',
            self::None => 'لا يوجد مصدر',
        };
    }
}
