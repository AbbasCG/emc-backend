<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionAssignment;
use App\Support\PermissionGovernance;
use App\Support\PermissionGroupLabels;
use App\Support\Permissions;
use Illuminate\Support\Collection;

/**
 * Per-user ACTION permissions (business capabilities, not page access): what a user holds, WHERE it comes from, and whether an
 * admin may change the direct part.
 *
 * `effective` is $user->hasPermission() — the very function the ticket checks (TicketAccess) and Gate use — so what the UI shows
 * is what the backend decides. `sources` only EXPLAINS it:
 *   system        super_admin / tech_admin hold Permissions::all() implicitly (explicit-only keys such as
 *                 tickets.review_participated are NOT in all(), so they never show up as system)
 *   role          the users.role row (or the specialised department-leader role) grants it in permission_role
 *   direct_allow  an active per-user allow assignment    direct_deny  an active per-user deny (beats role and system)
 * Only the DIRECT assignment is editable here; a role / system grant is changed in the role editor.
 */
class UserActionPermissionService
{
    public function __construct(private readonly PermissionDelegationService $delegation)
    {
    }

    /**
     * @return Collection<int, array<string, mixed>> one row per `permissions` table entry (group, key order), each with the contract keys
     */
    public function rows(User $user, ?User $actor = null): Collection
    {
        $user->flushPermissionCache();

        $perms = Permission::query()->get(['id', 'name', 'display_name', 'label_ar', 'group', 'description_ar']);
        $base = array_flip($user->basePermissions());
        $systemSet = ($user->isSuperAdmin() || $user->isTechAdmin()) ? array_flip(Permissions::all()) : [];
        $explicitFree = array_flip(Permissions::all());
        $effectiveSet = array_flip($user->allPermissions());

        // Every row of the user (a pair may carry several: legacy / delegation rows). `$assignments` = the ACTIVE ones, last row wins,
        // exactly as PermissionDelegationService::getEffectivePermissions() folds them; `$allRows` = all of them (revoke / grant rules).
        $allRows = UserPermissionAssignment::query()->where('user_id', $user->id)->orderBy('id')->get()->groupBy('permission_id');
        $assignments = $allRows->map(fn ($rows) => $rows->filter(fn ($a) => $a->expires_at === null || $a->expires_at->isFuture())->last())->filter();
        $grantedBy = User::withTrashed()->whereIn('id', $assignments->pluck('granted_by')->filter()->unique())->pluck('name', 'id');

        $ownRoleName = $user->role === 'teacher' ? 'instructor' : (string) $user->role;
        $ownRole = Role::query()->with('permissions:id,name')->where('name', $ownRoleName)->first();
        $ownSet = $ownRole ? array_flip($ownRole->permissions->pluck('name')->all()) : array_flip(Permissions::forRole($ownRoleName));
        $specializedName = $user->role === 'department_manager' ? $user->getDepartmentSpecializedRole() : null;
        $specializedRole = $specializedName ? Role::query()->with('permissions:id,name')->where('name', $specializedName)->first() : null;
        $specializedSet = $specializedName
            ? ($specializedRole ? array_flip($specializedRole->permissions->pluck('name')->all()) : array_flip(Permissions::forRole($specializedName)))
            : [];

        $roleLabel = $ownRole?->display_name ?: $ownRoleName;
        $specializedLabel = $specializedRole?->display_name ?: $specializedName;

        return $perms
            ->sortBy(fn (Permission $p) => sprintf('%04d|%s|%s', PermissionGroupLabels::rank((string) $p->group), $p->group, $p->name))
            ->values()
            ->map(function (Permission $p) use ($user, $actor, $base, $systemSet, $explicitFree, $effectiveSet, $assignments, $allRows, $grantedBy, $ownSet, $specializedSet, $roleLabel, $specializedLabel) {
                $key = $p->name;
                $assignment = $assignments->get($p->id);
                $directState = $assignment?->state;
                $roleGrants = isset($base[$key]);

                $sources = [];
                if ($roleGrants) {
                    if (isset($systemSet[$key])) {
                        $sources[] = ['type' => 'system', 'label_ar' => 'صلاحية ضمنية لدور «'.$user->role.'» (كل الصلاحيات القياسية)'];
                    } elseif (isset($specializedSet[$key]) && ! isset($ownSet[$key])) {
                        $sources[] = ['type' => 'role', 'label_ar' => 'من الدور المتخصص لقيادة الإدارة: '.$specializedLabel];
                    } else {
                        $sources[] = ['type' => 'role', 'label_ar' => 'من الدور: '.$roleLabel];
                    }
                }
                if ($directState === 'allow') {
                    $sources[] = ['type' => 'direct_allow', 'label_ar' => 'منح مباشر للمستخدم'.($assignment->expires_at ? ' (حتى '.$assignment->expires_at->toDateString().')' : '')];
                } elseif ($directState === 'deny') {
                    $sources[] = ['type' => 'direct_deny', 'label_ar' => 'سحب مباشر للمستخدم (يُلغي الدور)'];
                }

                $effective = isset($effectiveSet[$key]);
                $source = 'none';
                if ($effective) {
                    $source = $roleGrants ? (isset($systemSet[$key]) ? 'system' : 'role') : 'direct';
                }

                $pairRows = $allRows->get($p->id, collect());
                $mayMutate = $actor ? $this->mayMutate($actor, $user, $key) : false;
                $isRoot = (bool) $actor?->isSuperAdmin();

                return [
                    'key' => $key,
                    'label_ar' => $p->label_ar ?: $p->display_name ?: $key,
                    'description_ar' => $p->description_ar,
                    'group' => $p->group,
                    'effective' => $effective,
                    'source' => $source,
                    'sources' => $sources,
                    'direct_state' => $directState,
                    'direct_expires_at' => $assignment?->expires_at?->toIso8601String(),
                    'granted_by' => $assignment && $assignment->granted_by
                        ? ['id' => (int) $assignment->granted_by, 'name' => $grantedBy[$assignment->granted_by] ?? null]
                        : null,
                    'role_grants' => $roleGrants,
                    // Removing the DIRECT assignment leaves the role / system grant, so the permission stays effective iff the base grants it.
                    'still_effective_after_revoke' => $roleGrants,
                    'explicit_only' => ! isset($explicitFree[$key]),
                    // A delegation / department-scoped row may be overwritten, and a deny or delegation row revoked, by a super_admin only.
                    'can_grant' => $mayMutate && ($isRoot || ! self::hasForeignRow($pairRows)),
                    'can_revoke' => $mayMutate && $pairRows->isNotEmpty() && ($isRoot || ! self::needsSuperToRevoke($pairRows)),
                ];
            });
    }

