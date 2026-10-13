<?php

namespace App\Services;

use App\Models\HrRequest;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see and act on HR staffing requests (طلبات الاحتياج الواردة).
 *
 * Business authorization only; page access (the sidebar entry and route guard) is a separate
 * layer and grants nothing here.
 *
 *   view all      `manage_team` holders, and the platform-wide roles that already see every
 *                 department (DepartmentAccessService::isGlobal). Read-only for the latter.
 *   view own      a department leader sees the requests of the departments they lead.
 *   manage        `manage_team`: change the status and create vacancy drafts.
 *   match         manage + `view_volunteers`: matching reads volunteer applications, so the
 *                 viewer must already be allowed to see them.
 *
 * Nobody else gets anything: before this, any signed-in user could list and change requests.
 */
class HrRequestAccessService
{
    public function __construct(private readonly DepartmentAccessService $departments) {}

    public function canManage(User $user): bool
    {
        return $user->hasPermission(Permissions::MANAGE_TEAM);
    }

    public function canMatch(User $user): bool
    {
        return $this->canManage($user) && $user->hasPermission(Permissions::VIEW_VOLUNTEERS);
    }

    public function canViewAll(User $user): bool
    {
        return $this->canManage($user) || $this->departments->isGlobal($user);
    }

    /** @return array<int, int> */
    public function ledDepartmentIds(User $user): array
    {
        return $this->departments->getLedDepartmentIds($user)->map(fn ($id) => (int) $id)->all();
    }

    public function canView(User $user): bool
    {
        return $this->canViewAll($user) || $this->ledDepartmentIds($user) !== [];
    }

    public function canSee(User $user, HrRequest $request): bool
    {
        if ($this->canViewAll($user)) {
            return true;
        }

        return $request->department_id !== null && in_array((int) $request->department_id, $this->ledDepartmentIds($user), true);
    }

    /** Restrict a request query to what this user may see. Caller must have passed canView(). */
    public function scope(Builder $query, User $user): Builder
    {
        if ($this->canViewAll($user)) {
            return $query;
        }

        return $query->whereIn('department_id', $this->ledDepartmentIds($user) ?: [0]);
    }

    /** @return array<string, bool> */
    public function capabilities(User $user): array
    {
        return [
            'can_view_all' => $this->canViewAll($user),
            'can_manage' => $this->canManage($user),
            'can_match_volunteers' => $this->canMatch($user),
            'can_create_draft' => $this->canManage($user),
        ];
    }
}
