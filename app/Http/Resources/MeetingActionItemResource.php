<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingActionItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'meeting_id'  => $this->meeting_id,
            'task_id'     => $this->task_id,
            'title'       => $this->title,
            'due_date'    => $this->due_date,
            'status'      => $this->status,
            'assigned_to' => $this->whenLoaded('assignedTo', fn () => [
                'id'   => $this->assignedTo->id,
                'name' => $this->assignedTo->name,
            ]),
            'created_at'  => $this->created_at,
        ];
    }
}
