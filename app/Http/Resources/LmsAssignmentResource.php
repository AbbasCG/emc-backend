<?php

namespace App\Http\Resources;

use App\Support\AssignmentSubmissionStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LmsAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // The loaded `submissions` relation is filtered to the current student in StudentAssignmentController.
        $mySubmission = $this->whenLoaded('submissions', fn () => $this->submissions->first());
        $submissionModel = $this->relationLoaded('submissions') ? $this->submissions->first() : null;
        $studentStatus = AssignmentSubmissionStatus::forStudent($submissionModel, $this->resource);

        return [
            'id'              => $this->id,
            'course_id'       => $this->course_id,
            'course_assignment_id' => $this->course_assignment_id,
            'workshop_id'     => $this->workshop_id,
            'title'           => $this->title,
            'description'     => $this->description,
            'instructions'    => $this->instructions ?? null,
            'course_name'     => $this->whenLoaded('course', fn () => $this->course?->title)
                              ?: $this->whenLoaded('workshop', fn () => $this->workshop?->course?->title),
            'course_title'    => $this->whenLoaded('course', fn () => $this->course?->title)
                              ?: $this->whenLoaded('workshop', fn () => $this->workshop?->course?->title),
            'course'          => $this->when(
                $this->relationLoaded('course') || $this->relationLoaded('workshop'),
                function () {
                    $course = $this->course ?? $this->workshop?->course;
                    return $course ? ['id' => $course->id, 'title' => $course->title] : null;
                }
            ),
            'instructor_name' => $this->whenLoaded('course', fn () => $this->course?->instructor?->name ?? $this->course?->instructor?->user?->name)
                              ?: $this->whenLoaded('workshop', fn () => $this->workshop?->course?->instructor?->name ?? $this->workshop?->course?->instructor?->user?->name),
            'instructor'      => $this->when(
                $this->relationLoaded('course') || $this->relationLoaded('workshop'),
                function () {
                    $inst = ($this->course ?? $this->workshop?->course)?->instructor;
                    if (! $inst) {
                        return null;
                    }

                    return [
                        'id'    => $inst->id,
                        'name'  => $inst->user?->name ?? $inst->name,
                        'email' => $inst->user?->email,
                    ];
                }
            ),
            'assignment_status' => $this->status,
            'submissions_count' => $this->submissions_count ?? 0,
            'pending_count'   => $this->pending_submissions_count ?? $this->pending_count ?? 0,
            'pending_submissions_count' => $this->pending_submissions_count ?? $this->pending_count ?? 0,
            'graded_count'    => $this->graded_submissions_count ?? $this->graded_count ?? 0,
            'graded_submissions_count'  => $this->graded_submissions_count ?? $this->graded_count ?? 0,
            'needs_resubmission_count'  => $this->needs_resubmission_count ?? 0,

            // Deadline exposed under both the DB column name and a clean alias
            'due_date'        => $this->due_date?->toIso8601String(),
            'deadline'        => $this->due_date?->toIso8601String(),
            'due_at'          => $this->due_date?->toIso8601String(),

            'max_score'       => $this->max_score,
            'max_points'      => $this->max_score,
            'submission_type' => $this->submission_type ?? 'any',
            'status'          => $request->is('api/admin/*') ? ($this->status ?? 'active') : $studentStatus,
            'resubmission_allowed' => (bool) $this->resubmission_allowed,

            'score'           => $submissionModel?->score,
            'feedback'        => $submissionModel?->feedback,
            'submitted_at'    => $submissionModel?->submitted_at?->toIso8601String(),

            // Current student's submission (null when not yet submitted)
            'my_submission'   => $mySubmission
                ? AssignmentSubmissionResource::make($mySubmission)
                : null,

            // Full submissions list (only loaded on instructor-side endpoints)
            'submissions'     => AssignmentSubmissionResource::collection($this->whenLoaded('submissions')),

            'created_at'      => $this->created_at?->toIso8601String(),
        ];
    }
}
