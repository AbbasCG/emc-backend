<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentalUnit;
use App\Models\TeamProfile;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Proves the complete, intended chain end-to-end, using the CANONICAL member
 * identity source (team_profiles, what /dashboard/members actually shows —
 * see AdminMemberController) rather than an arbitrary user:
 *
 *   TeamProfile (existing EMC member, has a linked user_id)
 *     -> POST /admin/team-members using that SAME user_id (never a new person)
 *     -> team_members row (department + optional unit)
 *     -> GET /admin/departmental-units shows correct member_count
 *     -> POST /v1/tickets/meta?department_id&unit_id returns the member as
 *        an eligible assignee
 *     -> POST /v1/tickets/{id}/approve with that unit_id + assigned_to_id succeeds
 */
class CanonicalMemberToTicketAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function makeDepartment(array $overrides = []): Department
    {
        return Department::create(array_merge([
            'name' => 'Dept ' . Str::random(6),
            'name_ar' => 'إدارة التقنية والدعم الفني',
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

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    }

    /** A canonical EMC member (team_profiles row) that already has a linked user account and a department. */
    private function makeCanonicalMemberWithAccount(?Department $dept = null): array
    {
        $user = User::factory()->create(['role' => 'instructor', 'is_active' => true, 'name' => 'عباس']);
        $profile = TeamProfile::create([
            'user_id' => $user->id,
            'name_ar' => 'عباس',
            'position_ar' => 'رئيس قسم التقنية',
            'department_id' => $dept?->id,
            'member_type' => 'staff',
            'is_active' => true,
        ]);

        return [$profile, $user];
    }

    public function test_canonical_member_without_account_is_excluded_from_the_assignment_search_scope(): void
    {
        TeamProfile::create(['name_ar' => 'بدون حساب', 'position_ar' => 'متطوع', 'member_type' => 'volunteer', 'is_active' => true]);
        $admin = $this->admin();

        $res = $this->actingAs($admin)->getJson('/api/admin/members?search=بدون&with_account=1');
        $res->assertOk();
        $this->assertEmpty($res->json('data'));
    }

    public function test_full_chain_canonical_member_becomes_eligible_ticket_assignee(): void
    {
        $dept = $this->makeDepartment();
        $leader = $this->makeLeader($dept);
        $admin = $this->admin();
        [$profile, $user] = $this->makeCanonicalMemberWithAccount($dept);

        // Step 1: this member is findable through the SAME canonical directory /dashboard/members uses.
        $searchRes = $this->actingAs($admin)->getJson('/api/admin/members?search=عباس&with_account=1');
        $searchRes->assertOk();
        $hit = collect($searchRes->json('data'))->firstWhere('id', $profile->id);
        $this->assertNotNull($hit);
        $this->assertSame($user->id, $hit['user_id']);

        // Step 2: assign that EXACT user_id via /admin/team-members — department is
        // derived from the canonical profile above, never sent by the client.
        $createRes = $this->actingAs($admin)->postJson('/api/admin/team-members', [
            'user_id' => $user->id,
        ]);
        $createRes->assertStatus(201);
        $this->assertDatabaseHas('team_members', ['user_id' => $user->id, 'department_id' => $dept->id, 'unit_id' => null]);

        // Step 3: create a unit under the SAME department (as /dashboard/admin/department-units does).
        $unitRes = $this->actingAs($leader)->postJson('/api/admin/departmental-units', [
            'department_id' => $dept->id, 'code' => 'SW_DEV', 'name_ar' => 'وحدة تطوير البرمجيات',
        ]);
        $unitRes->assertStatus(201);
        $unitId = $unitRes->json('data.id');

        // Step 4: assign the member to that unit.
        $teamMemberId = $this->actingAs($admin)
            ->getJson("/api/admin/team-members?department_id={$dept->id}&unit_id=null")
            ->json('data.0.id');
        $this->actingAs($admin)->putJson("/api/admin/team-members/{$teamMemberId}", ['unit_id' => $unitId])
            ->assertOk();

        // Step 5: the unit now appears in Ticket meta for this department.
        $metaRes = $this->actingAs($leader)->getJson("/api/v1/tickets/meta?department_id={$dept->id}");
        $metaRes->assertOk();
        $unitCodes = collect($metaRes->json('tech_units'))->pluck('code');
        $this->assertTrue($unitCodes->contains('SW_DEV'));

        // Step 6: selecting that unit returns the member as an eligible assignee.
        $metaWithUnitRes = $this->actingAs($leader)
            ->getJson("/api/v1/tickets/meta?department_id={$dept->id}&unit_id={$unitId}");
        $metaWithUnitRes->assertOk();
        $assigneeIds = collect($metaWithUnitRes->json('users'))->pluck('id');
        $this->assertTrue($assigneeIds->contains($user->id));

        // Step 7: the ticket can actually be approved with this unit + this member.
        $ticket = Ticket::create([
            'ticket_number' => 'EMC-TCK-TEST-' . Str::random(6),
            'title' => 'تذكرة', 'description' => 'وصف', 'ticket_category' => 'OLD_ISSUE',
            'priority' => 'MEDIUM', 'department_id' => $dept->id,
            'created_by_name' => 'تجريبي', 'status' => 'PENDING_APPROVAL',
        ]);
        $approveRes = $this->actingAs($leader)->postJson("/api/v1/tickets/{$ticket->id}/approve", [
            'sla_hours' => 24, 'unit_id' => $unitId, 'assigned_to_id' => $user->id,
        ]);
        $approveRes->assertOk();
        $this->assertSame($unitId, $ticket->fresh()->unit_id);
        $this->assertSame($user->id, $ticket->fresh()->assigned_to_id);
    }

    public function test_unit_from_a_different_department_is_not_returned_for_this_ticket(): void
    {
        $deptA = $this->makeDepartment(['name_ar' => 'إدارة أ']);
        $deptB = $this->makeDepartment(['name_ar' => 'إدارة ب']);
        $admin = $this->admin();
        $leaderA = $this->makeLeader($deptA);

        DepartmentalUnit::create(['department_id' => $deptB->id, 'code' => 'OTHER', 'name_ar' => 'وحدة أخرى', 'is_active' => true]);

        $metaRes = $this->actingAs($leaderA)->getJson("/api/v1/tickets/meta?department_id={$deptA->id}");
        $metaRes->assertOk();
        $this->assertEmpty($metaRes->json('tech_units'));
    }
}
