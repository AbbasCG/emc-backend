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
 * WHAT IS CREDITED: to the ASSIGNEE (never the reviewer), from the values STORED on the ticket: the POINTS (task_points, scope ranges
 * unchanged) and the HOURS of the ACTUAL duration the assignee recorded when sending the work to review (actual_minutes, credited as
 * round(minutes / 60, 2)). `estimated_hours` is planning data only: never credited, never a fallback. Size, points and the approximate
 * duration are set at assignment; during review all four values are READ-ONLY. The confirmation («تأكيد المراجعة») is a stage
 * transition only: a body carrying any of them is refused (422 REVIEW_VALUES_LOCKED) and missing/invalid stored data is a 422
 * REVIEW_DATA_INCOMPLETE (return for rework to complete it).
 *
 * ORDER OF CHECKS (so an unauthorised caller can never store or probe the request): view/404, review permission 403,
 * participation permission 403 (created / assigned / submitted it), manager 403, already credited 409, status 409, HR gate 403, then
 * 422 (values locked, note, incomplete data). Nothing is persisted by a refused confirmation.
 *
 * DEPLOYMENT TICKETS (requires_deployment) are NOT closed here: confirm-review moves them to APPROVED_FOR_DEPLOY instead
 * (TicketDeploymentService) and approve-completion refuses them (409 DEPLOYMENT_REQUIRED). Their last step, the assignee's live
 * verification, credits through creditNow() below, so there is still ONE implementation of what a credit is and of how it stays single.
 *
 * WHO: a leader of the ticket's department or a global role (TicketAccess::canManage) who holds tickets.review_completion, and
 * also tickets.review_participated when they created the ticket, are its assignee or submitted the current round (TicketAccess::reviewRefusal);
 * work above «بسيطة» also needs an HR approver (TicketAccess::HR_APPROVAL_ROLES), judged on the STORED size.
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
    // The one-click review confirmation: the stored values are read-only during review and must already be complete.
    public const CODE_REVIEW_VALUES_LOCKED = 'REVIEW_VALUES_LOCKED';
    public const CODE_REVIEW_DATA_INCOMPLETE = 'REVIEW_DATA_INCOMPLETE';
    public const CODE_STALE_REVIEW = 'STALE_REVIEW';

    public const STALE_REVIEW_MESSAGE = 'تغيّرت التذكرة منذ فتحتها (أُعيدت أو أُعيد إرسالها)؛ حدّث الصفحة وراجع القيم الجديدة.';

    public const REVIEW_VALUES_LOCKED_MESSAGE = 'لا يمكن تعديل الحجم أو النقاط أو المدة التقريبية أو المدة الفعلية أثناء المراجعة؛ أعد التذكرة للتنفيذ لتعديلها.';
    /** The assignee's submission needs the actual duration (stored on the ticket; read-only afterwards). */
    public const ACTUAL_REQUIRED_FOR_SUBMIT_MESSAGE = 'أدخل المدة الفعلية التي استغرقها العمل (بالساعات والدقائق) قبل الإرسال للمراجعة.';

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
     * Re-read the ticket under the row lock and apply the reviewer gates every review endpoint shares (TicketAccess::requireReviewer):
     * it must be visible (404), the actor must hold «مراجعة واعتماد إنجاز التذاكر» (403 REVIEW_PERMISSION_REQUIRED), must also hold
     * «اعتماد التذاكر التي أشارك فيها» when they created it, are its assignee or submitted the current round (403
     * PARTICIPATION_PERMISSION_REQUIRED), and must lead the ticket's department or be a global role (403). MUST run inside a transaction.
     */
    public function lockForReview(int $ticketId, User $actor, string $managerMessage = 'الاعتماد النهائي لإنجاز التذكرة متاح فقط لقائد الإدارة المعنية.'): Ticket
    {
        $ticket = Ticket::lockForUpdate()->find($ticketId);
        if (! $ticket || ! $this->access->canView($actor, $ticket)) {
            throw new TicketActionException('التذكرة غير موجودة أو لا تملك صلاحية عليها.', 404);
        }
        $this->access->requireReviewer($actor, $ticket, $managerMessage);

        return $ticket;
    }

    /**
     * The structured facts stored on every review row (ticket_activity_logs.metadata): how the reviewer is tied to the ticket
     * (a list of creator / assignee / submitter, empty when they are none of them) and the review round. Internal: only people who may
     * read internal rows ever receive it (TicketAccess::redactInternal).
     *
     * @return array{reviewer_relation: array<int, string>, participated: bool, review_round: int}
     */
    public function reviewMetadata(Ticket $ticket, User $reviewer): array
    {
        $relation = $this->access->reviewerRelation($reviewer, $ticket);

        return ['reviewer_relation' => $relation, 'participated' => $relation !== [], 'review_round' => (int) $ticket->review_round];
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
     * The shared gates of every review confirmation (confirm-review and the two legacy endpoints), on a ticket already locked
     * and judged by lockForReview(): 409 already credited / wrong flow / wrong stage, 422 REVIEW_VALUES_LOCKED when the request
     * tries to send a value, 403 HR gate (judged on the STORED size), 409 no assignee / assignee unavailable (deployment), 422 for
     * a bad note, and 422 REVIEW_DATA_INCOMPLETE when a stored value is missing. Nothing is persisted by a refusal.
     *
     * @param  ?string  $flow  'classic' (approve-completion), 'deploy' (approve-for-deploy) or null (confirm-review: by the ticket's flag)
     * @param  array<string, mixed>  $sentValues  the locked keys the request carried
     * @return string the trimmed note
     */
    public function reviewGate(Ticket $ticket, User $actor, ?string $flow, array $sentValues, mixed $note, mixed $reviewRound = null): string
    {
        $deployment = $this->access->isDeploymentTicket($ticket);
        if ($flow === 'deploy' && ! $deployment) {
            throw new TicketActionException(TicketDeploymentService::NOT_DEPLOYMENT_MESSAGE, 409, [], self::CODE_NOT_A_DEPLOYMENT_TICKET);
        }
        $this->refuseIfCredited($ticket);
        if ($flow === 'classic' && $deployment) {
            // A deployment ticket is closed only by the assignee's verification on the live site.
            throw new TicketActionException('هذه التذكرة تتطلب نشرًا على الموقع الحي؛ استخدم اعتماد للنشر.', 409, [], self::CODE_DEPLOYMENT_REQUIRED);
        }
        if ($ticket->status !== TicketAccess::PENDING_REVIEW) {
            throw $deployment
                ? new TicketActionException(TicketDeploymentService::INVALID_STAGE_MESSAGE, 409, [], self::CODE_INVALID_STAGE)
                : new TicketActionException('لا يمكن اعتماد إنجاز تذكرة ليست بحالة (بانتظار المراجعة والاعتماد).', 409, [], self::CODE_NOT_PENDING_REVIEW);
        }
        // Stale-screen protection: a confirmation carrying the review round the reviewer SAW is refused when the ticket has moved on
        // (returned and resubmitted). Optional for compatibility; judged right after the stage checks, before anything is written.
        if ($reviewRound !== null && $reviewRound !== '') {
            $isWhole = is_int($reviewRound) || (is_string($reviewRound) && preg_match('/^\d+$/', trim($reviewRound)) === 1);
            if (! $isWhole) {
                throw new TicketActionException('رقم جولة المراجعة غير صالح.', 422, ['review_round' => ['رقم جولة المراجعة غير صالح.']]);
            }
            if ((int) $reviewRound !== (int) $ticket->review_round) {
                throw new TicketActionException(self::STALE_REVIEW_MESSAGE, 409, [], self::CODE_STALE_REVIEW);
            }
        }
        if ($this->access->needsHrApproval($ticket->task_scope) && ! $this->access->isHrApprover($actor)) {
            throw new TicketActionException('اعتماد هذه التذكرة يحتاج إلى اعتماد الموارد البشرية لأن حجمها أعلى من «بسيطة».', 403, [], self::CODE_HR_REQUIRED);
        }
        if ($sentValues !== []) {
            throw new TicketActionException(self::REVIEW_VALUES_LOCKED_MESSAGE, 422, array_fill_keys(array_keys($sentValues), [self::REVIEW_VALUES_LOCKED_MESSAGE]), self::CODE_REVIEW_VALUES_LOCKED);
        }
        if ($ticket->assigned_to_id === null || ! User::find($ticket->assigned_to_id)) {
            throw new TicketActionException('لا يمكن الاعتماد: لا يوجد مكلف تُضاف إليه النقاط والساعات.', 409);
        }
        // A deployment ticket has to be verified live by its assignee: nobody who cannot act can be left holding it.
        if ($deployment && $this->access->assigneeUnavailable($ticket)) {
            throw new TicketActionException('لا يمكن اعتماد التذكرة للنشر لأن المكلف غير متاح (غير فعّال أو محجوب أو محذوف)؛ أعدها للتنفيذ ثم أعد إسنادها.', 409, [], self::CODE_ASSIGNEE_UNAVAILABLE);
        }

        $validator = Validator::make(['note' => $note], ['note' => ['nullable', 'string', 'max:1000']], TicketValidationMessages::all() + [
            'note.string' => 'الملاحظة غير صالحة.',
            'note.max' => 'الملاحظة طويلة جدًا؛ الحد الأقصى 1000 حرف.',
        ]);
        if ($validator->fails()) {
            throw new TicketActionException((string) $validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        $missing = TicketWorkEstimate::missingForReview($ticket);
        if ($missing !== []) {
            $message = TicketWorkEstimate::incompleteMessage($missing);
            throw new TicketActionException($message, 422, array_fill_keys($missing, [$message]), self::CODE_REVIEW_DATA_INCOMPLETE);
        }

        return trim((string) $note);
    }

    /**
     * Confirm a completed ticket's review and credit it, now (the classic flow: PENDING_REVIEW -> RESOLVED), from the STORED
     * values only: nothing about size, points, hours or duration is accepted here.
     *
     * @param  mixed  $note  the reviewer's note as RECEIVED (validated here, after authorization)
     * @param  array<string, mixed>  $sentValues  locked keys the request carried (refused with REVIEW_VALUES_LOCKED)
     *
     * @throws TicketActionException
     */
    public function approveCompletion(int $ticketId, User $actor, mixed $note = null, array $sentValues = [], mixed $reviewRound = null): Ticket
    {
        return $this->guardDuplicate($ticketId, fn () => DB::transaction(function () use ($ticketId, $actor, $note, $sentValues, $reviewRound) {
            $ticket = $this->lockForReview($ticketId, $actor);
            $note = $this->reviewGate($ticket, $actor, 'classic', $sentValues, $note, $reviewRound);

            return $this->creditApproved($ticket, $actor, $note);
        }));
    }

    /** The classic final approval of a gated, locked ticket: the one credit from the stored points and actual minutes. MUST run in the caller's transaction. */
    public function creditApproved(Ticket $ticket, User $actor, string $note): Ticket
    {
        $assignee = User::find($ticket->assigned_to_id);

        $this->creditNow(
            $ticket, $assignee, $actor, (int) $actor->id, (int) $ticket->task_points, (int) $ticket->actual_minutes, (string) $ticket->task_scope,
            'اعتماد نهائي للتذكرة '.$ticket->ticket_number,
            function () use ($ticket, $actor, $note) {
                $this->log($ticket, $actor, 'APPROVED_COMPLETION', TicketAccess::PENDING_REVIEW, 'RESOLVED', 'تأكيد المراجعة: تم الاعتماد النهائي لإنجاز التذكرة وإغلاقها', $this->reviewMetadata($ticket, $actor));

                return $note !== '' ? ' | ملاحظة الاعتماد: '.$note : '';
            },
            // The assignee is told, unless they are the one confirming (a reviewer holding the participation permission).
            (int) $actor->id !== (int) $assignee->id,
        );

        return $ticket;
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

    /**
     * One activity row by the performer, with a short Arabic detail.
     *
     * @param  ?array<string, mixed>  $metadata  structured facts (see reviewMetadata); never shown to anyone who may not read internal rows
     */
    public function log(Ticket $ticket, User $by, string $action, ?string $from, ?string $to, string $details, ?array $metadata = null): void
    {
        TicketActivityLog::create([
            'ticket_id' => $ticket->id,
            'action' => $action,
            'previous_state' => $from,
            'new_state' => $to,
            'performed_by' => $by->id,
            'performed_by_name' => Str::limit((string) $by->name, 150, ''),
            'details' => $details,
            'metadata' => $metadata,
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
