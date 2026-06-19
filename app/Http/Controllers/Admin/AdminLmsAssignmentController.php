<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\LmsAssignmentResource;
use App\Models\Assignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminLmsAssignmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Assignment::query()
            ->with(['workshop', 'course'])
            ->withCount([
                'submissions',
                'submissions as pending_count' => fn ($q) => $q->where('status', 'pending_review'),
                'submissions as graded_count'  => fn ($q) => $q->where('status', 'reviewed'),
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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id'   => ['nullable', 'exists:courses,id'],
            'workshop_id' => ['nullable', 'exists:workshops,id'],
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'due_date'    => ['nullable', 'date'],
            'max_score'   => ['nullable', 'numeric', 'min:0'],
            'status'      => ['nullable', 'in:active,archived'],
        ]);

        $validated['status'] = $validated['status'] ?? 'active';

        $assignment = Assignment::query()->create($validated);

        return (new LmsAssignmentResource($assignment))
            ->additional(['success' => true, 'message' => 'Assignment created successfully'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Assignment $assignment): JsonResponse
    {
        $assignment->load(['workshop', 'submissions.user']);

        return (new LmsAssignmentResource($assignment))
            ->additional(['success' => true, 'message' => 'Assignment retrieved successfully'])
            ->response();
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
        ]);

        $assignment->update($validated);

        return (new LmsAssignmentResource($assignment->fresh()))
            ->additional(['success' => true, 'message' => 'Assignment updated successfully'])
            ->response();
    }

    public function destroy(Assignment $assignment): JsonResponse
    {
        $assignment->delete();

        return response()->json(['success' => true, 'message' => 'Assignment deleted successfully']);
    }
}
