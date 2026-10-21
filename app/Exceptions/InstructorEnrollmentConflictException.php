<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * An instructor tried to enroll in a course, workshop or learning path they teach themselves.
 *
 * It renders its own response, so every enrollment entry point answers the same way (403 with a stable `code`) without each
 * controller repeating the payload. `message` is the Arabic text itself, because clients show `message` as it is.
 */
class InstructorEnrollmentConflictException extends RuntimeException
{
    public const CODE = 'instructor_teaches_program';

    public function __construct(public readonly string $messageAr)
    {
        parent::__construct('An instructor cannot enroll in a program they teach.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success'    => false,
            'code'       => self::CODE,
            'message'    => $this->messageAr,
            'message_ar' => $this->messageAr,
            'message_en' => $this->getMessage(),
        ], 403);
    }
}
