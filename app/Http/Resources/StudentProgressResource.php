<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentProgressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'user_id'     => $this->user_id,
            'course_id'   => $this->course_id,
            'track_id'    => $this->track_id,
            'progress_percentage' => $this->progress_percentage,
            'completed_sessions' => $this->completed_sessions,
            'total_sessions'  => $this->total_sessions,
            'attendance_percentage' => $this->attendance_percentage,
            'assignment_completion_percentage' => $this->assignment_completion_percentage,
            'status'      => $this->status,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'last_activity_at' => $this->updated_at?->toIso8601String(),
            // display-ready fields
            'user_name'   => $this->whenLoaded('user', fn () => $this->user?->name),
            'user_email'  => $this->whenLoaded('user', fn () => $this->user?->email),
            'course_title' => $this->whenLoaded('course', fn () => $this->course?->title),
            'course'      => CourseResource::make($this->whenLoaded('course')),
        ];
    }
}
