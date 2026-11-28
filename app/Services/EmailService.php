<?php

namespace App\Services;

use App\Jobs\SendLoggedEmailJob;
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

        // ONE decision for every email (App\Services\EmailDeliveryPolicy): eligible recipient, central pause, personal choice, central
        // default. A skipped message is RECORDED, never silently dropped, so Email Logs can tell a message the settings stopped from
        // one that failed: no queue dispatch, no mail send, just an audit row with the reason.
        $ignorePersonalChoice = (bool) ($context['ignore_personal_choice'] ?? false);
        $decision = EmailDeliveryPolicy::decide($key, EmailDeliveryPolicy::resolveUser($userId, $toEmail), $ignorePersonalChoice);

        if (! $decision['send']) {
            try {
                NotificationLog::create([
                    'channel'              => 'email',
                    'type'                 => $key,
                    'recipient'            => $toEmail,
                    'recipient_name'       => $toName,
                    'subject'              => $subject,
                    'status'               => $decision['log_status'],
                    'user_id'              => $userId,
                    'mail_driver'          => config('mail.default'),
                    'payload'              => $payload + ['skip_reason' => $decision['source']],
                    'triggered_by_user_id' => $context['triggered_by_user_id'] ?? null,
                    'related_course_id'    => $context['related_course_id'] ?? null,
                    'related_session_id'   => $context['related_session_id'] ?? null,
                    'related_ticket_id'    => $context['related_ticket_id'] ?? null,
                    'related_assignment_id' => $context['related_assignment_id'] ?? null,
                ]);
            } catch (\Throwable $e) {
                Log::error('EmailService skipped logging failed', ['key' => $key, 'error' => $e->getMessage()]);
            }

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
                // The marker lets the queued job make the same decision when it runs (an admin's test message to themselves).
                'payload'              => ($ignorePersonalChoice ? $payload + ['_ignore_personal_choice' => true] : $payload) ?: null,
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
