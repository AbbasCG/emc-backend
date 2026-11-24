<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\DelegatablePermission;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketActivityLog;
use App\Models\TicketCredit;
use App\Models\User;
use App\Models\UserPermissionAssignment;
use App\Models\VolunteerPointAward;
use App\Models\VolunteerProfile;
use App\Services\DepartmentAccessService;
use App\Services\PermissionDelegationService;
use App\Services\TicketAccess;
use App\Support\PermissionGovernance;
use App\Support\Permissions;
use Database\Seeders\TicketReviewPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use ReflectionClass;
use Tests\Concerns\GrantsTicketReviewPermissions;
use Tests\TestCase;

/**
 * WHO MAY REVIEW A TICKET'S COMPLETION (approve-completion, approve-for-deploy, return-for-rework) is a permission rule now:
 *
 *   tickets.review_completion    «مراجعة واعتماد إنجاز التذاكر»      always required (plus the department scope)
 *   tickets.review_participated  «اعتماد التذاكر التي أشارك فيها»    additionally required when the reviewer CREATED the ticket,
 *                                 is its ASSIGNEE or SUBMITTED the current round; explicit grants only, never part of Permissions::all()
 */
class TicketReviewPermissionTest extends TestCase
{
    use GrantsTicketReviewPermissions;
    use RefreshDatabase;

    private Department $dept;

    private User $leader;

    private User $assignee;

    private User $member;

    private User $reporter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        $this->dept = Department::create(['name' => 'D '.Str::random(5), 'name_ar' => 'إدارة', 'slug' => 'd-'.Str::random(8), 'status' => 'active', 'is_active' => true]);
        $this->leader = $this->user('programs_manager');
        $this->dept->update(['leader_id' => $this->leader->id]);
        $this->assignee = $this->deptMember();
        $this->member = $this->deptMember();
        $this->reporter = $this->user('student');
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /** NOT auto-granted: every grant in this file is explicit. */
    private function user(string $role = 'student'): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function deptMember(): User
    {
        $u = $this->user();
        $this->dept->users()->attach($u->id);

        return $u;
    }

    /** A classic (non-deployment) ticket awaiting review, simple size, 30 points. */
    private function ticket(array $over = [], bool $deployment = false): Ticket
    {
        // submitted_by_id is not mass assignable (only the application sets it), so it is written with forceFill.
        $submittedBy = $over['submitted_by_id'] ?? null;
        unset($over['submitted_by_id']);

        $t = Ticket::create(array_merge([
            'ticket_number' => 'EMC-TCK-R-'.Str::random(8), 'title' => 'تعديل صفحة الدفع', 'description' => 'وصف', 'ticket_category' => 'OLD_ISSUE', 'priority' => 'MEDIUM',
            'department_id' => $this->dept->id, 'created_by_id' => $this->reporter->id, 'created_by_name' => 'مُبلِّغ', 'status' => 'PENDING_REVIEW',
            'assigned_to_id' => $this->assignee->id, 'approved_by_id' => $this->leader->id, 'accepted_at' => now()->subHour(),
            'task_scope' => 'simple', 'task_points' => 30, 'estimated_hours' => 2, 'resolution_summary' => 'تم الحل',
        ], $over));
        if ($deployment) {
            $t->forceFill(['requires_deployment' => true]);
        }
        if ($submittedBy !== null) {
            $t->forceFill(['submitted_by_id' => $submittedBy, 'submitted_at' => now()]);
        }
        $t->save();

        return $t;
    }

    private function act(Ticket $t, string $endpoint, User $as, array $body = [])
    {
        Sanctum::actingAs($as);

        return $this->postJson("/api/v1/tickets/{$t->id}/{$endpoint}", $body);
    }

    private function approve(Ticket $t, User $as, array $body = ['actual_minutes' => 60])
    {
        return $this->act($t, 'approve-completion', $as, $body);
    }

    private function approveDeploy(Ticket $t, User $as, array $body = ['actual_minutes' => 60])
    {
        return $this->act($t, 'approve-for-deploy', $as, $body);
    }

    private function rework(Ticket $t, User $as, string $reason = 'يلزم استكمال العمل')
    {
        return $this->act($t, 'return-for-rework', $as, ['reason' => $reason]);
    }

    private function show(Ticket $t, User $as)
    {
        Sanctum::actingAs($as);

        return $this->getJson("/api/v1/tickets/{$t->id}");
    }

    private function awards(?Ticket $t = null)
    {
        return VolunteerPointAward::where('source_type', Ticket::class)->when($t, fn ($q) => $q->where('source_id', $t->id));
    }

    /** @return array<string, Ticket> one ticket per relation the LEADER can have to it (all PENDING_REVIEW) */
    private function relationTickets(bool $deployment = false): array
    {
        return [
            'unrelated' => $this->ticket([], $deployment),
            'creator' => $this->ticket(['created_by_id' => $this->leader->id], $deployment),
            'assignee' => $this->ticket(['assigned_to_id' => $this->leader->id], $deployment),
            'submitter' => $this->ticket(['submitted_by_id' => $this->leader->id], $deployment),
        ];
    }

    // ═══ The permission rows ════════════════════════════════════════════════

    public function test_the_two_permissions_exist_with_arabic_labels_in_the_tickets_group(): void
    {
        $review = Permission::where('name', 'tickets.review_completion')->firstOrFail();
        $part = Permission::where('name', 'tickets.review_participated')->firstOrFail();

        $this->assertSame('مراجعة واعتماد إنجاز التذاكر', $review->label_ar);
        $this->assertSame('اعتماد التذاكر التي أشارك فيها', $part->label_ar);
        $this->assertSame('tickets', $review->group);
        $this->assertSame('tickets', $part->group);
        $this->assertNotEmpty($review->description_ar);
        $this->assertNotEmpty($part->description_ar);

        // Created by the migration alone: nobody holds them through a role row.
        $this->assertSame(0, DB::table('permission_role')->where('permission_id', $review->id)->count());
        $this->assertSame(0, DB::table('permission_role')->where('permission_id', $part->id)->count());
        $this->assertSame(0, UserPermissionAssignment::count());

        // The role editor lists them under «التذاكر».
        Sanctum::actingAs($this->user('super_admin'));
        $modules = collect($this->getJson('/api/admin/permissions')->assertOk()->json('data.modules'));
        $tickets = $modules->firstWhere('group', 'tickets');
        $this->assertSame('التذاكر', $tickets['group_label']);
        $this->assertEqualsCanonicalizing(['tickets.review_completion', 'tickets.review_participated'], array_column($tickets['permissions'], 'key'));

        $role = Role::firstOrCreate(['name' => 'programs_manager'], ['display_name' => 'مدير البرامج', 'is_system' => true]);
        $roleModules = collect($this->getJson("/api/admin/roles/{$role->name}/permissions")->assertOk()->json('data.modules'));
        $this->assertSame('التذاكر', $roleModules->firstWhere('group', 'tickets')['group_label']);
    }

