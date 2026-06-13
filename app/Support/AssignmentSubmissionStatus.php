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
    public static function forStudent(?AssignmentSubmission $submission, ?Assignment $assignment = null): string
    {
        if (! $submission || ! $submission->submitted_at) {
            if ($assignment?->due_date && Carbon::parse($assignment->due_date)->isPast()) {
                return 'pending';
            }

            return 'pending';
        }

        $raw = strtolower((string) $submission->status);

        if (in_array($raw, ['graded', 'reviewed'], true)) {
            return 'graded';
        }

        if ($raw === 'late') {
            return 'late';
        }

        $due = $assignment?->due_date ?? $submission->assignment?->due_date;
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

        return 'pending_review';
    }
}
