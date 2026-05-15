<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssignmentSubmissionResource;
use App\Http\Resources\LmsAssignmentResource;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Services\LmsEnrollmentService;
use App\Services\StudentProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class StudentAssignmentController extends Controller
{
    public function index(Request $request, LmsEnrollmentService $enrollment): AnonymousResourceCollection
    {
        $user = $request->user();
        $courseIds = $enrollment->enrolledCourseIds($user);

        $query = Assignment::query()
            ->where('status', 'active')
            ->where(function ($q) use ($courseIds) {
                $q->whereIn('course_id', $courseIds)
                    ->orWhereHas('workshop', fn ($w) => $w->whereIn('course_id', $courseIds));
            })
            ->with(['submissions' => fn ($q) => $q->where('user_id', $user->id)])
            ->orderBy('due_date');

        $perPage = min((int) $request->query('per_page', 20), 100);

        return LmsAssignmentResource::collection($query->paginate($perPage))
            ->additional(['success' => true, 'message' => 'Assignments retrieved successfully']);
    }

    public function submit(Request $request, Assignment $assignment, StudentProgressService $progress): JsonResponse
    {
        $this->authorize('submit', $assignment);

        $validated = $request->validate([
            'text_answer' => ['nullable', 'string', 'max:20000'],
            'file' => ['nullable', 'file', 'max:12288'],
        ]);

        $filePath = null;
        if ($request->hasFile('file') && $request->file('file')->isValid()) {
            $filePath = $request->file('file')->store('assignment-submissions', 'public');
        }

        $submission = DB::transaction(function () use ($request, $assignment, $validated, $filePath) {
            $existing = AssignmentSubmission::query()
                ->where('assignment_id', $assignment->id)
                ->where('user_id', $request->user()->id)
                ->first();

            return AssignmentSubmission::query()->updateOrCreate(
                [
                    'assignment_id' => $assignment->id,
                    'user_id' => $request->user()->id,
                ],
                [
                    'text_answer' => $validated['text_answer'] ?? $existing?->text_answer,
                    'file_path' => $filePath ?? $existing?->file_path,
                    'submitted_at' => now(),
                    'status' => 'submitted',
                    'score' => null,
                    'feedback' => null,
                ]
            );
        });

        $progress->syncForUser($request->user());

        return (new AssignmentSubmissionResource($submission->load('assignment')))
            ->additional(['success' => true, 'message' => 'Assignment submitted successfully.'])
            ->response()
            ->setStatusCode(201);
    }
}
