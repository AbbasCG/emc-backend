<?php

namespace App\Services;

use App\Models\Department;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Single source of truth for "which departments can this user act within" —
 * reused by every department-scoped surface (meeting reports, weekly reports,
 * department member listing, board filtering) so access rules never live in
 * per-role if/else chains scattered across controllers.
 *
 * A user's departments are read from every real membership signal already in
 * the schema, since no one of them is consistently populated for every
 * department-scoped role yet:
 *   - Department::leader_id      — the department's designated single leader
 *   - user_departments (pivot)   — general many-to-many assignment
 *   - team_members (status=active) — the department roster used for meeting
 *     attendance elsewhere in the app
 * This deliberately does not branch on role name — a user with no department
 * assignment at all gets zero departments regardless of their role slug.
 */
class DepartmentAccessService
{
    /** Roles that operate across every department without an explicit assignment. */
    private const GLOBAL_ROLES = ['super_admin', 'tech_admin', 'executive_admin', 'admin'];

    public function isGlobal(User $user): bool
    {
        return in_array($user->role, self::GLOBAL_ROLES, true);
    }

    /** Department IDs this user may read/write within. All departments for global roles. */
    public function getAllowedDepartmentIds(User $user): Collection
    {
        if ($this->isGlobal($user)) {
            return Department::query()->pluck('id');
        }

        $ledIds = Department::query()->where('leader_id', $user->id)->pluck('id');
        $pivotIds = $user->departments()->pluck('departments.id');
        $teamIds = TeamMember::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->pluck('department_id');

        return $ledIds->merge($pivotIds)->merge($teamIds)->unique()->values();
    }

    public function canAccessDepartment(User $user, int $departmentId): bool
    {
        if ($this->isGlobal($user)) {
            return true;
        }

        return $this->getAllowedDepartmentIds($user)->contains($departmentId);
    }

    /** The department to auto-select/lock the UI to when a scoped user has exactly one. Null otherwise (0, 2+, or global). */
    public function resolveSoleDepartmentId(User $user): ?int
    {
        if ($this->isGlobal($user)) {
            return null;
        }

        $ids = $this->getAllowedDepartmentIds($user);

        return $ids->count() === 1 ? $ids->first() : null;
    }

    /** Restrict a list query to the user's allowed departments. No-op for global roles. */
    public function scopeQuery(Builder $query, User $user, string $column = 'department_id'): Builder
    {
        if ($this->isGlobal($user)) {
            return $query;
        }

        return $query->whereIn($column, $this->getAllowedDepartmentIds($user));
    }

    /** The manifest shape the frontend uses to decide dropdown vs. locked field vs. full selector. */
    public function manifest(User $user): array
    {
        if ($this->isGlobal($user)) {
            return [
                'department_scope' => 'global',
                'allowed_departments' => Department::query()
                    ->orderBy('sort_order')
                    ->get(['id', 'name'])
                    ->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name]),
                'can_select_any_department' => true,
            ];
        }

        $ids = $this->getAllowedDepartmentIds($user);

        return [
            'department_scope' => 'restricted',
            'allowed_departments' => Department::query()
                ->whereIn('id', $ids)
                ->orderBy('sort_order')
                ->get(['id', 'name'])
                ->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name]),
            'can_select_any_department' => false,
        ];
    }
}
