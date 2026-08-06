<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RegistrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'user_id'       => $this->user_id,
            // Explicit boolean — never send "NULL" as a string to the frontend.
            'has_account'   => $this->user_id !== null,
            'course_id'     => $this->course_id,
            'course_title'  => $this->whenLoaded('course', fn () => $this->course?->title),
            'course_slug'   => $this->whenLoaded('course', fn () => $this->course?->slug),
            'full_name'     => $this->full_name,
            'email'         => $this->email,
            'phone'         => $this->phone,
            'city'          => $this->city,
            'gender'        => $this->gender,
            'notes'         => $this->notes,
            'status'        => $this->status,
            'registered_at' => $this->created_at,
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at,
            // Nested relationships — only present when eager-loaded
            'user'          => $this->whenLoaded('user', fn () => $this->user ? [
                'id'    => $this->user->id,
                'name'  => $this->user->name,
                'email' => $this->user->email,
                'phone' => $this->user->phone ?? null,
            ] : null),
            'course'        => $this->whenLoaded('course', fn () => new CourseResource($this->course)),
            'payments'      => PaymentResource::collection($this->whenLoaded('payments')),
        ];
    }
}
