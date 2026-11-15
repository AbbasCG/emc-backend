<?php

namespace App\Http\Resources;

use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\Registration;
use App\Models\StudentProgress;
use App\Support\AssignmentSubmissionStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Structured admin assignment detail: assignment, course, instructor, stats, submissions, students.
 *
 * @mixin CourseAssignment
 */
class AdminLmsAssignmentDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var CourseAssignment $assignment */
        $assignment   = $this->resource;
        $course       = $assignment->relationLoaded('course') ? $assignment->course : null;
        $instructor   = $course?->instructor;
        $instructorUser = $instructor?->user;

        $submissions = $assignment->relationLoaded('submissions')
            ? $assignment->submissions
            : collect();

        $submissionByUser = $submissions->keyBy('user_id');

        $stats = $this->buildStats($submissions);

        $submissionsList = $submissions
            ->filter(fn (AssignmentSubmission $s) => $s->submitted_at !== null)
            ->sortByDesc('submitted_at')
            ->values()
            ->map(fn (AssignmentSubmission $s) => $this->formatSubmission($s))
            ->all();

        $students = $this->buildStudentsList($course, $assignment, $submissionByUser);

        $rawStatus = $assignment->status ?? 'published';
        $adminStatus = match ($rawStatus) {
            'published' => 'active',
            'draft'     => 'archived',
            default     => $rawStatus,
        };

        return [
            'assignment' => [
                'id'                   => $assignment->id,
                'title'                => $assignment->title,
                'description'          => $assignment->description,
                'instructions'         => $assignment->instructions ?? null,
                'due_date'             => $assignment->deadline?->toIso8601String(),
                'deadline'             => $assignment->deadline?->toIso8601String(),
                'max_score'            => $assignment->max_points,
                'max_points'           => $assignment->max_points,
                'status'               => $adminStatus,
                'submission_type'      => $assignment->submission_type ?? 'any',
                'resubmission_allowed' => (bool) $assignment->resubmission_allowed,
                'course_id'            => $course?->id ?? $assignment->course_id,
                'workshop_id'          => null,
                'created_at'           => $assignment->created_at?->toIso8601String(),
            ],
            'course' => $course ? [
                'id'    => $course->id,
                'title' => $course->title,
                'slug'  => $course->slug,
            ] : null,
            'instructor' => $instructor ? [
                'id'    => $instructor->id,
                'name'  => $instructorUser?->name ?? $instructor->name,
                'email' => $instructorUser?->email,
            ] : null,
            'stats'       => $stats,
            'submissions' => $submissionsList,
            'students'    => $students,
        ];
    }

    /** @param \Illuminate\Support\Collection<int, AssignmentSubmission> $submissions */
    private function buildStats($submissions): array
    {
        $pending = 0;
        $graded  = 0;
        $needs   = 0;
        $late    = 0;

        foreach ($submissions as $sub) {
            if (! $sub->submitted_at) {
                continue;
            }
            $instStatus = AssignmentSubmissionStatus::forInstructor($sub);
            if ($instStatus === 'reviewed') {
                $graded++;
            } elseif ($instStatus === 'needs_revision') {
                $needs++;
            } elseif (in_array($instStatus, ['pending_review', 'resubmitted'], true)) {
                $pending++;
            }
            if ($sub->status === 'late') {
                $late++;
            }
        }

        return [
            'submissions_count'          => $submissions->whereNotNull('submitted_at')->count(),
            'pending_submissions_count'  => $pending,
            'graded_submissions_count'   => $graded,
            'needs_resubmission_count'   => $needs,
            'late_submissions_count'     => $late,
        ];
    }

    private function formatSubmission(AssignmentSubmission $s): array
    {
        $instStatus = AssignmentSubmissionStatus::forInstructor($s);

        return [
            'id'            => $s->id,
            'user_id'       => $s->user_id,
            'student_name'  => $s->user?->name,
            'student_email' => $s->user?->email,
            'submitted_at'  => $s->submitted_at?->toIso8601String(),
            'status'        => $instStatus,
            'score'         => $s->score,
            'grade'         => $s->score,
            'feedback'      => $s->feedback,
            'has_file'      => ! empty($s->file_path),
            'has_text'      => ! empty(trim((string) $s->text_answer)),
            'text_answer'   => $s->text_answer,
            'notes'         => $s->notes,
            'file_url'      => $s->file_path ? route('submissions.download', $s->id) : null,
            // Every file of the current attempt (a legacy single-file submission is one attachment with id "legacy").
            'attachments'   => app(\App\Services\AssignmentUploads\AssignmentSubmissionFileService::class)->attachmentsFor($s),
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int, AssignmentSubmission> $submissionByUser
     */
    private function buildStudentsList(?Course $course, CourseAssignment $assignment, $submissionByUser): array
    {
        if (! $course) {
            return [];
        }

        $registrations = Registration::query()
            ->with('user')
            ->where('course_id', $course->id)
            ->whereNotIn('status', ['cancelled'])
            ->latest()
            ->get();

        $userIds = $registrations->pluck('user_id')->filter()->values()->all();
        $progressMap = StudentProgress::query()
            ->where('course_id', $course->id)
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy('user_id');

        $duePast = $assignment->deadline && $assignment->deadline->isPast();

        return $registrations->map(function (Registration $reg) use ($submissionByUser, $progressMap, $duePast) {
            $user = $reg->user;
            $uid  = $user?->id ?? $reg->user_id;
            $sub  = $uid ? $submissionByUser->get($uid) : null;
            $hasSubmitted = $sub && $sub->submitted_at;
            $instStatus   = $sub ? AssignmentSubmissionStatus::forInstructor($sub) : 'not_submitted';
            $isLate       = ! $hasSubmitted && $duePast;

            return [
                'user_id'             => $uid,
                'name'                => $user?->name ?? $reg->full_name,
                'email'               => $user?->email ?? $reg->email,
                'registration_status' => $reg->status,
                'submitted'           => (bool) $hasSubmitted,
                'submission_status'   => $instStatus,
                'submitted_at'        => $sub?->submitted_at?->toIso8601String(),
                'score'               => $sub?->score,
                'is_late'             => $isLate || $instStatus === 'late',
                'progress_percentage' => $uid ? ($progressMap->get($uid)?->progress_percentage ?? 0) : 0,
            ];
        })->values()->all();
    }
}
