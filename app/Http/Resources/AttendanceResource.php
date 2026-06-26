<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $session = $this->relationLoaded('session') ? $this->session : null;
        $course  = $session?->course ?? $session?->workshop?->course ?? null;

        return [
            'id'              => $this->id,
            'session_id'      => $this->session_id,
            'user_id'         => $this->user_id,
            'status'          => $this->status,
            'checked_in_at'   => $this->checked_in_at,
            'attendance_date' => $this->checked_in_at
                ? \Illuminate\Support\Carbon::parse($this->checked_in_at)->toDateString()
                : null,
            'notes'           => $this->notes,
            // display-ready fields for admin views
            'session_title'   => $session?->title ?? $session?->name ?? null,
            'session_date'    => $session?->session_date?->toDateString()
                ?? ($session?->start_at ? \Illuminate\Support\Carbon::parse($session->start_at)->toDateString() : null),
            'course_title'    => $course?->title ?? null,
            'user_name'       => $this->whenLoaded('user', fn () => $this->user?->name),
            'user'            => UserResource::make($this->whenLoaded('user')),
            // nested relations for frontend fallback
            'session'         => $session ? [
                'id'       => $session->id,
                'title'    => $session->title ?? $session->name ?? null,
                'start_at' => $session->start_at ?? null,
                'end_at'   => $session->end_at ?? null,
            ] : null,
            'course'          => $course ? [
                'id'    => $course->id,
                'title' => $course->title,
            ] : null,
        ];
    }
}
