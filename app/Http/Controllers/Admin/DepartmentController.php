<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DepartmentController extends Controller
{
    public function index(): \Illuminate\Http\JsonResponse
    {
        $departments = Department::query()
            ->select('departments.*')
            // volunteer_requests use a free-text desired_department matching name_ar
            ->addSelect(DB::raw(
                "(SELECT COUNT(*) FROM volunteer_requests
                  WHERE volunteer_requests.desired_department = departments.name_ar
                  AND volunteer_requests.deleted_at IS NULL
                 ) AS volunteer_requests_count"
            ))
            ->addSelect(DB::raw(
                "(SELECT COUNT(*) FROM volunteer_requests
                  WHERE volunteer_requests.desired_department = departments.name_ar
                  AND volunteer_requests.status = 'pending'
                  AND volunteer_requests.deleted_at IS NULL
                 ) AS pending_volunteer_count"
            ))
            ->withCount([
                'teamProfiles as members_count',
                'teamProfiles as leaders_count' => fn ($q) => $q->where('is_leader', true),
                'courses as courses_count',
                // open tasks = everything except completed / cancelled
                'tasks as open_tasks_count' => fn ($q) => $q->whereNotIn('status', ['completed', 'cancelled']),
            ])
            ->with(['leader:id,name,email,role', 'sections', 'children'])
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
        $department->loadCount([
            'teamProfiles as members_count',
            'teamProfiles as leaders_count' => fn ($q) => $q->where('is_leader', true),
            'courses as courses_count',
            'tasks as open_tasks_count' => fn ($q) => $q->whereNotIn('status', ['completed', 'cancelled']),
        ]);

        $department->load(['leader:id,name,email,role', 'sections.leader', 'children', 'teamMembers.user']);

        $department->volunteer_requests_count = \App\Models\VolunteerRequest::where('desired_department', $department->name_ar)
            ->whereNull('deleted_at')
            ->count();

        $department->pending_volunteer_count = \App\Models\VolunteerRequest::where('desired_department', $department->name_ar)
            ->where('status', 'pending')
            ->whereNull('deleted_at')
            ->count();

        return response()->json([
            'success' => true,
            'data'    => new DepartmentResource($department),
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
