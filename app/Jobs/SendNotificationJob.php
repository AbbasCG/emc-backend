<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * @deprecated Ticket 8 canonical-architecture pass. Writes to Laravel's
 * built-in `notifications` table, a second in-app notification store that
 * is disjoint from the canonical `platform_notifications` table used by the
 * Notification Center, notification preferences, dedup, and every other
 * live notification code path. As of this pass it has **zero live
 * callers** (verified by grepping the full codebase for
 * `SendNotificationJob::dispatch`) — its only two former callers
 * (`GenerateReportJob`, `IssueCertificateJob`) were themselves dead code
 * and have both been migrated to `NotificationService::reportReady()` /
 * `NotificationService::certificateIssued()`. Do not add new callers — use
 * `NotificationService::send()` (or a scenario method) instead. Kept,
 * rather than deleted, only because the built-in `notifications` table and
 * this job's queue-retry semantics may still be referenced by out-of-repo
 * tooling; see docs/architecture/ADR_NOTIFICATION_ARCHITECTURE.md.
 *
 * Usage (do not use for new code):
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
            $subject = $this->data['title'] ?? 'EMC Notification';
            \App\Services\EmailService::dispatch('platform_notification_email', (string) $this->user->email,
                (string) $this->user->name, $this->user->id,
                new \App\Mail\RawContentMail($subject, (string) ($this->data['message'] ?? '')), $subject,
                ['notification_type' => $this->type]);
        }
    }
}
