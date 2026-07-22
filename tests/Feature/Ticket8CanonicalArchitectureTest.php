<?php

namespace Tests\Feature;

use App\Models\PlatformNotification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ticket 8 canonical-architecture pass. Covers: the migrated
 * GenerateReportJob/IssueCertificateJob write paths now landing in
 * platform_notifications (not the legacy built-in table), the mobile
 * notification endpoints now reading/writing the canonical store, the
 * legacy-data migration command, and the new deterministic idempotency-key
 * dedup path in SendPlatformNotificationJob.
 */
class Ticket8CanonicalArchitectureTest extends TestCase
{
    use RefreshDatabase;

    // ── Canonical write path ────────────────────────────────────────────────

    public function test_report_ready_scenario_writes_to_platform_notifications(): void
    {
        $user = User::factory()->create();

        NotificationService::reportReady($user->id, 42);

        $this->assertDatabaseHas('platform_notifications', [
            'user_id' => $user->id, 'type' => 'report_ready', 'entity_id' => 42,
        ]);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_certificate_issued_scenario_writes_to_platform_notifications(): void
    {
        $user = User::factory()->create();

        NotificationService::certificateIssued($user->id, 7, 'English 101', 'ABC123');

        $this->assertDatabaseHas('platform_notifications', [
            'user_id' => $user->id, 'type' => 'certificate_issued', 'entity_id' => 7,
        ]);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_no_live_application_caller_dispatches_the_deprecated_send_notification_job(): void
    {
        $matches = [];
        $files = array_filter(
            iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS))),
            fn ($f) => $f->getExtension() === 'php' && $f->getFilename() !== 'SendNotificationJob.php',
        );

        foreach ($files as $file) {
            $contents = file_get_contents($file->getPathname());
            if (str_contains($contents, 'SendNotificationJob::dispatch')) {
                $matches[] = $file->getPathname();
            }
        }

