<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function makeSuperAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    private function makeStudent(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['role' => 'student', 'is_active' => true], $attrs));
    }

    // ─── Profile (self) deletion ──────────────────────────────────────────────

    public function test_self_delete_preserves_name_and_email(): void
    {
        $user = $this->makeStudent(['name' => 'John Doe', 'email' => 'john@example.com']);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/profile')
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('users', [
            'id'    => $user->id,
            'name'  => 'John Doe',
            'email' => 'john@example.com',
        ]);
    }

    public function test_self_delete_sets_is_active_false_and_soft_deletes(): void
    {
        $user = $this->makeStudent();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/profile')
            ->assertOk();

        $row = User::withTrashed()->find($user->id);

        $this->assertFalse((bool) $row->is_active);
        $this->assertNotNull($row->deleted_at);
    }

    // ─── Login blocked for inactive / deleted users ───────────────────────────

    public function test_deleted_user_cannot_login(): void
    {
        $user = $this->makeStudent(['password' => bcrypt('secret123')]);
        $user->update(['is_active' => false]);
        $user->delete();

        $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'secret123',
        ])->assertStatus(403)
          ->assertJson(['success' => false, 'message' => 'Account is suspended.']);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = $this->makeStudent(['password' => bcrypt('pass1234'), 'is_active' => false]);

        $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'pass1234',
        ])->assertStatus(403)
          ->assertJson(['success' => false, 'message' => 'Account is suspended.']);
    }

    // ─── Admin user list status filter ───────────────────────────────────────

    public function test_admin_user_list_status_filter(): void
    {
        $admin   = $this->makeSuperAdmin();
        $active  = $this->makeStudent(['name' => 'Active User',   'is_active' => true]);
        $inactive = $this->makeStudent(['name' => 'Inactive User', 'is_active' => false]);
        $deleted  = $this->makeStudent(['name' => 'Deleted User',  'is_active' => false]);
        $deleted->delete();

        // active filter — should include active, exclude inactive and deleted
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users?status=active')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Active User'])
            ->assertJsonMissing(['name' => 'Inactive User'])
            ->assertJsonMissing(['name' => 'Deleted User']);

        // deleted filter
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users?status=deleted')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Deleted User'])
            ->assertJsonMissing(['name' => 'Active User']);
    }

    // ─── Admin restore ────────────────────────────────────────────────────────

    public function test_admin_can_restore_deleted_user(): void
    {
        $admin = $this->makeSuperAdmin();
        $user  = $this->makeStudent(['name' => 'Restored User', 'is_active' => true]);
        $user->update(['is_active' => false]);
        $user->delete();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/users/{$user->id}/restore")
            ->assertOk()
            ->assertJson(['success' => true]);

        $row = User::find($user->id);
        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->is_active);
        $this->assertNull($row->deleted_at);
    }

    public function test_restored_user_can_login_again(): void
    {
        $admin = $this->makeSuperAdmin();
        $user  = $this->makeStudent(['password' => bcrypt('secret123'), 'is_active' => true]);

        // Delete the user
        $user->update(['is_active' => false]);
        $user->delete();

        // Confirm login is blocked
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret123'])
            ->assertStatus(403);

        // Restore
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/users/{$user->id}/restore")
            ->assertOk();

        // Login should now succeed
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret123'])
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['token']]);
    }

    public function test_deleted_user_absent_from_active_list(): void
    {
        $admin   = $this->makeSuperAdmin();
        $active  = $this->makeStudent(['name' => 'Still Active']);
        $deleted = $this->makeStudent(['name' => 'Gone User']);
        $deleted->update(['is_active' => false]);
        $deleted->delete();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users?status=active')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Still Active'])
            ->assertJsonMissing(['name' => 'Gone User']);
    }
}
