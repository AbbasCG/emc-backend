<?php

namespace App\Jobs;

use App\Models\NotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendLoggedEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> retry back-off in seconds */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly Mailable $mailable,
        public readonly string   $toEmail,
        public readonly string   $toName,
        public readonly int      $logId,
    ) {}

    public function handle(): void
    {
        Mail::to($this->toEmail, $this->toName)->send($this->mailable);

        NotificationLog::where('id', $this->logId)->update([
            'status'  => 'sent',
            'sent_at' => now(),
        ]);
    }

    public function failed(Throwable $e): void
    {
        NotificationLog::where('id', $this->logId)->update([
            'status'        => 'failed',
            'failed_at'     => now(),
            'error_message' => mb_substr($e->getMessage(), 0, 500),
        ]);
    }
}
