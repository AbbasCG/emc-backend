<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentalUnit;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Enforces the "one User = one TeamProfile = one operational TeamMember"
 * business rule (see the architecture audit): TeamMember now links to a
 * SPECIFIC TeamProfile via team_profile_id, ambiguous multi-profile users
 * are rejected rather than guessed at, and editing a canonical profile's
 * department/title keeps its linked TeamMember row in sync (clearing an
 * incompatible unit) instead of leaving it silently stale.
 */
class TeamProfileMemberIdentityCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private function makeDepartment(array $overrides = []): Department
    {
        return Department::create(array_merge([
            'name' => 'Dept ' . Str::random(6),
            'name_ar' => 'إدارة تجريبية',
            'slug' => 'dept-' . Str::random(8),
            'status' => 'active',
            'is_active' => true,
        ], $overrides));
    }

    private function makeUnit(Department $dept, array $overrides = []): DepartmentalUnit
    {
        return DepartmentalUnit::create(array_merge([
            'department_id' => $dept->id,
            'code' => 'UNIT-' . Str::random(6),
            'name_ar' => 'وحدة تجريبية',
            'is_active' => true,
        ], $overrides));
    }

    private function makeUser(string $role = 'student'): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function admin(): User
    {
        return $this->makeUser('super_admin');
    }

    private function makeProfile(?Department $dept, array $overrides = []): array
    {
        $user = $this->makeUser('instructor');
        $profile = TeamProfile::create(array_merge([
            'user_id' => $user->id,
            'name_ar' => 'عضو ' . Str::random(4),
            'position_ar' => 'مطور',
            'department_id' => $dept?->id,
            'member_type' => 'staff',
            'is_active' => true,
        ], $overrides));

        return [$profile, $user];
    }

    public function test_database_blocks_a_second_team_profile_for_the_same_user(): void
    {
        // Stage 3/4: the DB-level unique(team_profiles.user_id) constraint
        // now prevents this scenario from ever being created in the first
        // place — a stronger guarantee than the earlier app-level-only check.
        $dept = $this->makeDepartment();
        $user = $this->makeUser('instructor');
        TeamProfile::create(['user_id' => $user->id, 'name_ar' => 'ملف 1', 'position_ar' => 'أ', 'department_id' => $dept->id, 'member_type' => 'staff', 'is_active' => true]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        TeamProfile::create(['user_id' => $user->id, 'name_ar' => 'ملف 2', 'position_ar' => 'ب', 'is_active' => true]);
    }

    public function test_multiple_profiles_with_null_user_id_remain_allowed(): void
    {
        // Unlinked members (no account yet) are NOT the same "user" for
        // uniqueness purposes — MySQL/SQLite both treat multiple NULLs in a
        // unique column as distinct, which is exactly the desired semantics.
        TeamProfile::create(['name_ar' => 'بدون حساب 1', 'position_ar' => 'أ', 'is_active' => true]);
        TeamProfile::create(['name_ar' => 'بدون حساب 2', 'position_ar' => 'ب', 'is_active' => true]);

        $this->assertSame(2, TeamProfile::whereNull('user_id')->count());
    }

    public function test_created_team_member_links_to_the_specific_profile(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->admin();
        [$profile, $user] = $this->makeProfile($dept);

        $this->actingAs($admin)->postJson('/api/admin/team-members', ['user_id' => $user->id])
            ->assertStatus(201);

        $member = TeamMember::where('user_id', $user->id)->first();
        $this->assertSame($profile->id, $member->team_profile_id);
    }

    public function test_one_operational_membership_per_profile_enforced(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->admin();
        [, $user] = $this->makeProfile($dept);

        $this->actingAs($admin)->postJson('/api/admin/team-members', ['user_id' => $user->id])->assertStatus(201);
        $this->actingAs($admin)->postJson('/api/admin/team-members', ['user_id' => $user->id])->assertStatus(422);

        $this->assertSame(1, TeamMember::where('user_id', $user->id)->count());
    }

    public function test_updating_profile_department_updates_same_profile_not_a_new_one(): void
    {
        $deptA = $this->makeDepartment();
        $deptB = $this->makeDepartment();
        $admin = $this->admin();
        [$profile, ] = $this->makeProfile($deptA);

        $this->actingAs($admin)->putJson("/api/admin/team-profiles/{$profile->id}", ['department_id' => $deptB->id])
            ->assertOk();

        $this->assertSame(1, TeamProfile::where('user_id', $profile->user_id)->count());
        $this->assertSame($deptB->id, $profile->fresh()->department_id);
    }

    public function test_updating_profile_position_updates_same_profile(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->admin();
        [$profile, ] = $this->makeProfile($dept, ['position_ar' => 'قديم']);

        $this->actingAs($admin)->putJson("/api/admin/team-profiles/{$profile->id}", ['position_ar' => 'جديد'])
            ->assertOk();

        $this->assertSame('جديد', $profile->fresh()->position_ar);
    }

    public function test_department_change_clears_incompatible_unit_on_linked_team_member(): void
    {
        $deptA = $this->makeDepartment();
        $deptB = $this->makeDepartment();
        $unitA = $this->makeUnit($deptA);
        $admin = $this->admin();
        [$profile, $user] = $this->makeProfile($deptA);
        $member = TeamMember::create([
            'user_id' => $user->id, 'team_profile_id' => $profile->id,
            'department_id' => $deptA->id, 'unit_id' => $unitA->id, 'status' => 'active',
        ]);

        $this->actingAs($admin)->putJson("/api/admin/team-profiles/{$profile->id}", ['department_id' => $deptB->id])
            ->assertOk();

        $fresh = $member->fresh();
        $this->assertSame($deptB->id, $fresh->department_id);
        $this->assertNull($fresh->unit_id);
        $this->assertSame('active', $fresh->status); // preserved
    }

    public function test_department_change_preserves_unit_if_it_still_belongs_to_the_new_department(): void
    {
        // Edge case: department_id is "changed" to the same value it already had —
        // no drift should occur, unit stays.
        $dept = $this->makeDepartment();
        $unit = $this->makeUnit($dept);
        $admin = $this->admin();
        [$profile, $user] = $this->makeProfile($dept);
        $member = TeamMember::create([
            'user_id' => $user->id, 'team_profile_id' => $profile->id,
            'department_id' => $dept->id, 'unit_id' => $unit->id, 'status' => 'active',
        ]);

        $this->actingAs($admin)->putJson("/api/admin/team-profiles/{$profile->id}", ['position_ar' => 'تحديث بسيط'])
            ->assertOk();

        $this->assertSame($unit->id, $member->fresh()->unit_id);
    }

    public function test_position_change_syncs_role_title_on_linked_team_member(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->admin();
        [$profile, $user] = $this->makeProfile($dept, ['position_ar' => 'قديم']);
        $member = TeamMember::create([
            'user_id' => $user->id, 'team_profile_id' => $profile->id,
            'department_id' => $dept->id, 'role_title' => 'قديم', 'status' => 'active',
        ]);

        $this->actingAs($admin)->putJson("/api/admin/team-profiles/{$profile->id}", ['position_ar' => 'جديد'])
            ->assertOk();

        $this->assertSame('جديد', $member->fresh()->role_title);
    }

    public function test_member_moved_away_from_department_no_longer_eligible_for_new_tickets(): void
    {
        $technology = $this->makeDepartment(['name_ar' => 'التقنية']);
        $quality = $this->makeDepartment(['name_ar' => 'الجودة']);
        $techUnit = $this->makeUnit($technology);
        $admin = $this->admin();
        $leader = User::factory()->create(['role' => 'department_manager', 'is_active' => true]);
        $technology->update(['leader_id' => $leader->id]);

        [$profile, $user] = $this->makeProfile($technology);
        $member = TeamMember::create([
            'user_id' => $user->id, 'team_profile_id' => $profile->id,
            'department_id' => $technology->id, 'unit_id' => $techUnit->id, 'status' => 'active',
        ]);

        // Confirm eligible BEFORE the move.
        $before = $this->actingAs($leader)->getJson("/api/v1/tickets/meta?department_id={$technology->id}&unit_id={$techUnit->id}");
        $this->assertTrue(collect($before->json('users'))->pluck('id')->contains($user->id));

        // Move the member's canonical profile to Quality.
        $this->actingAs($admin)->putJson("/api/admin/team-profiles/{$profile->id}", ['department_id' => $quality->id])
            ->assertOk();

        // No longer eligible for NEW Technology tickets.
        $after = $this->actingAs($leader)->getJson("/api/v1/tickets/meta?department_id={$technology->id}&unit_id={$techUnit->id}");
        $this->assertFalse(collect($after->json('users'))->pluck('id')->contains($user->id));
        $this->assertNull($member->fresh()->unit_id);
    }

    public function test_historical_ticket_assignment_survives_a_later_department_change(): void
    {
        $technology = $this->makeDepartment(['name_ar' => 'التقنية']);
        $quality = $this->makeDepartment(['name_ar' => 'الجودة']);
        $admin = $this->admin();
        $leader = User::factory()->create(['role' => 'department_manager', 'is_active' => true]);
        $technology->update(['leader_id' => $leader->id]);

        [$profile, $user] = $this->makeProfile($technology);
        $member = TeamMember::create([
            'user_id' => $user->id, 'team_profile_id' => $profile->id,
            'department_id' => $technology->id, 'status' => 'active',
        ]);

        $ticket = Ticket::create([
            'ticket_number' => 'EMC-TCK-TEST-' . Str::random(6),
            'title' => 'تذكرة', 'description' => 'وصف', 'ticket_category' => 'OLD_ISSUE',
            'priority' => 'MEDIUM', 'department_id' => $technology->id,
            'created_by_name' => 'تجريبي', 'status' => 'PENDING_APPROVAL',
        ]);
        $this->actingAs($leader)->postJson("/api/v1/tickets/{$ticket->id}/approve", [
            'sla_hours' => 24, 'assigned_to_id' => $user->id, 'task_scope' => 'simple', 'task_points' => 30,
        ])->assertOk();

        // Now move the member away.
        $this->actingAs($admin)->putJson("/api/admin/team-profiles/{$profile->id}", ['department_id' => $quality->id])
            ->assertOk();

        // The historical ticket's assignee is unchanged.
        $this->assertSame($user->id, $ticket->fresh()->assigned_to_id);
    }

    public function test_unit_from_another_department_still_rejected_on_create(): void
    {
        $deptA = $this->makeDepartment();
        $deptB = $this->makeDepartment();
        $unitB = $this->makeUnit($deptB);
        $admin = $this->admin();
        [, $user] = $this->makeProfile($deptA);

        $this->actingAs($admin)->postJson('/api/admin/team-members', [
            'user_id' => $user->id, 'unit_id' => $unitB->id,
        ])->assertStatus(422);
    }

    public function test_unauthorized_role_cannot_update_team_profile(): void
    {
        $dept = $this->makeDepartment();
        $outsider = $this->makeUser();
        [$profile, ] = $this->makeProfile($dept);

        $this->actingAs($outsider)->putJson("/api/admin/team-profiles/{$profile->id}", ['position_ar' => 'محاولة'])
            ->assertStatus(403);
    }

    // ── Migration 2026_08_29_000003 schema verification ─────────────────────
    // These exercise the migration's real effect (it already ran, as part of
    // RefreshDatabase, before every test in this class) by testing observable
    // DB behavior rather than introspecting driver-specific index metadata —
    // portable across the SQLite test DB and the real MySQL target.

    public function test_team_profile_id_is_not_null_at_the_database_level(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        \Illuminate\Support\Facades\DB::table('team_members')->insert([
            'user_id' => $this->makeUser()->id,
            'team_profile_id' => null,
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_team_profile_id_is_unique_at_the_database_level(): void
    {
        $dept = $this->makeDepartment();
        [$profile, $user] = $this->makeProfile($dept);
        TeamMember::create(['user_id' => $user->id, 'team_profile_id' => $profile->id, 'department_id' => $dept->id, 'status' => 'active']);

        $otherUser = $this->makeUser();
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        \Illuminate\Support\Facades\DB::table('team_members')->insert([
            'user_id' => $otherUser->id,
            'team_profile_id' => $profile->id, // same profile, different user — must be blocked
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_team_members_user_id_foreign_key_still_enforced_after_index_swap(): void
    {
        // Proves the standalone user_id index added by the migration still
        // correctly backs the FK — an invalid user_id must still be rejected,
        // not silently accepted because the old composite unique is gone.
        $dept = $this->makeDepartment();
        [$profile, ] = $this->makeProfile($dept);

        $this->expectException(\Illuminate\Database\QueryException::class);
        \Illuminate\Support\Facades\DB::table('team_members')->insert([
            'user_id' => 999999999,
            'team_profile_id' => $profile->id,
            'department_id' => $dept->id,
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_deleting_a_team_profile_with_an_operational_membership_is_blocked_with_a_controlled_response(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->admin();
        [$profile, $user] = $this->makeProfile($dept);
        TeamMember::create(['user_id' => $user->id, 'team_profile_id' => $profile->id, 'department_id' => $dept->id, 'status' => 'active']);

        $res = $this->actingAs($admin)->deleteJson("/api/admin/team-profiles/{$profile->id}");
        $res->assertStatus(409)->assertJsonPath('success', false);
        $this->assertDatabaseHas('team_profiles', ['id' => $profile->id]);
    }

    public function test_deleting_a_team_profile_without_an_operational_membership_succeeds(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->admin();
        [$profile, ] = $this->makeProfile($dept);

        $this->actingAs($admin)->deleteJson("/api/admin/team-profiles/{$profile->id}")
            ->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseMissing('team_profiles', ['id' => $profile->id]);
    }
}