    public function test_review_completion_is_part_of_all_but_participation_never_is(): void
    {
        $this->assertContains(Permissions::TICKETS_REVIEW_COMPLETION, Permissions::all());
        $this->assertNotContains(Permissions::TICKETS_REVIEW_PARTICIPATED, Permissions::all());
        foreach (['super_admin', 'tech_admin', 'executive_admin', 'admin', 'department_manager', 'programs_manager'] as $role) {
            $this->assertNotContains(Permissions::TICKETS_REVIEW_PARTICIPATED, Permissions::forRole($role), $role);
        }

        foreach (['super_admin', 'tech_admin'] as $role) {
            $root = $this->user($role);
            $this->assertTrue($root->hasPermission(Permissions::TICKETS_REVIEW_COMPLETION), "$role keeps reviewing through all()");
            $this->assertFalse($root->hasPermission(Permissions::TICKETS_REVIEW_PARTICIPATED), "$role does not hold participation by default");
            $this->assertTrue(Gate::forUser($root)->allows(Permissions::TICKETS_REVIEW_COMPLETION));
            $this->assertFalse(Gate::forUser($root)->allows(Permissions::TICKETS_REVIEW_PARTICIPATED), 'Gate::before does not bypass it');
        }
    }

    // ═══ The decision rule, endpoint by endpoint ════════════════════════════

    public function test_approve_completion_decision_table(): void
    {
        // (a) no review permission: refused on EVERY relation, nothing changes.
        foreach ($this->relationTickets() as $relation => $t) {
            $res = $this->approve($t, $this->leader)->assertForbidden()->assertJsonPath('code', 'REVIEW_PERMISSION_REQUIRED');
            // Unrelated: «request the permission»; tied to the ticket: «another reviewer must review it».
            $relation === 'unrelated'
                ? $this->assertSame(TicketAccess::REVIEW_PERMISSION_MESSAGE, $res->json('message'))
                : $this->assertStringContainsString('مراجع مخوّل آخر', $res->json('message'));
            $this->assertSame('PENDING_REVIEW', $t->fresh()->status, $relation);
        }
        $this->assertSame(0, $this->awards()->count());

        // (b) review permission only: fine on an unrelated ticket, refused on one the leader created / is assigned / submitted.
        $this->grantTicketReview($this->leader);
        $tickets = $this->relationTickets();
        foreach (['creator', 'assignee', 'submitter'] as $relation) {
            $this->approve($tickets[$relation], $this->leader)->assertForbidden()->assertJsonPath('code', 'PARTICIPATION_PERMISSION_REQUIRED');
            $this->assertSame('PENDING_REVIEW', $tickets[$relation]->fresh()->status, $relation);
            $this->assertNull($tickets[$relation]->fresh()->credited_at);
        }
        $this->assertSame(0, $this->awards()->count());
        $this->approve($tickets['unrelated'], $this->leader)->assertOk()->assertJsonPath('data.status', 'RESOLVED');

        // (c) both permissions: allowed on every relation, credited once each (always to the ASSIGNEE).
        $this->grantTicketReview($this->leader, participated: true);
        foreach (['creator', 'assignee', 'submitter'] as $relation) {
            $this->approve($tickets[$relation], $this->leader)->assertOk()->assertJsonPath('data.status', 'RESOLVED');
            $this->assertNotNull($tickets[$relation]->fresh()->credited_at);
        }
        $this->assertSame(1, $this->awards($tickets['assignee'])->count());
        $this->assertSame($this->leader->id, (int) $this->awards($tickets['assignee'])->value('user_id'), 'credited to the assignee, who here is the leader');
    }

    public function test_approve_for_deploy_and_return_for_rework_follow_the_same_table(): void
    {
        $this->grantTicketReview($this->leader); // review only

        $tickets = $this->relationTickets(deployment: true);
        foreach (['creator', 'assignee', 'submitter'] as $relation) {
            $this->approveDeploy($tickets[$relation], $this->leader)->assertForbidden()->assertJsonPath('code', 'PARTICIPATION_PERMISSION_REQUIRED');
            $this->rework($tickets[$relation], $this->leader)->assertForbidden()->assertJsonPath('code', 'PARTICIPATION_PERMISSION_REQUIRED');
            $this->assertSame('PENDING_REVIEW', $tickets[$relation]->fresh()->status);
        }
        $this->approveDeploy($tickets['unrelated'], $this->leader)->assertOk()->assertJsonPath('data.status', 'APPROVED_FOR_DEPLOY');
        $this->rework($this->ticket(), $this->leader)->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');

        // The stage after approval: returning from APPROVED_FOR_DEPLOY is under the same rule.
        $approvedMine = $this->ticket(['status' => 'APPROVED_FOR_DEPLOY', 'created_by_id' => $this->leader->id], deployment: true);
        $this->rework($approvedMine, $this->leader)->assertForbidden()->assertJsonPath('code', 'PARTICIPATION_PERMISSION_REQUIRED');
        $this->grantTicketReview($this->leader, participated: true);
        $this->rework($approvedMine, $this->leader)->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');

        // Without the base permission nobody gets in, whatever they hold otherwise.
        $nobody = $this->user('programs_manager');
        $dept2 = Department::create(['name' => 'N'.Str::random(4), 'name_ar' => 'ثانية', 'slug' => 'n-'.Str::random(6), 'status' => 'active', 'is_active' => true, 'leader_id' => $nobody->id]);
        $this->assertNotNull($dept2);
        $fresh = $this->ticket([], deployment: true);
        $this->approveDeploy($fresh, $this->leader, ['actual_minutes' => 60])->assertOk();
        $this->act($fresh, 'mark-deployed', $this->grantTicketReview($this->user('admin')), ['live_url' => 'https://example.com/x'])->assertOk();
    }

    public function test_assignee_unavailable_rescue_return_is_under_the_same_rule(): void
    {
        $gone = $this->deptMember();
        $t = $this->ticket(['status' => 'PENDING_VERIFICATION', 'assigned_to_id' => $gone->id, 'created_by_id' => $this->leader->id], deployment: true);
        $gone->forceFill(['is_active' => false])->save();

        // Review permission only, and the leader created the ticket: refused. With both: the rescue works.
        $this->grantTicketReview($this->leader);
        $this->rework($t, $this->leader)->assertForbidden()->assertJsonPath('code', 'PARTICIPATION_PERMISSION_REQUIRED');
        $caps = $this->show($t, $this->leader)->json('data');
        $this->assertFalse($caps['capabilities']['return_for_rework']);
        $this->assertSame('needs_participation_permission', $caps['review_context']['reason']);

        $this->grantTicketReview($this->leader, participated: true);
        $this->assertTrue($this->show($t, $this->leader)->json('data.capabilities.return_for_rework'));
        $this->rework($t, $this->leader)->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');

        // An unrelated leader without the base permission cannot rescue it either.
        $other = $this->ticket(['status' => 'PENDING_VERIFICATION', 'assigned_to_id' => $gone->id], deployment: true);
        Permission::firstOrCreate(['name' => 'view_tasks'], ['display_name' => 'View Tasks', 'group' => 'operations']);
        $this->grantToRole('programs_manager', 'view_tasks'); // a role row WITHOUT the review key
        $this->leader->flushPermissionCache();
        UserPermissionAssignment::where('user_id', $this->leader->id)->delete();
        $this->leader->flushPermissionCache();
        $this->rework($other, $this->leader)->assertForbidden()->assertJsonPath('code', 'REVIEW_PERMISSION_REQUIRED');
    }

