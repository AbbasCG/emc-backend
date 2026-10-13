<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Department;
use App\Models\Task;
use App\Services\DepartmentAccessService;
use App\Services\DepartmentRosterService;
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

    /** أعضاء إدارة (القائد + كامل أعضاء الإدارة) — يغذي قائمة التقييم في التقرير الأسبوعي ومحضر الاجتماع. */
    public function departmentMembers(Request $request, Department $department): JsonResponse
    {
        if (! $this->departmentAccess->canAccessDepartment($request->user(), $department->id)) {
            return response()->json(['success' => false, 'message' => 'ليست لديك صلاحية على هذه الإدارة.'], 403);
        }

        return response()->json(['success' => true, 'data' => app(DepartmentRosterService::class)->members($department)]);
    }
}
