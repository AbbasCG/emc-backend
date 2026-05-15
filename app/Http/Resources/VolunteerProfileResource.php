<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VolunteerProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'status'            => $this->status,
            'skills'            => $this->skills,
            'availability'      => $this->availability,
            'hours_logged'      => $this->hours_logged,
            'onboarding_status' => $this->onboarding_status,
            'notes'             => $this->notes,
            'user'              => $this->whenLoaded('user', fn () => [
                'id'    => $this->user->id,
                'name'  => $this->user->name,
                'email' => $this->user->email,
            ]),
            'department'        => $this->whenLoaded('department', fn () => [
                'id'   => $this->department?->id,
                'name' => $this->department?->name,
            ]),
            'section'           => $this->whenLoaded('section', fn () => [
                'id'   => $this->section?->id,
                'name' => $this->section?->name,
            ]),
            'created_at'        => $this->created_at,
        ];
    }
}
