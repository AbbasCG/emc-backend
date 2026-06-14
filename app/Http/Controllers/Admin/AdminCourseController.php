<?php

namespace App\Http\Controllers\Admin;

use App\Events\CourseCreatedEvent;
use App\Events\CourseUpdatedEvent;
use App\Events\InstructorAssignedEvent;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Instructor;
use App\Services\AuditLogService;
use App\Support\CourseDisplay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        if ($request->has('has_date')) {
            $request->boolean('has_date')
                ? $query->whereNotNull('start_date')
                : $query->whereNull('start_date');
        }
        if ($request->has('has_instructor')) {
            $request->boolean('has_instructor')
                ? $query->whereNotNull('instructor_id')
                : $query->whereNull('instructor_id');
        }
        if ($request->filled('search')) {
            $q = (string) $request->query('search');
            $query->where(fn ($sub) => $sub
                ->where('title', 'like', "%{$q}%")
                ->orWhere('slug', 'like', "%{$q}%")
                ->orWhere('description', 'like', "%{$q}%")
            );
        }

        $perPage  = min((int) $request->query('per_page', 20), 100);
        $courses  = $query->latest()->paginate($perPage);

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
        $logPayload = $request->all();
        if ($request->hasFile('course_image')) {
            $logPayload['course_image'] = '[BINARY_FILE]';
        }
        if ($request->hasFile('image')) {
            $logPayload['image'] = '[BINARY_FILE]';
        }
        Log::info('admin.course.store', $logPayload);

        $data = $this->validateCourse($request, null);

        $data['slug']       = $data['slug'] ?? $this->uniqueSlug($data['title']);
        $data['created_by'] = $request->user()->id;
        $data['status']     = $data['status'] ?? 'draft';

        // Derive type from is_free if type not sent
        if (empty($data['type'])) {
            $data['type'] = ($data['is_free'] ?? false) ? 'free' : 'paid';
        }
        $data['price'] = ($data['type'] === 'free') ? 0 : ($data['price'] ?? 0);

        // Path string in DB (e.g. courses/abc.jpg) — never the uploaded object
        $data['course_image'] = $this->handleImageUpload($request, null);

        $features = $data['features'] ?? [];
        unset($data['features']);

        $course = Course::create($data);

        foreach ($features as $i => $feat) {
            $course->features()->create(['title' => $feat, 'sort_order' => $i + 1]);
        }

        event(new CourseCreatedEvent($course, $request->user()->id));

        if ($course->instructor_id) {
            $instructor = Instructor::find($course->instructor_id);
            if ($instructor) {
                event(new InstructorAssignedEvent($course, $instructor, $request->user()->id));
            }
        }

        AuditLogService::logModel('created', $course, null,
            ['title' => $course->title, 'status' => $course->status], null, $request);

        $course->load(['instructor.user', 'track', 'features', 'department']);

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء الدورة بنجاح.',
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

    // ─── PUT/PATCH /api/admin/courses/{course} ────────────────────────────────
    public function update(Request $request, Course $course): JsonResponse
    {
        $logPayload = $request->all();
        if ($request->hasFile('course_image')) {
            $logPayload['course_image'] = '[BINARY_FILE]';
        }
        if ($request->hasFile('image')) {
            $logPayload['image'] = '[BINARY_FILE]';
        }
        Log::info('admin.course.update', ['course_id' => $course->id] + $logPayload);

        $data = $this->validateCourse($request, $course);

        $oldSnap = [
            'title'         => $course->title,
            'status'        => $course->status,
            'instructor_id' => $course->instructor_id,
            'start_date'    => $this->dateStr($course->start_date),
        ];

        $dateChanged = array_key_exists('start_date', $data)
            && ($data['start_date'] !== $this->dateStr($course->start_date));
        $instructorChanged = array_key_exists('instructor_id', $data)
            && ((int) $data['instructor_id'] !== (int) $course->instructor_id);

        if (isset($data['slug'])) {
            $data['slug'] = Str::slug($data['slug']) ?: $data['slug'];
        } elseif (isset($data['title'])) {
            $data['slug'] = $course->slug;
        }

        if (isset($data['type'])) {
            $data['price'] = $data['type'] === 'free' ? 0 : ($data['price'] ?? $course->price);
        }

        // Handle image upload (only overwrite if a new file or new string is provided)
        $newImage = $this->handleImageUpload($request, $course->course_image);
        if ($newImage !== $course->course_image) {
            $data['course_image'] = $newImage;
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
                event(new InstructorAssignedEvent($course, $instructor, $request->user()->id));
            }
        }

        $changes = [];
        if ($dateChanged) {
            $changes['start_date'] = $this->dateStr($fresh->start_date);
        }
        if (isset($data['status']) && $data['status'] !== $oldSnap['status']) {
            $changes['status'] = $data['status'];
        }
        if (! empty($data['registration_open'])) {
            $changes['registration_open'] = true;
        }
        if (! empty($changes)) {
            event(new CourseUpdatedEvent($fresh, $changes));
        }

        $action = (isset($data['status']) && $data['status'] !== $oldSnap['status']) ? 'status_changed' : 'updated';
        AuditLogService::logModel($action, $fresh, $oldSnap, [
            'title'      => $fresh->title,
            'status'     => $fresh->status,
            'start_date' => $fresh->start_date,
        ], null, $request);

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث الدورة بنجاح.',
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

        return response()->json(['success' => true, 'message' => 'تم حذف الدورة بنجاح.']);
    }

    // ─── POST /api/admin/courses/{course}/assign-instructor ───────────────────
    public function assignInstructor(Request $request, Course $course): JsonResponse
    {
        $data = $request->validate([
            'instructor_id' => ['nullable', 'integer', 'exists:instructors,id'],
            'user_id'       => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if (empty($data['instructor_id']) && ! empty($data['user_id'])) {
            $found = Instructor::where('user_id', $data['user_id'])->first();
            abort_unless($found, 422, 'المستخدم ليس لديه ملف مدرب.');
            $data['instructor_id'] = $found->id;
        }

        abort_unless(! empty($data['instructor_id']), 422, 'instructor_id أو user_id مطلوب.');

        $oldInstructorId = $course->instructor_id;

        $course->update([
            'instructor_id' => $data['instructor_id'],
            'assigned_by'   => $request->user()->id,
        ]);

        $instructor = Instructor::find($data['instructor_id']);
        if ($instructor) {
            event(new InstructorAssignedEvent($course, $instructor, $request->user()->id));
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
            'message' => 'تم تعيين المدرب بنجاح.',
            'data'    => $this->format($course),
        ]);
    }

    // ─── Shared validation ────────────────────────────────────────────────────

    private function validateCourse(Request $request, ?Course $course): array
    {
        $isUpdate = $course !== null;

        if ($request->has('session_format')) {
            $normalized = CourseDisplay::normalizeSessionFormatInput($request->input('session_format'));
            $request->merge(['session_format' => $normalized]);
        }

        if ($request->filled('slug')) {
            $slug = Str::slug((string) $request->input('slug')) ?: (string) $request->input('slug');
            $request->merge(['slug' => $slug]);
        } elseif (! $isUpdate && $request->filled('title')) {
            $slug = Str::slug((string) $request->input('title'));
            if ($slug !== '') {
                $request->merge(['slug' => $slug]);
            }
        }

        $messages = [
            'required'               => 'الحقل مطلوب.',
            'title.required'         => 'الحقل مطلوب.',
            'status.in'              => 'حالة النشر غير صحيحة.',
            'learning_outcomes.array'        => 'نوع البيانات غير صحيح.',
            'learning_outcomes.*.string'     => 'نوع البيانات غير صحيح.',
            'learning_outcomes.*.max'          => 'كل مخرج تعليمي يجب ألا يتجاوز 500 حرف.',
            'requirements.array'               => 'نوع البيانات غير صحيح.',
            'requirements.*.string'            => 'نوع البيانات غير صحيح.',
            'requirements.*.max'               => 'كل متطلب يجب ألا يتجاوز 500 حرف.',
            'curriculum_topics.array'        => 'نوع البيانات غير صحيح.',
            'curriculum_topics.*.string'     => 'نوع البيانات غير صحيح.',
            'curriculum_topics.*.max'        => 'كل محور يجب ألا يتجاوز 500 حرف.',
            'features.array'                 => 'نوع البيانات غير صحيح.',
            'features.*.string'              => 'نوع البيانات غير صحيح.',
            'features.*.max'                 => 'كل نقطة يجب ألا تتجاوز 255 حرفاً.',
            'keywords.*.max'                 => 'كل كلمة مفتاحية يجب ألا تتجاوز 100 حرف.',
        ];

        $attributes = [
            'status'           => 'حالة النشر',
            'course_image'     => 'صورة الغلاف',
            'learning_outcomes' => 'المخرجات التعليمية',
            'features'         => 'ماذا ستتعلم',
        ];

        $rules = [
            'track_id'              => 'nullable|exists:tracks,id',
            'instructor_id'         => 'nullable|exists:instructors,id',
            'department_id'         => 'nullable|exists:departments,id',
            'title'                 => [
                $isUpdate ? 'sometimes' : 'required',
                'string',
                'max:255',
                Rule::unique('courses', 'title')->ignore($course?->id),
            ],
            'slug'                  => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('courses', 'slug')->ignore($course?->id),
            ],
            'description'           => 'nullable|string',
            'short_description'     => 'nullable|string',
            'type'                  => 'nullable|in:free,paid',
            'price'                 => 'nullable|numeric|min:0',
            'is_free'               => 'nullable|boolean',
            'program_type'          => 'nullable|in:course,workshop,one_session,full_program',
            'content_type'          => 'nullable|in:course,workshop,one_session,full_program',
            'session_format'        => 'nullable|in:online,offline,hybrid,workshop_single_session,workshop_one_session',
            'location'              => 'nullable|string|max:255',
            // Accept both delivery_type (frontend alias) and location_type (DB column name)
            'delivery_type'         => 'nullable|in:online,offline,hybrid,unknown',
            'location_type'         => 'nullable|in:online,offline,hybrid,unknown',
            'meeting_link'          => 'nullable|string|max:500',
            'is_online'             => 'nullable|boolean',
            'start_date'            => 'nullable|date',
            'start_time'            => 'nullable|date_format:H:i',
            'end_date'              => 'nullable|date',
            'end_time'              => 'nullable|date_format:H:i',
            'capacity'              => 'nullable|integer|min:1',
            'seats_count'           => 'nullable|integer|min:1',   // alias for capacity
            'registration_open'     => 'nullable|boolean',
            'registration_status'   => 'nullable|in:open,closed,coming_soon',
            'status'                => 'nullable|in:draft,published,archived',
            'notes'                 => 'nullable|string',
            'duration'              => 'nullable|string|max:255',
            'training_hours'        => 'nullable|integer|min:0',
            'hours_count'           => 'nullable|integer|min:0',   // alias for training_hours
            'target_audience'       => 'nullable|string|max:255',
            'language'              => 'nullable|string|max:255',
            'level'                 => 'nullable|string|max:255',
            'study_days'            => 'nullable|string|max:255',
            'study_time'            => 'nullable|string|max:255',
            'certificate'           => 'nullable|string|max:255',
            'certificate_available' => 'nullable|boolean',
            'certificate_type'      => 'nullable|string|max:100',
            'learning_outcomes'     => 'nullable|array',
            'learning_outcomes.*'   => 'string|max:500',
            'requirements'          => 'nullable|array',
            'requirements.*'        => 'string|max:500',
            'curriculum_topics'     => 'nullable|array',
            'curriculum_topics.*'   => 'string|max:500',
            'keywords'              => 'nullable|array',
            'keywords.*'            => 'string|max:100',
            'features'              => 'nullable|array',
            'features.*'            => 'string|max:255',
            'requires_placement_test' => 'nullable|boolean',
            'course_thumbnail'        => 'nullable|string',
        ];

        if ($request->hasFile('course_image')) {
            $rules['course_image'] = 'nullable|file|mimes:jpg,jpeg,png,webp,gif|max:4096';
        } elseif ($request->hasFile('image')) {
            $rules['image'] = 'nullable|file|mimes:jpg,jpeg,png,webp,gif|max:4096';
            $rules['course_image'] = 'nullable';
        } else {
            // Accept string URL or null — no type enforcement so a URL of any length works
            $rules['course_image'] = 'nullable';
            $rules['image']        = 'nullable';
        }

        try {
            $data = $request->validate($rules, $messages, $attributes);
        } catch (ValidationException $e) {
            Log::warning('admin.course.validation_failed', [
                'course_id' => $course?->id,
                'errors'    => $e->errors(),
            ]);
            throw $e;
        }

        // Remove file objects from the validated data array — handleImageUpload handles them
        unset($data['course_image'], $data['image']);

        // Map delivery_type → location_type (frontend alias)
        if (isset($data['delivery_type']) && ! isset($data['location_type'])) {
            $data['location_type'] = $data['delivery_type'];
        }
        unset($data['delivery_type']);

        // Map seats_count → capacity (frontend alias)
        if (isset($data['seats_count']) && ! isset($data['capacity'])) {
            $data['capacity'] = $data['seats_count'];
        }
        unset($data['seats_count']);

        // Map hours_count → training_hours (frontend alias)
        if (isset($data['hours_count']) && ! isset($data['training_hours'])) {
            $data['training_hours'] = $data['hours_count'];
        }
        unset($data['hours_count']);

        // Map content_type → program_type (frontend alias)
        if (isset($data['content_type']) && ! isset($data['program_type'])) {
            $data['program_type'] = $data['content_type'];
        }
        unset($data['content_type']);

        if (isset($data['training_hours'])) {
            $data['training_hours'] = (int) round((float) $data['training_hours']);
        }

        return CourseDisplay::applyOneSessionDefaults($data);
    }

    // ─── Image upload helper ──────────────────────────────────────────────────

    private function handleImageUpload(Request $request, ?string $existing): ?string
    {
        if ($request->hasFile('course_image') && $request->file('course_image')->isValid()) {
            if ($existing && ! str_starts_with($existing, 'http')) {
                Storage::disk('public')->delete($existing);
            }

            return $request->file('course_image')->store('courses', 'public');
        }

        // Legacy: `image` field name
        if ($request->hasFile('image') && $request->file('image')->isValid()) {
            if ($existing && ! str_starts_with($existing, 'http')) {
                Storage::disk('public')->delete($existing);
            }

            return $request->file('image')->store('courses', 'public');
        }

        if ($request->filled('course_image') && ! $request->hasFile('course_image')) {
            return $request->input('course_image');
        }

        return $existing;
    }

    // ─── Response formatter ───────────────────────────────────────────────────

    private function format(Course $course): array
    {
        $imageUrl = $this->storageUrl($course->course_image);

        return [
            'id'                    => $course->id,
            'title'                 => $course->title,
            'slug'                  => $course->slug,
            'description'           => $course->description,
            'short_description'     => $course->short_description,
            'type'                  => $course->type,
            'price'                 => (float) $course->price,
            'is_free'               => (bool) $course->is_free,
            'program_type'          => $course->program_type,
            'content_type'          => $course->program_type,
            'session_format'        => $course->session_format,
            'requires_placement_test' => (bool) $course->requires_placement_test,
            'location'              => $course->location,
            'delivery_type'         => $course->location_type ?? 'online',
            'location_type'         => $course->location_type ?? 'online',
            'meeting_link'          => $course->meeting_link,
            'is_online'             => (bool) $course->is_online,
            'start_date'            => $this->dateStr($course->start_date),
            'start_time'            => $course->start_time,
            'end_date'              => $this->dateStr($course->end_date),
            'end_time'              => $course->end_time,
            'capacity'              => $course->capacity,
            'seats_count'           => $course->capacity,
            'registration_open'     => (bool) ($course->registration_open ?? true),
            'registration_status'   => $course->registration_status ?? 'open',
            'next_cohort_pending'   => $course->start_date === null,
            'status'                => $course->status,
            'notes'                 => $course->notes,
            'duration'              => CourseDisplay::resolvedDuration(
                $course->program_type,
                $course->session_format,
                $course->duration
            ),
            'duration_value'        => CourseDisplay::isOneSession($course->program_type, $course->session_format) ? 1 : null,
            'duration_unit'         => CourseDisplay::isOneSession($course->program_type, $course->session_format) ? 'day' : null,
            'computed_duration_label' => CourseDisplay::resolvedDuration(
                $course->program_type,
                $course->session_format,
                $course->duration
            ),
            'training_hours'        => $course->training_hours,
            'hours_count'           => $course->training_hours,
            'target_audience'       => $course->target_audience,
            'language'              => $course->language,
            'level'                 => $course->level,
            'study_days'            => $course->study_days,
            'study_time'            => $course->study_time,
            'certificate'           => $course->certificate,
            'certificate_available' => (bool) $course->certificate_available,
            'certificate_type'      => $course->certificate_type,
            'course_image'          => $imageUrl,
            'image_url'             => $imageUrl,
            'thumbnail_url'         => $this->storageUrl($course->course_thumbnail),
            'learning_outcomes'     => $course->learning_outcomes   ?? [],
            'requirements'          => $course->requirements        ?? [],
            'curriculum_topics'     => $course->curriculum_topics   ?? [],
            'keywords'              => $course->keywords            ?? [],
            'instructor'            => $course->instructor ? [
                'id'               => $course->instructor->id,
                'user_id'          => $course->instructor->user_id,
                'name'             => $course->instructor->user?->name ?? $course->instructor->name,
                'email'            => $course->instructor->user?->email,
                'role'             => $course->instructor->user?->role ?? 'instructor',
                'title'            => $course->instructor->title,
                'profile_photo_url' => $this->storageUrl(
                    $course->instructor->user?->avatar ?? $course->instructor->image
                ),
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

    /** Safely formats any date value (Carbon, string, or null) to Y-m-d. */
    private function dateStr(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }
        return \Carbon\Carbon::parse($value)->format('Y-m-d');
    }

    /** Returns a full public URL for a storage path, or null if empty. */
    private function storageUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        if (str_starts_with($path, 'http')) {
            return $path;
        }
        return asset('storage/' . ltrim($path, '/'));
    }

    private function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base  = Str::slug($title);
        $slug  = $base ?: 'course';
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
