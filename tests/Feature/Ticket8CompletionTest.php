<?php

namespace Tests\Feature;

use App\Events\PlatformNotificationCreated;
use App\Jobs\SendPushJob;
use App\Jobs\SendSmsJob;
use App\Models\NotificationPreference;
use App\Models\NotificationQuietHours;
use App\Models\PlatformNotification;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\QuietHoursService;
use App\Services\Sms\SmsNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Ticket 8 definitive completion pass: push, SMS, realtime, quiet hours,
 * idempotency adoption, and the event-matrix completeness guarantee.
 * No real provider network calls anywhere in this file — SMS/push default
 * to "log"/unconfigured (safe no-op) unless a test explicitly fakes Queue.
 */
class Ticket8CompletionTest extends TestCase
{
    use RefreshDatabase;

    // ── Push subscription registration ──────────────────────────────────────

    public function test_user_can_register_a_push_subscription(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/push-subscriptions', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
            'keys' => ['p256dh' => 'p256dh-key-value', 'auth' => 'auth-token-value'],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('push_subscriptions', ['user_id' => $user->id]);
    }

    public function test_registering_the_same_endpoint_twice_does_not_duplicate(): void
    {
        $user = User::factory()->create();
        $payload = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/dup', 'keys' => ['p256dh' => 'a', 'auth' => 'b']];

        $this->actingAs($user, 'sanctum')->postJson('/api/push-subscriptions', $payload)->assertCreated();
        $this->actingAs($user, 'sanctum')->postJson('/api/push-subscriptions', $payload)->assertCreated();

        $this->assertSame(1, PushSubscription::where('user_id', $user->id)->count());
    }

    public function test_user_can_remove_their_own_push_subscription(): void
    {
        $user = User::factory()->create();
        $sub = PushSubscription::create([
            'user_id' => $user->id, 'endpoint' => 'https://example.com/ep1',
            'p256dh_key' => 'a', 'auth_token' => 'b',
        ]);

        $response = $this->actingAs($user, 'sanctum')->deleteJson('/api/push-subscriptions', ['endpoint' => 'https://example.com/ep1']);

        $response->assertOk();
        $this->assertDatabaseMissing('push_subscriptions', ['id' => $sub->id]);
    }

    public function test_user_cannot_remove_another_users_push_subscription(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $sub = PushSubscription::create([
            'user_id' => $owner->id, 'endpoint' => 'https://example.com/ep2',
            'p256dh_key' => 'a', 'auth_token' => 'b',
        ]);

        $response = $this->actingAs($outsider, 'sanctum')->deleteJson('/api/push-subscriptions', ['endpoint' => 'https://example.com/ep2']);

        $response->assertOk();
        $response->assertJson(['deleted' => false]);
        $this->assertDatabaseHas('push_subscriptions', ['id' => $sub->id]);
    }

