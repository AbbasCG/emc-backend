<?php

namespace Tests\Feature;

use App\Jobs\SendLoggedEmailJob;
use App\Mail\SupportTicketAssignedMail;
use App\Mail\TicketAssignedMail;
use App\Models\Department;
use App\Models\EmailSetting;
use App\Models\NotificationChannelSetting;
use App\Models\NotificationLog;
use App\Models\NotificationPreference;
use App\Models\PlatformNotification;
use App\Models\SupportTicket;
use App\Models\Ticket;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\TicketAssignmentNotifications as Assign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The two "ticket assigned to you" notifications — operations tickets
 * (operations_ticket_assigned) and support tickets (ticket_assigned) — each with
 * two channels (email, in_app). Every channel is decided independently by the
 * administrator's switch AND the assignee's own preference, and neither ever
 * affects the assignment itself.
 */
class TicketAssignmentChannelSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;

    private User $leader;

    private User $alice;

    private User $bob;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([SendLoggedEmailJob::class]);

        $this->dept = Department::create([
            'name' => 'Dept '.Str::random(5), 'name_ar' => 'إدارة التقنية', 'slug' => 'dept-'.Str::random(8),
            'status' => 'active', 'is_active' => true,
        ]);
        $this->leader = $this->user('programs_manager', ['name' => 'قائد الإدارة']);
        $this->dept->update(['leader_id' => $this->leader->id]);
        $this->alice = $this->user('tech_admin', ['name' => 'أليس', 'email' => 'alice@example.com']);
        $this->bob = $this->user('tech_admin', ['name' => 'بوب', 'email' => 'bob@example.com']);
        $this->superAdmin = $this->user('super_admin');
    }

    private function user(string $role, array $attrs = []): User
    {
        return User::factory()->create(array_merge(['role' => $role, 'is_active' => true], $attrs));
    }

    private function opsTicket(array $over = []): Ticket
    {
        return Ticket::create(array_merge([
            'ticket_number' => 'EMC-TCK-'.Str::random(8), 'title' => 'تعطل بوابة الدفع', 'description' => 'وصف',
            'ticket_category' => 'OLD_ISSUE', 'priority' => 'MEDIUM', 'department_id' => $this->dept->id,
            'created_by_name' => 'مقدّم', 'status' => 'ASSIGNED', 'assigned_to_id' => $this->bob->id,
        ], $over));
    }

    private function supportTicket(array $over = []): SupportTicket
    {
        return SupportTicket::create(array_merge([
            'name' => 'عميل', 'email' => 'customer@example.test', 'phone' => '+31600000000', 'type' => 'technical',
            'priority' => 'high', 'status' => 'new', 'subject' => 'لا أستطيع الدخول', 'message' => 'رسالة العميل السرية',
        ], $over));
    }

    private function reassignOps(Ticket $t, User $to)
    {
        Sanctum::actingAs($this->leader);

        return $this->postJson("/api/v1/tickets/{$t->id}/reassign", ['assigned_to_id' => $to->id]);
    }

    private function assignSupport(SupportTicket $t, User $to, ?User $as = null)
    {
        Sanctum::actingAs($as ?? $this->superAdmin);

        return $this->patchJson("/api/operations/support-tickets/{$t->id}/assign", ['assigned_to' => $to->id]);
    }

    private function inApp(User $u, string $type)
    {
        return PlatformNotification::where('user_id', $u->id)->where('type', $type)->get();
    }

    /** @return array<int, SendLoggedEmailJob> */
    private function emails(string $mailClass): array
    {
        $jobs = [];
        Queue::assertPushed(SendLoggedEmailJob::class, function ($job) use (&$jobs, $mailClass) {
            if ($job->mailable instanceof $mailClass) {
                $jobs[] = $job;
            }

            return true;
        });

        return $jobs;
    }

    private function emailCount(string $mailClass): int
    {
        return count(Queue::pushed(SendLoggedEmailJob::class, fn ($job) => $job->mailable instanceof $mailClass));
    }

    // ═══ Defaults after the update ═══════════════════════════════════════════

    public function test_defaults_keep_todays_behaviour(): void
    {
        $this->assertTrue(Assign::adminEnabled(Assign::OPERATIONS, 'email'));
        $this->assertTrue(Assign::adminEnabled(Assign::OPERATIONS, 'in_app'));
        $this->assertTrue(Assign::adminEnabled(Assign::SUPPORT, 'in_app'), 'support in-app was always sent');
        $this->assertFalse(Assign::adminEnabled(Assign::SUPPORT, 'email'), 'support assignment email is new: off until an admin enables it');
    }

    public function test_an_account_without_saved_preferences_follows_the_global_setting(): void
    {
        Sanctum::actingAs($this->alice);

        $types = collect($this->getJson('/api/notifications/assignment-preferences')->assertOk()->json('data.types'))->keyBy('type');

        $this->assertSame(['operations_ticket_assigned', 'ticket_assigned'], $types->keys()->all());
        foreach ($types as $t) {
            foreach (['email', 'in_app'] as $c) {
                $this->assertTrue($t['channels'][$c]['enabled']);
                $this->assertFalse($t['channels'][$c]['customized']);
            }
        }
        $this->assertTrue($types['operations_ticket_assigned']['channels']['email']['available']);
        $this->assertFalse($types['ticket_assigned']['channels']['email']['available']);

        // And the global setting is what actually happens.
        $this->reassignOps($this->opsTicket(), $this->alice)->assertOk();
        $this->assertCount(1, $this->inApp($this->alice, Assign::OPERATIONS));
        $this->assertSame(1, $this->emailCount(TicketAssignedMail::class));
    }

    // ═══ Support tickets keep the original key and link ═════════════════════

    public function test_support_assignment_keeps_the_original_in_app_notification(): void
    {
        $t = $this->supportTicket();

        $this->assignSupport($t, $this->alice)->assertOk();

        $n = $this->inApp($this->alice, 'ticket_assigned')->sole();
        $this->assertSame('تم تعيين تذكرة دعم جديدة إليك', $n->title);
        $this->assertSame("/dashboard/admin/support-tickets/{$t->id}", $n->action_url);
        $this->assertSame(SupportTicket::class, $n->entity_type);
        $this->assertSame($this->superAdmin->id, (int) $n->created_by);
        // Email is off by default: logged as skipped, nothing queued.
        $this->assertSame('skipped_disabled', NotificationLog::where('type', 'ticket_assigned')->sole()->status);
        $this->assertSame(0, $this->emailCount(SupportTicketAssignedMail::class));
    }

    public function test_support_email_once_enabled_has_a_review_button_and_no_customer_data(): void
    {
        Assign::setAdmin(Assign::SUPPORT, 'email', true);
        $t = $this->supportTicket();

        $this->assignSupport($t, $this->alice)->assertOk();

        $jobs = $this->emails(SupportTicketAssignedMail::class);
        $this->assertCount(1, $jobs);
        $this->assertSame('alice@example.com', $jobs[0]->toEmail);
        $html = $jobs[0]->mailable->render();
        $this->assertStringContainsString('مراجعة التذكرة', $html);
        $this->assertStringContainsString(rtrim((string) config('app.frontend_url'), '/')."/dashboard/admin/support-tickets/{$t->id}", $html);
        $this->assertStringContainsString('لا أستطيع الدخول', $html);
        $this->assertStringContainsString('عالية', $jobs[0]->mailable->subjectLine());
        $this->assertStringNotContainsString('لا أستطيع الدخول', $jobs[0]->mailable->subjectLine(), 'customer-written subject stays out of the subject line');
        foreach (['customer@example.test', '+31600000000', 'رسالة العميل السرية'] as $private) {
            $this->assertStringNotContainsString($private, $html);
        }
    }

    public function test_support_reassign_to_the_same_person_or_to_yourself_sends_nothing(): void
    {
        $t = $this->supportTicket(['assigned_to' => $this->alice->id]);

        $this->assignSupport($t, $this->alice)->assertOk();
        $this->assignSupport($t, $this->bob, $this->bob)->assertOk();

        $this->assertSame(0, PlatformNotification::where('type', 'ticket_assigned')->count());
    }

    public function test_support_assignment_through_the_update_endpoint_also_notifies(): void
    {
        $t = $this->supportTicket();
        Sanctum::actingAs($this->superAdmin);

        $this->patchJson("/api/operations/support-tickets/{$t->id}", ['assigned_to' => $this->alice->id])->assertOk();
        $this->patchJson("/api/operations/support-tickets/{$t->id}", ['priority' => 'low'])->assertOk();

        $this->assertCount(1, $this->inApp($this->alice, 'ticket_assigned'));
    }

    public function test_support_a_to_b_to_a_notifies_each_real_assignment(): void
    {
        $t = $this->supportTicket();

        $this->assignSupport($t, $this->alice)->assertOk();
        $this->assignSupport($t, $this->bob)->assertOk();
        $this->assignSupport($t, $this->alice)->assertOk();

        $this->assertCount(2, $this->inApp($this->alice, 'ticket_assigned'));
        $this->assertCount(1, $this->inApp($this->bob, 'ticket_assigned'));
    }

    public function test_a_retried_support_assignment_is_not_duplicated_on_either_channel(): void
    {
        Assign::setAdmin(Assign::SUPPORT, 'email', true);
        $t = $this->supportTicket(['assigned_to' => $this->alice->id]);

        NotificationService::supportTicketAssigned($this->alice->id, $t, 'none@1', $this->superAdmin->id);
        NotificationService::supportTicketAssigned($this->alice->id, $t, 'none@1', $this->superAdmin->id);

        $this->assertCount(1, $this->inApp($this->alice, 'ticket_assigned'));
        $this->assertSame(1, NotificationLog::where('type', 'ticket_assigned')->count());
        $this->assertSame(1, $this->emailCount(SupportTicketAssignedMail::class));
    }

    // ═══ Independence: the two types ═══════════════════════════════════════

    public function test_turning_operations_off_does_not_touch_support_and_vice_versa(): void
    {
        Assign::setAdmin(Assign::SUPPORT, 'email', true);
        Assign::setAdmin(Assign::OPERATIONS, 'email', false);
        Assign::setAdmin(Assign::OPERATIONS, 'in_app', false);

        $this->reassignOps($this->opsTicket(), $this->alice)->assertOk();
        $this->assignSupport($this->supportTicket(), $this->alice)->assertOk();

        $this->assertCount(0, $this->inApp($this->alice, Assign::OPERATIONS));
        $this->assertSame(0, $this->emailCount(TicketAssignedMail::class));
        $this->assertCount(1, $this->inApp($this->alice, Assign::SUPPORT));
        $this->assertSame(1, $this->emailCount(SupportTicketAssignedMail::class));
    }

    public function test_a_user_turning_support_off_still_gets_operations(): void
    {
        Assign::setAdmin(Assign::SUPPORT, 'email', true);
        Sanctum::actingAs($this->alice);
        $this->putJson('/api/notifications/assignment-preferences', ['preferences' => [
            'ticket_assigned' => ['email' => false, 'in_app' => false],
        ]])->assertOk();

        $this->reassignOps($this->opsTicket(), $this->alice)->assertOk();
        $this->assignSupport($this->supportTicket(), $this->alice)->assertOk();

        $this->assertCount(1, $this->inApp($this->alice, Assign::OPERATIONS));
        $this->assertSame(1, $this->emailCount(TicketAssignedMail::class));
        $this->assertCount(0, $this->inApp($this->alice, Assign::SUPPORT));
        $this->assertSame(0, $this->emailCount(SupportTicketAssignedMail::class));
    }

    // ═══ Independence: the two channels ════════════════════════════════════

    public function test_admin_email_off_keeps_in_app(): void
    {
        Assign::setAdmin(Assign::OPERATIONS, 'email', false);

        $this->reassignOps($this->opsTicket(), $this->alice)->assertOk();

        $this->assertCount(1, $this->inApp($this->alice, Assign::OPERATIONS));
        $this->assertSame('skipped_disabled', NotificationLog::where('type', Assign::OPERATIONS)->sole()->status);
        $this->assertSame(0, $this->emailCount(TicketAssignedMail::class));
    }

    public function test_admin_in_app_off_keeps_email(): void
    {
        Assign::setAdmin(Assign::OPERATIONS, 'in_app', false);

        $this->reassignOps($this->opsTicket(), $this->alice)->assertOk();

        $this->assertCount(0, $this->inApp($this->alice, Assign::OPERATIONS));
        $this->assertSame(1, $this->emailCount(TicketAssignedMail::class));
    }

    public function test_user_email_off_keeps_in_app_and_logs_why(): void
    {
        NotificationPreference::create(['user_id' => $this->alice->id, 'channel' => 'email', 'type' => Assign::OPERATIONS, 'enabled' => false]);

        $this->reassignOps($this->opsTicket(), $this->alice)->assertOk();

        $this->assertCount(1, $this->inApp($this->alice, Assign::OPERATIONS));
        $this->assertSame('skipped_user_preference', NotificationLog::where('type', Assign::OPERATIONS)->sole()->status);
        $this->assertSame(0, $this->emailCount(TicketAssignedMail::class));
    }

    public function test_user_in_app_off_keeps_email(): void
    {
        NotificationPreference::create(['user_id' => $this->alice->id, 'channel' => 'in_app', 'type' => Assign::OPERATIONS, 'enabled' => false]);

        $this->reassignOps($this->opsTicket(), $this->alice)->assertOk();

        $this->assertCount(0, $this->inApp($this->alice, Assign::OPERATIONS));
        $this->assertSame(1, $this->emailCount(TicketAssignedMail::class));
    }

    public function test_both_channels_off_still_assigns_and_the_assignee_can_open_and_act(): void
    {
        foreach (['email', 'in_app'] as $c) {
            NotificationPreference::create(['user_id' => $this->alice->id, 'channel' => $c, 'type' => Assign::OPERATIONS, 'enabled' => false]);
        }
        $t = $this->opsTicket();

        $this->reassignOps($t, $this->alice)->assertOk();

        $this->assertSame($this->alice->id, (int) $t->fresh()->assigned_to_id);
        $this->assertSame(0, PlatformNotification::where('type', Assign::OPERATIONS)->count());
        $this->assertSame(0, $this->emailCount(TicketAssignedMail::class));

        Sanctum::actingAs($this->alice);
        $this->getJson("/api/v1/tickets/{$t->id}")->assertOk()->assertJsonPath('data.capabilities.accept_task', true);
        $this->postJson("/api/v1/tickets/{$t->id}/accept-task")->assertOk();
    }

    // ═══ Returning an accepted ticket to the same assignee ═══════════════════

    public function test_returning_an_accepted_ticket_to_the_same_person_notifies_once_on_each_allowed_channel(): void
    {
        $t = $this->opsTicket(['status' => 'IN_PROGRESS', 'assigned_to_id' => $this->alice->id, 'accepted_at' => now()->subHour()]);

        $this->reassignOps($t, $this->alice)->assertOk();

        $this->assertSame('ASSIGNED', $t->fresh()->status, 'the acceptance is back to awaiting a reply');
        $this->assertNull($t->fresh()->accepted_at);
        $this->assertCount(1, $this->inApp($this->alice, Assign::OPERATIONS));
        $this->assertSame(1, $this->emailCount(TicketAssignedMail::class));

        // Sent again while still awaiting a reply: the acceptance state does not change, so nothing repeats.
        $this->reassignOps($t, $this->alice)->assertOk();
        $this->assertCount(1, $this->inApp($this->alice, Assign::OPERATIONS));
        $this->assertSame(1, $this->emailCount(TicketAssignedMail::class));
        $this->assertSame(1, NotificationLog::where('type', Assign::OPERATIONS)->count());
    }

    public function test_an_in_progress_ticket_without_accepted_at_also_counts_as_back_to_awaiting_reply(): void
    {
        // Older rows can be IN_PROGRESS with no accepted_at; returning them still asks for a new acceptance.
        $t = $this->opsTicket(['status' => 'IN_PROGRESS', 'assigned_to_id' => $this->alice->id, 'accepted_at' => null]);

        $this->reassignOps($t, $this->alice)->assertOk();

        $this->assertSame('ASSIGNED', $t->fresh()->status);
        $this->assertCount(1, $this->inApp($this->alice, Assign::OPERATIONS));
        $this->assertSame(1, $this->emailCount(TicketAssignedMail::class));
    }

    public function test_returning_an_accepted_ticket_respects_the_admin_and_the_users_channels(): void
    {
        Assign::setAdmin(Assign::OPERATIONS, 'email', false);
        $a = $this->opsTicket(['status' => 'IN_PROGRESS', 'assigned_to_id' => $this->alice->id, 'accepted_at' => now()]);
        $this->reassignOps($a, $this->alice)->assertOk();

        $this->assertCount(1, $this->inApp($this->alice, Assign::OPERATIONS), 'admin email off: in-app only');
        $this->assertSame(0, $this->emailCount(TicketAssignedMail::class));
        $this->assertSame('skipped_disabled', NotificationLog::where('type', Assign::OPERATIONS)->sole()->status);

        Assign::setAdmin(Assign::OPERATIONS, 'email', true);
        NotificationPreference::create(['user_id' => $this->bob->id, 'channel' => 'in_app', 'type' => Assign::OPERATIONS, 'enabled' => false]);
        $b = $this->opsTicket(['status' => 'IN_PROGRESS', 'assigned_to_id' => $this->bob->id, 'accepted_at' => now()]);
        $this->reassignOps($b, $this->bob)->assertOk();

        $this->assertCount(0, $this->inApp($this->bob, Assign::OPERATIONS), 'user in-app off: email only');
        $this->assertSame(1, $this->emailCount(TicketAssignedMail::class));
    }

    public function test_when_the_acceptance_state_does_not_change_nothing_is_sent(): void
    {
        $inProgress = $this->opsTicket(['status' => 'IN_PROGRESS', 'assigned_to_id' => $this->alice->id, 'accepted_at' => now()]);
        $assigned = $this->opsTicket(['assigned_to_id' => $this->alice->id]);

        // Other edits of an accepted ticket, and handing an unaccepted one to the same person again.
        $inProgress->update(['priority' => 'HIGH', 'internal_notes' => 'متابعة']);
        Sanctum::actingAs($this->leader);
        $this->patchJson("/api/v1/tickets/{$inProgress->id}/priority", ['priority' => 'LOW'])->assertSuccessful();
        $this->reassignOps($assigned, $this->alice)->assertOk();

        $this->assertSame('IN_PROGRESS', $inProgress->fresh()->status);
        $this->assertSame(0, PlatformNotification::where('type', Assign::OPERATIONS)->count());
        $this->assertSame(0, NotificationLog::where('type', Assign::OPERATIONS)->count());
    }

    // ═══ Admin decision vs. user preference ════════════════════════════════

    public function test_admin_off_shows_unavailable_but_keeps_the_users_choice(): void
    {
        Sanctum::actingAs($this->alice);
        $this->putJson('/api/notifications/assignment-preferences', ['preferences' => [
            'operations_ticket_assigned' => ['email' => false],
        ]])->assertOk();

        Assign::setAdmin(Assign::OPERATIONS, 'email', false);
        $email = collect($this->getJson('/api/notifications/assignment-preferences')->json('data.types'))
            ->firstWhere('type', Assign::OPERATIONS)['channels']['email'];
        $this->assertSame(['enabled' => false, 'customized' => true, 'available' => false], $email);

        // Turned back on: the user's own "off" applies again.
        Assign::setAdmin(Assign::OPERATIONS, 'email', true);
        $this->reassignOps($this->opsTicket(), $this->alice)->assertOk();
        $this->assertSame(0, $this->emailCount(TicketAssignedMail::class));
        $this->assertSame('skipped_user_preference', NotificationLog::where('type', Assign::OPERATIONS)->sole()->status);
    }

    public function test_user_on_cannot_override_admin_off(): void
    {
        NotificationPreference::create(['user_id' => $this->alice->id, 'channel' => 'in_app', 'type' => Assign::OPERATIONS, 'enabled' => true]);
        Assign::setAdmin(Assign::OPERATIONS, 'in_app', false);

        $this->reassignOps($this->opsTicket(), $this->alice)->assertOk();

        $this->assertCount(0, $this->inApp($this->alice, Assign::OPERATIONS));
    }

    // ═══ Preference endpoint ════════════════════════════════════════════════

    public function test_saved_preferences_come_back_on_reopen(): void
    {
        Sanctum::actingAs($this->alice);

        $saved = $this->putJson('/api/notifications/assignment-preferences', ['preferences' => [
            'operations_ticket_assigned' => ['email' => false, 'in_app' => true],
            'ticket_assigned' => ['in_app' => false],
        ]])->assertOk()->json('data.types');

        $reopened = $this->getJson('/api/notifications/assignment-preferences')->assertOk()->json('data.types');
        $this->assertSame($saved, $reopened);

        $byType = collect($reopened)->keyBy('type');
        $this->assertFalse($byType['operations_ticket_assigned']['channels']['email']['enabled']);
        $this->assertTrue($byType['operations_ticket_assigned']['channels']['in_app']['enabled']);
        $this->assertTrue($byType['operations_ticket_assigned']['channels']['in_app']['customized']);
        $this->assertFalse($byType['ticket_assigned']['channels']['in_app']['enabled']);
        $this->assertFalse($byType['ticket_assigned']['channels']['email']['customized'], 'only what was sent is saved');
    }

    public function test_a_user_cannot_change_another_accounts_preferences(): void
    {
        NotificationPreference::create(['user_id' => $this->bob->id, 'channel' => 'email', 'type' => Assign::OPERATIONS, 'enabled' => true]);
        Sanctum::actingAs($this->alice);

        $this->putJson('/api/notifications/assignment-preferences', [
            'user_id' => $this->bob->id,
            'preferences' => ['operations_ticket_assigned' => ['email' => false]],
        ])->assertOk();

        $this->assertTrue(NotificationPreference::where('user_id', $this->bob->id)->where('type', Assign::OPERATIONS)->sole()->enabled);
        $this->assertFalse(NotificationPreference::where('user_id', $this->alice->id)->where('type', Assign::OPERATIONS)->sole()->enabled);
    }

    public function test_the_preference_endpoint_rejects_bad_input_and_guests(): void
    {
        $this->getJson('/api/notifications/assignment-preferences')->assertUnauthorized();

        Sanctum::actingAs($this->alice);
        $this->putJson('/api/notifications/assignment-preferences', ['preferences' => ['course_created' => ['email' => false]]])->assertStatus(422);
        $this->putJson('/api/notifications/assignment-preferences', ['preferences' => ['ticket_assigned' => ['sms' => false]]])->assertStatus(422);
        $this->putJson('/api/notifications/assignment-preferences', ['preferences' => ['ticket_assigned' => ['email' => 'maybe']]])->assertStatus(422);
        $this->putJson('/api/notifications/assignment-preferences', ['preferences' => []])->assertStatus(422);

        $this->assertSame(0, NotificationPreference::count());
    }

    // ═══ Admin endpoint ═════════════════════════════════════════════════════

    public function test_admin_settings_are_readable_by_admins_and_writable_only_by_super_admins(): void
    {
        Sanctum::actingAs($this->user('admin'));
        $this->getJson('/api/admin/assignment-notification-settings')->assertOk()->assertJsonCount(2, 'data.types');
        $this->putJson('/api/admin/assignment-notification-settings', ['settings' => ['ticket_assigned' => ['email' => true]]])->assertForbidden();

        // Same rule as email-settings: operations_manager may read but not write.
        // (tech_admin and super_admin pass every role check by RoleMiddleware design.)
        Sanctum::actingAs($this->user('operations_manager'));
        $this->getJson('/api/admin/assignment-notification-settings')->assertOk();
        $this->putJson('/api/admin/assignment-notification-settings', ['settings' => ['ticket_assigned' => ['email' => true]]])->assertForbidden();

        Sanctum::actingAs($this->user('student'));
        $this->getJson('/api/admin/assignment-notification-settings')->assertForbidden();

        $this->assertFalse(Assign::adminEnabled(Assign::SUPPORT, 'email'));
    }

    public function test_a_super_admin_switches_each_channel_independently(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $types = collect($this->putJson('/api/admin/assignment-notification-settings', ['settings' => [
            'ticket_assigned' => ['email' => true],
            'operations_ticket_assigned' => ['in_app' => false],
        ]])->assertOk()->json('data.types'))->keyBy('type');

        $this->assertTrue($types['ticket_assigned']['channels']['email']['enabled']);
        $this->assertTrue($types['ticket_assigned']['channels']['in_app']['enabled']);
        $this->assertFalse($types['operations_ticket_assigned']['channels']['in_app']['enabled']);
        $this->assertTrue($types['operations_ticket_assigned']['channels']['email']['enabled']);

        // Email reuses the admin email switch, so the email settings list agrees.
        $this->assertTrue((bool) EmailSetting::where('key', 'ticket_assigned')->value('enabled'));
        $list = $this->getJson('/api/admin/email-settings')->assertOk()->json('data.settings');
        $this->assertTrue($list['ticket_assigned']['enabled']);
        $this->assertTrue($list['operations_ticket_assigned']['enabled']);
        $this->assertFalse(NotificationChannelSetting::isEnabled('operations_ticket_assigned', 'in_app'));

        $this->putJson('/api/admin/assignment-notification-settings', ['settings' => ['course_created' => ['email' => false]]])->assertStatus(422);
        $this->putJson('/api/admin/assignment-notification-settings', ['settings' => ['ticket_assigned' => ['sms' => false]]])->assertStatus(422);
    }

    // ═══ Upgrade: splitting the old shared key ═════════════════════════════

    public function test_the_key_split_migration_keeps_old_records_and_settings(): void
    {
        // State as left by the earlier shared-key version: an admin had turned the (operations)
        // ticket_assigned email off, and rows of both systems carry ticket_assigned.
        EmailSetting::where('key', 'like', '%ticket_assigned')->delete();
        EmailSetting::create(['key' => 'ticket_assigned', 'enabled' => false]);
        $support = $this->supportTicket();
        $ops = $this->opsTicket();
        $now = now();
        DB::table('platform_notifications')->insert([
            ['user_id' => $this->alice->id, 'type' => 'ticket_assigned', 'title' => 'ops', 'message' => 'm', 'entity_type' => 'ticket', 'entity_id' => $ops->id, 'action_url' => "/dashboard/tickets/{$ops->id}?review=assigned", 'is_read' => false, 'created_at' => $now, 'updated_at' => $now],
            ['user_id' => $this->alice->id, 'type' => 'ticket_assigned', 'title' => 'support', 'message' => 'm', 'entity_type' => SupportTicket::class, 'entity_id' => $support->id, 'action_url' => "/dashboard/admin/support-tickets/{$support->id}", 'is_read' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);
        NotificationLog::create(['channel' => 'email', 'type' => 'ticket_assigned', 'recipient' => 'alice@example.com', 'status' => 'sent', 'user_id' => $this->alice->id, 'payload' => ['ticket_id' => $ops->id]]);
        NotificationPreference::create(['user_id' => $this->alice->id, 'channel' => 'in_app', 'type' => 'ticket_assigned', 'enabled' => false]);

        (include database_path('migrations/2026_11_22_000002_split_ticket_assigned_notification_keys.php'))->up();

        $this->assertFalse(Assign::adminEnabled(Assign::OPERATIONS, 'email'), 'the admin\'s earlier choice moves to the operations key');
        $this->assertFalse(Assign::adminEnabled(Assign::SUPPORT, 'email'));
        $this->assertSame("/dashboard/tickets/{$ops->id}?review=assigned", PlatformNotification::where('title', 'ops')->sole()->action_url);
        $this->assertSame(Assign::OPERATIONS, PlatformNotification::where('title', 'ops')->sole()->type);
        $this->assertSame('ticket_assigned', PlatformNotification::where('title', 'support')->sole()->type);
        $this->assertSame("/dashboard/admin/support-tickets/{$support->id}", PlatformNotification::where('title', 'support')->sole()->action_url);
        $this->assertSame(Assign::OPERATIONS, NotificationLog::sole()->type);
        // The user's choice keeps applying to the flow it used to affect, and stays on the support key.
        $this->assertFalse(Assign::userEnabled($this->alice->id, Assign::OPERATIONS, 'in_app'));
        $this->assertFalse(Assign::userEnabled($this->alice->id, Assign::SUPPORT, 'in_app'));
    }

    public function test_rolling_back_the_split_never_leaves_the_operations_email_off(): void
    {
        $migration = include database_path('migrations/2026_11_22_000002_split_ticket_assigned_notification_keys.php');
        $this->assertFalse(EmailSetting::where('key', 'operations_ticket_assigned')->exists(), 'fresh install: no operations row');

        $migration->down();

        $this->assertTrue(EmailSetting::isEnabled('ticket_assigned'), 'the shared key falls back to on, not the support off');
        $this->assertFalse(EmailSetting::where('key', 'operations_ticket_assigned')->exists());
    }
}
