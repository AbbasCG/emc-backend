<?php

namespace App\Jobs;

use App\Models\NotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
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
        $startMs = (int) (microtime(true) * 1000);

        $mailerName = $this->resolveMailerName();

        // Mail::to(...)->send($mailable) never consults the Mailable's own
        // `mailer` property (set inside envelope(), e.g. by
        // App\Mail\Concerns\UsesSupportSender) — Illuminate\Mail\Mailable::
        // send() only honors it when handed a MailFactory, and Mail::to()
        // hands it a concrete Mailer instead. The mailable was silently sent
        // through the DEFAULT mailer's SMTP login while still claiming a
        // From: address that belongs to a different account (e.g. the
        // 'support' mailer) — exactly the sender/account mismatch the
        // 'support' mailer config comment warns about, and the proven cause
        // of every contact_confirmation / contact_admin_notification send
        // failing regardless of recipient. Selecting the mailer explicitly
        // here, before ->to()->send(), makes the correct SMTP account
        // actually get used.
        $sent = Mail::mailer($mailerName)->to($this->toEmail, $this->toName)->send($this->mailable);

        $executionMs = (int) (microtime(true) * 1000) - $startMs;

        // Capture the SMTP debug string if available (Symfony Mailer SentMessage)
        $smtpResponse = null;
        if ($sent instanceof \Illuminate\Mail\SentMessage) {
            $smtpResponse = mb_substr(
                $this->sanitizeSmtpDebug($sent->getSymfonySentMessage()->getDebug()),
                0, 500
            );
        }

        NotificationLog::where('id', $this->logId)->update([
            'status'            => 'sent',
            'sent_at'           => now(),
            'execution_time_ms' => max(0, $executionMs),
            'job_id'            => $this->job?->getJobId(),
            'mail_driver'       => $mailerName,
            'smtp_response'     => $smtpResponse,
        ]);
    }

    public function failed(Throwable $e): void
    {
        NotificationLog::where('id', $this->logId)->update([
            'status'        => 'failed',
            'failed_at'     => now(),
            'error_message' => mb_substr($e->getMessage(), 0, 1000),
            'smtp_response' => mb_substr(
                '[' . get_class($e) . '] ' . $e->getMessage(),
                0, 500
            ),
            'retry_count'   => DB::raw('retry_count + 1'),
        ]);
    }

    /**
     * The mailer the Mailable's own envelope() actually asked for (e.g. the
     * dedicated 'support' SMTP account), falling back to the app default.
     * Running envelope() here is safe/idempotent — it only reads config and
     * conditionally calls Mailable::mailer(), the same thing Laravel itself
     * does again internally when the message is built for real.
     */
    private function resolveMailerName(): string
    {
        if (method_exists($this->mailable, 'envelope')) {
            $this->mailable->envelope();
        }

        return $this->mailable->mailer ?? config('mail.default');
    }

    /**
     * Strip the SMTP AUTH exchange out of a raw Symfony Mailer debug
     * transcript before it is ever persisted or truncated.
     *
     * The transcript is a verbatim wire-level log: Symfony's LoginAuthenticator
     * / PlainAuthenticator write the base64-encoded SMTP username and password
     * straight onto the debugged stream (see vendor symfony/mailer Auth/*
     * Authenticator classes) — base64 is trivially reversible, so storing this
     * as-is in notification_logs.smtp_response would leak live SMTP
     * credentials to anyone with Email Logs access. Every client-sent line
     * between an "AUTH ..." command and the server's final 2xx/5xx reply is
     * redacted; nothing else in the transcript is touched. Sanitizing BEFORE
     * truncation matters — truncating first could cut mid-credential and
     * leave an undetected fragment in the stored text.
     */
    private function sanitizeSmtpDebug(?string $debug): ?string
    {
        if ($debug === null || $debug === '') {
            return $debug;
        }

        $inAuthExchange = false;
        $lines = [];

        foreach (explode("\n", $debug) as $line) {
            $isClientLine = (bool) preg_match('/]\s*>/', $line);
            $isServerLine = (bool) preg_match('/]\s*</', $line);

            if ($isClientLine && preg_match('/\bAUTH\s+\S+/i', $line)) {
                $inAuthExchange = true;
                $lines[] = preg_replace('/(\bAUTH\s+\S+).*/i', '$1 [REDACTED]', $line);
                continue;
            }

            if ($inAuthExchange && $isClientLine) {
                // RFC 4954: every client line during the exchange is a
                // base64 credential response to the server's challenge.
                $lines[] = preg_replace('/>\s*.*/', '> [REDACTED]', $line);
                continue;
            }

            if ($inAuthExchange && $isServerLine) {
                $lines[] = $line;
                if (preg_match('/<\s*(235|5\d\d)\b/', $line)) {
                    $inAuthExchange = false;
                }
                continue;
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }
}
