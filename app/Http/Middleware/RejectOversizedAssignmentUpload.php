<?php

namespace App\Http\Middleware;

use App\Services\AssignmentUploads\AssignmentUploadPolicy;
use App\Services\AssignmentUploads\OversizedUploadResponder;
use App\Services\AssignmentUploads\PhpUploadLimits;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When a multipart request is larger than PHP's post_max_size, PHP throws the whole body away: $_POST and $_FILES
 * both arrive EMPTY while CONTENT_LENGTH still shows the real size. Without a guard the controller would see "no
 * text and no file" and answer with a misleading validation error.
 *
 * Laravel's global ValidatePostSize middleware already throws PostTooLargeException for that case; bootstrap/app.php
 * renders it with OversizedUploadResponder for this route. THIS middleware is the route-level backstop: it answers the
 * same 413 JSON if that global middleware is ever removed, and it additionally refuses bodies above the application's
 * own total limit (plus multipart overhead) before any file is inspected.
 *
 * A 413 produced by Nginx itself (client_max_body_size) never reaches PHP at all; the frontend must treat a bare
 * 413 the same way (see docs/operations/ASSIGNMENT_UPLOADS.md).
 */
class RejectOversizedAssignmentUpload
{
    public function __construct(
        private readonly AssignmentUploadPolicy $policy,
        private readonly PhpUploadLimits $php,
        private readonly OversizedUploadResponder $responder,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)
            && str_contains(strtolower((string) $request->header('Content-Type')), 'multipart/form-data')) {
            $length = (int) $request->server('CONTENT_LENGTH', 0);
            $phpLimit = $this->php->postMaxBytes();
            $appLimit = $this->policy->maxTotalBytes() + $this->policy->overheadBytes();

            if ($phpLimit > 0 && $length > $phpLimit) {
                return $this->responder->make($request, 'server_post_max_size');
            }

            if ($length > $appLimit) {
                return $this->responder->make($request, 'app_total_limit');
            }
        }

        return $next($request);
    }
}
