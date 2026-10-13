<?php

namespace App\Http\Resources;

use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Services\MeetingAccessService;
use App\Services\MeetingParticipantService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One meeting, in one shape for the admin schedule, the lounge and the detail page.
 *
 * Besides the stored fields it carries what the UI needs to act without
 * guessing: whether the single official report exists, a single plain
 * `display_status` for the badge, the audience kind, the viewer's own
 * attendance state, and the viewer's `capabilities` computed by
 * MeetingAccessService (the same checks the write endpoints enforce).
 *
 * Privacy is decided here, once:
 *  - the join link only for someone assigned to (or managing) the meeting;
 *  - participant names and the attendance summary only for who manages the
 *    meeting or has oversight (quality); everyone else assigned sees only their own state;
 *  - the official report, and the decisions/action items recorded with it, only
 *    for who manages the meeting or has oversight.
 *
 * Dates are plain `YYYY-MM-DD` / `HH:MM` strings (wall-clock time of the meeting).
 *
 * @mixin Meeting
 */
class MeetingResource extends JsonResource
{
    /** Include participants, attendance summary, decisions and action items (details view). */
    public bool $detailed = false;

    public function detailed(): static
    {
        $this->detailed = true;

        return $this;
    }

    /**
     * scheduled | in_progress | awaiting_report | report_submitted | cancelled.
     * "awaiting_report" = held (or due) and no report yet.
     */
    public static function displayStatus(Meeting $m, bool $hasReport): string
    {
        if ($m->status === 'cancelled') {
            return 'cancelled';
        }
        if ($hasReport) {
            return 'report_submitted';
        }
        if ($m->status === 'in_progress') {
            return 'in_progress';
        }
        if ($m->status === 'completed') {
            return 'awaiting_report';
        }

        $date = $m->meeting_date;
        $due = $date !== null && $date->copy()->endOfDay()->isPast();

        return $due ? 'awaiting_report' : 'scheduled';
    }

