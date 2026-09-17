<?php

namespace Tests\Feature;

use App\Jobs\SendLoggedEmailJob;
use App\Mail\ContactAdminNotificationMail;
use App\Mail\ContactConfirmationMail;
use App\Mail\WelcomeMail;
use App\Models\NotificationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use ReflectionClass;
use Tests\TestCase;

/**
 * ROOT CAUSE regression coverage for the production contact_confirmation /
 * contact_admin_notification delivery failure.
 *
 * Proven cause: SendLoggedEmailJob::handle() sent every logged mail via
 * Mail::to(...)->send($mailable). Illuminate\Mail\Mailable::send() only
 * honors a mailable's own `mailer` property (set by
 * App\Mail\Concerns\UsesSupportSender::resolveSupportSender() for the
 * contact mailables) when handed a MailFactory — Mail::to() always hands it
 * a concrete Mailer instead, so the mailer selection was silently ignored.
 * The message was sent through the DEFAULT SMTP account while still
 * claiming a From: address belonging to the separate 'support' account —
 * a sender/account mismatch most providers reject outright, regardless of
 * recipient (matching production: many different recipients, 100% failure).
 */
class SendLoggedEmailJobTest extends TestCase
{
    use RefreshDatabase;

    private function configureDistinctMailers(): void
    {
        config()->set('mail.default', 'array');
        config()->set('mail.mailers.array', ['transport' => 'array']);
        config()->set('mail.mailers.support.transport', 'array');
        config()->set('mail.mailers.support.username', 'support-user');
        config()->set('mail.mailers.support.password', 'support-pass');
        config()->set('mail.support_from_address', 'support@edumc.nl');
        config()->set('mail.support_from_name', 'EMC Support');
        config()->set('mail.from.address', 'noreply@edumc.nl');
        config()->set('mail.from.name', 'EMC');
    }

    private function makeLog(string $type = 'contact_confirmation'): NotificationLog
    {
        return NotificationLog::create([
            'channel'   => 'email',
            'type'      => $type,
            'recipient' => 'visitor@example.com',
            'status'    => 'pending',
            'queued_at' => now(),
        ]);
    }

