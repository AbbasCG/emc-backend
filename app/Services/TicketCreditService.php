<?php

namespace App\Services;

use App\Exceptions\TicketActionException;
use App\Models\Ticket;
use App\Models\TicketActivityLog;
use App\Models\TicketCredit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The FINAL approval of a ticket's completion: the one and only place where a ticket credits points and hours.
 *
 * Nothing else credits: assigning, accepting, submitting for review and returning for rework never touch the
 * ledger, and tickets that were already RESOLVED / UNRESOLVED / closed before this feature existed are never
 * credited by any path (there is no backfill; only an explicit approval of a PENDING_REVIEW ticket credits).
 *
 * WHAT IS CREDITED: to the ASSIGNEE (never the approver), the points and hours of the ticket's FINAL stored
 * estimate (task_points / estimated_hours; null hours credit 0), the same "no actual-duration step" rule as
 * regular tasks. The approver may correct the estimate in the same request; the correction is validated by
 * TicketWorkEstimate, logged (ESTIMATE_UPDATED) and is what gets credited. A legacy ticket with no size/points
 * cannot be approved until the request supplies them.
 *
 * WHO: a leader of the ticket's department or a global role (TicketAccess::canManage) who is NOT the assignee;
 * work above «بسيطة» also needs an HR approver (TicketAccess::HR_APPROVAL_ROLES), mirroring regular tasks
 * (simple: the manager's evaluation credits; above simple: HR's final approval credits). The HR gate is judged
 * on the LARGER of the stored and the final scope, so a leader cannot shrink a medium ticket to «بسيطة» in the
 * approval request to avoid HR.
 *
 * EXACTLY ONCE, in three layers:
 *   1. the ticket row is locked (SELECT ... FOR UPDATE) and the credit is looked up under that lock;
 *   2. the ticket_credits row (UNIQUE ticket_id) is the FIRST write, so a violation happens before anything else
 *      is written, and it rolls the whole transaction back (no ledger row, no hours);
 *   3. the ledger insert itself is source-idempotent (VolunteerCreditService::awardPoints).
 * The unique violation is caught OUTSIDE the transaction boundary: on PostgreSQL a failed statement aborts the
 * transaction, so nothing may continue inside it. A replayed or concurrent request gets HTTP 409 with
 * DUPLICATE_MESSAGE and changes nothing.
 */
class TicketCreditService
{
    public const DUPLICATE_MESSAGE = 'تم اعتماد هذه التذكرة وإضافة رصيدها مسبقًا.';

    /** Stable machine-readable refusal codes of the review endpoints. */
    public const CODE_ALREADY_CREDITED = 'ALREADY_CREDITED';
    public const CODE_NOT_PENDING_REVIEW = 'NOT_PENDING_REVIEW';
    public const CODE_SELF_REVIEW = 'SELF_REVIEW';
    public const CODE_HR_REQUIRED = 'HR_APPROVAL_REQUIRED';
    public const CODE_ESTIMATE_FROZEN = 'ESTIMATE_FROZEN';

    /** The 422 for a ticket that reaches its approval with no size/points (legacy); the assignment path keeps its own wording. */
    public const MISSING_ESTIMATE_MESSAGE = 'حدّد حجم المهمة ونقاطها قبل اعتماد الإنجاز';

    public const SUBMITTER_MESSAGE = 'لا يمكنك مراجعة عمل أرسلتَه أنت للمراجعة؛ يلزم مراجع آخر.';

    /** volunteer_point_awards.reason is VARCHAR(255). */
    private const LEDGER_REASON_MAX = 255;

    public function __construct(private readonly TicketAccess $access) {}

    /**
     * @param  array<string, mixed>  $estimateInput  any subset of task_scope / task_points / estimated_hours
     *
     * @throws TicketActionException
     */
    public function approveCompletion(int $ticketId, User $actor, array $estimateInput = [], ?string $note = null): Ticket
    {
        try {
            return $this->attempt($ticketId, $actor, $estimateInput, $note);
        } catch (QueryException $e) {
            // The unique index fired: another request credited this ticket between our check and our insert. The
            // transaction has already rolled back, so no ledger row and no hours were written by this request.
            if ($this->isUniqueViolation($e) && TicketCredit::where('ticket_id', $ticketId)->exists()) {
                throw new TicketActionException(self::DUPLICATE_MESSAGE, 409, [], self::CODE_ALREADY_CREDITED);
            }

            throw $e;
        }
    }

