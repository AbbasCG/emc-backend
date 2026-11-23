<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskComment;

/**
 * The writes a person does on a task they work on (comment, tick a checklist item), in ONE place.
 *
 * Admin\TaskController (management routes) and Api\MyWorkTaskController (own-only member routes) both call
 * these, so a comment or a ticked item is recorded identically whichever door it came through. Authorization and
 * request validation belong to the CALLER; nothing here decides who may act.
 */
class TaskCollaborationService
{
    public static function addComment(Task $task, int $userId, string $comment): TaskComment
    {
        return $task->comments()->create([
            'user_id' => $userId,
            'comment' => $comment,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data  already validated fields of the item (title, is_completed, sort_order)
     */
    public static function updateChecklistItem(TaskChecklistItem $item, array $data): TaskChecklistItem
    {
        $item->update($data);

        return $item;
    }
}
