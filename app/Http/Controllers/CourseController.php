<?php

namespace App\Http\Controllers;

use App\Models\Course;
use Illuminate\Support\Str;

use Illuminate\Http\Request;

class CourseController extends Controller
{
    public function index()
    {
        return Course::with(['instructor', 'features'])
            ->latest()
            ->get();
    }

    public function show($slug)
    {
        return Course::with(['instructor', 'features'])
            ->where('slug', $slug)
            ->firstOrFail();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'instructor_id' => 'nullable|exists:instructors,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'short_description' => 'nullable|string',
            'instructor_name' => 'nullable|string|max:255',
            'type' => 'required|in:free,paid',
            'price' => 'nullable|numeric',
            'location' => 'nullable|string|max:255',
            'is_online' => 'boolean',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'capacity' => 'nullable|integer',
            'status' => 'nullable|string',
            'duration' => 'nullable|string|max:255',
            'training_hours' => 'nullable|integer',
            'target_audience' => 'nullable|string|max:255',
            'language' => 'nullable|string|max:255',
            'level' => 'nullable|string|max:255',
            'study_days' => 'nullable|string|max:255',
            'study_time' => 'nullable|string|max:255',
            'certificate' => 'nullable|string|max:255',
            'course_image' => 'nullable|string|max:255',
            'features' => 'nullable|array',
            'features.*' => 'string|max:255',
        ]);

        $validated['slug'] = Str::slug($validated['title']);
        $validated['price'] = $validated['type'] === 'free' ? 0 : ($validated['price'] ?? 0);
        $validated['status'] = $validated['status'] ?? 'active';

        $course = Course::create($validated);

        if ($request->has('features')) {
            foreach ($request->features as $index => $feature) {
                $course->features()->create([
                    'title' => $feature,
                    'sort_order' => $index + 1,
                ]);
            }
        }

        return response()->json([
            'message' => 'Course created successfully',
            'data' => $course
        ], 201);
    }
}
