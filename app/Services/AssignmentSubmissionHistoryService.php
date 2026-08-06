<?php

namespace App\Services;

use App\Models\AssignmentSubmission;
use App\Models\AssignmentSubmissionAttempt;

/**
 * Ticket 5: preserves every prior state of an AssignmentSubmission before it
 * gets overwritten by a resubmission. `assignment_submissions` remains the
 * single "current" row (unchanged schema/behavior); this service snapshots
 * that row into `assignment_submission_attempts` immediately before any
 * caller overwrites it, so no attempt is ever lost.
 */
class AssignmentSubmissionHistoryService
{
    /** Snapshots the submission's CURRENT state (before the caller applies new data). */
    public function snapshot(AssignmentSubmission $submission): AssignmentSubmissionAttempt
    {
        $nextAttemptNumber = (int) (AssignmentSubmissionAttempt::where('assignment_submission_id', $submission->id)->max('attempt_number') ?? 0) + 1;

        return AssignmentSubmissionAttempt::create([
            'assignment_submission_id' => $submission->id,
            'attempt_number' => $nextAttemptNumber,
            'file_path'      => $submission->file_path,
            'text_answer'    => $submission->text_answer,
            'notes'          => $submission->notes,
            'score'          => $submission->score,
            'feedback'       => $submission->feedback,
            'status'         => $submission->status,
            'submitted_at'   => $submission->submitted_at,
            'reviewed_at'    => $submission->reviewed_at,
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, AssignmentSubmissionAttempt> */
    public function attempts(AssignmentSubmission $submission): \Illuminate\Support\Collection
    {
        return AssignmentSubmissionAttempt::where('assignment_submission_id', $submission->id)
            ->orderBy('attempt_number')
            ->get();
    }
}