    /** One permission row (same shape as rows()). */
    public function row(User $user, string $key, ?User $actor = null): ?array
    {
        return $this->rows($user, $actor)->firstWhere('key', $key);
    }

    /**
     * Rows grouped for the UI, optionally narrowed by `search` (Arabic label OR key, case- and Arabic-accent-insensitive) and `group`.
     *
     * @return array<int, array{key: string, label_ar: string, permissions: array<int, array<string, mixed>>}>
     */
    public function groups(User $user, ?User $actor, ?string $search = null, ?string $group = null): array
    {
        $rows = $this->rows($user, $actor);

        if ($group !== null && $group !== '' && $group !== 'all') {
            $rows = $rows->where('group', $group);
        }
        $needle = $search !== null ? PermissionGroupLabels::fold($search) : '';
        if ($needle !== '') {
            $rows = $rows->filter(fn (array $r) => str_contains(PermissionGroupLabels::fold($r['label_ar'].' '.$r['key']), $needle));
        }

        return $rows->groupBy('group')
            ->map(fn (Collection $perms, string $g) => ['key' => $g, 'label_ar' => PermissionGroupLabels::label($g), 'permissions' => $perms->values()->all()])
            ->values()
            ->all();
    }

    /** A row that is not a plain global direct_admin one (a department-scoped delegation, a role-sourced row, ...). */
    public static function isForeignRow(UserPermissionAssignment $a): bool
    {
        return $a->source_type !== 'direct_admin' || $a->scope_type !== 'global';
    }

    /** @param  Collection<int, UserPermissionAssignment>  $rows */
    public static function hasForeignRow(Collection $rows): bool
    {
        return $rows->contains(fn (UserPermissionAssignment $a) => self::isForeignRow($a));
    }

    /** Revoking a deny row or any delegation / foreign row is for a super_admin only. @param  Collection<int, UserPermissionAssignment>  $rows */
    public static function needsSuperToRevoke(Collection $rows): bool
    {
        return $rows->contains(fn (UserPermissionAssignment $a) => $a->state === 'deny' || self::isForeignRow($a));
    }

    /** Audit snapshot of the rows that are about to be replaced / removed. @param  Collection<int, UserPermissionAssignment>  $rows */
    public static function snapshot(Collection $rows): array
    {
        return $rows->map(fn (UserPermissionAssignment $a) => [
            'state' => $a->state,
            'source_type' => $a->source_type,
            'scope_type' => $a->scope_type,
            'scope_id' => $a->scope_id,
            'expires_at' => $a->expires_at?->toIso8601String(),
            'granted_by' => $a->granted_by,
        ])->values()->all();
    }

    /** Roles whose accounts only a super_admin may give or take direct permissions to / from. */
    public const SUPER_ONLY_TARGET_ROLES = ['super_admin', 'tech_admin', 'admin', 'executive_admin'];

    /**
     * May $actor change the DIRECT assignment of $key for $target?  PermissionDelegationService::canDelegate() is the single rule:
     * governance first (tickets.review_participated -> actual super_admin only), no self-change, users.impersonate carve-out, then
     * super_admin / tech_admin may change any other key. The super_admin account itself is only editable by a super_admin.
     */
    public function mayMutate(User $actor, User $target, string $key): bool
    {
        if (in_array($target->role, self::SUPER_ONLY_TARGET_ROLES, true) && ! $actor->isSuperAdmin()) {
            return false;
        }

        return $this->delegation->canDelegate($actor, $target, $key);
    }

    /** Why mayMutate() refused, as the HTTP message. */
    public function refusalMessage(User $actor, User $target, string $key): string
    {
        if (! PermissionGovernance::mayChange($actor, [$key])) {
            return PermissionGovernance::MESSAGE;
        }
        if ($actor->id === $target->id) {
            return 'لا يمكنك تعديل صلاحياتك المباشرة بنفسك.';
        }
        if (in_array($target->role, self::SUPER_ONLY_TARGET_ROLES, true) && ! $actor->isSuperAdmin()) {
            return 'صلاحيات حسابات الإدارة العليا (مدير عام، مدير تقني، مدير، إدارة تنفيذية) لا يمنحها أو يسحبها إلا مدير عام.';
        }

        return 'لا تملك صلاحية منح هذه الصلاحية أو سحبها.';
    }
}
