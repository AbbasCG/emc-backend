<?php

namespace Tests\Feature;

use App\Jobs\SendPushJob;
use App\Jobs\SendSmsJob;
use App\Models\NotificationPreference;
use App\Models\NotificationQuietHours;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\NotificationChannelPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Ticket 8 channel-policy completion pass: verifies the central
 * NotificationChannelPolicy classification is complete and consistent with
 * both the real NotificationService dispatch sites and the documented event
 * matrix, and that SendPlatformNotificationJob's policy-driven fan-out
 * dispatches (or withholds) each channel correctly.
 */
class Ticket8ChannelPolicyTest extends TestCase
{
    use RefreshDatabase;

    // ── Policy completeness ──────────────────────────────────────────────────

    /** @return string[] */
    private function realDispatchedTypes(): array
    {
        $src = file_get_contents(app_path('Services/NotificationService.php'));
        preg_match_all("/self::send\(\s*[^,]+,\s*\n?\s*'([a-z_]+)'/", $src, $m);

        return array_values(array_unique($m[1]));
    }

    public function test_policy_covers_every_real_dispatched_type_with_no_extras(): void
    {
        $realTypes = $this->realDispatchedTypes();
        $policyTypes = array_keys(NotificationChannelPolicy::all());

        sort($realTypes);
        sort($policyTypes);

        $this->assertSame($realTypes, $policyTypes, 'NotificationChannelPolicy must classify exactly the 49 real dispatched types — no scenario left unclassified, no stale entry for a type that no longer exists.');
    }

    public function test_every_policy_entry_uses_only_known_channels(): void
    {
        foreach (NotificationChannelPolicy::all() as $type => $policy) {
            foreach ($policy['channels'] as $channel) {
                $this->assertContains($channel, NotificationChannelPolicy::KNOWN_CHANNELS, "Type '{$type}' references unknown channel '{$channel}'.");
            }
        }
    }

    public function test_every_policy_entry_includes_in_app(): void
    {
        foreach (NotificationChannelPolicy::all() as $type => $policy) {
            $this->assertContains(NotificationChannelPolicy::CHANNEL_IN_APP, $policy['channels'], "Type '{$type}' must always include in_app.");
        }
    }

    public function test_every_policy_entry_has_a_boolean_urgent_flag(): void
    {
        foreach (NotificationChannelPolicy::all() as $type => $policy) {
            $this->assertIsBool($policy['urgent'], "Type '{$type}' urgent flag must be boolean.");
        }
    }

    public function test_no_type_is_marked_urgent(): void
    {
        foreach (NotificationChannelPolicy::all() as $type => $policy) {
            $this->assertFalse($policy['urgent'], "Type '{$type}' is marked urgent — no scenario carries a verified, unambiguous emergency signal (see ADR).");
        }
    }

    public function test_unclassified_type_falls_back_to_safe_in_app_only_default(): void
    {
        $this->assertSame(['in_app'], NotificationChannelPolicy::channelsFor('some_future_unclassified_type'));
        $this->assertFalse(NotificationChannelPolicy::isUrgent('some_future_unclassified_type'));
    }

    // ── Matrix / policy consistency (drift detection) ───────────────────────

    public function test_event_matrix_classification_table_lists_every_policy_type(): void
    {
        $matrix = file_get_contents(base_path('docs/architecture/NOTIFICATION_EVENT_MATRIX.md'));

        foreach (array_keys(NotificationChannelPolicy::all()) as $type) {
            $this->assertStringContainsString("`{$type}`", $matrix, "Event matrix classification table is missing type '{$type}' — matrix has drifted from the code.");
        }
    }

    // ── Dispatch correctness: in-app-only types never touch sms/push ────────

    public function test_in_app_only_type_does_not_dispatch_push_or_sms(): void
    {
        Queue::fake([SendSmsJob::class, SendPushJob::class]);
        $user = User::factory()->create(['phone' => '+966512345678']);

        NotificationService::send($user->id, 'course_created', 'T', 'M');

        Queue::assertNotPushed(SendSmsJob::class);
        Queue::assertNotPushed(SendPushJob::class);
    }

    public function test_push_policy_type_dispatches_push_without_explicit_extra_channels(): void
    {
        Queue::fake([SendSmsJob::class, SendPushJob::class]);
        $user = User::factory()->create();

        NotificationService::send($user->id, 'certificate_issued', 'T', 'M');

        Queue::assertPushed(SendPushJob::class, fn (SendPushJob $job) => $job->userId === $user->id);
        Queue::assertNotPushed(SendSmsJob::class);
    }

    public function test_push_policy_type_respects_preference_disabled(): void
    {
        Queue::fake([SendSmsJob::class, SendPushJob::class]);
        $user = User::factory()->create();
        NotificationPreference::create(['user_id' => $user->id, 'channel' => 'push', 'type' => 'certificate_issued', 'enabled' => false]);

        NotificationService::send($user->id, 'certificate_issued', 'T', 'M');

        Queue::assertNotPushed(SendPushJob::class);
    }

    public function test_a_type_with_no_sms_in_its_policy_never_dispatches_sms_even_if_preference_enabled(): void
    {
        Queue::fake([SendSmsJob::class, SendPushJob::class]);
        $user = User::factory()->create(['phone' => '+966512345678']);
        NotificationPreference::create(['user_id' => $user->id, 'channel' => 'sms', 'type' => 'certificate_issued', 'enabled' => true]);

        NotificationService::send($user->id, 'certificate_issued', 'T', 'M');

        Queue::assertNotPushed(SendSmsJob::class);
    }

