<?php

namespace App\Http\Resources;

use App\Models\AssignmentSubmission;
use App\Services\CourseAssignmentBridgeService;
use App\Support\AssignmentSubmissionStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentCourseAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $lmsAssignment = app(CourseAssignmentBridgeService::class)->ensureLmsAssignment($this->resource);

        $submission = AssignmentSubmission::query()
            ->where('assignment_id', $lmsAssignment->id)
            ->where('user_id', $request->user()?->id)
            ->first();

        return [
            'id'                   => $this->id,
            'course_id'            => $this->course_id,
            'module_id'            => $this->module_id,
            'course_session_id'    => $this->course_session_id,
            'assignment_id'        => $lmsAssignment->id,
            'lms_assignment_id'    => $lmsAssignment->id,
            'course_assignment_id' => $this->id,
            'title'                => $this->title,
            'description'          => $this->description,
            'instructions'         => $this->instructions,
            'deadline'             => $this->deadline?->toIso8601String(),
            'due_at'               => $this->deadline?->toIso8601String(),
            'max_points'           => $this->max_points,
            'submission_type'      => $this->submission_type,
            'is_required'          => $this->is_required,
            'resubmission_allowed' => (bool) $this->resubmission_allowed,
            'is_visible'           => $this->is_visible,
            'status'               => AssignmentSubmissionStatus::forStudent($submission, $lmsAssignment),
            'score'                => $submission?->score,
            'feedback'             => $submission?->feedback,
            'submitted_at'         => $submission?->submitted_at?->toIso8601String(),
            'my_submission'        => $submission
                ? AssignmentSubmissionResource::make($submission)
                : null,
            'sort_order'           => $this->sort_order,
        ];
    }
}
