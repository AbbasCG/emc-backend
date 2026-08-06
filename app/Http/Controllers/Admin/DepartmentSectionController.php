<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DepartmentSectionResource;
use App\Models\DepartmentSection;
use Illuminate\Http\Request;

class DepartmentSectionController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $sections = DepartmentSection::with(['department', 'leader'])
            ->when($request->department_id, fn ($q) => $q->where('department_id', $request->department_id))
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => DepartmentSectionResource::collection($sections),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'department_id' => 'required|exists:departments,id',
            'name'          => 'required|string|max:255',
            'description'   => 'nullable|string',
            'leader_id'     => 'nullable|exists:users,id',
            'status'        => 'in:active,inactive',
            'sort_order'    => 'integer',
        ]);

        $section = DepartmentSection::create($data);

        return response()->json([
            'success' => true,
            'data'    => new DepartmentSectionResource($section->load(['department', 'leader'])),
        ], 201);
    }

    public function show(DepartmentSection $departmentSection): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new DepartmentSectionResource($departmentSection->load(['department', 'leader', 'teamMembers.user'])),
        ]);
    }

    public function update(Request $request, DepartmentSection $departmentSection): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'department_id' => 'exists:departments,id',
            'name'          => 'string|max:255',
            'description'   => 'nullable|string',
            'leader_id'     => 'nullable|exists:users,id',
            'status'        => 'in:active,inactive',
            'sort_order'    => 'integer',
        ]);

        $departmentSection->update($data);

        return response()->json([
            'success' => true,
            'data'    => new DepartmentSectionResource($departmentSection->load(['department', 'leader'])),
        ]);
    }

    public function destroy(DepartmentSection $departmentSection): \Illuminate\Http\JsonResponse
    {
        $departmentSection->delete();

        return response()->json(['success' => true, 'message' => 'Section deleted.']);
    }
}
