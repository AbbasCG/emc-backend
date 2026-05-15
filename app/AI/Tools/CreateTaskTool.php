<?php

namespace App\AI\Tools;

use App\Models\Task;

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
                'due_date'    => ['type' => 'string', 'description' => 'Due date YYYY-MM-DD'],
                'priority'    => ['type' => 'string', 'enum' => ['low', 'normal', 'high', 'urgent']],
            ],
            'required' => ['title'],
        ];
    }

    public function execute(array $args, ?int $userId = null): mixed
    {
        if ($userId && ! $this->callerHasAccess($userId)) {
            return ['error' => 'Task creation requires admin or manager access.'];
        }

        if (! class_exists(Task::class)) {
            return ['error' => 'Task module not available.'];
        }

        $task = Task::create([
            'title'       => $args['title'],
            'description' => $args['description'] ?? null,
            'assigned_to' => $args['assigned_to'] ?? null,
            'due_date'    => $args['due_date'] ?? null,
            'priority'    => $args['priority'] ?? 'normal',
            'status'      => 'open',
            'created_by'  => $userId,
        ]);

        return [
            'task_id' => $task->id,
            'title'   => $task->title,
            'status'  => $task->status,
        ];
    }

    public function isAllowedForRole(string $role): bool
    {
        return in_array($role, ['admin', 'super_admin', 'department_manager'], true);
    }

    private function callerHasAccess(int $userId): bool
    {
        $user = \App\Models\User::query()->find($userId);

        return $user && $this->isAllowedForRole($user->role);
    }
}