    public function toArray(Request $request): array
    {
        $user = $request->user();
        $access = MeetingAccessService::for($request);

        $report = $this->relationLoaded('report') ? $this->report : null;
        $hasReport = $report !== null;

        $assigned = $user ? $access->isAssigned($user, $this->resource) : false;
        $canViewReport = $user ? $access->canViewReport($user, $this->resource, $hasReport) : false;
        $canManage = $user ? $access->canManage($user, $this->resource) : false;
        $canAttendance = $user ? $access->canViewAttendance($user, $this->resource) : false;
        $canJoin = $user ? $access->canJoin($user, $this->resource) : false;

        $mine = $user && $this->relationLoaded('participants')
            ? $this->participants->firstWhere('user_id', $user->id)
            : null;
        $explicit = $this->hasExplicitAudience();
        $audience = $explicit ? 'explicit' : ($this->department_id !== null ? 'department' : 'general');

        $date = $this->meeting_date?->toDateString();
        $start = $this->start_time ? substr((string) $this->start_time, 0, 5) : null;
        $end = $this->end_time ? substr((string) $this->end_time, 0, 5) : null;

        $data = [
            'id'            => $this->id,
            'title'         => $this->title,
            'description'   => $this->description,
            'type'          => $this->type,
            'status'        => $this->status,
            'display_status' => self::displayStatus($this->resource, $hasReport),
            'meeting_date'  => $date,
            'start_time'    => $start,
            'end_time'      => $end,
            'starts_at'     => $date ? trim($date.' '.($start ?? '')) : null,
            'timezone'      => 'Europe/Amsterdam',
            'meeting_url'   => $assigned ? $this->meeting_url : null,
            'recording_url' => $assigned ? $this->recording_url : null,
            'agenda'        => is_array($this->agenda) ? array_values(array_filter(array_map(
                fn ($a) => is_string($a) ? trim($a) : (is_array($a) ? trim((string) ($a['title'] ?? $a['text'] ?? '')) : ''),
                $this->agenda,
            ), fn ($a) => $a !== '')) : [],
            'department'    => $this->whenLoaded('department', fn () => $this->department
                ? ['id' => $this->department->id, 'name' => $this->department->name_ar ?: $this->department->name]
                : null),
            'organizer'     => $this->whenLoaded('organizer', fn () => $this->organizer
                ? ['id' => $this->organizer->id, 'name' => $this->organizer->name]
                : null),

            'audience'      => $audience,
            'my_attendance' => $mine ? [
                'status'       => $mine->status,
                'joined_at'    => $mine->joined_at?->toIso8601String(),
                'confirmed_at' => $mine->confirmed_at?->toIso8601String(),
            ] : null,

            'has_report'    => $hasReport,
            'report_id'     => $canViewReport ? $report?->id : null,
            'capabilities'  => [
                'can_view'                => true,
                'can_join'                => $canJoin,
                'can_confirm_attendance'  => $canJoin && ($mine?->confirmed_at === null)
                    && ! in_array($mine?->status, [MeetingParticipant::STATUS_ATTENDED, MeetingParticipant::STATUS_ABSENT], true),
                'can_manage'              => $canManage,
                'can_upload_report'       => $user ? $access->canUploadReport($user, $this->resource, $hasReport) : false,
                'can_view_report'         => $canViewReport,
                'can_view_attendance'     => $canAttendance,
                'can_view_quality_data'   => $user ? $access->hasOversight($user) : false,
            ],

            'created_at'    => $this->created_at?->toIso8601String(),
            'updated_at'    => $this->updated_at?->toIso8601String(),
        ];

        if (! $this->detailed) {
            return $data + ['participants_count' => $canAttendance && $this->relationLoaded('participants') ? $this->participants->count() : null];
        }

        $participants = [];
        $summary = null;
        if ($canAttendance && $user) {
            $participants = $this->participantRows($user);
            $summary = [
                'invited'  => count($participants),
                'joined'   => count(array_filter($participants, fn ($p) => $p['status'] === MeetingParticipant::STATUS_JOINED)),
                'attended' => count(array_filter($participants, fn ($p) => $p['status'] === MeetingParticipant::STATUS_ATTENDED)),
                'absent'   => count(array_filter($participants, fn ($p) => $p['status'] === MeetingParticipant::STATUS_ABSENT)),
            ];
        }

        $showRecords = $hasReport ? $canViewReport : $canManage;

        return $data + [
            'participants_visible' => $canAttendance,
            'participants'         => $participants,
            'attendance_summary'   => $summary,
            'decisions'            => $showRecords && $this->relationLoaded('decisions') ? MeetingDecisionResource::collection($this->decisions)->resolve($request) : [],
            'action_items'         => $showRecords && $this->relationLoaded('actionItems') ? MeetingActionItemResource::collection($this->actionItems)->resolve($request) : [],
            'report'               => $canViewReport && $report && $report->relationLoaded('author')
                ? (new MeetingReportResource($report))->resolve($request)
                : null,
        ];
    }

    /**
     * Everyone expected at the meeting with their status: the audience (invited, or the
     * department roster) merged with whoever joined or was marked on top of that.
     *
     * @return array<int, array{user: array{id: int, name: string, department: ?string}, status: string, joined_at: ?string, confirmed_at: ?string}>
     */
    private function participantRows(\App\Models\User $viewer): array
    {
        $rows = $this->relationLoaded('participants') ? $this->participants : $this->participants()->get();
        $byUser = $rows->keyBy('user_id');
        $names = \App\Models\User::query()->whereIn('id', $rows->pluck('user_id'))->pluck('name', 'id');

        $out = [];
        // A meeting with neither a department nor invitees has no defined audience: list only who actually joined.
        $general = $this->department_id === null && ! $this->hasExplicitAudience();
        $pool = $general ? collect() : app(MeetingParticipantService::class)->poolForMeeting($this->resource, $viewer);
        foreach ($pool as $member) {
            $row = $byUser->get($member['id']);
            $out[$member['id']] = [
                'user'         => ['id' => $member['id'], 'name' => $member['name'], 'department' => $member['department']],
                'status'       => $row?->status ?? MeetingParticipant::STATUS_INVITED,
                'joined_at'    => $row?->joined_at?->toIso8601String(),
                'confirmed_at' => $row?->confirmed_at?->toIso8601String(),
            ];
        }
        foreach ($rows as $row) {
            if (! isset($out[$row->user_id])) {
                $out[$row->user_id] = [
                    'user'         => ['id' => (int) $row->user_id, 'name' => (string) ($names[$row->user_id] ?? ''), 'department' => null],
                    'status'       => $row->status,
                    'joined_at'    => $row->joined_at?->toIso8601String(),
                    'confirmed_at' => $row->confirmed_at?->toIso8601String(),
                ];
            }
        }

        return array_values($out);
    }
}
