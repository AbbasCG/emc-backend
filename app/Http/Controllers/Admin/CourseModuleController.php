<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CourseModuleResource;
use App\Models\CourseModule;
use Illuminate\Http\Request;

class CourseModuleController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $modules = CourseModule::with('lessons')
            ->when($request->course_id, fn ($q) => $q->where('course_id', $request->course_id))
            ->orderBy('sort_order')
            ->get();

        return response()->json(['success' => true, 'data' => CourseModuleResource::collection($modules)]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'course_id'   => 'required|exists:courses,id',
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'sort_order'  => 'integer',
            'status'      => 'in:active,inactive,draft',
        ]);

        $module = CourseModule::create($data);

        return response()->json(['success' => true, 'data' => new CourseModuleResource($module)], 201);
    }

    public function show(CourseModule $module): \Illuminate\Http\JsonResponse
    {
        return response()->json(['success' => true, 'data' => new CourseModuleResource($module->load('lessons'))]);
    }

    public function update(Request $request, CourseModule $module): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'title'       => 'string|max:255',
            'description' => 'nullable|string',
            'sort_order'  => 'integer',
            'status'      => 'in:active,inactive,draft',
        ]);

        $module->update($data);

        return response()->json(['success' => true, 'data' => new CourseModuleResource($module)]);
    }

    public function destroy(CourseModule $module): \Illuminate\Http\JsonResponse
    {
        $module->delete();

        return response()->json(['success' => true, 'message' => 'Module deleted.']);
    }
}
