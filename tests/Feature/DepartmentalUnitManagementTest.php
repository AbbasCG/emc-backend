<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Department;
use App\Models\DepartmentalUnit;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * Admin unit management (DepartmentalUnit CRUD + assigning/moving/removing
 * team_members.unit_id) — the UI layer built on top of the Tickets
 * unit-membership work. Authorization mirrors the same canManageDepartment
 * rule as everything else (department leader or global), never a role name.
 */
class DepartmentalUnitManagementTest extends TestCase
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

    private function makeLeader(Department $dept): User
    {
        $user = User::factory()->create(['role' => 'department_manager', 'is_active' => true]);
        $dept->update(['leader_id' => $user->id]);

        return $user;
    }

    private function makeUser(string $role = 'student'): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
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

    private function makeMember(Department $dept, ?DepartmentalUnit $unit = null): TeamMember
    {
        $user = $this->makeUser();
        $profile = TeamProfile::create([
            'user_id' => $user->id, 'name_ar' => $user->name, 'position_ar' => 'عضو',
            'department_id' => $dept->id, 'member_type' => 'staff', 'is_active' => true,
        ]);

        return TeamMember::create([
            'user_id' => $user->id,
            'team_profile_id' => $profile->id,
            'department_id' => $dept->id,
            'unit_id' => $unit?->id,
            'status' => 'active',
        ]);
    }

    // ── Unit CRUD ─────────────────────────────────────────────────────────

    public function test_department_leader_can_create_unit_for_own_department(): void
    {
        $dept = $this->makeDepartment();
        $leader = $this->makeLeader($dept);

        $this->actingAs($leader)->postJson('/api/admin/departmental-units', [
            'department_id' => $dept->id,
            'code' => 'NEWUNIT',
            'name_ar' => 'وحدة جديدة',
        ])->assertStatus(201)
          ->assertJsonPath('data.department_id', $dept->id);

        $this->assertDatabaseHas('departmental_units', ['code' => 'NEWUNIT', 'department_id' => $dept->id]);
    }

    public function test_unauthorized_user_cannot_create_unit(): void
    {
        $dept = $this->makeDepartment();
        $outsider = $this->makeUser();

        $this->actingAs($outsider)->postJson('/api/admin/departmental-units', [
            'department_id' => $dept->id,
            'code' => 'BLOCKED',
            'name_ar' => 'محظورة',
        ])->assertStatus(403);
    }

    public function test_leader_of_department_a_cannot_create_unit_for_department_b(): void
    {
        $deptA = $this->makeDepartment();
        $deptB = $this->makeDepartment();
        $leaderA = $this->makeLeader($deptA);

        $this->actingAs($leaderA)->postJson('/api/admin/departmental-units', [
            'department_id' => $deptB->id,
            'code' => 'CROSS',
            'name_ar' => 'وحدة إدارة أخرى',
        ])->assertStatus(403);
    }

    public function test_leader_cannot_update_unit_of_a_different_department(): void
    {
        $deptA = $this->makeDepartment();
        $deptB = $this->makeDepartment();
        $leaderA = $this->makeLeader($deptA);
        $unitB = $this->makeUnit($deptB);

        $this->actingAs($leaderA)->putJson("/api/admin/departmental-units/{$unitB->id}", [
            'name_ar' => 'محاولة تعديل',
        ])->assertStatus(403);
    }

    public function test_leader_can_deactivate_own_unit(): void
    {
        $dept = $this->makeDepartment();
        $leader = $this->makeLeader($dept);
        $unit = $this->makeUnit($dept);

        $this->actingAs($leader)->putJson("/api/admin/departmental-units/{$unit->id}", [
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_unit_index_reports_member_count(): void
    {
        $dept = $this->makeDepartment();
        $leader = $this->makeLeader($dept);
        $unit = $this->makeUnit($dept);
        $this->makeMember($dept, $unit);
        $this->makeMember($dept, $unit);

        $res = $this->actingAs($leader)->getJson("/api/admin/departmental-units?department_id={$dept->id}");

        $res->assertOk();
        $row = collect($res->json('data'))->firstWhere('id', $unit->id);
        $this->assertSame(2, $row['member_count']);
    }

    // ── Assign / move / remove via team-members update ──────────────────────
    // /api/admin/team-members/* is (pre-existing, unmodified by this task)
    // gated to admin/super_admin/tech_admin only — department leaders were
    // never granted access to this HR-style endpoint, and this task does not
    // change that boundary, so these tests act as a global admin.

    public function test_assign_employee_to_same_department_unit_succeeds(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->makeUser('super_admin');
        $unit = $this->makeUnit($dept);
        $member = $this->makeMember($dept, null);

        $this->actingAs($admin)->putJson("/api/admin/team-members/{$member->id}", [
            'unit_id' => $unit->id,
        ])->assertOk()->assertJsonPath('data.unit.id', $unit->id);

        $this->assertSame($unit->id, $member->fresh()->unit_id);
    }

    public function test_cross_department_employee_assignment_fails(): void
    {
        $deptA = $this->makeDepartment();
        $deptB = $this->makeDepartment();
        $admin = $this->makeUser('super_admin');
        $unitB = $this->makeUnit($deptB);
        $memberOfA = $this->makeMember($deptA, null);

        $this->actingAs($admin)->putJson("/api/admin/team-members/{$memberOfA->id}", [
            'unit_id' => $unitB->id,
        ])->assertStatus(422);

        $this->assertNull($memberOfA->fresh()->unit_id);
    }

    public function test_moving_employee_updates_existing_record_without_duplicate(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->makeUser('super_admin');
        $unitA = $this->makeUnit($dept, ['code' => 'MOVE-A']);
        $unitB = $this->makeUnit($dept, ['code' => 'MOVE-B']);
        $member = $this->makeMember($dept, $unitA);

        $this->actingAs($admin)->putJson("/api/admin/team-members/{$member->id}", [
            'unit_id' => $unitB->id,
        ])->assertOk();

        $this->assertSame(1, TeamMember::where('user_id', $member->user_id)->count());
        $this->assertSame($unitB->id, $member->fresh()->unit_id);
    }

    public function test_removing_from_unit_sets_null_and_keeps_department_membership(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->makeUser('super_admin');
        $unit = $this->makeUnit($dept);
        $member = $this->makeMember($dept, $unit);

        $this->actingAs($admin)->putJson("/api/admin/team-members/{$member->id}", [
            'unit_id' => null,
        ])->assertOk();

        $fresh = $member->fresh();
        $this->assertNull($fresh->unit_id);
        $this->assertSame($dept->id, $fresh->department_id);
        $this->assertSame('active', $fresh->status);
    }
}
