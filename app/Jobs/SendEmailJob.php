<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Message;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Generic queued email sender.
 *
 * For transactional emails that do not need a dedicated Mailable class.
 * Complex, template-driven emails should use a proper Mailable instead.
 *
 * Usage:
 *   SendEmailJob::dispatch('user@example.com', 'Subject', '<p>Body</p>');
 */
class SendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $body,
        public readonly bool $isHtml = true,
    ) {}

    public function handle(): void
    {
        Mail::raw($this->body, function (Message $message) {
            $message->to($this->to)->subject($this->subject);

            if ($this->isHtml) {
                $message->contentType('text/html');
            }
        });
    }
}
