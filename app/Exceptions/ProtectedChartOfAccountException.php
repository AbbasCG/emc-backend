<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when code attempts to delete a system-managed Chart of Accounts
 * entry, or change its protected identity (code) once marked is_system.
 * Caught by ChartOfAccountsController to return a clean 422 instead of a
 * raw 500. Mirrors ProtectedFinanceAccountException's role for the
 * FinanceAccount model — kept as a separate class because the two models
 * (Account / FinanceAccount) represent different accounting concepts.
 */
class ProtectedChartOfAccountException extends RuntimeException
{
}
