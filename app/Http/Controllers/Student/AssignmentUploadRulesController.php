<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Services\AssignmentUploads\AssignmentUploadPolicy;
use Illuminate\Http\JsonResponse;

class AssignmentUploadRulesController extends Controller
{
    /**
     * GET /api/student/assignment-upload-rules
     *
     * The only source of the upload limits for the frontend, so the numbers are never duplicated client-side.
     */
    public function __invoke(AssignmentUploadPolicy $policy): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $policy->rules(),
        ]);
    }
}
