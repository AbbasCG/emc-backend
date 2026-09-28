<?php

namespace App\Services;

use App\Exceptions\FinanceApprovalNotPendingException;
use App\Exceptions\StaleFinanceApprovalException;
use App\Models\Course;
use App\Models\LearningPath;
use App\Models\PlatformNotification;
use App\Services\AuditLogService;
use App\Models\ProgramFinanceApproval;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ProgramFinanceApprovalService
{
    /**
     * THE canonical definition of "financially / commercially material" per
     * program type. A change to any of these is what sends a program (back)
     * to Finance; everything else — title, description, image, content — is
     * an ordinary edit that publishes immediately, matching the pre-existing
     * EMC behaviour where only pricing ever gated publication.
     *
     * Kept in ONE place so Course and LearningPath cannot drift apart, and so
     * the same list drives all three consumers: the "does this need review?"
     * decision, the reviewer-facing diff, and the staleness hash.
     */
    public const FINANCE_FIELDS = [
        // `type`/`is_paid`/`is_free` are all three of the ways a Course can be
        // flipped between free and paid (Course::isPaid() ORs them together),
        // so all three have to be watched or a flip slips through untracked.
        Course::class       => ['price', 'currency', 'type', 'is_paid', 'is_free', 'pricing_options'],
        // discount_price is what LearningPathCheckoutController actually
        // charges when set, so it is every bit as financial as `price`.
        LearningPath::class => ['price', 'discount_price', 'currency'],
    ];

    /**
     * The finance-relevant state of a program, normalized for comparison.
     *
     * For a Learning Path the course membership is part of the fingerprint:
     * the buyer pays the path's own price for THAT specific bundle, so
     * swapping courses in or out changes what is being sold even though the
     * number does not move.
     */
    public function financeFingerprint(Model $program, ?array $overrides = null, ?array $proposedCourseIds = null): array
    {
        $fields      = self::FINANCE_FIELDS[get_class($program)] ?? ['price', 'currency'];
        $fingerprint = [];

        foreach ($fields as $field) {
            $value = $overrides !== null && array_key_exists($field, $overrides)
                ? $overrides[$field]
                : $program->{$field};

            $fingerprint[$field] = $this->normalizeValue($field, $value);
        }

        if ($program instanceof LearningPath) {
            $ids = $proposedCourseIds !== null
                ? $proposedCourseIds
                : $program->courses()->pluck('courses.id')->all();

            $ids = array_values(array_unique(array_map('intval', $ids)));
            sort($ids);
            $fingerprint['course_ids'] = $ids;
        }

        return $fingerprint;
    }

    /** Stable hash of a fingerprint — the identity of "the version being reviewed". */
    public function fingerprintHash(array $fingerprint): string
    {
        ksort($fingerprint);

        return hash('sha256', json_encode($fingerprint, JSON_UNESCAPED_UNICODE));
    }

    /**
     * The finance-relevant changes an update would make, as
     * {field: {from: <current>, to: <proposed>}}. Empty means this edit is
     * financially neutral and must NOT disturb an existing approval.
     *
     * Deliberately callable BEFORE the write, so the publish guard and the
     * reviewer-facing snapshot are both derived from the same comparison.
     */
    public function financeChanges(Model $program, array $data, ?array $proposedCourseIds = null): array
    {
        $before = $this->financeFingerprint($program);
        $after  = $this->financeFingerprint($program, $data, $proposedCourseIds);

        $changes = [];
        foreach ($after as $field => $newValue) {
            $oldValue = $before[$field] ?? null;
            if ($oldValue !== $newValue) {
                $changes[$field] = ['from' => $oldValue, 'to' => $newValue];
            }
        }

        return $changes;
    }

    /**
     * Collapse representations that mean the same commercial thing, so a
     * harmless round-trip (a form re-sending "100.00" for 100, [] for null,
     * "eur" for "EUR") never opens a Finance request on its own.
     */
    private function normalizeValue(string $field, mixed $value): mixed
    {
        if ($field === 'price') {
            // null and 0 both mean "no price" — neither is chargeable.
            return round((float) ($value ?? 0), 2);
        }
        if ($field === 'discount_price') {
            // LearningPathCheckoutController::effectivePrice() ignores a
            // discount <= 0, so null, 0 and a negative value all charge the
            // same full price.
            $discount = round((float) ($value ?? 0), 2);

            return $discount > 0 ? $discount : null;
        }
        if (in_array($field, ['is_paid', 'is_free'], true)) {
            return (bool) $value;
        }
        if ($field === 'currency') {
            // Same default submit() snapshots for a program with no currency.
            return strtoupper((string) ($value ?? 'EUR'));
        }
        if ($field === 'pricing_options') {
            return $this->normalizeStructure($value);
        }

        return $value;
    }

    /**
     * Structured pricing (pricing_options): empty means none; object keys are
     * order-insensitive while list order is kept (it is what the buyer picks
     * by index); numeric strings compare as numbers.
     */
    private function normalizeStructure(mixed $value): mixed
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value   = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }

        if (is_array($value)) {
            if ($value === []) {
                return null;
            }

            $value = array_map(fn ($item) => $this->normalizeStructure($item), $value);
            if (! array_is_list($value)) {
                ksort($value);
            }

            return $value;
        }

        if (is_numeric($value)) {
            return round((float) $value, 2);
        }

        return $value;
    }

    /**
     * Submit a paid program for finance review.
     * Creates or resets the approval record, updates the denormalized status column.
     *
     * @param  string  $requestType  'create' for a first submission, 'update' when an
     *                               already-approved program was edited.
     * @param  array   $changes      financeChanges() output, shown to the reviewer.
     */
    public function submit(
        Model $program,
        User $actor,
        string $requestType = 'create',
        array $changes = [],
    ): ProgramFinanceApproval {
        return DB::transaction(function () use ($program, $actor, $requestType, $changes) {
            $hash = $this->fingerprintHash($this->financeFingerprint($program));

            $existing = ProgramFinanceApproval::where('approvable_type', get_class($program))
                ->where('approvable_id', $program->id)
                ->lockForUpdate()
                ->first();

            // Idempotency: saving the same proposed state again (double-click,
            // a retried request, an edit that touched nothing financial) must
            // not reset the reviewer's queue position or re-notify anyone.
            if ($existing
                && $existing->status === 'pending'
                && $existing->proposed_hash !== null
                && $existing->proposed_hash === $hash
            ) {
                return $existing;
            }

            $approval = ProgramFinanceApproval::updateOrCreate(
                [
                    'approvable_type' => get_class($program),
                    'approvable_id'   => $program->id,
                ],
                [
                    'status'           => 'pending',
                    'request_type'     => $requestType,
                    'submitted_by'     => $actor->id,
                    'submitted_at'     => now(),
                    'reviewed_by'      => null,
                    'reviewed_at'      => null,
                    'review_note'      => null,
                    'rejection_reason' => null,
                    'price_snapshot'   => (float) $program->price,
                    'currency_snapshot' => strtoupper($program->currency ?? 'EUR'),
                    'changes_snapshot' => $changes ?: null,
                    'proposed_hash'    => $hash,
                ],
            );

            // Denormalize onto the program row
            $program->update([
                'finance_approval_status'  => 'pending',
                'submitted_for_finance_at' => now(),
            ]);

            AuditLogService::log(
                'PROGRAM_SUBMITTED_FOR_FINANCE',
                class_basename($program),
                $program->id,
                $program->title,
                [
                    'finance_approval_status' => 'pending',
                    'price'                   => $program->price,
                    'request_type'            => $requestType,
                    'changes'                 => $changes ?: null,
                ],
                null,
                null,
            );

            // Notify all finance managers
            $this->notifyFinanceManagers($program, $actor, $approval);

            return $approval;
        });
    }

    /**
     * Apply a finance-sensitive edit and open its review request as ONE
     * atomic unit.
     *
     * The program row is written with finance_approval_status = 'pending' in
     * the SAME update that carries the new financial values, so there is no
     * moment where the new price is readable next to 'approved' (checkout and
     * the public catalog both key off that column). The review request is
     * opened inside the same transaction: if submit() fails, the edit rolls
     * back with it rather than surviving without anything in Finance's queue.
     *
     * @param  array         $changes    financeChanges() output. Empty = the
     *                                   edit is financially neutral: it is
     *                                   saved as-is and no review is opened.
     * @param  \Closure|null $alsoWrite  further writes that belong to the
     *                                   same edit (e.g. a path's course
     *                                   membership), run after the row update
     *                                   and before the fingerprint is taken.
     */
    public function applyEdit(
        Model $program,
        User $actor,
        array $data,
        array $changes,
        ?\Closure $alsoWrite = null,
    ): void {
        DB::transaction(function () use ($program, $actor, $data, $changes, $alsoWrite) {
            if ($changes !== []) {
                $data['finance_approval_status'] = 'pending';
            }

            $program->update($data);

            if ($alsoWrite) {
                $alsoWrite();
            }

            if ($changes !== []) {
                $this->submit($program->fresh(), $actor, 'update', $changes);
            }
        });
    }

    /**
     * Finance manager approves the program.
     *
     * @throws FinanceApprovalNotPendingException  already approved/rejected
     *                                             (e.g. a concurrent reviewer won)
     * @throws StaleFinanceApprovalException       program changed since submission
     */
    public function approve(Model $program, User $reviewer, ?string $note = null): ProgramFinanceApproval
    {
        try {
            return $this->approveLocked($program, $reviewer, $note);
        } catch (StaleFinanceApprovalException $e) {
            // Logged AFTER the transaction rolled back — inside it, the
            // rollback would erase the very record of the blocked attempt.
            AuditLogService::log(
                'PROGRAM_FINANCE_STALE_APPROVAL_BLOCKED',
                class_basename($program),
                $program->id,
                $program->title,
                ['reviewed_by' => $reviewer->name],
                null,
                null,
            );

            throw $e;
        }
    }

    private function approveLocked(Model $program, User $reviewer, ?string $note): ProgramFinanceApproval
    {
        return DB::transaction(function () use ($program, $reviewer, $note) {
            $approval = ProgramFinanceApproval::where('approvable_type', get_class($program))
                ->where('approvable_id', $program->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPending($approval);
            $this->assertNotStale($program, $approval);

            $approval->update([
                'status'      => 'approved',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            $program->update(['finance_approval_status' => 'approved']);

            AuditLogService::log(
                'PROGRAM_FINANCE_APPROVED',
                class_basename($program),
                $program->id,
                $program->title,
                ['finance_approval_status' => 'approved', 'reviewed_by' => $reviewer->name, 'note' => $note],
                null,
                null,
            );

            // Notify creator and admins
            $this->notifyApproval($program, $reviewer, $approval);

            return $approval;
        });
    }

    /**
     * Finance manager rejects the program.
     *
     * @throws FinanceApprovalNotPendingException  already approved/rejected
     */
    public function reject(Model $program, User $reviewer, string $reason, ?string $note = null): ProgramFinanceApproval
    {
        return DB::transaction(function () use ($program, $reviewer, $reason, $note) {
            $approval = ProgramFinanceApproval::where('approvable_type', get_class($program))
                ->where('approvable_id', $program->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPending($approval);

            $approval->update([
                'status'           => 'rejected',
                'reviewed_by'      => $reviewer->id,
                'reviewed_at'      => now(),
                'review_note'      => $note,
                'rejection_reason' => $reason,
            ]);

            $program->update(['finance_approval_status' => 'rejected']);

            AuditLogService::log(
                'PROGRAM_FINANCE_REJECTED',
                class_basename($program),
                $program->id,
                $program->title,
                ['finance_approval_status' => 'rejected', 'reason' => $reason, 'reviewed_by' => $reviewer->name],
                null,
                null,
            );

            // Notify creator
            $this->notifyRejection($program, $reviewer, $approval);

            return $approval;
        });
    }

    /**
     * Refuse to let a reviewer act on a version that is no longer current.
     *
     * Only guards approve(): approving is the direction that makes money go
     * live, so rubber-stamping an unseen version there is the dangerous case.
     * Rejection is deliberately exempt — it never makes anything effective,
     * it leaves the last approved state authoritative, and blocking it would
     * strand a reviewer with a request they cannot clear.
     */
    /**
     * PENDING allows exactly ONE terminal transition. Checked on the row read
     * under lockForUpdate(), so of two concurrent reviewers the second one
     * always sees the first one's committed decision and is refused — the
     * controller's own pre-check runs before the lock and cannot guarantee
     * this on its own.
     */
    private function assertPending(ProgramFinanceApproval $approval): void
    {
        if ($approval->status !== 'pending') {
            throw new FinanceApprovalNotPendingException('هذا الطلب ليس في حالة انتظار.');
        }
    }

    private function assertNotStale(Model $program, ProgramFinanceApproval $approval): void
    {
        if ($approval->proposed_hash === null) {
            // Legacy row submitted before change-review existed — nothing to
            // compare against. Allowed through rather than permanently stuck.
            return;
        }

        $current = $this->fingerprintHash($this->financeFingerprint($program->fresh()));

        if ($current !== $approval->proposed_hash) {
            throw new StaleFinanceApprovalException(
                'تم تعديل البرنامج بعد إرسال هذا الطلب. يرجى تحديث الصفحة ومراجعة النسخة الحالية.'
            );
        }
    }

    /**
     * When price/currency changes on an already-approved paid program, reset to pending.
     *
     * @deprecated Kept as a thin wrapper so any caller keeps working. It never
     *             had a call site and could not notify Finance (no actor to
     *             attribute the submission to); submit($program, $actor,
     *             'update', $changes) is the real path and is what the
     *             controllers now use.
     */
    public function resetOnPriceChange(Model $program, ?User $actor = null): void
    {
        if (! $program->isPaid()) {
            return;
        }
        if (($program->finance_approval_status ?? 'not_required') !== 'approved') {
            return;
        }

        $actor ??= User::find($program->updated_by ?? $program->created_by);

        if (! $actor) {
            return;
        }

        $this->submit($program, $actor, 'update');
    }

    // ─── Notification helpers ─────────────────────────────────────────────────

    private function notifyFinanceManagers(Model $program, User $actor, ProgramFinanceApproval $approval): void
    {
        $now      = now();
        $type     = class_basename($program);
        $price    = number_format((float) $program->price, 2);
        $currency = strtoupper($program->currency ?? 'EUR');

        // A reviewer needs to know from the notification alone whether this is
        // a brand-new program or a change to one they already approved — the
        // two demand very different scrutiny.
        $isUpdate    = $approval->isUpdateRequest();
        $entityLabel = $program instanceof LearningPath ? 'المسار التعليمي' : 'الدورة';
        $kindLabel   = $isUpdate
            ? ($program instanceof LearningPath ? 'تعديل مسار تعليمي معتمد' : 'تعديل دورة معتمدة')
            : ($program instanceof LearningPath ? 'إنشاء مسار تعليمي جديد' : 'إنشاء دورة جديدة');
        $reviewTitle = $isUpdate
            ? "{$kindLabel} بانتظار المراجعة المالية"
            : 'برنامج مدفوع جديد بانتظار المراجعة المالية';
        $reviewBody = $isUpdate
            ? "تم تعديل {$entityLabel} «{$program->title}» بعد الاعتماد، والتغييرات بانتظار مراجعتك. القيمة الحالية: {$price} {$currency}."
            : "تم إرسال البرنامج «{$program->title}» للمراجعة المالية بقيمة {$price} {$currency}.";

        // Deduplicate by user id so a user who is both admin and super_admin isn't notified twice
        $notifiedIds = collect();
        $rows        = collect();

        // Finance managers — link to their approvals page
        $managers = User::where('is_active', true)
            ->where('role', 'finance_manager')
            ->select('id')
            ->get();

        foreach ($managers as $u) {
            if ($notifiedIds->contains($u->id)) {
                continue;
            }
            $notifiedIds->push($u->id);
            $rows->push([
                'user_id'     => $u->id,
                'type'        => 'finance_approval_request',
                'title'       => $reviewTitle,
                'message'     => $reviewBody,
                'entity_type' => $type,
                'entity_id'   => $program->id,
                'action_url'  => '/dashboard/finance/program-approvals',
                'icon'        => 'dollar-sign',
                'color'       => 'amber',
                'is_read'     => false,
                'created_by'  => $actor->id,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        // Admin and Super Admin — link to program approvals (they can access it)
        $admins = User::where('is_active', true)
            ->whereIn('role', ['admin', 'super_admin'])
            ->select('id', 'role')
            ->get();

        foreach ($admins as $u) {
            if ($notifiedIds->contains($u->id)) {
                continue;
            }
            $notifiedIds->push($u->id);
            $rows->push([
                'user_id'     => $u->id,
                'type'        => 'finance_approval_request',
                'title'       => 'تم إرسال برنامج مدفوع للمراجعة المالية',
                'message'     => "تم إرسال البرنامج «{$program->title}» إلى المدير المالي للمراجعة قبل النشر. القيمة: {$price} {$currency}.",
                'entity_type' => $type,
                'entity_id'   => $program->id,
                'action_url'  => '/dashboard/finance/program-approvals',
                'icon'        => 'dollar-sign',
                'color'       => 'amber',
                'is_read'     => false,
                'created_by'  => $actor->id,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        // Creator confirmation (skip if creator is already notified above as admin/finance_manager)
        $creatorId = $program->created_by ?? null;
        if ($creatorId && ! $notifiedIds->contains($creatorId)) {
            $rows->push([
                'user_id'     => $creatorId,
                'type'        => 'finance_approval_request',
                'title'       => 'تم إرسال البرنامج للمراجعة المالية',
                'message'     => "تم إرسال البرنامج «{$program->title}» للمراجعة المالية، ولا يمكن نشره قبل الاعتماد.",
                'entity_type' => $type,
                'entity_id'   => $program->id,
                'action_url'  => '/dashboard/admin/programs',
                'icon'        => 'clock',
                'color'       => 'blue',
                'is_read'     => false,
                'created_by'  => $actor->id,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        if ($rows->isNotEmpty()) {
            PlatformNotification::insert($rows->toArray());
        }

        AuditLogService::log(
            'PROGRAM_FINANCE_NOTIFICATION_SENT',
            $type,
            $program->id,
            $program->title,
            [
                'notified_count'       => $rows->count(),
                'finance_manager_count' => $managers->count(),
                'admin_count'          => $admins->count(),
                'price'                => $program->price,
                'currency'             => $currency,
            ],
            null,
            null,
        );
    }

    private function notifyApproval(Model $program, User $reviewer, ProgramFinanceApproval $approval): void
    {
        $now          = now();
        $type         = class_basename($program);
        $notifiedIds  = collect();
        $rows         = collect();

        // Notify creator
        $creatorId = $program->created_by ?? null;
        if ($creatorId) {
            $notifiedIds->push($creatorId);
            $rows->push([
                'user_id'     => $creatorId,
                'type'        => 'finance_approved',
                'title'       => 'تم اعتماد البرنامج مالياً',
                'message'     => "تم اعتماد البرنامج «{$program->title}» من قِبَل المدير المالي. يمكنك الآن نشره.",
                'entity_type' => $type,
                'entity_id'   => $program->id,
                'action_url'  => '/dashboard/admin/programs',
                'icon'        => 'check-circle',
                'color'       => 'green',
                'is_read'     => false,
                'created_by'  => $reviewer->id,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        // Notify admins/super_admins (if different from creator)
        $admins = User::where('is_active', true)
            ->whereIn('role', ['admin', 'super_admin'])
            ->select('id')
            ->get();

        foreach ($admins as $u) {
            if ($notifiedIds->contains($u->id)) {
                continue;
            }
            $notifiedIds->push($u->id);
            $rows->push([
                'user_id'     => $u->id,
                'type'        => 'finance_approved',
                'title'       => 'تم اعتماد البرنامج مالياً',
                'message'     => "تم اعتماد البرنامج «{$program->title}» من قِبَل المدير المالي ويمكن الآن نشره.",
                'entity_type' => $type,
                'entity_id'   => $program->id,
                'action_url'  => '/dashboard/admin/programs',
                'icon'        => 'check-circle',
                'color'       => 'green',
                'is_read'     => false,
                'created_by'  => $reviewer->id,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        if ($rows->isNotEmpty()) {
            PlatformNotification::insert($rows->toArray());
        }
    }

    private function notifyRejection(Model $program, User $reviewer, ProgramFinanceApproval $approval): void
    {
        $creatorId = $program->created_by ?? null;
        if (! $creatorId) {
            return;
        }

        $now    = now();
        $reason = $approval->rejection_reason ?? '—';
        PlatformNotification::insert([[
            'user_id'     => $creatorId,
            'type'        => 'finance_rejected',
            'title'       => 'تم رفض البرنامج مالياً',
            'message'     => "رُفض البرنامج «{$program->title}». السبب: {$reason}",
            'entity_type' => class_basename($program),
            'entity_id'   => $program->id,
            'action_url'  => '/dashboard/admin/programs',
            'icon'        => 'x-circle',
            'color'       => 'red',
            'is_read'     => false,
            'created_by'  => $reviewer->id,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]]);
    }
}


