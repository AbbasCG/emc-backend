<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'ticket_number'        => $this->ticket_number,
            'subject'              => $this->subject,
            'message'              => $this->message,
            'full_name'            => $this->full_name ?? $this->name,
            'name'                 => $this->name,
            'email'                => $this->email,
            'phone'                => $this->phone,
            'type'                 => $this->type,
            'request_type'         => $this->request_type,
            'priority'             => $this->priority,
            'status'               => $this->status,
            'assigned_to'          => $this->whenLoaded('assignedTo', fn () => $this->assignedTo ? [
                'id'         => $this->assignedTo->id,
                'name'       => $this->assignedTo->name,
                'email'      => $this->assignedTo->email,
                'role'       => $this->assignedTo->role,
                'department' => $this->assignedTo->department,
            ] : null),
            'replies'              => SupportTicketReplyResource::collection($this->whenLoaded('replies')),
            'replies_count'        => $this->replies_count ?? $this->whenLoaded('replies', fn () => $this->replies->count(), 0),
            'last_reply_at'        => $this->whenLoaded('replies', fn () => optional($this->replies->first())->created_at),
            'resolved_at'          => optional($this->resolved_at)->toISOString(),
            'created_at'           => optional($this->created_at)->toISOString(),
            'updated_at'           => optional($this->updated_at)->toISOString(),
        ];
    }
}
