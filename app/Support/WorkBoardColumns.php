<?php

namespace App\Support;

/**
 * The ONE server-side map from a task state or a ticket state to a column of the work boards:
 * «مهامي ومسؤولياتي» (/dashboard/admin/tasks/my) and the global operations board (/dashboard/operations/board).
 *
 * It is the PHP twin of the frontend `src/lib/myWorkColumns.ts` (the frontend map stays the display source for
 * «مهامي»; this one decides the column of every card the global board returns, and `column` is also sent on the
 * my-work ticket rows). The two maps must never differ, so tests/Feature/OperationsWorkBoardTest.php compares them
 * entry by entry whenever the frontend checkout sits next to this repository.
 *
 * Task states (Task::status + acceptance_status):
 *   idea, under_review, planning, waiting_approval, in_progress, needs_evaluation, completed, postponed, cancelled
 *   acceptance: pending (assigned, not yet accepted) · accepted · rejected (the member declined)
 * Ticket states (Ticket::status): see TicketAccess / TicketQuery::STATUSES.
 */
final class WorkBoardColumns
{
    /** Columns in display order: key => Arabic label (the labels of MY_WORK_COLUMNS). */
    public const COLUMNS = [
        'ideas' => 'أفكار',
        'pending' => 'بانتظار الاستلام',
        'planning' => 'قيد المراجعة والتخطيط',
        'in_progress' => 'قيد التنفيذ',
        'review' => 'بانتظار الاعتماد والتقييم',
        'completed' => 'مكتملة',
        'on_hold' => 'مؤجلة / ملغاة',
        'closed' => 'متعذّر / معتذر عنه',
    ];

    /** Columns whose work is finished or put aside: a due date there is never "overdue". */
    public const NOT_OVERDUE = ['completed', 'on_hold', 'closed'];

    /** Task status => column (the stage alone; acceptance is applied by forTask). */
    public const TASK_STATUS_COLUMN = [
        'idea' => 'ideas',
        'under_review' => 'planning',
        'planning' => 'planning',
        'waiting_approval' => 'review',
        'in_progress' => 'in_progress',
        'needs_evaluation' => 'review',
        'completed' => 'completed',
        'postponed' => 'on_hold',
        'cancelled' => 'on_hold',
        // Vocabulary of the previous management screens, kept so a stale row still lands somewhere sensible.
        'study' => 'planning',
        'pending_approval' => 'review',
        'needs_review' => 'review',
        'done' => 'completed',
        'deferred' => 'on_hold',
    ];

    public const TICKET_STATUS_COLUMN = [
        'PENDING_APPROVAL' => 'pending',
        'ASSIGNED' => 'pending',
        'IN_PROGRESS' => 'in_progress',
        // Submitted by the assignee, waiting for the final approval: the same column as a task waiting for approval.
        'PENDING_REVIEW' => 'review',
        // A deployment ticket after the review: approved for deployment, then deployed and waiting for the assignee's live test.
        'APPROVED_FOR_DEPLOY' => 'review',
        'PENDING_VERIFICATION' => 'review',
        'RESOLVED' => 'completed',
        'UNRESOLVED' => 'closed',
        'REJECTED_BY_ASSIGNEE' => 'closed',
        'REJECTED_BY_ADMIN' => 'closed',
    ];

    /** The stage of a ticket in the review cycle: the badge on its card and the line saying who moves next. */
    public const TICKET_STAGE_CARD = [
        'PENDING_REVIEW' => ['badge' => 'بانتظار المراجعة', 'next' => 'بانتظار مراجعة الإنجاز'],
        'APPROVED_FOR_DEPLOY' => ['badge' => 'معتمدة للنشر', 'next' => 'بانتظار النشر'],
        'PENDING_VERIFICATION' => ['badge' => 'بانتظار اختبار المكلّف', 'next' => 'بانتظار اختبار المكلّف'],
    ];

    /**
     * The column of a task. A task still waiting for the member's answer is «بانتظار الاستلام» whatever its stage
     * (unless it is already finished or cancelled); a declined task is «معتذر عنه»; otherwise the stage decides.
     * An unknown status never disappears: it is shown in «قيد المراجعة والتخطيط».
     */
    public static function forTask(?string $status, ?string $acceptance = null): string
    {
        $byStatus = self::TASK_STATUS_COLUMN[$status ?? ''] ?? 'planning';
        if ($acceptance === 'rejected') {
            return 'closed';
        }
        if ($acceptance === 'pending' && $byStatus !== 'completed' && $byStatus !== 'on_hold') {
            return 'pending';
        }

        return $byStatus;
    }

    /** The column of a ticket. An unknown status is treated as awaiting a response, so it is never hidden. */
    public static function forTicket(?string $status): string
    {
        return self::TICKET_STATUS_COLUMN[$status ?? ''] ?? 'pending';
    }

    /**
     * The ticket statuses that land in a column.
     *
     * @return array<int, string>
     */
    public static function ticketStatusesFor(string $column): array
    {
        return array_keys(array_filter(self::TICKET_STATUS_COLUMN, fn (string $c) => $c === $column));
    }

    /** @return array<int, array{key: string, label: string}> */
    public static function list(): array
    {
        $out = [];
        foreach (self::COLUMNS as $key => $label) {
            $out[] = ['key' => $key, 'label' => $label];
        }

        return $out;
    }
}
