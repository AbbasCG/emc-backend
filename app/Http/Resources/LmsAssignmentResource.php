<?php

namespace App\Http\Resources;

use App\Models\CourseAssignment;
use App\Support\AssignmentSubmissionStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LmsAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Support both Assignment (due_date, max_score) and CourseAssignment (deadline, max_points)
        $isCa     = $this->resource instanceof CourseAssignment;
        $dueDate  = $this->due_date ?? $this->deadline;
        $maxScore = $this->max_score ?? $this->max_points;
        $caId     = $isCa ? $this->id : ($this->course_assignment_id ?? null);
        $workshopId = $isCa ? null : ($this->workshop_id ?? null);

        // Normalise admin status vocabulary: published/draft → active/archived
        $rawStatus = $this->status ?? ($isCa ? 'published' : 'active');
        $adminStatus = match ($rawStatus) {
            'published' => 'active',
            'draft'     => 'archived',
            default     => $rawStatus,
        };

        // The loaded `submissions` relation is filtered to the current student in controllers.
        $mySubmission    = $this->whenLoaded('submissions', fn () => $this->submissions->first());
        $submissionModel = $this->relationLoaded('submissions') ? $this->submissions->first() : null;
        $studentStatus   = AssignmentSubmissionStatus::forStudent($submissionModel, $this->resource);

        return [
            'id'                   => $this->id,
            'course_id'            => $this->course_id,
            'course_assignment_id' => $caId,
            'workshop_id'          => $workshopId,
            'title'                => $this->title,
            'description'          => $this->description,
            'instructions'         => $this->instructions ?? null,
            'course_name'          => $this->whenLoaded('course', fn () => $this->course?->title)
                                   ?: $this->whenLoaded('workshop', fn () => $this->workshop?->course?->title),
            'course_title'         => $this->whenLoaded('course', fn () => $this->course?->title)
                                   ?: $this->whenLoaded('workshop', fn () => $this->workshop?->course?->title),
            'course'               => $this->when(
                $this->relationLoaded('course') || $this->relationLoaded('workshop'),
                function () {
                    $course = $this->course ?? $this->workshop?->course;
                    return $course ? ['id' => $course->id, 'title' => $course->title] : null;
                }
            ),
            'instructor_name'      => $this->whenLoaded('course', fn () => $this->course?->instructor?->name ?? $this->course?->instructor?->user?->name)
                                   ?: $this->whenLoaded('workshop', fn () => $this->workshop?->course?->instructor?->name ?? $this->workshop?->course?->instructor?->user?->name),
            'instructor'           => $this->when(
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
            'assignment_status'    => $adminStatus,
            'submissions_count'    => $this->submissions_count ?? 0,
            'pending_count'        => $this->pending_submissions_count ?? $this->pending_count ?? 0,
            'pending_submissions_count' => $this->pending_submissions_count ?? $this->pending_count ?? 0,
            'graded_count'         => $this->graded_submissions_count ?? $this->graded_count ?? 0,
            'graded_submissions_count'  => $this->graded_submissions_count ?? $this->graded_count ?? 0,
            'needs_resubmission_count'  => $this->needs_resubmission_count ?? 0,

            'due_date'             => $dueDate?->toIso8601String(),
            'deadline'             => $dueDate?->toIso8601String(),
            'due_at'               => $dueDate?->toIso8601String(),

            'max_score'            => $maxScore,
            'max_points'           => $maxScore,
            'submission_type'      => $this->submission_type ?? 'any',
            'status'               => $request->is('api/admin/*') ? $adminStatus : $studentStatus,
            'is_visible'           => $this->is_visible ?? true,
            'resubmission_allowed' => (bool) $this->resubmission_allowed,

            'score'                => $submissionModel?->score,
            'feedback'             => $submissionModel?->feedback,
            'submitted_at'         => $submissionModel?->submitted_at?->toIso8601String(),

            'my_submission'        => $mySubmission
                ? AssignmentSubmissionResource::make($mySubmission)
                : null,

            'submissions'          => AssignmentSubmissionResource::collection($this->whenLoaded('submissions')),

            'created_at'           => $this->created_at?->toIso8601String(),
        ];
    }
}
