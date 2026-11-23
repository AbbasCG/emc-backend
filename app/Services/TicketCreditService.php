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

    public const ACTUAL_REQUIRED_MESSAGE = 'أدخل المدة الفعلية للإنجاز (بالساعات والدقائق) قبل الاعتماد النهائي.';
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

    /**
     * @param  array<string, mixed>  $estimateInput  any subset of task_scope / task_points / estimated_hours (planning only)
     * @param  mixed  $note  the approver's note as RECEIVED (validated here, after authorization)
     * @param  mixed  $actualMinutes  the actual duration in minutes as RECEIVED (validated here, after authorization); null = not sent
     *
     * @throws TicketActionException
     */
    public function approveCompletion(int $ticketId, User $actor, array $estimateInput = [], mixed $note = null, mixed $actualMinutes = null): Ticket
    {
        try {
            return $this->attempt($ticketId, $actor, $estimateInput, $note, $actualMinutes);
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
    private function attempt(int $ticketId, User $actor, array $estimateInput, mixed $note, mixed $actualMinutes): Ticket
    {
        return DB::transaction(function () use ($ticketId, $actor, $estimateInput, $note, $actualMinutes) {
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

            // HR gate on the larger of the stored size and the size the request asks for (see the class docblock). It is
            // judged BEFORE any validation, from the raw requested size, so an unauthorised caller learns nothing more.
            $requestedScope = isset($estimateInput['task_scope']) && is_string($estimateInput['task_scope']) && in_array($estimateInput['task_scope'], TicketWorkEstimate::SCOPES, true) ? $estimateInput['task_scope'] : null; // an unknown size is a 422 below, not an HR question
            if (($this->access->needsHrApproval($ticket->task_scope) || $this->access->needsHrApproval($requestedScope)) && ! $this->access->isHrApprover($actor)) {
                throw new TicketActionException('اعتماد هذه التذكرة يحتاج إلى اعتماد الموارد البشرية لأن حجمها أعلى من «بسيطة».', 403, [], self::CODE_HR_REQUIRED);
            }

            $assignee = $ticket->assigned_to_id ? User::find($ticket->assigned_to_id) : null;
            if (! $assignee) {
                throw new TicketActionException('لا يمكن الاعتماد: لا يوجد مكلف تُضاف إليه النقاط والساعات.', 409);
            }

            // Validation (422), last, in ONE response: the shape of the estimate fields and the note, then the estimate's
            // business rules (size/points must exist — legacy tickets supply them here — and fit the size), and the ACTUAL
            // duration. Nothing has been written yet.
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
            $note = trim((string) $note);
            $estimateBefore = TicketWorkEstimate::snapshot($ticket);
            $estimateChanged = TicketWorkEstimate::differs($ticket, $work);

            $now = now();
            $points = (int) $work['task_points'];
            // The credited hours are the ACTUAL approved time, never the planning estimate.
            $hours = round($minutes / 60, 2);

            // 1. The unique row FIRST: if the backstop fires, nothing else has been written yet.
            TicketCredit::create([
                'ticket_id' => $ticket->id,
                'user_id' => $assignee->id,
                'points' => $points,
                'hours' => $hours,
                'minutes' => $minutes,
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
                'credited_minutes' => $minutes,
                'actual_minutes' => $minutes,
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
            TicketActivityLog::create([
                'ticket_id' => $ticket->id,
                'action' => 'CREDIT_ADDED',
                'previous_state' => null,
                'new_state' => null,
                'performed_by' => $actor->id,
                'performed_by_name' => Str::limit((string) $actor->name, 150, ''),
                'details' => 'أُضيف إلى رصيد '.$assignee->name.': '.$points.' نقطة و'.rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.').' ساعة | المدة الفعلية: '.self::describeMinutes($minutes).($note !== '' ? ' | ملاحظة الاعتماد: '.$note : ''),
                'logged_at' => $now,
            ]);

            Log::info("Ticket #{$ticket->id} completion approved and credited: {$points} points, {$hours} hours ({$minutes} actual minutes) to user #{$assignee->id}");

            $assigneeId = (int) $assignee->id;
            $title = (string) $ticket->title;
            $tid = (int) $ticket->id;
            $actorId = (int) $actor->id;
            DB::afterCommit(function () use ($assigneeId, $tid, $title, $points, $hours, $actorId, $minutes) {
                try {
                    NotificationService::ticketCompletionApproved($assigneeId, $tid, $title, $points, $hours, $actorId, $minutes);
                } catch (\Throwable $e) {
                    // The credit has already committed; telling the assignee is best-effort.
                    report($e);
                }
            });

            return $ticket;
        });
    }

    /**
     * The actual duration to credit: it must be ENTERED in this request. A value stored on the ticket (from anywhere)
     * never satisfies it, and neither does the estimate or 0.
     *
     * @return array{0: ?int, 1: ?string, 2: ?string} minutes, error message, error code
     */
    private function resolveActualMinutes(mixed $sent): array
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
