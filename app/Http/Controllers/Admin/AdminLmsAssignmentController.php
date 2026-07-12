<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminLmsAssignmentDetailResource;
use App\Http\Resources\LmsAssignmentResource;
use App\Models\CourseAssignment;
use App\Services\CourseAssignmentBridgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminLmsAssignmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = CourseAssignment::query()
            ->with(['course.instructor.user'])
            ->withCount([
                'submissions as submissions_count',
                // HasManyThrough JOINs assignments onto assignment_submissions;
                // both tables have a `status` column, so every condition must be
                // fully qualified to avoid SQLSTATE 1052 (ambiguous column).
                'submissions as pending_submissions_count' => fn ($q) => $q
                    ->whereNotNull('assignment_submissions.submitted_at')
                    ->whereNotIn('assignment_submissions.status', ['reviewed', 'graded', 'needs_revision']),
                'submissions as graded_submissions_count' => fn ($q) => $q
                    ->whereIn('assignment_submissions.status', ['reviewed', 'graded']),
                'submissions as needs_resubmission_count' => fn ($q) => $q
                    ->where('assignment_submissions.status', 'needs_revision'),
            ])
            ->latest('course_assignments.created_at');

        if ($request->filled('course_id')) {
            $query->where('course_assignments.course_id', $request->query('course_id'));
        }

        if ($request->filled('status')) {
            $status = match ($request->query('status')) {
                'active'   => 'published',
                'archived' => 'draft',
                default    => $request->query('status'),
            };
            $query->where('course_assignments.status', $status);
        }

        $perPage = min((int) $request->query('per_page', 20), 100);

        return LmsAssignmentResource::collection($query->paginate($perPage))
            ->additional(['success' => true, 'message' => 'Assignments retrieved successfully']);
    }

    public function store(Request $request, CourseAssignmentBridgeService $bridge): JsonResponse
    {
        $validated = $request->validate([
            'course_id'       => ['required', 'exists:courses,id'],
            'module_id'       => ['nullable', 'exists:course_modules,id'],
            'title'           => ['required', 'string', 'max:255'],
            'description'     => ['nullable', 'string'],
            'instructions'    => ['nullable', 'string'],
            'due_date'        => ['nullable', 'date'],
            'deadline'        => ['nullable', 'date'],
            'max_score'       => ['nullable', 'numeric', 'min:0'],
            'max_points'      => ['nullable', 'numeric', 'min:0'],
            'status'          => ['nullable', 'in:active,archived,published,draft'],
            'is_visible'      => ['nullable', 'boolean'],
            'submission_type' => ['nullable', 'in:file,text,url,any'],
            'sort_order'      => ['nullable', 'integer'],
        ]);

        $rawStatus = $validated['status'] ?? 'active';
        $status = match ($rawStatus) {
            'active'   => 'published',
            'archived' => 'draft',
            default    => $rawStatus,
        };

        $courseAssignment = CourseAssignment::create([
            'course_id'       => $validated['course_id'],
            'module_id'       => $validated['module_id'] ?? null,
            'title'           => $validated['title'],
            'description'     => $validated['description'] ?? null,
            'instructions'    => $validated['instructions'] ?? null,
            'deadline'        => $validated['due_date'] ?? $validated['deadline'] ?? null,
            'max_points'      => (int) ($validated['max_score'] ?? $validated['max_points'] ?? 100),
            'is_visible'      => $validated['is_visible'] ?? true,
            'status'          => $status,
            'submission_type' => $validated['submission_type'] ?? 'any',
            'sort_order'      => $validated['sort_order'] ?? (int) CourseAssignment::where('course_id', $validated['course_id'])->max('sort_order') + 1,
        ]);

        // Ensure a bridge Assignment row exists so students can submit via the FK path.
        $bridge->ensureLmsAssignment($courseAssignment);

        return (new LmsAssignmentResource($courseAssignment->load('course.instructor.user')))
            ->additional(['success' => true, 'message' => 'Assignment created successfully'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(CourseAssignment $courseAssignment): JsonResponse
    {
        $courseAssignment->load([
            'course.instructor.user',
            'submissions.user',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Assignment detail retrieved successfully',
            'data'    => (new AdminLmsAssignmentDetailResource($courseAssignment))->resolve(),
        ]);
    }

    public function update(Request $request, CourseAssignment $courseAssignment, CourseAssignmentBridgeService $bridge): JsonResponse
    {
        $validated = $request->validate([
            'course_id'       => ['sometimes', 'nullable', 'exists:courses,id'],
            'title'           => ['sometimes', 'string', 'max:255'],
            'description'     => ['nullable', 'string'],
            'instructions'    => ['nullable', 'string'],
            'due_date'        => ['nullable', 'date'],
            'deadline'        => ['nullable', 'date'],
            'max_score'       => ['nullable', 'numeric', 'min:0'],
            'max_points'      => ['nullable', 'numeric', 'min:0'],
            'status'          => ['sometimes', 'in:active,archived,published,draft'],
            'is_visible'      => ['sometimes', 'boolean'],
            'submission_type' => ['sometimes', 'in:file,text,url,any'],
        ]);

        $update = [];
        if (array_key_exists('title', $validated))           $update['title']           = $validated['title'];
        if (array_key_exists('description', $validated))     $update['description']     = $validated['description'];
        if (array_key_exists('instructions', $validated))    $update['instructions']    = $validated['instructions'];
        if (array_key_exists('is_visible', $validated))      $update['is_visible']      = $validated['is_visible'];
        if (array_key_exists('submission_type', $validated)) $update['submission_type'] = $validated['submission_type'];

        if (array_key_exists('due_date', $validated))   $update['deadline'] = $validated['due_date'];
        if (array_key_exists('deadline', $validated))   $update['deadline'] = $validated['deadline'];

        if (array_key_exists('max_score', $validated))  $update['max_points'] = (int) $validated['max_score'];
        if (array_key_exists('max_points', $validated)) $update['max_points'] = (int) $validated['max_points'];

        if (array_key_exists('status', $validated)) {
            $update['status'] = match ($validated['status']) {
                'active'   => 'published',
                'archived' => 'draft',
                default    => $validated['status'],
            };
        }

        $courseAssignment->update($update);

        // Sync the bridge Assignment row so the submit FK path stays consistent.
        $bridge->ensureLmsAssignment($courseAssignment->fresh());

        return (new LmsAssignmentResource($courseAssignment->fresh()->load('course.instructor.user')))
            ->additional(['success' => true, 'message' => 'Assignment updated successfully'])
            ->response();
    }

    public function destroy(CourseAssignment $courseAssignment): JsonResponse
    {
        $courseAssignment->delete();

        return response()->json(['success' => true, 'message' => 'Assignment deleted successfully']);
    }
}
