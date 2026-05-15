<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LmsSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workshop_id' => $this->workshop_id,
            'course_id' => $this->course_id,
            'instructor_id' => $this->instructor_id,
            'title' => $this->title,
            'description' => $this->description,
            'session_date' => $this->session_date?->toDateString(),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'meeting_url' => $this->meeting_url,
            'recording_url' => $this->recording_url,
            'status' => $this->status,
            'workshop' => WorkshopResource::make($this->whenLoaded('workshop')),
            'instructor' => InstructorResource::make($this->whenLoaded('instructor')),
            'attendances' => AttendanceResource::collection($this->whenLoaded('attendances')),
            'created_at' => $this->created_at,
        ];
    }
}
