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
     * $context keys (all optional):
     *   triggered_by_user_id  int
     *   related_course_id     int
     *   related_session_id    int
     *   related_ticket_id     int
     *   related_assignment_id int
     */
    public static function dispatch(
        string   $key,
        string   $toEmail,
        string   $toName,
        ?int     $userId,
        Mailable $mailable,
        string   $subject,
        array    $payload = [],
        array    $context = [],
    ): void {
        if (trim($toEmail) === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        if (!EmailSetting::isEnabled($key)) {
            return;
        }

        try {
            $log = NotificationLog::create([
                'channel'              => 'email',
                'type'                 => $key,
                'recipient'            => $toEmail,
                'recipient_name'       => $toName,
                'subject'              => $subject,
                'status'               => 'pending',
                'user_id'              => $userId,
                'queued_at'            => now(),
                'mail_driver'          => config('mail.default'),
                'payload'              => $payload ?: null,
                'triggered_by_user_id' => $context['triggered_by_user_id'] ?? null,
                'related_course_id'    => $context['related_course_id'] ?? null,
                'related_session_id'   => $context['related_session_id'] ?? null,
                'related_ticket_id'    => $context['related_ticket_id'] ?? null,
                'related_assignment_id' => $context['related_assignment_id'] ?? null,
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
