<?php

namespace App\Exceptions;

use App\Services\Forms\FormAvailabilityService;
use RuntimeException;

/** A form refused a submission because it is closed, not open yet, ended, full, or needs a signed-in respondent. */
class FormUnavailableException extends RuntimeException
{
    public function __construct(public readonly string $state, string $message)
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return FormAvailabilityService::HTTP_STATUS[$this->state] ?? 403;
    }
}
