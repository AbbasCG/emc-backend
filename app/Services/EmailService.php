<?php

namespace App\Services;

use App\Jobs\SendLoggedEmailJob;
use App\Models\EmailSetting;
use App\Models\NotificationLog;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;

class EmailService
{
    /**
     * Dispatch a queued email and record it in notification_logs.
     *
     * Returns early silently if: the key is disabled, email is empty, or any
     * exception occurs during job dispatch (so callers never need try/catch).
     */
    public static function dispatch(
        string   $key,
        string   $toEmail,
        string   $toName,
        ?int     $userId,
        Mailable $mailable,
        string   $subject,
        array    $payload = []
    ): void {
        if (trim($toEmail) === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        if (!EmailSetting::isEnabled($key)) {
            return;
        }

        try {
            $log = NotificationLog::create([
                'channel'    => 'email',
                'type'       => $key,
                'recipient'  => $toEmail,
                'subject'    => $subject,
                'status'     => 'pending',
                'user_id'    => $userId,
                'queued_at'  => now(),
                'payload'    => $payload ?: null,
            ]);

            SendLoggedEmailJob::dispatch($mailable, $toEmail, $toName, $log->id)
                ->onQueue('emails');
        } catch (\Throwable $e) {
            Log::error('EmailService dispatch failed', [
                'key'   => $key,
                'to'    => $toEmail,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
