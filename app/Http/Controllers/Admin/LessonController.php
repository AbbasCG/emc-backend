<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\LessonResource;
use App\Models\Lesson;
use App\Support\CourseContentStatus;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LessonController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $lessons = Lesson::with('module')
            ->when($request->module_id, fn ($q) => $q->where('module_id', $request->module_id))
            ->orderBy('sort_order')
            ->get();

        return response()->json(['success' => true, 'data' => LessonResource::collection($lessons)]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'module_id'        => 'required|exists:course_modules,id',
            'title'            => 'required|string|max:255',
            'description'      => 'nullable|string',
            'content'          => 'nullable|string',
            'video_url'        => 'nullable|url',
            'duration_minutes' => 'nullable|integer|min:1',
            'sort_order'       => 'integer',
            'status'           => [Rule::in(CourseContentStatus::ALL)],
        ]);

        $lesson = Lesson::create($data);

        return response()->json(['success' => true, 'data' => new LessonResource($lesson)], 201);
    }

    public function show(Lesson $lesson): \Illuminate\Http\JsonResponse
    {
        return response()->json(['success' => true, 'data' => new LessonResource($lesson->load(['module', 'quizzes']))]);
    }

    public function update(Request $request, Lesson $lesson): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'title'            => 'string|max:255',
            'description'      => 'nullable|string',
            'content'          => 'nullable|string',
            'video_url'        => 'nullable|url',
            'duration_minutes' => 'nullable|integer|min:1',
            'sort_order'       => 'integer',
            'status'           => [Rule::in(CourseContentStatus::ALL)],
        ]);

        $lesson->update($data);

        return response()->json(['success' => true, 'data' => new LessonResource($lesson)]);
    }

    public function destroy(Lesson $lesson): \Illuminate\Http\JsonResponse
    {
        $lesson->delete();

        return response()->json(['success' => true, 'message' => 'Lesson deleted.']);
    }
}
