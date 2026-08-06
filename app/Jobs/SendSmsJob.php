<?php

namespace App\Jobs;

use App\Services\Sms\SmsNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued SMS delivery. Dispatched (optionally delayed for quiet hours) by
 * SendPlatformNotificationJob when a scenario opts a recipient into the
 * 'sms' channel. Never dispatched synchronously from a controller.
 */
class SendSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly string $to,
        public readonly string $type,
        public readonly array $variables = [],
        public readonly ?int $userId = null,
    ) {}

    public function handle(SmsNotificationService $service): void
    {
        $service->send($this->to, $this->type, $this->variables, $this->userId);
    }
}
