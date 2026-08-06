<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LmsEvaluationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'user_id'              => $this->user_id,
            'course_id'            => $this->course_id,
            'workshop_id'          => $this->workshop_id,
            'instructor_id'        => $this->instructor_id,
            'rating'               => $this->rating,
            'content_quality'      => $this->content_quality,
            'instructor_quality'   => $this->instructor_quality,
            'organization_quality' => $this->organization_quality,
            'comment'              => $this->comment,
            'course_title'         => $this->whenLoaded('course', fn () => $this->course?->title),
            'student_name'         => $this->whenLoaded('user', fn () => $this->user?->name),
            'submitted_at'         => $this->created_at?->toIso8601String(),
            'created_at'           => $this->created_at?->toIso8601String(),
        ];
    }
}
