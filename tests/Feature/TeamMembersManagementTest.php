<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentalUnit;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The new /dashboard/admin/team-members page's backend contract: creating
 * the team_members rows the Unit Management page and Ticket assignment both
 * depend on. Authorization/route gate is pre-existing and unchanged
 * (admin,super_admin,tech_admin — see routes/api.php); this covers the
 * create/update behavior and the duplicate-membership guard added in this
 * task.
 */
class TeamMembersManagementTest extends TestCase
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

    public function test_authorized_admin_can_list_team_members(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->admin();
        $member = $this->makeUser();
        TeamMember::create(['user_id' => $member->id, 'department_id' => $dept->id, 'status' => 'active']);

        $res = $this->actingAs($admin)->getJson("/api/admin/team-members?department_id={$dept->id}");
        $res->assertOk();
        $this->assertNotEmpty($res->json('data'));
    }

    public function test_unauthorized_user_cannot_list_or_create(): void
    {
        $dept = $this->makeDepartment();
        $outsider = $this->makeUser();
        $target = $this->makeUser();

        $this->actingAs($outsider)->getJson('/api/admin/team-members')->assertStatus(403);
        $this->actingAs($outsider)->postJson('/api/admin/team-members', [
            'user_id' => $target->id, 'department_id' => $dept->id,
        ])->assertStatus(403);
    }

    public function test_create_valid_membership_succeeds(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->admin();
        $target = $this->makeUser();

        $res = $this->actingAs($admin)->postJson('/api/admin/team-members', [
            'user_id' => $target->id, 'department_id' => $dept->id, 'role_title' => 'مطور',
        ]);
        $res->assertStatus(201)->assertJsonPath('data.department.id', $dept->id);
        $this->assertDatabaseHas('team_members', ['user_id' => $target->id, 'department_id' => $dept->id, 'unit_id' => null]);
    }

    public function test_invalid_department_rejected(): void
    {
        $admin = $this->admin();
        $target = $this->makeUser();

        $this->actingAs($admin)->postJson('/api/admin/team-members', [
            'user_id' => $target->id, 'department_id' => 999999,
        ])->assertStatus(422);
    }

    public function test_unit_from_a_different_department_rejected_on_create(): void
    {
        $deptA = $this->makeDepartment();
        $deptB = $this->makeDepartment();
        $unitB = $this->makeUnit($deptB);
        $admin = $this->admin();
        $target = $this->makeUser();

        $this->actingAs($admin)->postJson('/api/admin/team-members', [
            'user_id' => $target->id, 'department_id' => $deptA->id, 'unit_id' => $unitB->id,
        ])->assertStatus(422);
    }

    public function test_duplicate_membership_in_same_department_rejected(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->admin();
        $target = $this->makeUser();
        TeamMember::create(['user_id' => $target->id, 'department_id' => $dept->id, 'status' => 'active']);

        $this->actingAs($admin)->postJson('/api/admin/team-members', [
            'user_id' => $target->id, 'department_id' => $dept->id,
        ])->assertStatus(422);

        $this->assertSame(1, TeamMember::where('user_id', $target->id)->where('department_id', $dept->id)->count());
    }

    public function test_same_user_can_belong_to_multiple_different_departments(): void
    {
        $deptA = $this->makeDepartment();
        $deptB = $this->makeDepartment();
        $admin = $this->admin();
        $target = $this->makeUser();
        TeamMember::create(['user_id' => $target->id, 'department_id' => $deptA->id, 'status' => 'active']);

        $this->actingAs($admin)->postJson('/api/admin/team-members', [
            'user_id' => $target->id, 'department_id' => $deptB->id,
        ])->assertStatus(201);

        $this->assertSame(2, TeamMember::where('user_id', $target->id)->count());
    }

    public function test_update_existing_team_member_succeeds_without_new_row(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->admin();
        $target = $this->makeUser();
        $member = TeamMember::create(['user_id' => $target->id, 'department_id' => $dept->id, 'status' => 'active']);

        $this->actingAs($admin)->putJson("/api/admin/team-members/{$member->id}", [
            'role_title' => 'قائد فريق', 'status' => 'on_leave',
        ])->assertOk()->assertJsonPath('data.role_title', 'قائد فريق');

        $this->assertSame(1, TeamMember::where('user_id', $target->id)->count());
        $this->assertSame('on_leave', $member->fresh()->status);
    }

    public function test_moving_department_updates_existing_row_and_rejects_landing_on_a_duplicate(): void
    {
        $deptA = $this->makeDepartment();
        $deptB = $this->makeDepartment();
        $admin = $this->admin();
        $target = $this->makeUser();
        $member = TeamMember::create(['user_id' => $target->id, 'department_id' => $deptA->id, 'status' => 'active']);

        // Clean move: A -> B succeeds, same row.
        $this->actingAs($admin)->putJson("/api/admin/team-members/{$member->id}", [
            'department_id' => $deptB->id,
        ])->assertOk();
        $this->assertSame($deptB->id, $member->fresh()->department_id);
        $this->assertSame(1, TeamMember::where('user_id', $target->id)->count());

        // Now the user has a row in B (via the move) and a fresh row in A —
        // moving back to A should collide with that existing A row.
        $rowInA = TeamMember::create(['user_id' => $target->id, 'department_id' => $deptA->id, 'status' => 'active']);
        $this->actingAs($admin)->putJson("/api/admin/team-members/{$member->id}", [
            'department_id' => $deptA->id,
        ])->assertStatus(422);
    }

    public function test_null_unit_allowed_on_create(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->admin();
        $target = $this->makeUser();

        $this->actingAs($admin)->postJson('/api/admin/team-members', [
            'user_id' => $target->id, 'department_id' => $dept->id, 'unit_id' => null,
        ])->assertStatus(201);
    }

    public function test_new_unassigned_member_appears_via_unit_management_api(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->admin();
        $target = $this->makeUser();

        $this->actingAs($admin)->postJson('/api/admin/team-members', [
            'user_id' => $target->id, 'department_id' => $dept->id,
        ])->assertStatus(201);

        $res = $this->actingAs($admin)->getJson("/api/admin/team-members?department_id={$dept->id}&unit_id=null");
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('user.id');
        $this->assertTrue($ids->contains($target->id));
    }
}
