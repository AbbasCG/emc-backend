<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssignmentSubmissionResource;
use App\Models\AssignmentSubmission;
use App\Services\StudentProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InstructorSubmissionController extends Controller
{
    public function show(Request $request, AssignmentSubmission $submission): JsonResponse
    {
        $this->authorize('review', $submission);

        return (new AssignmentSubmissionResource($submission->load(['assignment', 'user'])))
            ->additional(['success' => true])
            ->response();
    }

    public function review(Request $request, AssignmentSubmission $submission, StudentProgressService $progress): JsonResponse
    {
        $this->authorize('review', $submission);

        $validated = $request->validate([
            'score' => ['nullable', 'numeric', 'min:0'],
            'feedback' => ['nullable', 'string', 'max:10000'],
            'status' => ['required', 'in:reviewed,needs_revision'],
        ]);

        DB::transaction(function () use ($submission, $validated) {
            $submission->update([
                'score' => $validated['score'] ?? $submission->score,
                'feedback' => $validated['feedback'] ?? $submission->feedback,
                'status' => $validated['status'],
            ]);
        });

        $progress->syncForUser($submission->user);

        return (new AssignmentSubmissionResource($submission->fresh()->load(['assignment', 'user'])))
            ->additional(['success' => true, 'message' => 'Submission reviewed successfully.'])
            ->response();
    }
}