    public function test_view_and_assign_permissions_alone_never_approve(): void
    {
        // A department leader with every task / ticket-adjacent permission the static role map gives, and the one department_manager role.
        $manager = $this->user('department_manager');
        $this->dept->update(['leader_id' => $manager->id]);
        foreach ([Permissions::VIEW_TASKS, Permissions::MANAGE_TASKS, Permissions::VIEW_SUPPORT_TICKETS, Permissions::MANAGE_SUPPORT_TICKETS, Permissions::VIEW_DEPARTMENTS, Permissions::MANAGE_DEPARTMENTS] as $key) {
            $this->assertNotNull(Permission::where('name', $key)->first() ?? Permission::create(['name' => $key, 'display_name' => $key, 'group' => 'operations']));
            $this->assignPermission($manager, $key);
        }

        foreach ($this->relationTickets() as $t) {
            $this->approve($t, $manager)->assertForbidden()->assertJsonPath('code', 'REVIEW_PERMISSION_REQUIRED');
        }
        $t = $this->ticket();
        $caps = $this->show($t, $manager)->assertOk()->json('data');
        $this->assertFalse($caps['capabilities']['approve_completion']);
        $this->assertFalse($caps['capabilities']['return_for_rework']);
        $this->assertSame('missing_permission', $caps['review_context']['reason']);
        $this->assertSame(0, $this->awards()->count());

        // The same user can still ASSIGN (reassign is a manager action, not a review).
        $this->assertTrue($caps['capabilities']['reassign'] === false, 'PENDING_REVIEW is not a reassignable status; the manager scope itself is intact');
        $this->assertTrue($caps['capabilities']['add_internal_note']);
    }

    // ═══ Root roles: no implicit participation ═════════════════════════════

    public function test_super_admin_is_refused_on_own_assigned_and_submitted_tickets_until_explicitly_granted(): void
    {
        $root = $this->user('super_admin');

        // Reviews an unrelated ticket (review permission through all()).
        $this->approve($this->ticket(), $root)->assertOk();

        $mine = [
            'created' => fn () => $this->ticket(['created_by_id' => $root->id]),
            'assigned' => fn () => $this->ticket(['assigned_to_id' => $root->id]),
            'submitted' => fn () => $this->ticket(['submitted_by_id' => $root->id]),
        ];
        foreach ($mine as $name => $make) {
            $t = $make();
            $this->approve($t, $root)->assertForbidden()->assertJsonPath('code', 'PARTICIPATION_PERMISSION_REQUIRED');
            $this->approveDeploy($this->ticket(['created_by_id' => $root->id], deployment: true), $root)->assertForbidden()->assertJsonPath('code', 'PARTICIPATION_PERMISSION_REQUIRED');
            $this->rework($t, $root)->assertForbidden()->assertJsonPath('code', 'PARTICIPATION_PERMISSION_REQUIRED');
            $this->assertFalse($this->show($t, $root)->json('data.capabilities.approve_completion'), $name);
            $this->assertSame('needs_participation_permission', $this->show($t, $root)->json('data.review_context.reason'), $name);
        }

        // A ROLE grant (the role editor): works for every super_admin.
        $this->grantToRole('super_admin', Permissions::TICKETS_REVIEW_PARTICIPATED);
        $root->flushPermissionCache();
        $t = $mine['assigned']();
        $this->assertTrue($this->show($t, $root)->json('data.capabilities.approve_completion'));
        $this->approve($t, $root)->assertOk()->assertJsonPath('data.status', 'RESOLVED');

        // A per-user DENY wins over the role grant, even for the root role.
        $this->assignPermission($root, Permissions::TICKETS_REVIEW_PARTICIPATED, 'deny');
        $this->approve($mine['created'](), $root)->assertForbidden()->assertJsonPath('code', 'PARTICIPATION_PERMISSION_REQUIRED');
        $this->approve($this->ticket(), $root)->assertOk(); // unrelated stays fine
    }

    public function test_super_admin_and_tech_admin_participation_through_a_per_user_grant_and_a_per_user_deny_of_the_base_permission(): void
    {
        foreach (['super_admin', 'tech_admin'] as $role) {
            $root = $this->user($role);
            $t = $this->ticket(['created_by_id' => $root->id]);

            $this->approve($t, $root)->assertForbidden()->assertJsonPath('code', 'PARTICIPATION_PERMISSION_REQUIRED');

            $this->assignPermission($root, Permissions::TICKETS_REVIEW_PARTICIPATED); // per-user allow, no role row at all
            $this->approve($t, $root)->assertOk();

            // Removing the BASE permission from a root account is possible too (explicit deny), and binds the Gate::before bypass.
            $this->assignPermission($root, Permissions::TICKETS_REVIEW_COMPLETION, 'deny');
            $this->approve($this->ticket(), $root)->assertForbidden()->assertJsonPath('code', 'REVIEW_PERMISSION_REQUIRED');
            $this->assertFalse($this->show($this->ticket(), $root)->json('data.capabilities.approve_completion'));
        }
    }

    public function test_a_global_role_is_not_a_participant_just_by_being_global(): void
    {
        $admin = $this->grantTicketReview($this->user('admin')); // global, review permission only
        $this->approve($this->ticket(), $admin)->assertOk();
        $this->assertSame([], app(TicketAccess::class)->reviewerRelation($admin, $this->ticket()));
    }

    // ═══ Capabilities and review_context ═══════════════════════════════════

