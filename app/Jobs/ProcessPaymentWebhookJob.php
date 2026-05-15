<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Models\Registration;
use App\Services\ActivityLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Process an incoming payment webhook idempotently.
 *
 * Uses WithoutOverlapping to prevent race conditions on the same payment.
 *
 * Usage:
 *   ProcessPaymentWebhookJob::dispatch($paymentId, $provider, $rawPayload);
 */
class ProcessPaymentWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly int $paymentId,
        public readonly string $provider,
        public readonly array $payload,
    ) {}

    /** Prevent concurrent processing of the same payment. */
    public function middleware(): array
    {
        return [new WithoutOverlapping("payment:{$this->paymentId}")];
    }

    public function handle(ActivityLogger $logger): void
    {
        $payment = Payment::query()->find($this->paymentId);

        if (! $payment) {
            Log::warning('ProcessPaymentWebhookJob: payment not found', ['id' => $this->paymentId]);

            return;
        }

        // Idempotency: skip if already in a terminal state
        if (in_array($payment->status, ['Payment Confirmed', 'Refunded'], true)) {
            Log::info('ProcessPaymentWebhookJob: already processed', ['id' => $this->paymentId]);

            return;
        }

        DB::transaction(function () use ($payment, $logger) {
            $oldStatus = $payment->status;
            $newStatus = $this->resolveStatus($this->payload);

            $payment->update(['status' => $newStatus]);

            if ($newStatus === 'Payment Confirmed') {
                $registration = Registration::query()->find($payment->registration_id);
                if ($registration && $registration->status !== 'Registered') {
                    $registration->update(['status' => 'Registered']);
                }
            }

            $logger->log(
                action: 'payment_webhook_processed',
                entityType: Payment::class,
                entityId: $payment->id,
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => $newStatus, 'provider' => $this->provider],
            );
        });
    }

    private function resolveStatus(array $payload): string
    {
        $event = strtolower($payload['event'] ?? $payload['type'] ?? '');

        if (str_contains($event, 'success') || str_contains($event, 'confirmed') || str_contains($event, 'paid')) {
            return 'Payment Confirmed';
        }

        if (str_contains($event, 'fail') || str_contains($event, 'declined')) {
            return 'Payment Failed';
        }

        return 'Payment Pending';
    }
}
