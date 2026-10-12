<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\User;
use App\Support\Permissions;

/**
 * Who may do what with a meeting and its single official report.
 *
 * Built on the existing authorization architecture - DepartmentAccessService
 * (membership / leadership / global / read-only-global) and the role
 * permission `manage_meetings` - and never on a role-name list. Page access
 * decides who can OPEN the lounge page; it grants none of this.
 *
 *   view     global, quality oversight (read-only global or the
 *            quality-governance department), a member/leader of the meeting's
 *            department, or its organizer.
 *   manage   global, the organizer, the meeting department's LEADER, or a
 *            member with the `manage_meetings` permission inside that
 *            department. (Edit, add decisions/action items, upload the report.)
 *   create   global, or - for a specific department - its leader or a member
 *            holding `manage_meetings`. A non-global user never creates a
 *            department-less meeting, because nobody in a department would see it.
 */
class MeetingAccessService
{
    public function __construct(private readonly DepartmentAccessService $departments)
    {
    }

    /** Quality / global oversight: reads every meeting and report, writes none by that right alone. */
    public function hasOversight(User $user): bool
    {
        return $this->departments->isGlobal($user)
            || $this->departments->isReadOnlyGlobal($user)
            || $user->inDepartment('quality-governance');
    }

    public function canView(User $user, Meeting $meeting): bool
    {
        if ($this->hasOversight($user)) {
            return true;
        }

        if ($meeting->organizer_id !== null && (int) $meeting->organizer_id === $user->id) {
            return true;
        }

        return $meeting->department_id !== null
            && $this->departments->canAccessDepartment($user, (int) $meeting->department_id);
    }

    public function canManage(User $user, Meeting $meeting): bool
    {
        if ($this->departments->isGlobal($user)) {
            return true;
        }

        if ($meeting->organizer_id !== null && (int) $meeting->organizer_id === $user->id) {
            return true;
        }

        return $meeting->department_id !== null && $this->canManageIn($user, (int) $meeting->department_id);
    }

    /** May this user schedule a meeting for $departmentId (null = no department). */
    public function canCreate(User $user, ?int $departmentId): bool
    {
        if ($this->departments->isGlobal($user)) {
            return true;
        }

        return $departmentId !== null && $this->canManageIn($user, $departmentId);
    }

    /** Does this user have any right to schedule meetings at all? Drives the "new meeting" button. */
    public function canCreateAny(User $user): bool
    {
        if ($this->departments->isGlobal($user)) {
            return true;
        }

        return $this->departments->getLedDepartmentIds($user)->isNotEmpty()
            || ($user->hasPermission(Permissions::MANAGE_MEETINGS) && $this->departments->getAccessibleDepartmentIds($user)->isNotEmpty());
    }

    /** Departments this user may schedule a meeting for (null = every department). */
    public function creatableDepartmentIds(User $user): ?array
    {
        if ($this->departments->isGlobal($user)) {
            return null;
        }

        $ids = $this->departments->getLedDepartmentIds($user);
        if ($user->hasPermission(Permissions::MANAGE_MEETINGS)) {
            $ids = $ids->merge($this->departments->getAccessibleDepartmentIds($user));
        }

        return $ids->unique()->values()->all();
    }

    public function canUploadReport(User $user, Meeting $meeting, bool $hasReport): bool
    {
        return ! $hasReport
            && $meeting->status !== 'cancelled'
            && $this->canManage($user, $meeting);
    }

    public function canViewReport(User $user, Meeting $meeting, bool $hasReport): bool
    {
        return $hasReport && $this->canView($user, $meeting);
    }

    private function canManageIn(User $user, int $departmentId): bool
    {
        if ($this->departments->canManageDepartment($user, $departmentId)) {
            return true;
        }

        return $user->hasPermission(Permissions::MANAGE_MEETINGS)
            && $this->departments->canAccessDepartment($user, $departmentId);
    }
}
