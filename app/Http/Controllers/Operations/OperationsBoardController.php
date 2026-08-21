<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Department;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * لوحة التشغيل المرئية للجميع: كل أدوار الفريق تقرأ نفس اللوحة —
 * من يعمل على ماذا، حالة كل مهمة، والمُقفَل منها. القراءة للجميع؛
 * الكتابة تبقى عبر مسارات الأدمن المقيَّدة.
 */
class OperationsBoardController extends Controller
{
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
    public function departmentMembers(Department $department): JsonResponse
    {
        $team = $department->teamMembers()
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

        $members = collect($leader ? [[
            'id' => $leader->id, 'name' => $leader->name, 'role' => $leader->role,
            'role_title' => null, 'kind' => 'leader',
        ]] : [])
            ->concat($team)
            ->unique('id')
            ->values();

        return response()->json(['success' => true, 'data' => $members]);
    }
}