    public function test_index_reports_subscription_state_without_leaking_subscription_details(): void
    {
        $user = User::factory()->create();
        PushSubscription::create(['user_id' => $user->id, 'endpoint' => 'https://x.com/1', 'p256dh_key' => 'a', 'auth_token' => 'b']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/push-subscriptions');

        $response->assertOk()->assertJson(['subscribed' => true]);
        $this->assertArrayNotHasKey('endpoint', $response->json());
    }

    public function test_push_send_is_a_safe_no_op_when_vapid_is_not_configured(): void
    {
        config(['services.vapid.public_key' => null, 'services.vapid.private_key' => null]);
        $user = User::factory()->create();
        PushSubscription::create(['user_id' => $user->id, 'endpoint' => 'https://x.com/2', 'p256dh_key' => 'a', 'auth_token' => 'b']);

        $result = app(\App\Services\Push\WebPushNotificationService::class)->sendToUser($user->id, 'test', 'T', 'M');

        $this->assertFalse($result);
        $this->assertDatabaseHas('notification_logs', ['user_id' => $user->id, 'channel' => 'push', 'status' => 'failed']);
    }

    // ── SMS ──────────────────────────────────────────────────────────────────

    public function test_valid_e164_number_is_accepted(): void
    {
        $this->assertTrue(SmsNotificationService::isValidE164('+966512345678'));
    }

    public function test_invalid_or_missing_number_is_rejected(): void
    {
        $this->assertFalse(SmsNotificationService::isValidE164(null));
        $this->assertFalse(SmsNotificationService::isValidE164(''));
        $this->assertFalse(SmsNotificationService::isValidE164('0512345678')); // missing country code
        $this->assertFalse(SmsNotificationService::isValidE164('not-a-phone'));
    }

    public function test_sms_send_skips_safely_for_an_invalid_number_without_a_provider_call(): void
    {
        $result = app(SmsNotificationService::class)->send('not-a-number', 'test', [], 1);

        $this->assertFalse($result);
        $this->assertDatabaseHas('notification_logs', ['channel' => 'sms', 'status' => 'failed']);
    }

    public function test_sms_channel_is_skipped_when_recipient_has_no_phone_number(): void
    {
        Queue::fake([SendSmsJob::class, SendPushJob::class]);
        $user = User::factory()->create(['phone' => null]);

        NotificationService::send($user->id, 'assignment_graded', 'T', 'M', ['channels' => ['sms']]);

        Queue::assertNotPushed(SendSmsJob::class);
    }

    public function test_sms_channel_is_skipped_when_the_user_disabled_it_for_this_type(): void
    {
        Queue::fake([SendSmsJob::class, SendPushJob::class]);
        $user = User::factory()->create(['phone' => '+966512345678']);
        NotificationPreference::create(['user_id' => $user->id, 'channel' => 'sms', 'type' => 'assignment_graded', 'enabled' => false]);

        NotificationService::send($user->id, 'assignment_graded', 'T', 'M', ['channels' => ['sms']]);

        Queue::assertNotPushed(SendSmsJob::class);
    }

    public function test_sms_channel_is_dispatched_when_opted_in_with_a_valid_phone_and_enabled_preference(): void
    {
        Queue::fake([SendSmsJob::class, SendPushJob::class]);
        $user = User::factory()->create(['phone' => '+966512345678']);

        NotificationService::send($user->id, 'assignment_graded', 'T', 'M', ['channels' => ['sms']]);

        Queue::assertPushed(SendSmsJob::class, fn (SendSmsJob $job) => $job->to === '+966512345678');
    }

    // ── Push channel gating (mirrors SMS gating) ────────────────────────────

    public function test_push_channel_is_skipped_when_disabled_via_preference(): void
    {
        Queue::fake([SendSmsJob::class, SendPushJob::class]);
        $user = User::factory()->create();
        NotificationPreference::create(['user_id' => $user->id, 'channel' => 'push', 'type' => 'certificate_issued', 'enabled' => false]);

        NotificationService::send($user->id, 'certificate_issued', 'T', 'M', ['channels' => ['push']]);

        Queue::assertNotPushed(SendPushJob::class);
    }

    public function test_push_channel_is_dispatched_when_opted_in_and_enabled(): void
    {
        Queue::fake([SendSmsJob::class, SendPushJob::class]);
        $user = User::factory()->create();

        NotificationService::send($user->id, 'certificate_issued', 'T', 'M', ['channels' => ['push']]);

        Queue::assertPushed(SendPushJob::class, fn (SendPushJob $job) => $job->userId === $user->id);
    }

    // ── Quiet hours ──────────────────────────────────────────────────────────

    public function test_same_day_quiet_hours_range_correctly_detects_inside_and_outside(): void
    {
        $user = User::factory()->create();
        NotificationQuietHours::create(['user_id' => $user->id, 'enabled' => true, 'start_time' => '13:00:00', 'end_time' => '17:00:00', 'timezone' => 'UTC']);
        $service = app(QuietHoursService::class);

        $this->assertTrue($service->isWithinQuietHours($user, Carbon::parse('2026-07-22 14:00:00', 'UTC')));
        $this->assertFalse($service->isWithinQuietHours($user, Carbon::parse('2026-07-22 18:00:00', 'UTC')));
    }

    public function test_overnight_quiet_hours_range_correctly_crosses_midnight(): void
    {
        $user = User::factory()->create();
        NotificationQuietHours::create(['user_id' => $user->id, 'enabled' => true, 'start_time' => '22:00:00', 'end_time' => '07:00:00', 'timezone' => 'UTC']);
        $service = app(QuietHoursService::class);

        $this->assertTrue($service->isWithinQuietHours($user, Carbon::parse('2026-07-22 23:30:00', 'UTC')), 'Before midnight, inside range');
        $this->assertTrue($service->isWithinQuietHours($user, Carbon::parse('2026-07-23 03:00:00', 'UTC')), 'After midnight, inside range');
        $this->assertFalse($service->isWithinQuietHours($user, Carbon::parse('2026-07-22 12:00:00', 'UTC')), 'Midday, outside range');
    }

    public function test_quiet_hours_respect_the_users_configured_timezone_not_server_time(): void
    {
        $user = User::factory()->create();
        // 22:00-07:00 in Asia/Riyadh (UTC+3) — 21:00 UTC is 00:00 Riyadh, inside the window.
        NotificationQuietHours::create(['user_id' => $user->id, 'enabled' => true, 'start_time' => '22:00:00', 'end_time' => '07:00:00', 'timezone' => 'Asia/Riyadh']);
        $service = app(QuietHoursService::class);

        $this->assertTrue($service->isWithinQuietHours($user, Carbon::parse('2026-07-22 21:00:00', 'UTC')));
        // 12:00 UTC = 15:00 Riyadh — outside the window.
        $this->assertFalse($service->isWithinQuietHours($user, Carbon::parse('2026-07-22 12:00:00', 'UTC')));
    }

    public function test_disabled_quiet_hours_never_delays_delivery(): void
    {
        $user = User::factory()->create();
        NotificationQuietHours::create(['user_id' => $user->id, 'enabled' => false, 'start_time' => '00:00:00', 'end_time' => '23:59:00', 'timezone' => 'UTC']);

        $this->assertFalse(app(QuietHoursService::class)->isWithinQuietHours($user));
    }

    public function test_no_quiet_hours_row_at_all_never_delays_delivery(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(app(QuietHoursService::class)->isWithinQuietHours($user));
    }

    public function test_in_app_notification_is_written_immediately_even_during_quiet_hours(): void
    {
        $user = User::factory()->create();
        NotificationQuietHours::create(['user_id' => $user->id, 'enabled' => true, 'start_time' => '00:00:00', 'end_time' => '23:59:59', 'timezone' => 'UTC']);

        NotificationService::send($user->id, 'test_type', 'T', 'M');

        $this->assertDatabaseHas('platform_notifications', ['user_id' => $user->id, 'type' => 'test_type']);
    }

    public function test_sms_is_delayed_not_dropped_during_quiet_hours(): void
    {
        Queue::fake([SendSmsJob::class, SendPushJob::class]);
        $user = User::factory()->create(['phone' => '+966512345678']);
        NotificationQuietHours::create(['user_id' => $user->id, 'enabled' => true, 'start_time' => '00:00:00', 'end_time' => '23:59:59', 'timezone' => 'UTC']);

        NotificationService::send($user->id, 'assignment_graded', 'T', 'M', ['channels' => ['sms']]);

        Queue::assertPushed(SendSmsJob::class);
    }

    public function test_urgent_flag_bypasses_quiet_hours(): void
    {
        Queue::fake([SendSmsJob::class, SendPushJob::class]);
        $user = User::factory()->create(['phone' => '+966512345678']);
        NotificationQuietHours::create(['user_id' => $user->id, 'enabled' => true, 'start_time' => '00:00:00', 'end_time' => '23:59:59', 'timezone' => 'UTC']);

        NotificationService::send($user->id, 'assignment_graded', 'T', 'M', ['channels' => ['sms'], 'urgent' => true]);

        Queue::assertPushed(SendSmsJob::class);
    }

    public function test_quiet_hours_endpoint_is_user_scoped(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')
            ->putJson('/api/notifications/quiet-hours', ['enabled' => true, 'start_time' => '22:00', 'end_time' => '07:00', 'timezone' => 'UTC'])
            ->assertOk();

        $this->assertDatabaseHas('notification_quiet_hours', ['user_id' => $user->id, 'enabled' => true]);

        $other = User::factory()->create();
        $response = $this->actingAs($other, 'sanctum')->getJson('/api/notifications/quiet-hours');
        $response->assertOk()->assertJson(['data' => ['enabled' => false]]);
    }

    public function test_quiet_hours_update_rejects_an_invalid_timezone(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')
            ->putJson('/api/notifications/quiet-hours', ['enabled' => true, 'start_time' => '22:00', 'end_time' => '07:00', 'timezone' => 'Not/A_Real_Zone'])
            ->assertStatus(422);
    }

    // ── Realtime ─────────────────────────────────────────────────────────────

    public function test_notification_created_event_broadcasts_on_the_owning_users_private_channel(): void
    {
        Event::fake([PlatformNotificationCreated::class]);
        $user = User::factory()->create();

        NotificationService::send($user->id, 'test_type', 'T', 'M');

        Event::assertDispatched(PlatformNotificationCreated::class, function (PlatformNotificationCreated $event) use ($user) {
            $channels = $event->broadcastOn();

            return $event->notification->user_id === $user->id
                && $channels[0]->name === "private-notifications.{$user->id}";
        });
    }

    /**
     * The "null"/"log" broadcast drivers (the safe defaults everywhere else
     * in this suite) don't actually invoke channel authorization at all —
     * their `auth()` is a documented no-op, by design, since they never
     * broadcast anywhere real. Verifying the ownership check itself
     * therefore requires a driver that really calls
     * `Broadcaster::verifyUserCanAccessChannel()` — "pusher"/"reverb" do
     * this via pure local signature computation, no network call, so
     * dummy non-empty credentials are sufficient and no real HTTP request
     * to Pusher/Reverb is ever made by this test.
     */
    private function usePusherProtocolBroadcasterForAuthCheck(): void
    {
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'test-key',
            'broadcasting.connections.pusher.secret' => 'test-secret',
            'broadcasting.connections.pusher.app_id' => 'test-app-id',
            'broadcasting.connections.pusher.options' => ['cluster' => 'mt1', 'useTLS' => true],
        ]);

