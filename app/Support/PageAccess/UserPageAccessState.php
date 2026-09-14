<?php

namespace App\Support\PageAccess;

/**
 * The explicit state of a per-user PAGE ACCESS override.
 *
 * There is deliberately NO `default` case: DEFAULT is represented by the
 * ABSENCE of a row (see UserPageAccessOverride / UserPageAccessOverrideService).
 * Storing an explicit "default" row would create two ways to express the same
 * thing and make "reset" ambiguous.
 *
 * PAGE ACCESS != BUSINESS AUTHORIZATION. The allow/deny wording intentionally
 * mirrors UserPermissionAssignment.state, but the two are unrelated stores:
 * that one decides backend actions, this one only decides page exposure.
 */
enum UserPageAccessState: string
{
    case Allow = 'allow';
    case Deny = 'deny';

    public function labelAr(): string
    {
        return match ($this) {
            self::Allow => 'مسموح صراحةً',
            self::Deny => 'محجوب صراحةً',
        };
    }
}
