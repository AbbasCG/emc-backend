<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when code attempts to delete a system finance account, or change
 * one of its protected identity fields (code/provider/payment_method/type).
 * Caught by controllers to return a clean 422 instead of a raw 500.
 */
class ProtectedFinanceAccountException extends RuntimeException
{
}
