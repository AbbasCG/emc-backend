<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DepartmentController extends Controller
{
    public function index(): \Illuminate\Http\JsonResponse
    {
        $departments = Department::with(['leader', 'sections', 'children'])
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => DepartmentResource::collection($departments),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'slug'        => 'nullable|string|unique:departments,slug',
            'description' => 'nullable|string',
            'leader_id'   => 'nullable|exists:users,id',
            'parent_id'   => 'nullable|exists:departments,id',
            'status'      => 'in:active,inactive,archived',
            'icon'        => 'nullable|string|max:100',
            'color'       => 'nullable|string|max:50',
            'sort_order'  => 'integer',
        ]);

        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);

        $department = Department::create($data);

        return response()->json([
            'success' => true,
            'data'    => new DepartmentResource($department->load(['leader', 'sections'])),
        ], 201);
    }

    public function show(Department $department): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new DepartmentResource(
                $department->load(['leader', 'sections.leader', 'children', 'teamMembers.user'])
            ),
        ]);
    }

    public function update(Request $request, Department $department): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'name'        => 'string|max:255',
            'slug'        => 'string|unique:departments,slug,' . $department->id,
            'description' => 'nullable|string',
            'leader_id'   => 'nullable|exists:users,id',
            'parent_id'   => 'nullable|exists:departments,id',
            'status'      => 'in:active,inactive,archived',
            'icon'        => 'nullable|string|max:100',
            'color'       => 'nullable|string|max:50',
            'sort_order'  => 'integer',
        ]);

        $department->update($data);

        return response()->json([
            'success' => true,
            'data'    => new DepartmentResource($department->load(['leader', 'sections'])),
        ]);
    }

    public function destroy(Department $department): \Illuminate\Http\JsonResponse
    {
        $department->delete();

        return response()->json(['success' => true, 'message' => 'Department deleted.']);
    }
}
