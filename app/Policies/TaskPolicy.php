<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->hasRole('department_manager');
    }

    public function view(User $user, Task $task): bool
    {
        if ($user->isAdmin() || $user->hasRole('department_manager')) {
            return true;
        }

        // Assigned user can view their own tasks
        return $task->assigned_to === $user->id || $task->created_by === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->hasRole('department_manager');
    }

    public function update(User $user, Task $task): bool
    {
        if ($user->isAdmin() || $user->hasRole('department_manager')) {
            return true;
        }

        // Assigned user can update status on their own tasks
        return $task->assigned_to === $user->id;
    }

    public function delete(User $user, Task $task): bool
    {
        return $user->isAdmin();
    }

    public function comment(User $user, Task $task): bool
    {
        return $this->view($user, $task);
    }
}
