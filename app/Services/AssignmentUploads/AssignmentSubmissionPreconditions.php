<?php

namespace App\Services\AssignmentUploads;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Services\AssignmentSubmissionHistoryService;
use Illuminate\Http\JsonResponse;

/**
 * The business rules that decide whether a submission may be accepted AT ALL, independent of the files' content.
 *
 * They are cheap (two small queries), so they run BEFORE any file is read, hashed or zip-listed (an already-submitted
 * assignment must not cost 25 MB of work), and AGAIN under a row lock inside the transaction (the first check can be
 * stale when two requests race).
 */
class AssignmentSubmissionPreconditions
{
    public function __construct(
        private readonly AssignmentSubmissionHistoryService $history,
        private readonly AssignmentSubmissionFileService $files,
    ) {
    }

    /**
     * @return array<string, list<string>> validation errors keyed like the request fields; [] = allowed
     */
    public function violations(Assignment $assignment, ?AssignmentSubmission $existing, bool $hasText, bool $hasNewFiles): array
    {
        $instructorRequestedResubmission = $existing && $existing->status === 'needs_revision';

        if ($existing?->submitted_at && ! $assignment->resubmission_allowed && ! $instructorRequestedResubmission) {
            return ['assignment' => ['تم تسليم هذا الواجب مسبقاً ولا يمكن إعادة التسليم إلا بطلب من المدرس.']];
        }

        if ($assignment->max_attempts !== null && $existing) {
            $priorAttempts = $this->history->attempts($existing)->count();
            $attemptAboutToBeMade = $priorAttempts + 2; // +1 for $existing itself (already one attempt), +1 for this new one
            if ($attemptAboutToBeMade > $assignment->max_attempts) {
                return ['assignment' => ["لقد وصلت إلى الحد الأقصى لعدد المحاولات المسموح بها ({$assignment->max_attempts})."]];
            }
        }

        if (! $hasText && ! $hasNewFiles && ! $existing?->text_answer && ! $this->files->hasCurrentFiles($existing)) {
            return ['text_answer' => ['يجب كتابة إجابة نصية أو رفع ملف واحد على الأقل.']];
        }

        return [];
    }

    /**
     * The one error shape of this endpoint: the API's standard {success, message, errors}, but with the FIRST Arabic
     * reason as the top-level `message` so a toast says why instead of a generic "Validation failed.".
     *
     * @param array<string, list<string>> $errors
     */
    public static function rejection(array $errors, int $status = 422): JsonResponse
    {
        $first = collect($errors)->flatten()->first();

        return response()->json([
            'success' => false,
            'message' => $first ?: 'تعذّر قبول التسليم. راجع البيانات والملفات وحاول مرة أخرى.',
            'errors'  => $errors,
        ], $status);
    }
}
