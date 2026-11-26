<?php

namespace App\Services;

use App\Models\Task;

/**
 * THE rules for a MANUAL status write on a task (the board's status move, the edit form, «مهامي», and creating a task with a
 * status). One place, so the four routes cannot drift apart again.
 *
 * The workflow the platform runs is:
 *
 *   ... -> needs_evaluation -> (manager evaluates) -> simple size:  completed            (credited by the evaluation)
 *                                                  -> any other:    waiting_approval     (the evaluation) -> (HR approves) -> completed (credited)
 *
 * so `waiting_approval` and `completed` are RESULTS of TaskController::evaluate / hrApprove, never something a person sets:
 * a status write that put a task there would claim an evaluation or an approval that did not happen (and, for `completed`,
 * make the real approval a silent "replay" that never credits the volunteer). And a task that already carries that outcome
 * (awaiting approval, approved, completed) is not re-opened or moved by hand: re-opening a credited task and approving it again
 * is how hours were added twice (the points ledger deduplicates, the hours never did).
 *
 * This class only judges a status CHANGE (writing the status the task already has is never refused). WHO may write (permission,
 * department scope, lock, assignee rules) stays with the callers; the evaluation and HR-approval endpoints are not manual writes
 * and do not go through here.
 */
class TaskTransitionRules
{
    /** The two outcomes only the evaluation / HR-approval flow may record. */
    public const OUTCOME_STATUSES = ['waiting_approval', 'completed'];

    /** Awaiting approval, approved by the manager or HR, or completed: the evaluation outcome is recorded (credit-bearing). */
    public static function isCreditBearing(Task $task): bool
    {
        return in_array($task->status, self::OUTCOME_STATUSES, true) || $task->manager_approved_at !== null || $task->hr_approved_at !== null;
    }

    /** The message when writing $to by hand on this EXISTING task is refused, null when it is allowed. */
    public static function refusal(Task $task, ?string $to): ?string
    {
        if ($to === null || $to === $task->status) {
            return null;
        }
        if (in_array($to, self::OUTCOME_STATUSES, true)) {
            return self::outcomeMessage();
        }
        if (self::isCreditBearing($task)) {
            return 'مهمة مقيَّمة أو معتمدة أو مكتملة لا تُعاد فتحها ولا تتغير حالتها يدويًا؛ ما سُجّل من تقييم واعتماد يبقى كما هو.';
        }

        return null;
    }

    /** The message when a NEW task may not be created with this status, null when it may. */
    public static function creationRefusal(?string $status): ?string
    {
        return $status !== null && in_array($status, self::OUTCOME_STATUSES, true) ? self::outcomeMessage() : null;
    }

    private static function outcomeMessage(): string
    {
        return 'لا تُسجَّل هذه الحالة يدويًا: «بانتظار الاعتماد» تنتج عن تقييم المدير، و«مكتملة» عن اعتماد الموارد البشرية (أو عن التقييم للمهمة البسيطة).';
    }
}
