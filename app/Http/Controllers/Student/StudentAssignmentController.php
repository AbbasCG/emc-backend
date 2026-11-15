<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\Student\SubmitAssignmentRequest;
use App\Http\Resources\AssignmentSubmissionResource;
use App\Http\Resources\StudentCourseAssignmentResource;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\CourseAssignment;
use App\Services\AssignmentSubmissionHistoryService;
use App\Services\AssignmentUploads\AssignmentSubmissionFileService;
use App\Services\AssignmentUploads\AssignmentSubmissionPreconditions;
use App\Services\AssignmentUploads\SubmissionRejectedException;
use App\Services\LmsEnrollmentService;
use App\Services\NotificationService;
use App\Services\StudentProgressService;
use App\Support\AssignmentSubmissionStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class StudentAssignmentController extends Controller
{
    /**
     * GET /api/student/assignments
     *
     * List assignments the student has submitted, sourced from course_assignments.
     */
    public function index(
        Request $request,
        LmsEnrollmentService $enrollment,
    ): AnonymousResourceCollection {
        $user      = $request->user();
        $courseIds = $enrollment->enrolledCourseIds($user);
        $myClassGroupIds = $enrollment->classGroupIdsByCourse($user)->values();

        $query = CourseAssignment::query()
            ->with(['course:id,title,slug'])
            ->whereIn('status', ['published', 'active'])
            ->where('is_visible', true)
            ->whereIn('course_id', $courseIds)
            ->visibleToClassGroupIn($myClassGroupIds)
            ->orderByDesc('deadline');

        $perPage = min((int) $request->query('per_page', 20), 100);

        $page = $query->paginate($perPage);

        // The student's own submission (with its attachments) for every row of this page in TWO queries, handed to
        // StudentCourseAssignmentResource through the request, instead of one submission query + one attachments query per row.
        $request->attributes->set(
            StudentCourseAssignmentResource::PRELOADED_SUBMISSIONS,
            StudentCourseAssignmentResource::submissionsFor($user, $page->getCollection()),
        );

        return StudentCourseAssignmentResource::collection($page)
            ->additional(['success' => true, 'message' => 'Assignments retrieved successfully']);
    }

    /**
     * GET /api/student/assignments/{assignment}
     *
     * Return a single assignment with the student's own submission status.
     * Accepts either an Assignment ID (legacy) or a CourseAssignment ID.
     */
    public function show(
        Request $request,
        Assignment $assignment,
        LmsEnrollmentService $enrollment,
    ): JsonResponse {
        $user      = $request->user();
        $courseIds = $enrollment->enrolledCourseIds($user);

        $accessible = ($assignment->course_id && $courseIds->contains($assignment->course_id))
            || ($assignment->workshop_id && $assignment->workshop && $courseIds->contains($assignment->workshop->course_id));

        if ($accessible && $assignment->class_group_id) {
            // Course enrollment alone isn't enough for a class-scoped assignment
            // — the student must be an active member of that specific class.
            // index() already enforces this via CourseAssignment::visibleToClassGroupIn();
            // this closes the same gap on the direct-by-ID path.
            $myClassGroupIds = $enrollment->classGroupIdsByCourse($user);
            $accessible = $myClassGroupIds->get($assignment->course_id) === $assignment->class_group_id;
        }

        // Participation needs an enrollment, whatever the account's administrative role. Administrators manage assignments through the
        // admin and instructor endpoints, never by acting as a learner of a course they are not enrolled in.
        if (! $accessible) {
            return response()->json(['success' => false, 'message' => 'You are not enrolled in this course.'], 403);
        }

        // If this Assignment links to a CourseAssignment, show the richer resource.
        if ($assignment->course_assignment_id) {
            $ca = CourseAssignment::find($assignment->course_assignment_id);
            if ($ca) {
                return (new StudentCourseAssignmentResource($ca))
                    ->additional(['success' => true])
                    ->response();
            }
        }

        $assignment->load([
            'course:id,title',
            'submissions' => fn ($q) => $q->where('user_id', $user->id),
            'submissions.currentFiles',
        ]);

        return (new \App\Http\Resources\LmsAssignmentResource($assignment))
            ->additional(['success' => true])
            ->response();
    }

    /**
     * A genuine race (two requests at once): the unique key was taken (UniqueConstraintViolationException, MySQL 1062,
     * SQLite UNIQUE), or the database aborted one of two competing transactions (SQLSTATE 40001, MySQL 1213 deadlock /
     * 1205 lock wait timeout). Other integrity violations (foreign key, NOT NULL) are NOT races and are not reported as one.
     */
    private function isConcurrencyConflict(\Throwable $e): bool
    {
        if ($e instanceof DeadlockException || $e instanceof UniqueConstraintViolationException) {
            return true;
        }
        if (! $e instanceof QueryException) {
            return false;
        }

        $state = (string) ($e->errorInfo[0] ?? '');
        $code  = (int) ($e->errorInfo[1] ?? 0);

        return $state === '40001'
            || in_array($code, [1062, 1213, 1205], true)
            || ($code === 19 && str_contains($e->getMessage(), 'UNIQUE constraint failed'));
    }

    /**
     * GET /api/student/assignments/{assignment}/history
     *
     * The student's own resubmission history for this assignment — every
     * prior attempt, never overwritten (see AssignmentSubmissionHistoryService).
     */
    public function history(Request $request, Assignment $assignment, LmsEnrollmentService $enrollment): JsonResponse
    {
        $user = $request->user();
        $courseIds = $enrollment->enrolledCourseIds($user);
        $accessible = ($assignment->course_id && $courseIds->contains($assignment->course_id))
            || ($assignment->workshop_id && $assignment->workshop && $courseIds->contains($assignment->workshop->course_id));

        if (! $accessible) {
            return response()->json(['success' => false, 'message' => 'You are not enrolled in this course.'], 403);
        }

        $submission = AssignmentSubmission::where('assignment_id', $assignment->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $submission) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $history = app(AssignmentSubmissionHistoryService::class);
        $attempts = $history->attempts($submission);
        $attachmentsByAttempt = $history->attachmentsByAttempt($submission);

        return response()->json([
            'success' => true,
            'data' => $attempts->map(fn ($a) => [
                'attempt_number' => $a->attempt_number,
                'text_answer'    => $a->text_answer,
                'has_file'       => $a->file_path !== null,
                'attachments'    => $attachmentsByAttempt[$a->attempt_number] ?? [],
                'score'          => $a->score,
                'feedback'       => $a->feedback,
                'status'         => $a->status,
                'submitted_at'   => $a->submitted_at,
                'reviewed_at'    => $a->reviewed_at,
            ]),
        ]);
    }

    /**
     * POST /api/student/assignments/{assignment}/submit
     *
     * Submit (or resubmit) an assignment.
     * Payload: files[] (optional, up to config('assignment_uploads.max_files')), file (optional, legacy single
     * upload kept for old clients), text_answer / answer_text (optional), notes (optional).
     *
     * Authorization, the cheap business rules and the verification of EVERY uploaded file happen in
     * SubmitAssignmentRequest, before anything is stored: one bad file => 422, nothing written, assignment not marked
     * submitted. Under concurrency the rules are checked AGAIN here, under a lock, because the first check can be stale.
     *
     * Attachment-set semantics on resubmission: a resubmission that carries files REPLACES the visible attachment
     * set; the superseded set stays reachable through the attempt history (the history snapshot copies it). A
     * resubmission without files keeps the current set (the same behaviour the single `file_path` always had).
     */
    public function submit(
        SubmitAssignmentRequest $request,
        Assignment $assignment,
        StudentProgressService $progress,
        AssignmentSubmissionFileService $attachments,
        AssignmentSubmissionPreconditions $preconditions,
    ): JsonResponse {
        $user      = $request->user();
        $validated = $request->validated();
        $uploads   = $request->inspectedUploads();

        $textAnswer = $validated['text_answer'] ?? $validated['answer_text'] ?? null;
        // SAME definition as SubmitAssignmentRequest (filled()): "0" is an answer, whitespace-only/empty is not.
        $hasText    = filled($textAnswer);

        // One submission per student+assignment at a time: a double click or a client retry that overlaps the first
        // request waits here instead of racing it. Stores without lock support simply skip this layer (the row lock and
        // the unique keys below remain the guarantee).
        $lock = null;
        try {
            $lock = Cache::lock('assignment-submit:'.$assignment->id.':'.$user->id, 120);
            $lock->block(5);
        } catch (LockTimeoutException) {
            return AssignmentSubmissionPreconditions::rejection([
                'assignment' => ['طلب تسليم آخر لهذا الواجب قيد المعالجة. انتظر لحظات ثم حدّث الصفحة وتحقق من حالة التسليم.'],
            ], 409);
        } catch (\Throwable) {
            $lock = null;
        }

        $stored = [];
        $requestedByInstructor = false;
        $wasResubmission = false;

        try {
            $existing = AssignmentSubmission::query()
                ->where('assignment_id', $assignment->id)
                ->where('user_id', $user->id)
                ->first();

            // Already-submitted / attempts used up / nothing to submit (also checked, cheaply, before the files were read).
            $violations = $preconditions->violations($assignment, $existing, $hasText, $uploads !== []);
            if ($violations !== []) {
                return AssignmentSubmissionPreconditions::rejection($violations);
            }

            $submissionStatus = AssignmentSubmissionStatus::onSubmit($assignment);

            // Only now (every check passed) are files written. Identical content already in the current set is reused
            // instead of being stored again, so a retry never creates duplicate attachments.
            try {
                $stored = $attachments->store($uploads, $assignment->id, $user->id, $attachments->currentSetIndex($existing));
            } catch (\RuntimeException $e) {
                Log::error('Could not write assignment attachments to the private disk.', ['error' => $e->getMessage(), 'assignment_id' => $assignment->id]);

                return response()->json([
                    'success' => false,
                    'code'    => 'storage_failed',
                    'message' => 'تعذّر حفظ الملفات على الخادم. لم يتم تسليم الواجب. أعد المحاولة بعد قليل، وإذا استمرت المشكلة فتواصل مع الدعم الفني.',
                ], 503);
            }

            $submission = DB::transaction(function () use ($user, $assignment, $textAnswer, $hasText, $uploads, $validated, $stored, $submissionStatus, $attachments, $preconditions, &$requestedByInstructor, &$wasResubmission) {
                // The row the decision is really based on: re-read under a lock so two overlapping requests cannot both
                // pass the rules with a stale copy, and re-run the rules on it.
                $locked = AssignmentSubmission::query()
                    ->where('assignment_id', $assignment->id)
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                $violations = $preconditions->violations($assignment, $locked, $hasText, $uploads !== []);
                if ($violations !== []) {
                    throw new SubmissionRejectedException($violations);
                }

                $requestedByInstructor = $locked && $locked->status === 'needs_revision';
                $wasResubmission       = (bool) $locked?->submitted_at;

                // Ticket 5: a real resubmission (a row already existed with prior
                // content/grade/feedback) must never silently lose that state —
                // snapshot it into attempt history before it gets overwritten.
                // The very first submission has no prior state to preserve.
                // The snapshot also copies the current attachment set into history.
                if ($locked) {
                    app(AssignmentSubmissionHistoryService::class)->snapshot($locked);
                }

                $data = [
                    'text_answer'  => $textAnswer ?? $locked?->text_answer,
                    'notes'        => array_key_exists('notes', $validated) ? $validated['notes'] : $locked?->notes,
                    // Legacy column kept in step: the first new attachment, so old clients that only know `file_url`
                    // (and the legacy download endpoints) keep working.
                    'file_path'    => ($stored[0]['stored_path'] ?? null) ?? $locked?->file_path,
                    'submitted_at' => now(),
                    'status'       => $submissionStatus,
                    'score'        => null,
                    'feedback'     => null,
                ];

                if ($requestedByInstructor
                    && \Illuminate\Support\Facades\Schema::hasColumn('assignment_submissions', 'resubmitted_at')
                ) {
                    $data['resubmitted_at'] = now();
                }

                $submission = AssignmentSubmission::query()->updateOrCreate(
                    [
                        'assignment_id' => $assignment->id,
                        'user_id'       => $user->id,
                    ],
                    $data
                );

                if ($stored !== []) {
                    $attachments->replaceCurrentSet($submission, $stored);
                }

                return $submission;
            }, 3); // up to 2 extra attempts on a deadlock / lock-wait: the closure is idempotent (it only reads, then writes inside the transaction)
        } catch (SubmissionRejectedException $e) {
            // Lost the race (or the rules changed between the first check and the lock): nothing of this request stays.
            $attachments->discard($stored);

            return AssignmentSubmissionPreconditions::rejection($e->errors, $e->status);
        } catch (QueryException|DeadlockException $e) {
            // The DB work failed: remove the files THIS request wrote so no orphan is left on the private disk. Files
            // the winning request stored have other (uuid) names and are never touched.
            $attachments->discard($stored);

            if ($this->isConcurrencyConflict($e)) {
                return AssignmentSubmissionPreconditions::rejection([
                    'assignment' => ['تم استلام تسليم هذا الواجب للتو من طلب آخر. حدّث الصفحة وتحقق من حالة التسليم قبل المحاولة مرة أخرى.'],
                ], 409);
            }

            // Anything else (a foreign key, NOT NULL, a lost connection, ...) is a real fault, not a race: say so honestly.
            Log::error('Assignment submission failed with an unexpected database error.', [
                'assignment_id' => $assignment->id,
                'user_id'       => $user->id,
                'sqlstate'      => $e instanceof QueryException ? ($e->errorInfo[0] ?? null) : null,
                'driver_code'   => $e instanceof QueryException ? ($e->errorInfo[1] ?? null) : null,
                'error'         => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'code'    => 'submission_failed',
                'message' => 'تعذّر حفظ التسليم بسبب خطأ غير متوقع. لم يتم تسليم الواجب. أعد المحاولة بعد قليل، وإذا استمرت المشكلة فتواصل مع الدعم الفني.',
            ], 500);
        } catch (\Throwable $e) {
            $attachments->discard($stored);

            throw $e;
        } finally {
            // Releasing the lock must NEVER turn an already committed submission into a 500 (a lock that expired, or a cache
            // store that went away, is only worth a log line).
            try {
                $lock?->release();
            } catch (\Throwable $e) {
                Log::warning('Could not release the assignment submission lock.', ['error' => $e->getMessage()]);
            }
        }

        $progress->syncForUser($user);

        $assignment->loadMissing('course.instructor');
        $instructorUserId = $assignment->course?->instructor?->user_id;
        if ($instructorUserId) {
            if ($requestedByInstructor) {
                NotificationService::studentResubmittedAssignment(
                    instructorUserId: $instructorUserId,
                    studentName:      $user->name,
                    courseTitle:      $assignment->course?->title ?? 'الدورة',
                    assignmentTitle:  $assignment->title,
                    submissionId:     $submission->id,
                );
            } else {
                NotificationService::studentSubmittedAssignment(
                    instructorUserId: $instructorUserId,
                    studentName:      $user->name,
                    courseTitle:      $assignment->course?->title ?? 'الدورة',
                    assignmentTitle:  $assignment->title,
                    submissionId:     $submission->id,
                );
            }
        }

        return (new AssignmentSubmissionResource($submission->load('assignment', 'currentFiles')))
            ->additional([
                'success' => true,
                'message' => $wasResubmission ? 'تمت إعادة تسليم الواجب بنجاح.' : 'تم تسليم الواجب بنجاح.',
            ])
            ->response()
            ->setStatusCode($wasResubmission ? 200 : 201);
    }
}
