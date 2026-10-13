<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;
use App\Support\Permissions;

/**
 * Who may do what with a meeting, its attendance and its single official report.
 *
 * Built on the existing authorization architecture - DepartmentAccessService
 * (membership / leadership / global / read-only-global) and the role
 * permission `manage_meetings` - and never on a role-name list. Page access
 * decides who can OPEN a page; it grants none of this.
 *
 *   assigned   the organizer; an explicitly invited user; for a department-wide
 *              meeting (nobody invited by name) a member/leader of that
 *              department; and anyone who manages the meeting.
 *   view       oversight (global, read-only-global such as quality, or the
 *              quality-governance department) or assigned.
 *   manage     global, the organizer, the meeting department's LEADER, or a
 *              member holding `manage_meetings` inside that department.
 *   join       assigned and the meeting is not cancelled.
 *   attendance names + statuses: manage or oversight. Everyone else assigned
 *              sees only their own state.
 *   report     filed by whoever manages the meeting; READ only by oversight or
 *              whoever manages it. A plain assigned user sees that a report
 *              exists, never its content.
 *   create     global, or - for a specific department - its leader or a member
 *              holding `manage_meetings`. A non-global user never schedules a
 *              department-less meeting, because nobody in a department would see it.
 *
 * The per-user lookups (accessible/led departments, permission, quality
 * membership) are memoized for the life of the service, so serializing a page of
 * meetings does not repeat them for every row.
 */
class MeetingAccessService
{
    /** @var array<int, array<string, mixed>> */
    private array $memo = [];

    public function __construct(private readonly DepartmentAccessService $departments)
    {
    }

    /**
     * The instance shared by everything serving ONE request (controller and
     * resources), so the memoized lookups are computed once per request and
     * never leak between requests or users.
     */
    public static function for(\Illuminate\Http\Request $request): self
    {
        $shared = $request->attributes->get('meeting_access');
        if (! $shared instanceof self) {
            $shared = app(self::class);
            $request->attributes->set('meeting_access', $shared);
        }

        return $shared;
    }

    private function remember(User $user, string $key, callable $resolve): mixed
    {
        if (! array_key_exists($key, $this->memo[$user->id] ?? [])) {
            $this->memo[$user->id][$key] = $resolve();
        }

        return $this->memo[$user->id][$key];
    }

    /** @return array<int, int> */
    private function accessibleIds(User $user): array
    {
        // TRUE membership (member or leader). getAccessibleDepartmentIds() would return every
        // department for read-only-global roles such as quality, and oversight is not assignment.
        return $this->remember($user, 'accessible', fn () => $this->departments->getMemberDepartmentIds($user)
            ->merge($this->departments->getLedDepartmentIds($user))->map(fn ($i) => (int) $i)->unique()->values()->all());
    }

    /** @return array<int, int> */
    private function ledIds(User $user): array
    {
        return $this->remember($user, 'led', fn () => $this->departments->getLedDepartmentIds($user)->map(fn ($i) => (int) $i)->all());
    }

    private function holdsManagePermission(User $user): bool
    {
        return (bool) $this->remember($user, 'manage_perm', fn () => $user->hasPermission(Permissions::MANAGE_MEETINGS));
    }

    private function isGlobal(User $user): bool
    {
        return $this->departments->isGlobal($user);
    }

    /** Quality / global oversight: reads every meeting and report, writes none by that right alone. */
    public function hasOversight(User $user): bool
    {
        return (bool) $this->remember($user, 'oversight', fn () => $this->departments->isGlobal($user)
            || $this->departments->isReadOnlyGlobal($user)
            || $user->inDepartment('quality-governance'));
    }

    /**
     * Meetings this user is ASSIGNED to (not oversight): what they organize, what they
     * are invited to, what their department leads or manages, and department-wide
     * meetings (nobody invited by name) of departments they belong to. The lounge list
     * and the calendar both start from this, so they can never disagree.
     */
    public function assignedQuery(User $user): \Illuminate\Database\Eloquent\Builder
    {
        $accessible = $this->accessibleIds($user);
        $led = $this->ledIds($user);
        $managed = $this->holdsManagePermission($user) ? $accessible : [];
        $invited = fn ($p) => $p->where('user_id', $user->id)->where('source', MeetingParticipant::SOURCE_INVITED);
        $anyInvited = fn ($p) => $p->where('source', MeetingParticipant::SOURCE_INVITED);

        return Meeting::query()->where(function ($q) use ($user, $accessible, $led, $managed, $invited, $anyInvited) {
            $q->where('organizer_id', $user->id)
                ->orWhereHas('participants', $invited)
                ->orWhereIn('department_id', array_merge($led, $managed))
                ->orWhere(fn ($d) => $d->whereIn('department_id', $accessible)->whereDoesntHave('participants', $anyInvited));
        });
    }

