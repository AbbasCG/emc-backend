<?php

namespace App\Http\Resources;

use App\Models\AssignmentSubmission;
use App\Services\CourseAssignmentBridgeService;
use App\Support\AssignmentSubmissionStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentCourseAssignmentResource extends JsonResource
{
    /** Request attribute holding the student's submissions keyed by LMS assignment id (set by StudentAssignmentController::index). */
    public const PRELOADED_SUBMISSIONS = 'student_submissions_by_assignment';

    /**
     * The student's own submission (with its attachments) for every given course assignment, keyed by LMS assignment id, in TWO
     * queries however many rows there are. Callers that serialise a list hand the result to the resource via
     * {@see PRELOADED_SUBMISSIONS}; an LMS assignment that does not exist yet simply has no entry (= no submission).
     *
     * @param iterable<\App\Models\CourseAssignment> $courseAssignments
     * @return \Illuminate\Support\Collection<int, AssignmentSubmission>
     */
    public static function submissionsFor(\App\Models\User $user, iterable $courseAssignments): \Illuminate\Support\Collection
    {
        $lmsIds = \App\Models\Assignment::query()
            ->whereIn('course_assignment_id', collect($courseAssignments)->pluck('id'))
            ->pluck('id');

        return AssignmentSubmission::query()
            ->with('currentFiles')
            ->where('user_id', $user->id)
            ->whereIn('assignment_id', $lmsIds)
            ->get()
            ->keyBy('assignment_id');
    }

    public function toArray(Request $request): array
    {
        $lmsAssignment = app(CourseAssignmentBridgeService::class)->ensureLmsAssignment($this->resource);

        // The list endpoint preloads every row's submission (+ attachments) in two queries; a single-assignment call
        // falls back to one query that also loads the attachments.
        $preloaded = $request->attributes->get(self::PRELOADED_SUBMISSIONS);
        $submission = $preloaded !== null
            ? $preloaded->get($lmsAssignment->id)
            : AssignmentSubmission::query()
                ->with('currentFiles')
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
