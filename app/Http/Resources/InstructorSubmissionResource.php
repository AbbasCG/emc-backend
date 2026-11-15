<?php

namespace App\Http\Resources;

use App\Services\AssignmentUploads\AssignmentSubmissionFileService;
use App\Support\AssignmentSubmissionStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Instructor assignment review queue — flat + nested fields for dashboard UI.
 */
class InstructorSubmissionResource extends JsonResource
{
    /** @var array<int, array{id:int,title:string,slug:string}|null>|null */
    public static ?array $learningPathByCourseId = null;

    public function toArray(Request $request): array
    {
        $assignment = $this->relationLoaded('assignment') ? $this->assignment : null;
        $course     = $assignment?->relationLoaded('course') ? $assignment->course : null;
        $workshop   = $assignment?->relationLoaded('workshop') ? $assignment->workshop : null;
        $user       = $this->relationLoaded('user') ? $this->user : null;

        $courseId = $course?->id ?? $assignment?->course_id ?? $assignment?->workshop?->course_id;
        $lp       = ($courseId && self::$learningPathByCourseId !== null)
            ? (self::$learningPathByCourseId[(int) $courseId] ?? null)
            : null;

        $instructorStatus = AssignmentSubmissionStatus::forInstructor($this->resource, $assignment);
        $textAnswer       = $this->text_answer;
        // Use the authorized download endpoint (handles private disk + public fallback).
        $fileUrl          = $this->file_path
            ? route('submissions.download', $this->id)
            : null;

        $studentName = $user?->name ?? '';
        $studentEmail = $user?->email ?? null;
        $avatar = $user?->avatar_url ?? null;

        return [
            'id'            => $this->id,
            'assignment_id' => $this->assignment_id,
            'user_id'       => $this->user_id,
            'student_id'    => $this->user_id,

            'status'         => $instructorStatus,
            'review_status'  => $this->status,
            'submitted_at'   => $this->submitted_at?->toIso8601String(),
            'score'          => $this->score !== null ? (float) $this->score : null,
            'grade'          => $this->score !== null ? (float) $this->score : null,
            'max_score'      => $assignment?->max_score !== null ? (float) $assignment->max_score : null,
            'feedback'       => $this->feedback,
            'body_preview'   => $textAnswer ? Str::limit($textAnswer, 160) : null,
            'body_text'      => $textAnswer,
            'text_answer'    => $textAnswer,
            'notes'          => $this->notes,
            // The raw storage path is internal and never serialised; use `file_url` / `attachments`.
            'has_file'       => $this->file_path !== null || $this->relationLoaded('currentFiles') && $this->currentFiles->isNotEmpty(),
            'file_url'       => $fileUrl,
            // Every file of the current attempt; a legacy single-file submission is one attachment with id "legacy".
            'attachments'    => app(AssignmentSubmissionFileService::class)->attachmentsFor($this->resource),

            // Flat aliases consumed by the frontend normalizer
            'student_name'     => $studentName,
            'assignment_title' => $assignment?->title ?? '',
            'course_name'      => $course?->title ?? $workshop?->title,

            'student' => [
                'id'     => $this->user_id,
                'name'   => $studentName,
                'email'  => $studentEmail,
                'avatar' => $avatar,
            ],

            'assignment' => $assignment ? [
                'id'        => $assignment->id,
                'title'     => $assignment->title,
                'max_score' => $assignment->max_score,
                'due_date'  => $assignment->due_date?->toIso8601String(),
            ] : null,

            'course' => $course ? [
                'id'    => $course->id,
                'title' => $course->title,
                'slug'  => $course->slug,
            ] : ($courseId ? ['id' => (int) $courseId, 'title' => null, 'slug' => null] : null),

            'workshop' => $workshop ? [
                'id'    => $workshop->id,
                'title' => $workshop->title ?? null,
                'slug'  => $workshop->slug  ?? null,
            ] : ($assignment?->workshop_id ? ['id' => $assignment->workshop_id, 'title' => null, 'slug' => null] : null),

            'learning_path'   => $lp,
            'reviewed_at'     => $this->reviewed_at?->toIso8601String() ?? null,
            'resubmitted_at'  => $this->resubmitted_at?->toIso8601String() ?? null,
            'reviewed_by'     => null,

            // Legacy nested payloads (backward compatible)
            'user'         => UserResource::make($this->whenLoaded('user')),
            'course_title' => $course?->title,
        ];
    }
}