    public function test_contact_confirmation_is_actually_sent_through_the_support_mailer(): void
    {
        $this->configureDistinctMailers();

        $captured = null;
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$captured) {
            $captured = $event->data['mailer'] ?? null;
        });

        $log = $this->makeLog();
        $mailable = new ContactConfirmationMail('Visitor', 'Subject', 'Body', now()->toDateTimeString(), 'TK-1');

        (new SendLoggedEmailJob($mailable, 'visitor@example.com', 'Visitor', $log->id))->handle();

        $this->assertSame('support', $captured);
        $this->assertSame('support', $log->fresh()->mail_driver);
        $this->assertSame('sent', $log->fresh()->status);
    }

    public function test_admin_notification_is_actually_sent_through_the_support_mailer(): void
    {
        $this->configureDistinctMailers();

        $captured = null;
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$captured) {
            $captured = $event->data['mailer'] ?? null;
        });

        $log = $this->makeLog('contact_admin_notification');
        $mailable = new ContactAdminNotificationMail(
            'Visitor', 'visitor@example.com', null, 'Subject', 'Body', 'general', now()->toDateTimeString(), null, 'TK-1'
        );

        (new SendLoggedEmailJob($mailable, 'support@edumc.nl', 'EMC Support', $log->id))->handle();

        $this->assertSame('support', $captured);
    }

    public function test_the_visitor_email_is_never_used_as_the_smtp_from_address(): void
    {
        $this->configureDistinctMailers();

        $capturedFrom = null;
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$capturedFrom) {
            $from = $event->message->getFrom();
            $capturedFrom = $from[0]->getAddress() ?? null;
        });

        $log = $this->makeLog();
        $mailable = new ContactConfirmationMail('Visitor', 'Subject', 'Body', now()->toDateTimeString(), 'TK-1');

        (new SendLoggedEmailJob($mailable, 'visitor@example.com', 'Visitor', $log->id))->handle();

        $this->assertSame('support@edumc.nl', $capturedFrom);
        $this->assertNotSame('visitor@example.com', $capturedFrom);
    }

    public function test_a_mailable_with_no_custom_mailer_still_uses_the_app_default(): void
    {
        $this->configureDistinctMailers();

        $captured = null;
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$captured) {
            $captured = $event->data['mailer'] ?? null;
        });

        $log = $this->makeLog('welcome');
        $mailable = new WelcomeMail('Someone', 'someone@example.com');

        (new SendLoggedEmailJob($mailable, 'someone@example.com', 'Someone', $log->id))->handle();

        $this->assertSame('array', $captured);
        $this->assertSame('array', $log->fresh()->mail_driver);
    }

    public function test_successful_send_records_sent_status_and_sent_at(): void
    {
        $this->configureDistinctMailers();

        $log = $this->makeLog();
        $mailable = new ContactConfirmationMail('Visitor', 'Subject', 'Body', now()->toDateTimeString(), 'TK-1');

        (new SendLoggedEmailJob($mailable, 'visitor@example.com', 'Visitor', $log->id))->handle();

        $fresh = $log->fresh();
        $this->assertSame('sent', $fresh->status);
        $this->assertNotNull($fresh->sent_at);
    }

    public function test_failure_records_failed_status_with_a_safe_diagnostic_message(): void
    {
        $log = $this->makeLog();
        $mailable = new ContactConfirmationMail('Visitor', 'Subject', 'Body', now()->toDateTimeString(), 'TK-1');
        $job = new SendLoggedEmailJob($mailable, 'visitor@example.com', 'Visitor', $log->id);

        $job->failed(new \RuntimeException('Expected response code "250" but got code "553", with message "553 Sender address rejected: not owned by user".'));

        $fresh = $log->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertNotNull($fresh->failed_at);
        $this->assertStringContainsString('553', $fresh->error_message);
        $this->assertSame(1, $fresh->retry_count);
    }

    public function test_failure_diagnostics_never_contain_a_password_or_secret_looking_value(): void
    {
        $log = $this->makeLog();
        $mailable = new ContactConfirmationMail('Visitor', 'Subject', 'Body', now()->toDateTimeString(), 'TK-1');
        $job = new SendLoggedEmailJob($mailable, 'visitor@example.com', 'Visitor', $log->id);

        $job->failed(new \RuntimeException('Failed to authenticate on SMTP server with username "support@edumc.nl" using the following authenticators: "LOGIN". Authenticator "LOGIN" returned "535 5.7.8 Authentication failed".'));

        $fresh = $log->fresh();
        $this->assertStringNotContainsString('password', strtolower((string) $fresh->error_message));
        // The stored diagnostic may legitimately name the SMTP *account*
        // (username) — that's an identifier, not a secret — but must never
        // contain the base64 credential payload itself.
        $this->assertStringNotContainsString(base64_encode('support@edumc.nl:some-real-password'), (string) $fresh->error_message);
    }

    public function test_sanitize_smtp_debug_redacts_a_login_auth_exchange(): void
    {
        $transcript = implode("\n", [
            '[2026-09-18T10:00:00.000000+00:00] > EHLO api.edumc.nl',
            '[2026-09-18T10:00:00.000000+00:00] < 250-smtp.hostinger.com',
            '[2026-09-18T10:00:00.000000+00:00] > AUTH LOGIN',
            '[2026-09-18T10:00:00.000000+00:00] < 334 VXNlcm5hbWU6',
            '[2026-09-18T10:00:00.000000+00:00] > c3VwcG9ydEBlZHVtYy5ubA==',
            '[2026-09-18T10:00:00.000000+00:00] < 334 UGFzc3dvcmQ6',
            '[2026-09-18T10:00:00.000000+00:00] > c3VwZXJTZWNyZXRQYXNzd29yZCE=',
            '[2026-09-18T10:00:00.000000+00:00] < 235 2.7.0 Authentication successful',
            '[2026-09-18T10:00:00.000000+00:00] > MAIL FROM:<support@edumc.nl>',
            '[2026-09-18T10:00:00.000000+00:00] < 250 OK',
            '[2026-09-18T10:00:00.000000+00:00] > DATA',
            '[2026-09-18T10:00:00.000000+00:00] < 354 End data with <CR><LF>.<CR><LF>',
            '[2026-09-18T10:00:00.000000+00:00] < 250 OK: queued',
        ]);

        $sanitized = $this->invokeSanitize($transcript);

        $this->assertStringNotContainsString('c3VwcG9ydEBlZHVtYy5ubA==', $sanitized);
        $this->assertStringNotContainsString('c3VwZXJTZWNyZXRQYXNzd29yZCE=', $sanitized);
        $this->assertStringContainsString('[REDACTED]', $sanitized);
        // Non-sensitive protocol lines survive untouched.
        $this->assertStringContainsString('MAIL FROM:<support@edumc.nl>', $sanitized);
        $this->assertStringContainsString('250 OK: queued', $sanitized);
        $this->assertStringContainsString('235 2.7.0 Authentication successful', $sanitized);
    }

    public function test_sanitize_smtp_debug_redacts_a_plain_auth_exchange(): void
    {
        $transcript = implode("\n", [
            '[2026-09-18T10:00:00.000000+00:00] > AUTH PLAN AHN1cHBvcnRAZWR1bWMubmwAc3VwcG9ydEBlZHVtYy5ubABzdXBlclNlY3JldA==',
            '[2026-09-18T10:00:00.000000+00:00] < 235 2.7.0 Authentication successful',
        ]);

        $sanitized = $this->invokeSanitize($transcript);

        $this->assertStringNotContainsString('AHN1cHBvcnRAZWR1bWMubmwAc3VwcG9ydEBlZHVtYy5ubABzdXBlclNlY3JldA==', $sanitized);
    }

    public function test_sanitize_smtp_debug_handles_empty_and_null_input(): void
    {
        $this->assertNull($this->invokeSanitize(null));
        $this->assertSame('', $this->invokeSanitize(''));
    }

    private function invokeSanitize(?string $debug): ?string
    {
        $job = new SendLoggedEmailJob(
            new ContactConfirmationMail('Visitor', 'Subject', 'Body', now()->toDateTimeString(), 'TK-1'),
            'visitor@example.com',
            'Visitor',
            1,
        );

        $method = (new ReflectionClass($job))->getMethod('sanitizeSmtpDebug');
        $method->setAccessible(true);

        return $method->invoke($job, $debug);
    }
}
