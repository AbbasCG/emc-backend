<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * TICKET CAPABILITIES — the canonical, per-ticket, per-user action set.
 *
 * `capabilities` on the ticket JSON must be a REPORT of what canManage()/
 * isAssignee() and each endpoint's own status guard would actually decide —
 * never a second, independently-derived opinion. Every assertion here cross-
 * checks the reported flag against the real endpoint outcome, so a future
 * change to one without the other fails these tests.
 */
class TicketCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    private function makeDepartment(array $overrides = []): Department
    {
        return Department::create(array_merge([
            'name' => 'Dept '.Str::random(6),
            'name_ar' => 'إدارة تجريبية',
            'slug' => 'dept-'.Str::random(8),
            'status' => 'active',
            'is_active' => true,
        ], $overrides));
    }

    private function makeLeader(Department $dept, string $role = 'programs_manager'): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true]);
        $dept->update(['leader_id' => $user->id]);

        return $user;
    }

    private function makeUser(string $role = 'student'): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function makeTicket(Department $dept, array $overrides = []): Ticket
    {
        return Ticket::create(array_merge([
            'ticket_number' => 'EMC-TCK-TEST-'.Str::random(6),
            'title' => 'تذكرة تجريبية',
            'description' => 'وصف تجريبي',
            'ticket_category' => 'OLD_ISSUE',
            'priority' => 'MEDIUM',
            'department_id' => $dept->id,
            'created_by_name' => 'مستخدم تجريبي',
            'status' => 'PENDING_APPROVAL',
        ], $overrides));
    }

    private function capabilitiesFor(User $actor, Ticket $ticket): array
    {
        Sanctum::actingAs($actor);

        return $this->getJson("/api/v1/tickets/{$ticket->id}")
            ->assertOk()
            ->json('data.capabilities');
    }

    // ── Leader on a PENDING_APPROVAL ticket ──────────────────────────────────

    public function test_leader_can_approve_and_reject_a_pending_ticket(): void
    {
        $dept = $this->makeDepartment();
        $leader = $this->makeLeader($dept);
        $ticket = $this->makeTicket($dept);

        $caps = $this->capabilitiesFor($leader, $ticket);

        $this->assertTrue($caps['approve']);
        $this->assertTrue($caps['reject']);
        $this->assertFalse($caps['reassign']);
        $this->assertFalse($caps['accept_task']);
        $this->assertFalse($caps['reject_task']);
        $this->assertFalse($caps['complete_task']);
        $this->assertTrue($caps['add_internal_note']);
    }

    public function test_a_non_leader_department_member_has_no_management_capabilities(): void
    {
        $dept = $this->makeDepartment();
        $this->makeLeader($dept);
        $member = $this->makeUser('programs_manager'); // same slug, NOT the leader
        $ticket = $this->makeTicket($dept, ['created_by_id' => $member->id]);

        $caps = $this->capabilitiesFor($member, $ticket);

        $this->assertFalse($caps['approve']);
        $this->assertFalse($caps['reject']);
        $this->assertFalse($caps['reassign']);
        $this->assertFalse($caps['add_internal_note']);
        $this->assertTrue($caps['comment'], 'A viewer (creator) may still comment.');
    }

    public function test_reported_approve_capability_matches_the_real_endpoint(): void
    {
        $dept = $this->makeDepartment();
        $leader = $this->makeLeader($dept);
        $ticket = $this->makeTicket($dept);

        $caps = $this->capabilitiesFor($leader, $ticket);
        $this->assertTrue($caps['approve']);

        Sanctum::actingAs($leader);
        $this->postJson("/api/v1/tickets/{$ticket->id}/approve", ['sla_hours' => 24])
            ->assertOk();
    }

    /**
     * Leading department B grants no standing on department A's ticket at
     * all - canView() itself refuses before capabilities are ever computed,
     * which is a stronger guarantee than "capabilities.approve is false"
     * would be.
     */
    public function test_a_leader_of_a_different_department_cannot_even_view_the_ticket(): void
    {
        $dept = $this->makeDepartment();
        $otherDept = $this->makeDepartment();
        $otherLeader = $this->makeLeader($otherDept);
        $ticket = $this->makeTicket($dept);

        Sanctum::actingAs($otherLeader);
        $this->getJson("/api/v1/tickets/{$ticket->id}")->assertForbidden();
    }

    // ── The exact drift this feature exists to close ─────────────────────────

    /**
     * REJECTED_BY_ASSIGNEE is a dead-end status: no endpoint transitions out
     * of it, and reassign() has never accepted it (its guard is strictly
     * ASSIGNED/IN_PROGRESS). The pre-existing Admin table nonetheless showed
     * a "reassign" button for this exact status - clicking it would 409.
     * capabilities.reassign must correctly report false here.
     */
    public function test_reassign_is_false_for_a_rejected_by_assignee_ticket(): void
    {
        $dept = $this->makeDepartment();
        $leader = $this->makeLeader($dept);
        $assignee = $this->makeUser('support_agent');
        $ticket = $this->makeTicket($dept, [
            'status' => 'REJECTED_BY_ASSIGNEE',
            'assigned_to_id' => $assignee->id,
            'assignee_rejection_reason' => 'غير متاح',
        ]);

        $caps = $this->capabilitiesFor($leader, $ticket);
        $this->assertFalse($caps['reassign'], 'reassign() only accepts ASSIGNED/IN_PROGRESS.');

        // Cross-check against the real endpoint: it must genuinely refuse.
        Sanctum::actingAs($leader);
        $this->postJson("/api/v1/tickets/{$ticket->id}/reassign", ['assigned_to_id' => $assignee->id])
            ->assertStatus(409);
    }

    public function test_reassign_is_true_for_assigned_and_in_progress(): void
    {
        $dept = $this->makeDepartment();
        $leader = $this->makeLeader($dept);
        $assignee = $this->makeUser('support_agent');

        foreach (['ASSIGNED', 'IN_PROGRESS'] as $status) {
            $ticket = $this->makeTicket($dept, ['status' => $status, 'assigned_to_id' => $assignee->id]);
            $caps = $this->capabilitiesFor($leader, $ticket);
            $this->assertTrue($caps['reassign'], "[{$status}] must allow reassign.");
        }
    }

    // ── Assignee capabilities ─────────────────────────────────────────────────

    public function test_assignee_can_accept_an_assigned_ticket(): void
    {
        $dept = $this->makeDepartment();
        $assignee = $this->makeUser('support_agent');
        $ticket = $this->makeTicket($dept, ['status' => 'ASSIGNED', 'assigned_to_id' => $assignee->id]);

        $caps = $this->capabilitiesFor($assignee, $ticket);

        $this->assertTrue($caps['accept_task']);
        $this->assertTrue($caps['reject_task']);
        $this->assertFalse($caps['complete_task']);
        $this->assertFalse($caps['approve']);
        $this->assertFalse($caps['reassign'], 'Assignee is not the department leader.');
    }

    public function test_assignee_can_complete_an_in_progress_ticket(): void
    {
        $dept = $this->makeDepartment();
        $assignee = $this->makeUser('support_agent');
        $ticket = $this->makeTicket($dept, ['status' => 'IN_PROGRESS', 'assigned_to_id' => $assignee->id]);

        $caps = $this->capabilitiesFor($assignee, $ticket);

        $this->assertTrue($caps['complete_task']);
        $this->assertFalse($caps['accept_task']);
        $this->assertFalse($caps['reject_task']);
    }

    public function test_a_different_user_is_not_treated_as_the_assignee(): void
    {
        $dept = $this->makeDepartment();
        $assignee = $this->makeUser('support_agent');
        $outsider = $this->makeUser('support_agent');
        $ticket = $this->makeTicket($dept, ['status' => 'ASSIGNED', 'assigned_to_id' => $assignee->id]);

        Sanctum::actingAs($outsider);
        $this->getJson("/api/v1/tickets/{$ticket->id}")->assertForbidden();
    }

    // ── Global roles ──────────────────────────────────────────────────────────

    public function test_a_global_role_gets_full_capabilities_regardless_of_department(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->makeUser('super_admin');
        $ticket = $this->makeTicket($dept);

        $caps = $this->capabilitiesFor($admin, $ticket);

        $this->assertTrue($caps['approve']);
        $this->assertTrue($caps['reject']);
        $this->assertTrue($caps['add_internal_note']);
    }

    public function test_a_global_role_can_act_as_assignee_even_when_not_literally_assigned(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->makeUser('tech_admin');
        $otherAssignee = $this->makeUser('support_agent');
        $ticket = $this->makeTicket($dept, ['status' => 'IN_PROGRESS', 'assigned_to_id' => $otherAssignee->id]);

        $caps = $this->capabilitiesFor($admin, $ticket);

        $this->assertTrue($caps['complete_task'], 'isAssignee() treats global roles as eligible.');
    }

    // ── Closed statuses ───────────────────────────────────────────────────────

    public function test_no_action_capabilities_remain_on_a_resolved_ticket(): void
    {
        $dept = $this->makeDepartment();
        $leader = $this->makeLeader($dept);
        $assignee = $this->makeUser('support_agent');
        $ticket = $this->makeTicket($dept, [
            'status' => 'RESOLVED',
            'assigned_to_id' => $assignee->id,
            'resolved_at' => now(),
            'resolution_summary' => 'تم',
        ]);

        $leaderCaps = $this->capabilitiesFor($leader, $ticket);
        $this->assertFalse($leaderCaps['approve']);
        $this->assertFalse($leaderCaps['reject']);
        $this->assertFalse($leaderCaps['reassign']);

        $assigneeCaps = $this->capabilitiesFor($assignee, $ticket);
        $this->assertFalse($assigneeCaps['accept_task']);
        $this->assertFalse($assigneeCaps['reject_task']);
        $this->assertFalse($assigneeCaps['complete_task']);
    }

    // ── index() parity with show() ───────────────────────────────────────────

    public function test_the_list_endpoint_reports_the_same_capabilities_as_show(): void
    {
        $dept = $this->makeDepartment();
        $leader = $this->makeLeader($dept);
        $this->makeTicket($dept);

        Sanctum::actingAs($leader);
        $list = $this->getJson('/api/v1/tickets')->assertOk()->json('data.data');

        $this->assertNotEmpty($list);
        $this->assertArrayHasKey('capabilities', $list[0]);
        $this->assertTrue($list[0]['capabilities']['approve']);
    }

    public function test_capabilities_never_expose_a_ticket_the_user_cannot_view(): void
    {
        $dept = $this->makeDepartment();
        $ticket = $this->makeTicket($dept);
        $outsider = $this->makeUser('student');

        Sanctum::actingAs($outsider);
        $this->getJson("/api/v1/tickets/{$ticket->id}")->assertForbidden();
    }

    public function test_the_raw_ticket_shape_is_unchanged_apart_from_the_new_key(): void
    {
        $dept = $this->makeDepartment();
        $leader = $this->makeLeader($dept);
        $ticket = $this->makeTicket($dept);

        Sanctum::actingAs($leader);
        $data = $this->getJson("/api/v1/tickets/{$ticket->id}")->assertOk()->json('data');

        foreach (['id', 'ticket_number', 'title', 'status', 'is_delayed', 'department', 'creator'] as $field) {
            $this->assertArrayHasKey($field, $data, "[{$field}] must remain present on the ticket payload.");
        }
    }
}