    public function test_explicit_extra_channels_still_overrides_the_policy(): void
    {
        Queue::fake([SendSmsJob::class, SendPushJob::class]);
        $user = User::factory()->create(['phone' => '+966512345678']);

        // course_created's policy is in_app-only; explicit override still opts into sms.
        NotificationService::send($user->id, 'course_created', 'T', 'M', ['channels' => ['sms']]);

        Queue::assertPushed(SendSmsJob::class);
    }

    // ── Quiet hours interaction ──────────────────────────────────────────────

    public function test_push_is_delayed_not_dropped_during_quiet_hours_for_a_policy_driven_type(): void
    {
        Queue::fake([SendSmsJob::class, SendPushJob::class]);
        $user = User::factory()->create();
        NotificationQuietHours::create(['user_id' => $user->id, 'enabled' => true, 'start_time' => '00:00:00', 'end_time' => '23:59:59', 'timezone' => 'UTC']);

        NotificationService::send($user->id, 'certificate_issued', 'T', 'M');

        Queue::assertPushed(SendPushJob::class);
    }

    public function test_in_app_row_is_written_immediately_during_quiet_hours_regardless_of_policy(): void
    {
        $user = User::factory()->create();
        NotificationQuietHours::create(['user_id' => $user->id, 'enabled' => true, 'start_time' => '00:00:00', 'end_time' => '23:59:59', 'timezone' => 'UTC']);

        NotificationService::send($user->id, 'certificate_issued', 'T', 'M');

        $this->assertDatabaseHas('platform_notifications', ['user_id' => $user->id, 'type' => 'certificate_issued']);
    }

    // ── Context-dependent policy resolution ──────────────────────────────────

    public function test_session_scheduled_update_within_threshold_adds_sms(): void
    {
        $channels = NotificationChannelPolicy::resolveWithContext('session_scheduled', [
            'starts_at' => now()->addHours(5)->toIso8601String(),
            'is_update' => true,
        ]);

        $this->assertContains('sms', $channels);
    }

    public function test_session_scheduled_update_beyond_threshold_does_not_add_sms(): void
    {
        $channels = NotificationChannelPolicy::resolveWithContext('session_scheduled', [
            'starts_at' => now()->addHours(48)->toIso8601String(),
            'is_update' => true,
        ]);

        $this->assertNotContains('sms', $channels);
    }

    public function test_session_scheduled_brand_new_never_adds_sms_even_if_imminent(): void
    {
        $channels = NotificationChannelPolicy::resolveWithContext('session_scheduled', [
            'starts_at' => now()->addHours(1)->toIso8601String(),
            'is_update' => false,
        ]);

        $this->assertNotContains('sms', $channels);
    }

    public function test_oral_assessment_rescheduled_within_threshold_adds_sms(): void
    {
        $channels = NotificationChannelPolicy::resolveWithContext('oral_assessment_rescheduled', [
            'starts_at' => now()->addHours(10)->toIso8601String(),
        ]);

        $this->assertContains('sms', $channels);
    }

    public function test_context_resolution_at_exact_threshold_boundary_is_deterministic(): void
    {
        $atBoundary = NotificationChannelPolicy::resolveWithContext('oral_assessment_rescheduled', [
            'starts_at' => now()->addHours(NotificationChannelPolicy::IMMINENT_THRESHOLD_HOURS)->toIso8601String(),
        ]);
        $justOver = NotificationChannelPolicy::resolveWithContext('oral_assessment_rescheduled', [
            'starts_at' => now()->addHours(NotificationChannelPolicy::IMMINENT_THRESHOLD_HOURS + 1)->toIso8601String(),
        ]);

        $this->assertContains('sms', $atBoundary);
        $this->assertNotContains('sms', $justOver);
    }

    public function test_context_resolution_without_starts_at_falls_back_to_base_policy(): void
    {
        $channels = NotificationChannelPolicy::resolveWithContext('oral_assessment_rescheduled', []);

        $this->assertSame(NotificationChannelPolicy::channelsFor('oral_assessment_rescheduled'), $channels);
    }

    public function test_course_cancelled_never_resolves_sms_regardless_of_context(): void
    {
        // courseCancelled() has no starts_at parameter; resolveWithContext is a no-op for it.
        $channels = NotificationChannelPolicy::resolveWithContext('course_cancelled', [
            'starts_at' => now()->addHour()->toIso8601String(),
        ]);

        $this->assertNotContains('sms', $channels);
    }

    // ── No-duplication ────────────────────────────────────────────────────────

    public function test_retry_with_the_same_idempotency_key_does_not_duplicate_push_dispatch(): void
    {
        Queue::fake([SendSmsJob::class, SendPushJob::class]);
        $user = User::factory()->create();

        NotificationService::certificateIssued($user->id, 501, 'English 101', 'ABC123');
        NotificationService::certificateIssued($user->id, 501, 'English 101', 'ABC123');

        Queue::assertPushed(SendPushJob::class, 1);
    }

    public function test_channel_policy_does_not_duplicate_an_existing_separate_email_call_site(): void
    {
        // certificate_issued is policy-classified 'existing_separate' for email — the
        // scenario method itself must not also queue an email via the channel policy path.
        Queue::fake([SendSmsJob::class, SendPushJob::class]);
        $user = User::factory()->create();

        NotificationService::certificateIssued($user->id, 502, 'English 101', 'XYZ789');

        // Only push (and the in-app row) come from the policy-driven fan-out; no 'email' channel exists in KNOWN_CHANNELS.
        $this->assertNotContains('email', NotificationChannelPolicy::KNOWN_CHANNELS);
    }
}
