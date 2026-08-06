<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'title'         => $this->title,
            'description'   => $this->description,
            'type'          => $this->type,
            'status'        => $this->status,
            'meeting_date'  => $this->meeting_date,
            'start_time'    => $this->start_time,
            'end_time'      => $this->end_time,
            'meeting_url'   => $this->meeting_url,
            'recording_url' => $this->recording_url,
            'agenda'        => $this->agenda,
            'minutes'       => $this->minutes,
            'attendees'     => $this->attendees,
            'files'         => $this->files,
            'department'    => $this->whenLoaded('department', fn () => [
                'id'   => $this->department->id,
                'name' => $this->department->name,
            ]),
            'organizer'     => $this->whenLoaded('organizer', fn () => [
                'id'   => $this->organizer->id,
                'name' => $this->organizer->name,
            ]),
            'decisions'     => MeetingDecisionResource::collection($this->whenLoaded('decisions')),
            'action_items'  => MeetingActionItemResource::collection($this->whenLoaded('actionItems')),
            'created_at'    => $this->created_at,
        ];
    }
}
