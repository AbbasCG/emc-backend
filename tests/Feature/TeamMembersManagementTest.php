<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentalUnit;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * /dashboard/admin/team-members backend contract. department_id and
 * role_title are NEVER accepted from the client — they are always derived
 * from the canonical team_profiles record for the given user_id (the same
 * directory /dashboard/members shows), which is what stops a forged
 * department_id from ever taking effect. This page only ever writes
 * unit_id/status on an existing team_members row.
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

    /** A canonical EMC member (team_profiles row) with a linked account and a department. */
    private function makeCanonicalMember(?Department $dept = null, array $overrides = []): array
    {
        $user = $this->makeUser('instructor');
        $profile = TeamProfile::create(array_merge([
            'user_id' => $user->id,
            'name_ar' => 'عضو تجريبي ' . Str::random(4),
            'position_ar' => 'مطور',
            'department_id' => $dept?->id,
            'member_type' => 'staff',
            'is_active' => true,
        ], $overrides));

        return [$profile, $user];
    }

    public function test_authorized_admin_can_list_team_members(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->admin();
        [$profile, $memberUser] = $this->makeCanonicalMember($dept);
        TeamMember::create(['user_id' => $memberUser->id, 'team_profile_id' => $profile->id, 'department_id' => $dept->id, 'status' => 'active']);

        $res = $this->actingAs($admin)->getJson("/api/admin/team-members?department_id={$dept->id}");
        $res->assertOk();
        $this->assertNotEmpty($res->json('data'));
    }

    public function test_unauthorized_user_cannot_list_or_create(): void
    {
        $dept = $this->makeDepartment();
        $outsider = $this->makeUser();
        [, $memberUser] = $this->makeCanonicalMember($dept);

        $this->actingAs($outsider)->getJson('/api/admin/team-members')->assertStatus(403);
        $this->actingAs($outsider)->postJson('/api/admin/team-members', [
            'user_id' => $memberUser->id,
        ])->assertStatus(403);
    }

    public function test_create_derives_department_and_title_from_canonical_member(): void
    {
        $dept = $this->makeDepartment(['name_ar' => 'إدارة التقنية والدعم الفني']);
        $admin = $this->admin();
        [, $memberUser] = $this->makeCanonicalMember($dept, ['position_ar' => 'رئيس قسم التقنية']);

        $res = $this->actingAs($admin)->postJson('/api/admin/team-members', [
            'user_id' => $memberUser->id,
        ]);
        $res->assertStatus(201)
            ->assertJsonPath('data.department.id', $dept->id)
            ->assertJsonPath('data.department.name', 'إدارة التقنية والدعم الفني')
            ->assertJsonPath('data.role_title', 'رئيس قسم التقنية');

        $this->assertDatabaseHas('team_members', [
            'user_id' => $memberUser->id, 'department_id' => $dept->id,
            'role_title' => 'رئيس قسم التقنية', 'unit_id' => null,
        ]);
    }

    public function test_forged_department_id_in_request_is_ignored(): void
    {
        $realDept = $this->makeDepartment(['name_ar' => 'إدارة حقيقية']);
        $otherDept = $this->makeDepartment(['name_ar' => 'إدارة أخرى']);
        $admin = $this->admin();
        [, $memberUser] = $this->makeCanonicalMember($realDept);

        // Attempt to smuggle a different department_id/role_title — must be ignored entirely.
        $res = $this->actingAs($admin)->postJson('/api/admin/team-members', [
            'user_id' => $memberUser->id, 'department_id' => $otherDept->id, 'role_title' => 'منصب مزوّر',
        ]);
        $res->assertStatus(201)->assertJsonPath('data.department.id', $realDept->id);
        $this->assertDatabaseHas('team_members', ['user_id' => $memberUser->id, 'department_id' => $realDept->id]);
        $this->assertDatabaseMissing('team_members', ['department_id' => $otherDept->id]);
    }

    public function test_member_without_canonical_department_cannot_be_assigned(): void
    {
        $admin = $this->admin();
        [, $memberUser] = $this->makeCanonicalMember(null); // profile exists, department_id is null

        $this->actingAs($admin)->postJson('/api/admin/team-members', [
            'user_id' => $memberUser->id,
        ])->assertStatus(422);
    }

    public function test_user_with_no_canonical_member_profile_cannot_be_assigned(): void
    {
        $admin = $this->admin();
        $arbitraryUser = $this->makeUser(); // no team_profiles row at all

        $this->actingAs($admin)->postJson('/api/admin/team-members', [
            'user_id' => $arbitraryUser->id,
        ])->assertStatus(422);
    }

    public function test_unit_from_a_different_department_rejected_on_create(): void
    {
        $deptA = $this->makeDepartment();
        $deptB = $this->makeDepartment();
        $unitB = $this->makeUnit($deptB);
        $admin = $this->admin();
        [, $memberUser] = $this->makeCanonicalMember($deptA);

        $this->actingAs($admin)->postJson('/api/admin/team-members', [
            'user_id' => $memberUser->id, 'unit_id' => $unitB->id,
        ])->assertStatus(422);
    }

    public function test_valid_unit_from_member_department_accepted(): void
    {
        $dept = $this->makeDepartment();
        $unit = $this->makeUnit($dept);
        $admin = $this->admin();
        [, $memberUser] = $this->makeCanonicalMember($dept);

        $this->actingAs($admin)->postJson('/api/admin/team-members', [
            'user_id' => $memberUser->id, 'unit_id' => $unit->id,
        ])->assertStatus(201)->assertJsonPath('data.unit.id', $unit->id);
    }

    public function test_duplicate_membership_in_same_department_rejected(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->admin();
        [$profile, $memberUser] = $this->makeCanonicalMember($dept);
        TeamMember::create(['user_id' => $memberUser->id, 'team_profile_id' => $profile->id, 'department_id' => $dept->id, 'status' => 'active']);

        $this->actingAs($admin)->postJson('/api/admin/team-members', [
            'user_id' => $memberUser->id,
        ])->assertStatus(422);

        $this->assertSame(1, TeamMember::where('user_id', $memberUser->id)->where('department_id', $dept->id)->count());
    }

    public function test_update_existing_team_member_only_touches_unit_and_status(): void
    {
        $dept = $this->makeDepartment();
        $unit = $this->makeUnit($dept);
        $admin = $this->admin();
        [$profile, $memberUser] = $this->makeCanonicalMember($dept);
        $member = TeamMember::create(['user_id' => $memberUser->id, 'team_profile_id' => $profile->id, 'department_id' => $dept->id, 'role_title' => 'مطور', 'status' => 'active']);

        $res = $this->actingAs($admin)->putJson("/api/admin/team-members/{$member->id}", [
            'unit_id' => $unit->id, 'status' => 'on_leave',
        ]);
        $res->assertOk()->assertJsonPath('data.role_title', 'مطور'); // untouched

        $fresh = $member->fresh();
        $this->assertSame($unit->id, $fresh->unit_id);
        $this->assertSame('on_leave', $fresh->status);
        $this->assertSame($dept->id, $fresh->department_id); // never changed by this endpoint
    }

    public function test_update_ignores_a_forged_department_id(): void
    {
        $dept = $this->makeDepartment();
        $otherDept = $this->makeDepartment();
        $admin = $this->admin();
        [$profile, $memberUser] = $this->makeCanonicalMember($dept);
        $member = TeamMember::create(['user_id' => $memberUser->id, 'team_profile_id' => $profile->id, 'department_id' => $dept->id, 'status' => 'active']);

        $this->actingAs($admin)->putJson("/api/admin/team-members/{$member->id}", [
            'department_id' => $otherDept->id,
        ])->assertOk();

        $this->assertSame($dept->id, $member->fresh()->department_id);
    }

    public function test_inactive_member_not_eligible_for_new_ticket_assignment(): void
    {
        $dept = $this->makeDepartment();
        $unit = $this->makeUnit($dept);
        $admin = $this->admin();
        [$profile, $memberUser] = $this->makeCanonicalMember($dept);
        TeamMember::create([
            'user_id' => $memberUser->id, 'team_profile_id' => $profile->id, 'department_id' => $dept->id,
            'unit_id' => $unit->id, 'status' => 'inactive',
        ]);

        $res = $this->actingAs($admin)->getJson("/api/v1/tickets/meta?department_id={$dept->id}&unit_id={$unit->id}");
        $res->assertOk();
        $ids = collect($res->json('users'))->pluck('id');
        $this->assertFalse($ids->contains($memberUser->id));
    }

    public function test_new_unassigned_member_appears_via_unit_management_api(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->admin();
        [, $memberUser] = $this->makeCanonicalMember($dept);

        $this->actingAs($admin)->postJson('/api/admin/team-members', [
            'user_id' => $memberUser->id,
        ])->assertStatus(201);

        $res = $this->actingAs($admin)->getJson("/api/admin/team-members?department_id={$dept->id}&unit_id=null");
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('user.id');
        $this->assertTrue($ids->contains($memberUser->id));
    }

    public function test_existing_canonical_member_identity_is_unchanged_by_this_flow(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->admin();
        [$profile, $memberUser] = $this->makeCanonicalMember($dept, ['name_ar' => 'عباس']);

        $this->actingAs($admin)->postJson('/api/admin/team-members', ['user_id' => $memberUser->id])
            ->assertStatus(201);

        // The canonical profile — what /dashboard/members shows — is untouched.
        $this->assertSame('عباس', $profile->fresh()->name_ar);
        $this->assertSame($memberUser->id, $profile->fresh()->user_id);
    }
}
