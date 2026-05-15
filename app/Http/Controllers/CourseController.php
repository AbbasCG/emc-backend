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

        $query = Course::with(['instructor', 'features', 'track'])->latest();

        if ($request->filled('track_id')) {
            $query->where('track_id', $request->query('track_id'));
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
        $course = Course::with(['instructor', 'features', 'track'])
            ->where('slug', $slug)
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

        $course->load(['instructor', 'features', 'track']);

        return (new CourseResource($course))
            ->additional([
                'success' => true,
                'message' => 'Course created successfully',
            ])
            ->response()
            ->setStatusCode(201);
    }

    private function uniqueSlug(string $title): string
    {
        $base  = Str::slug($title);
        $slug  = $base;
        $count = 1;

        while (Course::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $count++;
        }

        return $slug;
    }
}
