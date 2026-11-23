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
 * لوحة التشغيل المرئية للجميع / الأجايل العام والخاص
 */
class OperationsBoardController extends Controller
{
    public function __construct(private readonly DepartmentAccessService $departmentAccess)
    {
    }

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
            'in_progress', 'needs_evaluation', 'completed', 'postponed', 'cancelled',
        ];

        $user = $request->user();
        $isMineScope = $request->scope === 'mine' || $request->boolean('mine');

        $query = Task::query()
            ->with([
                'department:id,name',
                'assignedTo:id,name',
                'createdBy:id,name',
                'evaluation.evaluator',
                'attachments',
            ])
            // Counters only (a subquery each, no rows loaded): group parents and checklist progress on the cards.
            ->withCount([
                'subtasks',
                'checklistItems',
                'checklistItems as checklist_done_count' => fn ($q) => $q->where('is_completed', true),
            ])
            ->when($request->department_id, fn ($q) => $q->where('department_id', $request->department_id))
            ->when($isMineScope, function ($q) use ($user) {
                // الأجايل الخاص بالعضو: فقط مهامه المقبولة
                $q->where('assigned_to', $user->id)
                  ->where('acceptance_status', Task::ACCEPTANCE_ACCEPTED);
            })
            ->when(! $isMineScope && $request->unassigned, function ($q) {
                // المهام غير المسندة الموجهة للإدارة
                $q->whereNull('assigned_to');
            });

        $board = [];
        foreach ($statuses as $status) {
            $board[$status] = TaskResource::collection(
                (clone $query)->where('status', $status)->orderByDesc('updated_at')->get(),
            );
        }

        return response()->json(['success' => true, 'data' => $board]);
    }

    public function departmentMembers(Request $request, Department $department): JsonResponse
    {
        if (! $this->departmentAccess->canAccessDepartment($request->user(), $department->id)) {
            return response()->json(['success' => false, 'message' => 'ليست لديك صلاحية على هذه الإدارة.'], 403);
        }

        return response()->json(['success' => true, 'data' => app(DepartmentRosterService::class)->members($department)]);
    }
}