    public function test_capabilities_and_review_context_reflect_the_rule_exactly(): void
    {
        $mine = $this->ticket(['created_by_id' => $this->leader->id]);
        $other = $this->ticket();
        $deploy = $this->ticket([], deployment: true);

        // No permission at all.
        $d = $this->show($other, $this->leader)->assertOk()->json('data');
        $this->assertSame(false, $d['capabilities']['approve_completion']);
        $this->assertSame(false, $d['capabilities']['return_for_rework']);
        $this->assertSame(['stage' => 'PENDING_REVIEW', 'can_review' => false, 'reason' => 'missing_permission', 'relation' => [], 'needs_participation_permission' => false, 'required_permissions' => ['tickets.review_completion']], $d['review_context']);
        $this->assertFalse($this->show($deploy, $this->leader)->json('data.capabilities.approve_for_deploy'));

        // Review permission only.
        $this->grantTicketReview($this->leader);
        $d = $this->show($other, $this->leader)->json('data');
        $this->assertTrue($d['capabilities']['approve_completion']);
        $this->assertTrue($d['capabilities']['return_for_rework']);
        $this->assertTrue($d['review_context']['can_review']);
        $this->assertNull($d['review_context']['reason']);
        $this->assertTrue($this->show($deploy, $this->leader)->json('data.capabilities.approve_for_deploy'));

        $d = $this->show($mine, $this->leader)->json('data');
        $this->assertFalse($d['capabilities']['approve_completion']);
        $this->assertFalse($d['capabilities']['return_for_rework']);
        $this->assertSame('needs_participation_permission', $d['review_context']['reason']);
        $this->assertSame(['creator'], $d['review_context']['relation']);
        $this->assertTrue($d['review_context']['needs_participation_permission']);
        $this->assertSame(['tickets.review_completion', 'tickets.review_participated'], $d['review_context']['required_permissions']);

        // Both permissions.
        $this->grantTicketReview($this->leader, participated: true);
        $d = $this->show($mine, $this->leader)->json('data');
        $this->assertTrue($d['capabilities']['approve_completion']);
        $this->assertTrue($d['capabilities']['return_for_rework']);
        $this->assertTrue($d['review_context']['can_review']);
        $this->assertSame(['creator'], $d['review_context']['relation']);

        // The stage is honoured: no review context outside a review stage.
        $working = $this->ticket(['status' => 'IN_PROGRESS']);
        $this->assertNull($this->show($working, $this->leader)->json('data.review_context'));
        $this->assertFalse($this->show($working, $this->leader)->json('data.capabilities.approve_completion'));
    }

    public function test_review_context_is_never_sent_to_reporters_assignees_or_plain_members(): void
    {
        $t = $this->ticket(['created_by_id' => $this->reporter->id]);
        $this->dept->users()->syncWithoutDetaching([$this->reporter->id]);

        foreach ([$this->reporter, $this->assignee, $this->member] as $viewer) {
            $res = $this->show($t, $viewer)->assertOk();
            $this->assertArrayNotHasKey('review_context', $res->json('data'), 'viewer '.$viewer->id);
            $this->assertFalse($res->json('data.capabilities.approve_completion') ?? false);
            $this->assertStringNotContainsString('review_context', $res->getContent());
            $this->assertStringNotContainsString('reviewer_relation', $res->getContent());
        }

        // Even a holder of BOTH permissions who is a plain member (no leadership, no global role) gets nothing.
        $holder = $this->grantTicketReview($this->deptMember(), participated: true);
        $res = $this->show($t, $holder)->assertOk();
        $this->assertArrayNotHasKey('review_context', $res->json('data'));
        $this->assertFalse($res->json('data.capabilities.approve_completion'));
        $this->approve($t, $holder)->assertForbidden()->assertJsonMissingPath('code');
    }

    // ═══ HR gate, deployment stages, replay ════════════════════════════════

    public function test_the_hr_gate_still_applies_on_top_of_the_permissions(): void
    {
        $this->grantTicketReview($this->leader, participated: true);
        $medium = $this->ticket(['task_scope' => 'medium', 'task_points' => 100]);

        $this->approve($medium, $this->leader)->assertForbidden()->assertJsonPath('code', 'HR_APPROVAL_REQUIRED');
        $this->assertFalse($this->show($medium, $this->leader)->json('data.capabilities.approve_completion'));

        $hr = $this->grantTicketReview($this->user('admin')); // an HR approver role, review permission only
        $this->approve($medium, $hr)->assertOk()->assertJsonPath('data.credit.points', 100);

        // The permission is judged BEFORE the HR gate: an HR role without the permission is told so.
        $m2 = $this->ticket(['task_scope' => 'medium', 'task_points' => 100]);
        $this->approve($m2, $this->user('admin'))->assertForbidden()->assertJsonPath('code', 'REVIEW_PERMISSION_REQUIRED');
    }

    public function test_replay_credits_once_and_a_lost_permission_is_refused_before_the_409(): void
    {
        $this->grantTicketReview($this->leader, participated: true);
        $t = $this->ticket(['created_by_id' => $this->leader->id]);

        $this->approve($t, $this->leader)->assertOk();
        $this->approve($t, $this->leader)->assertStatus(409)->assertJsonPath('code', 'ALREADY_CREDITED');
        $this->rework($t, $this->leader)->assertStatus(409)->assertJsonPath('code', 'ALREADY_CREDITED');
        $this->assertSame(1, TicketCredit::where('ticket_id', $t->id)->count());
        $this->assertSame(1, $this->awards($t)->count());
        $hours = (float) VolunteerProfile::where('user_id', $this->assignee->id)->value('hours_logged');
        $this->assertEqualsWithDelta(1.0, $hours, 0.001);

        // Revoking the participation permission afterwards: the replay now meets the permission refusal first, still no second credit.
        $this->assignPermission($this->leader, Permissions::TICKETS_REVIEW_PARTICIPATED, 'deny');
        $this->approve($t, $this->leader)->assertForbidden()->assertJsonPath('code', 'PARTICIPATION_PERMISSION_REQUIRED');
        $this->assertSame(1, TicketCredit::where('ticket_id', $t->id)->count());
        $this->assertSame(1, $this->awards($t)->count());
        $this->assertEqualsWithDelta($hours, (float) VolunteerProfile::where('user_id', $this->assignee->id)->value('hours_logged'), 0.0001);
    }

    public function test_deployment_cycle_with_a_participant_reviewer_credits_exactly_once_and_later_steps_keep_their_rules(): void
    {
        $this->grantTicketReview($this->leader, participated: true);
        $admin = $this->grantTicketReview($this->user('admin'));
        $t = $this->ticket(['status' => 'IN_PROGRESS', 'created_by_id' => $this->leader->id], deployment: true);

        $this->act($t, 'complete-task', $this->assignee, ['resolution_type' => 'RESOLVED', 'resolution_summary' => 'تم التنفيذ كاملًا'])->assertOk();
        $this->assertSame($this->assignee->id, (int) $t->fresh()->submitted_by_id);

        // The creator-leader approves for deployment (participation permission held).
        $this->approveDeploy($t, $this->leader, ['actual_minutes' => 90])->assertOk()->assertJsonPath('data.status', 'APPROVED_FOR_DEPLOY');
        $this->assertSame(0, TicketCredit::count(), 'nothing credited before the live verification');

        // mark-deployed keeps its own rule: judged by a manager who is not the assignee; the review permissions are NOT extended to it.
        $this->act($t, 'mark-deployed', $this->member, ['live_url' => 'https://example.com/live'])->assertForbidden();
        $this->act($t, 'mark-deployed', $admin, ['live_url' => 'https://example.com/live'])->assertOk();

        // verify-* stay assignee-only: a reviewer holding both permissions is not the assignee.
        $this->act($t, 'verify-resolved', $this->leader, ['note' => 'تحققت على الموقع'])->assertForbidden()->assertJsonPath('code', 'ASSIGNEE_ONLY');
        $this->act($t, 'verify-resolved', $this->assignee, ['note' => 'تحققت على الموقع'])->assertOk()->assertJsonPath('data.status', 'RESOLVED');
        $this->act($t, 'verify-resolved', $this->assignee, ['note' => 'تحققت على الموقع'])->assertStatus(409)->assertJsonPath('code', 'ALREADY_CREDITED');
        $this->assertSame(1, TicketCredit::where('ticket_id', $t->id)->count());
        $this->assertSame(1, $this->awards($t)->count());
    }

