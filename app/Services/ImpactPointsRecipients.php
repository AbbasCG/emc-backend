<?php

namespace App\Services;

use App\Models\Department;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use App\Models\VolunteerPointAward;
use App\Support\Permissions;
use Illuminate\Support\Collection;

/**
 * WHO may receive Impact Points, and WHO may give them to whom.
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
 * WHO MAY AWARD — business authorization, not page access
 * ─────────────────────────────────────────────────────────────────────────
 * The existing permission system decides, so the owner can grant or withdraw
 * it from the Roles & Permissions page or delegate it per user:
 *   members.recognize ("منح تكريم أو شارة لعضو") or members.manage.
 * Opening the Impact Points page grants neither.
 *
 * SCOPE: org-wide for members.manage holders and global admin roles; a holder
 * of members.view_department (department-scoped member authority) is limited
 * to the departments they canonically lead — departments.leader_id via
 * DepartmentAccessService, never a role slug or team_profiles.is_leader.
 */
class ImpactPointsRecipients
{
    public function __construct(private readonly DepartmentAccessService $departmentAccess)
    {
    }

    public function canAward(User $actor): bool
    {
        return $actor->hasPermission(Permissions::MEMBERS_RECOGNIZE)
            || $actor->hasPermission(Permissions::MEMBERS_MANAGE);
    }

    /**
     * Departments the actor may award within. null = every department.
     * An empty collection means the actor may award nobody.
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
     * Eligible recipients the actor may award, excluding the actor.
     *
     * @return Collection<int, array{user_id:int, name:string, department_id:int, department:?string, role_title:?string, month_points:int, lifetime_points:int}>
     */
    public function forActor(User $actor): Collection
    {
        return $this->resolve($this->departmentScope($actor), $actor->id);
    }

    /** Is this user a valid recipient that the actor may award? */
    public function isAwardableBy(User $actor, int $recipientId): bool
    {
        if ($recipientId === $actor->id) {
            return false;
        }

        return $this->resolve($this->departmentScope($actor), $actor->id, $recipientId)->isNotEmpty();
    }

    /**
     * @param  Collection<int, int>|null  $departmentIds  null = all departments
     */
    private function resolve(?Collection $departmentIds, ?int $excludeUserId = null, ?int $onlyUserId = null): Collection
    {
        if ($departmentIds !== null && $departmentIds->isEmpty()) {
            return collect();
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

        unset($context[$excludeUserId]);

        if ($context === []) {
            return collect();
        }

        $users = User::query()
            ->whereIn('id', array_keys($context))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $departments = Department::query()
            ->whereIn('id', collect($context)->pluck('department_id')->unique()->all())
            ->pluck('name', 'id');

        $ids = $users->pluck('id')->all();
        $lifetime = VolunteerPointAward::query()
            ->whereIn('user_id', $ids)
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(points) as total')
            ->pluck('total', 'user_id');
        $month = VolunteerPointAward::query()
            ->whereIn('user_id', $ids)
            ->where('points', '>', 0)
            ->whereBetween('awarded_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(points) as total')
            ->pluck('total', 'user_id');

        return $users->map(fn (User $u) => [
            'user_id'         => $u->id,
            'name'            => $u->name,
            'department_id'   => (int) $context[$u->id]['department_id'],
            'department'      => $departments[$context[$u->id]['department_id']] ?? null,
            'role_title'      => $context[$u->id]['role_title'],
            'month_points'    => (int) ($month[$u->id] ?? 0),
            'lifetime_points' => (int) ($lifetime[$u->id] ?? 0),
        ])->values();
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