        $this->assertEmpty($matches, 'SendNotificationJob is deprecated — no app/ file should dispatch it: ' . implode(', ', $matches));
    }

    // ── Mobile migration ─────────────────────────────────────────────────────

    public function test_mobile_notifications_endpoint_reads_the_canonical_store(): void
    {
        $user = User::factory()->create();
        PlatformNotification::create(['user_id' => $user->id, 'type' => 't', 'title' => 'Hi', 'message' => 'M']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/mobile/notifications');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Hi', $response->json('data.0.data.title'));
        $this->assertSame(1, $response->json('meta.unread'));
    }

    public function test_mobile_notifications_endpoint_never_returns_another_users_notification(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        PlatformNotification::create(['user_id' => $other->id, 'type' => 't', 'title' => 'Not yours', 'message' => 'M']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/mobile/notifications');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_mobile_notifications_endpoint_excludes_archived(): void
    {
        $user = User::factory()->create();
        PlatformNotification::create(['user_id' => $user->id, 'type' => 't', 'title' => 'Active', 'message' => 'M']);
        PlatformNotification::create(['user_id' => $user->id, 'type' => 't', 'title' => 'Archived', 'message' => 'M', 'archived_at' => now()]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/mobile/notifications');

        $this->assertCount(1, $response->json('data'));
    }

    public function test_mobile_mark_read_is_ownership_scoped(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $n = PlatformNotification::create(['user_id' => $owner->id, 'type' => 't', 'title' => 'T', 'message' => 'M']);

        $this->actingAs($outsider, 'sanctum')->putJson("/api/mobile/notifications/{$n->id}/read")->assertStatus(404);
        $this->assertFalse($n->fresh()->is_read);

        $this->actingAs($owner, 'sanctum')->putJson("/api/mobile/notifications/{$n->id}/read")->assertOk();
        $this->assertTrue($n->fresh()->is_read);
    }

    public function test_mobile_unread_count_matches_the_web_notification_center(): void
    {
        $user = User::factory()->create();
        PlatformNotification::create(['user_id' => $user->id, 'type' => 't', 'title' => 'A', 'message' => 'M']);
        PlatformNotification::create(['user_id' => $user->id, 'type' => 't', 'title' => 'B', 'message' => 'M', 'is_read' => true, 'read_at' => now()]);

        $mobile = $this->actingAs($user, 'sanctum')->getJson('/api/mobile/notifications');
        $web = $this->actingAs($user, 'sanctum')->getJson('/api/notifications');

        $this->assertSame(1, $mobile->json('meta.unread'));
        $this->assertSame($web->json('unread_count'), $mobile->json('meta.unread'));
    }

    // ── Legacy migration command ────────────────────────────────────────────

    public function test_dry_run_migration_changes_nothing(): void
    {
        $user = User::factory()->create();
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(), 'type' => 'legacy_type', 'notifiable_type' => User::class,
            'notifiable_id' => $user->id, 'data' => json_encode(['title' => 'Old', 'message' => 'Msg']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('notifications:migrate-legacy', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseCount('platform_notifications', 0);
    }

    public function test_migration_preserves_mapped_fields_including_read_at_and_created_at(): void
    {
        $user = User::factory()->create();
        $readAt = now()->subDay();
        $createdAt = now()->subDays(2);
        $legacyId = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $legacyId, 'type' => 'legacy_type', 'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(['title' => 'Old Title', 'message' => 'Old Message', 'url' => 'https://example.com/x']),
            'read_at' => $readAt, 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);

        $this->artisan('notifications:migrate-legacy')->assertSuccessful();

        $migrated = PlatformNotification::where('legacy_source_id', $legacyId)->first();
        $this->assertNotNull($migrated);
        $this->assertSame($user->id, $migrated->user_id);
        $this->assertSame('Old Title', $migrated->title);
        $this->assertSame('Old Message', $migrated->message);
        $this->assertSame('https://example.com/x', $migrated->meta_url);
        $this->assertTrue($migrated->is_read);
        $this->assertEquals($readAt->timestamp, $migrated->read_at->timestamp);
        $this->assertEquals($createdAt->timestamp, $migrated->created_at->timestamp);
    }

    public function test_migration_rerun_is_idempotent_and_never_duplicates(): void
    {
        $user = User::factory()->create();
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(), 'type' => 't', 'notifiable_type' => User::class,
            'notifiable_id' => $user->id, 'data' => json_encode(['title' => 'T', 'message' => 'M']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('notifications:migrate-legacy')->assertSuccessful();
        $this->assertDatabaseCount('platform_notifications', 1);

        $this->artisan('notifications:migrate-legacy')->assertSuccessful();
        $this->assertDatabaseCount('platform_notifications', 1);
    }

    public function test_migration_reports_unmappable_rows_without_fabricating_data(): void
    {
        $user = User::factory()->create();
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(), 'type' => 't', 'notifiable_type' => User::class,
            'notifiable_id' => $user->id, 'data' => json_encode(['foo' => 'bar']), // no title/message
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('notifications:migrate-legacy')->assertSuccessful();

        $this->assertDatabaseCount('platform_notifications', 0);
        $this->assertDatabaseCount('notifications', 1); // never deleted
    }

    public function test_migration_never_deletes_source_rows(): void
    {
        $user = User::factory()->create();
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(), 'type' => 't', 'notifiable_type' => User::class,
            'notifiable_id' => $user->id, 'data' => json_encode(['title' => 'T', 'message' => 'M']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('notifications:migrate-legacy')->assertSuccessful();

        $this->assertDatabaseCount('notifications', 1);
    }

    // ── Deterministic idempotency key ───────────────────────────────────────

    public function test_exact_duplicate_semantic_event_with_the_same_idempotency_key_creates_one_row(): void
    {
        $user = User::factory()->create();

        NotificationService::send($user->id, 'x', 'T', 'M', ['idempotency_key' => 'assignment:5:graded:v1']);
        NotificationService::send($user->id, 'x', 'T', 'M', ['idempotency_key' => 'assignment:5:graded:v1']);

        $this->assertSame(1, PlatformNotification::where('user_id', $user->id)->where('type', 'x')->count());
    }

    public function test_a_distinct_idempotency_key_for_a_legitimate_repeat_event_creates_a_second_row(): void
    {
        $user = User::factory()->create();

        NotificationService::send($user->id, 'x', 'T', 'M', ['idempotency_key' => 'assignment:5:resubmit:1']);
        NotificationService::send($user->id, 'x', 'T', 'M', ['idempotency_key' => 'assignment:5:resubmit:2']);

        $this->assertSame(2, PlatformNotification::where('user_id', $user->id)->where('type', 'x')->count());
    }

    public function test_idempotency_key_dedup_is_per_user_two_recipients_each_get_their_own_row(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        NotificationService::send([$a->id, $b->id], 'x', 'T', 'M', ['idempotency_key' => 'batch:1']);

        $this->assertSame(1, PlatformNotification::where('user_id', $a->id)->where('type', 'x')->count());
        $this->assertSame(1, PlatformNotification::where('user_id', $b->id)->where('type', 'x')->count());
    }

    public function test_disabled_preference_still_blocks_delivery_even_with_an_idempotency_key(): void
    {
        $user = User::factory()->create();
        \App\Models\NotificationPreference::create([
            'user_id' => $user->id, 'channel' => 'in_app', 'type' => 'x', 'enabled' => false,
        ]);

        NotificationService::send($user->id, 'x', 'T', 'M', ['idempotency_key' => 'k1']);

        $this->assertDatabaseMissing('platform_notifications', ['user_id' => $user->id, 'type' => 'x']);
    }

    public function test_retry_with_the_same_idempotency_key_does_not_duplicate_after_a_prior_successful_send(): void
    {
        $user = User::factory()->create();

        NotificationService::send($user->id, 'x', 'T', 'M', ['idempotency_key' => 'retry-key']);
        // Simulates a queue worker retry of the same logical event.
        NotificationService::send($user->id, 'x', 'T', 'M', ['idempotency_key' => 'retry-key']);
        NotificationService::send($user->id, 'x', 'T', 'M', ['idempotency_key' => 'retry-key']);

        $this->assertSame(1, PlatformNotification::where('user_id', $user->id)->where('type', 'x')->count());
    }

    public function test_omitting_idempotency_key_preserves_the_original_time_window_dedup_behavior(): void
    {
        $user = User::factory()->create();

        NotificationService::send($user->id, 'dup_type', 'T', 'M', ['entity_id' => 9]);
        NotificationService::send($user->id, 'dup_type', 'T', 'M', ['entity_id' => 9]);

        $this->assertSame(1, PlatformNotification::where('user_id', $user->id)->where('type', 'dup_type')->count());
    }
}
