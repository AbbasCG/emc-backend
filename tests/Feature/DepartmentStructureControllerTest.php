<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentSection;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the ported+adapted DepartmentStructureController: every operation
 * (including reads) is independently authorized via DepartmentAccessService
 * (departments.leader_id), the route-level role list is only a coarse
 * pre-filter (verified separately with a structurally-excluded role), global
 * admins retain override, no cross-department reads/writes are possible, and
 * writes are blocked against archived departments for non-global users.
 */
class DepartmentStructureControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeDepartment(array $overrides = []): Department
    {
        return Department::create(array_merge([
            'name' => 'Dept', 'name_ar' => 'إدارة', 'slug' => 'dept-structure-test-'.uniqid(),
            'status' => 'active', 'is_active' => true,
        ], $overrides));
    }

    private function makeTeamMember(User $user, Department $dept, array $overrides = []): TeamMember
    {
        $profile = TeamProfile::create([
            'department_id' => $dept->id, 'user_id' => $user->id,
            'name_ar' => $user->name, 'position_ar' => 'عضو', 'is_active' => true,
        ]);

        return TeamMember::create(array_merge([
            'user_id' => $user->id, 'team_profile_id' => $profile->id, 'department_id' => $dept->id, 'status' => 'active',
        ], $overrides));
    }

    // ── Read: leader can view own department structure ────────────────────

    public function test_leader_can_view_own_department_structure(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $dept = $this->makeDepartment(['leader_id' => $leader->id]);

        $response = $this->actingAs($leader)->getJson('/api/department/structure');

        $response->assertOk();
        $this->assertSame($dept->id, $response->json('department.id'));
    }

    // ── Read: leader cannot view another department ────────────────────────

    public function test_leader_cannot_view_another_departments_structure(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $ownDept = $this->makeDepartment(['leader_id' => $leader->id]);
        $otherDept = $this->makeDepartment();

        $response = $this->actingAs($leader)->getJson('/api/department/structure?department_id='.$otherDept->id);

        $response->assertStatus(403);
    }

    // ── Read: non-leader (manager-tier role, zero led departments) gets 403 ─

    public function test_authenticated_non_leader_manager_role_receives_403_on_read(): void
    {
        $user = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);

        $response = $this->actingAs($user)->getJson('/api/department/structure');

        $response->assertStatus(403);
    }

    // ── Route-level coarse filter: structurally-excluded role is blocked at the middleware ─

    public function test_structurally_non_leader_role_is_blocked_at_the_route_layer(): void
    {
        $student = User::factory()->create(['role' => 'student', 'is_active' => true]);

        $response = $this->actingAs($student)->getJson('/api/department/structure');

        $response->assertStatus(403);
    }

    // ── Read: global admin sees a default active department and can select any ─

    public function test_global_admin_can_view_any_department_structure(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $dept = $this->makeDepartment();

        $response = $this->actingAs($admin)->getJson('/api/department/structure?department_id='.$dept->id);

        $response->assertOk();
        $this->assertSame($dept->id, $response->json('department.id'));
    }

    // ── Create ───────────────────────────────────────────────────────────

    public function test_leader_can_create_a_section_in_own_department(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $dept = $this->makeDepartment(['leader_id' => $leader->id]);

        $response = $this->actingAs($leader)->postJson('/api/department/sections', [
            'department_id' => $dept->id, 'name' => 'قسم فرعي',
        ]);

        $response->assertCreated();
        $this->assertSame(1, DepartmentSection::where('department_id', $dept->id)->count());
    }

    public function test_leader_cannot_create_a_section_in_another_department(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $this->makeDepartment(['leader_id' => $leader->id]);
        $otherDept = $this->makeDepartment();

        $response = $this->actingAs($leader)->postJson('/api/department/sections', [
            'department_id' => $otherDept->id, 'name' => 'قسم فرعي',
        ]);

        $response->assertStatus(403);
        $this->assertSame(0, DepartmentSection::count());
    }

    public function test_cannot_create_a_section_in_an_archived_department_as_a_leader(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $dept = $this->makeDepartment(['leader_id' => $leader->id, 'status' => 'archived']);

        $response = $this->actingAs($leader)->postJson('/api/department/sections', [
            'department_id' => $dept->id, 'name' => 'قسم فرعي',
        ]);

        $response->assertStatus(403);
    }

    public function test_create_section_with_leader_lacking_a_team_profile_fails_with_no_writes(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $dept = $this->makeDepartment(['leader_id' => $leader->id]);
        $futureLeader = User::factory()->create();
        // No TeamProfile for $futureLeader.

        $response = $this->actingAs($leader)->postJson('/api/department/sections', [
            'department_id' => $dept->id, 'name' => 'قسم فرعي', 'leader_id' => $futureLeader->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DepartmentSection::count(), 'section must not be created when leader sync would fail');
    }

    public function test_global_admin_can_create_a_section_in_an_archived_department(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $dept = $this->makeDepartment(['status' => 'archived']);

        $response = $this->actingAs($admin)->postJson('/api/department/sections', [
            'department_id' => $dept->id, 'name' => 'قسم فرعي',
        ]);

        $response->assertCreated();
    }

    // ── Update ───────────────────────────────────────────────────────────

    public function test_leader_can_update_a_section_in_own_department(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $dept = $this->makeDepartment(['leader_id' => $leader->id]);
        $section = DepartmentSection::create(['department_id' => $dept->id, 'name' => 'قديم', 'status' => 'active', 'sort_order' => 0]);

        $response = $this->actingAs($leader)->putJson('/api/department/sections/'.$section->id, ['name' => 'جديد']);

        $response->assertOk();
        $this->assertSame('جديد', $section->fresh()->name);
    }

    public function test_leader_cannot_update_a_section_in_another_department(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $this->makeDepartment(['leader_id' => $leader->id]);
        $otherDept = $this->makeDepartment();
        $section = DepartmentSection::create(['department_id' => $otherDept->id, 'name' => 'قديم', 'status' => 'active', 'sort_order' => 0]);

        $response = $this->actingAs($leader)->putJson('/api/department/sections/'.$section->id, ['name' => 'جديد']);

        $response->assertStatus(403);
        $this->assertSame('قديم', $section->fresh()->name);
    }

    // ── Reorder ──────────────────────────────────────────────────────────

    public function test_leader_can_reorder_sections_in_own_department(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $dept = $this->makeDepartment(['leader_id' => $leader->id]);
        $s1 = DepartmentSection::create(['department_id' => $dept->id, 'name' => 'A', 'status' => 'active', 'sort_order' => 0]);
        $s2 = DepartmentSection::create(['department_id' => $dept->id, 'name' => 'B', 'status' => 'active', 'sort_order' => 1]);

        $response = $this->actingAs($leader)->postJson('/api/department/sections/reorder', [
            'department_id' => $dept->id, 'section_ids' => [$s2->id, $s1->id],
        ]);

        $response->assertOk();
        $this->assertSame(0, $s2->fresh()->sort_order);
        $this->assertSame(1, $s1->fresh()->sort_order);
    }

    public function test_reorder_rejects_a_section_id_from_another_department(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $dept = $this->makeDepartment(['leader_id' => $leader->id]);
        $ownSection = DepartmentSection::create(['department_id' => $dept->id, 'name' => 'A', 'status' => 'active', 'sort_order' => 0]);
        $otherDept = $this->makeDepartment();
        $foreignSection = DepartmentSection::create(['department_id' => $otherDept->id, 'name' => 'X', 'status' => 'active', 'sort_order' => 0]);

        $response = $this->actingAs($leader)->postJson('/api/department/sections/reorder', [
            'department_id' => $dept->id, 'section_ids' => [$ownSection->id, $foreignSection->id],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, $foreignSection->fresh()->sort_order, 'foreign section must not be touched');
    }

    // ── Delete ───────────────────────────────────────────────────────────

    public function test_leader_can_delete_a_section_and_members_are_unassigned(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $dept = $this->makeDepartment(['leader_id' => $leader->id]);
        $section = DepartmentSection::create(['department_id' => $dept->id, 'name' => 'A', 'status' => 'active', 'sort_order' => 0]);
        $memberUser = User::factory()->create();
        $member = $this->makeTeamMember($memberUser, $dept, ['section_id' => $section->id]);

        $response = $this->actingAs($leader)->deleteJson('/api/department/sections/'.$section->id);

        $response->assertOk();
        $this->assertNull(DepartmentSection::find($section->id));
        $this->assertNull($member->fresh()->section_id);
    }

    public function test_leader_cannot_delete_a_section_in_another_department(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $this->makeDepartment(['leader_id' => $leader->id]);
        $otherDept = $this->makeDepartment();
        $section = DepartmentSection::create(['department_id' => $otherDept->id, 'name' => 'A', 'status' => 'active', 'sort_order' => 0]);

        $response = $this->actingAs($leader)->deleteJson('/api/department/sections/'.$section->id);

        $response->assertStatus(403);
        $this->assertNotNull(DepartmentSection::find($section->id));
    }

    // ── Assign member to section ────────────────────────────────────────

    public function test_leader_can_assign_a_member_to_a_section(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $dept = $this->makeDepartment(['leader_id' => $leader->id]);
        $section = DepartmentSection::create(['department_id' => $dept->id, 'name' => 'A', 'status' => 'active', 'sort_order' => 0]);
        $memberUser = User::factory()->create();
        TeamProfile::create([
            'department_id' => $dept->id, 'user_id' => $memberUser->id, 'name_ar' => $memberUser->name, 'position_ar' => 'عضو', 'is_active' => true,
        ]);

        $response = $this->actingAs($leader)->postJson('/api/department/members/assign-section', [
            'user_id' => $memberUser->id, 'department_id' => $dept->id, 'section_id' => $section->id, 'role_title' => 'مساعد',
        ]);

        $response->assertOk();
        $this->assertSame(1, TeamMember::where('user_id', $memberUser->id)->where('section_id', $section->id)->count());
    }

    public function test_assign_member_fails_when_user_has_no_team_profile_yet(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $dept = $this->makeDepartment(['leader_id' => $leader->id]);
        $memberUser = User::factory()->create();
        // No TeamProfile created for $memberUser.

        $response = $this->actingAs($leader)->postJson('/api/department/members/assign-section', [
            'user_id' => $memberUser->id, 'department_id' => $dept->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, TeamMember::where('user_id', $memberUser->id)->count(), 'must never fabricate a TeamProfile/TeamMember');
    }

    public function test_leader_cannot_assign_a_member_to_another_departments_section(): void
    {
        $leader = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $this->makeDepartment(['leader_id' => $leader->id]);
        $otherDept = $this->makeDepartment();
        $memberUser = User::factory()->create();

        $response = $this->actingAs($leader)->postJson('/api/department/members/assign-section', [
            'user_id' => $memberUser->id, 'department_id' => $otherDept->id,
        ]);

        $response->assertStatus(403);
    }
}
