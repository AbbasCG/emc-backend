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
use Illuminate\Support\Facades\Validator;
use App\Support\TicketValidationMessages;

/**
 * The FINAL approval of a ticket's completion: the one and only place where a ticket credits points and hours.
 *
 * Nothing else credits: assigning, accepting, submitting for review and returning for rework never touch the
 * ledger, and tickets that were already RESOLVED / UNRESOLVED / closed before this feature existed are never
 * credited by any path (there is no backfill; only an explicit approval of a PENDING_REVIEW ticket credits).
 *
 * WHAT IS CREDITED: to the ASSIGNEE (never the approver), the POINTS of the ticket's final stored estimate
 * (task_points, scope ranges unchanged) and the HOURS of the ACTUAL duration the reviewer enters in the approval
 * (actual_minutes, REQUIRED, credited as round(minutes / 60, 2)). `estimated_hours` is planning data only: it is never
 * credited, never a fallback and never 0-substituted. The approver may correct the estimate in the same request; the
 * correction is validated by TicketWorkEstimate and logged (ESTIMATE_UPDATED). A legacy ticket with no size/points
 * cannot be approved until the request supplies them either.
 *
 * ORDER OF CHECKS (so an unauthorised caller can never store or probe the request): view/404, self-review and
 * submitter 403, manager 403, already credited 409, status 409, HR gate 403, then validation 422 (estimate fields, note
 * and the actual duration, all in ONE 422). The 409s come before the HR gate so a reviewer with a stale screen on an
 * already credited / no longer pending ticket is told that, not that HR is needed. Nothing is persisted by a refused
 * approval.
 *
 * DEPLOYMENT TICKETS (requires_deployment) are NOT closed here: approve-completion refuses them (409 DEPLOYMENT_REQUIRED)
 * and TicketDeploymentService runs their cycle. Its last step, the assignee's live verification, credits through
 * creditNow() below, so there is still ONE implementation of what a credit is and of how it stays single.
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
    public const CODE_ACTUAL_REQUIRED = 'ACTUAL_DURATION_REQUIRED';
    public const CODE_ACTUAL_INVALID = 'ACTUAL_DURATION_INVALID';
    // The deployment cycle (TicketDeploymentService shares this machinery).
    public const CODE_INVALID_STAGE = 'INVALID_STAGE';
    public const CODE_DEPLOYMENT_REQUIRED = 'DEPLOYMENT_REQUIRED';
    public const CODE_NOT_A_DEPLOYMENT_TICKET = 'NOT_A_DEPLOYMENT_TICKET';
    public const CODE_ASSIGNEE_ONLY = 'ASSIGNEE_ONLY';
    public const CODE_ASSIGNEE_UNAVAILABLE = 'ASSIGNEE_UNAVAILABLE';

    public const ACTUAL_REQUIRED_MESSAGE = 'أدخل المدة الفعلية للإنجاز (بالساعات والدقائق) قبل الاعتماد النهائي.';
    /** The same requirement as it reads in the deployment flow, where the final step is the live verification, not this approval. */
    public const ACTUAL_REQUIRED_FOR_DEPLOY_MESSAGE = 'أدخل المدة الفعلية للإنجاز (بالساعات والدقائق) قبل الاعتماد للنشر.';
    public const ACTUAL_INTEGER_MESSAGE = 'المدة الفعلية يجب أن تكون عددًا صحيحًا من الدقائق.';
    public const ACTUAL_MIN_MESSAGE = 'المدة الفعلية يجب ألا تقل عن دقيقة واحدة.';
    public const ACTUAL_MAX_MESSAGE = 'المدة الفعلية لا تتجاوز 1000 ساعة (60000 دقيقة).';

    /** 1000 hours. */
    public const MAX_ACTUAL_MINUTES = 60000;

    /** The 422 for a ticket that reaches its approval with no size/points (legacy); the assignment path keeps its own wording. */
    public const MISSING_ESTIMATE_MESSAGE = 'حدّد حجم المهمة ونقاطها قبل اعتماد الإنجاز';

    public const SUBMITTER_MESSAGE = 'لا يمكنك مراجعة عمل أرسلتَه أنت للمراجعة؛ يلزم مراجع آخر.';

    /** volunteer_point_awards.reason is VARCHAR(255). */
    private const LEDGER_REASON_MAX = 255;

    public function __construct(private readonly TicketAccess $access) {}

    /** Primary guard: has this ticket been credited already? (Overridable only so a test can reach the backstop.) */
    protected function alreadyCredited(Ticket $ticket): bool
    {
        return $ticket->credited_at !== null || TicketCredit::where('ticket_id', $ticket->id)->exists();
    }

    /**
     * Run a credit transaction and turn the unique-index backstop into the same 409 as the primary guard. The violation
     * is caught OUTSIDE the transaction (on PostgreSQL a failed statement aborts it), which has already rolled back: no
     * ledger row, no hours, no status change.
     *
     * @template T
     *
     * @param  callable(): T  $transaction
     * @return T
     */
    public function guardDuplicate(int $ticketId, callable $transaction): mixed
    {
        try {
            return $transaction();
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e) && TicketCredit::where('ticket_id', $ticketId)->exists()) {
                throw new TicketActionException(self::DUPLICATE_MESSAGE, 409, [], self::CODE_ALREADY_CREDITED);
            }

            throw $e;
        }
    }

    /**
     * Re-read the ticket under the row lock and apply the reviewer gates every review endpoint shares: it must be visible
     * (404), the actor must not be its assignee or the submitter of the current round (403 SELF_REVIEW, judged BEFORE the
     * generic refusal so an ordinary member is told plainly that nobody reviews their own work) and must be allowed to
     * manage its department (403). MUST run inside a transaction.
     */
    public function lockForReview(int $ticketId, User $actor, string $selfReviewMessage = 'لا يمكنك اعتماد إنجاز مهمة أنت المكلف بها؛ يلزم مراجع آخر.', string $managerMessage = 'الاعتماد النهائي لإنجاز التذكرة متاح فقط لقائد الإدارة المعنية.'): Ticket
    {
        $ticket = Ticket::lockForUpdate()->find($ticketId);
        if (! $ticket || ! $this->access->canView($actor, $ticket)) {
            throw new TicketActionException('التذكرة غير موجودة أو لا تملك صلاحية عليها.', 404);
        }
        if ($ticket->assigned_to_id !== null && (int) $ticket->assigned_to_id === (int) $actor->id) {
            throw new TicketActionException($selfReviewMessage, 403, [], self::CODE_SELF_REVIEW);
        }
        if ($ticket->submitted_by_id !== null && (int) $ticket->submitted_by_id === (int) $actor->id) {
            throw new TicketActionException(self::SUBMITTER_MESSAGE, 403, [], self::CODE_SELF_REVIEW);
        }
        if (! $this->access->canManage($actor, (int) $ticket->department_id)) {
            throw new TicketActionException($managerMessage, 403);
        }

        return $ticket;
    }

    /** The 409 for a ticket that has already been credited (the primary guard of every credit path). */
    public function refuseIfCredited(Ticket $ticket): void
    {
        if ($this->alreadyCredited($ticket)) {
            throw new TicketActionException(self::DUPLICATE_MESSAGE, 409, [], self::CODE_ALREADY_CREDITED);
        }
    }

    /**
     * HR gate on the larger of the stored size and the size the request asks for (see the class docblock). Judged BEFORE
     * any validation, from the raw requested size, so an unauthorised caller learns nothing more.
     *
     * @param  array<string, mixed>  $estimateInput
     */
    public function guardHr(Ticket $ticket, User $actor, array $estimateInput): void
    {
        $requestedScope = isset($estimateInput['task_scope']) && is_string($estimateInput['task_scope']) && in_array($estimateInput['task_scope'], TicketWorkEstimate::SCOPES, true) ? $estimateInput['task_scope'] : null; // an unknown size is a 422 later, not an HR question
        if (($this->access->needsHrApproval($ticket->task_scope) || $this->access->needsHrApproval($requestedScope)) && ! $this->access->isHrApprover($actor)) {
            throw new TicketActionException('اعتماد هذه التذكرة يحتاج إلى اعتماد الموارد البشرية لأن حجمها أعلى من «بسيطة».', 403, [], self::CODE_HR_REQUIRED);
        }
    }

    /**
     * Validation (422), last, in ONE response: the shape of the estimate fields and the note, then the estimate's business
     * rules (size/points must exist — legacy tickets supply them here — and fit the size), and the ACTUAL duration. Nothing
     * has been written yet.
     *
     * @param  array<string, mixed>  $estimateInput
     * @return array{0: array<string, mixed>, 1: int, 2: string} the resolved estimate, the actual minutes, the trimmed note
     */
    public function validateApproval(Ticket $ticket, array $estimateInput, mixed $note, mixed $actualMinutes, string $requiredMessage = self::ACTUAL_REQUIRED_MESSAGE): array
    {
        $errors = [];
        $message = null;
        $work = null;

        $shape = Validator::make(
            TicketWorkEstimate::only($estimateInput) + ['note' => $note],
            TicketWorkEstimate::rules() + ['note' => ['nullable', 'string', 'max:1000']],
            TicketWorkEstimate::messages() + TicketValidationMessages::all() + [
                'note.string' => 'الملاحظة غير صالحة.',
                'note.max' => 'الملاحظة طويلة جدًا؛ الحد الأقصى 1000 حرف.',
            ],
        );
        if ($shape->fails()) {
            $errors = $shape->errors()->toArray();
            $message = (string) $shape->errors()->first();
        }
        // The business rules run too (they re-validate every value they read), so one answer lists everything; a field
        // already reported by the shape check keeps that message.
        try {
            $work = TicketWorkEstimate::resolve($ticket, TicketWorkEstimate::only($estimateInput), missingMessage: self::MISSING_ESTIMATE_MESSAGE);
        } catch (TicketActionException $e) {
            $errors += $e->errors;
            $message ??= $e->getMessage();
        }

        [$minutes, $actualError, $actualCode] = $this->resolveActualMinutes($actualMinutes);
        if ($actualCode === self::CODE_ACTUAL_REQUIRED) {
            $actualError = $requiredMessage;
        }
        if ($actualError !== null) {
            $errors['actual_minutes'] = [$actualError];
            $message ??= $actualError;
        }

        if ($errors !== []) {
            // The headline is the first error. The machine code names the DURATION problem, so it is attached only when
            // the duration is the only thing wrong (then headline and code agree); with other errors present the code
            // would contradict the message, and the client reads `errors.actual_minutes` instead.
            throw new TicketActionException((string) $message, 422, $errors, array_keys($errors) === ['actual_minutes'] ? $actualCode : null);
        }

        return [$work, $minutes, trim((string) $note)];
    }

    /**
     * Approve a completed ticket's work and credit it, now (the classic flow: PENDING_REVIEW -> RESOLVED).
     *
     * @param  array<string, mixed>  $estimateInput  any subset of task_scope / task_points / estimated_hours (planning only)
     * @param  mixed  $note  the approver's note as RECEIVED (validated here, after authorization)
     * @param  mixed  $actualMinutes  the actual duration in minutes as RECEIVED (validated here, after authorization); null = not sent
     *
     * @throws TicketActionException
     */
    public function approveCompletion(int $ticketId, User $actor, array $estimateInput = [], mixed $note = null, mixed $actualMinutes = null): Ticket
    {
        return $this->guardDuplicate($ticketId, fn () => DB::transaction(function () use ($ticketId, $actor, $estimateInput, $note, $actualMinutes) {
            $ticket = $this->lockForReview($ticketId, $actor);
            $this->refuseIfCredited($ticket);
            // A deployment ticket is closed only by the assignee's verification on the live site.
            if ($this->access->isDeploymentTicket($ticket)) {
                throw new TicketActionException('هذه التذكرة تتطلب نشرًا على الموقع الحي؛ استخدم اعتماد للنشر.', 409, [], self::CODE_DEPLOYMENT_REQUIRED);
            }
            if ($ticket->status !== TicketAccess::PENDING_REVIEW) {
                throw new TicketActionException('لا يمكن اعتماد إنجاز تذكرة ليست بحالة (بانتظار المراجعة والاعتماد).', 409, [], self::CODE_NOT_PENDING_REVIEW);
            }
            $this->guardHr($ticket, $actor, $estimateInput);

            $assignee = $ticket->assigned_to_id ? User::find($ticket->assigned_to_id) : null;
            if (! $assignee) {
                throw new TicketActionException('لا يمكن الاعتماد: لا يوجد مكلف تُضاف إليه النقاط والساعات.', 409);
            }

            [$work, $minutes, $note] = $this->validateApproval($ticket, $estimateInput, $note, $actualMinutes);

            $estimateBefore = TicketWorkEstimate::snapshot($ticket);
            $estimateChanged = TicketWorkEstimate::differs($ticket, $work);

            $ticket->fill($work);
            $this->creditNow(
                $ticket, $assignee, $actor, (int) $actor->id, (int) $work['task_points'], $minutes, $work['task_scope'],
                'اعتماد نهائي للتذكرة '.$ticket->ticket_number,
                function () use ($ticket, $actor, $estimateChanged, $estimateBefore, $note) {
                    if ($estimateChanged) {
                        TicketWorkEstimate::log($ticket, $actor, $estimateBefore, TicketWorkEstimate::snapshot($ticket));
                    }
                    $this->log($ticket, $actor, 'APPROVED_COMPLETION', TicketAccess::PENDING_REVIEW, 'RESOLVED', 'تم الاعتماد النهائي لإنجاز التذكرة وإغلاقها');

                    return $note !== '' ? ' | ملاحظة الاعتماد: '.$note : '';
                },
                true,
            );

            return $ticket;
        }));
    }

    /**
     * The ONE credit: the unique ticket_credits row FIRST (a violation stops everything before anything else is written),
     * the ticket's final snapshot, the ledger row and the cumulative hours, the records and the notification. MUST run
     * inside the caller's transaction, on a ticket that was locked and judged by the caller.
     *
     * @param  User  $performer  who is doing it now (the log rows); $approverId is who the credit is recorded as approved by
     * @param  int  $points  the approved points
     * @param  int  $minutes  the approved actual minutes (hours = round(minutes / 60, 2))
     * @param  callable(): string  $writeLogs  writes the step-specific log rows and returns the text appended to CREDIT_ADDED
     * @param  bool  $notifyAssignee  tell the assignee (false when the assignee is the one performing it)
     */
    public function creditNow(Ticket $ticket, User $assignee, User $performer, ?int $approverId, int $points, int $minutes, string $scope, string $ledgerNote, callable $writeLogs, bool $notifyAssignee): void
    {
        $now = now();
        // The credited hours are the ACTUAL approved time, never the planning estimate.
        $hours = round($minutes / 60, 2);

        // 1. The unique row FIRST: if the backstop fires, nothing else has been written yet.
        TicketCredit::create([
            'ticket_id' => $ticket->id,
            'user_id' => $assignee->id,
            'points' => $points,
            'hours' => $hours,
            'minutes' => $minutes,
            'approved_by_id' => $approverId,
            'credited_at' => $now,
        ]);

        // 2. The ticket: final status and the snapshot of the credit.
        $ticket->forceFill([
            'status' => 'RESOLVED',
            'resolved_at' => $now,
            'credited_points' => $points,
            'credited_hours' => $hours,
            'credited_minutes' => $minutes,
            'actual_minutes' => $minutes,
            'credited_at' => $now,
            'credited_by_id' => $approverId,
        ])->save();

        // 3. The ledger and the cumulative hours (the same code regular tasks use).
        $award = VolunteerCreditService::awardPoints(
            (int) $assignee->id,
            $points,
            'ticket',
            // A ticket title may be up to 255 characters; the ledger reason column is 255 in total (MariaDB strict mode
            // refuses an over-long value, which would make the ticket impossible to approve).
            Str::limit('إنجاز تذكرة: '.$ticket->title, self::LEDGER_REASON_MAX - 1, '…'),
            $ledgerNote.' (حجم: '.(TicketWorkEstimate::SCOPE_LABELS[$scope] ?? $scope).')',
            $approverId,
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

        // 4. The record. The public rows carry no numbers; CREDIT_ADDED (like ESTIMATE_UPDATED) is for managers, global
        //    roles and the assignee only (TicketAccess::INTERNAL_LOG_ACTIONS).
        $suffix = $writeLogs();
        TicketActivityLog::create([
            'ticket_id' => $ticket->id,
            'action' => 'CREDIT_ADDED',
            'previous_state' => null,
            'new_state' => null,
            'performed_by' => $performer->id,
            'performed_by_name' => Str::limit((string) $performer->name, 150, ''),
            'details' => 'أُضيف إلى رصيد '.$assignee->name.': '.$points.' نقطة و'.rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.').' ساعة | المدة الفعلية: '.self::describeMinutes($minutes).$suffix,
            'logged_at' => $now,
        ]);

        Log::info("Ticket #{$ticket->id} completion credited: {$points} points, {$hours} hours ({$minutes} actual minutes) to user #{$assignee->id}");

        if ($notifyAssignee) {
            $assigneeId = (int) $assignee->id;
            $title = (string) $ticket->title;
            $tid = (int) $ticket->id;
            $performerId = (int) $performer->id;
            DB::afterCommit(function () use ($assigneeId, $tid, $title, $points, $hours, $performerId, $minutes) {
                try {
                    NotificationService::ticketCompletionApproved($assigneeId, $tid, $title, $points, $hours, $performerId, $minutes);
                } catch (\Throwable $e) {
                    // The credit has already committed; telling the assignee is best-effort.
                    report($e);
                }
            });
        }
    }

    /** One activity row by the performer, with a short Arabic detail. */
    public function log(Ticket $ticket, User $by, string $action, ?string $from, ?string $to, string $details): void
    {
        TicketActivityLog::create([
            'ticket_id' => $ticket->id,
            'action' => $action,
            'previous_state' => $from,
            'new_state' => $to,
            'performed_by' => $by->id,
            'performed_by_name' => Str::limit((string) $by->name, 150, ''),
            'details' => $details,
            'logged_at' => now(),
        ]);
    }
    /**
     * The actual duration to credit: it must be ENTERED in this request. A value stored on the ticket (from anywhere)
     * never satisfies it, and neither does the estimate or 0.
     *
     * @return array{0: ?int, 1: ?string, 2: ?string} minutes, error message, error code
     */
    public function resolveActualMinutes(mixed $sent): array
    {
        if ($sent === null || $sent === '') {
            return [null, self::ACTUAL_REQUIRED_MESSAGE, self::CODE_ACTUAL_REQUIRED];
        }

        $isWhole = is_int($sent) || (is_string($sent) && preg_match('/^-?\d+$/', trim($sent)) === 1);
        if (! $isWhole) {
            return [null, self::ACTUAL_INTEGER_MESSAGE, self::CODE_ACTUAL_INVALID];
        }

        $minutes = (int) $sent;
        if ($minutes < 1) {
            return [null, self::ACTUAL_MIN_MESSAGE, self::CODE_ACTUAL_INVALID];
        }
        if ($minutes > self::MAX_ACTUAL_MINUTES) {
            return [null, self::ACTUAL_MAX_MESSAGE, self::CODE_ACTUAL_INVALID];
        }

        return [$minutes, null, null];
    }
    /** «ساعة و15 دقيقة» (for the internal log row): only the non-zero parts, Arabic plural forms. */
    public static function describeMinutes(int $minutes): string
    {
        return \App\Support\ArabicDuration::describe($minutes);
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
