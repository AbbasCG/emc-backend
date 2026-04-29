<?php

namespace App\Http\Controllers;

use App\Models\Instructor;
use Illuminate\Http\Request;

class InstructorController extends Controller
{
    public function index()
    {
        return Instructor::latest()->get();
    }

    public function show($id)
    {
        return Instructor::with('courses')->findOrFail($id);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'title' => 'nullable|string|max:255',
            'bio' => 'nullable|string',
            'image' => 'nullable|string|max:255',
        ]);

        $instructor = Instructor::create($validated);

        return response()->json([
            'message' => 'Instructor created successfully',
            'data' => $instructor,
        ], 201);
    }
}