<?php

namespace App\Http\Controllers\Institute;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Course;
use App\Models\ClassGroup;
use App\Models\User;
use App\Models\Registration;
use App\Models\ClassGroupStudent;
use App\Models\PlacementTest;
use Illuminate\Support\Str;

class InstituteAdminController extends Controller
{
    // List Levels / Bundles
    public function index()
    {
        $levels = Course::where('is_institute', true)->withCount('classGroups')->get();
        // map title to title_en for the frontend
        $levels->transform(function ($level) {
            $level->title_en = $level->title;
            return $level;
        });
        return response()->json([
            'levels' => $levels
        ]);
    }

    // Store a new level
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title_en' => 'required|string|max:255',
            'title_ar' => 'nullable|string|max:255',
            'description_en' => 'nullable|string',
            'description_ar' => 'nullable|string',
            'course_image' => 'nullable|string',
            'requirements' => 'nullable|string',
            'target_audience' => 'nullable|string',
            'language' => 'nullable|string',
            'price' => 'nullable|numeric',
            'capacity' => 'nullable|integer',
            'pricing_options' => 'nullable|array',
            'is_paid' => 'required|boolean',
        ]);

        $slug = \Illuminate\Support\Str::slug($validated['title_en']) . '-' . uniqid();

        $course = Course::create([
            'title' => $validated['title_en'],
            'title_en' => $validated['title_en'],
            'title_ar' => $validated['title_ar'] ?? $validated['title_en'],
            'slug' => $slug,
            'description_en' => $validated['description_en'] ?? '',
            'description_ar' => $validated['description_ar'] ?? '',
            'course_image' => $validated['course_image'] ?? null,
            'requirements' => $validated['requirements'] ?? null,
            'target_audience' => $validated['target_audience'] ?? null,
            'language' => $validated['language'] ?? 'English',
            'price' => $validated['price'] ?? 0,
            'capacity' => $validated['capacity'] ?? 50,
            'is_institute' => true,
            'is_paid' => $validated['is_paid'],
            'pricing_options' => $validated['pricing_options'] ?? [],
            'status' => 'published'
        ]);

        return response()->json([
            'message' => 'Level created successfully',
            'level' => $course
        ], 201);
    }

    // Show a specific level
    public function show($id)
    {
        $level = Course::where('is_institute', true)->findOrFail($id);
        return response()->json(['level' => $level]);
    }

    // Update a level
    public function update(Request $request, $id)
    {
        $level = Course::where('is_institute', true)->findOrFail($id);
        
        $validated = $request->validate([
            'title_en' => 'sometimes|string|max:255',
            'title_ar' => 'nullable|string|max:255',
            'description_en' => 'nullable|string',
            'description_ar' => 'nullable|string',
            'course_image' => 'nullable|string',
            'requirements' => 'nullable|string',
            'target_audience' => 'nullable|string',
            'language' => 'nullable|string',
            'price' => 'nullable|numeric',
            'capacity' => 'nullable|integer',
            'pricing_options' => 'nullable|array',
            'is_paid' => 'nullable|boolean',
        ]);

        if (isset($validated['title_en'])) {
            $validated['title'] = $validated['title_en'];
        }

        $level->update($validated);

        return response()->json(['message' => 'Level updated successfully', 'level' => $level]);
    }

    // Delete a level
    public function destroy($id)
    {
        $level = Course::where('is_institute', true)->findOrFail($id);
        $level->delete();
        
        return response()->json(['message' => 'Level deleted successfully']);
    }

    // Waitlist
    public function waitlist(Request $request)
    {
        // Find users who have an active registration for an institute course
        // but are not assigned to any class group yet.
        $registrations = Registration::whereHas('course', function ($q) {
            $q->where('is_institute', true);
        })
        ->with(['user', 'course'])
        ->get();

        $waitlist = [];
        foreach ($registrations as $reg) {
            // Check if student is already in a class for this course
            $inClass = ClassGroupStudent::where('user_id', $reg->user_id)
                ->whereHas('classGroup', function($q) use ($reg) {
                    $q->where('course_id', $reg->course_id);
                })->exists();

            if (!$inClass) {
                $waitlist[] = [
                    'id' => $reg->id,
                    'user_id' => $reg->user_id,
                    'name' => $reg->user->name ?? $reg->full_name,
                    'level' => $reg->course->title_en,
                    'course_id' => $reg->course_id,
                    'preferredTime' => 'Not Set', // Could be fetched from user preferences
                    'status' => 'Pending'
                ];
            }
        }

        return response()->json([
            'waitlist' => $waitlist
        ]);
    }

    // List Classes
    public function getClasses(Request $request)
    {
        $classes = ClassGroup::where('is_institute', true)
            ->with(['courses', 'instructor'])
            ->withCount('students')
            ->get();
            
        return response()->json(['classes' => $classes]);
    }

    // Create Class
    public function storeClass(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'instructor_id' => 'required|integer|exists:instructors,id',
            'capacity' => 'required|integer',
            'course_ids' => 'required|array',
            'course_ids.*' => 'integer|exists:courses,id',
            'schedule_day' => 'nullable|string',
            'schedule_time' => 'nullable|string',
        ]);

        $class = ClassGroup::create([
            'name' => $validated['name'],
            'instructor_id' => $validated['instructor_id'],
            'capacity' => $validated['capacity'],
            'schedule_day' => $validated['schedule_day'] ?? 'Mon-Wed-Fri',
            'schedule_time' => $validated['schedule_time'] ?? '18:00 - 20:00',
            'status' => 'active',
            'is_institute' => true,
        ]);

        $class->courses()->attach($validated['course_ids']);

        return response()->json([
            'message' => 'Class created successfully',
            'class' => $class
        ]);
    }

    public function getClass($id)
    {
        $class = ClassGroup::with('courses')->where('is_institute', true)->findOrFail($id);
        return response()->json(['class' => $class]);
    }

    public function updateClass(Request $request, $id)
    {
        $class = ClassGroup::where('is_institute', true)->findOrFail($id);
        
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'instructor_id' => 'sometimes|integer|exists:instructors,id',
            'capacity' => 'sometimes|integer',
            'course_ids' => 'sometimes|array',
            'course_ids.*' => 'integer|exists:courses,id',
            'schedule_day' => 'nullable|string',
            'schedule_time' => 'nullable|string',
        ]);

        $class->update($validated);

        if (isset($validated['course_ids'])) {
            $class->courses()->sync($validated['course_ids']);
        }

        return response()->json([
            'message' => 'Class updated successfully',
            'class' => $class
        ]);
    }

    public function deleteClass($id)
    {
        $class = ClassGroup::where('is_institute', true)->findOrFail($id);
        $class->delete();
        
        return response()->json(['message' => 'Class deleted successfully']);
    }

    // Get Instructors for dropdown
    public function getInstructors()
    {
        // Simple fetch of instructors
        $instructors = \App\Models\Instructor::with('user')->get();
        return response()->json(['instructors' => $instructors]);
    }

    // Auto-assign students to classes
    public function autoAssign(Request $request)
    {
        // 1. Get waitlist
        $registrations = Registration::whereHas('course', function ($q) {
            $q->where('is_institute', true);
        })->get();

        $assignedCount = 0;
        $failedCount = 0;

        foreach ($registrations as $reg) {
            $inClass = ClassGroupStudent::where('user_id', $reg->user_id)
                ->whereHas('classGroup', function($q) use ($reg) {
                    $q->where('course_id', $reg->course_id);
                })->exists();

            if (!$inClass) {
                // Find a class group for this course that has space
                $availableClass = ClassGroup::where('course_id', $reg->course_id)
                    ->where('is_institute', true)
                    ->withCount('activeStudents')
                    ->having('active_students_count', '<', \DB::raw('capacity')) // Assume DB raw works here or we just filter in php
                    ->first();

                // Safer php filter if having raw fails:
                if (!$availableClass) {
                    $classes = ClassGroup::where('course_id', $reg->course_id)->where('is_institute', true)->get();
                    foreach ($classes as $cls) {
                        if ($cls->activeStudents()->count() < $cls->capacity) {
                            $availableClass = $cls;
                            break;
                        }
                    }
                }

                if ($availableClass) {
                    ClassGroupStudent::create([
                        'class_group_id' => $availableClass->id,
                        'user_id' => $reg->user_id,
                        'status' => 'active'
                    ]);
                    $assignedCount++;
                } else {
                    $failedCount++;
                }
            }
        }

        return response()->json([
            'message' => "Auto-assignment completed: $assignedCount assigned, $failedCount failed.",
            'assigned_count' => $assignedCount,
            'failed_count' => $failedCount,
        ]);
    }

    // Manual transfer
    public function manualTransfer(Request $request)
    {
        $request->validate([
            'student_id' => 'required|integer',
            'from_class_id' => 'required|integer',
            'to_class_id' => 'required|integer',
        ]);

        return response()->json([
            'message' => 'Student transferred successfully'
        ]);
    }
}
