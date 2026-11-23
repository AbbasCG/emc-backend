<?php

namespace App\Services;

use App\Exceptions\TicketActionException;
use App\Models\Ticket;
use App\Models\User;
use App\Rules\LiveUrl;
use App\Support\TicketValidationMessages;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The review cycle of tickets that REQUIRE A LIVE DEPLOYMENT (tickets.requires_deployment). Every other ticket keeps the
 * classic flow (PENDING_REVIEW -> approve-completion -> RESOLVED + credit, TicketCreditService).
 *
 *   IN_PROGRESS --complete-task--> PENDING_REVIEW --approve-for-deploy--> APPROVED_FOR_DEPLOY
 *       --mark-deployed--> PENDING_VERIFICATION --verify-resolved--> RESOLVED (the ONE credit)
 *
 * Rework: PENDING_REVIEW / APPROVED_FOR_DEPLOY --return-for-rework--> IN_PROGRESS (TicketActions) and
 * PENDING_VERIFICATION --verify-failed--> IN_PROGRESS. Any rework CLEARS the approved values (actual_minutes, the approval
 * and deployment fields; the previous round stays only in the activity log), so the next round needs a new review, a new
 * approval and a new deployment before anything can close.
 *
 * Every transition re-reads the ticket under the row lock and judges the stage THERE (409 INVALID_STAGE), so two requests
 * can never both advance it. Nothing is credited before verify-resolved; that step credits through
 * TicketCreditService::creditNow() with the APPROVED points and minutes, so the single-credit guarantees (unique credit
 * row, source-idempotent ledger, one transaction) are the same ones the classic flow has. A deployment ticket reaches
 * RESOLVED through no other path.
 */
class TicketDeploymentService
{
    public const INVALID_STAGE_MESSAGE = 'هذه الخطوة غير متاحة في المرحلة الحالية للتذكرة.';

    public const NOT_DEPLOYMENT_MESSAGE = 'هذه التذكرة لا تتطلب نشرًا على الموقع الحي.';

    /** The approval and deployment fields every rework wipes (the approved values are valid for ONE round only). */
    public const CLEARED_BY_REWORK = [
        'actual_minutes' => null,
        'review_approved_by_id' => null,
        'review_approved_at' => null,
        'deployed_by_id' => null,
        'deployed_at' => null,
        'live_url' => null,
        'verified_by_id' => null,
        'verified_at' => null,
    ];

    public function __construct(
        private readonly TicketAccess $access,
        private readonly TicketCreditService $credits,
    ) {}

    // ── 1. approve for deployment (reviewer) ─────────────────────────────────

    /**
     * Same gates and order as approve-completion: 404 view, 403 SELF_REVIEW (assignee / submitter), 403 manager, 409
     * (not a deployment ticket / already credited / wrong stage), 403 HR gate, then ONE 422 for the estimate fields, the
     * note and the REQUIRED actual duration. Stores the approved values and credits NOTHING.
     *
     * @param  array<string, mixed>  $estimateInput
     */
    public function approveForDeploy(int $ticketId, User $actor, array $estimateInput = [], mixed $note = null, mixed $actualMinutes = null): Ticket
    {
        return DB::transaction(function () use ($ticketId, $actor, $estimateInput, $note, $actualMinutes) {
            $ticket = $this->credits->lockForReview(
                $ticketId, $actor,
                'لا يمكنك اعتماد عمل أنت المكلف به؛ يلزم مراجع آخر.',
                'اعتماد التذكرة للنشر متاح فقط لقائد الإدارة المعنية.',
            );
            $this->requireDeploymentTicket($ticket);
            $this->credits->refuseIfCredited($ticket);
            $this->requireStatus($ticket, TicketAccess::PENDING_REVIEW);
            $this->credits->guardHr($ticket, $actor, $estimateInput);

            // The change will have to be verified on the live site by the assignee: nobody who cannot act can be left holding it.
            if ($this->access->assigneeUnavailable($ticket)) {
                throw new TicketActionException('لا يمكن اعتماد التذكرة للنشر لأن المكلف غير متاح (غير فعّال أو محجوب أو محذوف)؛ أعدها للتنفيذ ثم أعد إسنادها.', 409, [], TicketCreditService::CODE_ASSIGNEE_UNAVAILABLE);
            }

            [$work, $minutes, $note] = $this->credits->validateApproval($ticket, $estimateInput, $note, $actualMinutes, TicketCreditService::ACTUAL_REQUIRED_FOR_DEPLOY_MESSAGE);

            $before = TicketWorkEstimate::snapshot($ticket);
            $changed = TicketWorkEstimate::differs($ticket, $work);
            $now = now();

            $ticket->fill($work);
            $ticket->forceFill([
                'status' => TicketAccess::APPROVED_FOR_DEPLOY,
                'actual_minutes' => $minutes,
                'review_approved_by_id' => $actor->id,
                'review_approved_at' => $now,
            ])->save();

            if ($changed) {
                TicketWorkEstimate::log($ticket, $actor, $before, TicketWorkEstimate::snapshot($ticket));
            }
            $this->credits->log(
                $ticket, $actor, 'APPROVED_FOR_DEPLOY', TicketAccess::PENDING_REVIEW, TicketAccess::APPROVED_FOR_DEPLOY,
                'اعتُمد العمل للنشر على الموقع الحي: '.(int) $work['task_points'].' نقطة، المدة الفعلية '.TicketCreditService::describeMinutes($minutes)
                    .($note !== '' ? ' | ملاحظة الاعتماد: '.$note : '').' (لم يُضَف أي رصيد بعد).',
            );

            return $ticket;
        });
    }

