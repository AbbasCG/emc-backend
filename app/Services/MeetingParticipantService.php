<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Participants of a meeting: who can be picked, who is invited, who joined, and
 * who the official report records as present.
 *
 * The candidate pool is decided HERE, on the server, from canonical membership
 * (never from the client and never "all users"):
 *   - explicit audience  -> exactly the people invited by name;
 *   - a department       -> that department's roster (DepartmentRosterService);
 *   - neither            -> EMC members inside the viewer's own scope (every
 *                           department's members for global/read-only-global,
 *                           else only the departments the viewer can access).
 * Inactive accounts and LMS-only students are never candidates.
 */
class MeetingParticipantService
{
    public function __construct(
        private readonly DepartmentRosterService $roster,
        private readonly DepartmentAccessService $departments,
    ) {
    }

    /**
     * @return Collection<int, array{id: int, name: string, role: ?string, role_title: ?string, department: ?string}>
     */
    public function candidatesForDepartment(Department $department): Collection
    {
        $entries = collect($this->roster->members($department));
        $active = $this->activeNonStudentIds($entries->pluck('id')->all());
        $deptName = $department->name_ar ?: $department->name;

        return $entries
            ->filter(fn (array $m) => isset($active[$m['id']]))
            ->map(fn (array $m) => [
                'id'         => (int) $m['id'],
                'name'       => (string) ($m['name'] ?? ''),
                'role'       => $m['role'],
                'role_title' => $m['role_title'],
                'department' => $deptName,
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /** EMC members inside the viewer's scope, for a meeting with no department. */
    public function candidatesForViewerScope(User $viewer): Collection
    {
        $scopeAll = $this->departments->isGlobal($viewer) || $this->departments->isReadOnlyGlobal($viewer);
        $deptIds = $scopeAll ? null : $this->departments->getAccessibleDepartmentIds($viewer)->all();

        if ($deptIds !== null && $deptIds === []) {
            return collect();
        }

        $pairs = collect();
        $pairs = $pairs->merge(
            DB::table('user_departments')->when($deptIds !== null, fn ($q) => $q->whereIn('department_id', $deptIds))
                ->get(['user_id', 'department_id'])
        );
        $pairs = $pairs->merge(
            TeamMember::query()->where('status', 'active')->whereNotNull('user_id')
                ->when($deptIds !== null, fn ($q) => $q->whereIn('department_id', $deptIds))
                ->get(['user_id', 'department_id'])
        );
        $pairs = $pairs->merge(
            TeamProfile::query()->where('is_active', true)->whereNotNull('user_id')->whereNotNull('department_id')
                ->when($deptIds !== null, fn ($q) => $q->whereIn('department_id', $deptIds))
                ->get(['user_id', 'department_id'])
        );
        foreach (Department::query()->when($deptIds !== null, fn ($q) => $q->whereIn('id', $deptIds))
            ->get(['id', 'leader_id', 'deputy_leader_id', 'operational_member_id']) as $d) {
            foreach (['leader_id', 'deputy_leader_id', 'operational_member_id'] as $seat) {
                if ($d->{$seat}) {
                    $pairs->push((object) ['user_id' => $d->{$seat}, 'department_id' => $d->id]);
                }
            }
        }

        $firstDept = [];
        foreach ($pairs as $pair) {
            $firstDept[(int) $pair->user_id] ??= (int) $pair->department_id;
        }

        $deptNames = Department::query()->get(['id', 'name', 'name_ar'])
            ->mapWithKeys(fn ($d) => [(int) $d->id => $d->name_ar ?: $d->name]);

        if ($firstDept === []) {
            return collect();
        }

        return User::query()
            ->whereIn('id', array_keys($firstDept))
            ->where('is_active', true)
            ->where('role', '!=', 'student')
            ->orderBy('name')
            ->get(['id', 'name', 'role'])
            ->map(fn (User $u) => [
                'id'         => (int) $u->id,
                'name'       => (string) $u->name,
                'role'       => $u->role,
                'role_title' => null,
                'department' => isset($firstDept[$u->id]) ? ($deptNames[$firstDept[$u->id]] ?? null) : null,
            ])
            ->values();
    }

    /** The pool for SCHEDULING: a department's roster, or the viewer's scope when none is chosen. */
    public function candidatesForScheduling(User $viewer, ?int $departmentId): Collection
    {
        if ($departmentId !== null) {
            $department = Department::query()->find($departmentId);

            return $department ? $this->candidatesForDepartment($department) : collect();
        }

        return $this->candidatesForViewerScope($viewer);
    }

    /** The pool for ATTENDANCE and the report of an existing meeting. */
    public function poolForMeeting(Meeting $meeting, User $viewer): Collection
    {
        if ($meeting->hasExplicitAudience()) {
            $ids = $meeting->participants()->where('source', MeetingParticipant::SOURCE_INVITED)->pluck('user_id')->all();
            $active = $this->activeNonStudentIds($ids);
            $deptName = $meeting->department ? ($meeting->department->name_ar ?: $meeting->department->name) : null;

            return User::query()->whereIn('id', array_keys($active))->orderBy('name')->get(['id', 'name', 'role'])
                ->map(fn (User $u) => [
                    'id' => (int) $u->id, 'name' => (string) $u->name, 'role' => $u->role, 'role_title' => null, 'department' => $deptName,
                ])->values();
        }

        return $this->candidatesForScheduling($viewer, $meeting->department_id !== null ? (int) $meeting->department_id : null);
    }

    /** @return array<int, true> keyed by user id */
    private function activeNonStudentIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return User::query()->whereIn('id', $ids)->where('is_active', true)->where('role', '!=', 'student')
            ->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    // ── Invitations ──────────────────────────────────────────────────────────

    /**
     * Make $userIds the explicit audience. Rows of people already removed from the
     * list are dropped only while they are still just "invited" (nothing recorded).
     *
     * @param  array<int, int>  $userIds
     */
    public function syncInvited(Meeting $meeting, array $userIds): void
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        $meeting->participants()
            ->where('source', MeetingParticipant::SOURCE_INVITED)
            ->where('status', MeetingParticipant::STATUS_INVITED)
            ->whereNotIn('user_id', $userIds)
            ->delete();

        $newlyInvited = [];
        foreach ($userIds as $id) {
            $row = $meeting->participants()->where('user_id', $id)->first();
            if ($row) {
                if ($row->source !== MeetingParticipant::SOURCE_INVITED) {
                    $row->update(['source' => MeetingParticipant::SOURCE_INVITED]);
                }
            } else {
                $meeting->participants()->create([
                    'user_id' => $id, 'source' => MeetingParticipant::SOURCE_INVITED, 'status' => MeetingParticipant::STATUS_INVITED,
                ]);
                $newlyInvited[] = $id;
            }
        }
        $meeting->unsetRelation('participants');

        // Send in-platform notification to newly invited members
        if (! empty($newlyInvited)) {
            $dateStr = $meeting->meeting_date ? $meeting->meeting_date->format('Y-m-d') : '';
            $timeStr = $meeting->start_time ?: '';
            \App\Services\NotificationService::send(
                $newlyInvited,
                'meeting_invitation',
                "دعوة لاجتماع: {$meeting->title}",
                "تمت دعوتك لحضور اجتماع '{$meeting->title}' المجدول بتاريخ {$dateStr} الساعة {$timeStr}",
                [
                    'entity_type' => 'meeting',
                    'entity_id'   => $meeting->id,
                    'action_url'  => "/dashboard/assignee?tab=meetings",
                    'meta_url'    => "/dashboard/assignee?tab=meetings",
                    'icon'        => 'video',
                    'color'       => 'blue',
                ]
            );
        }
    }

    // ── Joining ──────────────────────────────────────────────────────────────

    /** Record that $user opened the meeting ('join') or confirmed they attended ('confirm'). */
    public function record(Meeting $meeting, User $user, string $kind): MeetingParticipant
    {
        $row = $meeting->participants()->firstOrNew(['user_id' => $user->id]);
        if (! $row->exists) {
            $row->source = MeetingParticipant::SOURCE_SELF;
            $row->status = MeetingParticipant::STATUS_INVITED;
        }

        $now = now();
        if ($kind === 'confirm') {
            $row->confirmed_at ??= $now;
        }
        $row->joined_at ??= $now;
        // A report that already finalized the person keeps its verdict.
        if (! in_array($row->status, [MeetingParticipant::STATUS_ATTENDED, MeetingParticipant::STATUS_ABSENT], true)) {
            $row->status = MeetingParticipant::STATUS_JOINED;
        }
        $row->save();
        $meeting->unsetRelation('participants');

        return $row;
    }

    // ── The report's attendance ──────────────────────────────────────────────

    /**
     * Final attendance from the official report or lounge close. Every entry must be in $pool; the
     * caller has already validated that.
     *
     * @param  array<int, array{user_id: int, attended: bool, excuse_status?: ?string, excuse_reason?: ?string}>  $attendance
     */
    public function markAttendance(Meeting $meeting, array $attendance, User $by): void
    {
        foreach ($attendance as $entry) {
            $row = $meeting->participants()->firstOrNew(['user_id' => (int) $entry['user_id']]);
            if (! $row->exists) {
                $row->source = MeetingParticipant::SOURCE_ROSTER;
            }
            $row->status = $entry['attended'] ? MeetingParticipant::STATUS_ATTENDED : MeetingParticipant::STATUS_ABSENT;
            if (! $entry['attended']) {
                $row->excuse_status = $entry['excuse_status'] ?? null;
                $row->excuse_reason = $entry['excuse_reason'] ?? null;
            } else {
                $row->excuse_status = null;
                $row->excuse_reason = null;
            }
            $row->marked_by = $by->id;
            $row->save();
        }
        $meeting->unsetRelation('participants');
    }

    // ── Summaries ────────────────────────────────────────────────────────────

    /**
     * @return array{invited: int, joined: int, attended: int, absent: int}
     */
    public function summary(Meeting $meeting): array
    {
        $rows = $meeting->relationLoaded('participants') ? $meeting->participants : $meeting->participants()->get();

        return [
            'invited'  => $rows->count(),
            'joined'   => $rows->where('status', MeetingParticipant::STATUS_JOINED)->count(),
            'attended' => $rows->where('status', MeetingParticipant::STATUS_ATTENDED)->count(),
            'absent'   => $rows->where('status', MeetingParticipant::STATUS_ABSENT)->count(),
        ];
    }

    /**
     * Counts for a page of reports in one query: meeting_id => {invited, attended, absent}.
     *
     * @param  array<int, int>  $meetingIds
     * @return array<int, array{invited: int, attended: int, absent: int}>
     */
    public function summariesFor(array $meetingIds): array
    {
        if ($meetingIds === []) {
            return [];
        }

        $out = [];
        foreach (MeetingParticipant::query()->whereIn('meeting_id', $meetingIds)->get(['meeting_id', 'status']) as $p) {
            $out[$p->meeting_id] ??= ['invited' => 0, 'attended' => 0, 'absent' => 0];
            $out[$p->meeting_id]['invited']++;
            if (in_array($p->status, [MeetingParticipant::STATUS_ATTENDED, MeetingParticipant::STATUS_JOINED], true)) {
                $out[$p->meeting_id]['attended']++;
            } elseif ($p->status === MeetingParticipant::STATUS_ABSENT) {
                $out[$p->meeting_id]['absent']++;
            }
        }

        return $out;
    }
}
