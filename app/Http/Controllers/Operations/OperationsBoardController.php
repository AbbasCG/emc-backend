<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Department;
use App\Models\Task;
use App\Services\DepartmentAccessService;
use App\Services\DepartmentRosterService;
use App\Services\OperationsWorkBoard;
use App\Support\WorkBoardColumns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * لوحة التشغيل المرئية للجميع / الأجايل العام والخاص
 */
class OperationsBoardController extends Controller
{
    /**
     * The roles that may read the global work board (GET /operations/work-board): the role list of the shared /operations group
     * (routes/api.php) PLUS the four roles the frontend lets onto /dashboard/operations/* (utils/dashboardAccess.ts) that the
     * group never listed, so they could open the page and then got a 403: department_member, strategy_planning_manager,
     * digital_ambassadors_manager, advisors_manager. The payload is the public-safe projection (see OperationsWorkBoard).
     */
    public const WORK_BOARD_ROLES = [
        'admin', 'super_admin', 'tech_admin', 'executive_admin', 'programs_manager', 'instructor', 'hr_manager', 'finance_manager',
        'marketing', 'marketing_manager', 'quality', 'quality_manager', 'support_agent', 'operations_manager', 'partnerships_manager',
        'community_manager', 'volunteer', 'department_manager', 'section_lead', 'ai_manager',
        'department_member', 'strategy_planning_manager', 'digital_ambassadors_manager', 'advisors_manager',
    ];

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

    /**
     * GET /operations/work-board: the GLOBAL board (tasks + assigned tickets of every assignee, same columns as «مهامي ومسؤولياتي»),
     * in the public-safe projection of OperationsWorkBoard. It does not replace GET /operations/board, which the task-management
     * screens still read unchanged.
     */
    public function workBoard(Request $request, OperationsWorkBoard $board): JsonResponse
    {
        $f = $request->validate([
            'scope' => ['sometimes', 'nullable', Rule::in(['all', 'mine'])],
            'kind' => ['sometimes', 'nullable', Rule::in(['all', 'tasks', 'tickets'])],
            'department_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_column' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.OperationsWorkBoard::MAX_PER_COLUMN],
            'column' => ['sometimes', 'nullable', Rule::in(array_keys(WorkBoardColumns::COLUMNS))],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ], [
            'scope.in' => 'نطاق اللوحة غير صالح.',
            'kind.in' => 'نوع العناصر غير صالح.',
            'column.in' => 'العمود غير صالح.',
        ]);
        $f = array_filter($f, fn ($v) => $v !== null && $v !== '');

        if (isset($f['page']) && empty($f['column'])) {
            return response()->json(['success' => false, 'message' => 'الترقيم يخص عمودًا واحدًا: أرسل column مع page.', 'errors' => ['page' => ['أرسل column مع page.']]], 422);
        }
        if (((int) ($f['page'] ?? 1)) * ((int) ($f['per_column'] ?? OperationsWorkBoard::DEFAULT_PER_COLUMN)) > OperationsWorkBoard::MAX_DEPTH) {
            return response()->json(['success' => false, 'message' => 'الصفحة المطلوبة بعيدة جدًا؛ ضيّق البحث أو المرشحات.', 'errors' => ['page' => ['صفحة بعيدة جدًا.']]], 422);
        }

        return response()->json(['success' => true] + $board->build($request->user(), $f));
    }

    public function departmentMembers(Request $request, Department $department): JsonResponse
    {
        if (! $this->departmentAccess->canAccessDepartment($request->user(), $department->id)) {
            return response()->json(['success' => false, 'message' => 'ليست لديك صلاحية على هذه الإدارة.'], 403);
        }

        return response()->json(['success' => true, 'data' => app(DepartmentRosterService::class)->members($department)]);
    }
}
