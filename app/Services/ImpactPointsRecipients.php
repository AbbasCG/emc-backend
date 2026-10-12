<?php

namespace App\Services;

use App\Models\Department;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use App\Models\VolunteerPointAward;
use App\Support\ImpactLevels;
use App\Support\Permissions;
use Illuminate\Support\Collection;

/**
 * WHO may receive Impact Points, and WHO may give, adjust or inspect them.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * VALID RECIPIENT
 * ─────────────────────────────────────────────────────────────────────────
 * An active, non-deleted user with an ACTIVE EMC team record that names a
 * department:
 *   - a team_members row with status = 'active', or
 *   - an active team_profiles row linked to the user (accepted volunteers and
 *     staff receive one).
 * A role slug never qualifies anyone on its own: an LMS student or a
 * course-only instructor has neither record and is therefore never a
 * recipient, while the same person WITH a real team record is. The
 * user_departments pivot alone is deliberately not enough — it is set from the
 * admin user editor for any account and does not prove EMC work.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHO MAY MANAGE — business authorization, not page access
 * ─────────────────────────────────────────────────────────────────────────
 * The existing permission system decides, so the owner can grant or withdraw
 * it from the Roles & Permissions page or delegate it per user:
 *   members.recognize ("منح تكريم أو شارة لعضو") or members.manage.
 * Opening the Impact Points page grants neither.
 *
 * One authority covers every management action — listing the people with
 * points, reading another person's history, awarding and adjusting. There is
 * deliberately no second permission: an adjustment is the same act as the
 * negative award this system has always allowed, only recorded with a typed,
 * auditable row. Splitting them would add a permission without adding safety.
 *
 * SCOPE: org-wide for members.manage holders and global admin roles (and for a
 * members.recognize holder with no department-scoped authority, e.g. HR); a
 * holder of members.view_department (department-scoped member authority) is
 * limited to the departments they canonically lead — departments.leader_id via
 * DepartmentAccessService, never a role slug or team_profiles.is_leader.
 */
class ImpactPointsRecipients
{
    public const SCOPE_NONE = 'none';
    public const SCOPE_DEPARTMENT = 'department';
    public const SCOPE_ORGANIZATION = 'organization';

    public function __construct(private readonly DepartmentAccessService $departmentAccess)
    {
    }

    public function canAward(User $actor): bool
    {
        return $actor->hasPermission(Permissions::MEMBERS_RECOGNIZE)
            || $actor->hasPermission(Permissions::MEMBERS_MANAGE);
    }

    /** List the people with points in scope. Same authority as awarding. */
    public function canViewAll(User $actor): bool
    {
        return $this->canAward($actor);
    }

    /** Append a typed correction. Same authority as awarding. */
    public function canAdjust(User $actor): bool
    {
        return $this->canAward($actor);
    }

    public function scopeLabel(User $actor): string
    {
        if (! $this->canAward($actor)) {
            return self::SCOPE_NONE;
        }

        return $this->departmentScope($actor) === null ? self::SCOPE_ORGANIZATION : self::SCOPE_DEPARTMENT;
    }

    /**
     * Server-computed capabilities for the page. The frontend only reflects
     * these; every endpoint re-checks them.
     *
     * @return array{can_view_all:bool, can_award:bool, can_adjust:bool, can_view_history:bool, can_manage:bool, scope:string}
     */
    public function capabilities(User $actor): array
    {
        $manage = $this->canAward($actor);

        return [
            'can_view_all'     => $this->canViewAll($actor),
            'can_award'        => $manage,
            'can_adjust'       => $this->canAdjust($actor),
            // Reading ANOTHER person's history. Everyone may read their own.
            'can_view_history' => $manage,
            'can_manage'       => $manage,
            'scope'            => $this->scopeLabel($actor),
        ];
    }

    /**
     * Departments the actor may act within. null = every department.
     * An empty collection means the actor may act on nobody.
     */
    public function departmentScope(User $actor): ?Collection
    {
        if ($actor->hasPermission(Permissions::MEMBERS_MANAGE) || $this->departmentAccess->isGlobal($actor)) {
            return null;
        }

        if ($actor->hasPermission(Permissions::MEMBERS_VIEW_DEPARTMENT)) {
            return $this->departmentAccess->getLedDepartmentIds($actor)->values();
        }

        return null;
    }

    /**
     * Eligible recipients in the actor's scope. The actor is excluded unless
     * $includeSelf — the people list shows the actor's own row (view only).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function forActor(User $actor, bool $includeSelf = false): Collection
    {
        return $this->resolve($this->departmentScope($actor), $includeSelf ? null : $actor->id);
    }

    /** Is this user a valid recipient that the actor may act on? */
    public function isAwardableBy(User $actor, int $recipientId): bool
    {
        if ($recipientId === $actor->id) {
            return false;
        }

        return $this->resolve($this->departmentScope($actor), $actor->id, $recipientId)->isNotEmpty();
    }

    /**
     * May the actor read this person's ledger? Their own — always (checked by
     * the caller). Someone else's — only within management scope. An
     * organization-wide manager may also open someone who has ledger rows but
     * is no longer an active recipient (left the team): that is an audit need,
     * and it never makes them a recipient again.
     */
    public function canViewHistoryOf(User $actor, int $targetId): bool
    {
        if (! $this->canAward($actor)) {
            return false;
        }

        if ($this->resolve($this->departmentScope($actor), null, $targetId)->isNotEmpty()) {
            return true;
        }

        return $this->departmentScope($actor) === null
            && VolunteerPointAward::query()->where('user_id', $targetId)->exists();
    }

