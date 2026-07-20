<?php

namespace Tests\Feature;

use App\Jobs\SendPlatformNotificationJob;
use App\Models\PlatformNotification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * NotificationService::send() used to write PlatformNotification rows
 * synchronously inline within the HTTP request (Ticket 8 audit finding: no
 * queue anywhere in the in-app notification path, unlike email which was
 * already queued via SendLoggedEmailJob). It now dispatches
 * SendPlatformNotificationJob, which — under QUEUE_CONNECTION=sync (the test
 * default) — still executes immediately, preserving every existing caller's
 * behavior and assertions.
 */
class PlatformNotificationQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_dispatches_the_queued_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        NotificationService::send($user->id, 'test_type', 'Title', 'Message');

        Queue::assertPushed(SendPlatformNotificationJob::class, function ($job) use ($user) {
            return $job->userIds === [$user->id]
                && $job->type === 'test_type'
                && $job->title === 'Title'
                && $job->message === 'Message';
        });
    }

    public function test_send_still_creates_the_notification_row_under_sync_queue(): void
    {
        // QUEUE_CONNECTION=sync in phpunit.xml — no Queue::fake() here, so the
        // job runs for real, proving behavior is unchanged for existing callers.
        $user = User::factory()->create();

        NotificationService::send($user->id, 'test_type', 'Title', 'Message');

        $this->assertDatabaseHas('platform_notifications', [
            'user_id' => $user->id, 'type' => 'test_type', 'title' => 'Title',
        ]);
    }

    public function test_dedup_window_still_applies_through_the_job(): void
    {
        $user = User::factory()->create();

        NotificationService::send($user->id, 'dup_type', 'T', 'M', ['entity_id' => 5]);
        NotificationService::send($user->id, 'dup_type', 'T', 'M', ['entity_id' => 5]);

        $this->assertSame(1, PlatformNotification::where('user_id', $user->id)
            ->where('type', 'dup_type')->where('entity_id', 5)->count());
    }

    public function test_unsafe_meta_url_scheme_is_still_dropped(): void
    {
        $user = User::factory()->create();

        NotificationService::send($user->id, 'link_type', 'T', 'M', [
            'meta_url' => 'javascript:alert(1)',
        ]);

        $row = PlatformNotification::where('user_id', $user->id)->where('type', 'link_type')->first();
        $this->assertNull($row->meta_url);
    }
}
