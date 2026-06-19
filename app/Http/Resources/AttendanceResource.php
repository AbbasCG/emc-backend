<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'session_id'    => $this->session_id,
            'user_id'       => $this->user_id,
            'status'        => $this->status,
            'checked_in_at' => $this->checked_in_at,
            'notes'         => $this->notes,
            // display-ready fields for admin views
            'session_title' => $this->whenLoaded('session', fn () => $this->session?->title),
            'session_date'  => $this->whenLoaded('session', fn () => $this->session?->session_date?->toDateString()),
            'course_title'  => $this->whenLoaded('session', fn () => $this->session?->course?->title
                ?? $this->session?->workshop?->course?->title),
            'user_name'     => $this->whenLoaded('user', fn () => $this->user?->name),
            'user'          => UserResource::make($this->whenLoaded('user')),
        ];
    }
}
