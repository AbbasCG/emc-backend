<?php

namespace App\Services\AssignmentUploads;

use RuntimeException;

/** A submission failed a business precondition AFTER files were stored (re-check under lock); the caller cleans up and answers. */
class SubmissionRejectedException extends RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(public readonly array $errors, public readonly int $status = 422)
    {
        parent::__construct('Submission rejected.');
    }
}
