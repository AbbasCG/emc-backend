<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Models\TicketCredit;
use App\Models\User;
use App\Models\VolunteerPointAward;
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
                    } elseif (! $access->assigneeEligible($u, (int) $t->department_id, $access->effectiveUnitId($t, null))) {
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
        $checks[] = ['active_without_estimate', 'تذكرة مُسندة بلا حجم/نقاط / Active assigned ticket without task_scope or task_points (incl. PENDING_REVIEW: it must be completed through rework before the review can be confirmed)',
            $this->ids(Ticket::query()->whereIn('status', [...$active, 'PENDING_REVIEW'])->whereNotNull('assigned_to_id')
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

        // 10-14. Review and credit integrity. Read-only: nothing is ever credited or repaired here, and tickets that
        //        were closed before the review step existed are deliberately NOT flagged for missing a credit.
        $checks[] = ['pending_review_without_assignee', 'بانتظار المراجعة بلا مكلّف / PENDING_REVIEW with NULL assignee (nobody to credit)',
            $this->ids(Ticket::query()->where('status', 'PENDING_REVIEW')->whereNull('assigned_to_id'))];

        $checks[] = ['credited_without_credit_row', 'تذكرة معتمدة الرصيد بلا سجل رصيد / Ticket with credited_at but no ticket_credits row',
            $this->ids(Ticket::query()->whereNotNull('credited_at')->whereNotIn('id', TicketCredit::query()->select('ticket_id')))];

        $checks[] = ['credit_row_without_snapshot', 'سجل رصيد لتذكرة بلا لقطة اعتماد / ticket_credits row whose ticket has no credited_at snapshot',
            DB::table('ticket_credits')->join('tickets', 'tickets.id', '=', 'ticket_credits.ticket_id')->whereNull('tickets.credited_at')
                ->orderBy('ticket_credits.ticket_id')->pluck('ticket_credits.ticket_id')->map(fn ($i) => (int) $i)->all()];

        $checks[] = ['credit_row_without_ledger_award', 'سجل رصيد بلا قيد في ليدجر النقاط / ticket_credits row without a volunteer_point_awards row',
            DB::table('ticket_credits')->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('volunteer_point_awards')
                    ->where('volunteer_point_awards.source_type', Ticket::class)
                    ->whereColumn('volunteer_point_awards.source_id', 'ticket_credits.ticket_id');
            })->orderBy('ticket_id')->pluck('ticket_id')->map(fn ($i) => (int) $i)->all()];

        $checks[] = ['duplicate_awards_per_ticket', 'أكثر من قيد نقاط لتذكرة واحدة / More than one ledger award for the same ticket',
            VolunteerPointAward::query()->where('source_type', Ticket::class)->select('source_id')->groupBy('source_id')->havingRaw('COUNT(*) > 1')
                ->orderBy('source_id')->pluck('source_id')->map(fn ($i) => (int) $i)->all()];

        // 15-16. The deployment cycle. A ticket in APPROVED_FOR_DEPLOY / PENDING_VERIFICATION must carry the approved values
        //        (and, once live, the deployment fields); a deployment ticket credited without the live verification is wrong.
        $checks[] = ['deployment_stage_without_approved_values', 'تذكرة نشر في مرحلة الاعتماد/التحقق بلا قيم معتمدة أو بيانات نشر / Deployment ticket in APPROVED_FOR_DEPLOY or PENDING_VERIFICATION without approved values or deploy fields',
            $this->ids(Ticket::query()->where('requires_deployment', true)->where(function ($q) {
                $q->where(fn ($a) => $a->whereIn('status', ['APPROVED_FOR_DEPLOY', 'PENDING_VERIFICATION'])
                    ->where(fn ($b) => $b->whereNull('actual_minutes')->orWhereNull('task_points')->orWhereNull('task_scope')->orWhereNull('review_approved_by_id')->orWhereNull('review_approved_at')))
                    ->orWhere(fn ($a) => $a->where('status', 'PENDING_VERIFICATION')
                        ->where(fn ($b) => $b->whereNull('deployed_at')->orWhereNull('deployed_by_id')->orWhereNull('live_url')));
            }))];
        $checks[] = ['credited_deployment_without_verification', 'تذكرة نشر معتمدة الرصيد بلا تحقق على الموقع الحي / Credited deployment ticket without verified_at',
            $this->ids(Ticket::query()->where('requires_deployment', true)->whereNotNull('credited_at')->whereNull('verified_at'))];

        // 17. A deployment ticket parked after review whose assignee can no longer act (gone, deactivated, blocked): nobody could
        //     verify it on the live site. A manager frees it by returning it for rework.
        $stuck = [];
        Ticket::query()->where('requires_deployment', true)->whereIn('status', ['APPROVED_FOR_DEPLOY', 'PENDING_VERIFICATION'])
            ->orderBy('id')->chunkById(500, function ($tickets) use (&$stuck, $access) {
                foreach ($tickets as $t) {
                    if ($access->assigneeUnavailable($t)) {
                        $stuck[] = (int) $t->id;
                    }
                }
            });
        $checks[] = ['deployment_assignee_unavailable', 'تذكرة نشر عالقة: المكلّف محذوف أو غير فعّال أو محجوب / Deployment ticket in APPROVED_FOR_DEPLOY or PENDING_VERIFICATION whose assignee is missing, inactive or blocked', $stuck];

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
