<?php

namespace App\Http\Controllers;

use App\Http\Resources\CourseResource;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class CourseController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min((int) $request->query('per_page', 15), 100);

        $query = Course::with(['instructor.user', 'features', 'track'])
            ->withCount('registrations')
            ->where('status', 'published')
            // registration_open defaults true; NULL means old row created before column existed
            ->where(function ($q) {
                $q->whereNull('registration_open')
                  ->orWhere('registration_open', true);
            })
            ->latest();

        if ($request->filled('track_id')) {
            $query->where('track_id', $request->query('track_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->filled('search')) {
            $term = (string) $request->query('search');
            $query->where(fn ($q) => $q->where('title', 'like', "%{$term}%")
                ->orWhere('short_description', 'like', "%{$term}%"));
        }

        $courses = $query->paginate($perPage);

        return CourseResource::collection($courses)
            ->additional([
                'success' => true,
                'message' => 'Courses retrieved successfully',
            ]);
    }

    public function show(string $slug): JsonResponse
    {
        $course = Course::with(['instructor.user', 'features', 'track'])
            ->withCount('registrations')
            ->where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        return (new CourseResource($course))
            ->additional([
                'success' => true,
                'message' => 'Course retrieved successfully',
            ])
            ->response();
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'track_id'         => 'nullable|exists:tracks,id',
            'instructor_id'    => 'nullable|exists:instructors,id',
            'title'            => 'required|string|max:255',
            'description'      => 'nullable|string',
            'short_description' => 'nullable|string',
            'type'             => 'required|in:free,paid',
            'price'            => 'nullable|numeric|min:0',
            'location'         => 'nullable|string|max:255',
            'is_online'        => 'boolean',
            'start_date'       => 'nullable|date',
            'end_date'         => 'nullable|date|after_or_equal:start_date',
            'capacity'         => 'nullable|integer|min:1',
            'status'           => 'nullable|in:active,draft,archived',
            'duration'         => 'nullable|string|max:255',
            'training_hours'   => 'nullable|integer|min:0',
            'target_audience'  => 'nullable|string|max:255',
            'language'         => 'nullable|string|max:255',
            'level'            => 'nullable|string|max:255',
            'study_days'       => 'nullable|string|max:255',
            'study_time'       => 'nullable|string|max:255',
            'certificate'      => 'nullable|string|max:255',
            'course_image'     => 'nullable|string|max:255',
            'learning_outcomes' => 'nullable|array',
            'learning_outcomes.*' => 'string|max:500',
            'features'         => 'nullable|array',
            'features.*'       => 'string|max:255',
        ]);

        $validated['slug']   = $this->uniqueSlug($validated['title']);
        $validated['price']  = $validated['type'] === 'free' ? 0 : ($validated['price'] ?? 0);
        $validated['status'] = $validated['status'] ?? 'active';

        $features = $validated['features'] ?? [];
        unset($validated['features']);

        $course = Course::create($validated);

        foreach ($features as $index => $feature) {
            $course->features()->create([
                'title'      => $feature,
                'sort_order' => $index + 1,
            ]);
        }

        $course->load(['instructor.user', 'features', 'track']);

        return (new CourseResource($course))
            ->additional([
                'success' => true,
                'message' => 'Course created successfully',
            ])
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Course $course): JsonResponse
    {
        $validated = $request->validate([
            'track_id'          => 'nullable|exists:tracks,id',
            'instructor_id'     => 'nullable|exists:instructors,id',
            'title'             => 'sometimes|string|max:255',
            'description'       => 'nullable|string',
            'short_description' => 'nullable|string',
            'type'              => 'sometimes|in:free,paid',
            'price'             => 'nullable|numeric|min:0',
            'location'          => 'nullable|string|max:255',
            'is_online'         => 'boolean',
            'start_date'        => 'nullable|date',
            'end_date'          => 'nullable|date|after_or_equal:start_date',
            'capacity'          => 'nullable|integer|min:1',
            'status'            => 'nullable|in:active,draft,archived',
            'duration'          => 'nullable|string|max:255',
            'training_hours'    => 'nullable|integer|min:0',
            'target_audience'   => 'nullable|string|max:255',
            'language'          => 'nullable|string|max:255',
            'level'             => 'nullable|string|max:255',
            'study_days'        => 'nullable|string|max:255',
            'study_time'        => 'nullable|string|max:255',
            'certificate'       => 'nullable|string|max:255',
            'course_image'      => 'nullable|string|max:255',
            'learning_outcomes' => 'nullable|array',
            'learning_outcomes.*' => 'string|max:500',
            'features'          => 'nullable|array',
            'features.*'        => 'string|max:255',
        ]);

        if (isset($validated['title']) && ! isset($validated['slug'])) {
            $validated['slug'] = $this->uniqueSlug($validated['title'], $course->id);
        }

        if (isset($validated['type'])) {
            $validated['price'] = $validated['type'] === 'free' ? 0 : ($validated['price'] ?? $course->price);
        }

        $features = $validated['features'] ?? null;
        unset($validated['features']);

        $course->update($validated);

        if ($features !== null) {
            $course->features()->delete();
            foreach ($features as $index => $feature) {
                $course->features()->create(['title' => $feature, 'sort_order' => $index + 1]);
            }
        }

        $course->load(['instructor.user', 'features', 'track']);

        return (new CourseResource($course))
            ->additional(['success' => true, 'message' => 'Course updated successfully'])
            ->response();
    }

    public function destroy(Course $course): JsonResponse
    {
        $course->delete();

        return response()->json(['success' => true, 'message' => 'Course deleted successfully']);
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
