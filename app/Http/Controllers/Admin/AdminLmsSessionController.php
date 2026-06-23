<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\LmsSessionResource;
use App\Models\LmsSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminLmsSessionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = LmsSession::query()
            ->with(['workshop', 'instructor', 'course'])
            ->withCount('attendances')
            ->latest();

        if ($request->filled('course_id')) {
            $query->where(function ($q) use ($request) {
                $cid = $request->query('course_id');
                $q->where('course_id', $cid)
                    ->orWhereHas('workshop', fn ($w) => $w->where('course_id', $cid));
            });
        }

        if ($request->filled('workshop_id')) {
            $query->where('workshop_id', $request->query('workshop_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $perPage = min((int) $request->query('per_page', 20), 100);

        return LmsSessionResource::collection($query->paginate($perPage))
            ->additional(['success' => true, 'message' => 'Sessions retrieved successfully']);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'workshop_id'    => ['nullable', 'exists:workshops,id'],
            'course_id'      => ['nullable', 'exists:courses,id'],
            'instructor_id'  => ['nullable', 'exists:instructors,id'],
            'title'          => ['required', 'string', 'max:255'],
            'description'    => ['nullable', 'string'],
            'session_date'   => ['required', 'date'],
            'start_time'     => ['required', 'date_format:H:i'],
            'end_time'       => ['required', 'date_format:H:i'],
            'meeting_url'    => ['nullable', 'string', 'max:2000'],
            'recording_url'  => ['nullable', 'string', 'max:2000'],
            'status'         => ['nullable', 'in:scheduled,live,completed,cancelled'],
        ]);

        $validated['status'] = $validated['status'] ?? 'scheduled';

        $session = LmsSession::query()->create($validated);

        return (new LmsSessionResource($session->load(['workshop', 'instructor'])))
            ->additional(['success' => true, 'message' => 'Session created successfully'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(LmsSession $session): JsonResponse
    {
        $session->load(['workshop', 'instructor', 'attendances.user']);

        return (new LmsSessionResource($session))
            ->additional(['success' => true, 'message' => 'Session retrieved successfully'])
            ->response();
    }

    public function update(Request $request, LmsSession $session): JsonResponse
    {
        $validated = $request->validate([
            'workshop_id'   => ['sometimes', 'nullable', 'exists:workshops,id'],
            'course_id'     => ['sometimes', 'nullable', 'exists:courses,id'],
            'instructor_id' => ['sometimes', 'nullable', 'exists:instructors,id'],
            'title'         => ['sometimes', 'string', 'max:255'],
            'description'   => ['nullable', 'string'],
            'session_date'  => ['sometimes', 'date'],
            'start_time'    => ['sometimes', 'date_format:H:i'],
            'end_time'      => ['sometimes', 'date_format:H:i'],
            'meeting_url'   => ['nullable', 'string', 'max:2000'],
            'recording_url' => ['nullable', 'string', 'max:2000'],
            'status'        => ['sometimes', 'in:scheduled,live,completed,cancelled'],
        ]);

        $session->update($validated);

        return (new LmsSessionResource($session->fresh()->load(['workshop', 'instructor'])))
            ->additional(['success' => true, 'message' => 'Session updated successfully'])
            ->response();
    }

    public function destroy(LmsSession $session): JsonResponse
    {
        $session->delete();

        return response()->json(['success' => true, 'message' => 'Session deleted successfully']);
    }
}
