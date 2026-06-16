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
            ->with(['course:id,title'])
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

        if ($existing?->submitted_at && ! $assignment->resubmission_allowed) {
            throw ValidationException::withMessages([
                'assignment' => ['This assignment has already been submitted and resubmission is not allowed.'],
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

        $submission = DB::transaction(function () use ($user, $assignment, $textAnswer, $validated, $filePath, $existing, $submissionStatus) {
            return AssignmentSubmission::query()->updateOrCreate(
                [
                    'assignment_id' => $assignment->id,
                    'user_id'       => $user->id,
                ],
                [
                    'text_answer'  => $textAnswer ?? $existing?->text_answer,
                    'notes'        => array_key_exists('notes', $validated) ? $validated['notes'] : $existing?->notes,
                    'file_path'    => $filePath ?? $existing?->file_path,
                    'submitted_at' => now(),
                    'status'       => $submissionStatus,
                    'score'        => null,
                    'feedback'     => null,
                ]
            );
        });

        $progress->syncForUser($user);

        // Notify the instructor that a submission was received.
        $assignment->loadMissing('course.instructor');
        $instructorUserId = $assignment->course?->instructor?->user_id;
        if ($instructorUserId) {
            NotificationService::studentSubmittedAssignment(
                instructorUserId: $instructorUserId,
                studentName:      $user->name,
                courseTitle:      $assignment->course?->title ?? 'الدورة',
                assignmentTitle:  $assignment->title,
                submissionId:     $submission->id,
            );
        }

        return (new AssignmentSubmissionResource($submission->load('assignment')))
            ->additional(['success' => true, 'message' => 'Assignment submitted successfully.'])
            ->response()
            ->setStatusCode($existing?->submitted_at ? 200 : 201);
    }
}
