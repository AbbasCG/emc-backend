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
            // Recorded (not silently dropped) so Email Logs can show this
            // was intentionally skipped rather than pretending it was sent
            // — no queue dispatch, no mail send, just an audit trail row.
            try {
                NotificationLog::create([
                    'channel'              => 'email',
                    'type'                 => $key,
                    'recipient'            => $toEmail,
                    'recipient_name'       => $toName,
                    'subject'              => $subject,
                    'status'               => 'skipped_disabled',
                    'user_id'              => $userId,
                    'mail_driver'          => config('mail.default'),
                    'payload'              => $payload ?: null,
                    'triggered_by_user_id' => $context['triggered_by_user_id'] ?? null,
                    'related_course_id'    => $context['related_course_id'] ?? null,
                    'related_session_id'   => $context['related_session_id'] ?? null,
                    'related_ticket_id'    => $context['related_ticket_id'] ?? null,
                    'related_assignment_id' => $context['related_assignment_id'] ?? null,
                ]);
            } catch (\Throwable $e) {
                Log::error('EmailService skipped-disabled logging failed', ['key' => $key, 'error' => $e->getMessage()]);
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

    /**
     * One notification_logs row for an email the platform sends or tries to send outside
     * dispatch() (password reset, synchronous mails). Never throws: logging must not break a send.
     *
     * Status vocabulary: pending → sent | failed, sending → sent | failed (synchronous), and
     * skipped_disabled / skipped_user_preference for emails deliberately not sent.
     */
    public static function record(
        string $key,
        string $toEmail,
        ?string $toName,
        ?int $userId,
        string $subject,
        string $status,
        array $payload = [],
        array $context = [],
        ?string $error = null,
    ): ?NotificationLog {
        try {
            return NotificationLog::create([
                'channel'              => 'email',
                'type'                 => $key,
                'recipient'            => $toEmail,
                'recipient_name'       => $toName,
                'subject'              => $subject,
                'status'               => $status,
                'user_id'              => $userId,
                'queued_at'            => in_array($status, ['pending', 'sending'], true) ? now() : null,
                'failed_at'            => $status === 'failed' ? now() : null,
                'error_message'        => $error !== null ? mb_substr($error, 0, 1000) : null,
                'mail_driver'          => config('mail.default'),
                'payload'              => $payload ?: null,
                'triggered_by_user_id' => $context['triggered_by_user_id'] ?? null,
                'related_course_id'    => $context['related_course_id'] ?? null,
                'related_session_id'   => $context['related_session_id'] ?? null,
                'related_ticket_id'    => $context['related_ticket_id'] ?? null,
                'related_assignment_id' => $context['related_assignment_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error('EmailService record failed', ['key' => $key, 'status' => $status, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Send right now (no queue) and log the outcome, for callers that must know at once whether
     * the mail went out. Not gated by EmailSetting: the caller decides whether this mail is
     * optional. Returns true when the mailer accepted the message.
     */
    public static function sendNow(
        string $key,
        string $toEmail,
        string $toName,
        ?int $userId,
        Mailable $mailable,
        array $payload = [],
        array $context = [],
    ): bool {
        $subject = self::subjectOf($mailable);
        $log = self::record($key, $toEmail, $toName, $userId, $subject, 'sending', $payload, $context);
        $started = (int) (microtime(true) * 1000);

        try {
            if (method_exists($mailable, 'envelope')) {
                $mailable->envelope();
            }
            // Same call the callers used before (Mail::to on the default mailer) unless the
            // Mailable asks for a specific one.
            $mailer = $mailable->mailer ?? config('mail.default');
            $pending = $mailable->mailer
                ? \Illuminate\Support\Facades\Mail::mailer($mailable->mailer)->to($toEmail, $toName)
                : \Illuminate\Support\Facades\Mail::to($toEmail, $toName);
            $pending->send($mailable);

            $log?->update([
                'status' => 'sent', 'sent_at' => now(), 'mail_driver' => $mailer,
                'execution_time_ms' => max(0, (int) (microtime(true) * 1000) - $started),
            ]);

            return true;
        } catch (\Throwable $e) {
            // Only the exception text: the mailable may carry secrets (e.g. a temporary password).
            Log::error('EmailService sendNow failed', ['key' => $key, 'error' => $e->getMessage()]);
            $log?->update([
                'status' => 'failed', 'failed_at' => now(),
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
                'smtp_response' => mb_substr('['.get_class($e).'] '.$e->getMessage(), 0, 500),
            ]);

            return false;
        }
    }

    /** The subject a Mailable will go out with (its envelope), without rendering the body. */
    public static function subjectOf(Mailable $mailable): string
    {
        try {
            if (method_exists($mailable, 'envelope')) {
                $subject = $mailable->envelope()->subject;
                if (is_string($subject) && $subject !== '') {
                    return $subject;
                }
            }
        } catch (\Throwable) {
            // fall through
        }

        return (string) ($mailable->subject ?? class_basename($mailable));
    }
}