    // ── 2. mark deployed (department leader / global role) ───────────────────

    /**
     * The change is on the live site. Judged by a manager of the department who is not the ticket's assignee; the deploy
     * time is the SERVER's (never a client value).
     */
    public function markDeployed(int $ticketId, User $actor, mixed $liveUrl, mixed $note = null): Ticket
    {
        return DB::transaction(function () use ($ticketId, $actor, $liveUrl, $note) {
            $ticket = Ticket::lockForUpdate()->find($ticketId);
            if (! $ticket || ! $this->access->canView($actor, $ticket)) {
                throw new TicketActionException('التذكرة غير موجودة أو لا تملك صلاحية عليها.', 404);
            }
            if ($this->access->isTicketAssignee($actor, $ticket)) {
                throw new TicketActionException('لا يمكنك تسجيل نشر عمل أنت المكلف به؛ يلزم شخص آخر.', 403, [], TicketCreditService::CODE_SELF_REVIEW);
            }
            if (! $this->access->canManage($actor, (int) $ticket->department_id)) {
                throw new TicketActionException('تسجيل النشر متاح فقط لقائد الإدارة المعنية.', 403);
            }
            $this->requireDeploymentTicket($ticket);
            $this->credits->refuseIfCredited($ticket);
            $this->requireStatus($ticket, TicketAccess::APPROVED_FOR_DEPLOY);

            $validator = Validator::make(
                ['live_url' => is_string($liveUrl) ? trim($liveUrl) : $liveUrl, 'note' => $note],
                ['live_url' => ['bail', 'required', 'string', 'max:500', new LiveUrl], 'note' => ['nullable', 'string', 'max:1000']],
                TicketValidationMessages::all() + [
                    'live_url.required' => 'أدخل رابط الصفحة على الموقع الحي ليختبرها المكلّف.',
                    'live_url.string' => 'رابط الموقع الحي غير صالح.',
                    'live_url.max' => 'رابط الموقع الحي طويل جدًا؛ الحد الأقصى 500 حرف.',
                    'note.string' => 'الملاحظة غير صالحة.',
                    'note.max' => 'الملاحظة طويلة جدًا؛ الحد الأقصى 1000 حرف.',
                ],
            );
            if ($validator->fails()) {
                throw new TicketActionException((string) $validator->errors()->first(), 422, $validator->errors()->toArray());
            }

            $url = trim((string) $liveUrl);
            $note = trim((string) $note);
            $now = now();

            $ticket->forceFill([
                'status' => TicketAccess::PENDING_VERIFICATION,
                'deployed_by_id' => $actor->id,
                'deployed_at' => $now,
                'live_url' => $url,
            ])->save();

            $this->credits->log($ticket, $actor, 'DEPLOYED_LIVE', TicketAccess::APPROVED_FOR_DEPLOY, TicketAccess::PENDING_VERIFICATION, 'نُشر التعديل على الموقع الحي وهو بانتظار تحقق المكلّف.');
            $this->credits->log($ticket, $actor, 'DEPLOY_DETAILS', null, null, 'رابط الاختبار: '.$url.($note !== '' ? ' | ملاحظة النشر: '.$note : ''));

            $assigneeId = (int) $ticket->assigned_to_id;
            $tid = (int) $ticket->id;
            $title = (string) $ticket->title;
            $round = (int) $ticket->review_round;
            $actorId = (int) $actor->id;
            DB::afterCommit(function () use ($assigneeId, $tid, $title, $round, $actorId) {
                try {
                    NotificationService::ticketLiveVerificationRequired($assigneeId, $tid, $title, $round, $actorId);
                } catch (\Throwable $e) {
                    // The deployment is recorded; telling the assignee is best-effort.
                    report($e);
                }
            });

            return $ticket;
        });
    }

    // ── 3. verify on the live site: the ONE credit (assignee only) ───────────

