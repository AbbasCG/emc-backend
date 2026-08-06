<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Deliver a webhook event to a single endpoint with HMAC signature.
 *
 * - Retries up to 3 times with exponential back-off.
 * - Records every attempt in webhook_deliveries.
 * - Signs the payload with HMAC-SHA256 for receiver verification.
 *
 * Usage:
 *   DispatchWebhookJob::dispatch($endpoint->id, 'registration.created', $payload);
 */
class DispatchWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300]; // seconds between retries

    public int $timeout = 30;

    public function __construct(
        public readonly int $endpointId,
        public readonly string $event,
        public readonly array $payload,
    ) {}

    public function handle(): void
    {
        $endpoint = WebhookEndpoint::query()->find($this->endpointId);

        if (! $endpoint || ! $endpoint->isActive()) {
            return;
        }

        $delivery = WebhookDelivery::create([
            'endpoint_id'  => $endpoint->id,
            'event'        => $this->event,
            'payload'      => $this->payload,
            'status'       => 'pending',
            'attempted_at' => now(),
        ]);

        $body      = json_encode($this->payload, JSON_UNESCAPED_UNICODE);
        $signature = hash_hmac('sha256', $body, $endpoint->secret);

        try {
            $response = Http::withHeaders([
                'Content-Type'       => 'application/json',
                'X-EMC-Event'        => $this->event,
                'X-EMC-Signature'    => "sha256={$signature}",
                'X-EMC-Delivery'     => (string) $delivery->id,
                'User-Agent'         => 'EMC-Webhooks/1.0',
            ])
            ->timeout($this->timeout)
            ->withBody($body, 'application/json')
            ->post($endpoint->url);

            $delivery->update([
                'status'        => $response->successful() ? 'delivered' : 'failed',
                'response_code' => $response->status(),
                'response_body' => substr($response->body(), 0, 1000),
                'attempted_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('DispatchWebhookJob: delivery failed', [
                'endpoint' => $endpoint->url,
                'error'    => $e->getMessage(),
            ]);

            $delivery->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'attempted_at'  => now(),
            ]);

            throw $e; // triggers retry
        }
    }
}
