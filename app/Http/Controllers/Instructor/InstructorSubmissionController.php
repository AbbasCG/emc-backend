<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Resources\InstructorSubmissionResource;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Registration;
use App\Services\AssignmentSubmissionHistoryService;
use App\Services\AssignmentSubmissionTransitionService;
use App\Services\InstructorSubmissionScopeService;
use App\Services\NotificationService;
use App\Services\StudentProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InstructorSubmissionController extends Controller
{
    public function __construct(
        private readonly InstructorSubmissionScopeService $scope,
    ) {}

    /**
     * GET /api/instructor/submissions
     *
     * Submissions for assignments on courses / learning paths assigned to the instructor.
     * Filters: ?assignment_id=, ?status=pending_review|reviewed|needs_revision, ?course_id=, ?per_page=
     */
    public function index(Request $request): JsonResponse
    {
        $user    = $request->user();
        $profile = $user->instructorProfile;

        if (! $profile && ! $user->isAdmin() && ! $user->isSuperAdmin()) {
            return response()->json(['success' => false, 'message' => 'غير مرتبط بمدرب.'], 403);
        }

        $courseIds = $profile
            ? $this->scope->accessibleCourseIds($profile)
            : collect();

        $assignmentIds = $profile
            ? $this->scope->accessibleAssignmentIds(
                $profile,
                $request->filled('assignment_id') ? (int) $request->query('assignment_id') : null,
            )
            : Assignment::query()->pluck('id');

        if ($assignmentIds->isEmpty()) {
            return response()->json([
                'success' => true,
                'data'    => [],
                'meta'    => [
                    'total'        => 0,
                    'current_page' => 1,
                    'last_page'    => 1,
                    'per_page'     => min((int) $request->query('per_page', 25), 100),
                ],
            ]);
        }

        $perPage = min((int) $request->query('per_page', 25), 100);

        $submissions = AssignmentSubmission::with([
            'user'              => fn ($q) => $q->withTrashed()->select(['id', 'name', 'email', 'avatar']),
            'assignment:id,title,max_score,due_date,course_id,workshop_id',
            'assignment.course' => fn ($q) => $q->withTrashed()->select(['id', 'title', 'slug', 'instructor_id']),
            'assignment.workshop' => fn ($q) => $q->withTrashed()->select(['id', 'title', 'slug', 'course_id']),
            'currentFiles',
        ])
            ->whereIn('assignment_id', $assignmentIds)
            ->whereNotNull('submitted_at')
            ->when($request->filled('course_id'), function ($q) use ($request, $courseIds) {
                $cid = (int) $request->query('course_id');
                if (! $courseIds->contains($cid)) {
                    $q->whereRaw('0 = 1');
                    return;
                }
                $q->whereHas('assignment', function ($aq) use ($cid) {
                    $aq->where('course_id', $cid)
                        ->orWhereHas('workshop', fn ($w) => $w->where('course_id', $cid));
                });
            })
            ->when($request->filled('status'), fn ($q) => $this->applyInstructorStatusFilter($q, (string) $request->query('status')))
            ->orderByDesc('submitted_at')
            ->paginate($perPage);

        $this->hydrateLearningPathMap($profile, $courseIds);

        $data = InstructorSubmissionResource::collection($submissions)->resolve();

        InstructorSubmissionResource::$learningPathByCourseId = null;

        return response()->json([
            'success' => true,
            'data'    => $data,
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
     */
    public function forAssignment(Request $request, Assignment $assignment): JsonResponse
    {
        $user    = $request->user();
        $profile = $user->instructorProfile;

        if (! $profile && ! $user->isAdmin() && ! $user->isSuperAdmin()) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        if ($profile && ! $this->scope->instructorOwnsAssignment($profile, $assignment)) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $assignment->loadMissing(['course:id,title,slug', 'workshop:id,course_id']);

        $submissions = AssignmentSubmission::with([
            'user'              => fn ($q) => $q->withTrashed()->select(['id', 'name', 'email', 'avatar']),
            'assignment:id,title,max_score,due_date,course_id,workshop_id',
            'assignment.course' => fn ($q) => $q->withTrashed()->select(['id', 'title', 'slug', 'instructor_id']),
            'assignment.workshop' => fn ($q) => $q->withTrashed()->select(['id', 'title', 'slug', 'course_id']),
            'currentFiles',
        ])
            ->where('assignment_id', $assignment->id)
            ->whereNotNull('submitted_at')
            ->orderByDesc('submitted_at')
            ->get();

        $courseId = $assignment->course_id ?? $assignment->workshop?->course_id;
        $notSubmitted = collect();

        if ($courseId) {
            $submittedUserIds = $submissions->pluck('user_id')->filter()->all();

            $notSubmitted = Registration::with('user:id,name,email,avatar')
                ->where('course_id', $courseId)
                ->whereNotIn('status', ['Rejected', 'Cancelled', 'rejected', 'cancelled'])
                ->whereNotNull('user_id')
                ->whereNotIn('user_id', $submittedUserIds)
                ->get()
                ->map(fn ($r) => [
                    'id'           => $r->user_id,
                    'name'         => $r->user?->name ?? $r->full_name ?? null,
                    'email'        => $r->user?->email ?? $r->email ?? null,
                    'avatar'       => $r->user?->avatar_url ?? null,
                    'status'       => 'not_submitted',
                    'student_name' => $r->user?->name ?? $r->full_name ?? '',
                ])
                ->values();
        }

        if ($profile) {
            $this->hydrateLearningPathMap($profile, collect([(int) $courseId])->filter());
        }

        $data = InstructorSubmissionResource::collection($submissions)->resolve();

        InstructorSubmissionResource::$learningPathByCourseId = null;

        return response()->json([
            'success'       => true,
            'data'          => $data,
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

        $submission->load([
            'user'              => fn ($q) => $q->withTrashed()->select(['id', 'name', 'email', 'avatar']),
            'assignment:id,title,max_score,due_date,course_id,workshop_id',
            'assignment.course' => fn ($q) => $q->withTrashed()->select(['id', 'title', 'slug', 'instructor_id']),
            'assignment.workshop' => fn ($q) => $q->withTrashed()->select(['id', 'title', 'slug', 'course_id']),
            'currentFiles',
        ]);

        $profile  = $request->user()->instructorProfile;
        $courseId = $submission->assignment?->course_id ?? $submission->assignment?->workshop?->course_id;

        if ($profile && $courseId) {
            InstructorSubmissionResource::$learningPathByCourseId = [
                (int) $courseId => $this->scope->learningPathSummaryForCourse($profile, (int) $courseId),
            ];
        }

        $response = (new InstructorSubmissionResource($submission))
            ->additional(['success' => true])
            ->response();

        InstructorSubmissionResource::$learningPathByCourseId = null;

        return $response;
    }

    /**
     * GET /api/instructor/submissions/{submission}/history
     *
     * Every prior attempt for this submission — the current row (in
     * `assignment_submissions`) is never overwritten without first being
     * snapshotted here (see AssignmentSubmissionHistoryService), so this
     * always reflects the true resubmission history.
     */
    public function history(Request $request, AssignmentSubmission $submission): JsonResponse
    {
        $this->authorize('review', $submission);

        $history = app(AssignmentSubmissionHistoryService::class);
        $attempts = $history->attempts($submission);
        $attachmentsByAttempt = $history->attachmentsByAttempt($submission);

        return response()->json([
            'success' => true,
            'data' => $attempts->map(fn ($a) => [
                'attempt_number' => $a->attempt_number,
                // Historical attempt files are preserved on disk but have no
                // dedicated per-attempt download route yet — only the
                // presence of a file is reported, not a (misleading) link to
                // the CURRENT submission's file.
                'has_file'       => $a->file_path !== null,
                // Files of THAT attempt, downloadable through the per-file authorized endpoint.
                'attachments'    => $attachmentsByAttempt[$a->attempt_number] ?? [],
                'text_answer'    => $a->text_answer,
                'notes'          => $a->notes,
                'score'          => $a->score,
                'feedback'       => $a->feedback,
                'status'         => $a->status,
                'submitted_at'   => $a->submitted_at,
                'reviewed_at'    => $a->reviewed_at,
            ]),
        ]);
    }

    /**
     * PUT /api/instructor/submissions/{submission}/review
     */
    public function review(Request $request, AssignmentSubmission $submission, StudentProgressService $progress): JsonResponse
    {
        $this->authorize('review', $submission);

        $submission->loadMissing('assignment:id,max_score');
        $maxScore = $submission->assignment?->max_score;

        $validated = $request->validate([
            'score'    => ['nullable', 'numeric', 'min:0', $maxScore !== null ? "max:{$maxScore}" : 'nullable'],
            'grade'    => ['nullable', 'numeric', 'min:0', $maxScore !== null ? "max:{$maxScore}" : 'nullable'],
            'feedback' => ['nullable', 'string', 'max:10000'],
            'status'   => ['required', 'in:reviewed,needs_revision,graded,pending_review'],
        ]);

        $storedStatus = match ($validated['status']) {
            'graded', 'reviewed' => 'graded',
            'needs_revision'     => 'needs_revision',
            default              => 'submitted',
        };

        if ($storedStatus !== $submission->status) {
            try {
                app(AssignmentSubmissionTransitionService::class)->assertLegalReviewTransition($submission, $storedStatus);
            } catch (\Illuminate\Validation\ValidationException $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }
        }

        $requestingResubmission = $storedStatus === 'needs_revision';

        DB::transaction(function () use ($submission, $validated, $storedStatus) {
            $data = [
                'score'    => $validated['score'] ?? $validated['grade'] ?? $submission->score,
                'feedback' => $validated['feedback'] ?? $submission->feedback,
                'status'   => $storedStatus,
            ];
            if (\Illuminate\Support\Facades\Schema::hasColumn('assignment_submissions', 'reviewed_at')) {
                $data['reviewed_at'] = now();
            }
            $submission->update($data);
        });

        $progress->syncForUser($submission->user);

        $fresh = $submission->fresh()->load([
            'user'              => fn ($q) => $q->withTrashed()->select(['id', 'name', 'email', 'avatar']),
            'assignment:id,title,max_score,due_date,course_id,workshop_id',
            'assignment.course' => fn ($q) => $q->withTrashed()->select(['id', 'title', 'slug', 'instructor_id']),
            'assignment.workshop' => fn ($q) => $q->withTrashed()->select(['id', 'title', 'slug', 'course_id']),
            'currentFiles',
        ]);

        $student    = $fresh->user;
        $assignment = $fresh->assignment;
        $course     = $assignment?->course;

        if ($student) {
            if ($requestingResubmission) {
                // Instructor asked the student to resubmit — send a targeted notification.
                NotificationService::requestedResubmission(
                    studentUserId:   $student->id,
                    courseTitle:     $course?->title ?? 'الدورة',
                    assignmentTitle: $assignment?->title ?? 'الواجب',
                    feedback:        $fresh->feedback,
                    submissionId:    $fresh->id,
                );
            } else {
                NotificationService::assignmentGraded(
                    studentUserId:   $student->id,
                    courseId:        $course?->id ?? 0,
                    courseTitle:     $course?->title ?? 'الدورة',
                    assignmentTitle: $assignment?->title ?? 'الواجب',
                    score:           $fresh->score,
                    maxScore:        $assignment?->max_score ?? null,
                    feedback:        $fresh->feedback,
                    submissionId:    $fresh->id,
                );
            }
        }

        $profile  = $request->user()->instructorProfile;
        $courseId = $assignment?->course_id ?? $assignment?->workshop?->course_id;
        if ($profile && $courseId) {
            InstructorSubmissionResource::$learningPathByCourseId = [
                (int) $courseId => $this->scope->learningPathSummaryForCourse($profile, (int) $courseId),
            ];
        }

        $response = (new InstructorSubmissionResource($fresh))
            ->additional(['success' => true, 'message' => 'Submission reviewed successfully.'])
            ->response();

        InstructorSubmissionResource::$learningPathByCourseId = null;

        return $response;
    }

    /**
     * PUT /api/instructor/submissions/bulk-review
     *
     * Ticket 5: bulk grading — applies the same score/feedback/status to a
     * list of submission IDs. Every submission is individually authorized
     * (no owner check is skipped for bulk) and individually transition-
     * checked; a submission that fails either is skipped and reported, not
     * silently dropped, and never blocks the rest of the batch.
     */
    public function bulkReview(Request $request, StudentProgressService $progress): JsonResponse
    {
        $validated = $request->validate([
            'submission_ids'   => ['required', 'array', 'min:1'],
            'submission_ids.*' => ['integer', 'exists:assignment_submissions,id'],
            'score'            => ['nullable', 'numeric', 'min:0'],
            'feedback'         => ['nullable', 'string', 'max:10000'],
            'status'           => ['required', 'in:reviewed,needs_revision,graded'],
        ]);

        $storedStatus = $validated['status'] === 'needs_revision' ? 'needs_revision' : 'graded';
        $requestingResubmission = $storedStatus === 'needs_revision';

        $updated = [];
        $skipped = [];

        foreach ($validated['submission_ids'] as $id) {
            $submission = AssignmentSubmission::with('assignment:id,title,max_score,course_id,workshop_id', 'user:id,name,email')->find($id);
            if (! $submission || ! $request->user()->can('review', $submission)) {
                $skipped[] = ['id' => $id, 'reason' => 'unauthorized'];
                continue;
            }

            $maxScore = $submission->assignment?->max_score;
            if ($validated['score'] !== null && $maxScore !== null && $validated['score'] > $maxScore) {
                $skipped[] = ['id' => $id, 'reason' => 'score_exceeds_max'];
                continue;
            }

            if ($storedStatus !== $submission->status) {
                try {
                    app(AssignmentSubmissionTransitionService::class)->assertLegalReviewTransition($submission, $storedStatus);
                } catch (\Illuminate\Validation\ValidationException) {
                    $skipped[] = ['id' => $id, 'reason' => 'illegal_transition'];
                    continue;
                }
            }

            $submission->update([
                'score'       => $validated['score'] ?? $submission->score,
                'feedback'    => $validated['feedback'] ?? $submission->feedback,
                'status'      => $storedStatus,
                'reviewed_at' => now(),
            ]);

            $progress->syncForUser($submission->user);

            $assignment = $submission->assignment;
            $course = $assignment?->course;
            if ($submission->user) {
                if ($requestingResubmission) {
                    NotificationService::requestedResubmission(
                        studentUserId: $submission->user->id, courseTitle: $course?->title ?? 'الدورة',
                        assignmentTitle: $assignment?->title ?? 'الواجب', feedback: $submission->feedback, submissionId: $submission->id,
                    );
                } else {
                    NotificationService::assignmentGraded(
                        studentUserId: $submission->user->id, courseId: $course?->id ?? 0, courseTitle: $course?->title ?? 'الدورة',
                        assignmentTitle: $assignment?->title ?? 'الواجب', score: $submission->score,
                        maxScore: $assignment?->max_score, feedback: $submission->feedback, submissionId: $submission->id,
                    );
                }
            }

            $updated[] = $id;
        }

        return response()->json([
            'success' => true,
            'updated_count' => count($updated),
            'skipped_count' => count($skipped),
            'updated' => $updated,
            'skipped' => $skipped,
        ]);
    }

    private function applyInstructorStatusFilter($query, string $status): void
    {
        match ($status) {
            'pending_review' => $query->whereIn('assignment_submissions.status', ['submitted', 'late'])
                                      ->whereNull('assignment_submissions.resubmitted_at'),
            'resubmitted'    => $query->whereIn('assignment_submissions.status', ['submitted', 'late'])
                                      ->whereNotNull('assignment_submissions.resubmitted_at'),
            'reviewed'       => $query->whereIn('assignment_submissions.status', ['graded', 'reviewed']),
            'needs_revision' => $query->where('assignment_submissions.status', 'needs_revision'),
            'not_submitted'  => $query->whereRaw('0 = 1'),
            default          => $query->where('assignment_submissions.status', $status),
        };
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>|null  $courseIds
     */
    private function hydrateLearningPathMap($profile, $courseIds): void
    {
        if (! $profile || ! $courseIds || $courseIds->isEmpty()) {
            InstructorSubmissionResource::$learningPathByCourseId = [];

            return;
        }

        $map = [];
        foreach ($courseIds as $cid) {
            $map[(int) $cid] = $this->scope->learningPathSummaryForCourse($profile, (int) $cid);
        }

        InstructorSubmissionResource::$learningPathByCourseId = $map;
    }
}