    /** Primary guard: has this ticket been credited already? (Overridable only so a test can reach the backstop.) */
    protected function alreadyCredited(Ticket $ticket): bool
    {
        return $ticket->credited_at !== null || TicketCredit::where('ticket_id', $ticket->id)->exists();
    }

    /** @param array<string, mixed> $estimateInput */
    private function attempt(int $ticketId, User $actor, array $estimateInput, ?string $note): Ticket
    {
        return DB::transaction(function () use ($ticketId, $actor, $estimateInput, $note) {
            $ticket = Ticket::lockForUpdate()->find($ticketId);
            if (! $ticket || ! $this->access->canView($actor, $ticket)) {
                throw new TicketActionException('التذكرة غير موجودة أو لا تملك صلاحية عليها.', 404);
            }
            // Self-review is judged BEFORE the generic "leaders only" refusal: an ordinary member who is the assignee is told
            // plainly that nobody approves their own work (instead of a misleading "only the department leader").
            if ($ticket->assigned_to_id !== null && (int) $ticket->assigned_to_id === (int) $actor->id) {
                throw new TicketActionException('لا يمكنك اعتماد إنجاز مهمة أنت المكلف بها؛ يلزم مراجع آخر.', 403, [], self::CODE_SELF_REVIEW);
            }
            if ($ticket->submitted_by_id !== null && (int) $ticket->submitted_by_id === (int) $actor->id) {
                throw new TicketActionException(self::SUBMITTER_MESSAGE, 403, [], self::CODE_SELF_REVIEW);
            }
            if (! $this->access->canManage($actor, (int) $ticket->department_id)) {
                throw new TicketActionException('الاعتماد النهائي لإنجاز التذكرة متاح فقط لقائد الإدارة المعنية.', 403);
            }
            if ($this->alreadyCredited($ticket)) {
                throw new TicketActionException(self::DUPLICATE_MESSAGE, 409, [], self::CODE_ALREADY_CREDITED);
            }
            if ($ticket->status !== TicketAccess::PENDING_REVIEW) {
                throw new TicketActionException('لا يمكن اعتماد إنجاز تذكرة ليست بحالة (بانتظار المراجعة والاعتماد).', 409, [], self::CODE_NOT_PENDING_REVIEW);
            }

            $assignee = $ticket->assigned_to_id ? User::find($ticket->assigned_to_id) : null;
            if (! $assignee) {
                throw new TicketActionException('لا يمكن الاعتماد: لا يوجد مكلف تُضاف إليه النقاط والساعات.', 409);
            }

            // Size and points must exist (legacy tickets supply them here) and be valid for the size.
            $work = TicketWorkEstimate::resolve($ticket, $estimateInput, missingMessage: self::MISSING_ESTIMATE_MESSAGE);

            // HR gate on the larger of the stored and the final size (see the class docblock).
            if (($this->access->needsHrApproval($ticket->task_scope) || $this->access->needsHrApproval($work['task_scope'])) && ! $this->access->isHrApprover($actor)) {
                throw new TicketActionException('اعتماد هذه التذكرة يحتاج إلى اعتماد الموارد البشرية لأن حجمها أعلى من «بسيطة».', 403, [], self::CODE_HR_REQUIRED);
            }

            $estimateBefore = TicketWorkEstimate::snapshot($ticket);
            $estimateChanged = TicketWorkEstimate::differs($ticket, $work);

            $now = now();
            $points = (int) $work['task_points'];
            $hours = round((float) ($work['estimated_hours'] ?? 0), 2);

            // 1. The unique row FIRST: if the backstop fires, nothing else has been written yet.
            TicketCredit::create([
                'ticket_id' => $ticket->id,
                'user_id' => $assignee->id,
                'points' => $points,
                'hours' => $hours,
                'approved_by_id' => $actor->id,
                'credited_at' => $now,
            ]);

            // 2. The ticket: final status, the estimate it was credited with, and the snapshot of the credit.
            $ticket->fill($work);
            $ticket->forceFill([
                'status' => 'RESOLVED',
                'resolved_at' => $now,
                'credited_points' => $points,
                'credited_hours' => $hours,
                'credited_at' => $now,
                'credited_by_id' => $actor->id,
            ])->save();

            // 3. The ledger and the cumulative hours (the same code regular tasks use).
            $award = VolunteerCreditService::awardPoints(
                (int) $assignee->id,
                $points,
                'ticket',
                // A ticket title may be up to 255 characters; the ledger reason column is 255 in total (MariaDB strict mode
                // refuses an over-long value, which would make the ticket impossible to approve).
                Str::limit('إنجاز تذكرة: '.$ticket->title, self::LEDGER_REASON_MAX - 1, '…'),
                'اعتماد نهائي للتذكرة '.$ticket->ticket_number.' (حجم: '.(TicketWorkEstimate::SCOPE_LABELS[$work['task_scope']] ?? $work['task_scope']).')',
                (int) $actor->id,
                Ticket::class,
                (int) $ticket->id,
            );
            if ($award === null) {
                // A ledger row already exists for this ticket but no credit row does: never add a second one.
                throw new TicketActionException(self::DUPLICATE_MESSAGE, 409, [], self::CODE_ALREADY_CREDITED);
            }
            if ($hours > 0) {
                VolunteerCreditService::addHours((int) $assignee->id, $hours);
            }

            // 4. The record. The public row carries no numbers; CREDIT_ADDED (like ESTIMATE_UPDATED) is for managers,
            //    global roles and the assignee only (TicketAccess::INTERNAL_LOG_ACTIONS).
            if ($estimateChanged) {
                TicketWorkEstimate::log($ticket, $actor, $estimateBefore, TicketWorkEstimate::snapshot($ticket));
            }
            TicketActivityLog::create([
                'ticket_id' => $ticket->id,
                'action' => 'APPROVED_COMPLETION',
                'previous_state' => TicketAccess::PENDING_REVIEW,
                'new_state' => 'RESOLVED',
                'performed_by' => $actor->id,
                'performed_by_name' => Str::limit((string) $actor->name, 150, ''),
                'details' => 'تم الاعتماد النهائي لإنجاز التذكرة وإغلاقها',
                'logged_at' => $now,
            ]);
            $note = $note !== null ? trim($note) : '';
            TicketActivityLog::create([
                'ticket_id' => $ticket->id,
                'action' => 'CREDIT_ADDED',
                'previous_state' => null,
                'new_state' => null,
                'performed_by' => $actor->id,
                'performed_by_name' => Str::limit((string) $actor->name, 150, ''),
                'details' => 'أُضيف إلى رصيد '.$assignee->name.': '.$points.' نقطة و'.rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.').' ساعة'.($note !== '' ? ' | ملاحظة الاعتماد: '.$note : ''),
                'logged_at' => $now,
            ]);

            Log::info("Ticket #{$ticket->id} completion approved and credited: {$points} points, {$hours} hours to user #{$assignee->id}");

            $assigneeId = (int) $assignee->id;
            $title = (string) $ticket->title;
            $tid = (int) $ticket->id;
            $actorId = (int) $actor->id;
            DB::afterCommit(function () use ($assigneeId, $tid, $title, $points, $hours, $actorId) {
                try {
                    NotificationService::ticketCompletionApproved($assigneeId, $tid, $title, $points, $hours, $actorId);
                } catch (\Throwable $e) {
                    // The credit has already committed; telling the assignee is best-effort.
                    report($e);
                }
            });

            return $ticket;
        });
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // 23000 integrity constraint (MySQL/MariaDB/SQLite), 23505 unique_violation (PostgreSQL).
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        $message = strtolower($e->getMessage());

        return in_array($state, ['23000', '23505'], true)
            && (str_contains($message, 'ticket_credits') || str_contains($message, 'unique'));
    }
}
