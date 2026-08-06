<?php

namespace App\Jobs;

use App\Services\WhatsApp\WhatsAppNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued WhatsApp notification.
 *
 * Usage:
 *   SendWhatsAppJob::dispatch('+966512345678', 'certificate_issued', ['name' => 'Ahmed'], 42);
 */
class SendWhatsAppJob implements ShouldQueue
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

    public function handle(WhatsAppNotificationService $service): void
    {
        $service->send($this->to, $this->type, $this->variables, $this->userId);
    }
}
