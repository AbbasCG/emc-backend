<?php

namespace App\Support;

use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Who may change WHERE a conflict-of-interest permission is held, and the audit trail of permission changes.
 *
 * tickets.review_participated («اعتماد التذاكر التي أشارك فيها») lets someone approve the work they created, were assigned or
 * submitted. Adding it to / removing it from ANY role, or granting / revoking it for ANY person, is reserved to an actual
 * super_admin: not tech_admin, not an admin holding manage_roles, not a department leader (delegatable row or not).
 * Every entry point that changes role or per-user permissions calls requireSuperAdmin() with the keys it is about to
 * change, and logs the diff with logChange().
 */
final class PermissionGovernance
{
    /** @var array<int, string> */
    public const SUPER_ADMIN_ONLY = [Permissions::TICKETS_REVIEW_PARTICIPATED];

    public const MESSAGE = 'منح صلاحية «اعتماد التذاكر التي أشارك فيها» أو سحبها من الأدوار أو الأشخاص متاح للمدير العام (super_admin) فقط.';

    /**
     * Keys outside Permissions::all() that keep their OWN, older rule instead of the blanket super_admin-only one:
     * users.impersonate may be delegated by an admin-tier actor that ACTUALLY holds it (PermissionDelegationService::canDelegate).
     *
     * @var array<int, string>
     */
    public const HOLDER_RULE_KEYS = [Permissions::IMPERSONATE_USERS];

    /**
     * The guarded keys among $names: SUPER_ADMIN_ONLY plus EVERY other key outside Permissions::all() (explicit-grant-only keys such
     * as executive.command_center.view and any future one): they are never implied by a role, so who holds them is decided by a
     * super_admin alone, for grants and revokes alike. HOLDER_RULE_KEYS (users.impersonate) are the one approved exception.
     *
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    public static function guarded(array $names): array
    {
        $implicit = array_flip(Permissions::all());

        return array_values(array_filter(
            array_unique($names),
            fn ($n) => in_array($n, self::SUPER_ADMIN_ONLY, true)
                || (! isset($implicit[$n]) && ! in_array($n, self::HOLDER_RULE_KEYS, true))
        ));
    }

    /** May $actor change the holders of every key in $names? True unless a guarded key is among them and the actor is not super_admin. */
    public static function mayChange(?User $actor, array $names): bool
    {
        return self::guarded($names) === [] || (bool) $actor?->isSuperAdmin();
    }

    /** 403 (Arabic message) when $actor may not change the holders of one of $names. */
    public static function requireSuperAdmin(?User $actor, array $names): void
    {
        abort_unless(self::mayChange($actor, $names), 403, self::MESSAGE);
    }

    /**
     * Audit row of a permission change (who = the authenticated user, set by ActivityLogger). Written for every key, not just the guarded ones.
     *
     * @param  array<int, string>  $added
     * @param  array<int, string>  $removed
     * @param  array<string, mixed>  $context  e.g. ['role' => 'admin'] or ['target_user_id' => 5, 'state' => 'allow']
     */
    public static function logChange(string $action, ?Model $subject, array $added, array $removed, array $context = [], ?Request $request = null): void
    {
        ActivityLogger::log(
            $action,
            $subject,
            [],
            $request,
            null,
            ['added' => array_values($added), 'removed' => array_values($removed), 'guarded' => self::guarded([...$added, ...$removed])] + $context,
        );
    }
}
