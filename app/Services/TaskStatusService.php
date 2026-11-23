<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Http\Request;

/**
 * The state change behind "move a task to another status", in ONE place.
 *
 * Admin\TaskController::updateStatus (management / board route) and Api\MyWorkTaskController::status (own-only
 * member route) both call it, so a move is written and logged identically. WHO may move and WHEN (locked task,
 * acceptance) is decided by the CALLER; this class only applies the move and writes the activity-log entry.
 */
class TaskStatusService
{
    public const STATUSES = ['idea', 'under_review', 'planning', 'waiting_approval', 'in_progress', 'completed', 'postponed', 'cancelled', 'needs_evaluation'];

    public static function move(Task $task, string $status, ?Request $request = null): Task
    {
        $data = ['status' => $status];

        $task->update($data);
        ActivityLogger::log('task.status_changed', $task, $data, $request);

        return $task;
    }
}