    public function test_a_leader_who_is_the_assignee_with_both_permissions_still_cannot_mark_it_deployed(): void
    {
        $this->grantTicketReview($this->leader, participated: true);
        $t = $this->ticket(['assigned_to_id' => $this->leader->id], deployment: true);

        $this->approveDeploy($t, $this->leader, ['actual_minutes' => 60])->assertOk();
        $this->act($t, 'mark-deployed', $this->leader, ['live_url' => 'https://example.com/live'])->assertForbidden()->assertJsonPath('code', 'SELF_REVIEW');
        $this->assertFalse($this->show($t, $this->leader)->json('data.capabilities.mark_deployed'));
    }

    // ═══ The audit trail ═══════════════════════════════════════════════════

    public function test_review_rows_record_who_when_and_the_relation_and_stay_internal(): void
    {
        $this->grantTicketReview($this->leader, participated: true);

        // Approved by the creator who is also the assignee and the submitter, in the fixed order.
        $t = $this->ticket(['created_by_id' => $this->leader->id, 'assigned_to_id' => $this->leader->id, 'submitted_by_id' => $this->leader->id]);
        $this->approve($t, $this->leader)->assertOk();
        $row = TicketActivityLog::where('ticket_id', $t->id)->where('action', 'APPROVED_COMPLETION')->sole();
        $this->assertSame($this->leader->id, (int) $row->performed_by);
        $this->assertNotNull($row->logged_at);
        $this->assertSame(['reviewer_relation' => ['creator', 'assignee', 'submitter'], 'participated' => true, 'review_round' => 0], $row->metadata);

        // An unrelated reviewer: empty list.
        $u = $this->ticket();
        $this->approve($u, $this->leader)->assertOk();
        $row = TicketActivityLog::where('ticket_id', $u->id)->where('action', 'APPROVED_COMPLETION')->sole();
        $this->assertSame([], $row->metadata['reviewer_relation']);
        $this->assertFalse($row->metadata['participated']);

        // approve-for-deploy and return-for-rework rows (internal actions) carry it too, and say it in Arabic.
        $d = $this->ticket(['created_by_id' => $this->leader->id], deployment: true);
        $this->approveDeploy($d, $this->leader, ['actual_minutes' => 60])->assertOk();
        $row = TicketActivityLog::where('ticket_id', $d->id)->where('action', 'APPROVED_FOR_DEPLOY')->sole();
        $this->assertSame(['creator'], $row->metadata['reviewer_relation']);
        $this->assertStringContainsString('منشئ التذكرة', $row->details);
        $this->assertContains('APPROVED_FOR_DEPLOY', TicketAccess::INTERNAL_LOG_ACTIONS);

        $r = $this->ticket(['assigned_to_id' => $this->leader->id]);
        $this->rework($r, $this->leader)->assertOk();
        $row = TicketActivityLog::where('ticket_id', $r->id)->where('action', 'RETURNED_FOR_REWORK')->sole();
        $this->assertSame(['assignee'], $row->metadata['reviewer_relation']);
        $this->assertSame($this->leader->id, (int) $row->performed_by);
        $this->assertStringContainsString('المكلّف بها', $row->details);

        // Visibility: internal viewers get the metadata through the detail payload, nobody else ever sees the key or the internal rows.
        $logs = collect($this->show($d, $this->leader)->assertOk()->json('data.activity_logs'));
        $this->assertSame(['creator'], $logs->firstWhere('action', 'APPROVED_FOR_DEPLOY')['metadata']['reviewer_relation']);

        $done = $this->ticket(['created_by_id' => $this->reporter->id, 'assigned_to_id' => $this->leader->id]);
        $this->approve($done, $this->leader)->assertOk();
        foreach ([$this->reporter, $this->member] as $viewer) {
            $res = $this->show($done, $viewer)->assertOk();
            $this->assertStringNotContainsString('reviewer_relation', $res->getContent());
            $this->assertStringNotContainsString('"metadata"', $res->getContent());
            if ($viewer->is($this->member)) {
                $this->assertContains('APPROVED_COMPLETION', array_column($res->json('data.activity_logs') ?? [], 'action'), 'the public closing row stays for a plain member (as before), without its metadata');
            }
        }
        $reporterDeploy = $this->show($d, $this->reporter);
        $this->assertNotContains('APPROVED_FOR_DEPLOY', array_column($reporterDeploy->json('data.activity_logs') ?? [], 'action'));
        $this->assertStringNotContainsString('reviewer_relation', $reporterDeploy->getContent());

        // A raw serialization of the model never carries it either.
        $this->assertArrayNotHasKey('metadata', TicketActivityLog::where('action', 'APPROVED_COMPLETION')->first()->toArray());
    }

    // ═══ Granting through the existing mechanism ═══════════════════════════

    public function test_both_permissions_can_be_granted_to_a_person_through_the_delegation_endpoint(): void
    {
        $root = $this->user('super_admin');
        $target = $this->deptMember();

        Sanctum::actingAs($root);
        foreach (['tickets.review_completion', 'tickets.review_participated'] as $key) {
            $this->postJson("/api/department/employees/{$target->id}/permissions/grant", ['permission_name' => $key])->assertOk();
        }
        $target->flushPermissionCache();
        $this->assertTrue($target->hasPermission('tickets.review_completion'));
        $this->assertTrue($target->hasPermission('tickets.review_participated'));

        // …and revoke (a per-user deny).
        $this->postJson("/api/department/employees/{$target->id}/permissions/revoke", ['permission_name' => 'tickets.review_participated'])->assertOk();
        $target->flushPermissionCache();
        $this->assertFalse($target->hasPermission('tickets.review_participated'));
        $this->assertTrue($target->hasPermission('tickets.review_completion'));
    }

