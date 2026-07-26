<?php

namespace Tests\Feature;

use App\Jobs\SendLoggedEmailJob;
use App\Mail\WelcomeMail;
use App\Models\EmailSetting;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EmailAutomationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = 'admin'): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    public function test_email_enabled_by_default_when_no_row_exists(): void
    {
        $this->assertTrue(EmailSetting::isEnabled('welcome'));
    }

    public function test_dispatch_queues_mail_when_setting_enabled(): void
    {
        Queue::fake();
        EmailSetting::updateOrCreate(['key' => 'welcome'], ['enabled' => true]);

        EmailService::dispatch(
            'welcome', 'student@example.com', 'Student', null,
            new WelcomeMail('Student', 'student@example.com'), 'Welcome',
        );

        Queue::assertPushed(SendLoggedEmailJob::class, 1);
        $this->assertDatabaseHas('notification_logs', [
            'type' => 'welcome', 'recipient' => 'student@example.com', 'status' => 'pending',
        ]);
    }

    public function test_dispatch_does_not_queue_or_send_when_setting_disabled(): void
    {
        Queue::fake();
        EmailSetting::updateOrCreate(['key' => 'welcome'], ['enabled' => false]);

        EmailService::dispatch(
            'welcome', 'student@example.com', 'Student', null,
            new WelcomeMail('Student', 'student@example.com'), 'Welcome',
        );

        Queue::assertNotPushed(SendLoggedEmailJob::class);
    }

    public function test_dispatch_logs_skipped_disabled_status_instead_of_pretending_it_sent(): void
    {
        Queue::fake();
        EmailSetting::updateOrCreate(['key' => 'welcome'], ['enabled' => false]);

        EmailService::dispatch(
            'welcome', 'student@example.com', 'Student', null,
            new WelcomeMail('Student', 'student@example.com'), 'Welcome',
        );

        $this->assertDatabaseHas('notification_logs', [
            'type' => 'welcome', 'recipient' => 'student@example.com', 'status' => 'skipped_disabled',
        ]);
        $this->assertDatabaseMissing('notification_logs', [
            'type' => 'welcome', 'recipient' => 'student@example.com', 'status' => 'pending',
        ]);
    }

    public function test_email_settings_index_returns_categories_and_new_department_keys(): void
    {
        $response = $this->actingAs($this->admin('super_admin'), 'sanctum')->getJson('/api/admin/email-settings');
        $response->assertOk();

        foreach ([
            'ambassador_confirmation', 'ambassador_admin_notification', 'ambassador_status_changed',
            'oral_assessment_booking_confirmed', 'oral_assessment_meeting_link_updated',
            'oral_assessment_instructor_message',
        ] as $key) {
            $this->assertArrayHasKey($key, $response->json('data.settings'));
            $this->assertNotEmpty($response->json("data.settings.{$key}.category"));
        }
    }

    public function test_settings_changes_persist_and_clear_cache(): void
    {
        $superAdmin = $this->admin('super_admin');

        $response = $this->actingAs($superAdmin, 'sanctum')->putJson('/api/admin/email-settings', [
            'settings' => ['ambassador_confirmation' => ['enabled' => false]],
        ]);
        $response->assertOk();

        $this->assertDatabaseHas('email_settings', ['key' => 'ambassador_confirmation', 'enabled' => false]);
        $this->assertFalse(EmailSetting::isEnabled('ambassador_confirmation'));
    }

    public function test_super_admin_can_update_email_settings(): void
    {
        $response = $this->actingAs($this->admin('super_admin'), 'sanctum')
            ->putJson('/api/admin/email-settings', ['settings' => ['welcome' => ['enabled' => false]]]);

        $response->assertOk();
    }

    public function test_executive_admin_can_update_email_settings(): void
    {
        $response = $this->actingAs($this->admin('executive_admin'), 'sanctum')
            ->putJson('/api/admin/email-settings', ['settings' => ['welcome' => ['enabled' => false]]]);

        $response->assertOk();
    }

    public function test_plain_admin_cannot_update_email_settings(): void
    {
        $response = $this->actingAs($this->admin('admin'), 'sanctum')
            ->putJson('/api/admin/email-settings', ['settings' => ['welcome' => ['enabled' => false]]]);

        $response->assertForbidden();
        $this->assertTrue(EmailSetting::isEnabled('welcome'));
    }

    public function test_tech_admin_can_update_email_settings(): void
    {
        // RoleMiddleware treats tech_admin as a superuser-equivalent that
        // bypasses every role check platform-wide (see isTechAdmin() short
        // circuit) — this matches that existing, pre-established policy
        // rather than introducing a new exception just for this page.
        $response = $this->actingAs($this->admin('tech_admin'), 'sanctum')
            ->putJson('/api/admin/email-settings', ['settings' => ['welcome' => ['enabled' => false]]]);

        $response->assertOk();
    }

    public function test_plain_admin_can_still_read_email_settings(): void
    {
        $response = $this->actingAs($this->admin('admin'), 'sanctum')->getJson('/api/admin/email-settings');
        $response->assertOk();
    }

    public function test_unauthenticated_user_cannot_read_or_update_email_settings(): void
    {
        $this->getJson('/api/admin/email-settings')->assertUnauthorized();
        $this->putJson('/api/admin/email-settings', ['settings' => []])->assertUnauthorized();
    }

    public function test_in_app_notification_is_unaffected_by_email_setting(): void
    {
        // NotificationService::send() (in-app/platform_notifications) is a
        // separate system from EmailService::dispatch() — disabling an
        // email key must never suppress the in-app notification.
        EmailSetting::updateOrCreate(['key' => 'volunteer_admin_notification'], ['enabled' => false]);
        $admin = $this->admin('admin');

        \App\Services\NotificationService::send(
            $admin->id, 'volunteer_request', 'طلب تطوع جديد', 'رسالة تجريبية',
        );

        $this->assertDatabaseHas('platform_notifications', [
            'user_id' => $admin->id, 'type' => 'volunteer_request',
        ]);
    }

    public function test_disabling_password_reset_prevents_reset_notification(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        EmailSetting::updateOrCreate(['key' => 'password_reset'], ['enabled' => false]);

        $user = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $user->sendPasswordResetNotification('some-token');

        \Illuminate\Support\Facades\Notification::assertNothingSent();
    }

    public function test_enabling_password_reset_allows_reset_notification(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        EmailSetting::updateOrCreate(['key' => 'password_reset'], ['enabled' => true]);

        $user = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $user->sendPasswordResetNotification('some-token');

        \Illuminate\Support\Facades\Notification::assertSentTo($user, \App\Notifications\ResetPasswordNotification::class);
    }
}
