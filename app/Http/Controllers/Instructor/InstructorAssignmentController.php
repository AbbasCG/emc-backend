<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Http\Resources\LmsAssignmentResource;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\Registration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InstructorAssignmentController extends Controller
{
    private function ownedCourseIds(Request $request)
    {
        $profile = $request->user()->instructorProfile;

        return $profile
            ? Course::query()->where('instructor_id', $profile->id)->pluck('id')
            : collect();
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $courseIds = $this->ownedCourseIds($request);

        $query = CourseAssignment::query()
            ->with(['course:id,title'])
            ->withCount([
                'submissions as submissions_count',
                'submissions as pending_submissions_count' => fn ($q) => $q
                    ->whereNotNull('submitted_at')
                    ->whereNotIn('status', ['reviewed', 'graded', 'needs_revision']),
                'submissions as graded_submissions_count' => fn ($q) => $q
                    ->whereIn('status', ['reviewed', 'graded']),
            ])
            ->whereIn('course_id', $courseIds)
            ->latest();

        $perPage = min((int) $request->query('per_page', 25), 100);

        return LmsAssignmentResource::collection($query->paginate($perPage))
            ->additional(['success' => true, 'message' => 'Assignments retrieved successfully']);
    }

    /**
     * GET /api/instructor/assignments/dashboard
     *
     * Ticket 5: aggregate counters across every assignment the instructor
     * owns — every value is one bulk query, never per-assignment N+1.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $courseIds = $this->ownedCourseIds($request);

        if ($courseIds->isEmpty()) {
            return response()->json(['success' => true, 'data' => [
                'assignments_total' => 0, 'submissions_total' => 0, 'pending_review' => 0,
                'graded' => 0, 'needs_revision' => 0, 'missing_submissions' => 0,
            ]]);
        }

        $assignmentIds = CourseAssignment::whereIn('course_id', $courseIds)->pluck('id');
        $assignmentsTotal = $assignmentIds->count();

        $submissionCounts = AssignmentSubmission::whereIn(
            'assignment_id',
            \App\Models\Assignment::whereIn('course_assignment_id', $assignmentIds)->pluck('id')
        )
            ->selectRaw("count(*) as total,
                sum(case when status in ('reviewed','graded') then 1 else 0 end) as graded,
                sum(case when status = 'needs_revision' then 1 else 0 end) as needs_revision,
                sum(case when submitted_at is not null and status not in ('reviewed','graded','needs_revision') then 1 else 0 end) as pending_review")
            ->first();

        // Missing = (enrolled students per course) - (students who submitted to at least one of that course's assignments), summed per assignment.
        $enrolledByCourseId = Registration::whereIn('course_id', $courseIds)
            ->whereNotIn('status', ['Rejected', 'Cancelled', 'rejected', 'cancelled'])
            ->whereNotNull('user_id')
            ->get(['course_id', 'user_id'])
            ->groupBy('course_id');

        $missing = 0;
        $assignments = CourseAssignment::whereIn('course_id', $courseIds)->with('assignment:id,course_assignment_id')->get(['id', 'course_id']);
        $submittedByAssignment = AssignmentSubmission::whereIn(
            'assignment_id',
            \App\Models\Assignment::whereIn('course_assignment_id', $assignmentIds)->pluck('id')
        )->whereNotNull('submitted_at')->get(['assignment_id', 'user_id'])->groupBy('assignment_id');
        $assignmentIdByCourseAssignmentId = \App\Models\Assignment::whereIn('course_assignment_id', $assignmentIds)->pluck('id', 'course_assignment_id');

        foreach ($assignments as $ca) {
            $enrolledIds = ($enrolledByCourseId->get($ca->course_id) ?? collect())->pluck('user_id');
            $bridgeAssignmentId = $assignmentIdByCourseAssignmentId->get($ca->id);
            $submittedIds = $bridgeAssignmentId ? ($submittedByAssignment->get($bridgeAssignmentId) ?? collect())->pluck('user_id') : collect();
            $missing += $enrolledIds->diff($submittedIds)->count();
        }

        return response()->json(['success' => true, 'data' => [
            'assignments_total'   => $assignmentsTotal,
            'submissions_total'   => (int) ($submissionCounts->total ?? 0),
            'pending_review'      => (int) ($submissionCounts->pending_review ?? 0),
            'graded'              => (int) ($submissionCounts->graded ?? 0),
            'needs_revision'      => (int) ($submissionCounts->needs_revision ?? 0),
            'missing_submissions' => $missing,
        ]]);
    }

    /**
     * GET /api/instructor/assignments/missing-submissions
     *
     * Ticket 5: cross-assignment missing-submissions list (per-assignment
     * detail already existed via forAssignment()'s `not_submitted` block —
     * this is the aggregate view across every assignment at once).
     */
    public function missingSubmissions(Request $request): JsonResponse
    {
        $courseIds = $this->ownedCourseIds($request);
        $assignments = CourseAssignment::whereIn('course_id', $courseIds)
            ->with('course:id,title')
            ->get(['id', 'course_id', 'title', 'deadline']);

        $assignmentIdByCourseAssignmentId = \App\Models\Assignment::whereIn('course_assignment_id', $assignments->pluck('id'))->pluck('id', 'course_assignment_id');
        $submittedByAssignment = AssignmentSubmission::whereIn('assignment_id', $assignmentIdByCourseAssignmentId->values())
            ->whereNotNull('submitted_at')
            ->get(['assignment_id', 'user_id'])
            ->groupBy('assignment_id');
        $enrolledByCourseId = Registration::whereIn('course_id', $courseIds)
            ->whereNotIn('status', ['Rejected', 'Cancelled', 'rejected', 'cancelled'])
            ->whereNotNull('user_id')
            ->with('user:id,name,email')
            ->get(['course_id', 'user_id'])
            ->groupBy('course_id');

        $rows = [];
        foreach ($assignments as $ca) {
            $bridgeId = $assignmentIdByCourseAssignmentId->get($ca->id);
            $submittedIds = $bridgeId ? ($submittedByAssignment->get($bridgeId) ?? collect())->pluck('user_id') : collect();
            $enrolled = $enrolledByCourseId->get($ca->course_id) ?? collect();
            $missingStudents = $enrolled->filter(fn ($r) => ! $submittedIds->contains($r->user_id));

            if ($missingStudents->isEmpty()) {
                continue;
            }

            $rows[] = [
                'assignment_id'    => $ca->id,
                'assignment_title' => $ca->title,
                'course_title'     => $ca->course?->title,
                'deadline'         => $ca->deadline,
                'missing_count'    => $missingStudents->count(),
                'students'         => $missingStudents->map(fn ($r) => [
                    'user_id' => $r->user_id, 'name' => $r->user?->name, 'email' => $r->user?->email,
                ])->values(),
            ];
        }

        return response()->json(['success' => true, 'data' => $rows]);
    }
}
