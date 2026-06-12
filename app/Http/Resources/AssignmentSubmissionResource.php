<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class AssignmentSubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'assignment_id' => $this->assignment_id,
            'user_id'       => $this->user_id,

            // File: always expose a public URL if a file path is stored
            'file_path'     => $this->file_path,
            'file_url'      => $this->file_path
                ? Storage::disk('public')->url($this->file_path)
                : null,

            // Text answer
            'text_answer'   => $this->text_answer,

            // Student notes attached at submission time
            'notes'         => $this->notes ?? null,

            // Grading (score exposed as both `score` and `grade`)
            'score'         => $this->score,
            'grade'         => $this->score,

            // Instructor feedback
            'feedback'      => $this->feedback,

            // Timestamps & status
            'submitted_at'  => $this->submitted_at?->toIso8601String(),
            'status'        => $this->status,

            // Relations
            'user'          => UserResource::make($this->whenLoaded('user')),
            'assignment'    => LmsAssignmentResource::make($this->whenLoaded('assignment')),

            // Convenience: course title from the loaded assignment relation
            'course_title'  => $this->whenLoaded('assignment', fn () => $this->assignment?->course?->title),
        ];
    }
}
