<?php

namespace App\Notifications\Concerns;

use App\Models\NotificationLog;
use Throwable;

/**
 * For a Laravel Notification that emails through the mail channel, so it shows in Email Logs like
 * every EmailService email: the sender creates a pending notification_logs row and passes its id
 * here; MarkLoggedNotificationEmailSent marks it sent, and failed() marks it failed once the queued
 * notification has used up its retries.
 */
trait TracksEmailLog
{
    public ?int $emailLogId = null;

    public function withEmailLog(?int $logId): static
    {
        $this->emailLogId = $logId;

        return $this;
    }

    /** Called by Laravel when the queued notification finally fails. */
    public function failed(Throwable $e): void
    {
        if ($this->emailLogId === null) {
            return;
        }

        NotificationLog::whereKey($this->emailLogId)->update([
            'status' => 'failed',
            'failed_at' => now(),
            'error_message' => mb_substr($e->getMessage(), 0, 1000),
            'smtp_response' => mb_substr('['.get_class($e).'] '.$e->getMessage(), 0, 500),
        ]);
    }
}
