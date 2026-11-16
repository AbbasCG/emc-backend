<?php

namespace App\Exceptions;

use RuntimeException;

/** A volunteer-request conversion that must be refused (not a crash): carries a stable reason code and the HTTP status to answer with. */
class VolunteerConversionException extends RuntimeException
{
    public const ALREADY_CONVERTED = 'already_converted';
    public const NOT_ACCEPTED      = 'not_accepted';
    public const ACCOUNT_DELETED   = 'account_deleted';
    public const NO_EMAIL          = 'no_email';
    public const ACCOUNT_INACTIVE  = 'account_inactive';
    public const ROLE_NOT_ELIGIBLE = 'role_not_eligible';

    public function __construct(public readonly string $reason, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