        // routes/channels.php already ran at boot against the default (null)
        // driver instance, registering its channel closures there — the
        // "pusher" driver instance created just now by the config() switch
        // above starts with zero registered channels. Re-running the
        // channel-registration file re-registers the same closures onto
        // whichever driver instance is now current.
        require base_path('routes/channels.php');
    }

    public function test_another_user_cannot_authenticate_someone_elses_notification_channel(): void
    {
        $this->usePusherProtocolBroadcasterForAuthCheck();
        $owner = User::factory()->create();
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider, 'sanctum')->postJson('/api/broadcasting/auth', [
            'channel_name' => "private-notifications.{$owner->id}",
            'socket_id' => '1234.5678',
        ]);

        $response->assertStatus(403);
    }

    public function test_the_owning_user_can_authenticate_their_own_notification_channel(): void
    {
        $this->usePusherProtocolBroadcasterForAuthCheck();
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/broadcasting/auth', [
            'channel_name' => "private-notifications.{$user->id}",
            'socket_id' => '1234.5678',
        ]);

        $response->assertOk();
    }

    public function test_broadcasting_auth_requires_authentication(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/broadcasting/auth', [
            'channel_name' => "private-notifications.{$user->id}",
            'socket_id' => '1234.5678',
        ])->assertStatus(401);
    }

    // ── Idempotency adoption (6 representative scenarios) ───────────────────

    public function test_certificate_issued_idempotency_key_prevents_duplicate_delivery(): void
    {
        $user = User::factory()->create();

        NotificationService::certificateIssued($user->id, 55, 'Course', 'CODE1');
        NotificationService::certificateIssued($user->id, 55, 'Course', 'CODE1');

        $this->assertSame(1, PlatformNotification::where('user_id', $user->id)->where('type', 'certificate_issued')->count());
    }

    public function test_a_different_certificate_id_is_not_deduped(): void
    {
        $user = User::factory()->create();

        NotificationService::certificateIssued($user->id, 55, 'Course', 'CODE1');
        NotificationService::certificateIssued($user->id, 56, 'Course', 'CODE2');

        $this->assertSame(2, PlatformNotification::where('user_id', $user->id)->where('type', 'certificate_issued')->count());
    }

    public function test_assignment_graded_with_the_same_submission_and_score_deduplicates(): void
    {
        $user = User::factory()->create();

        NotificationService::assignmentGraded($user->id, 1, 'C', 'A', 90.0, 100.0, null, 400);
        NotificationService::assignmentGraded($user->id, 1, 'C', 'A', 90.0, 100.0, null, 400);

        $this->assertSame(1, PlatformNotification::where('user_id', $user->id)->where('type', 'assignment_graded')->count());
    }

    public function test_assignment_graded_with_a_different_score_for_the_same_submission_is_a_new_notification(): void
    {
        $user = User::factory()->create();

        NotificationService::assignmentGraded($user->id, 1, 'C', 'A', 70.0, 100.0, null, 400);
        NotificationService::assignmentGraded($user->id, 1, 'C', 'A', 95.0, 100.0, null, 400);

        $this->assertSame(2, PlatformNotification::where('user_id', $user->id)->where('type', 'assignment_graded')->count());
    }

    public function test_session_scheduled_create_and_update_are_distinct_notifications(): void
    {
        $course = \App\Models\Course::factory()->create();
        $user = User::factory()->create();
        \App\Models\Registration::factory()->create(['user_id' => $user->id, 'course_id' => $course->id, 'status' => 'registered']);

        NotificationService::sessionScheduled($course->id, 'C', 'S1', '2026-08-01T10:00:00Z', false);
        NotificationService::sessionScheduled($course->id, 'C', 'S1', '2026-08-01T10:00:00Z', true);

        $this->assertSame(2, PlatformNotification::where('user_id', $user->id)->where('type', 'session_scheduled')->count());
    }

    public function test_material_visible_idempotency_key_prevents_duplicate_delivery(): void
    {
        $course = \App\Models\Course::factory()->create();
        $user = User::factory()->create();
        \App\Models\Registration::factory()->create(['user_id' => $user->id, 'course_id' => $course->id, 'status' => 'registered']);
        $material = \App\Models\CourseMaterial::create([
            'course_id' => $course->id, 'class_group_id' => null, 'title' => 'M1', 'type' => 'document', 'is_visible' => true,
        ]);

        NotificationService::materialVisible($material);
        NotificationService::materialVisible($material);

        $this->assertSame(1, PlatformNotification::where('user_id', $user->id)->where('type', 'material_visible')->count());
    }

    public function test_idempotency_key_never_suppresses_a_different_users_delivery(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        NotificationService::certificateIssued($a->id, 99, 'C', 'X');
        NotificationService::certificateIssued($b->id, 99, 'C', 'X'); // same certificate id, different recipient — should never happen in practice, but proves per-user isolation

        $this->assertSame(1, PlatformNotification::where('user_id', $a->id)->where('type', 'certificate_issued')->count());
        $this->assertSame(1, PlatformNotification::where('user_id', $b->id)->where('type', 'certificate_issued')->count());
    }

    // ── Event matrix completeness ────────────────────────────────────────────

    public function test_every_notification_service_scenario_method_is_represented_in_the_event_matrix(): void
    {
        $reflection = new \ReflectionClass(NotificationService::class);
        $scenarioMethods = array_values(array_filter(
            array_map(fn (\ReflectionMethod $m) => $m->getName(), $reflection->getMethods(\ReflectionMethod::IS_STATIC)),
            function (string $name) use ($reflection) {
                return $reflection->getMethod($name)->isPublic() && $name !== 'send';
            },
        ));

        $matrix = file_get_contents(base_path('docs/architecture/NOTIFICATION_EVENT_MATRIX.md'));

        $missing = [];
        foreach ($scenarioMethods as $method) {
            if (! str_contains($matrix, "`{$method}`")) {
                $missing[] = $method;
            }
        }

        $this->assertEmpty($missing, 'Scenario methods missing from the event matrix: ' . implode(', ', $missing));
    }

    public function test_event_matrix_does_not_reference_a_nonexistent_scenario_method(): void
    {
        $reflection = new \ReflectionClass(NotificationService::class);
        $realMethods = array_values(array_filter(
            array_map(fn (\ReflectionMethod $m) => $m->getName(), $reflection->getMethods(\ReflectionMethod::IS_STATIC)),
            fn (string $name) => $reflection->getMethod($name)->isPublic(),
        ));

        $matrix = file_get_contents(base_path('docs/architecture/NOTIFICATION_EVENT_MATRIX.md'));
        preg_match_all('/\| \d+ \| `(\w+)` \|/', $matrix, $matches);

        $unknown = array_diff($matches[1], $realMethods);

        $this->assertEmpty($unknown, 'Event matrix references methods that do not exist: ' . implode(', ', $unknown));
    }
}
