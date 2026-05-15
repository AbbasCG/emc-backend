<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'email'       => $this->email,
            'type'        => $this->type,
            'priority'    => $this->priority,
            'status'      => $this->status,
            'subject'     => $this->subject,
            'message'     => $this->message,
            'assigned_to' => $this->whenLoaded('assignedTo', fn () => [
                'id'   => $this->assignedTo?->id,
                'name' => $this->assignedTo?->name,
            ]),
            'replies'     => SupportTicketReplyResource::collection($this->whenLoaded('replies')),
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
        ];
    }
}
