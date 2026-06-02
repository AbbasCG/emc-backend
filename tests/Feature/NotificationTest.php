<?php

namespace Tests\Feature;

use App\Models\PlatformNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helper ───────────────────────────────────────────────────────────────

    private function notify(User $user, array $overrides = []): PlatformNotification
    {
        return PlatformNotification::create(array_merge([
            'user_id' => $user->id,
            'type'    => 'info',
            'title'   => 'Test Notification',
            'message' => 'This is a test.',
            'is_read' => false,
        ], $overrides));
    }

    // ─── List ─────────────────────────────────────────────────────────────────

    public function test_user_can_list_own_notifications(): void
    {
        $user = User::factory()->create();
        $this->notify($user);
        $this->notify($user);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_notifications_are_scoped_to_authenticated_user(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->notify($userA);

        $response = $this->actingAs($userB, 'sanctum')
            ->getJson('/api/notifications');

        $response->assertOk();

        // UserB has no notifications — list must be empty
        $items = $response->json('data.data') ?? $response->json('data') ?? [];
        $this->assertEmpty($items, 'UserB must not see UserA\'s notifications');
    }

    public function test_unauthenticated_cannot_list_notifications(): void
    {
        $this->getJson('/api/notifications')
            ->assertStatus(401);
    }

    // ─── Unread count ─────────────────────────────────────────────────────────

    public function test_unread_count_reflects_unread_notifications(): void
    {
        $user = User::factory()->create();
        $this->notify($user, ['is_read' => false]);
        $this->notify($user, ['is_read' => false]);
        $this->notify($user, ['is_read' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/notifications/unread-count');

        $response->assertOk()
            ->assertJson(['success' => true]);

        $count = $response->json('unread_count') ?? $response->json('data.count') ?? $response->json('count');
        $this->assertEquals(2, $count, 'Unread count should be 2');
    }

    public function test_unread_count_is_zero_when_all_read(): void
    {
        $user = User::factory()->create();
        $this->notify($user, ['is_read' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/notifications/unread-count');

        $response->assertOk();
        $count = $response->json('unread_count') ?? $response->json('data.count') ?? $response->json('count');
        $this->assertEquals(0, $count);
    }

    // ─── Mark as read ─────────────────────────────────────────────────────────

    public function test_user_can_mark_single_notification_as_read(): void
    {
        $user         = User::factory()->create();
        $notification = $this->notify($user, ['is_read' => false]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('platform_notifications', [
            'id'      => $notification->id,
            'is_read' => true,
        ]);
    }

    public function test_mark_read_sets_read_at_timestamp(): void
    {
        $user         = User::factory()->create();
        $notification = $this->notify($user, ['is_read' => false, 'read_at' => null]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/notifications/{$notification->id}/read")
            ->assertOk();

        $notification->refresh();
        $this->assertNotNull($notification->read_at, 'read_at must be set after marking as read');
    }

    public function test_user_cannot_mark_another_users_notification_as_read(): void
    {
        $userA        = User::factory()->create();
        $userB        = User::factory()->create();
        $notification = $this->notify($userA);

        $response = $this->actingAs($userB, 'sanctum')
            ->putJson("/api/notifications/{$notification->id}/read");

        $this->assertContains($response->status(), [403, 404],
            "Expected 403/404 for cross-user notification mark-read, got {$response->status()}");
    }

    // ─── Mark all read ────────────────────────────────────────────────────────

    public function test_user_can_mark_all_notifications_as_read(): void
    {
        $user = User::factory()->create();
        $this->notify($user, ['is_read' => false]);
        $this->notify($user, ['is_read' => false]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJson(['success' => true]);

        $remaining = PlatformNotification::where('user_id', $user->id)
            ->where('is_read', false)
            ->count();

        $this->assertEquals(0, $remaining, 'All notifications must be marked as read');
    }

    public function test_mark_all_read_does_not_affect_other_users(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $nA = $this->notify($userA, ['is_read' => false]);
        $this->notify($userB, ['is_read' => false]);

        // UserA marks all their own as read
        $this->actingAs($userA, 'sanctum')
            ->putJson('/api/notifications/read-all')
            ->assertOk();

        // UserB's notification must remain unread
        $this->assertDatabaseHas('platform_notifications', [
            'user_id' => $userB->id,
            'is_read' => false,
        ]);
    }

    // ─── Delete ───────────────────────────────────────────────────────────────

    public function test_user_can_delete_own_notification(): void
    {
        $user         = User::factory()->create();
        $notification = $this->notify($user);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/notifications/{$notification->id}")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('platform_notifications', ['id' => $notification->id]);
    }

    public function test_user_cannot_delete_another_users_notification(): void
    {
        $userA        = User::factory()->create();
        $userB        = User::factory()->create();
        $notification = $this->notify($userA);

        $response = $this->actingAs($userB, 'sanctum')
            ->deleteJson("/api/notifications/{$notification->id}");

        $this->assertContains($response->status(), [403, 404],
            "Expected 403/404 for cross-user notification delete, got {$response->status()}");

        // Notification must still exist
        $this->assertDatabaseHas('platform_notifications', ['id' => $notification->id]);
    }

    // ─── Response structure ───────────────────────────────────────────────────

    public function test_notification_list_response_has_required_fields(): void
    {
        $user = User::factory()->create();
        $this->notify($user, ['action_url' => '/courses/1', 'type' => 'success']);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/notifications');

        $response->assertOk()
            ->assertJsonStructure(['success', 'data']);
    }
}
