<?php

namespace App\Services\AssignmentUploads;

use RuntimeException;

/** A single uploaded file was refused. `getMessage()` is the Arabic text shown to the user. */
class UploadRejectedException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $messageAr)
    {
        parent::__construct($messageAr);
    }
}
