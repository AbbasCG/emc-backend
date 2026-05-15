<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RegistrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'course_id'  => $this->course_id,
            'full_name'  => $this->full_name,
            'email'      => $this->email,
            'phone'      => $this->phone,
            'city'       => $this->city,
            'gender'     => $this->gender,
            'notes'      => $this->notes,
            'status'     => $this->status,
            // Nested relationships — only present when eager-loaded
            'course'     => CourseResource::make($this->whenLoaded('course')),
            'payments'   => PaymentResource::collection($this->whenLoaded('payments')),
            'created_at' => $this->created_at,
        ];
    }
}
