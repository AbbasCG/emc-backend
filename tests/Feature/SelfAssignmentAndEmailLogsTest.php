<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationJob;
use App\Models\Department;
use App\Models\NotificationLog;
use App\Models\NotificationPreference;
use App\Models\PlatformNotification;
use App\Models\SupportTicket;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Services\EmailService;
use App\Services\VolunteerConversionService;
use App\Support\TicketAssignmentNotifications as Assign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * 1. Assigning a ticket to yourself is a real assignment: you get the email when the admin and your
 *    own settings allow it, only once per assignment, operations and support kept apart.
 * 2. Every email the platform sends or tries to send lands in Email Logs with its real status, and
 *    the list hides "skipped" rows by default (before paginating) while failures stay visible.
 *
 * Nothing is faked here except the transport: the queue is sync and the mailer is Laravel's
 * in-memory "array" mailer, so SendLoggedEmailJob really runs and the message that would have gone
 * out (subject, recipient, HTML) can be read back.
 */
class SelfAssignmentAndEmailLogsTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;

    private User $me;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dept = Department::create([
            'name' => 'Dept '.Str::random(5), 'name_ar' => 'إدارة التقنية', 'slug' => 'dept-'.Str::random(8),
            'status' => 'active', 'is_active' => true,
        ]);
        // A global role can both manage tickets and be their assignee.
        $this->me = User::factory()->create(['role' => 'tech_admin', 'is_active' => true, 'name' => 'عباس', 'email' => 'me@example.com']);
        $this->other = User::factory()->create(['role' => 'tech_admin', 'is_active' => true, 'name' => 'سارة', 'email' => 'other@example.com']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return array<int, Email> messages the array mailer "sent" */
    private function sentMessages(): array
    {
        return collect(Mail::mailer('array')->getSymfonyTransport()->messages())
            ->map(fn (SentMessage|\Symfony\Component\Mailer\SentMessage $m) => $m->getOriginalMessage())
            ->all();
    }

    private function opsTicket(array $over = []): Ticket
    {
        return Ticket::create(array_merge([
            'ticket_number' => 'EMC-TCK-'.Str::random(8), 'title' => 'تعطل بوابة الدفع', 'description' => 'وصف',
            'ticket_category' => 'OLD_ISSUE', 'priority' => 'HIGH', 'department_id' => $this->dept->id,
            'created_by_name' => 'مقدّم', 'status' => 'ASSIGNED', 'assigned_to_id' => $this->other->id,
        ], $over));
    }

    private function supportTicket(array $over = []): SupportTicket
    {
        return SupportTicket::create(array_merge([
            'name' => 'عميل', 'email' => 'customer@example.test', 'type' => 'technical',
            'priority' => 'medium', 'status' => 'new', 'subject' => 'مشكلة دخول', 'message' => 'نص',
        ], $over));
    }

    private function reassignOps(Ticket $t, User $to)
    {
        Sanctum::actingAs($this->me);

        return $this->postJson("/api/v1/tickets/{$t->id}/reassign", ['assigned_to_id' => $to->id]);
    }

    private function assignSupport(SupportTicket $t, User $to)
    {
        Sanctum::actingAs($this->me);

        return $this->patchJson("/api/operations/support-tickets/{$t->id}/assign", ['assigned_to' => $to->id]);
    }

    private function logs(string $type)
    {
        return NotificationLog::where('channel', 'email')->where('type', $type)->get();
    }

    // ═══ 1. Assigning to yourself ═══════════════════════════════════════════

    public function test_self_assigning_an_operations_ticket_sends_you_the_email_and_logs_it_as_sent(): void
    {
        $t = $this->opsTicket();

        $this->reassignOps($t, $this->me)->assertOk();

        $this->assertSame($this->me->id, (int) $t->fresh()->assigned_to_id);
        $log = $this->logs(Assign::OPERATIONS)->sole();
        $this->assertSame('sent', $log->status);
        $this->assertSame('me@example.com', $log->recipient);
        $this->assertSame($this->me->id, (int) $log->user_id);
        $this->assertNotNull($log->sent_at);

        $mail = collect($this->sentMessages())->sole();
        $this->assertSame($log->subject, $mail->getSubject());
        $this->assertStringContainsString('أُسندت إليك تذكرة', $mail->getSubject());
        $this->assertSame('me@example.com', $mail->getTo()[0]->getAddress());
        // «مراجعة التذكرة» opens the assigned ticket in review mode.
        $this->assertStringContainsString('مراجعة التذكرة', $mail->getHtmlBody());
        $this->assertStringContainsString(rtrim((string) config('app.frontend_url'), '/')."/dashboard/tickets/{$t->id}?review=assigned", $mail->getHtmlBody());
        $this->assertCount(1, PlatformNotification::where('user_id', $this->me->id)->where('type', Assign::OPERATIONS)->get());
    }

    public function test_self_assigning_a_support_ticket_sends_you_the_email_once_the_admin_enables_it(): void
    {
        Assign::setAdmin(Assign::SUPPORT, 'email', true);
        $t = $this->supportTicket();

        $this->assignSupport($t, $this->me)->assertOk();

        $log = $this->logs(Assign::SUPPORT)->sole();
        $this->assertSame('sent', $log->status);
        $this->assertSame('me@example.com', $log->recipient);
        $mail = collect($this->sentMessages())->sole();
        $this->assertSame($log->subject, $mail->getSubject());
        $this->assertStringContainsString(rtrim((string) config('app.frontend_url'), '/')."/dashboard/admin/support-tickets/{$t->id}", $mail->getHtmlBody());
        $this->assertSame(0, $this->logs(Assign::OPERATIONS)->count(), 'support never touches the operations key');
    }

    public function test_self_assignment_with_email_off_sends_no_email(): void
    {
        // Admin off for operations; support stays at its default (off).
        Assign::setAdmin(Assign::OPERATIONS, 'email', false);
        $this->reassignOps($this->opsTicket(), $this->me)->assertOk();
        $this->assignSupport($this->supportTicket(), $this->me)->assertOk();

        $this->assertSame([], $this->sentMessages());
        $this->assertSame('skipped_disabled', $this->logs(Assign::OPERATIONS)->sole()->status);
        $this->assertSame('skipped_disabled', $this->logs(Assign::SUPPORT)->sole()->status);
        // In-app still arrives: only the email channel was off.
        $this->assertSame(2, PlatformNotification::where('user_id', $this->me->id)->count());
    }

    public function test_self_assignment_with_my_own_email_preference_off_sends_no_email(): void
    {
        NotificationPreference::create(['user_id' => $this->me->id, 'channel' => 'email', 'type' => Assign::OPERATIONS, 'enabled' => false]);

        $this->reassignOps($this->opsTicket(), $this->me)->assertOk();

        $this->assertSame([], $this->sentMessages());
        $this->assertSame('skipped_user_preference', $this->logs(Assign::OPERATIONS)->sole()->status);
    }

    public function test_assigning_someone_else_emails_them_not_me(): void
    {
        $t = $this->opsTicket(['assigned_to_id' => $this->me->id]);

        $this->reassignOps($t, $this->other)->assertOk();

        $mail = collect($this->sentMessages())->sole();
        $this->assertSame('other@example.com', $mail->getTo()[0]->getAddress());
        $this->assertSame('other@example.com', $this->logs(Assign::OPERATIONS)->sole()->recipient);
    }

    public function test_saving_the_same_assignment_again_does_not_repeat_the_email(): void
    {
        Assign::setAdmin(Assign::SUPPORT, 'email', true);
        $ops = $this->opsTicket();
        $support = $this->supportTicket();

        $this->reassignOps($ops, $this->me)->assertOk();
        $this->reassignOps($ops, $this->me)->assertOk();
        $ops->fresh()->update(['priority' => 'LOW']);
        $this->assignSupport($support, $this->me)->assertOk();
        $this->assignSupport($support, $this->me)->assertOk();
        Sanctum::actingAs($this->me);
        $this->patchJson("/api/operations/support-tickets/{$support->id}", ['assigned_to' => $this->me->id])->assertOk();

        $this->assertCount(2, $this->sentMessages());
        $this->assertSame(1, $this->logs(Assign::OPERATIONS)->count());
        $this->assertSame(1, $this->logs(Assign::SUPPORT)->count());
    }

    // ═══ 2a. Email paths that used to bypass the log ═══════════════════════

    public function test_a_password_reset_email_is_logged_and_marked_sent(): void
    {
        $this->me->sendPasswordResetNotification('secret-token-123');

        $log = $this->logs('password_reset')->sole();
        $this->assertSame('sent', $log->status);
        $this->assertSame('me@example.com', $log->recipient);
        $this->assertSame(ResetPasswordNotification::SUBJECT, $log->subject);
        $this->assertStringNotContainsString('secret-token-123', json_encode($log->toArray()));
        $this->assertSame(ResetPasswordNotification::SUBJECT, collect($this->sentMessages())->sole()->getSubject());
    }

    public function test_a_failed_password_reset_is_marked_failed(): void
    {
        $log = EmailService::record('password_reset', 'me@example.com', 'عباس', $this->me->id, ResetPasswordNotification::SUBJECT, 'pending');

        (new ResetPasswordNotification('t'))->withEmailLog($log->id)->failed(new \RuntimeException('SMTP 550 mailbox unavailable'));

        $log->refresh();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('SMTP 550', $log->error_message);
    }

    public function test_the_volunteer_acceptance_email_is_logged_without_the_password(): void
    {
        $sent = app(VolunteerConversionService::class)->sendAcceptedMail($this->me, 'إدارة التقنية', 'Temp-Pass-9876');

        $this->assertTrue($sent);
        $log = $this->logs('volunteer_accepted')->sole();
        $this->assertSame('sent', $log->status);
        $this->assertSame('me@example.com', $log->recipient);
        $this->assertNotSame('', (string) $log->subject);
        $this->assertStringNotContainsString('Temp-Pass-9876', json_encode($log->toArray()));
    }

    public function test_a_volunteer_acceptance_email_that_cannot_be_sent_is_logged_as_failed(): void
    {
        config(['mail.default' => 'does-not-exist']);

        $sent = app(VolunteerConversionService::class)->sendAcceptedMail($this->me, 'إدارة التقنية', 'Temp-Pass-9876');

        $this->assertFalse($sent);
        $log = $this->logs('volunteer_accepted')->sole();
        $this->assertSame('failed', $log->status);
        $this->assertNotNull($log->error_message);
        $this->assertStringNotContainsString('Temp-Pass-9876', json_encode($log->toArray()));
    }

    public function test_a_platform_notification_email_is_logged(): void
    {
        SendNotificationJob::dispatch($this->me, 'custom_notice', ['title' => 'تنبيه مهم', 'message' => 'نص التنبيه', 'send_email' => true]);

        $log = $this->logs('platform_notification_email')->sole();
        $this->assertSame('sent', $log->status);
        $this->assertSame('تنبيه مهم', $log->subject);
        $this->assertSame('تنبيه مهم', collect($this->sentMessages())->sole()->getSubject());
    }

    public function test_a_password_reset_that_cannot_even_be_queued_is_marked_failed_not_left_pending(): void
    {
        \Illuminate\Support\Facades\Notification::shouldReceive('send')->andThrow(new \RuntimeException('queue backend down'));

        try {
            $this->me->sendPasswordResetNotification('tok');
            $this->fail('the queue error must still reach the caller');
        } catch (\RuntimeException) {
        }

        $log = $this->logs('password_reset')->sole();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('queue backend down', $log->error_message);
    }

    public function test_an_email_to_a_malformed_address_shows_as_failed_instead_of_vanishing(): void
    {
        \App\Services\EmailService::dispatch('automation_email', 'not-an-address', 'not-an-address', null,
            new \App\Mail\RawContentMail('تنبيه', 'نص'), 'تنبيه');

        $log = $this->logs('automation_email')->sole();
        $this->assertSame('failed', $log->status);
        $this->assertSame('not-an-address', $log->recipient);
        $this->assertSame([], $this->sentMessages());
    }

    public function test_the_queue_panel_counts_synchronous_sends_in_progress(): void
    {
        \App\Services\EmailService::record('volunteer_accepted', 'me@example.com', 'عباس', $this->me->id, 'قبول', 'sending');
        Sanctum::actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));

        $this->assertSame(1, $this->getJson('/api/admin/email-logs/queue')->assertOk()->json('data.pending_logs'));
        $this->assertSame(1, $this->getJson('/api/admin/email-logs/stats')->json('data.pending'));
    }

    // ═══ 2b. The Email Logs list ═══════════════════════════════════════════

    private function seedLogs(): void
    {
        $row = fn (string $status, string $recipient, int $minutesAgo) => NotificationLog::create([
            'channel' => 'email', 'type' => 'operations_ticket_assigned', 'recipient' => $recipient,
            'subject' => "موضوع {$recipient}", 'status' => $status,
        ])->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();

        foreach (range(1, 6) as $i) {
            $row('skipped_disabled', "skip{$i}@example.com", $i);
        }
        $row('skipped_user_preference', 'pref@example.com', 7);
        $row('sent', 'sent1@example.com', 8);
        $row('sent', 'sent2@example.com', 9);
        $row('pending', 'pending@example.com', 10);
        $row('failed', 'failed@example.com', 11);
    }

    public function test_skipped_rows_are_hidden_by_default_before_pagination_and_failures_stay(): void
    {
        $this->seedLogs();
        Sanctum::actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));

        $page1 = $this->getJson('/api/admin/email-logs?per_page=2')->assertOk();
        $this->assertSame(4, $page1->json('meta.total'));
        $this->assertSame(2, $page1->json('meta.last_page'));
        $this->assertCount(2, $page1->json('data'));

        $all = collect($page1->json('data'))->merge($this->getJson('/api/admin/email-logs?per_page=2&page=2')->json('data'));
        $this->assertEqualsCanonicalizing(['sent', 'sent', 'pending', 'failed'], $all->pluck('status')->all());
        $this->assertContains('failed@example.com', $all->pluck('recipient')->all());
    }

    public function test_choosing_a_skipped_status_shows_those_rows(): void
    {
        $this->seedLogs();
        Sanctum::actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));

        $r = $this->getJson('/api/admin/email-logs?status=skipped_disabled&per_page=5')->assertOk();
        $this->assertSame(6, $r->json('meta.total'));
        $this->assertSame(2, $r->json('meta.last_page'));
        $this->assertSame(['skipped_disabled'], collect($r->json('data'))->pluck('status')->unique()->values()->all());

        $this->assertSame(1, $this->getJson('/api/admin/email-logs?status=skipped_user_preference')->json('meta.total'));
        $this->assertSame(1, $this->getJson('/api/admin/email-logs?status=failed')->json('meta.total'));
    }

    public function test_search_and_type_filters_follow_the_same_rule(): void
    {
        $this->seedLogs();
        Sanctum::actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));

        $this->assertSame(0, $this->getJson('/api/admin/email-logs?search=skip1')->json('meta.total'));
        $this->assertSame(1, $this->getJson('/api/admin/email-logs?search=skip1&status=skipped_disabled')->json('meta.total'));
        $this->assertSame(1, $this->getJson('/api/admin/email-logs?search=failed')->json('meta.total'));
        $this->assertSame(4, $this->getJson('/api/admin/email-logs?type=operations_ticket_assigned')->json('meta.total'));
    }

    public function test_counters_match_the_default_list(): void
    {
        $this->seedLogs();
        Sanctum::actingAs(User::factory()->create(['role' => 'super_admin', 'is_active' => true]));

        $stats = $this->getJson('/api/admin/email-logs/stats')->assertOk()->json('data');

        $this->assertSame(4, $stats['total'], 'same as the default list total');
        $this->assertSame(7, $stats['skipped']);
        $this->assertSame(2, $stats['total_sent']);
        $this->assertSame(1, $stats['failed']);
        $this->assertSame(1, $stats['pending']);
        $this->assertEquals(50.0, $stats['success_rate'], '2 sent of 4 sent-or-attempted, skipped rows apart');
    }
}
