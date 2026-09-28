<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a Finance reviewer tries to approve or reject a request that is
 * no longer pending — typically because another reviewer's decision committed
 * first. Checked under the row lock in ProgramFinanceApprovalService, so a
 * pending request gets exactly one terminal transition. Caught by
 * ProgramFinanceApprovalController, which answers with the same 422 as its own
 * pre-lock status check.
 */
class FinanceApprovalNotPendingException extends RuntimeException
{
}
