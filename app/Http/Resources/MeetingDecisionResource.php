<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingDecisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'meeting_id'  => $this->meeting_id,
            'title'       => $this->title,
            'description' => $this->description,
            'decided_by'  => $this->whenLoaded('decidedBy', fn () => [
                'id'   => $this->decidedBy->id,
                'name' => $this->decidedBy->name,
            ]),
            'created_at'  => $this->created_at,
        ];
    }
}
