<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a Finance reviewer tries to act on an approval request whose
 * proposed state no longer matches the program's current finance-relevant
 * state — i.e. the program was edited again after this request was raised.
 *
 * Approving in that situation would rubber-stamp a version the reviewer
 * never actually saw. Caught by ProgramFinanceApprovalController to return a
 * 409 telling the reviewer to reload the (now-refreshed) request.
 */
class StaleFinanceApprovalException extends RuntimeException
{
}
