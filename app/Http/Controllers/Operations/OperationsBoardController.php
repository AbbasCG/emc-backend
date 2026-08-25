<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Department;
use App\Models\Task;
use App\Services\DepartmentAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * لوحة التشغيل المرئية للجميع: كل أدوار الفريق تقرأ نفس اللوحة —
 * من يعمل على ماذا، حالة كل مهمة، والمُقفَل منها. القراءة للجميع؛
 * الكتابة تبقى عبر مسارات الأدمن المقيَّدة.
 */
class OperationsBoardController extends Controller
{
    public function __construct(private readonly DepartmentAccessService $departmentAccess)
    {
    }

    /** ما نطاق الإدارات المتاح لهذا المستخدم — تُبنى عليه واجهة اختيار الإدارة. */
    public function myDepartmentAccess(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->departmentAccess->manifest($request->user()),
        ]);
    }

    public function board(Request $request): JsonResponse
    {
        $statuses = [
            'idea', 'under_review', 'planning', 'waiting_approval',
            'in_progress', 'completed', 'postponed', 'cancelled', 'needs_evaluation',
        ];

        $query = Task::query()
            ->with(['department:id,name', 'assignedTo:id,name', 'createdBy:id,name'])
            ->when($request->department_id, fn ($q) => $q->where('department_id', $request->department_id));

        $board = [];
        foreach ($statuses as $status) {
            $board[$status] = TaskResource::collection(
                (clone $query)->where('status', $status)->orderByDesc('updated_at')->get(),
            );
        }

        return response()->json(['success' => true, 'data' => $board]);
    }

    /** أعضاء إدارة (القائد + الفريق) — يغذي قائمة الحضور في تقرير الاجتماع. */
    public function departmentMembers(Request $request, Department $department): JsonResponse
    {
        if (! $this->departmentAccess->canAccessDepartment($request->user(), $department->id)) {
            return response()->json(['success' => false, 'message' => 'ليست لديك صلاحية على هذه الإدارة.'], 403);
        }

        $team = $department->teamMembers()
            ->where('status', 'active')
            ->with('user:id,name,role')
            ->get()
            ->filter(fn ($member) => $member->user !== null)
            ->map(fn ($member) => [
                'id'         => $member->user->id,
                'name'       => $member->user->name,
                'role'       => $member->user->role,
                'role_title' => $member->role_title,
                'kind'       => 'team',
            ]);

        $leader = $department->leader()->first(['id', 'name', 'role']);

        $usersPivot = $department->users()
            ->select('users.id', 'users.name', 'users.role')
            ->get()
            ->map(fn ($user) => [
                'id'         => $user->id,
                'name'       => $user->name,
                'role'       => $user->role,
                'role_title' => null,
                'kind'       => 'team',
            ]);

        $members = collect($leader ? [[
            'id' => $leader->id, 'name' => $leader->name, 'role' => $leader->role,
            'role_title' => null, 'kind' => 'leader',
        ]] : [])
            ->concat($team)
            ->concat($usersPivot)
            ->unique('id')
            ->values();

        return response()->json(['success' => true, 'data' => $members]);
    }
}
