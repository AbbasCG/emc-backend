<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\Registration;
use App\Notifications\CourseDateUpdatedNotification;
use App\Notifications\InstructorAssignedNotification;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminCourseController extends Controller
{
    // ─── GET /api/admin/courses ───────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $query = Course::with(['instructor.user', 'track', 'department'])
            ->withCount('registrations');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('instructor_id')) {
            $query->where('instructor_id', $request->query('instructor_id'));
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->query('department_id'));
        }

        if ($request->filled('track_id')) {
            $query->where('track_id', $request->query('track_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        // has_date=true → must have start_date, has_date=false → no start_date
        if ($request->has('has_date')) {
            $request->boolean('has_date')
                ? $query->whereNotNull('start_date')
                : $query->whereNull('start_date');
        }

        // has_instructor=true → must have instructor_id
        if ($request->has('has_instructor')) {
            $request->boolean('has_instructor')
                ? $query->whereNotNull('instructor_id')
                : $query->whereNull('instructor_id');
        }

        if ($request->filled('search')) {
            $q = (string) $request->query('search');
            $query->where(function ($sub) use ($q) {
                $sub->where('title', 'like', "%{$q}%")
                    ->orWhere('slug', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            });
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $courses = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $courses->map(fn ($c) => $this->format($c)),
            'meta'    => [
                'total'        => $courses->total(),
                'current_page' => $courses->currentPage(),
                'last_page'    => $courses->lastPage(),
                'per_page'     => $courses->perPage(),
            ],
        ]);
    }

    // ─── POST /api/admin/courses ──────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'track_id'            => 'nullable|exists:tracks,id',
            'instructor_id'       => 'nullable|exists:instructors,id',
            'department_id'       => 'nullable|exists:departments,id',
            'title'               => 'required|string|max:255',
            'description'         => 'nullable|string',
            'short_description'   => 'nullable|string',
            'type'                => 'required|in:free,paid',
            'price'               => 'nullable|numeric|min:0',
            'location'            => 'nullable|string|max:255',
            'location_type'       => 'nullable|in:online,offline,hybrid,unknown',
            'meeting_link'        => 'nullable|url|max:500',
            'is_online'           => 'boolean',
            'start_date'          => 'nullable|date',
            'end_date'            => 'nullable|date|after_or_equal:start_date',
            'capacity'            => 'nullable|integer|min:1',
            'registration_open'   => 'boolean',
            'status'              => 'nullable|in:draft,published,scheduled,completed,cancelled,active,archived',
            'duration'            => 'nullable|string|max:255',
            'training_hours'      => 'nullable|integer|min:0',
            'target_audience'     => 'nullable|string|max:255',
            'language'            => 'nullable|string|max:255',
            'level'               => 'nullable|string|max:255',
            'study_days'          => 'nullable|string|max:255',
            'study_time'          => 'nullable|string|max:255',
            'certificate'         => 'nullable|string|max:255',
            'course_image'        => 'nullable|string|max:500',
            'learning_outcomes'   => 'nullable|array',
            'learning_outcomes.*' => 'string|max:500',
            'features'            => 'nullable|array',
            'features.*'          => 'string|max:255',
        ]);

        $data['slug']       = $this->uniqueSlug($data['title']);
        $data['price']      = ($data['type'] === 'free') ? 0 : ($data['price'] ?? 0);
        $data['status']     = $data['status'] ?? 'draft';
        $data['created_by'] = $request->user()->id;

        $features = $data['features'] ?? [];
        unset($data['features']);

        $course = Course::create($data);

        foreach ($features as $i => $feat) {
            $course->features()->create(['title' => $feat, 'sort_order' => $i + 1]);
        }

        // Notify instructor if assigned at creation
        if ($course->instructor_id) {
            $this->notifyInstructor($course->instructor, 'course', $course->id, $course->title);
        }

        AuditLogService::logModel('created', $course, null,
            ['title' => $course->title, 'status' => $course->status], null, $request);

        $course->load(['instructor.user', 'track', 'features', 'department']);

        return response()->json([
            'success' => true,
            'message' => 'Course created successfully.',
            'data'    => $this->format($course),
        ], 201);
    }

    // ─── GET /api/admin/courses/{course} ─────────────────────────────────────
    public function show(Course $course): JsonResponse
    {
        $course->load(['instructor.user', 'track', 'features', 'department', 'createdBy', 'assignedBy'])
            ->loadCount('registrations');

        return response()->json([
            'success' => true,
            'data'    => $this->format($course),
        ]);
    }

    // ─── PUT /api/admin/courses/{course} ─────────────────────────────────────
    public function update(Request $request, Course $course): JsonResponse
    {
        $data = $request->validate([
            'track_id'            => 'nullable|exists:tracks,id',
            'instructor_id'       => 'nullable|exists:instructors,id',
            'department_id'       => 'nullable|exists:departments,id',
            'title'               => 'sometimes|string|max:255',
            'description'         => 'nullable|string',
            'short_description'   => 'nullable|string',
            'type'                => 'sometimes|in:free,paid',
            'price'               => 'nullable|numeric|min:0',
            'location'            => 'nullable|string|max:255',
            'location_type'       => 'nullable|in:online,offline,hybrid,unknown',
            'meeting_link'        => 'nullable|url|max:500',
            'is_online'           => 'boolean',
            'start_date'          => 'nullable|date',
            'end_date'            => 'nullable|date|after_or_equal:start_date',
            'capacity'            => 'nullable|integer|min:1',
            'registration_open'   => 'boolean',
            'status'              => 'nullable|in:draft,published,scheduled,completed,cancelled,active,archived',
            'duration'            => 'nullable|string|max:255',
            'training_hours'      => 'nullable|integer|min:0',
            'target_audience'     => 'nullable|string|max:255',
            'language'            => 'nullable|string|max:255',
            'level'               => 'nullable|string|max:255',
            'study_days'          => 'nullable|string|max:255',
            'study_time'          => 'nullable|string|max:255',
            'certificate'         => 'nullable|string|max:255',
            'course_image'        => 'nullable|string|max:500',
            'learning_outcomes'   => 'nullable|array',
            'learning_outcomes.*' => 'string|max:500',
            'features'            => 'nullable|array',
            'features.*'          => 'string|max:255',
        ]);

        $oldSnap = [
            'title'         => $course->title,
            'status'        => $course->status,
            'instructor_id' => $course->instructor_id,
            'start_date'    => $course->start_date?->format('Y-m-d'),
        ];

        $dateChanged = array_key_exists('start_date', $data)
            && ($data['start_date'] !== $course->start_date?->format('Y-m-d'));
        $instructorChanged = array_key_exists('instructor_id', $data)
            && ((int) $data['instructor_id'] !== (int) $course->instructor_id);

        if (isset($data['title'])) {
            $data['slug'] = $this->uniqueSlug($data['title'], $course->id);
        }

        if (isset($data['type'])) {
            $data['price'] = $data['type'] === 'free' ? 0 : ($data['price'] ?? $course->price);
        }

        $features = $data['features'] ?? null;
        unset($data['features']);

        $course->update($data);
        $fresh = $course->fresh(['instructor.user', 'track', 'features', 'department']);

        if ($features !== null) {
            $course->features()->delete();
            foreach ($features as $i => $feat) {
                $course->features()->create(['title' => $feat, 'sort_order' => $i + 1]);
            }
        }

        if ($instructorChanged && ! empty($data['instructor_id'])) {
            $instructor = Instructor::find($data['instructor_id']);
            if ($instructor) {
                $this->notifyInstructor($instructor, 'course', $course->id, $course->title);
            }
        }

        if ($dateChanged) {
            $this->notifyDateChange($course, $fresh->start_date?->format('Y-m-d'));
        }

        $action = (isset($data['status']) && $data['status'] !== $oldSnap['status']) ? 'status_changed' : 'updated';
        AuditLogService::logModel($action, $fresh, $oldSnap, [
            'title'      => $fresh->title,
            'status'     => $fresh->status,
            'start_date' => $fresh->start_date,
        ], null, $request);

        return response()->json([
            'success' => true,
            'message' => 'Course updated successfully.',
            'data'    => $this->format($fresh),
        ]);
    }

    // ─── DELETE /api/admin/courses/{course} ───────────────────────────────────
    public function destroy(Request $request, Course $course): JsonResponse
    {
        $snap  = ['title' => $course->title, 'status' => $course->status];
        $id    = $course->id;
        $label = $course->title;

        $course->delete();

        AuditLogService::log('deleted', 'Course', $id, $label, $snap, null, null, $request);

        return response()->json(['success' => true, 'message' => 'Course deleted successfully.']);
    }

    // ─── POST /api/admin/courses/{course}/assign-instructor ───────────────────
    public function assignInstructor(Request $request, Course $course): JsonResponse
    {
        $data = $request->validate([
            'instructor_id' => 'required|exists:instructors,id',
        ]);

        $oldInstructorId = $course->instructor_id;

        $course->update([
            'instructor_id' => $data['instructor_id'],
            'assigned_by'   => $request->user()->id,
        ]);

        $instructor = Instructor::find($data['instructor_id']);
        if ($instructor) {
            $this->notifyInstructor($instructor, 'course', $course->id, $course->title);
        }

        AuditLogService::log('updated', 'Course', $course->id, $course->title,
            ['instructor_id' => $oldInstructorId],
            ['instructor_id' => $data['instructor_id'], 'assigned_by' => $request->user()->id],
            ['action' => 'assign_instructor'],
            $request
        );

        $course->load(['instructor.user', 'track', 'department']);

        return response()->json([
            'success' => true,
            'message' => 'Instructor assigned successfully.',
            'data'    => $this->format($course),
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function notifyInstructor(Instructor $instructor, string $entityType, int $entityId, string $title): void
    {
        if ($instructor->user) {
            $instructor->user->notify(new InstructorAssignedNotification($entityType, $entityId, $title));
        }
    }

    private function notifyDateChange(Course $course, ?string $newDate): void
    {
        $course->loadMissing(['instructor.user']);
        $notification = new CourseDateUpdatedNotification($course->id, $course->title, $newDate);

        if ($course->instructor?->user) {
            $course->instructor->user->notify($notification);
        }

        Registration::where('course_id', $course->id)
            ->whereNotNull('user_id')
            ->whereIn('status', ['Registered', 'Pending Payment', 'Payment Confirmed'])
            ->with('user:id')
            ->get()
            ->each(fn ($reg) => $reg->user?->notify($notification));
    }

    private function format(Course $course): array
    {
        return [
            'id'                  => $course->id,
            'title'               => $course->title,
            'slug'                => $course->slug,
            'description'         => $course->description,
            'short_description'   => $course->short_description,
            'type'                => $course->type,
            'price'               => (float) $course->price,
            'location'            => $course->location,
            'location_type'       => $course->location_type ?? 'unknown',
            'meeting_link'        => $course->meeting_link,
            'is_online'           => $course->is_online,
            'start_date'          => $course->start_date,
            'end_date'            => $course->end_date,
            'capacity'            => $course->capacity,
            'registration_open'   => $course->registration_open ?? true,
            'next_cohort_pending' => $course->start_date === null,
            'status'              => $course->status,
            'duration'            => $course->duration,
            'training_hours'      => $course->training_hours,
            'target_audience'     => $course->target_audience,
            'language'            => $course->language,
            'level'               => $course->level,
            'study_days'          => $course->study_days,
            'study_time'          => $course->study_time,
            'certificate'         => $course->certificate,
            'course_image'        => $course->course_image,
            'learning_outcomes'   => $course->learning_outcomes ?? [],
            'instructor'          => $course->instructor ? [
                'id'        => $course->instructor->id,
                'name'      => $course->instructor->name,
                'title'     => $course->instructor->title,
                'image'     => $course->instructor->image,
                'user_id'   => $course->instructor->user_id,
                'user_name' => $course->instructor->user?->name,
            ] : null,
            'track'               => $course->track ? [
                'id'    => $course->track->id,
                'title' => $course->track->title,
                'slug'  => $course->track->slug,
            ] : null,
            'department'          => $course->department ? [
                'id'   => $course->department->id,
                'name' => $course->department->name,
            ] : null,
            'created_by'          => $course->createdBy ? [
                'id'   => $course->createdBy->id,
                'name' => $course->createdBy->name,
            ] : null,
            'registrations_count' => $course->registrations_count ?? null,
            'features'            => $course->features?->pluck('title')->all() ?? [],
            'created_at'          => $course->created_at,
            'updated_at'          => $course->updated_at,
        ];
    }

    private function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base  = Str::slug($title);
        $slug  = $base;
        $count = 1;

        while (
            Course::query()
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $base . '-' . $count++;
        }

        return $slug;
    }
}
