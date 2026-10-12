<?php

namespace App\Http\Resources;

use App\Models\Meeting;
use App\Services\MeetingAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One meeting, in one shape for the admin schedule, the lounge and the detail page.
 *
 * Besides the stored fields it carries what the UI needs to act without
 * guessing: whether the single official report exists (`has_report`,
 * `report_id`), a single plain `display_status` for the badge, and the viewer's
 * `capabilities` computed by MeetingAccessService (the same checks the write
 * endpoints enforce). Dates are plain `YYYY-MM-DD` / `HH:MM` strings.
 *
 * @mixin Meeting
 */
class MeetingResource extends JsonResource
{
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
        $access = app(MeetingAccessService::class);

        $report = $this->relationLoaded('report') ? $this->report : null;
        $hasReport = $report !== null;
        $canViewReport = $user ? $access->canViewReport($user, $this->resource, $hasReport) : false;

        $date = $this->meeting_date?->toDateString();
        $start = $this->start_time ? substr((string) $this->start_time, 0, 5) : null;
        $end = $this->end_time ? substr((string) $this->end_time, 0, 5) : null;

        return [
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
            'meeting_url'   => $user && $access->canView($user, $this->resource) ? $this->meeting_url : null,
            'recording_url' => $this->recording_url,
            'agenda'        => $this->agenda,
            'minutes'       => $this->minutes,
            'attendees'     => $this->attendees,
            'files'         => $this->files,
            'department'    => $this->whenLoaded('department', fn () => $this->department
                ? ['id' => $this->department->id, 'name' => $this->department->name_ar ?: $this->department->name]
                : null),
            'organizer'     => $this->whenLoaded('organizer', fn () => $this->organizer
                ? ['id' => $this->organizer->id, 'name' => $this->organizer->name]
                : null),
            'decisions'     => MeetingDecisionResource::collection($this->whenLoaded('decisions')),
            'action_items'  => MeetingActionItemResource::collection($this->whenLoaded('actionItems')),

            'has_report'    => $hasReport,
            'report_id'     => $canViewReport ? $report?->id : null,
            // The body only on the detail view (author loaded) and only for someone allowed to read it.
            'report'        => $canViewReport && $report->relationLoaded('author')
                ? (new MeetingReportResource($report))->resolve($request)
                : null,
            'capabilities'  => [
                'can_manage'        => $user ? $access->canManage($user, $this->resource) : false,
                'can_upload_report' => $user ? $access->canUploadReport($user, $this->resource, $hasReport) : false,
                'can_view_report'   => $canViewReport,
            ],

            'created_at'    => $this->created_at?->toIso8601String(),
            'updated_at'    => $this->updated_at?->toIso8601String(),
        ];
    }
}
