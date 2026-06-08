<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OralAssessmentSlotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'starts_at'      => $this->starts_at->toIso8601String(),
            'ends_at'        => $this->ends_at->toIso8601String(),
            'duration_mins'  => (int) $this->starts_at->diffInMinutes($this->ends_at),
            'booking_status' => $this->booking_status,
            'meeting_link'   => $this->meeting_link,
            'instructor'     => $this->whenLoaded('instructor', fn () => [
                'id'    => $this->instructor->id,
                'name'  => $this->instructor->name,
                'title' => $this->instructor->title,
            ]),
        ];
    }
}
