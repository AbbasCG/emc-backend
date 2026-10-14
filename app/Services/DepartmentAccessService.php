<?php

namespace App\Services;

use App\Models\Department;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

    /**
     * Roles with read-only global access to all department data
     * (view reports, view members) — without creation/management rights.
     * Quality management must oversee all departments' performance reports.
     */
    private const READ_ONLY_GLOBAL_ROLES = ['quality_manager', 'quality'];

    public function isGlobal(User $user): bool
    {
        return in_array($user->role, self::GLOBAL_ROLES, true);
    }

    /** True for roles with read-only access to all departments. */
    public function isReadOnlyGlobal(User $user): bool
    {
        return in_array($user->role, self::READ_ONLY_GLOBAL_ROLES, true);
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

    /** Departments this user may READ (view reports/members) for: member ∪ leader ∪ all-if-global ∪ all-if-read-only-global. */
    public function getAccessibleDepartmentIds(User $user): Collection
    {
        if ($this->isGlobal($user) || $this->isReadOnlyGlobal($user)) {
            return Department::query()->pluck('id');
        }

        return $this->getLedDepartmentIds($user)->merge($this->getMemberDepartmentIds($user))->unique()->values();
    }

    /** May this user READ this department's reports/roster? Membership OR leadership OR global OR read-only-global. */
    public function canAccessDepartment(User $user, int $departmentId): bool
    {
        if ($this->isGlobal($user) || $this->isReadOnlyGlobal($user)) {
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

    /** Restrict a list (read) query to the user's accessible departments. No-op for global or read-only-global roles. */
    public function scopeQuery(Builder $query, User $user, string $column = 'department_id'): Builder
    {
        if ($this->isGlobal($user) || $this->isReadOnlyGlobal($user)) {
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
     * The manifest the frontend uses to build department-scoped UI. It
     * describes TWO deliberately separate scopes, because READ authority is
     * not WRITE authority:
     *
     * CREATE scope — `department_scope`, `allowed_departments`,
     * `can_select_any_department`, `can_create_report`. Always
     * leadership-scoped (or global admin), never membership-scoped, since
     * only leaders (and global admins) may create reports. An empty
     * `allowed_departments` for a non-global user means they lead nothing
     * and the create action must be hidden, not shown with an empty field.
     * Several consumers (the create-form department field, sole-department
     * auto-select, the department dashboards) depend on these keys meaning
     * exactly this, so read-only access must never be folded into them.
     *
     * READ scope — `read_scope`, `readable_departments`,
     * `can_view_multiple_departments`. Mirrors getAccessibleDepartmentIds():
     * membership ∪ leadership, or every department for global and
     * read-only-global roles. Drives browsing/filtering only. A read-only
     * global user (quality/quality_manager) therefore gets every department
     * here while their CREATE scope stays whatever canonical leadership
     * (departments.leader_id) independently grants — usually nothing.
     *
     * Both lists offer only active departments (`->active()`), consistent
     * with the pre-existing create picker: an archived department (e.g. a
     * merged-away duplicate) is not offered as a new target or filter
     * option. This does NOT affect getLedDepartmentIds()/
     * canManageDepartment()/getAccessibleDepartmentIds(), which remain
     * unfiltered by status and keep answering authority questions about
     * EXISTING data regardless of a department's current status.
     */
    public function manifest(User $user): array
    {
        $isGlobal = $this->isGlobal($user);
        $readsEveryDepartment = $isGlobal || $this->isReadOnlyGlobal($user);

        $ledIds = $isGlobal ? collect() : $this->getLedDepartmentIds($user);

        $allowedDepartments = $this->activeDepartmentOptions($isGlobal ? null : $ledIds);
        $readableDepartments = $this->activeDepartmentOptions(
            $readsEveryDepartment ? null : $this->getAccessibleDepartmentIds($user)
        );

        return [
            // CREATE scope — unchanged meaning (leadership or global admin only).
            'department_scope'          => $isGlobal ? 'global' : 'restricted',
            'allowed_departments'       => $allowedDepartments,
            'can_select_any_department' => $isGlobal,
            'can_create_report'         => $isGlobal || $ledIds->isNotEmpty(),

            // READ scope — browsing/filtering only, never grants creation.
            'read_scope'                    => $readsEveryDepartment ? 'global' : 'restricted',
            'readable_departments'          => $readableDepartments,
            'can_view_multiple_departments' => $readableDepartments->count() > 1,

            // The user's own primary department (user_departments.is_primary), offered only when it is
            // UNAMBIGUOUS and inside their read scope. Null for global roles (they are never steered into
            // one department) and whenever there is no primary, more than one, or one they cannot read.
            'primary_department_id' => $readsEveryDepartment ? null : $this->primaryDepartmentId($user, $readableDepartments),
        ];
    }

    /** @param  Collection<int, array{id: int, name: string}>  $readable */
    private function primaryDepartmentId(User $user, Collection $readable): ?int
    {
        $primary = DB::table('user_departments')->where('user_id', $user->id)->where('is_primary', true)->pluck('department_id');
        if ($primary->count() !== 1) {
            return null;
        }

        $id = (int) $primary->first();

        return $readable->contains(fn ($d) => (int) $d['id'] === $id) ? $id : null;
    }

    /**
     * Active departments as {id, name} options, ordered for display.
     * `null` means "every active department"; a collection restricts to it.
     */
    private function activeDepartmentOptions(?Collection $restrictToIds): Collection
    {
        return Department::query()
            ->when($restrictToIds !== null, fn (Builder $q) => $q->whereIn('id', $restrictToIds))
            ->active()
            ->orderBy('sort_order')
            ->get(['id', 'name', 'name_ar'])
            // `name` stays exactly as before; `name_ar` is the display name for Arabic screens (null when not set).
            ->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name, 'name_ar' => $d->name_ar])
            ->values();
    }
}
