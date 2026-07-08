<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssignmentSubmissionResource;
use App\Http\Resources\LmsAssignmentResource;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Services\CourseAssignmentBridgeService;
use App\Services\LmsEnrollmentService;
use App\Services\NotificationService;
use App\Services\StudentProgressService;
use App\Support\AssignmentSubmissionStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudentAssignmentController extends Controller
{
    /**
     * GET /api/student/assignments
     *
     * List all assignments for courses the student is enrolled in.
     */
    public function index(
        Request $request,
        LmsEnrollmentService $enrollment,
        CourseAssignmentBridgeService $bridge,
    ): AnonymousResourceCollection {
        $user      = $request->user();
        $courseIds = $enrollment->enrolledCourseIds($user);

        $bridge->syncForCourses($courseIds);

        $query = Assignment::query()
            ->with(['course:id,title,slug'])
            ->where('status', 'active')
            ->where('is_visible', true)
            ->where(function ($q) use ($courseIds) {
                $q->whereIn('course_id', $courseIds)
                    ->orWhereHas('workshop', fn ($w) => $w->whereIn('course_id', $courseIds));
            })
            // Exclude orphaned rows: linked CourseAssignment was soft-deleted but
            // this Assignment row was never cleaned up (pre-cascade-fix data).
            ->where(function ($q) {
                $q->whereNull('course_assignment_id')
                  ->orWhereExists(function ($sub) {
                      $sub->select(DB::raw(1))
                          ->from('course_assignments')
                          ->whereColumn('course_assignments.id', 'assignments.course_assignment_id')
                          ->whereNull('course_assignments.deleted_at');
                  });
            })
            // Only assignments this student has actually submitted.
            ->whereHas('submissions', fn ($q) => $q->where('user_id', $user->id)->whereNotNull('submitted_at'))
            ->with(['submissions' => fn ($q) => $q->where('user_id', $user->id)])
            ->orderByDesc('due_date');

        $perPage = min((int) $request->query('per_page', 20), 100);

        return LmsAssignmentResource::collection($query->paginate($perPage))
            ->additional(['success' => true, 'message' => 'Assignments retrieved successfully']);
    }

    /**
     * GET /api/student/assignments/{assignment}
     *
     * Return a single assignment with the student's own submission status.
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

        if (! $accessible && ! $user->isAdmin() && ! $user->isSuperAdmin()) {
            return response()->json(['success' => false, 'message' => 'You are not enrolled in this course.'], 403);
        }

        $assignment->load([
            'course:id,title',
            'submissions' => fn ($q) => $q->where('user_id', $user->id),
        ]);

        return (new LmsAssignmentResource($assignment))
            ->additional(['success' => true])
            ->response();
    }

    /**
     * POST /api/student/assignments/{assignment}/submit
     *
     * Submit (or resubmit) an assignment.
     * Payload: file (optional), text_answer / answer_text (optional), notes (optional).
     */
    public function submit(
        Request $request,
        Assignment $assignment,
        LmsEnrollmentService $enrollment,
        StudentProgressService $progress,
    ): JsonResponse {
        $user      = $request->user();
        $courseIds = $enrollment->enrolledCourseIds($user);

        // Resolve the course this assignment belongs to.
        $assignment->loadMissing('workshop');
        $courseId = $assignment->course_id
            ?? $assignment->workshop?->course_id;

        $accessible = $courseId && $courseIds->contains($courseId);

        if (! $accessible && ! $user->isAdmin() && ! $user->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك بالوصول إلى هذا المسار.',
            ], 403);
        }

        $validated = $request->validate([
            'text_answer' => ['nullable', 'string', 'max:20000'],
            'answer_text' => ['nullable', 'string', 'max:20000'],
            'notes'       => ['nullable', 'string', 'max:5000'],
            'file'        => [
                'nullable', 'file', 'max:10240',
                'mimes:pdf,doc,docx,png,jpg,jpeg,webp,zip',
            ],
        ]);

        $textAnswer = $validated['text_answer'] ?? $validated['answer_text'] ?? null;

        $existing = AssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->where('user_id', $user->id)
            ->first();

        // Resubmission is allowed only when:
        //   (a) the assignment has global resubmission_allowed flag, OR
        //   (b) the instructor explicitly requested a new submission (status = needs_revision).
        $instructorRequestedResubmission = $existing && $existing->status === 'needs_revision';

        if ($existing?->submitted_at && ! $assignment->resubmission_allowed && ! $instructorRequestedResubmission) {
            throw ValidationException::withMessages([
                'assignment' => ['تم تسليم هذا الواجب مسبقاً ولا يمكن إعادة التسليم إلا بطلب من المدرس.'],
            ]);
        }

        $filePath = null;
        if ($request->hasFile('file') && $request->file('file')->isValid()) {
            $filePath = $request->file('file')->store('assignment-submissions', 'private');
        }

        if (! $textAnswer && ! $filePath && ! $existing?->text_answer && ! $existing?->file_path) {
            throw ValidationException::withMessages([
                'text_answer' => ['Provide a text answer or upload a file.'],
            ]);
        }

        $submissionStatus = AssignmentSubmissionStatus::onSubmit($assignment);

        $submission = DB::transaction(function () use ($user, $assignment, $textAnswer, $validated, $filePath, $existing, $submissionStatus, $instructorRequestedResubmission) {
            $data = [
                'text_answer'  => $textAnswer ?? $existing?->text_answer,
                'notes'        => array_key_exists('notes', $validated) ? $validated['notes'] : $existing?->notes,
                'file_path'    => $filePath ?? $existing?->file_path,
                'submitted_at' => now(),
                'status'       => $submissionStatus,
                'score'        => null,
                'feedback'     => null,
            ];

            // Track when a resubmission occurs so the instructor sees a distinct status.
            if ($instructorRequestedResubmission
                && \Illuminate\Support\Facades\Schema::hasColumn('assignment_submissions', 'resubmitted_at')
            ) {
                $data['resubmitted_at'] = now();
            }

            return AssignmentSubmission::query()->updateOrCreate(
                [
                    'assignment_id' => $assignment->id,
                    'user_id'       => $user->id,
                ],
                $data
            );
        });

        $progress->syncForUser($user);

        // Notify the instructor — distinguish resubmission from initial submission.
        $assignment->loadMissing('course.instructor');
        $instructorUserId = $assignment->course?->instructor?->user_id;
        if ($instructorUserId) {
            if ($instructorRequestedResubmission) {
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

        return (new AssignmentSubmissionResource($submission->load('assignment')))
            ->additional(['success' => true, 'message' => 'Assignment submitted successfully.'])
            ->response()
            ->setStatusCode($existing?->submitted_at ? 200 : 201);
    }
}
