<?php

namespace App\Http\Resources;

use App\Services\AssignmentUploads\AssignmentSubmissionFileService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssignmentSubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'assignment_id' => $this->assignment_id,
            'user_id'       => $this->user_id,

            // File: served through an authorized download endpoint —
            // never expose the raw storage path or a direct public URL.
            'file_url'      => $this->file_path
                ? route('submissions.download', $this->id)
                : null,

            // Every file of the CURRENT attempt (multi-file submissions). A legacy single-file submission appears as
            // one attachment with id "legacy". Download only through the authorized `download_url`.
            'attachments'   => app(AssignmentSubmissionFileService::class)->attachmentsFor($this->resource),

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
