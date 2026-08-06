<?php

namespace App\Services;

use App\Models\AssignmentSubmission;
use Illuminate\Validation\ValidationException;

/**
 * Ticket 5: single source of truth for legal AssignmentSubmission status
 * transitions — the counterpart to Ticket 4's LmsSessionTransitionService.
 *
 * The real stored status column only ever holds `submitted`, `late`,
 * `graded`, or `needs_revision` (confirmed by auditing every write site:
 * AssignmentSubmissionStatus::onSubmit()/InstructorSubmissionController::
 * review()'s status mapping) — not the ticket's full 11-label vocabulary
 * (Draft/Published/Open/.../Archived), which describes the *Assignment's*
 * lifecycle in places and doesn't match this schema's actual submission
 * states. Renaming the stored column's values to an unused vocabulary would
 * be a breaking, purely cosmetic change touching every existing consumer
 * (AssignmentSubmissionResource, InstructorSubmissionResource, the 4
 * existing frontend pages, AssignmentSubmissionStatus's own derivation
 * logic) for no behavioral gain — exactly the kind of unnecessary
 * architecture change the project rules prohibit. Instead, this service
 * formalizes and centrally enforces transitions over the REAL, existing
 * status values, the same way the transition endpoint replaced raw status
 * mass-assignment for sessions.
 */
class AssignmentSubmissionTransitionService
{
    public const STATUS_SUBMITTED      = 'submitted';
    public const STATUS_LATE           = 'late';
    public const STATUS_GRADED         = 'graded';
    public const STATUS_NEEDS_REVISION = 'needs_revision';

    /** @var array<string, array<int, string>> */
    private const MATRIX = [
        self::STATUS_SUBMITTED      => [self::STATUS_GRADED, self::STATUS_NEEDS_REVISION, self::STATUS_LATE],
        self::STATUS_LATE           => [self::STATUS_GRADED, self::STATUS_NEEDS_REVISION],
        self::STATUS_GRADED         => [self::STATUS_NEEDS_REVISION], // instructor can still request a resubmission after grading
        self::STATUS_NEEDS_REVISION => [self::STATUS_SUBMITTED, self::STATUS_LATE], // only via the student's own resubmission, not a direct review() call
    ];

    /** @return array<int, string> */
    public function allowedTransitions(AssignmentSubmission $submission): array
    {
        return self::MATRIX[$submission->status] ?? [];
    }

    public function canTransition(AssignmentSubmission $submission, string $targetStatus): bool
    {
        return in_array($targetStatus, $this->allowedTransitions($submission), true);
    }

    /** Used by InstructorSubmissionController::review() instead of an unchecked raw status write. */
    public function assertLegalReviewTransition(AssignmentSubmission $submission, string $targetStatus): void
    {
        // A submission being reviewed for the first time has no submitted
        // status recorded as a transition source when created directly
        // (e.g. via factory) — only enforce the matrix when the submission
        // already has one of the known statuses.
        if (! in_array($submission->status, [self::STATUS_SUBMITTED, self::STATUS_LATE, self::STATUS_GRADED, self::STATUS_NEEDS_REVISION], true)) {
            return;
        }

        if (! $this->canTransition($submission, $targetStatus)) {
            throw ValidationException::withMessages([
                'status' => "لا يمكن نقل حالة التسليم من \"{$submission->status}\" إلى \"{$targetStatus}\".",
            ]);
        }
    }
}
