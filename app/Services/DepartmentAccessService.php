<?php

namespace App\Services;

use App\Models\Department;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Single source of truth for department scoping — reused by every
 * department-scoped surface (meeting reports, weekly reports, department
 * member listing, board filtering) so access rules never live in per-role
 * if/else chains scattered across controllers.
 *
 * Two deliberately separate concepts, per EMC policy:
 *
 *   MEMBERSHIP — "does this user belong to the department" (roster/
 *   attendance visibility). Read from `user_departments` (pivot) and active
 *   `team_members` rows. Grants read access (view reports, view the member
 *   roster) but NEVER report-creation rights.
 *
 *   LEADERSHIP — "is this user responsible for the department" (may create
 *   and manage its reports). Read exclusively from the canonical
 *   `departments.leader_id` column — the schema's single-leader-per-
 *   department relationship. A user can lead several departments (several
 *   Department rows sharing their `leader_id`), but being an active team
 *   member never implies leadership, and a department-scoped role name
 *   (e.g. "programs_manager") never implies leadership by itself — only an
 *   actual `leader_id` assignment does.
 *
 * Privileged system roles (super_admin/tech_admin/executive_admin/admin)
 * bypass both checks — existing platform-wide admin override.
 */
class DepartmentAccessService
{
    /** Roles that operate across every department without an explicit assignment. */
    private const GLOBAL_ROLES = ['super_admin', 'tech_admin', 'executive_admin', 'admin'];

    public function isGlobal(User $user): bool
    {
        return in_array($user->role, self::GLOBAL_ROLES, true);
    }

    /**
     * Departments this user is the configured leader of — the canonical
     * leadership relationship (departments.leader_id). This is the ONLY
     * signal that grants report-creation rights for non-global users.
     */
    public function getLedDepartmentIds(User $user): Collection
    {
        return Department::query()->where('leader_id', $user->id)->pluck('id');
    }

    /**
     * Departments this user merely belongs to — roster/attendance
     * membership only, never a source of report-creation rights.
     */
    public function getMemberDepartmentIds(User $user): Collection
    {
        $pivotIds = $user->departments()->pluck('departments.id');
        $teamIds = TeamMember::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->pluck('department_id');

        return $pivotIds->merge($teamIds)->unique()->values();
    }

    /** Departments this user may READ (view reports/members) for: member ∪ leader ∪ all-if-global. */
    public function getAccessibleDepartmentIds(User $user): Collection
    {
        if ($this->isGlobal($user)) {
            return Department::query()->pluck('id');
        }

        return $this->getLedDepartmentIds($user)->merge($this->getMemberDepartmentIds($user))->unique()->values();
    }

    /** May this user READ this department's reports/roster? Membership OR leadership OR global. */
    public function canAccessDepartment(User $user, int $departmentId): bool
    {
        if ($this->isGlobal($user)) {
            return true;
        }

        return $this->getAccessibleDepartmentIds($user)->contains($departmentId);
    }

    /**
     * May this user CREATE/manage reports for this department? Leadership
     * (or global override) ONLY — active membership is not sufficient, and
     * neither is holding a department-scoped role name without an actual
     * leader_id assignment.
     */
    public function canManageDepartment(User $user, int $departmentId): bool
    {
        if ($this->isGlobal($user)) {
            return true;
        }

        return $this->getLedDepartmentIds($user)->contains($departmentId);
    }

    /** Restrict a list (read) query to the user's accessible departments. No-op for global roles. */
    public function scopeQuery(Builder $query, User $user, string $column = 'department_id'): Builder
    {
        if ($this->isGlobal($user)) {
            return $query;
        }

        return $query->whereIn($column, $this->getAccessibleDepartmentIds($user));
    }

    /** The department to auto-select/lock the create-report UI to when a leader leads exactly one. Null otherwise (0, 2+, or global). */
    public function resolveSoleLedDepartmentId(User $user): ?int
    {
        if ($this->isGlobal($user)) {
            return null;
        }

        $ids = $this->getLedDepartmentIds($user);

        return $ids->count() === 1 ? $ids->first() : null;
    }

    /**
     * The manifest the frontend uses to build the report-CREATION UI —
     * always leadership-scoped (or global), never membership-scoped, since
     * only leaders (and global admins) may create reports. An empty
     * `allowed_departments` for a non-global user means they lead nothing
     * (selectable) and the create action must be hidden, not shown with an
     * empty field.
     *
     * Selectable options are restricted to active departments (`->active()`)
     * — a new report is a new organizational assignment, and an archived
     * department (e.g. a merged-away duplicate) must not be offered as a
     * target for one. This does NOT affect getLedDepartmentIds()/
     * canManageDepartment() below, which remain unfiltered by status: those
     * answer "does this user have authority over department X" for EXISTING
     * data (already-created reports, department overview), a fact that must
     * keep working regardless of the department's current status — only the
     * "what can I pick to create something new" listing changes here.
     */
    public function manifest(User $user): array
    {
        if ($this->isGlobal($user)) {
            return [
                'department_scope' => 'global',
                'allowed_departments' => Department::query()
                    ->active()
                    ->orderBy('sort_order')
                    ->get(['id', 'name'])
                    ->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name]),
                'can_select_any_department' => true,
            ];
        }

        $ids = $this->getLedDepartmentIds($user);

        return [
            'department_scope' => 'restricted',
            'allowed_departments' => Department::query()
                ->whereIn('id', $ids)
                ->active()
                ->orderBy('sort_order')
                ->get(['id', 'name'])
                ->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name]),
            'can_select_any_department' => false,
        ];
    }
}
