<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssignmentSubmissionResource;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\Registration;
use App\Services\NotificationService;
use App\Services\StudentProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InstructorSubmissionController extends Controller
{
    /**
     * GET /api/instructor/submissions
     *
     * All submissions for assignments belonging to the authenticated instructor's courses.
     * Optional filters: ?assignment_id=, ?status=, ?per_page=
     */
    public function index(Request $request): JsonResponse
    {
        $user    = $request->user();
        $profile = $user->instructorProfile;

        $courseIds = $profile
            ? Course::query()->where('instructor_id', $profile->id)->pluck('id')
            : collect();

        $assignmentIds = Assignment::query()
            ->where(function ($q) use ($courseIds) {
                $q->whereIn('course_id', $courseIds)
                  ->orWhereHas('workshop', fn ($w) => $w->whereIn('course_id', $courseIds));
            })
            ->when($request->filled('assignment_id'), fn ($q) => $q->where('id', $request->query('assignment_id')))
            ->pluck('id');

        $perPage = min((int) $request->query('per_page', 25), 100);

        $submissions = AssignmentSubmission::with([
            'user:id,name,email,avatar',
            'assignment.course:id,title',
        ])
            ->whereIn('assignment_id', $assignmentIds)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('submitted_at')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => AssignmentSubmissionResource::collection($submissions)->resolve(),
            'meta'    => [
                'total'        => $submissions->total(),
                'current_page' => $submissions->currentPage(),
                'last_page'    => $submissions->lastPage(),
                'per_page'     => $submissions->perPage(),
            ],
        ]);
    }

    /**
     * GET /api/instructor/assignments/{assignment}/submissions
     *
     * Submissions for a specific assignment + list of not-yet-submitted enrolled students.
     */
    public function forAssignment(Request $request, Assignment $assignment): JsonResponse
    {
        $user    = $request->user();
        $profile = $user->instructorProfile;

        $courseIds = $profile
            ? Course::query()->where('instructor_id', $profile->id)->pluck('id')
            : collect();

        $owned = $courseIds->contains($assignment->course_id);
        if (! $owned && ! $user->isSuperAdmin() && ! $user->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $submissions = AssignmentSubmission::with(['user:id,name,email,avatar'])
            ->where('assignment_id', $assignment->id)
            ->orderByDesc('submitted_at')
            ->get();

        // Determine students who have NOT submitted
        $notSubmitted = collect();
        $courseId     = $assignment->course_id
            ?? $assignment->workshop?->course_id;

        if ($courseId) {
            $submittedUserIds = $submissions->pluck('user_id')->toArray();

            $notSubmitted = Registration::with('user:id,name,email,avatar')
                ->where('course_id', $courseId)
                ->whereNotIn('status', ['Rejected', 'Cancelled', 'rejected', 'cancelled'])
                ->whereNotNull('user_id')
                ->whereNotIn('user_id', $submittedUserIds)
                ->get()
                ->map(fn ($r) => [
                    'id'     => $r->user_id,
                    'name'   => $r->user?->name   ?? $r->full_name ?? null,
                    'email'  => $r->user?->email  ?? $r->email      ?? null,
                    'avatar' => $r->user?->avatar ?? null,
                    'status' => 'not_submitted',
                ])
                ->values();
        }

        return response()->json([
            'success'       => true,
            'data'          => AssignmentSubmissionResource::collection($submissions)->resolve(),
            'not_submitted' => $notSubmitted,
            'meta'          => [
                'total_submitted'     => $submissions->count(),
                'total_not_submitted' => $notSubmitted->count(),
            ],
        ]);
    }

    /**
     * GET /api/instructor/submissions/{submission}
     */
    public function show(Request $request, AssignmentSubmission $submission): JsonResponse
    {
        $this->authorize('review', $submission);

        return (new AssignmentSubmissionResource($submission->load(['assignment.course', 'user'])))
            ->additional(['success' => true])
            ->response();
    }

    /**
     * PUT /api/instructor/submissions/{submission}/review
     *
     * Grade / give feedback on a submission.
     * Payload: status (reviewed|needs_revision), score (nullable), feedback (nullable).
     */
    public function review(Request $request, AssignmentSubmission $submission, StudentProgressService $progress): JsonResponse
    {
        $this->authorize('review', $submission);

        $validated = $request->validate([
            'score'    => ['nullable', 'numeric', 'min:0'],
            'grade'    => ['nullable', 'numeric', 'min:0'],   // alias for score
            'feedback' => ['nullable', 'string', 'max:10000'],
            'status'   => ['required', 'in:reviewed,needs_revision,graded'],
        ]);

        $storedStatus = $validated['status'] === 'graded' ? 'graded' : $validated['status'];
        if ($storedStatus === 'reviewed') {
            $storedStatus = 'graded';
        }

        DB::transaction(function () use ($submission, $validated, $storedStatus) {
            $submission->update([
                'score'    => $validated['score'] ?? $validated['grade'] ?? $submission->score,
                'feedback' => $validated['feedback'] ?? $submission->feedback,
                'status'   => $storedStatus,
            ]);
        });

        $progress->syncForUser($submission->user);

        // Notify the student that their submission was graded.
        $fresh      = $submission->fresh()->load(['assignment.course', 'user']);
        $student    = $fresh->user;
        $assignment = $fresh->assignment;
        $course     = $assignment?->course;

        if ($student) {
            NotificationService::assignmentGraded(
                studentUserId:   $student->id,
                courseId:        $course?->id      ?? 0,
                courseTitle:     $course?->title   ?? 'الدورة',
                assignmentTitle: $assignment?->title ?? 'الواجب',
                score:           $fresh->score,
                maxScore:        $assignment?->max_score ?? null,
                feedback:        $fresh->feedback,
                submissionId:    $fresh->id,
            );
        }

        return (new AssignmentSubmissionResource($fresh))
            ->additional(['success' => true, 'message' => 'Submission reviewed successfully.'])
            ->response();
    }
}
