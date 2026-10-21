<?php

namespace App\Http\Resources;

use App\Models\LearningPathEnrollment;
use App\Models\PaymentReconciliation;
use App\Services\LearnerEnrollmentGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One reconciliation case as finance sees it: who paid, for what, how much, the references, why it needs a decision, what can be done
 * and what was decided. `conflict_still_present` tells the screen whether activating the enrollment would be refused right now.
 *
 * @mixin PaymentReconciliation
 */
class PaymentReconciliationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $payment = $this->payment;
        $order = $payment?->order;
        $open = $this->status === PaymentReconciliation::STATUS_OPEN;

        return [
            'id'            => $this->id,
            'status'        => $this->status,
            'reason'        => $this->reason,
            'created_at'    => $this->created_at?->toIso8601String(),
            'user'          => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name, 'email' => $this->user->email] : null,
            'program'       => $this->programSummary(),
            'payment'       => $payment ? [
                'id'                  => $payment->id,
                'amount'              => (float) $payment->amount,
                'currency'            => strtoupper((string) ($order?->currency ?? 'EUR')),
                'status'              => $payment->status,
                'provider'            => $payment->provider,
                'payment_method'      => $payment->payment_method,
                'provider_reference'  => $payment->provider_payment_id,
                'confirmed_at'        => $payment->confirmed_at?->toIso8601String(),
            ] : null,
            'order'         => $order ? ['id' => $order->id, 'number' => $order->order_number, 'status' => $order->status] : null,
            'invoice'       => $order?->invoice ? ['id' => $order->invoice->id, 'number' => $order->invoice->invoice_number] : null,
            'registration'  => $this->registration ? ['id' => $this->registration->id, 'status' => $this->registration->status] : null,
            // What can be done now. The refund itself is never an action here: the platform does not move money.
            'actions'       => $open ? [PaymentReconciliation::RESOLUTION_ACTIVATED, PaymentReconciliation::RESOLUTION_CLOSED] : [],
            'conflict_still_present' => $open ? $this->conflictStillPresent() : null,
            'resolution'    => $this->resolution,
            'resolution_note' => $this->resolution_note,
            'resolved_at'   => $this->resolved_at?->toIso8601String(),
            'resolved_by'   => $this->resolvedBy ? ['id' => $this->resolvedBy->id, 'name' => $this->resolvedBy->name] : null,
            // null = no refund follow-up; `pending` = money is owed back and must be returned outside the platform.
            'refund_status' => $this->refund_status,
        ];
    }

    /** @return array{type: string, id: int|null, title: string|null, slug: string|null}|null */
    private function programSummary(): ?array
    {
        if ($this->course) {
            return ['type' => $this->course->program_type === 'workshop' ? 'workshop' : 'course', 'id' => $this->course->id, 'title' => $this->course->title, 'slug' => $this->course->slug];
        }

        $path = $this->learning_path_id ? \App\Models\LearningPath::query()->find($this->learning_path_id) : null;

        return $path ? ['type' => 'learning_path', 'id' => $path->id, 'title' => $path->title, 'slug' => $path->slug] : null;
    }

    private function conflictStillPresent(): bool
    {
        $guard = app(LearnerEnrollmentGuard::class);
        $user = $this->user;
        if (! $user) {
            return false;
        }

        if ($this->course) {
            return $guard->teachesCourse($user, $this->course);
        }

        $path = LearningPathEnrollment::query()->find($this->learning_path_enrollment_id)?->learningPath;

        return $path !== null && $guard->teachesLearningPath($user, $path);
    }
}