    /**
     * Department name and title of a valid recipient, or null when the user has
     * no active team record. Display context only — grants nothing.
     *
     * @return array{department:?string, role_title:?string}|null
     */
    public function describe(int $userId): ?array
    {
        $ctx = $this->context(null, null, $userId)[$userId] ?? null;

        if ($ctx === null) {
            return null;
        }

        return [
            'department' => Department::query()->whereKey($ctx['department_id'])->value('name'),
            'role_title' => $ctx['role_title'],
        ];
    }

    /**
     * Every valid recipient id in the organization, with no point queries —
     * for filtering ledger-driven lists (the leaderboard) to real EMC members.
     *
     * @return Collection<int, int>
     */
    public function validRecipientIds(): Collection
    {
        $context = $this->context(null, null, null);

        if ($context === []) {
            return collect();
        }

        return User::query()->whereIn('id', array_keys($context))->where('is_active', true)->pluck('id');
    }

    /**
     * user_id => [department_id, role_title] for every active team record in
     * scope. A user can have both a membership and a profile; one row wins.
     *
     * @param  Collection<int, int>|null  $departmentIds  null = all departments
     * @return array<int, array{department_id:int, role_title:?string}>
     */
    private function context(?Collection $departmentIds, ?int $excludeUserId, ?int $onlyUserId): array
    {
        if ($departmentIds !== null && $departmentIds->isEmpty()) {
            return [];
        }

        $members = TeamMember::query()
            ->where('status', 'active')
            ->whereNotNull('user_id')
            ->whereNotNull('department_id')
            ->when($departmentIds, fn ($q) => $q->whereIn('department_id', $departmentIds))
            ->when($onlyUserId, fn ($q) => $q->where('user_id', $onlyUserId))
            ->orderBy('id')
            ->get(['user_id', 'department_id', 'role_title']);

        // A profile whose team_members row was explicitly deactivated does not
        // re-qualify its user — the same rule OperationsBoardController
        // applies to the department roster.
        $profiles = TeamProfile::query()
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->whereNotNull('department_id')
            ->when($departmentIds, fn ($q) => $q->whereIn('department_id', $departmentIds))
            ->when($onlyUserId, fn ($q) => $q->where('user_id', $onlyUserId))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('team_members')
                ->whereColumn('team_members.team_profile_id', 'team_profiles.id')
                ->where('team_members.status', '!=', 'active'))
            ->orderBy('id')
            ->get(['user_id', 'department_id', 'position_ar']);

        // One row per user: an active team_members record outranks the profile
        // for department and title; the profile fills what is missing.
        $context = [];
        foreach ($members as $m) {
            $context[$m->user_id] ??= ['department_id' => $m->department_id, 'role_title' => $m->role_title];
        }
        foreach ($profiles as $p) {
            if (! isset($context[$p->user_id])) {
                $context[$p->user_id] = ['department_id' => $p->department_id, 'role_title' => $p->position_ar];
            } elseif ($context[$p->user_id]['role_title'] === null) {
                $context[$p->user_id]['role_title'] = $p->position_ar;
            }
        }

        if ($excludeUserId !== null) {
            unset($context[$excludeUserId]);
        }

        return $context;
    }

    /**
     * @param  Collection<int, int>|null  $departmentIds  null = all departments
     * @return Collection<int, array<string, mixed>>
     */
    private function resolve(?Collection $departmentIds, ?int $excludeUserId = null, ?int $onlyUserId = null): Collection
    {
        $context = $this->context($departmentIds, $excludeUserId, $onlyUserId);

        if ($context === []) {
            return collect();
        }

        $users = User::query()
            ->whereIn('id', array_keys($context))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'created_at']);

        $departments = Department::query()
            ->whereIn('id', collect($context)->pluck('department_id')->unique()->all())
            ->pluck('name', 'id');

        $ids = $users->pluck('id')->all();
        $totals = VolunteerPointAward::query()
            ->whereIn('user_id', $ids)
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(points) as total, COUNT(*) as events, MAX(awarded_at) as last_at')
            ->get()
            ->keyBy('user_id');
        $month = VolunteerPointAward::query()
            ->whereIn('user_id', $ids)
            ->where('points', '>', 0)
            ->whereBetween('awarded_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(points) as total')
            ->pluck('total', 'user_id');

        return $users->map(function (User $u) use ($context, $departments, $totals, $month) {
            $lifetime = (int) ($totals[$u->id]->total ?? 0);
            $level = ImpactLevels::eligible($lifetime, (int) $u->created_at->diffInMonths(now()));
            $lastAt = $totals[$u->id]->last_at ?? null;

            return [
                'user_id'          => $u->id,
                'name'             => $u->name,
                'department_id'    => (int) $context[$u->id]['department_id'],
                'department'       => $departments[$context[$u->id]['department_id']] ?? null,
                'role_title'       => $context[$u->id]['role_title'],
                'month_points'     => (int) ($month[$u->id] ?? 0),
                'lifetime_points'  => $lifetime,
                'awards_count'     => (int) ($totals[$u->id]->events ?? 0),
                'last_activity_at' => $lastAt ? \Illuminate\Support\Carbon::parse($lastAt)->toIso8601String() : null,
                'level'            => ['id' => $level['id'], 'title' => $level['title']],
            ];
        })->values();
    }

    /** Positive points a user already received this calendar month (all sources). */
    public function monthPoints(int $userId): int
    {
        return (int) VolunteerPointAward::query()
            ->where('user_id', $userId)
            ->where('points', '>', 0)
            ->whereBetween('awarded_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('points');
    }
}