    public function isInvited(User $user, Meeting $meeting): bool
    {
        if ($meeting->relationLoaded('participants')) {
            return $meeting->participants->contains(
                fn (MeetingParticipant $p) => (int) $p->user_id === $user->id && $p->source === MeetingParticipant::SOURCE_INVITED
            );
        }

        return $meeting->participants()->where('user_id', $user->id)->where('source', MeetingParticipant::SOURCE_INVITED)->exists();
    }

    public function isAssigned(User $user, Meeting $meeting): bool
    {
        if ($meeting->organizer_id !== null && (int) $meeting->organizer_id === $user->id) {
            return true;
        }
        if ($this->isInvited($user, $meeting)) {
            return true;
        }
        if ($this->canManage($user, $meeting)) {
            return true;
        }

        return $meeting->department_id !== null
            && ! $meeting->hasExplicitAudience()
            && in_array((int) $meeting->department_id, $this->accessibleIds($user), true);
    }

    public function canView(User $user, Meeting $meeting): bool
    {
        return $this->hasOversight($user) || $this->isAssigned($user, $meeting);
    }

    public function canManage(User $user, Meeting $meeting): bool
    {
        if ($this->isGlobal($user)) {
            return true;
        }

        if ($meeting->organizer_id !== null && (int) $meeting->organizer_id === $user->id) {
            return true;
        }

        return $meeting->department_id !== null && $this->canManageIn($user, (int) $meeting->department_id);
    }

    public function canJoin(User $user, Meeting $meeting): bool
    {
        return $meeting->status !== 'cancelled' && $this->isAssigned($user, $meeting);
    }

    public function canViewAttendance(User $user, Meeting $meeting): bool
    {
        return $this->canManage($user, $meeting) || $this->hasOversight($user);
    }

    /** May this user schedule a meeting for $departmentId (null = no department)? */
    public function canCreate(User $user, ?int $departmentId): bool
    {
        if ($this->isGlobal($user)) {
            return true;
        }

        return $departmentId !== null && $this->canManageIn($user, $departmentId);
    }

    /** Does this user have any right to schedule meetings at all? Drives the "new meeting" button. */
    public function canCreateAny(User $user): bool
    {
        if ($this->isGlobal($user)) {
            return true;
        }

        return $this->ledIds($user) !== []
            || ($this->holdsManagePermission($user) && $this->accessibleIds($user) !== []);
    }

    /** Departments this user may schedule a meeting for (null = every department). */
    public function creatableDepartmentIds(User $user): ?array
    {
        if ($this->isGlobal($user)) {
            return null;
        }

        $ids = $this->ledIds($user);
        if ($this->holdsManagePermission($user)) {
            $ids = array_merge($ids, $this->accessibleIds($user));
        }

        return array_values(array_unique($ids));
    }

    /** Departments whose official meeting reports this user may read (null = all of them). */
    public function readableReportDepartmentIds(User $user): ?array
    {
        if ($this->hasOversight($user)) {
            return null;
        }

        return $this->creatableDepartmentIds($user) ?? [];
    }

    public function canViewReportsOf(User $user, ?int $departmentId): bool
    {
        $ids = $this->readableReportDepartmentIds($user);

        return $ids === null || ($departmentId !== null && in_array($departmentId, $ids, true));
    }

    public function canUploadReport(User $user, Meeting $meeting, bool $hasReport): bool
    {
        return ! $hasReport
            && $meeting->status !== 'cancelled'
            && $this->canManage($user, $meeting);
    }

    public function canViewReport(User $user, Meeting $meeting, bool $hasReport): bool
    {
        return $hasReport && ($this->hasOversight($user) || $this->canManage($user, $meeting));
    }

    private function canManageIn(User $user, int $departmentId): bool
    {
        if (in_array($departmentId, $this->ledIds($user), true)) {
            return true;
        }

        return $this->holdsManagePermission($user) && in_array($departmentId, $this->accessibleIds($user), true);
    }
}
