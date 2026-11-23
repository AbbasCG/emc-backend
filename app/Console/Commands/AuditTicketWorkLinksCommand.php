<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * READ-ONLY audit of legacy ticket rows that would make «مهامي» (the my-work screen) wrong or confusing.
 *
 * It only SELECTs: there is no --apply, nothing is repaired, nothing is notified. The output lists, per check,
 * the number of tickets and their ids so an operator can decide what to fix by hand.
 */
class AuditTicketWorkLinksCommand extends Command
{
    protected $signature = 'tickets:audit-work-links {--limit=25 : Most ids printed per check (the count is always exact)}';

    protected $description = 'Read-only audit of ticket rows that affect the my-work screen (assignee, acceptance, estimate, duplicates, department, resolution). Changes nothing.';

    public function handle(TicketAccess $access): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $active = ['ASSIGNED', 'IN_PROGRESS'];

        /** @var array<int, array{0: string, 1: string, 2: array<int, int>}> $checks */
        $checks = [];

        // 1. Active ticket with no assignee at all.
        $checks[] = ['active_without_assignee', 'تذكرة نشطة بلا مكلّف / Active ticket with NULL assignee',
            $this->ids(Ticket::query()->whereIn('status', $active)->whereNull('assigned_to_id'))];

        // 2-3. Assignee gone (deleted or missing), inactive, or not eligible for the ticket's department/unit.
        $deleted = [];
        $inactive = [];
        $ineligible = [];
        Ticket::query()->whereIn('status', $active)->whereNotNull('assigned_to_id')
            ->select('id', 'assigned_to_id', 'department_id', 'unit_id')
            ->orderBy('id')
            ->chunkById(500, function ($tickets) use (&$deleted, &$inactive, &$ineligible, $access) {
                $users = User::withTrashed()->whereIn('id', $tickets->pluck('assigned_to_id')->unique())->get()->keyBy('id');
                foreach ($tickets as $t) {
                    $u = $users->get($t->assigned_to_id);
                    if (! $u || $u->trashed()) {
                        $deleted[] = $t->id;
                    } elseif (! $u->is_active) {
                        $inactive[] = $t->id;
                    } elseif (! $access->assigneeEligible($u, (int) $t->department_id, $t->unit_id ? (int) $t->unit_id : null)) {
                        $ineligible[] = $t->id;
                    }
                }
            });
        $checks[] = ['assignee_deleted', 'المكلّف محذوف / Assignee deleted or missing', $deleted];
        $checks[] = ['assignee_inactive', 'المكلّف غير فعّال / Assignee inactive', $inactive];
        $checks[] = ['assignee_ineligible', 'المكلّف غير مؤهّل لإدارة/وحدة التذكرة / Assignee not eligible for the ticket department or unit', $ineligible];

        // 4-5. Acceptance timestamp vs status.
        $checks[] = ['in_progress_without_accepted_at', 'قيد المعالجة بلا وقت قبول / IN_PROGRESS with NULL accepted_at',
            $this->ids(Ticket::query()->where('status', 'IN_PROGRESS')->whereNull('accepted_at'))];
        $checks[] = ['assigned_with_accepted_at', 'مُسندة بوقت قبول / ASSIGNED with a non-null accepted_at',
            $this->ids(Ticket::query()->where('status', 'ASSIGNED')->whereNotNull('accepted_at'))];

        // 6. Active assigned work with no estimate (legacy: it must be set before the next reassignment).
        $checks[] = ['active_without_estimate', 'تذكرة مُسندة بلا حجم/نقاط / Active assigned ticket without task_scope or task_points',
            $this->ids(Ticket::query()->whereIn('status', $active)->whereNotNull('assigned_to_id')
                ->where(fn ($q) => $q->whereNull('task_scope')->orWhereNull('task_points')))];

        // 7. Duplicate ticket numbers (soft-deleted rows included: the unique index counts them too).
        $dupNumbers = DB::table('tickets')->select('ticket_number')->groupBy('ticket_number')->havingRaw('COUNT(*) > 1')->pluck('ticket_number');
        $checks[] = ['duplicate_ticket_number', 'رقم تذكرة مكرّر / Duplicate ticket_number',
            $dupNumbers->isEmpty() ? [] : DB::table('tickets')->whereIn('ticket_number', $dupNumbers)->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all()];

        // 8. Department no longer exists.
        $checks[] = ['department_missing', 'الإدارة غير موجودة / Department no longer exists',
            DB::table('tickets')->leftJoin('departments', 'departments.id', '=', 'tickets.department_id')
                ->whereNull('departments.id')->whereNull('tickets.deleted_at')->orderBy('tickets.id')->pluck('tickets.id')->map(fn ($i) => (int) $i)->all()];

        // 9. Status vs resolved_at.
        $checks[] = ['resolved_without_resolved_at', 'محلولة بلا وقت حل / RESOLVED with NULL resolved_at',
            $this->ids(Ticket::query()->where('status', 'RESOLVED')->whereNull('resolved_at'))];
        $checks[] = ['resolved_at_on_unresolved_status', 'وقت حل على تذكرة غير محلولة / Non-RESOLVED ticket with resolved_at',
            $this->ids(Ticket::query()->where('status', '!=', 'RESOLVED')->whereNotNull('resolved_at'))];

        $rows = [];
        $total = 0;
        foreach ($checks as [$key, $label, $ids]) {
            $count = count($ids);
            $total += $count;
            $shown = array_slice($ids, 0, $limit);
            $rows[] = [$key, $label, $count, $count === 0 ? '-' : implode(', ', $shown).($count > $limit ? ', ... (+'.($count - $limit).')' : '')];
        }

        $this->info('تدقيق روابط عمل التذاكر (قراءة فقط) / Ticket work-links audit (read-only)');
        $this->table(['Check', 'Description', 'Count', 'Ticket ids'], $rows);
        $this->line($total === 0
            ? 'لا مشاكل / No problems found.'
            : "إجمالي الحالات / Total flagged cases: {$total} (a ticket can appear under more than one check). Nothing was changed.");

        return self::SUCCESS;
    }

    /** @return array<int, int> */
    private function ids($query): array
    {
        return $query->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();
    }
}
