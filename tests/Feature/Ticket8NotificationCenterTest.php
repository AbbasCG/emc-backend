<?php

namespace Tests\Feature;

use App\Models\NotificationPreference;
use App\Models\PlatformNotification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ticket 8 audit found two real bugs in the existing notification pipeline
 * (not new features): NotificationPreference was never consulted before
 * sending, and the dedup check was a non-atomic SELECT-then-INSERT. Both
 * are fixed here. Also covers the new pin/archive/bulk-action endpoints
 * closing the "no pin/archive" gap in the notification center.
 */
class Ticket8NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    // ── Preference enforcement (real bug fix) ───────────────────────────────

    public function test_user_who_disabled_a_notification_type_does_not_receive_it(): void
    {
        $user = User::factory()->create();
        NotificationPreference::create([
            'user_id' => $user->id, 'channel' => 'in_app', 'type' => 'test_type', 'enabled' => false,
        ]);

        NotificationService::send($user->id, 'test_type', 'Title', 'Message');

        $this->assertDatabaseMissing('platform_notifications', ['user_id' => $user->id, 'type' => 'test_type']);
    }

    public function test_user_with_no_preference_row_still_receives_notifications_by_default(): void
    {
        $user = User::factory()->create();
        // No NotificationPreference row at all — must default to enabled (matches column default(true)).

        NotificationService::send($user->id, 'test_type', 'Title', 'Message');

        $this->assertDatabaseHas('platform_notifications', ['user_id' => $user->id, 'type' => 'test_type']);
    }

    public function test_disabling_a_different_type_does_not_block_this_one(): void
    {
        $user = User::factory()->create();
        NotificationPreference::create([
            'user_id' => $user->id, 'channel' => 'in_app', 'type' => 'other_type', 'enabled' => false,
        ]);

        NotificationService::send($user->id, 'test_type', 'Title', 'Message');

        $this->assertDatabaseHas('platform_notifications', ['user_id' => $user->id, 'type' => 'test_type']);
    }

    public function test_disabling_the_email_channel_does_not_block_the_in_app_notification(): void
    {
        $user = User::factory()->create();
        // Disabling a different channel for the same type must not affect in_app delivery.
        NotificationPreference::create([
            'user_id' => $user->id, 'channel' => 'email', 'type' => 'test_type', 'enabled' => false,
        ]);

        NotificationService::send($user->id, 'test_type', 'Title', 'Message');

        $this->assertDatabaseHas('platform_notifications', ['user_id' => $user->id, 'type' => 'test_type']);
    }

    public function test_preference_filtering_only_excludes_the_disabled_user_in_a_batch(): void
    {
        $blocked = User::factory()->create();
        $allowed = User::factory()->create();
        NotificationPreference::create(['user_id' => $blocked->id, 'channel' => 'in_app', 'type' => 'test_type', 'enabled' => false]);

        NotificationService::send([$blocked->id, $allowed->id], 'test_type', 'Title', 'Message');

        $this->assertDatabaseMissing('platform_notifications', ['user_id' => $blocked->id, 'type' => 'test_type']);
        $this->assertDatabaseHas('platform_notifications', ['user_id' => $allowed->id, 'type' => 'test_type']);
    }

    // ── Dedup constant wiring (regression proof the fix didn't break dedup) ──

    public function test_dedup_window_still_applies_after_the_atomicity_hardening(): void
    {
        $user = User::factory()->create();

        NotificationService::send($user->id, 'dup_type', 'T', 'M', ['entity_id' => 5]);
        NotificationService::send($user->id, 'dup_type', 'T', 'M', ['entity_id' => 5]);

        $this->assertSame(1, PlatformNotification::where('user_id', $user->id)->where('type', 'dup_type')->count());
    }

    // ── Pin/unpin ────────────────────────────────────────────────────────────

    public function test_user_can_pin_and_unpin_their_own_notification(): void
    {
        $user = User::factory()->create();
        $n = PlatformNotification::create(['user_id' => $user->id, 'type' => 't', 'title' => 'T', 'message' => 'M']);

        $this->actingAs($user, 'sanctum')->putJson("/api/notifications/{$n->id}/pin")->assertOk();
        $this->assertTrue($n->fresh()->pinned);

        $this->actingAs($user, 'sanctum')->putJson("/api/notifications/{$n->id}/unpin")->assertOk();
        $this->assertFalse($n->fresh()->pinned);
    }

    public function test_user_cannot_pin_another_users_notification(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();
        $n = PlatformNotification::create(['user_id' => $owner->id, 'type' => 't', 'title' => 'T', 'message' => 'M']);

        $this->actingAs($outsider, 'sanctum')->putJson("/api/notifications/{$n->id}/pin")->assertStatus(404);
        $this->assertFalse($n->fresh()->pinned);
    }

    public function test_pinned_notifications_are_ordered_first(): void
    {
        $user = User::factory()->create();
        $old = PlatformNotification::create(['user_id' => $user->id, 'type' => 't', 'title' => 'Old', 'message' => 'M', 'created_at' => now()->subDay()]);
        $new = PlatformNotification::create(['user_id' => $user->id, 'type' => 't', 'title' => 'New', 'message' => 'M']);
        $old->update(['pinned' => true]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications');
        $response->assertOk();
        $this->assertSame($old->id, $response->json('data.0.id'));
    }

    // ── Archive/unarchive ────────────────────────────────────────────────────

    public function test_user_can_archive_and_unarchive_their_own_notification(): void
    {
        $user = User::factory()->create();
        $n = PlatformNotification::create(['user_id' => $user->id, 'type' => 't', 'title' => 'T', 'message' => 'M']);

        $this->actingAs($user, 'sanctum')->putJson("/api/notifications/{$n->id}/archive")->assertOk();
        $this->assertNotNull($n->fresh()->archived_at);

        $this->actingAs($user, 'sanctum')->putJson("/api/notifications/{$n->id}/unarchive")->assertOk();
        $this->assertNull($n->fresh()->archived_at);
    }

    public function test_archived_notifications_are_hidden_from_the_default_list(): void
    {
        $user = User::factory()->create();
        $visible = PlatformNotification::create(['user_id' => $user->id, 'type' => 't', 'title' => 'Visible', 'message' => 'M']);
        $archived = PlatformNotification::create(['user_id' => $user->id, 'type' => 't', 'title' => 'Archived', 'message' => 'M', 'archived_at' => now()]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications');
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($visible->id));
        $this->assertFalse($ids->contains($archived->id));
    }

    public function test_archived_only_filter_returns_only_archived_notifications(): void
    {
        $user = User::factory()->create();
        PlatformNotification::create(['user_id' => $user->id, 'type' => 't', 'title' => 'Visible', 'message' => 'M']);
        $archived = PlatformNotification::create(['user_id' => $user->id, 'type' => 't', 'title' => 'Archived', 'message' => 'M', 'archived_at' => now()]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications?archived=1');
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertSame([$archived->id], $ids->all());
    }

    // ── Search ───────────────────────────────────────────────────────────────

    public function test_search_filters_by_title_or_message(): void
    {
        $user = User::factory()->create();
        $match = PlatformNotification::create(['user_id' => $user->id, 'type' => 't', 'title' => 'Assignment graded', 'message' => 'M']);
        PlatformNotification::create(['user_id' => $user->id, 'type' => 't', 'title' => 'Unrelated', 'message' => 'Nothing here']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications?search=graded');
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertSame([$match->id], $ids->all());
    }

    // ── Bulk actions ─────────────────────────────────────────────────────────

    public function test_bulk_archive_updates_only_the_requesting_users_own_notifications(): void
    {
        $user = User::factory()->create();
        $outsider = User::factory()->create();
        $n1 = PlatformNotification::create(['user_id' => $user->id, 'type' => 't', 'title' => 'A', 'message' => 'M']);
        $n2 = PlatformNotification::create(['user_id' => $user->id, 'type' => 't', 'title' => 'B', 'message' => 'M']);
        $foreign = PlatformNotification::create(['user_id' => $outsider->id, 'type' => 't', 'title' => 'C', 'message' => 'M']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/notifications/bulk', [
            'ids' => [$n1->id, $n2->id, $foreign->id], 'action' => 'archive',
        ]);

        $response->assertOk();
        $this->assertNotNull($n1->fresh()->archived_at);
        $this->assertNotNull($n2->fresh()->archived_at);
        $this->assertNull($foreign->fresh()->archived_at, 'Bulk action must never affect another user\'s notification.');
    }

    public function test_bulk_delete_removes_selected_notifications(): void
    {
        $user = User::factory()->create();
        $n1 = PlatformNotification::create(['user_id' => $user->id, 'type' => 't', 'title' => 'A', 'message' => 'M']);
        $n2 = PlatformNotification::create(['user_id' => $user->id, 'type' => 't', 'title' => 'B', 'message' => 'M']);

        $this->actingAs($user, 'sanctum')->postJson('/api/notifications/bulk', [
            'ids' => [$n1->id, $n2->id], 'action' => 'delete',
        ])->assertOk();

        $this->assertDatabaseMissing('platform_notifications', ['id' => $n1->id]);
        $this->assertDatabaseMissing('platform_notifications', ['id' => $n2->id]);
    }

    public function test_bulk_action_rejects_an_unsupported_action_value(): void
    {
        $user = User::factory()->create();
        $n = PlatformNotification::create(['user_id' => $user->id, 'type' => 't', 'title' => 'A', 'message' => 'M']);

        $this->actingAs($user, 'sanctum')->postJson('/api/notifications/bulk', [
            'ids' => [$n->id], 'action' => 'not_a_real_action',
        ])->assertStatus(422);
    }

    public function test_unauthenticated_request_cannot_access_notifications(): void
    {
        $this->getJson('/api/notifications')->assertStatus(401);
        $this->postJson('/api/notifications/bulk', ['ids' => [1], 'action' => 'delete'])->assertStatus(401);
    }
}
