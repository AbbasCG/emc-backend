<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatch in-app and optional email notification to a user.
 *
 * Usage:
 *   SendNotificationJob::dispatch($user, 'certificate_issued', [
 *       'title'   => 'Your certificate is ready',
 *       'message' => 'Download your certificate from the dashboard.',
 *       'url'     => '/certificates/123',
 *   ]);
 */
class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60; // seconds between retries

    public function __construct(
        public readonly User $user,
        public readonly string $type,
        public readonly array $data = [],
    ) {}

    public function handle(): void
    {
        // Write to the notifications table (Laravel built-in)
        $this->user->notifications()->create([
            'id'              => \Illuminate\Support\Str::uuid(),
            'type'            => $this->type,
            'notifiable_type' => User::class,
            'notifiable_id'   => $this->user->id,
            'data'            => $this->data,
        ]);

        // Optional: send email notification
        if (! empty($this->data['send_email']) && $this->user->email) {
            SendEmailJob::dispatch(
                to: $this->user->email,
                subject: $this->data['title'] ?? 'EMC Notification',
                body: $this->data['message'] ?? '',
            );
        }
    }
}
