<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\DepartmentSection;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\DepartmentAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentStructureController extends Controller
{
    public function __construct(private readonly DepartmentAccessService $departmentAccess)
    {
    }

    /**
     * Get the full organizational structure for the manager's led department(s).
     */
    public function getStructure(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($this->departmentAccess->isGlobal($user)) {
            $departmentId = $request->input('department_id');
            if (!$departmentId) {
                $department = Department::first();
                $departmentId = $department ? $department->id : null;
            }
            $departments = Department::all(['id', 'name']);
        } else {
            $ledIds = $this->departmentAccess->getLedDepartmentIds($user);
            if ($ledIds->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'department' => null,
                    'sections' => [],
                    'unassigned_members' => [],
                    'all_members' => [],
                    'departments' => [],
                ]);
            }
            $departmentId = $request->input('department_id') ?: $ledIds->first();
            $departments = Department::whereIn('id', $ledIds)->get(['id', 'name']);
        }

        if (!$departmentId) {
            return response()->json([
                'success' => true,
                'department' => null,
                'sections' => [],
                'unassigned_members' => [],
                'all_members' => [],
                'departments' => $departments,
            ]);
        }

        $department = Department::with('leader:id,name,email,avatar,role')->find($departmentId);
        if (!$department) {
            return response()->json(['success' => false, 'message' => 'Department not found'], 404);
        }

        // Get sections in this department with their leaders & team members
        $sections = DepartmentSection::where('department_id', $departmentId)
            ->with(['leader:id,name,email,avatar,role', 'teamMembers.user:id,name,email,avatar,role'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        // Get all department members (via user_departments pivot, direct department_id column, or TeamMember)
        $memberUsers = User::where(function ($q) use ($departmentId) {
            $q->where('department_id', $departmentId)
              ->orWhereHas('departments', fn($sq) => $sq->where('departments.id', $departmentId));
        })->get(['id', 'name', 'email', 'avatar', 'role', 'department_id']);

        // Format members and their section assignment
        $assignedUserIds = [];
        $formattedSections = $sections->map(function ($section) use (&$assignedUserIds) {
            $members = $section->teamMembers->map(function ($tm) use (&$assignedUserIds) {
                if ($tm->user) {
                    $assignedUserIds[] = $tm->user_id;
                    return [
                        'team_member_id' => $tm->id,
                        'user_id' => $tm->user_id,
                        'name' => $tm->user->name,
                        'email' => $tm->user->email,
                        'avatar' => $tm->user->avatar,
                        'role' => $tm->user->role,
                        'role_title' => $tm->role_title ?: 'عضو قسم',
                        'status' => $tm->status,
                    ];
                }
                return null;
            })->filter()->values();

            return [
                'id' => $section->id,
                'name' => $section->name,
                'description' => $section->description,
                'status' => $section->status,
                'sort_order' => $section->sort_order,
                'leader' => $section->leader ? [
                    'id' => $section->leader->id,
                    'name' => $section->leader->name,
                    'email' => $section->leader->email,
                    'avatar' => $section->leader->avatar,
                ] : null,
                'members' => $members,
                'member_count' => $members->count(),
            ];
        });

        // Unassigned department members
        $unassigned = $memberUsers->reject(fn($u) => in_array($u->id, $assignedUserIds))->map(function ($u) {
            $tm = TeamMember::where('user_id', $u->id)->first();
            return [
                'user_id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'avatar' => $u->avatar,
                'role' => $u->role,
                'role_title' => $tm ? ($tm->role_title ?: 'غير مسكّن') : 'غير مسكّن',
            ];
        })->values();

        $allMembersFormatted = $memberUsers->map(function ($u) use ($departmentId) {
            $tm = TeamMember::where('user_id', $u->id)->where('department_id', $departmentId)->first();
            return [
                'user_id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'avatar' => $u->avatar,
                'role' => $u->role,
                'section_id' => $tm ? $tm->section_id : null,
                'role_title' => $tm ? $tm->role_title : null,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'department' => [
                'id' => $department->id,
                'name' => $department->name,
                'description' => $department->description ?? '',
                'leader' => $department->leader ? [
                    'id' => $department->leader->id,
                    'name' => $department->leader->name,
                    'email' => $department->leader->email,
                ] : null,
            ],
            'sections' => $formattedSections,
            'unassigned_members' => $unassigned,
            'all_members' => $allMembersFormatted,
            'departments' => $departments,
        ]);
    }

    /**
     * Create a new sub-section in a department.
     */
    public function storeSection(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'department_id' => 'required|exists:departments,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'leader_id' => 'nullable|exists:users,id',
            'sort_order' => 'nullable|integer',
        ]);

        if (!$this->departmentAccess->canManageDepartment($user, (int) $data['department_id'])) {
            return response()->json(['success' => false, 'message' => 'غير مصرح لك بإدارة هذا القسم'], 403);
        }

        $data['status'] = 'active';
        $data['sort_order'] = $data['sort_order'] ?? 0;

        $section = DepartmentSection::create($data);

        // If a section leader is assigned, update/create their TeamMember record
        if ($section->leader_id) {
            TeamMember::updateOrCreate(
                ['user_id' => $section->leader_id, 'department_id' => $section->department_id],
                [
                    'section_id' => $section->id,
                    'role_title' => 'مشرف التقسيم',
                    'status' => 'active',
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء التقسيم الإداري بنجاح',
            'section' => $section->load('leader:id,name,email'),
        ], 201);
    }

    /**
     * Update an existing section.
     */
    public function updateSection(Request $request, DepartmentSection $section): JsonResponse
    {
        $user = $request->user();

        if (!$this->departmentAccess->canManageDepartment($user, (int) $section->department_id)) {
            return response()->json(['success' => false, 'message' => 'غير مصرح لك بإدارة هذا القسم'], 403);
        }

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'leader_id' => 'nullable|exists:users,id',
            'status' => 'sometimes|in:active,inactive',
            'sort_order' => 'nullable|integer',
        ]);

        $section->update($data);

        if (array_key_exists('leader_id', $data) && $data['leader_id']) {
            TeamMember::updateOrCreate(
                ['user_id' => $data['leader_id'], 'department_id' => $section->department_id],
                [
                    'section_id' => $section->id,
                    'role_title' => 'مشرف التقسيم',
                    'status' => 'active',
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث التقسيم الإداري بنجاح',
            'section' => $section->load('leader:id,name,email'),
        ]);
    }

    /**
     * Delete a section (unassigning members).
     */
    public function destroySection(Request $request, DepartmentSection $section): JsonResponse
    {
        $user = $request->user();

        if (!$this->departmentAccess->canManageDepartment($user, (int) $section->department_id)) {
            return response()->json(['success' => false, 'message' => 'غير مصرح لك بإدارة هذا القسم'], 403);
        }

        // Reset section_id for team members in this section
        TeamMember::where('section_id', $section->id)->update(['section_id' => null]);

        $section->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف التقسيم الإداري بنجاح',
        ]);
    }

    /**
     * Assign a user to a section and set their role title in the department structure.
     */
    public function assignMemberSection(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'department_id' => 'required|exists:departments,id',
            'section_id' => 'nullable|exists:department_sections,id',
            'role_title' => 'nullable|string|max:255',
        ]);

        if (!$this->departmentAccess->canManageDepartment($user, (int) $data['department_id'])) {
            return response()->json(['success' => false, 'message' => 'غير مصرح لك بتسكين الأعضاء في هذه الإدارة'], 403);
        }

        $teamMember = TeamMember::updateOrCreate(
            ['user_id' => $data['user_id'], 'department_id' => $data['department_id']],
            [
                'section_id' => $data['section_id'] ?? null,
                'role_title' => $data['role_title'] ?? 'عضو قسم',
                'status' => 'active',
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'تم تسكين العضو في التقسيم الإداري بنجاح',
            'team_member' => $teamMember,
        ]);
    }
}
