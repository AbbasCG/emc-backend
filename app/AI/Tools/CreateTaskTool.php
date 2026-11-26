<?php

namespace App\AI\Tools;

use App\Models\Task;
use App\Models\User;
use App\Services\TaskWorkflowService;
use App\Services\TaskWriteAccess;

class CreateTaskTool implements AiToolInterface
{
    public function name(): string
    {
        return 'create_task';
    }

    public function description(): string
    {
        return 'Create a new task and optionally assign it to a user. For admin and department managers.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'title'       => ['type' => 'string', 'description' => 'Task title'],
                'description' => ['type' => 'string', 'description' => 'Task description'],
                'assigned_to' => ['type' => 'integer', 'description' => 'User ID to assign task to'],
                'department_id' => ['type' => 'integer', 'description' => 'Department the task belongs to (required unless the user acts on every department)'],
                'due_date'    => ['type' => 'string', 'description' => 'Due date YYYY-MM-DD'],
                'priority'    => ['type' => 'string', 'enum' => ['low', 'normal', 'high', 'urgent']],
            ],
            'required' => ['title'],
        ];
    }

    public function execute(array $args, ?int $userId = null): mixed
    {
        // The acting user is mandatory: the same permission + scope rule as the task endpoints (TaskWriteAccess) decides, never the role alone.
        $user = $userId ? User::query()->find($userId) : null;
        if (! $user || ! $this->isAllowedForRole((string) $user->role)) {
            return ['error' => 'Task creation requires admin or manager access.'];
        }

        $access = app(TaskWriteAccess::class);
        if (! $access->holds($user, TaskWriteAccess::CREATE)) {
            return ['error' => 'You do not have the permission to create tasks.'];
        }

        $assignee = ! empty($args['assigned_to']) ? (int) $args['assigned_to'] : null;
        $departmentId = ! empty($args['department_id']) ? (int) $args['department_id'] : null;

        if ($assignee !== null) {
            if (! $access->holds($user, TaskWriteAccess::ASSIGN)) {
                return ['error' => 'You do not have the permission to assign tasks.'];
            }
            if (! User::query()->whereKey($assignee)->exists()) {
                return ['error' => 'The assignee does not exist.'];
            }
            $check = TaskWorkflowService::canAssignTaskToUser($assignee);
            if (! $check['allowed']) {
                return ['error' => $check['message']];
            }
        }
        if ($access->manageableDepartmentIds($user) !== null) {
            if ($departmentId === null || ! $access->inScope($user, $departmentId)) {
                return ['error' => 'You may only create tasks in a department you lead (pass department_id).'];
            }
            if ($assignee !== null && ! $access->assigneeInScope($user, $assignee)) {
                return ['error' => 'You may only assign tasks to members of the departments you lead.'];
            }
        }

        if (! class_exists(Task::class)) {
            return ['error' => 'Task module not available.'];
        }

        // Same acceptance rule as TaskController::store: someone else's assignment waits for them, the creator's own is accepted.
        $pending = $assignee !== null && $assignee !== (int) $user->id;

        $task = Task::create([
            'title'             => $args['title'],
            'description'       => $args['description'] ?? null,
            'assigned_to'       => $assignee,
            'department_id'     => $departmentId,
            'due_date'          => $args['due_date'] ?? null,
            'priority'          => $args['priority'] ?? 'normal',
            'status'            => 'open',
            'created_by'        => $user->id,
            'acceptance_status' => $pending ? Task::ACCEPTANCE_PENDING : Task::ACCEPTANCE_ACCEPTED,
            'accepted_at'       => $pending ? null : now(),
        ]);

        return [
            'task_id' => $task->id,
            'title'   => $task->title,
            'status'  => $task->status,
        ];
    }

    /** Which roles the assistant offers the tool to (unchanged); the permission and scope in execute() are what actually allow a creation. */
    public function isAllowedForRole(string $role): bool
    {
        return in_array($role, ['admin', 'super_admin', 'department_manager'], true);
    }
}
