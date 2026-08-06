<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CourseSessionResource;
use App\Http\Resources\LmsSessionResource;
use App\Models\CourseSession;
use App\Models\LmsSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminLmsSessionController extends Controller
{
    /**
     * GET /api/admin/lms/sessions
     * Returns all course sessions with link-open tracking data.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = CourseSession::query()
            ->with(['course.instructor.user', 'linkOpens.user'])
            ->withCount(['attendances', 'linkOpens'])
            ->latest('start_at');

        if ($request->filled('course_id')) {
            $query->where('course_id', $request->query('course_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('location_type')) {
            $query->where('location_type', $request->query('location_type'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('start_at', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('start_at', '<=', $request->query('date_to'));
        }

        $perPage = min((int) $request->query('per_page', 100), 500);

        return CourseSessionResource::collection($query->paginate($perPage))
            ->additional(['success' => true]);
    }

    /**
     * GET /api/admin/lms/sessions/{session}
     */
    public function show(CourseSession $session): JsonResponse
    {
        $session->load(['course.instructor.user', 'attendances', 'linkOpens.user']);
        $session->loadCount(['attendances', 'linkOpens']);

        return (new CourseSessionResource($session))
            ->additional(['success' => true])
            ->response();
    }

    /**
     * GET /api/admin/lms/sessions/{session}/link-opens
     * Returns all students who opened the link for this session.
     */
    public function linkOpens(CourseSession $session): JsonResponse
    {
        $opens = $session->linkOpens()->with('user:id,name,email')->orderBy('opened_at')->get();

        return response()->json([
            'success' => true,
            'data'    => $opens->map(fn ($lo) => [
                'id'        => $lo->user?->id,
                'name'      => $lo->user?->name,
                'email'     => $lo->user?->email,
                'opened_at' => $lo->opened_at?->toISOString(),
            ])->values(),
            'meta' => ['total' => $opens->count()],
        ]);
    }

    /** Write operations target course_sessions so new sessions appear in the card grid. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'course_id'     => ['nullable', 'exists:courses,id'],
            'title'         => ['required', 'string', 'max:255'],
            'description'   => ['nullable', 'string'],
            'session_date'  => ['required', 'date'],
            'start_time'    => ['required', 'date_format:H:i'],
            'end_time'      => ['nullable', 'date_format:H:i'],
            'location_type' => ['nullable', 'in:online,offline,hybrid'],
            'meeting_link'  => ['nullable', 'string', 'max:2000'],
            'status'        => ['nullable', 'in:scheduled,live,completed,cancelled,published,draft'],
        ]);

        $date      = $validated['session_date'];
        $startAt   = $date . ' ' . $validated['start_time'] . ':00';
        $endAt     = isset($validated['end_time']) ? $date . ' ' . $validated['end_time'] . ':00' : null;

        $session = CourseSession::query()->create([
            'course_id'     => $validated['course_id'] ?? null,
            'title'         => $validated['title'],
            'description'   => $validated['description'] ?? null,
            'start_at'      => $startAt,
            'end_at'        => $endAt,
            'location_type' => $validated['location_type'] ?? 'online',
            'meeting_link'  => $validated['meeting_link'] ?? null,
            'status'        => $validated['status'] ?? 'scheduled',
        ]);

        return (new CourseSessionResource($session->load('course.instructor.user')))
            ->additional(['success' => true])
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, CourseSession $session): JsonResponse
    {
        $validated = $request->validate([
            'course_id'     => ['sometimes', 'nullable', 'exists:courses,id'],
            'title'         => ['sometimes', 'string', 'max:255'],
            'description'   => ['nullable', 'string'],
            'session_date'  => ['sometimes', 'date'],
            'start_time'    => ['sometimes', 'date_format:H:i'],
            'end_time'      => ['nullable', 'date_format:H:i'],
            'location_type' => ['sometimes', 'nullable', 'in:online,offline,hybrid'],
            'meeting_link'  => ['nullable', 'string', 'max:2000'],
            'status'        => ['sometimes', 'in:scheduled,live,completed,cancelled,published,draft'],
        ]);

        // Merge session_date + start_time / end_time into start_at / end_at
        if (isset($validated['session_date']) || isset($validated['start_time'])) {
            $date  = $validated['session_date'] ?? $session->start_at?->toDateString();
            if (isset($validated['start_time'])) {
                $validated['start_at'] = $date . ' ' . $validated['start_time'] . ':00';
            }
            if (isset($validated['end_time'])) {
                $validated['end_at'] = $date . ' ' . $validated['end_time'] . ':00';
            }
        }

        unset($validated['session_date'], $validated['start_time'], $validated['end_time']);

        $session->update($validated);

        return (new CourseSessionResource($session->fresh()->load('course.instructor.user')))
            ->additional(['success' => true])
            ->response();
    }

    public function destroy(CourseSession $session): JsonResponse
    {
        $session->delete();

        return response()->json(['success' => true, 'message' => 'Session deleted successfully']);
    }
}
