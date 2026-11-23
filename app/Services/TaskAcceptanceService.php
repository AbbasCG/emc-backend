<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Http\Request;

/**
 * The state change behind "the assignee accepts / declines a task", in ONE place.
 *
 * Both the management endpoints (Admin\TaskController::accept / reject, which also let admin roles act for the
 * assignee) and the member-safe own-work endpoints (Api\MyWorkTaskController) call it. Who may call, and which
 * acceptance states are allowed, is decided by the CALLER; this class only applies the change and writes the
 * activity-log entry, so the two routes can never record an acceptance differently.
 */
class TaskAcceptanceService
{
    public static function accept(Task $task, ?Request $request = null): Task
    {
        $task->update([
            'acceptance_status' => Task::ACCEPTANCE_ACCEPTED,
            'accepted_at'       => now(),
            'rejection_reason'  => null,
            'status'            => in_array($task->status, ['idea', 'under_review']) ? 'planning' : $task->status,
        ]);

        ActivityLogger::log('task.accepted', $task, [], $request);

        return $task;
    }

    public static function reject(Task $task, string $reason, ?Request $request = null): Task
    {
        $task->update([
            'acceptance_status' => Task::ACCEPTANCE_REJECTED,
            'rejection_reason'  => $reason,
            'rejected_at'       => now(),
        ]);

        ActivityLogger::log('task.rejected', $task, ['reason' => $reason], $request);

        return $task;
    }
}
