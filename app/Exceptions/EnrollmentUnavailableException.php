<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A course, workshop or path cannot take a NEW enrollment right now (closed, unpublished, ended or full).
 *
 * One response for every entry point and for free and paid alike: 422 with a stable `code` and the Arabic text in `message`
 * (clients show `message` as it is). Existing registrations and learning access are never touched by it.
 */
class EnrollmentUnavailableException extends RuntimeException
{
    public const CLOSED = 'registration_closed';

    public const UNAVAILABLE = 'program_unavailable';

    public const ENDED = 'program_ended';

    public const FULL = 'program_full';

    public function __construct(public readonly string $reason, public readonly string $messageAr)
    {
        parent::__construct("Enrollment is not available: {$reason}.");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success'    => false,
            'code'       => $this->reason,
            'message'    => $this->messageAr,
            'message_ar' => $this->messageAr,
            'message_en' => $this->getMessage(),
        ], 422);
    }
}
