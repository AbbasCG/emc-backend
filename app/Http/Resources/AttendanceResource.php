<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->session_id,
            'user_id' => $this->user_id,
            'status' => $this->status,
            'checked_in_at' => $this->checked_in_at,
            'notes' => $this->notes,
            'user' => UserResource::make($this->whenLoaded('user')),
        ];
    }
}
