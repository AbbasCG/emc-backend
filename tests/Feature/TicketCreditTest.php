<?php

namespace Tests\Feature;

use App\Exceptions\TicketActionException;
use App\Models\Department;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TicketActivityLog;
use App\Models\TicketCredit;
use App\Models\User;
use App\Models\VolunteerPointAward;
use App\Models\VolunteerProfile;
use App\Services\TaskWorkflowService;
use App\Services\TicketAccess;
use App\Services\TicketCreditService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POINTS AND HOURS ARE CREDITED ONCE, AT THE FINAL APPROVAL OF THE COMPLETION.
 *
 * The assignee's «تم الحل» only SUBMITS the work (IN_PROGRESS -> PENDING_REVIEW). A leader of the department (or a
 * global role) who is NOT the assignee gives the final approval, which is the single place that credits (once,
 * to the assignee); work above «بسيطة» additionally needs an HR approver. Nothing else credits, nothing legacy is
 * ever credited retroactively, and a replay changes nothing.
 */
class TicketCreditTest extends TestCase
{
    use RefreshDatabase;

    private const DUPLICATE = 'تم اعتماد هذه التذكرة وإضافة رصيدها مسبقًا.';

    private Department $dept;

    private User $leader;

    private User $assignee;

    private User $reporter;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->dept, $this->leader] = $this->deptWithLeader('programs_manager');
        $this->assignee = $this->deptMember();
        $this->member = $this->deptMember();
        $this->reporter = $this->user('student');
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function user(string $role = 'student'): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    /** @return array{0: Department, 1: User} */
    private function deptWithLeader(string $leaderRole): array
    {
        $dept = Department::create(['name' => 'D '.Str::random(5), 'name_ar' => 'إدارة', 'slug' => 'd-'.Str::random(8), 'status' => 'active', 'is_active' => true]);
        $leader = $this->user($leaderRole);
        $dept->update(['leader_id' => $leader->id]);

        return [$dept, $leader];
    }

    private function deptMember(?Department $dept = null, string $role = 'student'): User
    {
        $u = $this->user($role);
        ($dept ?? $this->dept)->users()->attach($u->id);

        return $u;
    }

    private function ticket(array $over = []): Ticket
    {
        return Ticket::create(array_merge([
            'ticket_number' => 'EMC-TCK-C-'.Str::random(8),
            'title' => 'تعطل بوابة الدفع',
            'description' => 'وصف',
            'ticket_category' => 'OLD_ISSUE',
            'priority' => 'MEDIUM',
            'department_id' => $this->dept->id,
            'created_by_id' => $this->reporter->id,
            'created_by_name' => 'مُبلِّغ',
            'status' => 'PENDING_APPROVAL',
        ], $over));
    }

    /** A ticket whose assignee has submitted the work (simple, 30 points, 2.5 hours unless overridden). */
    private function submitted(array $over = []): Ticket
    {
        return $this->ticket(array_merge([
            'status' => 'PENDING_REVIEW',
            'assigned_to_id' => $this->assignee->id,
            'approved_by_id' => $this->leader->id,
            'accepted_at' => now()->subHour(),
            'task_scope' => 'simple',
            'task_points' => 30,
            'estimated_hours' => 2.5,
            'resolution_summary' => 'تم إصلاح المشكلة',
        ], $over));
    }

    private function approveCompletion(Ticket $t, ?User $as = null, array $body = [])
    {
        Sanctum::actingAs($as ?? $this->leader);

        return $this->postJson("/api/v1/tickets/{$t->id}/approve-completion", $body);
    }

    private function returnForRework(Ticket $t, ?User $as = null, array $body = ['reason' => 'يلزم استكمال الاختبار'])
    {
        Sanctum::actingAs($as ?? $this->leader);

        return $this->postJson("/api/v1/tickets/{$t->id}/return-for-rework", $body);
    }

    private function awards(?Ticket $t = null)
    {
        return VolunteerPointAward::where('source_type', Ticket::class)->when($t, fn ($q) => $q->where('source_id', $t->id));
    }

    private function hours(User $u): float
    {
        return (float) (VolunteerProfile::where('user_id', $u->id)->value('hours_logged') ?? 0);
    }

    private function ledgerState(): array
    {
        return [VolunteerPointAward::count(), (float) VolunteerProfile::sum('hours_logged'), TicketCredit::count()];
    }

    // ═══ Nothing before the final approval credits ═══════════════════════════

    public function test_assign_accept_submit_and_return_for_rework_never_credit(): void
    {
        $before = $this->ledgerState();
        $t = $this->ticket();

        Sanctum::actingAs($this->leader);
        $this->postJson("/api/v1/tickets/{$t->id}/approve", ['sla_hours' => 24, 'assigned_to_id' => $this->assignee->id, 'task_scope' => 'simple', 'task_points' => 30, 'estimated_hours' => 2.5])->assertOk();
        $this->assertSame($before, $this->ledgerState(), 'assigning credits nothing');

        Sanctum::actingAs($this->assignee);
        $this->postJson("/api/v1/tickets/{$t->id}/accept-task")->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');
        $this->assertSame($before, $this->ledgerState(), 'accepting credits nothing');

        $this->postJson("/api/v1/tickets/{$t->id}/complete-task", ['resolution_type' => 'RESOLVED', 'resolution_summary' => 'تم الحل بالكامل'])
            ->assertOk()->assertJsonPath('data.status', 'PENDING_REVIEW')->assertJsonPath('data.credit.status', 'pending_review');
        $this->assertSame($before, $this->ledgerState(), 'submitting for review credits nothing');
        $fresh = $t->fresh();
        $this->assertNull($fresh->resolved_at, 'not resolved until the final approval');
        $this->assertNull($fresh->credited_at);

        $this->returnForRework($t)->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');
        $this->assertSame($before, $this->ledgerState(), 'returning for rework credits nothing');
        $this->assertNull($t->fresh()->credited_at);
    }

    public function test_an_unresolved_outcome_credits_nothing(): void
    {
        $t = $this->ticket(['status' => 'IN_PROGRESS', 'assigned_to_id' => $this->assignee->id, 'task_scope' => 'simple', 'task_points' => 30]);
        $before = $this->ledgerState();

        Sanctum::actingAs($this->assignee);
        $this->postJson("/api/v1/tickets/{$t->id}/complete-task", ['resolution_type' => 'UNRESOLVED', 'unresolved_reason' => 'تعذر الحل بسبب قيود'])
            ->assertOk()->assertJsonPath('data.status', 'UNRESOLVED');

        $this->assertSame($before, $this->ledgerState());
        $this->approveCompletion($t->fresh())->assertStatus(409);
        $this->assertSame($before, $this->ledgerState());
    }

    public function test_an_in_progress_ticket_with_nobody_assigned_still_closes_directly_and_credits_nothing(): void
    {
        $t = $this->ticket(['status' => 'IN_PROGRESS', 'assigned_to_id' => null]);
        $admin = $this->user('admin');
        $before = $this->ledgerState();

        // A global role may act for the (missing) assignee; with nobody to credit it closes as before.
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/tickets/{$t->id}/complete-task", ['resolution_type' => 'RESOLVED', 'resolution_summary' => 'تم الحل بالكامل'])
            ->assertOk()->assertJsonPath('data.status', 'RESOLVED');

        $this->assertSame($before, $this->ledgerState());
    }

    // ═══ The final approval ═════════════════════════════════════════════════

    public function test_the_final_approval_credits_the_assignee_once_with_snapshot_ledger_hours_and_log(): void
    {
        $t = $this->submitted();

        $res = $this->approveCompletion($t)->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'RESOLVED')
            ->assertJsonPath('data.credit.status', 'credited')
            ->assertJsonPath('data.credit.points', 30)
            ->assertJsonPath('data.credit.hours', 2.5)
            ->assertJsonPath('data.credit.credited_by_name', $this->leader->name);
        $this->assertNotNull($res->json('data.credit.credited_at'));

        $fresh = $t->fresh();
        $this->assertSame('RESOLVED', $fresh->status);
        $this->assertNotNull($fresh->resolved_at);
        $this->assertSame(30, $fresh->credited_points);
        $this->assertEqualsWithDelta(2.5, $fresh->credited_hours, 0.001);
        $this->assertSame($this->leader->id, (int) $fresh->credited_by_id);
        $this->assertNotNull($fresh->credited_at);

        // The ledger: ONE row, to the ASSIGNEE (never the approver), mapped to the ticket.
        $award = $this->awards($t)->sole();
        $this->assertSame($this->assignee->id, (int) $award->user_id);
        $this->assertSame($this->leader->id, (int) $award->awarded_by);
        $this->assertSame(30, $award->points);
        $this->assertSame('ticket', $award->category);
        $this->assertSame(0, VolunteerPointAward::where('user_id', $this->leader->id)->count(), 'the approver gets nothing');
        $this->assertEqualsWithDelta(2.5, $this->hours($this->assignee), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->hours($this->leader), 0.001);

        $credit = TicketCredit::where('ticket_id', $t->id)->sole();
        $this->assertSame($this->assignee->id, (int) $credit->user_id);
        $this->assertSame(30, $credit->points);
        $this->assertEqualsWithDelta(2.5, $credit->hours, 0.001);
        $this->assertSame($this->leader->id, (int) $credit->approved_by_id);

        $actions = TicketActivityLog::where('ticket_id', $t->id)->pluck('action')->all();
        $this->assertContains('APPROVED_COMPLETION', $actions);
        $this->assertContains('CREDIT_ADDED', $actions);
        $this->assertSame(1, array_count_values($actions)['CREDIT_ADDED']);
        $this->assertLessThanOrEqual(50, strlen((string) TicketActivityLog::where('action', 'APPROVED_COMPLETION')->value('new_state')));

        // The assignee is told (in-app); the reporter hears «resolved» only now.
        $this->assertSame(1, DB::table('platform_notifications')->where('user_id', $this->assignee->id)->where('type', 'ticket_completion_approved')->count());
        $this->assertSame(1, DB::table('platform_notifications')->where('user_id', $this->reporter->id)->where('type', 'ticket_resolved')->count());
    }

    public function test_a_replayed_approval_is_a_409_no_op_and_credits_once(): void
    {
        $t = $this->submitted();

        $this->approveCompletion($t)->assertOk();
        $state = $this->ledgerState();

        $this->approveCompletion($t)->assertStatus(409)->assertExactJson(['success' => false, 'message' => self::DUPLICATE, 'code' => 'ALREADY_CREDITED']);
        $this->approveCompletion($t, $this->user('admin'))->assertStatus(409)->assertExactJson(['success' => false, 'message' => self::DUPLICATE, 'code' => 'ALREADY_CREDITED']);

        $this->assertSame($state, $this->ledgerState());
        $this->assertSame(1, $this->awards($t)->count());
        $this->assertEqualsWithDelta(2.5, $this->hours($this->assignee), 0.001);
        $this->assertSame(1, TicketActivityLog::where('ticket_id', $t->id)->where('action', 'CREDIT_ADDED')->count());
    }

    public function test_two_sequential_service_calls_credit_once(): void
    {
        $t = $this->submitted();
        $service = app(TicketCreditService::class);

        $service->approveCompletion($t->id, $this->leader);
        try {
            $service->approveCompletion($t->id, $this->leader);
            $this->fail('the second approval must be refused');
        } catch (TicketActionException $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame(self::DUPLICATE, $e->getMessage());
        }

        $this->assertSame(1, $this->awards($t)->count());
        $this->assertSame(1, TicketCredit::where('ticket_id', $t->id)->count());
        $this->assertEqualsWithDelta(2.5, $this->hours($this->assignee), 0.001);
    }

    public function test_an_existing_credit_row_blocks_the_approval(): void
    {
        $t = $this->submitted();
        TicketCredit::create(['ticket_id' => $t->id, 'user_id' => $this->assignee->id, 'points' => 30, 'hours' => 2.5, 'approved_by_id' => $this->leader->id, 'credited_at' => now()]);
        $state = $this->ledgerState();

        $this->approveCompletion($t)->assertStatus(409)->assertExactJson(['success' => false, 'message' => self::DUPLICATE, 'code' => 'ALREADY_CREDITED']);

        $this->assertSame($state, $this->ledgerState());
        $this->assertSame('PENDING_REVIEW', $t->fresh()->status);
    }

    public function test_the_unique_index_is_the_backstop_when_the_primary_check_is_beaten_by_a_race(): void
    {
        $t = $this->submitted();
        // A concurrent request committed its credit row between our check and our insert.
        TicketCredit::create(['ticket_id' => $t->id, 'user_id' => $this->assignee->id, 'points' => 30, 'hours' => 2.5, 'approved_by_id' => $this->leader->id, 'credited_at' => now()]);
        $state = $this->ledgerState();
        $logs = TicketActivityLog::count();

        // The same service, with only the primary guard "beaten" (it reports not-credited yet).
        $racing = new class(app(TicketAccess::class)) extends TicketCreditService
        {
            protected function alreadyCredited(Ticket $ticket): bool
            {
                return false;
            }
        };

        try {
            $racing->approveCompletion($t->id, $this->leader);
            $this->fail('the unique index must stop the second credit');
        } catch (TicketActionException $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame(self::DUPLICATE, $e->getMessage());
        }

        $this->assertSame($state, $this->ledgerState(), 'no ledger row, no hours, no second credit row');
        $this->assertSame($logs, TicketActivityLog::count(), 'the whole attempt rolled back');
        $fresh = $t->fresh();
        $this->assertSame('PENDING_REVIEW', $fresh->status);
        $this->assertNull($fresh->credited_at);
    }

    public function test_the_database_itself_refuses_a_second_credit_row_for_a_ticket(): void
    {
        $t = $this->submitted();
        $row = ['ticket_id' => $t->id, 'user_id' => $this->assignee->id, 'points' => 30, 'hours' => 2.5, 'credited_at' => now(), 'created_at' => now(), 'updated_at' => now()];
        DB::table('ticket_credits')->insert($row);

        $this->expectException(QueryException::class);
        DB::table('ticket_credits')->insert($row);
    }

    public function test_a_ledger_row_that_already_exists_for_the_ticket_is_never_doubled(): void
    {
        $t = $this->submitted();
        VolunteerPointAward::create(['user_id' => $this->assignee->id, 'category' => 'ticket', 'points' => 30, 'reason' => 'يدوي', 'awarded_by' => $this->leader->id, 'source_type' => Ticket::class, 'source_id' => $t->id, 'awarded_at' => now()]);
        $state = $this->ledgerState();

        $this->approveCompletion($t)->assertStatus(409)->assertJsonPath('message', self::DUPLICATE);

        $this->assertSame($state, $this->ledgerState());
        $this->assertSame('PENDING_REVIEW', $t->fresh()->status, 'refused as a whole');
    }

    public function test_hours_are_optional_and_null_hours_credit_zero(): void
    {
        $t = $this->submitted(['estimated_hours' => null]);

        $this->approveCompletion($t)->assertOk()->assertJsonPath('data.credit.points', 30)->assertJsonPath('data.credit.hours', 0);

        $this->assertSame(1, $this->awards($t)->count());
        $this->assertEqualsWithDelta(0.0, $this->hours($this->assignee), 0.001);
        $this->assertNull(VolunteerProfile::where('user_id', $this->assignee->id)->first(), 'no profile is created for zero hours');
    }

    public function test_hours_accumulate_on_the_assignees_existing_profile(): void
    {
        VolunteerProfile::create(['user_id' => $this->assignee->id, 'hours_logged' => 10]);
        $t = $this->submitted();

        $this->approveCompletion($t)->assertOk();

        $this->assertEqualsWithDelta(12.5, $this->hours($this->assignee), 0.001);
    }

    // ═══ Who may approve ════════════════════════════════════════════════════

    public function test_nobody_approves_their_own_work_a_leader_assigned_to_themselves(): void
    {
        $t = $this->submitted(['assigned_to_id' => $this->leader->id]);
        $state = $this->ledgerState();

        $this->approveCompletion($t, $this->leader)->assertForbidden();
        $this->returnForRework($t, $this->leader)->assertForbidden();

        $this->assertSame($state, $this->ledgerState());
        $this->assertSame('PENDING_REVIEW', $t->fresh()->status);

        $caps = $this->getJson("/api/v1/tickets/{$t->id}")->assertOk()->json('data.capabilities');
        $this->assertFalse($caps['approve_completion']);
        $this->assertFalse($caps['return_for_rework']);
    }

    public function test_nobody_approves_their_own_work_a_global_role_assigned_to_themselves(): void
    {
        $admin = $this->deptMember(null, 'admin');
        $t = $this->submitted(['assigned_to_id' => $admin->id]);
        $state = $this->ledgerState();

        $this->approveCompletion($t, $admin)->assertForbidden();
        $this->returnForRework($t, $admin)->assertForbidden();
        $this->assertSame($state, $this->ledgerState());

        // ...but a different global role may.
        $this->approveCompletion($t, $this->user('super_admin'))->assertOk();
        $this->assertSame($admin->id, (int) $this->awards($t)->sole()->user_id, 'the credit goes to the assignee');
    }

    public function test_the_self_assigned_leader_journey_end_to_end(): void
    {
        $t = $this->ticket();

        // The leader (an eligible department person) assigns the ticket to themselves and does the work.
        Sanctum::actingAs($this->leader);
        $this->postJson("/api/v1/tickets/{$t->id}/approve", ['sla_hours' => 24, 'assigned_to_id' => $this->leader->id, 'task_scope' => 'simple', 'task_points' => 40, 'estimated_hours' => 1])->assertOk();
        $this->postJson("/api/v1/tickets/{$t->id}/accept-task")->assertOk();
        $this->postJson("/api/v1/tickets/{$t->id}/complete-task", ['resolution_type' => 'RESOLVED', 'resolution_summary' => 'أنجزتها بنفسي'])->assertOk()->assertJsonPath('data.status', 'PENDING_REVIEW');

        // They cannot approve or return it.
        $this->approveCompletion($t)->assertForbidden();
        $this->returnForRework($t)->assertForbidden();
        $this->assertSame(0, $this->awards()->count());

        // A DIFFERENT approver closes it; the credit lands on the leader as assignee.
        $this->approveCompletion($t, $this->user('executive_admin'))->assertOk();
        $award = $this->awards($t)->sole();
        $this->assertSame($this->leader->id, (int) $award->user_id);
        $this->assertSame(40, $award->points);
        $this->assertEqualsWithDelta(1.0, $this->hours($this->leader), 0.001);
    }

    public function test_a_department_leader_can_approve_a_simple_ticket(): void
    {
        $this->approveCompletion($this->submitted())->assertOk();
    }

    public function test_work_above_simple_needs_an_hr_approver(): void
    {
        $t = $this->submitted(['task_scope' => 'medium', 'task_points' => 100, 'estimated_hours' => 4]);
        $state = $this->ledgerState();

        // The department leader alone is not enough.
        $res = $this->approveCompletion($t)->assertForbidden();
        $this->assertStringContainsString('الموارد البشرية', $res->json('message'));
        $this->assertSame($state, $this->ledgerState());

        $caps = $this->getJson("/api/v1/tickets/{$t->id}")->assertOk()->json('data.capabilities');
        $this->assertFalse($caps['approve_completion'], 'the capability mirrors the HR gate');
        $this->assertTrue($caps['return_for_rework'], 'returning for rework needs no HR role');

        // A global HR approver can.
        Sanctum::actingAs($this->user('admin'));
        $this->assertTrue($this->getJson("/api/v1/tickets/{$t->id}")->json('data.capabilities.approve_completion'));
        $this->approveCompletion($t, $this->user('admin'))->assertOk();
        $this->assertSame(100, $this->awards($t)->sole()->points);
    }

    public function test_a_leader_who_holds_an_hr_role_can_approve_work_above_simple(): void
    {
        [$dept, $hrLeader] = $this->deptWithLeader('hr_manager');
        $assignee = $this->deptMember($dept);
        $t = $this->submitted(['department_id' => $dept->id, 'assigned_to_id' => $assignee->id, 'task_scope' => 'large', 'task_points' => 250, 'estimated_hours' => 6]);

        $this->approveCompletion($t, $hrLeader)->assertOk();

        $this->assertSame(250, $this->awards($t)->sole()->points);
    }

    public function test_a_leader_cannot_shrink_the_size_in_the_request_to_skip_hr(): void
    {
        $t = $this->submitted(['task_scope' => 'medium', 'task_points' => 100]);
        $state = $this->ledgerState();

        $this->approveCompletion($t, null, ['task_scope' => 'simple', 'task_points' => 30])->assertForbidden();

        $this->assertSame($state, $this->ledgerState());
        $this->assertSame('medium', $t->fresh()->task_scope, 'the refused attempt changed nothing');
    }

    public function test_a_leader_cannot_upgrade_to_a_larger_size_without_hr_either(): void
    {
        $t = $this->submitted();

        $this->approveCompletion($t, null, ['task_scope' => 'large', 'task_points' => 250])->assertForbidden();

        $this->assertSame(0, $this->awards()->count());
    }

    public function test_outsiders_other_leaders_members_and_reporters_are_refused_without_leaking(): void
    {
        $t = $this->submitted();
        [$otherDept, $otherLeader] = $this->deptWithLeader('programs_manager');
        $state = $this->ledgerState();

        // Not able to see the ticket at all: indistinguishable from a missing one.
        $this->approveCompletion($t, $otherLeader)->assertNotFound();
        $this->approveCompletion($t, $this->user('student'))->assertNotFound();
        $this->returnForRework($t, $otherLeader)->assertNotFound();

        // Able to see it, not to review it.
        $this->approveCompletion($t, $this->member)->assertForbidden();
        $this->approveCompletion($t, $this->reporter)->assertForbidden();
        $this->approveCompletion($t, $this->assignee)->assertForbidden();
        $this->returnForRework($t, $this->member)->assertForbidden();
        $this->returnForRework($t, $this->reporter)->assertForbidden();
        $this->returnForRework($t, $this->assignee)->assertForbidden();

        $this->assertSame($state, $this->ledgerState());
        $this->assertSame('PENDING_REVIEW', $t->fresh()->status);

        Sanctum::actingAs($this->leader);
        $this->postJson('/api/v1/tickets/999999/approve-completion')->assertNotFound();
    }

    public function test_unauthenticated_is_refused(): void
    {
        $t = $this->submitted();

        $this->postJson("/api/v1/tickets/{$t->id}/approve-completion")->assertUnauthorized();
        $this->postJson("/api/v1/tickets/{$t->id}/return-for-rework", ['reason' => 'xxxxxxx'])->assertUnauthorized();
    }

    public function test_only_a_pending_review_ticket_can_be_approved(): void
    {
        foreach (['PENDING_APPROVAL', 'ASSIGNED', 'IN_PROGRESS', 'UNRESOLVED', 'REJECTED_BY_ADMIN', 'REJECTED_BY_ASSIGNEE'] as $status) {
            $t = $this->submitted(['status' => $status]);
            $this->approveCompletion($t)->assertStatus(409);
        }
        $this->assertSame(0, $this->awards()->count());
    }

    // ═══ Legacy and incomplete tickets ═══════════════════════════════════════

    public function test_a_legacy_ticket_without_size_and_points_needs_them_in_the_request(): void
    {
        $t = $this->submitted(['task_scope' => null, 'task_points' => null, 'estimated_hours' => null]);
        $state = $this->ledgerState();

        $this->approveCompletion($t)->assertStatus(422)
            ->assertJsonPath('message', 'حدّد حجم المهمة ونقاطها قبل اعتماد الإنجاز')
            ->assertJsonPath('errors.task_scope.0', 'حدّد حجم المهمة ونقاطها قبل اعتماد الإنجاز')
            ->assertJsonPath('errors.task_points.0', 'حدّد حجم المهمة ونقاطها قبل اعتماد الإنجاز');

        // The assignment path keeps its own wording.
        Sanctum::actingAs($this->leader);
        $this->postJson("/api/v1/tickets/{$this->ticket()->id}/approve", ['sla_hours' => 24, 'assigned_to_id' => $this->assignee->id])
            ->assertStatus(422)->assertJsonPath('message', 'حدّد نقاط المهمة وحجمها قبل الإسناد');
        $this->approveCompletion($t, null, ['task_scope' => 'simple'])->assertStatus(422)->assertJsonStructure(['errors' => ['task_points']]);
        $this->approveCompletion($t, null, ['task_scope' => 'simple', 'task_points' => 500])->assertStatus(422)->assertJsonStructure(['errors' => ['task_points']]);
        $this->assertSame($state, $this->ledgerState());
        $this->assertSame('PENDING_REVIEW', $t->fresh()->status);

        $this->approveCompletion($t, null, ['task_scope' => 'simple', 'task_points' => 35, 'estimated_hours' => 1.5])->assertOk();

        $this->assertSame(35, $this->awards($t)->sole()->points);
        $this->assertEqualsWithDelta(1.5, $this->hours($this->assignee), 0.001);
        $this->assertSame(1, TicketActivityLog::where('ticket_id', $t->id)->where('action', 'ESTIMATE_UPDATED')->count());
        $this->approveCompletion($t, null, ['task_scope' => 'simple', 'task_points' => 35])->assertStatus(409);
        $this->assertSame(1, $this->awards($t)->count());
    }

    public function test_a_corrected_estimate_in_the_request_is_validated_logged_and_is_what_gets_credited(): void
    {
        $t = $this->submitted();

        $this->approveCompletion($t, null, ['task_points' => 45, 'estimated_hours' => 3, 'note' => 'استغرقت أطول'])->assertOk();

        $fresh = $t->fresh();
        $this->assertSame(45, $fresh->task_points);
        $this->assertSame(45, $fresh->credited_points);
        $this->assertSame(45, $this->awards($t)->sole()->points);
        $this->assertEqualsWithDelta(3.0, $this->hours($this->assignee), 0.001);
        $this->assertSame(1, TicketActivityLog::where('ticket_id', $t->id)->where('action', 'ESTIMATE_UPDATED')->count());
        $this->assertStringContainsString('استغرقت أطول', (string) TicketActivityLog::where('action', 'CREDIT_ADDED')->value('details'));
    }

    public function test_an_invalid_estimate_in_the_request_changes_nothing(): void
    {
        $t = $this->submitted();
        $state = $this->ledgerState();

        $this->approveCompletion($t, null, ['task_points' => 9999])->assertStatus(422);
        $this->approveCompletion($t, null, ['estimated_hours' => -1])->assertStatus(422);
        $this->approveCompletion($t, null, ['task_scope' => 'giant'])->assertStatus(422);
        $this->approveCompletion($t, null, ['note' => str_repeat('x', 1001)])->assertStatus(422);

        $this->assertSame($state, $this->ledgerState());
        $this->assertSame(30, $t->fresh()->task_points);
    }

    public function test_tickets_already_closed_before_this_feature_are_never_credited_by_any_path(): void
    {
        $resolved = $this->ticket(['status' => 'RESOLVED', 'resolved_at' => now()->subMonth(), 'assigned_to_id' => $this->assignee->id, 'task_scope' => 'simple', 'task_points' => 30, 'estimated_hours' => 2]);
        $unresolved = $this->ticket(['status' => 'UNRESOLVED', 'assigned_to_id' => $this->assignee->id, 'task_scope' => 'simple', 'task_points' => 30]);
        $state = $this->ledgerState();

        // The approval endpoint refuses (it is not PENDING_REVIEW), the audit is read-only, and there is nothing else.
        $this->approveCompletion($resolved)->assertStatus(409);
        $this->approveCompletion($unresolved)->assertStatus(409);
        $this->artisan('tickets:audit-work-links')->assertSuccessful();
        $this->returnForRework($resolved)->assertStatus(409);

        $this->assertSame($state, $this->ledgerState());
        $this->assertNull($resolved->fresh()->credited_at);
        $this->assertSame(0, $this->awards()->count());
        $this->assertSame([], array_values(array_filter(array_keys(app('router')->getRoutes()->getRoutesByName()), fn ($n) => str_contains((string) $n, 'backfill'))), 'there is no backfill route');
        $this->assertArrayNotHasKey('tickets:credit-legacy', \Illuminate\Support\Facades\Artisan::all(), 'there is no command that credits legacy tickets');
    }

    // ═══ Return for rework ═══════════════════════════════════════════════════

    public function test_return_for_rework_needs_a_reason_and_moves_back_to_in_progress(): void
    {
        $t = $this->submitted();

        $this->returnForRework($t, null, [])->assertStatus(422)->assertJsonStructure(['errors' => ['reason']]);
        $this->returnForRework($t, null, ['reason' => 'قصير'])->assertStatus(422);
        $this->returnForRework($t, null, ['reason' => str_repeat('س', 1001)])->assertStatus(422);
        $this->assertSame('PENDING_REVIEW', $t->fresh()->status);

        $this->returnForRework($t, null, ['reason' => 'يلزم استكمال الاختبار'])->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');

        $log = TicketActivityLog::where('ticket_id', $t->id)->where('action', 'RETURNED_FOR_REWORK')->sole();
        $this->assertSame('PENDING_REVIEW', $log->previous_state);
        $this->assertSame('IN_PROGRESS', $log->new_state);
        $this->assertStringContainsString('يلزم استكمال الاختبار', $log->details);
        $this->assertSame(1, DB::table('platform_notifications')->where('user_id', $this->assignee->id)->where('type', 'ticket_returned_for_rework')->count());
        $this->assertSame(0, $this->ledgerState()[0]);

        // Resubmit and approve: credited exactly once, after the round trip.
        Sanctum::actingAs($this->assignee);
        $this->postJson("/api/v1/tickets/{$t->id}/complete-task", ['resolution_type' => 'RESOLVED', 'resolution_summary' => 'استكملت الاختبار'])->assertOk();
        $this->approveCompletion($t)->assertOk();
        $this->assertSame(1, $this->awards($t)->count());
    }

    public function test_a_credited_ticket_cannot_be_returned_and_has_no_reopen_path(): void
    {
        $t = $this->submitted();
        $this->approveCompletion($t)->assertOk();
        $state = $this->ledgerState();

        $this->returnForRework($t)->assertStatus(409);
        // A credited (RESOLVED) ticket is closed: it cannot be reassigned, re-estimated or approved again.
        Sanctum::actingAs($this->leader);
        $this->postJson("/api/v1/tickets/{$t->id}/reassign", ['assigned_to_id' => $this->member->id, 'task_scope' => 'simple', 'task_points' => 30])->assertStatus(409);
        $this->patchJson("/api/v1/tickets/{$t->id}/work-estimate", ['task_scope' => 'simple', 'task_points' => 40])->assertStatus(409);
        $this->approveCompletion($t)->assertStatus(409);

        $this->assertSame($state, $this->ledgerState());
        $this->assertSame('RESOLVED', $t->fresh()->status);
        $this->assertSame(30, $t->fresh()->credited_points);
    }

    public function test_a_pending_review_ticket_cannot_be_reassigned(): void
    {
        $t = $this->submitted();

        Sanctum::actingAs($this->leader);
        $this->postJson("/api/v1/tickets/{$t->id}/reassign", ['assigned_to_id' => $this->member->id, 'task_scope' => 'simple', 'task_points' => 30])->assertStatus(409);
    }

    // ═══ Visibility ═════════════════════════════════════════════════════════

    public function test_the_reporter_sees_neither_estimate_nor_credit_and_sees_review_as_in_progress(): void
    {
        $t = $this->submitted();

        Sanctum::actingAs($this->reporter);
        $show = $this->getJson("/api/v1/tickets/{$t->id}")->assertOk()->assertJsonPath('data.viewer_role', 'reporter')->assertJsonPath('data.status', 'IN_PROGRESS');
        $row = collect($this->getJson('/api/v1/tickets')->assertOk()->json('data.data'))->firstWhere('id', $t->id);
        $this->assertSame('IN_PROGRESS', $row['status']);
        $this->assertNull($show->json('data.resolution_note'), 'the assignee answer is not shown before the final approval');
        $this->assertNull($show->json('data.resolved_at'));
        $this->assertNotContains('resolved', array_column($show->json('data.timeline'), 'key'));
        $this->assertTrue($show->json('data.capabilities.comment'));
        $this->assertFalse($show->json('data.capabilities.approve_completion'));

        $body = json_encode($show->json(), JSON_UNESCAPED_UNICODE).json_encode($row, JSON_UNESCAPED_UNICODE);
        foreach (['task_scope', 'task_points', 'estimated_hours', 'credit', 'PENDING_REVIEW'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, $leak);
        }

        // After the final approval the reporter's timeline ends in «resolved».
        $this->approveCompletion($t)->assertOk();
        Sanctum::actingAs($this->reporter);
        $after = $this->getJson("/api/v1/tickets/{$t->id}")->assertOk();
        $this->assertSame('RESOLVED', $after->json('data.status'));
        $this->assertContains('resolved', array_column($after->json('data.timeline'), 'key'));
        $this->assertStringNotContainsString('credit', json_encode($after->json(), JSON_UNESCAPED_UNICODE));
    }

    public function test_a_plain_member_never_sees_the_credit_but_managers_and_the_assignee_do(): void
    {
        $t = $this->submitted();
        $this->approveCompletion($t)->assertOk();

        Sanctum::actingAs($this->member);
        $plain = $this->getJson("/api/v1/tickets/{$t->id}")->assertOk()->json('data');
        foreach (['credit', 'credited_points', 'credited_hours', 'credited_at', 'credited_by_id', 'task_scope', 'task_points', 'estimated_hours', 'creditor'] as $key) {
            $this->assertArrayNotHasKey($key, $plain, $key);
        }
        $this->assertNotContains('CREDIT_ADDED', array_column($plain['activity_logs'], 'action'));
        $this->assertNotContains('ESTIMATE_UPDATED', array_column($plain['activity_logs'], 'action'));
        $this->assertContains('APPROVED_COMPLETION', array_column($plain['activity_logs'], 'action'), 'the public row stays');
        $this->assertStringNotContainsString('نقطة', collect($plain['activity_logs'])->firstWhere('action', 'APPROVED_COMPLETION')['details']);

        $list = collect($this->getJson('/api/v1/tickets')->json('data.data'))->firstWhere('id', $t->id);
        $this->assertArrayNotHasKey('credit', $list);
        $this->assertArrayNotHasKey('credited_points', $list);

        foreach ([$this->leader, $this->assignee, $this->user('admin')] as $viewer) {
            Sanctum::actingAs($viewer);
            $staff = $this->getJson("/api/v1/tickets/{$t->id}")->assertOk();
            $staff->assertJsonPath('data.credit.status', 'credited')->assertJsonPath('data.credit.points', 30);
            $this->assertArrayNotHasKey('credited_points', $staff->json('data'));
            $this->assertContains('CREDIT_ADDED', array_column($staff->json('data.activity_logs'), 'action'));
        }
    }

    public function test_capabilities_report_what_the_endpoints_decide(): void
    {
        $submitted = $this->submitted();
        $inProgress = $this->submitted(['status' => 'IN_PROGRESS']);
        $viewers = ['leader' => $this->leader, 'assignee' => $this->assignee, 'member' => $this->member, 'reporter' => $this->reporter, 'admin' => $this->user('admin')];

        foreach ($viewers as $name => $viewer) {
            Sanctum::actingAs($viewer);
            $caps = $this->getJson("/api/v1/tickets/{$submitted->id}")->assertOk()->json('data.capabilities');
            $expect = in_array($name, ['leader', 'admin'], true);
            $this->assertSame($expect, $caps['approve_completion'], "approve_completion for {$name}");
            $this->assertSame($expect, $caps['return_for_rework'], "return_for_rework for {$name}");
            $this->assertFalse($caps['complete_task'], 'complete_task is only for IN_PROGRESS');

            $other = $this->getJson("/api/v1/tickets/{$inProgress->id}")->assertOk()->json('data.capabilities');
            $this->assertFalse($other['approve_completion']);
            $this->assertFalse($other['return_for_rework']);
            $this->assertSame($name === 'assignee' || $name === 'admin', $other['complete_task'], "complete_task for {$name}");
        }
    }

    // ═══ «مهامي» ═════════════════════════════════════════════════════════════

    public function test_my_work_returns_the_submitted_ticket_for_the_assignee_with_its_credit_status(): void
    {
        $t = $this->submitted();

        Sanctum::actingAs($this->assignee);
        $row = collect($this->getJson('/api/v1/tickets/my-work')->assertOk()->json('data'))->where('id', $t->id)->sole();
        $this->assertSame('PENDING_REVIEW', $row['status']);
        $this->assertSame('pending_review', $row['credit']['status']);
        $this->assertFalse($row['capabilities']['can_complete']);
        $this->assertSame(1, $this->getJson('/api/v1/tickets/my-work?status=PENDING_REVIEW')->assertOk()->json('meta.open_total'));

        $this->approveCompletion($t)->assertOk();
        Sanctum::actingAs($this->assignee);
        $done = collect($this->getJson('/api/v1/tickets/my-work')->json('data'))->where('id', $t->id)->sole();
        $this->assertSame('RESOLVED', $done['status']);
        $this->assertSame('credited', $done['credit']['status']);
        $this->assertSame(30, $done['credit']['points']);
    }

    public function test_the_new_status_is_filterable_and_counted_as_open(): void
    {
        $t = $this->submitted();

        Sanctum::actingAs($this->leader);
        $ids = collect($this->getJson('/api/v1/tickets?status=PENDING_REVIEW')->assertOk()->json('data.data'))->pluck('id')->all();
        $this->assertSame([$t->id], $ids);
        $stats = $this->getJson('/api/v1/tickets/stats')->assertOk()->json('data.counts');
        $this->assertSame(1, $stats['pending_review']);
        $this->assertSame(1, $stats['open']);
        $this->assertSame(0, $stats['closed']);
        $this->assertSame(1, collect($this->getJson('/api/v1/tickets?attention=1')->json('data.data'))->where('id', $t->id)->count(), 'waiting for the leader to review');
    }

    public function test_a_ticket_waiting_for_review_is_not_needing_attention_of_its_own_assignee_leader(): void
    {
        $t = $this->submitted(['assigned_to_id' => $this->leader->id]);

        Sanctum::actingAs($this->leader);
        $this->assertSame(0, collect($this->getJson('/api/v1/tickets?attention=1')->json('data.data'))->where('id', $t->id)->count());
    }

    // ═══ Regular tasks keep crediting exactly as before ══════════════════════

    public function test_regular_tasks_still_credit_points_once_and_hours_through_the_shared_service(): void
    {
        $volunteer = $this->user('student');
        $approver = $this->user('admin');
        $task = Task::create([
            'title' => 'مهمة عادية', 'description' => 'x', 'task_scope' => Task::SCOPE_SIMPLE, 'points' => 30, 'estimated_hours' => 2,
            'assigned_to' => $volunteer->id, 'created_by' => $approver->id, 'status' => 'completed', 'priority' => 'medium',
        ]);

        TaskWorkflowService::creditVolunteerHoursAndPoints($task, $approver);
        $this->assertEqualsWithDelta(2.0, $this->hours($volunteer), 0.001);
        TaskWorkflowService::creditVolunteerHoursAndPoints($task, $approver);

        $this->assertSame(1, VolunteerPointAward::where('source_type', Task::class)->where('source_id', $task->id)->count(), 'points are not doubled (unchanged behaviour)');
        $award = VolunteerPointAward::where('source_type', Task::class)->sole();
        $this->assertSame('task', $award->category);
        $this->assertSame('إنجاز مهمة: مهمة عادية', $award->reason);
        $this->assertSame('اعتماد نهائي للمهمة #'.$task->id.' (حجم: simple)', $award->note);
    }

    // ═══ Audit ═══════════════════════════════════════════════════════════════

    public function test_the_audit_reports_review_and_credit_integrity_problems_read_only(): void
    {
        $orphanReview = $this->submitted(['assigned_to_id' => null]);
        $noRow = $this->ticket(['status' => 'RESOLVED', 'resolved_at' => now()]);
        $noRow->forceFill(['credited_at' => now(), 'credited_points' => 30, 'credited_hours' => 1])->save(); // not mass assignable on purpose
        $noLedger = $this->submitted();
        TicketCredit::create(['ticket_id' => $noLedger->id, 'user_id' => $this->assignee->id, 'points' => 30, 'hours' => 1, 'credited_at' => now()]);
        $dup = $this->submitted();
        foreach ([1, 2] as $i) {
            VolunteerPointAward::create(['user_id' => $this->assignee->id, 'category' => 'ticket', 'points' => 30, 'reason' => 'x', 'source_type' => Ticket::class, 'source_id' => $dup->id, 'awarded_at' => now()]);
        }
        $before = [DB::table('tickets')->count(), DB::table('ticket_credits')->count(), VolunteerPointAward::count(), DB::table('ticket_activity_logs')->count()];

        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('tickets:audit-work-links'));
        $report = [];
        foreach (explode("\n", \Illuminate\Support\Facades\Artisan::output()) as $line) {
            if (preg_match('/^\|\s*([a-z_]+)\s*\|.*\|\s*(\d+)\s*\|\s*(.*?)\s*\|$/u', rtrim($line), $m)) {
                $report[$m[1]] = ['count' => (int) $m[2], 'ids' => $m[3]];
            }
        }

        $this->assertSame(['count' => 1, 'ids' => (string) $orphanReview->id], $report['pending_review_without_assignee']);
        $this->assertSame(['count' => 1, 'ids' => (string) $noRow->id], $report['credited_without_credit_row']);
        $this->assertSame(['count' => 1, 'ids' => (string) $noLedger->id], $report['credit_row_without_ledger_award']);
        $this->assertSame(['count' => 1, 'ids' => (string) $noLedger->id], $report['credit_row_without_snapshot']);
        $this->assertSame(['count' => 1, 'ids' => (string) $dup->id], $report['duplicate_awards_per_ticket']);
        $this->assertSame($before, [DB::table('tickets')->count(), DB::table('ticket_credits')->count(), VolunteerPointAward::count(), DB::table('ticket_activity_logs')->count()], 'read-only');
        $this->assertNotNull($noRow->fresh());
    }

    // ═══ Round 2: field limits, estimate freeze, separation of duties, reporter folding, codes ═══

    public function test_a_title_of_255_characters_never_breaks_the_ledger_reason(): void
    {
        $title = str_repeat('ع', 255);
        $t = $this->submitted(['title' => $title]);

        $this->approveCompletion($t)->assertOk();

        $award = $this->awards($t)->sole();
        // SQLite would store any length; MariaDB strict mode refuses more than the VARCHAR(255) column holds.
        $this->assertLessThanOrEqual(255, mb_strlen($award->reason));
        $this->assertStringStartsWith('إنجاز تذكرة: ', $award->reason);
        $this->assertStringEndsWith('…', $award->reason);
        foreach (TicketActivityLog::where('ticket_id', $t->id)->get() as $log) {
            $this->assertLessThanOrEqual(50, mb_strlen((string) $log->previous_state));
            $this->assertLessThanOrEqual(50, mb_strlen((string) $log->new_state));
            $this->assertLessThanOrEqual(150, mb_strlen((string) $log->performed_by_name));
        }

        // A short title is stored whole.
        $short = $this->submitted(['title' => 'عنوان قصير']);
        $this->approveCompletion($short)->assertOk();
        $this->assertSame('إنجاز تذكرة: عنوان قصير', $this->awards($short)->sole()->reason);
    }

    public function test_the_estimate_cannot_be_edited_while_the_work_is_in_review_and_the_hr_gate_cannot_be_dodged(): void
    {
        $t = $this->submitted(['task_scope' => 'medium', 'task_points' => 100]);

        Sanctum::actingAs($this->leader);
        $this->patchJson("/api/v1/tickets/{$t->id}/work-estimate", ['task_scope' => 'simple', 'task_points' => 30])
            ->assertStatus(409)->assertJsonPath('code', 'ESTIMATE_FROZEN')->assertJsonPath('success', false);
        $this->assertSame('medium', $t->fresh()->task_scope);
        $this->assertFalse($this->getJson("/api/v1/tickets/{$t->id}")->json('data.capabilities.can_edit_estimate'));

        // The only place to correct it is the approval request, which still applies the HR gate.
        $this->approveCompletion($t)->assertForbidden()->assertJsonPath('code', 'HR_APPROVAL_REQUIRED');
        $this->assertSame(0, $this->awards()->count());

        // Before the work is submitted the leader may still edit it.
        $open = $this->submitted(['status' => 'IN_PROGRESS']);
        Sanctum::actingAs($this->leader);
        $this->assertTrue($this->getJson("/api/v1/tickets/{$open->id}")->json('data.capabilities.can_edit_estimate'));
        $this->patchJson("/api/v1/tickets/{$open->id}/work-estimate", ['task_scope' => 'simple', 'task_points' => 40])->assertOk();
    }

    public function test_the_assignee_cannot_resize_their_own_work_in_any_status(): void
    {
        $adminAssignee = $this->deptMember(null, 'admin');
        foreach ([$this->leader, $adminAssignee] as $who) {
            foreach (['ASSIGNED', 'IN_PROGRESS'] as $status) {
                $t = $this->submitted(['status' => $status, 'assigned_to_id' => $who->id]);

                Sanctum::actingAs($who);
                $this->patchJson("/api/v1/tickets/{$t->id}/work-estimate", ['task_scope' => 'simple', 'task_points' => 49])
                    ->assertForbidden()->assertJsonPath('code', 'OWN_WORK_ESTIMATE');
                $this->assertSame(30, $t->fresh()->task_points);
                $this->assertFalse($this->getJson("/api/v1/tickets/{$t->id}")->json('data.capabilities.can_edit_estimate'));
            }
        }

        // Editing someone else's ticket keeps working.
        $other = $this->submitted(['status' => 'IN_PROGRESS']);
        Sanctum::actingAs($this->leader);
        $this->patchJson("/api/v1/tickets/{$other->id}/work-estimate", ['task_scope' => 'simple', 'task_points' => 45])->assertOk();
    }

    public function test_the_person_who_submitted_the_work_cannot_review_it(): void
    {
        $x = $this->user('admin');
        $y = $this->user('super_admin');
        $t = $this->ticket(['status' => 'IN_PROGRESS', 'assigned_to_id' => $this->assignee->id, 'task_scope' => 'simple', 'task_points' => 30]);

        // Global X completes it on the assignee's behalf.
        Sanctum::actingAs($x);
        $this->postJson("/api/v1/tickets/{$t->id}/complete-task", ['resolution_type' => 'RESOLVED', 'resolution_summary' => 'أنجزتها نيابة عنه'])
            ->assertOk()->assertJsonPath('data.status', 'PENDING_REVIEW');
        $this->assertSame($x->id, (int) $t->fresh()->submitted_by_id);

        $caps = $this->getJson("/api/v1/tickets/{$t->id}")->json('data.capabilities');
        $this->assertFalse($caps['approve_completion']);
        $this->assertFalse($caps['return_for_rework']);
        $state = $this->ledgerState();
        $this->approveCompletion($t, $x)->assertForbidden()->assertJsonPath('code', 'SELF_REVIEW');
        $this->returnForRework($t, $x)->assertForbidden()->assertJsonPath('code', 'SELF_REVIEW');
        $this->assertSame($state, $this->ledgerState());

        // Another reviewer can; the capability says so.
        Sanctum::actingAs($y);
        $this->assertTrue($this->getJson("/api/v1/tickets/{$t->id}")->json('data.capabilities.approve_completion'));
        Sanctum::actingAs($this->leader);
        $this->assertTrue($this->getJson("/api/v1/tickets/{$t->id}")->json('data.capabilities.return_for_rework'));

        // A returned ticket resubmitted by the assignee is a NEW round: X may now review it.
        $this->returnForRework($t, $y)->assertOk();
        Sanctum::actingAs($this->assignee);
        $this->postJson("/api/v1/tickets/{$t->id}/complete-task", ['resolution_type' => 'RESOLVED', 'resolution_summary' => 'استكملتها بنفسي'])->assertOk();
        $this->assertSame($this->assignee->id, (int) $t->fresh()->submitted_by_id);
        $this->approveCompletion($t, $x)->assertOk();
        $this->assertSame($this->assignee->id, (int) $this->awards($t)->sole()->user_id);
    }

    public function test_the_submitter_is_not_exposed_in_any_payload(): void
    {
        $t = $this->submitted(['status' => 'IN_PROGRESS']);
        Sanctum::actingAs($this->assignee);
        $this->postJson("/api/v1/tickets/{$t->id}/complete-task", ['resolution_type' => 'RESOLVED', 'resolution_summary' => 'تم الحل بالكامل'])->assertOk();

        foreach ([$this->leader, $this->assignee, $this->member, $this->reporter] as $viewer) {
            Sanctum::actingAs($viewer);
            $body = json_encode($this->getJson("/api/v1/tickets/{$t->id}")->json(), JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('submitted_by_id', $body);
            $this->assertStringNotContainsString('submitted_at', $body);
        }
    }

    public function test_a_reporter_only_viewer_sees_review_folded_into_in_progress_everywhere(): void
    {
        $review = $this->submitted();
        $working = $this->submitted(['status' => 'IN_PROGRESS']);

        Sanctum::actingAs($this->reporter);
        $counts = $this->getJson('/api/v1/tickets/stats')->assertOk()->json('data.counts');
        $this->assertSame(2, $counts['in_progress']);
        $this->assertSame(0, $counts['pending_review']);

        $board = collect($this->getJson('/api/v1/tickets/board')->assertOk()->json('data.columns'))->keyBy('status');
        $this->assertFalse($board->has('PENDING_REVIEW'), 'no review column for a reporter');
        $this->assertSame(2, $board['IN_PROGRESS']['count']);
        $this->assertEqualsCanonicalizing([$review->id, $working->id], collect($board['IN_PROGRESS']['tickets'])->pluck('id')->all());
        foreach ($board['IN_PROGRESS']['tickets'] as $card) {
            $this->assertSame('IN_PROGRESS', $card['status'], 'a card agrees with its column');
        }

        $inProgress = collect($this->getJson('/api/v1/tickets?status=IN_PROGRESS')->assertOk()->json('data.data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$review->id, $working->id], $inProgress);
        $this->assertSame([], $this->getJson('/api/v1/tickets?status=PENDING_REVIEW')->assertOk()->json('data.data'), 'filtering the internal step reveals nothing');

        $byStatus = collect($this->getJson('/api/v1/tickets/analytics?days=30')->assertOk()->json('data.by_status'))->keyBy('key');
        $this->assertSame(0, $byStatus['PENDING_REVIEW']['count']);
        $this->assertSame(2, $byStatus['IN_PROGRESS']['count']);

        // Staff keep the real status.
        Sanctum::actingAs($this->leader);
        $this->assertSame(1, $this->getJson('/api/v1/tickets/stats')->json('data.counts.pending_review'));
        $this->assertSame([$review->id], collect($this->getJson('/api/v1/tickets?status=PENDING_REVIEW')->json('data.data'))->pluck('id')->all());
        $this->assertTrue(collect($this->getJson('/api/v1/tickets/board')->json('data.columns'))->pluck('status')->contains('PENDING_REVIEW'));
    }

    public function test_a_ticket_in_review_is_open_and_overdue_but_the_insight_points_at_the_reviewer(): void
    {
        $t = $this->submitted(['expected_resolution_time' => now()->subHours(100), 'created_at' => now()->subDays(6)]);

        Sanctum::actingAs($this->leader);
        $this->assertSame(1, $this->getJson('/api/v1/tickets/stats')->json('data.counts.overdue'), 'still counted as overdue');

        $insight = collect($this->getJson('/api/v1/tickets/insights')->assertOk()->json('data'))->firstWhere('ticket_id', $t->id);
        $this->assertNotNull($insight);
        $this->assertSame('بانتظار اعتماد المراجعة — راجع الإنجاز أو أعده للتنفيذ', $insight['suggested_action']);
        $this->assertNull($insight['suggested_priority']);
        $this->assertStringNotContainsString('توجيه', $insight['suggested_action']);
        $this->assertStringNotContainsString('تواصل', $insight['suggested_action']);
    }

    public function test_the_rework_reason_is_for_managers_and_the_assignee_only(): void
    {
        $t = $this->submitted();
        $this->returnForRework($t, null, ['reason' => 'سبب إعادة داخلي خاص'])->assertOk();

        foreach ([$this->leader, $this->assignee] as $viewer) {
            Sanctum::actingAs($viewer);
            $this->assertContains('RETURNED_FOR_REWORK', array_column($this->getJson("/api/v1/tickets/{$t->id}")->json('data.activity_logs'), 'action'));
        }

        Sanctum::actingAs($this->member);
        $plain = json_encode($this->getJson("/api/v1/tickets/{$t->id}")->assertOk()->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('RETURNED_FOR_REWORK', $plain);
        $this->assertStringNotContainsString('سبب إعادة داخلي خاص', $plain);

        Sanctum::actingAs($this->reporter);
        $reporter = json_encode($this->getJson("/api/v1/tickets/{$t->id}")->assertOk()->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('RETURNED_FOR_REWORK', $reporter);
        $this->assertStringNotContainsString('سبب إعادة داخلي خاص', $reporter);
    }

    public function test_only_the_assignee_receives_the_completion_and_rework_notifications(): void
    {
        $t = $this->submitted();
        $this->returnForRework($t)->assertOk();
        $rows = DB::table('platform_notifications')->where('type', 'ticket_returned_for_rework')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($this->assignee->id, (int) $rows->first()->user_id, 'not the reporter, not the reviewer');

        Sanctum::actingAs($this->assignee);
        $this->postJson("/api/v1/tickets/{$t->id}/complete-task", ['resolution_type' => 'RESOLVED', 'resolution_summary' => 'تم الحل بالكامل'])->assertOk();
        $this->approveCompletion($t)->assertOk();

        $rows = DB::table('platform_notifications')->where('type', 'ticket_completion_approved')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($this->assignee->id, (int) $rows->first()->user_id);
        $this->assertSame(0, DB::table('platform_notifications')->whereIn('type', ['ticket_completion_approved', 'ticket_returned_for_rework'])->whereIn('user_id', [$this->reporter->id, $this->leader->id])->count());
    }

    public function test_refusals_carry_stable_codes_and_a_credited_ticket_always_has_hours(): void
    {
        $t = $this->submitted();
        $this->approveCompletion($t)->assertOk();
        $this->approveCompletion($t)->assertStatus(409)->assertExactJson(['success' => false, 'message' => self::DUPLICATE, 'code' => 'ALREADY_CREDITED']);
        $this->returnForRework($t)->assertStatus(409)->assertJsonPath('code', 'ALREADY_CREDITED');

        $working = $this->submitted(['status' => 'IN_PROGRESS']);
        $this->approveCompletion($working)->assertStatus(409)->assertJsonPath('code', 'NOT_PENDING_REVIEW');
        $this->returnForRework($working)->assertStatus(409)->assertJsonPath('code', 'NOT_PENDING_REVIEW');

        $selfLeader = $this->submitted(['assigned_to_id' => $this->leader->id]);
        $this->approveCompletion($selfLeader, $this->leader)->assertForbidden()->assertJsonPath('code', 'SELF_REVIEW');
        $this->returnForRework($selfLeader, $this->leader)->assertForbidden()->assertJsonPath('code', 'SELF_REVIEW');

        $medium = $this->submitted(['task_scope' => 'medium', 'task_points' => 100]);
        $this->approveCompletion($medium)->assertForbidden()->assertJsonPath('code', 'HR_APPROVAL_REQUIRED');

        Sanctum::actingAs($this->leader);
        $this->patchJson("/api/v1/tickets/{$medium->id}/work-estimate", ['task_scope' => 'simple', 'task_points' => 31])->assertStatus(409)->assertJsonPath('code', 'ESTIMATE_FROZEN');

        // credit.hours is never null once credited (0 when the ticket had no hours estimate).
        $noHours = $this->submitted(['estimated_hours' => null]);
        $this->approveCompletion($noHours)->assertOk()->assertJsonPath('data.credit.hours', 0);
        $this->assertNotNull($noHours->fresh()->credited_hours);
    }

    public function test_self_review_is_answered_plainly_before_the_leaders_only_refusal(): void
    {
        $own = 'لا يمكنك اعتماد إنجاز مهمة أنت المكلف بها؛ يلزم مراجع آخر.';
        $t = $this->submitted();

        // An ordinary member who is the assignee: the self-review answer, with its code.
        $this->approveCompletion($t, $this->assignee)->assertForbidden()->assertJsonPath('code', 'SELF_REVIEW')->assertJsonPath('message', $own);
        $this->returnForRework($t, $this->assignee)->assertForbidden()->assertJsonPath('code', 'SELF_REVIEW')
            ->assertJsonPath('message', 'لا يمكنك إعادة مهمة أنت المكلف بها؛ يلزم مراجع آخر.');

        // The ordinary member who SUBMITTED it for someone else (a plain member as submitter) likewise.
        $other = $this->submitted();
        $other->forceFill(['submitted_by_id' => $this->member->id])->save();
        $this->approveCompletion($other, $this->member)->assertForbidden()->assertJsonPath('code', 'SELF_REVIEW')->assertJsonPath('message', \App\Services\TicketCreditService::SUBMITTER_MESSAGE);
        $this->returnForRework($other, $this->member)->assertForbidden()->assertJsonPath('code', 'SELF_REVIEW');

        // A plain member who is neither: the generic leaders-only 403, without a code.
        $plain = $this->approveCompletion($t, $this->member)->assertForbidden();
        $this->assertStringContainsString('لقائد الإدارة', $plain->json('message'));
        $this->assertArrayNotHasKey('code', $plain->json());
        $this->assertArrayNotHasKey('code', $this->returnForRework($t, $this->member)->assertForbidden()->json());

        // Somebody who cannot even view the ticket still gets 404.
        $this->approveCompletion($t, $this->user('student'))->assertNotFound();
        $this->assertSame(0, $this->awards()->count());
    }
}
