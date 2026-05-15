<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssignmentSubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'assignment_id' => $this->assignment_id,
            'user_id' => $this->user_id,
            'file_path' => $this->file_path,
            'text_answer' => $this->text_answer,
            'score' => $this->score,
            'feedback' => $this->feedback,
            'submitted_at' => $this->submitted_at,
            'status' => $this->status,
            'user' => UserResource::make($this->whenLoaded('user')),
            'assignment' => LmsAssignmentResource::make($this->whenLoaded('assignment')),
        ];
    }
}
