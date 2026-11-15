<?php

namespace App\Services;

use App\Models\AssignmentSubmission;
use App\Models\AssignmentSubmissionFile;
use App\Services\AssignmentUploads\AssignmentSubmissionFileService;
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

        $attempt = AssignmentSubmissionAttempt::create([
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

        // Multi-file submissions: the attachment set that belongs to this attempt is COPIED (rows only; the physical
        // files are shared and never deleted) so a later resubmission can replace the current set without losing it.
        AssignmentSubmissionFile::query()
            ->where('submission_id', $submission->id)
            ->where('attempt_number', 0)
            ->get()
            ->each(fn (AssignmentSubmissionFile $f) => AssignmentSubmissionFile::create([
                'submission_id'  => $f->submission_id,
                'attempt_number' => $attempt->attempt_number,
                'original_name'  => $f->original_name,
                'stored_path'    => $f->stored_path,
                'mime'           => $f->mime,
                'extension'      => $f->extension,
                'size_bytes'     => $f->size_bytes,
                'sha256'         => $f->sha256,
            ]));

        return $attempt;
    }

    /**
     * Attachment sets of the history attempts, keyed by attempt number, already presented for the API.
     * One query for the whole history (no N+1).
     *
     * @return array<int, list<array{id:int,name:string,size:int,mime:string,download_url:string}>>
     */
    public function attachmentsByAttempt(AssignmentSubmission $submission): array
    {
        $files = app(AssignmentSubmissionFileService::class);
        $out = [];

        AssignmentSubmissionFile::query()
            ->where('submission_id', $submission->id)
            ->where('attempt_number', '>', 0)
            ->orderBy('id')
            ->get()
            ->each(function (AssignmentSubmissionFile $f) use (&$out, $files, $submission) {
                $out[$f->attempt_number][] = $files->presentFile($submission, $f);
            });

        return $out;
    }

    /** @return \Illuminate\Support\Collection<int, AssignmentSubmissionAttempt> */
    public function attempts(AssignmentSubmission $submission): \Illuminate\Support\Collection
    {
        return AssignmentSubmissionAttempt::where('assignment_submission_id', $submission->id)
            ->orderBy('attempt_number')
            ->get();
    }
}
