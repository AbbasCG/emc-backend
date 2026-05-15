<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LmsAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'workshop_id' => $this->workshop_id,
            'title' => $this->title,
            'description' => $this->description,
            'due_date' => $this->due_date,
            'max_score' => $this->max_score,
            'status' => $this->status,
            'submissions' => AssignmentSubmissionResource::collection($this->whenLoaded('submissions')),
            'created_at' => $this->created_at,
        ];
    }
}
