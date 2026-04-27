<?php

namespace App\Http\Controllers;
use App\Models\Course;
use Illuminate\Support\Str;

use Illuminate\Http\Request;

class CourseController extends Controller
{
    public function index()
    {
        return Course::latest()->get();
    }

    public function show($slug)
    {
        return Course::where('slug', $slug)->firstOrFail();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
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
        ]);

        $validated['slug'] = Str::slug($validated['title']);
        $validated['price'] = $validated['type'] === 'free' ? 0 : ($validated['price'] ?? 0);
        $validated['status'] = $validated['status'] ?? 'active';

        $course = Course::create($validated);

        return response()->json([
            'message' => 'Course created successfully',
            'data' => $course
        ], 201);
    }
}
