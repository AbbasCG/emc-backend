<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Task;
use App\Services\DepartmentRosterService;
use App\Services\TaskWriteAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the task-writing UI of the operations board (create / assign / edit a task) needs from the server, for ANY holder of
 * tasks.create / tasks.assign / tasks.update, scoped exactly as the writes themselves are (App\Services\TaskWriteAccess):
 *
 *   GET operations/board/task-scope/departments                  the departments the actor may write tasks in (all active ones for a
 *                                                                global actor, only the ones they lead for anyone else)
 *   GET operations/board/task-scope/departments/{id}/members     the roster of one of those departments (the assignees they may pick)
 *   GET operations/board/tasks/{task}/editable                   the editable values of one task, to pre-fill the edit form
 *                                                                (description and notes only for a holder who can EDIT, not for assign-only)
 *
 * The role-gated readers the admin UI used before (operations/departments, operations/departments/{id}/members) stay untouched:
 * the first is closed to hr_manager and the second lets a plain MEMBER read a roster, neither is a write scope.
 */
class BoardTaskScopeController extends Controller
{
    /** Rebuilt at the start of every action: its per-actor caches must live for one request only (the router keeps controller instances). */
    private TaskWriteAccess $access;

    public function __construct(private readonly DepartmentRosterService $roster)
    {
        $this->access = app(TaskWriteAccess::class);
    }

    public function departments(Request $request): JsonResponse
    {
        $this->access = app(TaskWriteAccess::class);
        if ($refusal = $this->refuseUnlessHolder($request)) {
            return $refusal;
        }

        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => $this->access->departmentOptions($user),
            'meta' => ['manageable_department_ids' => $this->access->reportedDepartmentIds($user)],
        ]);
    }

    public function members(Request $request, Department $department): JsonResponse
    {
        $this->access = app(TaskWriteAccess::class);
        if ($refusal = $this->refuseUnlessHolder($request)) {
            return $refusal;
        }
        if (! $this->access->inScope($request->user(), (int) $department->id)) {
            return response()->json(['success' => false, 'message' => 'هذه الإدارة خارج نطاق الإدارات التي تقودها.'], 403);
        }

        return response()->json(['success' => true, 'data' => $this->roster->members($department)]);
    }

    public function editable(Request $request, Task $task): JsonResponse
    {
        $this->access = app(TaskWriteAccess::class);
        $user = $request->user();
        $canEdit = $this->access->canEdit($user, $task);
        $canReassign = $this->access->canReassign($user, $task);
        if (! $canEdit && ! $canReassign) {
            return response()->json(['success' => false, 'message' => 'لا يمكنك تعديل هذه المهمة.'], 403);
        }

        $task->loadMissing('assignedTo:id,name');

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $task->id,
                'title' => $task->title,
                'department_id' => $task->department_id,
                'section_id' => $task->section_id,
                'assigned_to' => $task->assignedTo ? ['id' => $task->assignedTo->id, 'name' => $task->assignedTo->name] : null,
                'priority' => $task->priority,
                'task_scope' => $task->task_scope,
                'points' => $task->points,
                'estimated_hours' => $task->estimated_hours,
                'status' => $task->status,
                'start_date' => $task->start_date?->toDateString(),
                'due_date' => $task->due_date?->toDateString(),
                'due_datetime' => $task->due_datetime?->toIso8601String(),
                'is_locked' => $task->locked_at !== null,
                'capabilities' => ['can_edit' => $canEdit, 'can_reassign' => $canReassign],
            ] + ($canEdit ? ['description' => $task->description, 'notes' => $task->notes] : []),
        ]);
    }

    private function refuseUnlessHolder(Request $request): ?JsonResponse
    {
        return $this->access->holdsAny($request->user())
            ? null
            : response()->json(['success' => false, 'message' => 'ليست لديك صلاحية إدارة المهام.'], 403);
    }
}
