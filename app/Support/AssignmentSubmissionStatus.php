<?php

namespace App\Support;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use Carbon\Carbon;

class AssignmentSubmissionStatus
{
    /**
     * Derive student-facing status: pending | submitted | late | graded
     */
    public static function forStudent(?AssignmentSubmission $submission, mixed $assignment = null): string
    {
        if (! $submission || ! $submission->submitted_at) {
            return 'pending';
        }

        $raw = strtolower((string) $submission->status);

        if (in_array($raw, ['graded', 'reviewed'], true)) {
            return 'graded';
        }

        // Instructor requested a new submission — student must resubmit.
        if ($raw === 'needs_revision') {
            return 'needs_resubmission';
        }

        if ($raw === 'late') {
            return 'late';
        }

        // Resolve deadline from either Assignment (due_date) or CourseAssignment (deadline)
        $due = $assignment?->due_date ?? $assignment?->deadline ?? $submission->assignment?->due_date ?? null;
        if ($due && Carbon::parse($submission->submitted_at)->gt(Carbon::parse($due))) {
            return 'late';
        }

        return 'submitted';
    }

    /**
     * Status stored when a student submits.
     */
    public static function onSubmit(Assignment $assignment): string
    {
        if ($assignment->due_date && now()->gt($assignment->due_date)) {
            return 'late';
        }

        return 'submitted';
    }

    /**
     * Instructor review queue status: pending_review | reviewed | needs_revision | not_submitted
     */
    public static function forInstructor(AssignmentSubmission $submission, ?Assignment $assignment = null): string
    {
        if (! $submission->submitted_at) {
            return 'not_submitted';
        }

        $raw = strtolower((string) $submission->status);

        if (in_array($raw, ['graded', 'reviewed'], true)) {
            return 'reviewed';
        }

        if ($raw === 'needs_revision') {
            return 'needs_revision';
        }

        // Student resubmitted after instructor requested revision — show distinct status.
        if (! is_null($submission->resubmitted_at)) {
            return 'resubmitted';
        }

        return 'pending_review';
    }
}