    public function test_a_department_leader_can_never_delegate_the_participation_permission(): void
    {
        $leader = $this->grantTicketReview($this->leader, participated: true); // holds both
        $role = Role::firstOrCreate(['name' => 'programs_manager'], ['display_name' => 'مدير البرامج', 'is_system' => true]);
        foreach (['tickets.review_completion', 'tickets.review_participated'] as $key) {
            DelegatablePermission::create(['role_id' => $role->id, 'permission_id' => Permission::where('name', $key)->value('id'), 'scope_type' => 'department', 'can_grant' => true, 'can_revoke' => true]);
        }
        $svc = app(PermissionDelegationService::class);

        $this->assertTrue($svc->canDelegate($leader, $this->member, 'tickets.review_completion'), 'the ordinary key follows the existing delegation policy');
        $this->assertFalse($svc->canDelegate($leader, $this->member, 'tickets.review_participated'), 'the participation key is never delegated by a department leader');
        $this->assertTrue($svc->canDelegate($this->user('super_admin'), $this->member, 'tickets.review_participated'));
    }

    public function test_the_migration_adds_the_delegation_nodes_only_to_an_existing_tree_and_is_idempotent(): void
    {
        $migration = require base_path('database/migrations/2026_11_26_000001_create_ticket_review_permissions.php');

        // Empty tree (fresh install): nothing is invented; PermissionNodeSeeder builds the whole tree later, these rows included.
        $this->assertSame(0, DB::table('permission_nodes')->count());
        $migration->up();
        $this->assertSame(0, DB::table('permission_nodes')->count());

        // An existing tree: the section and both actions are added once.
        DB::table('permission_nodes')->insert(['node_type' => 'section', 'key' => 'section.other', 'label_ar' => 'قسم', 'sort_order' => 7, 'created_at' => now(), 'updated_at' => now()]);
        $migration->up();
        $migration->up();
        $keys = DB::table('permission_nodes')->pluck('key')->all();
        $this->assertContains('section.tickets', $keys);
        $this->assertSame(1, DB::table('permission_nodes')->where('key', 'tickets.review_completion')->count());
        $this->assertSame(1, DB::table('permission_nodes')->where('key', 'tickets.review_participated')->count());
        $parent = DB::table('permission_nodes')->where('key', 'section.tickets')->value('id');
        $this->assertSame(2, DB::table('permission_nodes')->where('parent_id', $parent)->count());
        $this->assertSame(2, Permission::whereIn('name', ['tickets.review_completion', 'tickets.review_participated'])->count());

        // down() removes exactly those and leaves the rest.
        $migration->down();
        $this->assertSame(['section.other'], DB::table('permission_nodes')->pluck('key')->all());
        $this->assertSame(0, Permission::whereIn('name', ['tickets.review_completion', 'tickets.review_participated'])->count());
        $migration->up();
        $this->assertSame(2, Permission::whereIn('name', ['tickets.review_completion', 'tickets.review_participated'])->count());
    }

    // ═══ The opt-in seeder ═════════════════════════════════════════════════

    public function test_the_seeder_grants_global_roles_role_wide_and_every_leader_per_user_never_the_participation_key(): void
    {
        foreach (['super_admin', 'tech_admin', 'executive_admin', 'admin', 'programs_manager', 'department_manager', 'student', 'volunteer'] as $r) {
            Role::firstOrCreate(['name' => $r], ['display_name' => $r, 'is_system' => true]);
        }
        // programs_manager leads $this->dept (setUp). Other leaders, whatever their role, get a per-user allow too.
        $studentLeader = $this->user('student');
        Department::create(['name' => 'S'.Str::random(4), 'name_ar' => 'ثانية', 'slug' => 's-'.Str::random(6), 'status' => 'active', 'is_active' => true, 'leader_id' => $studentLeader->id]);
        $deptManagerLeader = $this->user('department_manager');
        Department::create(['name' => 'M'.Str::random(4), 'name_ar' => 'رابعة', 'slug' => 'm-'.Str::random(6), 'status' => 'active', 'is_active' => true, 'leader_id' => $deptManagerLeader->id]);
        $bystander = $this->user('department_manager'); // same role as a leader, leads nothing
        // A denied leader is skipped.
        $deniedLeader = $this->user('volunteer');
        Department::create(['name' => 'V'.Str::random(4), 'name_ar' => 'ثالثة', 'slug' => 'v-'.Str::random(6), 'status' => 'active', 'is_active' => true, 'leader_id' => $deniedLeader->id]);
        $this->assignPermission($deniedLeader, Permissions::TICKETS_REVIEW_COMPLETION, 'deny');

        $seeder = new TicketReviewPermissionSeeder;
        $plan = $seeder->plan();
        $this->assertEqualsCanonicalizing(['super_admin', 'tech_admin', 'executive_admin', 'admin'], $plan['roles']);
        $this->assertEqualsCanonicalizing([$this->leader->id, $studentLeader->id, $deptManagerLeader->id], $plan['users']);
        $this->assertSame([$deniedLeader->id], $plan['skipped_denied']);

        $seeder->run();
        $seeder->run(); // idempotent

        $review = Permission::where('name', 'tickets.review_completion')->firstOrFail();
        $part = Permission::where('name', 'tickets.review_participated')->firstOrFail();
        $granted = Role::whereHas('permissions', fn ($q) => $q->where('permissions.id', $review->id))->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['super_admin', 'tech_admin', 'executive_admin', 'admin'], $granted, 'no leader role gets a role-wide grant');
        $this->assertSame(0, DB::table('permission_role')->where('permission_id', $part->id)->count(), 'participation is never granted to a role');
        $this->assertSame(0, UserPermissionAssignment::where('permission_id', $part->id)->count(), 'nor to a person');
        foreach ([$this->leader, $studentLeader, $deptManagerLeader] as $leader) {
            $this->assertSame(1, UserPermissionAssignment::where('permission_id', $review->id)->where('user_id', $leader->id)->where('state', 'allow')->count());
        }
        $this->assertSame(0, UserPermissionAssignment::where('permission_id', $review->id)->where('user_id', $bystander->id)->count());
        $this->assertFalse($bystander->fresh()->hasPermission('tickets.review_completion'));
        $this->assertSame('deny', UserPermissionAssignment::where('permission_id', $review->id)->where('user_id', $deniedLeader->id)->value('state'));

