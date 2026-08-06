<?php

namespace App\Jobs;

use App\Services\Push\WebPushNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly int $userId,
        public readonly string $type,
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
    ) {}

    public function handle(WebPushNotificationService $service): void
    {
        $service->sendToUser($this->userId, $this->type, $this->title, $this->body, $this->data);
    }
}
