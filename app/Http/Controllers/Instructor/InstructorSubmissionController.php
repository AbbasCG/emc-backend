<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Resources\InstructorSubmissionResource;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Registration;
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
            'user:id,name,email,avatar',
            'assignment:id,title,max_score,due_date,course_id,workshop_id',
            'assignment.course:id,title,slug,instructor_id',
            'assignment.workshop:id,course_id',
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
            'user:id,name,email,avatar',
            'assignment:id,title,max_score,due_date,course_id,workshop_id',
            'assignment.course:id,title,slug,instructor_id',
            'assignment.workshop:id,course_id',
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
            'user:id,name,email,avatar',
            'assignment:id,title,max_score,due_date,course_id,workshop_id',
            'assignment.course:id,title,slug,instructor_id',
            'assignment.workshop:id,course_id',
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
     * PUT /api/instructor/submissions/{submission}/review
     */
    public function review(Request $request, AssignmentSubmission $submission, StudentProgressService $progress): JsonResponse
    {
        $this->authorize('review', $submission);

        $validated = $request->validate([
            'score'    => ['nullable', 'numeric', 'min:0'],
            'grade'    => ['nullable', 'numeric', 'min:0'],
            'feedback' => ['nullable', 'string', 'max:10000'],
            'status'   => ['required', 'in:reviewed,needs_revision,graded,pending_review'],
        ]);

        $storedStatus = match ($validated['status']) {
            'graded', 'reviewed' => 'graded',
            'needs_revision'     => 'needs_revision',
            default              => 'submitted',
        };

        DB::transaction(function () use ($submission, $validated, $storedStatus) {
            $submission->update([
                'score'    => $validated['score'] ?? $validated['grade'] ?? $submission->score,
                'feedback' => $validated['feedback'] ?? $submission->feedback,
                'status'   => $storedStatus,
            ]);
        });

        $progress->syncForUser($submission->user);

        $fresh = $submission->fresh()->load([
            'user:id,name,email,avatar',
            'assignment:id,title,max_score,due_date,course_id,workshop_id',
            'assignment.course:id,title,slug,instructor_id',
            'assignment.workshop:id,course_id',
        ]);

        $student    = $fresh->user;
        $assignment = $fresh->assignment;
        $course     = $assignment?->course;

        if ($student) {
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

    private function applyInstructorStatusFilter($query, string $status): void
    {
        match ($status) {
            'pending_review' => $query->whereIn('assignment_submissions.status', ['submitted', 'late']),
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
