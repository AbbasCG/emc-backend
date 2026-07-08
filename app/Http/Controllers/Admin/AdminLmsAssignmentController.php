<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminLmsAssignmentDetailResource;
use App\Http\Resources\LmsAssignmentResource;
use App\Models\Assignment;
use App\Models\CourseAssignment;
use App\Services\CourseAssignmentBridgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminLmsAssignmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Assignment::query()
            ->with(['course.instructor.user', 'workshop.course.instructor.user'])
            ->withCount([
                'submissions as submissions_count',
                'submissions as pending_submissions_count' => fn ($q) => $q
                    ->whereNotNull('submitted_at')
                    ->whereNotIn('status', ['reviewed', 'graded', 'needs_revision']),
                'submissions as graded_submissions_count' => fn ($q) => $q
                    ->whereIn('status', ['reviewed', 'graded']),
                'submissions as needs_resubmission_count' => fn ($q) => $q
                    ->where('status', 'needs_revision'),
            ])
            ->latest();

        if ($request->filled('course_id')) {
            $query->where(function ($q) use ($request) {
                $cid = $request->query('course_id');
                $q->where('course_id', $cid)->orWhereHas('workshop', fn ($w) => $w->where('course_id', $cid));
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $perPage = min((int) $request->query('per_page', 20), 100);

        return LmsAssignmentResource::collection($query->paginate($perPage))
            ->additional(['success' => true, 'message' => 'Assignments retrieved successfully']);
    }

    public function store(Request $request, CourseAssignmentBridgeService $bridge): JsonResponse
    {
        $validated = $request->validate([
            'course_id'   => ['nullable', 'exists:courses,id'],
            'workshop_id' => ['nullable', 'exists:workshops,id'],
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'due_date'    => ['nullable', 'date'],
            'max_score'   => ['nullable', 'numeric', 'min:0'],
            'status'      => ['nullable', 'in:active,archived'],
            'is_visible'  => ['nullable', 'boolean'],
        ]);

        $validated['status']     = $validated['status'] ?? 'active';
        $validated['is_visible'] = $validated['is_visible'] ?? true;

        // If this assignment belongs to a specific course (not a workshop), also create
        // a CourseAssignment so it appears in the course content page.
        if (! empty($validated['course_id']) && empty($validated['workshop_id'])) {
            $courseAssignment = CourseAssignment::create([
                'course_id'       => $validated['course_id'],
                'title'           => $validated['title'],
                'description'     => $validated['description'] ?? null,
                'deadline'        => $validated['due_date'] ?? null,
                'max_points'      => $validated['max_score'] ?? null,
                'is_visible'      => $validated['is_visible'],
                'status'          => $validated['status'] === 'active' ? 'published' : 'draft',
                'submission_type' => 'any',
                'sort_order'      => (int) CourseAssignment::where('course_id', $validated['course_id'])->max('sort_order') + 1,
            ]);

            $validated['course_assignment_id'] = $courseAssignment->id;
        }

        $assignment = Assignment::query()->create($validated);

        // Link the CourseAssignment back to the new Assignment.
        if (isset($courseAssignment)) {
            // The bridge foreign key goes on the Assignment side; CourseAssignment is already linked.
        }

        return (new LmsAssignmentResource($assignment->load('course.instructor.user')))
            ->additional(['success' => true, 'message' => 'Assignment created successfully'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Assignment $assignment): JsonResponse
    {
        $assignment->load([
            'course.instructor.user',
            'workshop.course.instructor.user',
            'submissions.user',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Assignment detail retrieved successfully',
            'data'    => (new AdminLmsAssignmentDetailResource($assignment))->resolve(),
        ]);
    }

    public function update(Request $request, Assignment $assignment): JsonResponse
    {
        $validated = $request->validate([
            'course_id'   => ['sometimes', 'nullable', 'exists:courses,id'],
            'workshop_id' => ['sometimes', 'nullable', 'exists:workshops,id'],
            'title'       => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'due_date'    => ['nullable', 'date'],
            'max_score'   => ['nullable', 'numeric', 'min:0'],
            'status'      => ['sometimes', 'in:active,archived'],
            'is_visible'  => ['sometimes', 'boolean'],
        ]);

        $assignment->update($validated);

        // Mirror changes to the linked CourseAssignment (if any).
        if ($assignment->course_assignment_id) {
            $courseAssignment = CourseAssignment::withTrashed()->find($assignment->course_assignment_id);
            if ($courseAssignment) {
                $caUpdate = [];
                if (array_key_exists('title', $validated))       $caUpdate['title']       = $validated['title'];
                if (array_key_exists('description', $validated)) $caUpdate['description'] = $validated['description'];
                if (array_key_exists('due_date', $validated))    $caUpdate['deadline']    = $validated['due_date'];
                if (array_key_exists('max_score', $validated))   $caUpdate['max_points']  = $validated['max_score'];
                if (array_key_exists('is_visible', $validated))  $caUpdate['is_visible']  = $validated['is_visible'];
                if (array_key_exists('status', $validated)) {
                    $caUpdate['status'] = $validated['status'] === 'active' ? 'published' : 'draft';
                }
                if (! empty($caUpdate)) {
                    $courseAssignment->restore();  // restore if soft-deleted
                    $courseAssignment->update($caUpdate);
                }
            }
        }

        return (new LmsAssignmentResource($assignment->fresh()->load('course.instructor.user')))
            ->additional(['success' => true, 'message' => 'Assignment updated successfully'])
            ->response();
    }

    public function destroy(Assignment $assignment): JsonResponse
    {
        // Also soft-delete the linked CourseAssignment so it disappears from course content page.
        if ($assignment->course_assignment_id) {
            CourseAssignment::withTrashed()
                ->where('id', $assignment->course_assignment_id)
                ->delete();
        }

        $assignment->delete();

        return response()->json(['success' => true, 'message' => 'Assignment deleted successfully']);
    }
}
