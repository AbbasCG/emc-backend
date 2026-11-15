<?php

namespace App\Services\AssignmentUploads;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The one 413 body for an assignment upload that is too large, used by RejectOversizedAssignmentUpload and by the
 * PostTooLargeException renderer (Laravel's global ValidatePostSize middleware throws that before route middleware runs).
 */
class OversizedUploadResponder
{
    public function __construct(
        private readonly AssignmentUploadPolicy $policy,
        private readonly PhpUploadLimits $php,
    ) {
    }

    public function make(Request $request, string $reason): JsonResponse
    {
        $phpLimit = $this->php->postMaxBytes();

        Log::warning('Assignment upload rejected: request body too large.', [
            'reason'         => $reason,
            'content_length' => (int) $request->server('CONTENT_LENGTH', 0),
            'post_max_size'  => $this->php->raw('post_max_size'),
            'app_limit_bytes' => $this->policy->maxTotalBytes() + $this->policy->overheadBytes(),
        ]);

        // Say the REAL limit. When PHP's post_max_size is below the application's total limit, quoting the
        // application's 25 MB would tell the student a body that PHP just refused is acceptable.
        $message = ($reason === 'server_post_max_size' && $phpLimit > 0)
            ? 'حجم الملفات أكبر مما يقبله الخادم حالياً (الحد '.AssignmentUploadPolicy::formatBytesAr($phpLimit).'). قلّل عدد الملفات أو حجمها.'
            : 'حجم الملفات المرفوعة أكبر من المسموح. الحد الأقصى '.AssignmentUploadPolicy::formatBytesAr($this->policy->maxTotalBytes())
                .' لجميع الملفات معاً. قلّل حجم الملفات أو عددها وأعد المحاولة.';

        return response()->json([
            'success'    => false,
            'code'       => 'payload_too_large',
            'message'    => $message,
            'message_ar' => $message,
            'limits'     => $this->policy->rules() + ['server_post_max_bytes' => $phpLimit],
        ], 413);
    }
}
