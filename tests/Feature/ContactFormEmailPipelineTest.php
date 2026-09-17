<?php

namespace Tests\Feature;

use App\Mail\ContactAdminNotificationMail;
use App\Mail\ContactConfirmationMail;
use App\Mail\WelcomeMail;
use App\Models\NotificationLog;
use App\Models\SupportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Full contact-form pipeline: submission -> persistence -> both emails
 * dispatched -> NotificationLog rows -> (queue is 'sync' in phpunit.xml, so
 * SendLoggedEmailJob runs inline) -> final status.
 *
 * Uses Mail::fake() here (addressing/persistence assertions only — these do
 * not depend on real transport selection, which SendLoggedEmailJobTest
 * covers separately without faking, since Mail::fake() would bypass the
 * exact code path that caused the production bug).
 */
class ContactFormEmailPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name'    => 'Sara Ahmed',
            'email'   => 'sara@example.com',
            'subject' => 'Question about enrollment',
            'message' => 'I would like to know more about the upcoming course schedule.',
            'category' => 'general',
        ], $overrides);
    }

    public function test_contact_submission_persists_a_support_ticket(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/contact', $this->validPayload());

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseHas('support_tickets', [
            'email'  => 'sara@example.com',
            'source' => 'contact_form',
            'status' => 'new',
        ]);
    }

    public function test_visitor_confirmation_mail_is_addressed_to_the_submitter(): void
    {
        Mail::fake();

        $this->postJson('/api/contact', $this->validPayload(['email' => 'visitor-x@example.com']));

        Mail::assertSent(ContactConfirmationMail::class, function (ContactConfirmationMail $mail) {
            return $mail->hasTo('visitor-x@example.com');
        });
    }

    public function test_admin_notification_is_addressed_to_the_configured_support_inbox(): void
    {
        Mail::fake();
        config()->set('mail.support_email', 'support@edumc.nl');

        $this->postJson('/api/contact', $this->validPayload());

        Mail::assertSent(ContactAdminNotificationMail::class, function (ContactAdminNotificationMail $mail) {
            return $mail->hasTo('support@edumc.nl');
        });
    }

    public function test_admin_notification_reply_to_is_the_visitor_not_the_support_inbox(): void
    {
        Mail::fake();

        $this->postJson('/api/contact', $this->validPayload(['email' => 'visitor-y@example.com']));

        Mail::assertSent(ContactAdminNotificationMail::class, function (ContactAdminNotificationMail $mail) {
            $envelope = $mail->envelope();
            return $envelope->replyTo[0]->address === 'visitor-y@example.com';
        });
    }

    public function test_visitor_confirmation_reply_to_is_the_support_inbox_not_a_random_address(): void
    {
        Mail::fake();
        config()->set('mail.support_email', 'support@edumc.nl');

        $this->postJson('/api/contact', $this->validPayload());

        Mail::assertSent(ContactConfirmationMail::class, function (ContactConfirmationMail $mail) {
            $envelope = $mail->envelope();
            return $envelope->replyTo[0]->address === 'support@edumc.nl';
        });
    }

    public function test_neither_contact_mail_ever_uses_the_visitor_address_as_from(): void
    {
        Mail::fake();

        $this->postJson('/api/contact', $this->validPayload(['email' => 'visitor-z@example.com']));

        Mail::assertSent(ContactConfirmationMail::class, function (ContactConfirmationMail $mail) {
            return $mail->envelope()->from->address !== 'visitor-z@example.com';
        });
        Mail::assertSent(ContactAdminNotificationMail::class, function (ContactAdminNotificationMail $mail) {
            return $mail->envelope()->from->address !== 'visitor-z@example.com';
        });
    }

    public function test_confirmation_template_renders_without_error(): void
    {
        $mailable = new ContactConfirmationMail('Sara', 'Enrollment question', 'Body text here', '2026-09-18 10:00', 'TK-2026-0001');

        $rendered = $mailable->render();

        $this->assertStringContainsString('Sara', $rendered);
        $this->assertStringContainsString('TK-2026-0001', $rendered);
        $this->assertStringContainsString('Enrollment question', $rendered);
    }

    public function test_admin_notification_template_renders_without_error(): void
    {
        $mailable = new ContactAdminNotificationMail(
            'Sara', 'sara@example.com', null, 'Enrollment question', 'Body text', 'general', '2026-09-18 10:00', null, 'TK-2026-0001'
        );

        $rendered = $mailable->render();

        $this->assertStringContainsString('Sara', $rendered);
        $this->assertStringContainsString('sara@example.com', $rendered);
    }

    public function test_two_notification_log_rows_are_created_for_one_submission(): void
    {
        Mail::fake();

        $this->postJson('/api/contact', $this->validPayload());

        $this->assertSame(2, NotificationLog::where('channel', 'email')
            ->whereIn('type', ['contact_confirmation', 'contact_admin_notification'])
            ->count());
    }

    public function test_contact_submission_survives_even_if_email_dispatch_throws(): void
    {
        // QUEUE_CONNECTION=sync in tests means SendLoggedEmailJob::handle()
        // runs inline and its exception propagates back through ::dispatch();
        // pointing the default mailer at a name that doesn't exist forces a
        // real throw at send time. EmailService::dispatch() wraps this in its
        // own try/catch — the contract under test is that this failure never
        // reaches the contact route, so the ticket is still created and the
        // request still reports success.
        config()->set('mail.default', 'this_mailer_does_not_exist');
        $before = SupportTicket::count();

        $response = $this->postJson('/api/contact', $this->validPayload());

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame($before + 1, SupportTicket::count());

        $this->assertDatabaseHas('notification_logs', [
            'type'   => 'contact_confirmation',
            'status' => 'failed',
        ]);
    }

    public function test_honeypot_field_rejects_bots_without_creating_a_ticket(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/contact', $this->validPayload(['_honey' => 'i-am-a-bot']));

        $response->assertStatus(422);
        $this->assertDatabaseMissing('support_tickets', ['email' => 'sara@example.com']);
    }

    public function test_retry_endpoint_rejects_contact_confirmation_as_not_auto_retryable(): void
    {
        $admin = \App\Models\User::factory()->create(['role' => 'super_admin']);
        $log = NotificationLog::create([
            'channel' => 'email', 'type' => 'contact_confirmation',
            'recipient' => 'visitor@example.com', 'recipient_name' => 'Visitor',
            'status' => 'failed', 'failed_at' => now(),
        ]);

        $response = $this->actingAs($admin)->postJson("/api/admin/email-logs/{$log->id}/retry");

        $response->assertStatus(422);
        $this->assertSame('failed', $log->fresh()->status);
    }

    public function test_retry_endpoint_never_touches_the_support_tickets_table(): void
    {
        $admin = \App\Models\User::factory()->create(['role' => 'super_admin']);
        $log = NotificationLog::create([
            'channel' => 'email', 'type' => 'welcome',
            'recipient' => 'someone@example.com', 'recipient_name' => 'Someone',
            'status' => 'failed', 'failed_at' => now(),
        ]);
        $ticketsBefore = SupportTicket::count();

        $this->actingAs($admin)->postJson("/api/admin/email-logs/{$log->id}/retry")->assertOk();

        // QUEUE_CONNECTION=sync in tests means the retried job already ran
        // to completion inline by the time the response returns — the
        // contract under test is that retrying an email never creates or
        // touches a SupportTicket row, regardless of the resulting status.
        $this->assertSame($ticketsBefore, SupportTicket::count());
        $this->assertNotSame('failed', $log->fresh()->status);
    }
}
