<?php

namespace App\Exceptions;

use RuntimeException;

/** A ticket action that was refused for a business reason; carries the HTTP status and field errors to report. */
class TicketActionException extends RuntimeException
{
    /** @param array<string, array<int, string>> $errors */
    public function __construct(string $message, public readonly int $status = 409, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
