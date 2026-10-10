<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $startsAt = trim(($this->meeting_date ?? '') . ' ' . ($this->start_time ?? ''));

        return [
            'id'              => $this->id,
            'title'           => $this->title,
            'description'     => $this->description,
            'type'            => $this->type,
            'status'          => $this->status,
            'meeting_date'    => $this->meeting_date,
            'start_time'      => $this->start_time,
            'end_time'        => $this->end_time,
            'starts_at'       => $startsAt !== '' ? $startsAt : null,
            'meeting_url'     => $this->meeting_url,
            'recording_url'   => $this->recording_url,
            'agenda'          => $this->agenda,
            'minutes'         => $this->minutes,
            'attendees'       => $this->attendees,
            'files'           => $this->files,
            'department_id'   => $this->department_id,
            'department_name' => $this->relationLoaded('department') && $this->department ? ($this->department->name_ar ?: $this->department->name) : ($this->department?->name_ar ?: $this->department?->name),
            'organizer_name'  => $this->relationLoaded('organizer') && $this->organizer ? $this->organizer->name : $this->organizer?->name,
            'department'      => $this->whenLoaded('department', fn () => [
                'id'   => $this->department->id,
                'name' => $this->department->name_ar ?: $this->department->name,
            ]),
            'organizer'       => $this->whenLoaded('organizer', fn () => [
                'id'   => $this->organizer->id,
                'name' => $this->organizer->name,
            ]),
            'decisions'       => MeetingDecisionResource::collection($this->whenLoaded('decisions')),
            'action_items'    => MeetingActionItemResource::collection($this->whenLoaded('actionItems')),
            'report'          => new MeetingReportResource($this->whenLoaded('report')),
            'created_at'      => $this->created_at,
        ];
    }
}
