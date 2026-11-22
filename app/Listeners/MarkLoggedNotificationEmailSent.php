<?php

namespace App\Listeners;

use App\Models\NotificationLog;
use App\Notifications\Concerns\TracksEmailLog;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Marks the Email Logs row of a tracked notification (see TracksEmailLog) as sent once Laravel's
 * mail channel has handed the message to the mailer. Auto-discovered.
 */
class MarkLoggedNotificationEmailSent
{
    public function handle(NotificationSent $event): void
    {
        $notification = $event->notification;

        if ($event->channel !== 'mail'
            || ! in_array(TracksEmailLog::class, class_uses_recursive($notification), true)
            || $notification->emailLogId === null) {
            return;
        }

        // The mail is already out: a logging error must not fail the job and trigger a resend.
        try {
            NotificationLog::whereKey($notification->emailLogId)
                ->whereIn('status', ['pending', 'sending'])
                ->update(['status' => 'sent', 'sent_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