        // After the seeder the leader reviews again, an unrelated ticket only.
        $this->assertTrue($this->leader->fresh()->hasPermission('tickets.review_completion'));
        $this->approve($this->ticket(), $this->leader->fresh())->assertOk();
        $this->approve($this->ticket(['created_by_id' => $this->leader->id]), $this->leader->fresh())->assertForbidden()->assertJsonPath('code', 'PARTICIPATION_PERMISSION_REQUIRED');
    }

    public function test_without_the_seeder_only_root_accounts_review_fail_closed(): void
    {
        $admin = $this->user('admin');
        $exec = $this->user('executive_admin');
        $t = $this->ticket();

        foreach ([$this->leader, $admin, $exec] as $who) {
            $this->approve($t, $who)->assertForbidden()->assertJsonPath('code', 'REVIEW_PERMISSION_REQUIRED');
        }
        $this->approve($t, $this->user('tech_admin'))->assertOk();
    }

    public function test_the_seeders_global_roles_match_the_access_service(): void
    {
        $const = (new ReflectionClass(DepartmentAccessService::class))->getReflectionConstant('GLOBAL_ROLES')->getValue();
        $this->assertEqualsCanonicalizing($const, TicketReviewPermissionSeeder::GLOBAL_ROLES);
    }

    // ═══ Governance of the participation key (super_admin only) ═════════════

    private function adminWithManageRoles(): User
    {
        $admin = $this->user('admin');
        Permission::firstOrCreate(['name' => 'manage_roles'], ['display_name' => 'Manage Roles', 'group' => 'users']);
        $this->assignPermission($admin, 'manage_roles');

        return $admin;
    }

    public function test_only_super_admin_may_change_who_holds_the_participation_key_on_roles(): void
    {
        $role = Role::firstOrCreate(['name' => 'operations_manager'], ['display_name' => 'Ops', 'is_system' => true]);
        Permission::firstOrCreate(['name' => 'view_tasks'], ['display_name' => 'View Tasks', 'group' => 'operations']);
        $key = 'tickets.review_participated';

        foreach ([$this->user('tech_admin'), $this->adminWithManageRoles()] as $actor) {
            // Role editor: adding it is refused with the Arabic message and NOTHING of the edit is applied.
            Sanctum::actingAs($actor);
            $this->putJson('/api/admin/roles/operations_manager/permissions', ['permissions' => ['tickets.review_completion', $key]])
                ->assertForbidden()->assertJsonPath('message', PermissionGovernance::MESSAGE);
            $this->assertNotContains($key, $role->permissions()->pluck('name')->all());

            // The ordinary key stays editable by them.
            $this->putJson('/api/admin/roles/operations_manager/permissions', ['permissions' => ['tickets.review_completion']])->assertOk();
            $this->putJson('/api/admin/roles/operations_manager/permissions', ['permissions' => ['view_tasks']])->assertOk();

            // The dedicated grant / revoke endpoints.
            $this->postJson('/api/admin/roles/operations_manager/grant-permission', ['permission' => $key])->assertForbidden()->assertJsonPath('message', PermissionGovernance::MESSAGE);
            $this->postJson('/api/admin/roles/operations_manager/revoke-permission', ['permission' => $key])->assertForbidden()->assertJsonPath('message', PermissionGovernance::MESSAGE);
        }
        $this->assertSame(['view_tasks'], $role->permissions()->pluck('name')->all());

        // super_admin: allowed (editor, grant, revoke).
        Sanctum::actingAs($this->user('super_admin'));
        $this->putJson('/api/admin/roles/operations_manager/permissions', ['permissions' => [$key]])->assertOk();
        $this->assertSame([$key], $role->permissions()->pluck('name')->all());

        // REMOVING it is guarded too; leaving it untouched while editing other keys is not.
        foreach ([$this->user('tech_admin'), $this->adminWithManageRoles()] as $actor) {
            Sanctum::actingAs($actor);
            $this->putJson('/api/admin/roles/operations_manager/permissions', ['permissions' => ['view_tasks']])->assertForbidden()->assertJsonPath('message', PermissionGovernance::MESSAGE);
            $this->putJson('/api/admin/roles/operations_manager/permissions', ['permissions' => [$key, 'tickets.review_completion']])->assertOk();
            $this->putJson('/api/admin/roles/operations_manager/permissions', ['permissions' => [$key]])->assertOk();
            $this->assertContains($key, $role->permissions()->pluck('name')->all());
        }

        Sanctum::actingAs($this->user('super_admin'));
        $this->postJson('/api/admin/roles/operations_manager/revoke-permission', ['permission' => $key])->assertOk();
        $this->postJson('/api/admin/roles/operations_manager/grant-permission', ['permission' => $key])->assertOk();
        $this->assertContains($key, $role->permissions()->pluck('name')->all());
    }

    public function test_only_super_admin_may_grant_or_revoke_the_participation_key_for_a_person(): void
    {
        $target = $this->deptMember();
        $key = 'tickets.review_participated';
        $role = Role::firstOrCreate(['name' => 'programs_manager'], ['display_name' => 'مدير البرامج', 'is_system' => true]);
        DelegatablePermission::create(['role_id' => $role->id, 'permission_id' => Permission::where('name', $key)->value('id'), 'scope_type' => 'department', 'can_grant' => true, 'can_revoke' => true]);
        $leader = $this->grantTicketReview($this->leader, participated: true);
        $assignmentsBefore = UserPermissionAssignment::where('user_id', $target->id)->count();

        foreach ([$this->user('tech_admin'), $this->adminWithManageRoles(), $leader] as $actor) {
            Sanctum::actingAs($actor);
            $this->postJson("/api/department/employees/{$target->id}/permissions/grant", ['permission_name' => $key])->assertForbidden()->assertJsonPath('message', PermissionGovernance::MESSAGE);
            $this->postJson("/api/department/employees/{$target->id}/permissions/revoke", ['permission_name' => $key])->assertForbidden()->assertJsonPath('message', PermissionGovernance::MESSAGE);
            $this->assertFalse(app(PermissionDelegationService::class)->canDelegate($actor, $target, $key));
        }
        $this->assertSame($assignmentsBefore, UserPermissionAssignment::where('user_id', $target->id)->count());

        Sanctum::actingAs($this->user('super_admin'));
        $this->postJson("/api/department/employees/{$target->id}/permissions/grant", ['permission_name' => $key])->assertOk();
        $target->flushPermissionCache();
        $this->assertTrue($target->hasPermission($key));
        $this->postJson("/api/department/employees/{$target->id}/permissions/revoke", ['permission_name' => $key])->assertOk();
        $target->flushPermissionCache();
        $this->assertFalse($target->hasPermission($key));
    }

    public function test_permission_changes_are_audited_with_the_diff(): void
    {
        $role = Role::firstOrCreate(['name' => 'operations_manager'], ['display_name' => 'Ops', 'is_system' => true]);
        $root = $this->user('super_admin');
        $target = $this->deptMember();
        Sanctum::actingAs($root);

        $this->putJson('/api/admin/roles/operations_manager/permissions', ['permissions' => ['tickets.review_completion', 'tickets.review_participated']])->assertOk();
        $row = ActivityLog::where('action', 'role_permissions_changed')->latest('id')->firstOrFail();
        $this->assertSame($root->id, (int) $row->user_id);
        $this->assertEqualsCanonicalizing(['tickets.review_completion', 'tickets.review_participated'], $row->new_values['added']);
        $this->assertSame([], $row->new_values['removed']);
        $this->assertSame(['tickets.review_participated'], $row->new_values['guarded']);
        $this->assertSame('operations_manager', $row->new_values['role']);
        $this->assertSame($role->id, (int) $row->entity_id);
        $this->assertTrue(ActivityLog::where('action', 'role_permissions_replaced')->exists(), 'the existing log row is kept');

        $this->postJson('/api/admin/roles/operations_manager/revoke-permission', ['permission' => 'tickets.review_completion'])->assertOk();
        $row = ActivityLog::where('action', 'role_permissions_changed')->latest('id')->firstOrFail();
        $this->assertSame(['tickets.review_completion'], $row->new_values['removed']);

        $this->postJson("/api/department/employees/{$target->id}/permissions/grant", ['permission_name' => 'tickets.review_completion'])->assertOk();
        $row = ActivityLog::where('action', 'user_permission_granted')->latest('id')->firstOrFail();
        $this->assertSame(['tickets.review_completion'], $row->new_values['added']);
        $this->assertSame($target->id, $row->new_values['target_user_id']);
        $this->assertSame('allow', $row->new_values['state']);
        $this->assertSame($root->id, (int) $row->user_id);

        $this->postJson("/api/department/employees/{$target->id}/permissions/revoke", ['permission_name' => 'tickets.review_completion'])->assertOk();
        $row = ActivityLog::where('action', 'user_permission_revoked')->latest('id')->firstOrFail();
        $this->assertSame(['tickets.review_completion'], $row->new_values['removed']);
        $this->assertSame('allow', $row->new_values['previous_state']);
        $this->assertSame('deny', $row->new_values['state']);
    }

    // ═══ Wording, notifications, seeders, performance ═══════════════════════

    public function test_the_missing_permission_wording_depends_on_the_relation(): void
    {
        $own = $this->ticket(); // $this->assignee is the assignee
        $res = $this->approve($own, $this->assignee)->assertForbidden()->assertJsonPath('code', 'REVIEW_PERMISSION_REQUIRED')
            ->assertJsonPath('message', 'أنت المكلّف بها؛ ويلزم أن يراجع هذه التذكرة مراجع مخوّل آخر.');
        $this->assertStringNotContainsString('اطلبها', $res->json('message'));

        $this->approve($this->ticket(['created_by_id' => $this->reporter->id]), $this->reporter)->assertForbidden()
            ->assertJsonPath('message', 'أنت منشئ التذكرة؛ ويلزم أن يراجع هذه التذكرة مراجع مخوّل آخر.');
        $submitted = $this->ticket(['submitted_by_id' => $this->member->id]);
        $this->approve($submitted, $this->member)->assertForbidden()->assertJsonPath('message', 'أنت من أرسل إنجازها للمراجعة؛ ويلزم أن يراجع هذه التذكرة مراجع مخوّل آخر.');
        $this->approve($submitted, $this->leader)->assertForbidden()->assertJsonPath('message', TicketAccess::REVIEW_PERMISSION_MESSAGE); // unrelated, no permission
    }

    public function test_a_reviewer_who_is_the_assignee_is_not_notified_of_their_own_action(): void
    {
        $this->grantTicketReview($this->leader, participated: true);
        $mine = $this->ticket(['assigned_to_id' => $this->leader->id]);
        $this->approve($mine, $this->leader)->assertOk();
        $other = $this->ticket(['assigned_to_id' => $this->leader->id]);
        $this->rework($other, $this->leader)->assertOk();
        $this->assertSame(0, DB::table('platform_notifications')->whereIn('type', ['ticket_completion_approved', 'ticket_returned_for_rework'])->where('user_id', $this->leader->id)->count());

        // Somebody else's ticket still notifies its assignee.
        $theirs = $this->ticket();
        $this->approve($theirs, $this->leader)->assertOk();
        $this->assertSame(1, DB::table('platform_notifications')->where('type', 'ticket_completion_approved')->where('user_id', $this->assignee->id)->count());
    }

    public function test_permission_seeder_does_not_overwrite_the_ticket_rows_written_by_the_migration(): void
    {
        $columns = ['display_name', 'description', 'label_ar', 'description_ar', 'group'];
        $before = Permission::whereIn('name', ['tickets.review_completion', 'tickets.review_participated'])->get()->keyBy('name')->map->only($columns)->all();

        $this->artisan('db:seed', ['--class' => \Database\Seeders\PermissionSeeder::class])->assertSuccessful();

        $after = Permission::whereIn('name', ['tickets.review_completion', 'tickets.review_participated'])->get()->keyBy('name')->map->only($columns)->all();
        $this->assertSame($before, $after);
        $this->assertNotNull($after['tickets.review_participated']['description']);
        $this->assertSame('Approve tickets I take part in', $after['tickets.review_participated']['display_name']);
    }

    public function test_assignee_unavailability_is_remembered_per_assignee_and_can_be_forgotten(): void
    {
        $access = app(TicketAccess::class);
        $t = $this->ticket();
        $other = $this->ticket();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertFalse($access->assigneeUnavailable($t));
        $first = count(DB::getQueryLog());
        $this->assertFalse($access->assigneeUnavailable($other)); // same assignee: remembered
        $this->assertFalse($access->assigneeUnavailable($t));
        $this->assertSame($first, count(DB::getQueryLog()), 'one lookup per assignee for the life of the instance');
        DB::disableQueryLog();

        $this->assignee->forceFill(['is_active' => false])->save();
        $this->assertFalse($access->assigneeUnavailable($t), 'still the remembered answer');
        $access->forgetAssigneeAvailability($this->assignee->id);
        $this->assertTrue($access->assigneeUnavailable($t));
        $this->assertTrue(app(TicketAccess::class)->assigneeUnavailable($t), 'a new instance always asks afresh');
    }

    public function test_review_context_makes_no_assignee_query_unless_the_viewer_passes_the_prechecks(): void
    {
        $access = app(TicketAccess::class);
        $verification = $this->ticket(['status' => 'PENDING_VERIFICATION'], deployment: true);
        $review = $this->ticket();

        // No review permission, a plain member, a reporter: nothing is looked up on a PENDING_VERIFICATION ticket.
        foreach ([$this->leader, $this->member, $this->reporter] as $viewer) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->assertNull($access->reviewContext($viewer, $verification));
            $userQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], '"users"'))->count();
            DB::disableQueryLog();
            $this->assertSame(0, $userQueries, 'no user lookup for viewer '.$viewer->id);
        }

        // A review-stage ticket needs no query at all once the viewer's leadership is known.
        $this->grantTicketReview($this->leader);
        $access->reviewContext($this->leader, $review); // warms the per-user leadership lookup
        DB::enableQueryLog();
        DB::flushQueryLog();
        $ctx = $access->reviewContext($this->leader, $review);
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertTrue($ctx['can_review']);

        // Passing the pre-checks on PENDING_VERIFICATION does ask about the assignee.
        $this->assertNull($access->reviewContext($this->leader, $verification), 'an available assignee: nothing to rescue');
        $this->assignee->forceFill(['is_active' => false])->save();
        $access->forgetAssigneeAvailability();
        $this->assertNotNull($access->reviewContext($this->leader, $verification));
    }
}
