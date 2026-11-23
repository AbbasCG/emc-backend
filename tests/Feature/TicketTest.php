<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Department;
use App\Models\DepartmentalUnit;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * EMC Tickets — authentication, department-leadership authorization, IDOR,
 * and state-machine coverage. Authorization mirrors the canonical
 * DepartmentAccessService rules already established for Meeting/Weekly
 * Reports: membership grants read access only, leadership (departments.
 * leader_id) or a global role grants management actions — a role name
 * alone (e.g. "programs_manager") never does.
 */
class TicketTest extends TestCase
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
            'ticket_number' => 'EMC-TCK-TEST-' . Str::random(6),
            'title' => 'تذكرة تجريبية',
            'description' => 'وصف تجريبي',
            'ticket_category' => 'OLD_ISSUE',
            'priority' => 'MEDIUM',
            'department_id' => $dept->id,
            'created_by_name' => 'مستخدم تجريبي',
            'status' => 'PENDING_APPROVAL',
        ], $overrides));
    }

    // ── Authentication: every action requires a logged-in user ──────────────

    public function test_anonymous_cannot_list_tickets(): void
    {
        $this->getJson('/api/v1/tickets')->assertStatus(401);
    }

    public function test_anonymous_cannot_view_ticket(): void
    {
        $dept = $this->makeDepartment();
        $ticket = $this->makeTicket($dept);
        $this->getJson("/api/v1/tickets/{$ticket->id}")->assertStatus(401);
    }

    public function test_anonymous_cannot_create_ticket(): void
    {
        $dept = $this->makeDepartment();
        $this->postJson('/api/v1/tickets', [
            'title' => 'x', 'description' => 'y', 'ticket_category' => 'OLD_ISSUE',
            'department_id' => $dept->id, 'created_by_name' => 'x',
        ])->assertStatus(401);
    }

    public function test_anonymous_cannot_approve_reject_or_manage(): void
    {
        $dept = $this->makeDepartment();
        $ticket = $this->makeTicket($dept);

        $this->postJson("/api/v1/tickets/{$ticket->id}/approve", ['sla_hours' => 24])->assertStatus(401);
        $this->postJson("/api/v1/tickets/{$ticket->id}/reject", ['admin_rejection_reason' => 'سبب كافٍ للرفض'])->assertStatus(401);
        $this->postJson("/api/v1/tickets/{$ticket->id}/accept-task")->assertStatus(401);
        $this->postJson("/api/v1/tickets/{$ticket->id}/complete-task", ['resolution_type' => 'RESOLVED'])->assertStatus(401);
        $this->postJson("/api/v1/tickets/{$ticket->id}/reassign", ['assigned_to_id' => 1])->assertStatus(401);
    }

    public function test_anonymous_cannot_access_meta_or_attachments(): void
    {
        $dept = $this->makeDepartment();
        $ticket = $this->makeTicket($dept);
        $attachment = TicketAttachment::create([
            'ticket_id' => $ticket->id, 'file_url' => 'tickets/x.png', 'file_name' => 'x.png',
        ]);

        $this->getJson('/api/v1/tickets/meta')->assertStatus(401);
        $this->getJson("/api/v1/tickets/{$ticket->id}/attachments/{$attachment->id}/download")->assertStatus(401);
    }

    // ── Meta: least-privilege, no full user table for ordinary users ────────

    public function test_meta_does_not_leak_full_user_list_to_ordinary_user(): void
    {
        User::factory()->count(5)->create();
        $user = $this->makeUser();

        $response = $this->actingAs($user)->getJson('/api/v1/tickets/meta');

        $response->assertOk();
        $this->assertSame([], $response->json('users'));
    }

    public function test_meta_gives_privileged_role_a_scoped_assignable_user_list(): void
    {
        $admin = $this->makeUser('super_admin');
        $dept = $this->makeDepartment();
        $leader = $this->makeLeader($dept);

        $response = $this->actingAs($admin)->getJson("/api/v1/tickets/meta?department_id={$dept->id}");

        $response->assertOk();
        $ids = collect($response->json('users'))->pluck('id');
        $this->assertTrue($ids->contains($leader->id));
        // No email exposed anywhere in the users payload.
        $this->assertArrayNotHasKey('email', $response->json('users.0') ?? ['email' => null]);
    }

    // ── Creator ───────────────────────────────────────────────────────────

    public function test_authenticated_user_can_submit_ticket(): void
    {
        $dept = $this->makeDepartment();
        $user = $this->makeUser();

        $response = $this->actingAs($user)->postJson('/api/v1/tickets', [
            'title' => 'بطء في النظام',
            'description' => 'شرح البطء الحاصل',
            'ticket_category' => 'OLD_ISSUE',
            'priority' => 'HIGH',
            'department_id' => $dept->id,
            'created_by_name' => 'محمد أحمد',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.status', 'PENDING_APPROVAL');
        $this->assertDatabaseHas('tickets', ['title' => 'بطء في النظام', 'created_by_id' => $user->id]);
    }

    public function test_creator_can_view_own_ticket(): void
    {
        $dept = $this->makeDepartment();
        $user = $this->makeUser();
        $ticket = $this->makeTicket($dept, ['created_by_id' => $user->id]);

        $this->actingAs($user)->getJson("/api/v1/tickets/{$ticket->id}")->assertOk();
    }

    public function test_creator_cannot_approve_own_ticket(): void
    {
        $dept = $this->makeDepartment();
        $user = $this->makeUser();
        $ticket = $this->makeTicket($dept, ['created_by_id' => $user->id]);

        $this->actingAs($user)
            ->postJson("/api/v1/tickets/{$ticket->id}/approve", ['sla_hours' => 24])
            ->assertStatus(403);
    }

    // ── Department leadership authority ──────────────────────────────────

    public function test_department_leader_can_approve_own_department_ticket(): void
    {
        $dept = $this->makeDepartment();
        $leader = $this->makeLeader($dept);
        $ticket = $this->makeTicket($dept);

        $response = $this->actingAs($leader)->postJson("/api/v1/tickets/{$ticket->id}/approve", ['sla_hours' => 24]);

        $response->assertOk()->assertJsonPath('data.status', 'ASSIGNED');
    }

    public function test_leader_of_department_a_cannot_approve_department_b_ticket(): void
    {
        $deptA = $this->makeDepartment();
        $deptB = $this->makeDepartment();
        $leaderA = $this->makeLeader($deptA);
        $ticket = $this->makeTicket($deptB);

        $this->actingAs($leaderA)
            ->postJson("/api/v1/tickets/{$ticket->id}/approve", ['sla_hours' => 24])
            ->assertStatus(403);
    }

    public function test_manager_role_without_leadership_cannot_approve(): void
    {
        // Same role slug as a real leader elsewhere, but NOT the configured leader_id here.
        $dept = $this->makeDepartment();
        $this->makeLeader($dept, 'programs_manager'); // the real leader
        $lookalike = $this->makeUser('programs_manager'); // same role, not the leader
        $ticket = $this->makeTicket($dept);

        $this->actingAs($lookalike)
            ->postJson("/api/v1/tickets/{$ticket->id}/approve", ['sla_hours' => 24])
            ->assertStatus(403);
    }

    public function test_privileged_admin_can_approve_any_department_ticket(): void
    {
        $dept = $this->makeDepartment();
        $admin = $this->makeUser('super_admin');
        $ticket = $this->makeTicket($dept);

        $this->actingAs($admin)
            ->postJson("/api/v1/tickets/{$ticket->id}/approve", ['sla_hours' => 24])
            ->assertOk();
    }

    public function test_admin_rejection_requires_mandatory_reason(): void
    {
        $dept = $this->makeDepartment();
        $leader = $this->makeLeader($dept);
        $ticket = $this->makeTicket($dept);

        $this->actingAs($leader)
            ->postJson("/api/v1/tickets/{$ticket->id}/reject", [])
            ->assertStatus(422);

        $this->actingAs($leader)
            ->postJson("/api/v1/tickets/{$ticket->id}/reject", ['admin_rejection_reason' => 'الطلب خارج النطاق الفني المعتمد للمركز'])
            ->assertOk()
            ->assertJsonPath('data.status', 'REJECTED_BY_ADMIN');
    }

    // ── Assignee workflow ─────────────────────────────────────────────────

    public function test_assignee_can_accept_and_complete_task(): void
    {
        $dept = $this->makeDepartment();
        $assignee = $this->makeUser('instructor');
        $ticket = $this->makeTicket($dept, ['status' => 'ASSIGNED', 'assigned_to_id' => $assignee->id]);

        $this->actingAs($assignee)->postJson("/api/v1/tickets/{$ticket->id}/accept-task")
            ->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');

        $this->actingAs($assignee)->postJson("/api/v1/tickets/{$ticket->id}/complete-task", [
            'resolution_type' => 'RESOLVED',
            'resolution_summary' => 'تم إصلاح المشكلة عبر تحديث الخادم',
        ])->assertOk()->assertJsonPath('data.status', 'RESOLVED');
    }

    public function test_non_assignee_cannot_accept_or_complete_task(): void
    {
        $dept = $this->makeDepartment();
        $assignee = $this->makeUser();
        $stranger = $this->makeUser();
        $ticket = $this->makeTicket($dept, ['status' => 'ASSIGNED', 'assigned_to_id' => $assignee->id]);

        $this->actingAs($stranger)->postJson("/api/v1/tickets/{$ticket->id}/accept-task")->assertStatus(403);

        $ticket->update(['status' => 'IN_PROGRESS']);
        $this->actingAs($stranger)->postJson("/api/v1/tickets/{$ticket->id}/complete-task", [
            'resolution_type' => 'RESOLVED', 'resolution_summary' => 'محاولة اختراق',
        ])->assertStatus(403);
    }

    public function test_unresolved_completion_requires_reason(): void
    {
        $dept = $this->makeDepartment();
        $assignee = $this->makeUser();
        $ticket = $this->makeTicket($dept, ['status' => 'IN_PROGRESS', 'assigned_to_id' => $assignee->id]);

        $this->actingAs($assignee)->postJson("/api/v1/tickets/{$ticket->id}/complete-task", [
            'resolution_type' => 'UNRESOLVED',
        ])->assertStatus(422);

        $this->actingAs($assignee)->postJson("/api/v1/tickets/{$ticket->id}/complete-task", [
            'resolution_type' => 'UNRESOLVED', 'unresolved_reason' => 'يتطلب صلاحيات خادم غير متاحة حالياً',
        ])->assertOk()->assertJsonPath('data.status', 'UNRESOLVED');
    }

    // ── IDOR ──────────────────────────────────────────────────────────────

    public function test_unrelated_user_cannot_view_ticket_outside_their_scope(): void
    {
        $dept = $this->makeDepartment();
        $creator = $this->makeUser();
        $ticket = $this->makeTicket($dept, ['created_by_id' => $creator->id]);
        $stranger = $this->makeUser(); // no department tie, not creator, not assignee

        $this->actingAs($stranger)->getJson("/api/v1/tickets/{$ticket->id}")->assertStatus(403);
    }

    public function test_unrelated_user_cannot_download_attachment(): void
    {
        $dept = $this->makeDepartment();
        $creator = $this->makeUser();
        $ticket = $this->makeTicket($dept, ['created_by_id' => $creator->id]);
        $attachment = TicketAttachment::create([
            'ticket_id' => $ticket->id, 'file_url' => 'tickets/private.png', 'file_name' => 'private.png',
        ]);
        $stranger = $this->makeUser();

        $this->actingAs($stranger)
            ->getJson("/api/v1/tickets/{$ticket->id}/attachments/{$attachment->id}/download")
            ->assertStatus(403);
    }

    public function test_attachment_response_never_exposes_raw_storage_path(): void
    {
        $dept = $this->makeDepartment();
        $creator = $this->makeUser();
        $ticket = $this->makeTicket($dept, ['created_by_id' => $creator->id]);
        TicketAttachment::create([
            'ticket_id' => $ticket->id, 'file_url' => 'tickets/attachments/secret-name.png', 'file_name' => 'x.png',
        ]);

        $response = $this->actingAs($creator)->getJson("/api/v1/tickets/{$ticket->id}");

        $body = $response->getContent();
        $this->assertStringNotContainsString('secret-name.png', $body);
        $this->assertStringNotContainsString('tickets/attachments', $body);
    }

    // ── State machine ─────────────────────────────────────────────────────

    public function test_cannot_approve_ticket_not_pending_approval(): void
    {
        $dept = $this->makeDepartment();
        $leader = $this->makeLeader($dept);
        $ticket = $this->makeTicket($dept, ['status' => 'ASSIGNED']);

        $this->actingAs($leader)
            ->postJson("/api/v1/tickets/{$ticket->id}/approve", ['sla_hours' => 24])
            ->assertStatus(409);
    }

    public function test_cannot_accept_task_not_assigned_status(): void
    {
        $dept = $this->makeDepartment();
        $assignee = $this->makeUser();
        $ticket = $this->makeTicket($dept, ['status' => 'PENDING_APPROVAL', 'assigned_to_id' => $assignee->id]);

        $this->actingAs($assignee)->postJson("/api/v1/tickets/{$ticket->id}/accept-task")->assertStatus(409);
    }

    public function test_cannot_complete_task_not_in_progress(): void
    {
        $dept = $this->makeDepartment();
        $assignee = $this->makeUser();
        $ticket = $this->makeTicket($dept, ['status' => 'ASSIGNED', 'assigned_to_id' => $assignee->id]);

        $this->actingAs($assignee)->postJson("/api/v1/tickets/{$ticket->id}/complete-task", [
            'resolution_type' => 'RESOLVED', 'resolution_summary' => 'محاولة تجاوز الحالة',
        ])->assertStatus(409);
    }

    // ── Cross-scope validation ────────────────────────────────────────────

    public function test_approve_rejects_unit_from_a_different_department(): void
    {
        $dept = $this->makeDepartment();
        $otherDept = $this->makeDepartment();
        $leader = $this->makeLeader($dept);
        $foreignUnit = DepartmentalUnit::create([
            'department_id' => $otherDept->id, 'code' => 'FOREIGN', 'name_ar' => 'وحدة أخرى',
        ]);
        $ticket = $this->makeTicket($dept);

        $this->actingAs($leader)->postJson("/api/v1/tickets/{$ticket->id}/approve", [
            'sla_hours' => 24, 'unit_id' => $foreignUnit->id,
        ])->assertStatus(422);
    }

    public function test_approve_rejects_assignee_ineligible_for_department(): void
    {
        $dept = $this->makeDepartment();
        $leader = $this->makeLeader($dept);
        $ineligible = $this->makeUser(); // no tie to $dept at all
        $ticket = $this->makeTicket($dept);

        $this->actingAs($leader)->postJson("/api/v1/tickets/{$ticket->id}/approve", [
            'sla_hours' => 24, 'assigned_to_id' => $ineligible->id,
        ])->assertStatus(422);
    }

    // ── Unit membership (team_members.unit_id) ──────────────────────────────

    private function makeUnit(Department $dept, array $overrides = []): DepartmentalUnit
    {
        return DepartmentalUnit::create(array_merge([
            'department_id' => $dept->id,
            'code' => 'UNIT-' . Str::random(6),
            'name_ar' => 'وحدة تجريبية',
            'is_active' => true,
        ], $overrides));
    }

    private function makeUnitMember(Department $dept, DepartmentalUnit $unit, array $overrides = []): User
    {
        $user = $this->makeUser();
        $profile = \App\Models\TeamProfile::create([
            'user_id' => $user->id, 'name_ar' => $user->name, 'position_ar' => 'عضو',
            'department_id' => $dept->id, 'member_type' => 'staff', 'is_active' => true,
        ]);
        \App\Models\TeamMember::create(array_merge([
            'user_id' => $user->id,
            'team_profile_id' => $profile->id,
            'department_id' => $dept->id,
            'unit_id' => $unit->id,
            'status' => 'active',
        ], $overrides));

        return $user;
    }

    public function test_meta_returns_units_only_for_the_requested_department(): void
    {
        $dept = $this->makeDepartment();
        $otherDept = $this->makeDepartment();
        $this->makeUnit($dept, ['code' => 'MINE']);
        $this->makeUnit($otherDept, ['code' => 'THEIRS']);
        $admin = $this->makeUser('super_admin');

        $res = $this->actingAs($admin)->getJson('/api/v1/tickets/meta?department_id=' . $dept->id);

        $res->assertOk();
        $codes = collect($res->json('tech_units'))->pluck('code');
        $this->assertTrue($codes->contains('MINE'));
        $this->assertFalse($codes->contains('THEIRS'));
    }

    public function test_meta_returns_no_units_without_a_department_id(): void
    {
        $dept = $this->makeDepartment();
        $this->makeUnit($dept);
        $admin = $this->makeUser('super_admin');

        $res = $this->actingAs($admin)->getJson('/api/v1/tickets/meta');

        $res->assertOk();
        $this->assertSame([], $res->json('tech_units'));
    }

    public function test_meta_scopes_assignable_users_to_the_selected_unit(): void
    {
        $dept = $this->makeDepartment();
        $unitA = $this->makeUnit($dept, ['code' => 'A']);
        $unitB = $this->makeUnit($dept, ['code' => 'B']);
        $memberA = $this->makeUnitMember($dept, $unitA);
        $memberB = $this->makeUnitMember($dept, $unitB);
        $admin = $this->makeUser('super_admin');

        $res = $this->actingAs($admin)->getJson(
            "/api/v1/tickets/meta?department_id={$dept->id}&unit_id={$unitA->id}"
        );

        $res->assertOk();
        $ids = collect($res->json('users'))->pluck('id');
        $this->assertTrue($ids->contains($memberA->id));
        $this->assertFalse($ids->contains($memberB->id));
    }

    public function test_meta_excludes_inactive_unit_member(): void
    {
        $dept = $this->makeDepartment();
        $unit = $this->makeUnit($dept);
        $inactiveMember = $this->makeUnitMember($dept, $unit, ['status' => 'inactive']);
        $admin = $this->makeUser('super_admin');

        $res = $this->actingAs($admin)->getJson(
            "/api/v1/tickets/meta?department_id={$dept->id}&unit_id={$unit->id}"
        );

        $ids = collect($res->json('users'))->pluck('id');
        $this->assertFalse($ids->contains($inactiveMember->id));
    }

    public function test_approve_with_valid_unit_and_unit_member_succeeds(): void
    {
        $dept = $this->makeDepartment();
        $leader = $this->makeLeader($dept);
        $unit = $this->makeUnit($dept);
        $member = $this->makeUnitMember($dept, $unit);
        $ticket = $this->makeTicket($dept);

        $this->actingAs($leader)->postJson("/api/v1/tickets/{$ticket->id}/approve", [
            'sla_hours' => 24, 'unit_id' => $unit->id, 'assigned_to_id' => $member->id, 'task_scope' => 'simple', 'task_points' => 30,
        ])->assertOk();

        $this->assertSame('ASSIGNED', $ticket->fresh()->status);
        $this->assertSame($member->id, $ticket->fresh()->assigned_to_id);
    }

    public function test_approve_rejects_employee_from_a_different_unit_than_the_one_selected(): void
    {
        $dept = $this->makeDepartment();
        $leader = $this->makeLeader($dept);
        $unitA = $this->makeUnit($dept, ['code' => 'A2']);
        $unitB = $this->makeUnit($dept, ['code' => 'B2']);
        $memberOfB = $this->makeUnitMember($dept, $unitB);
        $ticket = $this->makeTicket($dept);

        // The exact ID-manipulation attack from the spec: unit A selected,
        // assignee is actually a member of unit B only.
        $this->actingAs($leader)->postJson("/api/v1/tickets/{$ticket->id}/approve", [
            'sla_hours' => 24, 'unit_id' => $unitA->id, 'assigned_to_id' => $memberOfB->id,
        ])->assertStatus(422);
    }

    public function test_approve_rejects_arbitrary_active_user_who_is_not_a_unit_member(): void
    {
        $dept = $this->makeDepartment();
        $leader = $this->makeLeader($dept);
        $unit = $this->makeUnit($dept);
        $randomUser = $this->makeUser(); // active, but no team_members row at all

        $ticket = $this->makeTicket($dept);

        $this->actingAs($leader)->postJson("/api/v1/tickets/{$ticket->id}/approve", [
            'sla_hours' => 24, 'unit_id' => $unit->id, 'assigned_to_id' => $randomUser->id,
        ])->assertStatus(422);
    }

    public function test_reassign_rejects_employee_from_a_different_unit(): void
    {
        $dept = $this->makeDepartment();
        $leader = $this->makeLeader($dept);
        $unitA = $this->makeUnit($dept, ['code' => 'RA']);
        $unitB = $this->makeUnit($dept, ['code' => 'RB']);
        $memberOfB = $this->makeUnitMember($dept, $unitB);
        $ticket = $this->makeTicket($dept, ['status' => 'ASSIGNED']);

        $this->actingAs($leader)->postJson("/api/v1/tickets/{$ticket->id}/reassign", [
            'unit_id' => $unitA->id, 'assigned_to_id' => $memberOfB->id,
        ])->assertStatus(422);
    }

    public function test_approve_global_role_bypasses_unit_membership_check(): void
    {
        $dept = $this->makeDepartment();
        $leader = $this->makeLeader($dept);
        $unit = $this->makeUnit($dept);
        $globalAdmin = $this->makeUser('super_admin'); // no team_members row anywhere

        $ticket = $this->makeTicket($dept);

        $this->actingAs($leader)->postJson("/api/v1/tickets/{$ticket->id}/approve", [
            'sla_hours' => 24, 'unit_id' => $unit->id, 'assigned_to_id' => $globalAdmin->id, 'task_scope' => 'simple', 'task_points' => 30,
        ])->assertOk();
    }

    public function test_unauthorized_user_still_cannot_approve_with_unit_and_member(): void
    {
        $dept = $this->makeDepartment();
        $unit = $this->makeUnit($dept);
        $member = $this->makeUnitMember($dept, $unit);
        $outsider = $this->makeUser(); // not the department's leader, not global
        $ticket = $this->makeTicket($dept);

        $this->actingAs($outsider)->postJson("/api/v1/tickets/{$ticket->id}/approve", [
            'sla_hours' => 24, 'unit_id' => $unit->id, 'assigned_to_id' => $member->id,
        ])->assertStatus(403);
    }
}