    /**
     * The assignee confirms the fix on the live site. The status change and the one-time credit happen in ONE transaction
     * under the row lock: any failure rolls back both. A replay or a parallel request gets 409 ALREADY_CREDITED.
     */
    public function verifyResolved(int $ticketId, User $actor, mixed $note): Ticket
    {
        return $this->credits->guardDuplicate($ticketId, fn () => DB::transaction(function () use ($ticketId, $actor, $note) {
            $ticket = $this->lockForAssignee($ticketId, $actor);
            $this->requireDeploymentTicket($ticket);
            $this->credits->refuseIfCredited($ticket);
            $this->requireStatus($ticket, TicketAccess::PENDING_VERIFICATION);

            $note = $this->validateText($note, 'note', 'اكتب ملاحظة التحقق (5 أحرف على الأقل).', 'ملاحظة التحقق قصيرة؛ اكتب 5 أحرف على الأقل.', 'ملاحظة التحقق طويلة جدًا؛ الحد الأقصى 1000 حرف.');

            // The values approved for deployment are what is credited; without them there is nothing to credit.
            if ($ticket->task_points === null || $ticket->task_scope === null || $ticket->actual_minutes === null) {
                throw new TicketActionException('القيم المعتمدة للتذكرة غير مكتملة؛ يلزم اعتمادها للنشر من جديد.', 409, [], TicketCreditService::CODE_INVALID_STAGE);
            }

            $now = now();
            $ticket->forceFill(['verified_by_id' => $actor->id, 'verified_at' => $now]);

            $this->credits->creditNow(
                $ticket, $actor, $actor,
                $ticket->review_approved_by_id !== null ? (int) $ticket->review_approved_by_id : null,
                (int) $ticket->task_points, (int) $ticket->actual_minutes, (string) $ticket->task_scope,
                'اعتماد نهائي بعد التحقق على الموقع الحي للتذكرة '.$ticket->ticket_number,
                function () use ($ticket, $actor, $note) {
                    $this->credits->log($ticket, $actor, 'VERIFIED_RESOLVED', TicketAccess::PENDING_VERIFICATION, 'RESOLVED', 'تحقق المكلّف من الحل على الموقع الحي: '.$note);

                    return '';
                },
                false, // the assignee is the one acting: no notification to themselves (the reporter hears it from the observer)
            );

            return $ticket;
        }));
    }

    // ── 4. verification failed (assignee only) ───────────────────────────────

    public function verifyFailed(int $ticketId, User $actor, mixed $description): Ticket
    {
        return DB::transaction(function () use ($ticketId, $actor, $description) {
            $ticket = $this->lockForAssignee($ticketId, $actor);
            $this->requireDeploymentTicket($ticket);
            $this->credits->refuseIfCredited($ticket);
            $this->requireStatus($ticket, TicketAccess::PENDING_VERIFICATION);

            $description = $this->validateText($description, 'description', 'اكتب وصف المشكلة التي ظهرت على الموقع الحي.', 'وصف المشكلة قصير؛ اكتب 5 أحرف على الأقل.', 'وصف المشكلة طويل جدًا؛ الحد الأقصى 1000 حرف.');

            // Back to the assignee; the approved and deployment values are void (the old round stays in the log only).
            $ticket->forceFill(['status' => 'IN_PROGRESS'] + self::CLEARED_BY_REWORK)->save();

            $this->credits->log($ticket, $actor, 'VERIFICATION_FAILED', TicketAccess::PENDING_VERIFICATION, 'IN_PROGRESS', 'لم ينجح التحقق على الموقع الحي: '.$description);

            return $ticket;
        });
    }

    // ── shared checks ────────────────────────────────────────────────────────

    /** Lock the ticket and require the ACTUAL assignee (404 when it cannot be seen). A global role does not stand in for them. */
    private function lockForAssignee(int $ticketId, User $actor): Ticket
    {
        $ticket = Ticket::lockForUpdate()->find($ticketId);
        if (! $ticket || ! $this->access->canView($actor, $ticket)) {
            throw new TicketActionException('التذكرة غير موجودة أو لا تملك صلاحية عليها.', 404);
        }
        if (! $this->access->isTicketAssignee($actor, $ticket)) {
            throw new TicketActionException('التحقق على الموقع الحي متاح للمكلّف بالتذكرة فقط.', 403, [], TicketCreditService::CODE_ASSIGNEE_ONLY);
        }

        return $ticket;
    }

    private function requireDeploymentTicket(Ticket $ticket): void
    {
        if (! $this->access->isDeploymentTicket($ticket)) {
            throw new TicketActionException(self::NOT_DEPLOYMENT_MESSAGE, 409, [], TicketCreditService::CODE_NOT_A_DEPLOYMENT_TICKET);
        }
    }

    private function requireStatus(Ticket $ticket, string $status): void
    {
        if ($ticket->status !== $status) {
            throw new TicketActionException(self::INVALID_STAGE_MESSAGE, 409, [], TicketCreditService::CODE_INVALID_STAGE);
        }
    }

    /** A required 5..1000 character text, trimmed. */
    private function validateText(mixed $value, string $field, string $required, string $min, string $max): string
    {
        $validator = Validator::make([$field => is_string($value) ? trim($value) : $value], [$field => ['required', 'string', 'min:5', 'max:1000']], [
            "{$field}.required" => $required,
            "{$field}.string" => $required,
            "{$field}.min" => $min,
            "{$field}.max" => $max,
        ]);
        if ($validator->fails()) {
            throw new TicketActionException((string) $validator->errors()->first(), 422, $validator->errors()->toArray());
        }

        return trim((string) $value);
    }
}
