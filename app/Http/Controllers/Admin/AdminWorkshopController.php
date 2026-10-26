<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Instructor;
use App\Models\Workshop;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminWorkshopController extends Controller
{
    // GET /api/admin/workshops
    public function index(Request $request): JsonResponse
    {
        $workshops = Workshop::with(['course:id,title,slug', 'instructor'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', $request->query('course_id')))
            ->when($request->filled('instructor_id'), fn ($q) => $q->where('instructor_id', $request->query('instructor_id')))
            ->when($request->has('has_instructor'), fn ($q) => $request->boolean('has_instructor')
                ? $q->whereNotNull('instructor_id')
                : $q->whereNull('instructor_id'))
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'like', "%{$request->query('search')}%"))
            ->orderBy('sort_order')
            ->paginate(min((int) $request->query('per_page', 20), 100));

        return response()->json([
            'success' => true,
            'data'    => $workshops->map(fn ($w) => $this->format($w)),
            'meta'    => [
                'total'        => $workshops->total(),
                'current_page' => $workshops->currentPage(),
                'last_page'    => $workshops->lastPage(),
                'per_page'     => $workshops->perPage(),
            ],
        ]);
    }

    // POST /api/admin/workshops
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'course_id'         => 'required|exists:courses,id',
            'instructor_id'     => 'nullable|exists:instructors,id',
            'title'             => 'required|string|max:255',
            'description'       => 'nullable|string',
            'status'            => 'nullable|in:active,draft,archived,published,scheduled,completed,cancelled',
            'start_date'        => 'required|date',
            'start_time'        => 'nullable|date_format:H:i',
            'end_date'          => 'required|date|after_or_equal:start_date',
            'end_time'          => 'nullable|date_format:H:i',
            'location_type'     => 'nullable|in:online,offline,hybrid,unknown',
            'meeting_link'      => 'nullable|url|max:500',
            'registration_open' => 'boolean',
            'sort_order'        => 'nullable|integer|min:0',
        ]);

        $data['slug']   = $this->uniqueSlug($data['title']);
        $data['status'] = $data['status'] ?? 'draft';
        if (isset($data['instructor_id'])) {
            $data['assigned_by'] = $request->user()->id;
        }

        $workshop = Workshop::create($data);

        if ($workshop->instructor_id) {
            $this->notifyInstructor($workshop->instructor, 'workshop', $workshop->id, $workshop->title, $request->user()->id);
        }

        AuditLogService::logModel('created', $workshop, null,
            ['title' => $workshop->title, 'status' => $workshop->status], null, $request);

        return response()->json([
            'success' => true,
            'message' => 'Workshop created successfully',
            'data'    => $this->format($workshop->load(['course:id,title,slug', 'instructor'])),
        ], 201);
    }

    // GET /api/admin/workshops/{workshop}
    public function show(Workshop $workshop): JsonResponse
    {
        $workshop->load(['course:id,title,slug', 'instructor', 'sessions', 'materials', 'assignments']);

        return response()->json(['success' => true, 'data' => $this->format($workshop)]);
    }

    // PUT /api/admin/workshops/{workshop}
    public function update(Request $request, Workshop $workshop): JsonResponse
    {
        $data = $request->validate([
            'course_id'         => 'sometimes|exists:courses,id',
            'instructor_id'     => 'nullable|exists:instructors,id',
            'title'             => 'sometimes|string|max:255',
            'description'       => 'nullable|string',
            'status'            => 'sometimes|in:active,draft,archived,published,scheduled,completed,cancelled',
            'start_date'        => 'sometimes|required|date',
            'start_time'        => 'nullable|date_format:H:i',
            'end_date'          => 'sometimes|required|date|after_or_equal:start_date',
            'end_time'          => 'nullable|date_format:H:i',
            'location_type'     => 'nullable|in:online,offline,hybrid,unknown',
            'meeting_link'      => 'nullable|url|max:500',
            'registration_open' => 'boolean',
            'sort_order'        => 'nullable|integer|min:0',
        ]);

        if (isset($data['title'])) {
            $data['slug'] = $this->uniqueSlug($data['title'], $workshop->id);
        }

        $oldSnap = ['title' => $workshop->title, 'status' => $workshop->status, 'instructor_id' => $workshop->instructor_id];

        $instructorChanged = array_key_exists('instructor_id', $data)
            && ((int) $data['instructor_id'] !== (int) $workshop->instructor_id);

        if ($instructorChanged && ! empty($data['instructor_id'])) {
            $data['assigned_by'] = $request->user()->id;
        }

        $workshop->update($data);
        $fresh = $workshop->fresh(['course:id,title,slug', 'instructor']);

        if ($instructorChanged && ! empty($data['instructor_id'])) {
            $this->notifyInstructor($fresh->instructor, 'workshop', $workshop->id, $workshop->title, $request->user()->id);
        }

        $action = (isset($data['status']) && $data['status'] !== $oldSnap['status']) ? 'status_changed' : 'updated';
        AuditLogService::logModel($action, $fresh, $oldSnap,
            ['title' => $fresh->title, 'status' => $fresh->status], null, $request);

        return response()->json([
            'success' => true,
            'message' => 'Workshop updated successfully',
            'data'    => $this->format($fresh),
        ]);
    }

    // DELETE /api/admin/workshops/{workshop}
    public function destroy(Workshop $workshop, Request $request): JsonResponse
    {
        $snap  = ['title' => $workshop->title, 'status' => $workshop->status];
        $label = $workshop->title;
        $id    = $workshop->id;

        $workshop->delete();

        AuditLogService::log('deleted', 'Workshop', $id, $label, $snap, null, null, $request);

        return response()->json(['success' => true, 'message' => 'Workshop deleted successfully']);
    }

    // POST /api/admin/workshops/{workshop}/assign-instructor
    public function assignInstructor(Request $request, Workshop $workshop): JsonResponse
    {
        $data = $request->validate([
            'instructor_id' => 'required|exists:instructors,id',
        ]);

        $oldInstructorId = $workshop->instructor_id;

        $workshop->update([
            'instructor_id' => $data['instructor_id'],
            'assigned_by'   => $request->user()->id,
        ]);

        $instructor = Instructor::find($data['instructor_id']);
        if ($instructor) {
            $this->notifyInstructor($instructor, 'workshop', $workshop->id, $workshop->title, $request->user()->id);
        }

        AuditLogService::log('updated', 'Workshop', $workshop->id, $workshop->title,
            ['instructor_id' => $oldInstructorId],
            ['instructor_id' => $data['instructor_id'], 'assigned_by' => $request->user()->id],
            ['action' => 'assign_instructor'],
            $request
        );

        $workshop->load(['course:id,title,slug', 'instructor']);

        return response()->json([
            'success' => true,
            'message' => 'Instructor assigned to workshop successfully.',
            'data'    => $this->format($workshop),
        ]);
    }

    private function notifyInstructor(?Instructor $instructor, string $entityType, int $entityId, string $title, int $assignedByUserId = 0): void
    {
        if ($instructor?->user_id) {
            NotificationService::workshopInstructorAssigned($instructor->user_id, $entityId, $title, $assignedByUserId);
        }
    }

    private function format(Workshop $w): array
    {
        return [
            'id'               => $w->id,
            'title'            => $w->title,
            'slug'             => $w->slug,
            'description'      => $w->description,
            'status'           => $w->status,
            'start_date'       => $w->start_date,
            'start_time'       => $w->start_time,
            'end_date'         => $w->end_date,
            'end_time'         => $w->end_time,
            'location_type'    => $w->location_type,
            'meeting_link'     => $w->meeting_link,
            'registration_open'=> $w->registration_open ?? false,
            'sort_order'       => $w->sort_order,
            'instructor'       => $w->instructor ? [
                'id'       => $w->instructor->id,
                'name'     => $w->instructor->name,
                'user_id'  => $w->instructor->user_id,
            ] : null,
            'course'           => $w->course ? ['id' => $w->course->id, 'title' => $w->course->title] : null,
            'created_at'       => $w->created_at,
        ];
    }

    private function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base  = Str::slug($title);
        $slug  = $base;
        $count = 1;

        while (
            Workshop::query()
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $base . '-' . $count++;
        }

        return $slug;
    }
}
