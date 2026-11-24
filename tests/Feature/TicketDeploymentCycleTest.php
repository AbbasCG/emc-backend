<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketActivityLog;
use App\Models\TicketCredit;
use App\Models\User;
use App\Models\VolunteerPointAward;
use App\Models\VolunteerProfile;
use App\Services\TaskWorkflowService;
use App\Services\TicketAccess;
use App\Services\TicketCreditService;
use App\Services\TicketDeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\GrantsTicketReviewPermissions;
use Tests\TestCase;

/**
 * THE LIVE-DEPLOYMENT CYCLE: a ticket flagged `requires_deployment` is credited ONLY when its assignee verifies the change
 * on the live site.
 *
 *   IN_PROGRESS -complete-task-> PENDING_REVIEW -approve-for-deploy-> APPROVED_FOR_DEPLOY -mark-deployed->
 *   PENDING_VERIFICATION -verify-resolved-> RESOLVED (one credit)        (verify-failed / return-for-rework go back)
 *
 * Every other ticket keeps the classic flow (TicketCreditTest / TicketActualDurationTest).
 */
class TicketDeploymentCycleTest extends TestCase
{
    use RefreshDatabase;
    use GrantsTicketReviewPermissions;

    private const DUPLICATE = 'تم اعتماد هذه التذكرة وإضافة رصيدها مسبقًا.';

    private Department $dept;

    private User $leader;

    private User $assignee;

    private User $member;

    private User $reporter;

    private User $admin;

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
        $this->admin = $this->user('admin');
        // Reviewing is a permission now (tickets.review_completion): both the leader and the global admin hold it (never the participation one).
        $this->grantTicketReview($this->leader);
        $this->grantTicketReview($this->admin);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function user(string $role = 'student'): User
    {
        return $this->grantReviewToReviewerRole(User::factory()->create(['role' => $role, 'is_active' => true]));
    }

    private function deptMember(): User
    {
        $u = $this->user();
        $this->dept->users()->attach($u->id);

        return $u;
    }

    /** A deployment ticket (flag set the way only the application sets it) in the given status, estimate 2 h, simple, 30 points. */
    private function dticket(string $status = 'IN_PROGRESS', array $over = [], bool $flag = true): Ticket
    {
        $t = Ticket::create(array_merge([
            'ticket_number' => 'EMC-TCK-X-'.Str::random(8), 'title' => 'تعديل صفحة الدفع', 'description' => 'وصف', 'ticket_category' => 'OLD_ISSUE', 'priority' => 'MEDIUM',
            'department_id' => $this->dept->id, 'created_by_id' => $this->reporter->id, 'created_by_name' => 'مُبلِّغ', 'status' => $status,
            'assigned_to_id' => $this->assignee->id, 'approved_by_id' => $this->leader->id, 'accepted_at' => now()->subHour(),
            'task_scope' => 'simple', 'task_points' => 30, 'estimated_hours' => 2,
        ], $over));
        if ($flag) {
            $t->forceFill(['requires_deployment' => true])->save();
        }

        return $t;
    }

    private function act(Ticket $t, string $endpoint, array $body = [], ?User $as = null)
    {
        Sanctum::actingAs($as ?? $this->leader);

        return $this->postJson("/api/v1/tickets/{$t->id}/{$endpoint}", $body);
    }

    private function submit(Ticket $t, ?User $as = null, array $body = [])
    {
        return $this->act($t, 'complete-task', $body, $as ?? $this->assignee);
    }

    private function approveDeploy(Ticket $t, array $body = ['actual_minutes' => 75], ?User $as = null)
    {
        return $this->act($t, 'approve-for-deploy', $body, $as);
    }

    private function deploy(Ticket $t, array $body = ['live_url' => 'https://example.com/pay'], ?User $as = null)
    {
        return $this->act($t, 'mark-deployed', $body, $as ?? $this->admin);
    }

    private function verify(Ticket $t, array $body = ['note' => 'تحققت على الموقع وهو يعمل'], ?User $as = null)
    {
        return $this->act($t, 'verify-resolved', $body, $as ?? $this->assignee);
    }

    private function hours(User $u): float
    {
        return (float) (VolunteerProfile::where('user_id', $u->id)->value('hours_logged') ?? 0);
    }

    private function awards(?Ticket $t = null)
    {
        return VolunteerPointAward::where('source_type', Ticket::class)->when($t, fn ($q) => $q->where('source_id', $t->id));
    }

    private function state(): array
    {
        return [VolunteerPointAward::count(), (float) VolunteerProfile::sum('hours_logged'), TicketCredit::count()];
    }

    private function logs(Ticket $t): array
    {
        return TicketActivityLog::where('ticket_id', $t->id)->orderBy('id')->pluck('action')->all();
    }

    private function notifications(string $type, ?User $user = null): int
    {
        return DB::table('platform_notifications')->where('type', $type)->when($user, fn ($q) => $q->where('user_id', $user->id))->count();
    }

    // ═══ The full cycle ═════════════════════════════════════════════════════

    public function test_the_full_cycle_credits_only_after_the_live_verification(): void
    {
        $t = $this->dticket();
        $before = $this->state();

        // 1. ONE-CLICK submission: an empty body works and credits nothing.
        $this->submit($t)->assertOk()->assertJsonPath('data.status', 'PENDING_REVIEW')->assertJsonPath('data.workflow.stage', 'pending_review')->assertJsonPath('data.workflow.next_actor', 'reviewer');
        $this->assertSame(1, $t->fresh()->review_round);
        $this->assertSame($before, $this->state());

        // 2. Approved for deployment (estimate 2 h, ACTUAL 75 min): the approved values are stored, nothing is credited.
        $this->approveDeploy($t, ['actual_minutes' => 75, 'note' => 'جاهز للنشر'])->assertOk()
            ->assertJsonPath('data.status', 'APPROVED_FOR_DEPLOY')
            ->assertJsonPath('data.actual_minutes', 75)
            ->assertJsonPath('data.credit.status', 'approved')
            ->assertJsonPath('data.credit.points', 30)
            ->assertJsonPath('data.credit.minutes', 75)
            ->assertJsonPath('data.credit.credited_at', null)
            ->assertJsonPath('data.workflow.stage', 'approved_for_deploy')
            ->assertJsonPath('data.workflow.next_actor', 'deployer')
            ->assertJsonPath('data.deployment.review_approved_by_name', $this->leader->name);
        $this->assertSame($before, $this->state(), 'approving for deployment credits nothing');
        $this->assertNull($t->fresh()->credited_at);

        // 3. Deployed by a global role: the deploy time is the server's, the assignee is told once.
        $this->deploy($t)->assertOk()
            ->assertJsonPath('data.status', 'PENDING_VERIFICATION')
            ->assertJsonPath('data.deployment.live_url', 'https://example.com/pay')
            ->assertJsonPath('data.deployment.deployed_by_name', $this->admin->name)
            ->assertJsonPath('data.workflow.next_actor', 'assignee')
            ->assertJsonPath('data.workflow.next_step_key', 'verify_live')
            ->assertJsonPath('data.credit.status', 'approved');
        $this->assertNotNull($t->fresh()->deployed_at);
        $this->assertSame($before, $this->state(), 'marking it deployed credits nothing');
        $this->assertSame(1, $this->notifications('ticket_live_verification_required', $this->assignee));
        $this->assertSame(1, $this->notifications('ticket_live_verification_required'), 'only the assignee is told');
        $this->assertSame(0, $this->notifications('ticket_resolved', $this->reporter), 'the reporter hears nothing before the end');

        // 4. The assignee verifies on the live site: the ONE credit, exactly the approved values.
        $this->verify($t)->assertOk()
            ->assertJsonPath('data.status', 'RESOLVED')
            ->assertJsonPath('data.credit.status', 'credited')
            ->assertJsonPath('data.credit.points', 30)
            ->assertJsonPath('data.credit.hours', 1.25)
            ->assertJsonPath('data.credit.minutes', 75)
            ->assertJsonPath('data.credit.credited_by_name', $this->leader->name);

        $this->assertEqualsWithDelta(1.25, $this->hours($this->assignee), 0.0001, '+1.25 h exactly (not the 2 h estimate)');
        $award = $this->awards($t)->sole();
        $this->assertSame($this->assignee->id, (int) $award->user_id);
        $this->assertSame(30, $award->points);
        $this->assertSame($this->leader->id, (int) $award->awarded_by, 'recorded as approved by the reviewer');
        $credit = TicketCredit::where('ticket_id', $t->id)->sole();
        $this->assertSame([75, $this->leader->id], [$credit->minutes, (int) $credit->approved_by_id]);
        $fresh = $t->fresh();
        $this->assertSame('RESOLVED', $fresh->status);
        $this->assertNotNull($fresh->verified_at);
        $this->assertSame($this->assignee->id, (int) $fresh->verified_by_id);
        $this->assertSame($this->leader->id, (int) $fresh->credited_by_id);
        $this->assertSame(1, $this->notifications('ticket_resolved', $this->reporter), 'the reporter is notified once, at RESOLVED');
        $this->assertSame(0, $this->notifications('ticket_completion_approved'), 'the assignee did it themselves: no self notification');

        $this->assertEquals(['SUBMITTED_FOR_REVIEW', 'APPROVED_FOR_DEPLOY', 'DEPLOYED_LIVE', 'DEPLOY_DETAILS', 'VERIFIED_RESOLVED', 'CREDIT_ADDED'], array_values(array_diff($this->logs($t), ['ESTIMATE_UPDATED'])));
    }

    public function test_replay_and_a_parallel_verification_credit_once(): void
    {
        $t = $this->dticket('PENDING_VERIFICATION', ['review_round' => 1]);
        $t->forceFill(['actual_minutes' => 75, 'review_approved_by_id' => $this->leader->id, 'review_approved_at' => now(), 'deployed_by_id' => $this->admin->id, 'deployed_at' => now(), 'live_url' => 'https://example.com/x'])->save();

        $this->verify($t)->assertOk();
        $state = $this->state();
        $this->verify($t)->assertStatus(409)->assertJsonPath('code', 'ALREADY_CREDITED')->assertJsonPath('message', self::DUPLICATE);
        $this->act($t, 'verify-failed', ['description' => 'محاولة بعد الإغلاق'], $this->assignee)->assertStatus(409)->assertJsonPath('code', 'ALREADY_CREDITED');
        $this->assertSame($state, $this->state());
        $this->assertSame('RESOLVED', $t->fresh()->status);
        $this->assertEqualsWithDelta(1.25, $this->hours($this->assignee), 0.0001);
    }

    public function test_the_unique_index_is_the_backstop_and_the_status_rolls_back_with_the_credit(): void
    {
        $t = $this->dticket('PENDING_VERIFICATION');
        $t->forceFill(['actual_minutes' => 75, 'review_approved_by_id' => $this->leader->id, 'review_approved_at' => now(), 'deployed_by_id' => $this->admin->id, 'deployed_at' => now(), 'live_url' => 'https://example.com/x'])->save();
        // A concurrent request committed its credit row between our check and our insert.
        TicketCredit::create(['ticket_id' => $t->id, 'user_id' => $this->assignee->id, 'points' => 30, 'hours' => 1.25, 'minutes' => 75, 'approved_by_id' => $this->leader->id, 'credited_at' => now()]);
        $state = $this->state();
        $logs = TicketActivityLog::count();

        $racing = new class(app(TicketAccess::class)) extends TicketCreditService
        {
            protected function alreadyCredited(Ticket $ticket): bool
            {
                return false;
            }
        };
        $service = new TicketDeploymentService(app(TicketAccess::class), $racing);

        try {
            $service->verifyResolved($t->id, $this->assignee, 'تحققت من الحل');
            $this->fail('the unique index must stop the second credit');
        } catch (\App\Exceptions\TicketActionException $e) {
            $this->assertSame([409, 'ALREADY_CREDITED'], [$e->status, $e->errorCode]);
        }

        $this->assertSame($state, $this->state(), 'no ledger row, no hours, no second credit row');
        $this->assertSame($logs, TicketActivityLog::count());
        $fresh = $t->fresh();
        $this->assertSame('PENDING_VERIFICATION', $fresh->status, 'the status change rolled back with the credit');
        $this->assertNull($fresh->verified_at);
        $this->assertNull($fresh->credited_at);
    }

    public function test_a_failed_verification_goes_back_and_the_next_round_needs_everything_again(): void
    {
        $t = $this->dticket();
        $this->submit($t)->assertOk();
        $this->approveDeploy($t, ['actual_minutes' => 75])->assertOk();
        $this->deploy($t)->assertOk();
        $before = $this->state();

        $res = $this->act($t, 'verify-failed', ['description' => 'الزر ما زال لا يعمل على الموقع الحي'], $this->assignee)->assertOk()
            ->assertJsonPath('data.status', 'IN_PROGRESS')->assertJsonPath('data.actual_minutes', null)->assertJsonPath('data.credit.status', 'none')
            ->assertJsonPath('data.deployment.live_url', null)->assertJsonPath('data.deployment.deployed_at', null)->assertJsonPath('data.deployment.review_approved_at', null);
        $this->assertSame($before, $this->state(), 'nothing credited');
        $fresh = $t->fresh();
        foreach (['actual_minutes', 'review_approved_by_id', 'review_approved_at', 'deployed_by_id', 'deployed_at', 'live_url', 'verified_at'] as $f) {
            $this->assertNull($fresh->{$f}, $f);
        }
        $this->assertContains('VERIFICATION_FAILED', $this->logs($t));

        // The next round cannot skip anything: verifying now is refused, and a new review/approval/deployment is needed.
        $this->verify($t)->assertStatus(409)->assertJsonPath('code', 'INVALID_STAGE');
        $this->submit($t)->assertOk()->assertJsonPath('data.deployment.round', 2);
        $this->approveDeploy($t, ['actual_minutes' => 30, 'task_points' => 40])->assertOk();
        $this->verify($t)->assertStatus(409)->assertJsonPath('code', 'INVALID_STAGE');
        $this->deploy($t, ['live_url' => 'https://example.com/pay?v=2'])->assertOk();
        $this->assertSame(2, $this->notifications('ticket_live_verification_required', $this->assignee), 'one notification per round');
        $this->verify($t)->assertOk()->assertJsonPath('data.credit.points', 40)->assertJsonPath('data.credit.minutes', 30);

        $this->assertSame(1, $this->awards($t)->count(), 'ONE credit across both rounds');
        $this->assertSame(40, $this->awards($t)->sole()->points);
        $this->assertEqualsWithDelta(0.5, $this->hours($this->assignee), 0.0001, 'the NEW round values, not the first');
    }

    public function test_return_for_rework_from_review_and_from_approved_for_deploy_clears_the_approval(): void
    {
        // From PENDING_REVIEW.
        $a = $this->dticket();
        $this->submit($a)->assertOk();
        $this->act($a, 'return-for-rework', ['reason' => 'يلزم استكمال الاختبار'])->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');

        // From APPROVED_FOR_DEPLOY: the approved values are void.
        $b = $this->dticket();
        $this->submit($b)->assertOk();
        $this->approveDeploy($b)->assertOk();
        $this->assertSame(75, $b->fresh()->actual_minutes);
        $this->act($b, 'return-for-rework', ['reason' => 'ظهر خلل قبل النشر'])->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS')->assertJsonPath('data.actual_minutes', null);
        $fresh = $b->fresh();
        $this->assertNull($fresh->actual_minutes);
        $this->assertNull($fresh->review_approved_at);
        $this->assertSame('APPROVED_FOR_DEPLOY', TicketActivityLog::where('ticket_id', $b->id)->where('action', 'RETURNED_FOR_REWORK')->value('previous_state'));
        $this->assertSame(0, $this->awards()->count());

        // Once live, only the assignee sends it back (verify-failed).
        $c = $this->dticket();
        $this->submit($c)->assertOk();
        $this->approveDeploy($c)->assertOk();
        $this->deploy($c)->assertOk();
        $this->act($c, 'return-for-rework', ['reason' => 'محاولة إعادة بعد النشر'])->assertStatus(409)->assertJsonPath('code', 'INVALID_STAGE');
        $this->assertSame('PENDING_VERIFICATION', $c->fresh()->status);

        // A closed (credited) ticket cannot be returned.
        $this->verify($c)->assertOk();
        $this->act($c, 'return-for-rework', ['reason' => 'محاولة إعادة بعد الإغلاق'])->assertStatus(409)->assertJsonPath('code', 'ALREADY_CREDITED');
    }

    // ═══ Stages cannot be skipped; nothing closes a deployment ticket but the live verification ═══

    public function test_every_endpoint_refuses_the_wrong_stage(): void
    {
        $matrix = [
            ['approve-for-deploy', ['IN_PROGRESS', 'APPROVED_FOR_DEPLOY', 'PENDING_VERIFICATION', 'ASSIGNED'], ['actual_minutes' => 75], null],
            ['mark-deployed', ['IN_PROGRESS', 'PENDING_REVIEW', 'PENDING_VERIFICATION'], ['live_url' => 'https://example.com'], $this->admin],
            ['verify-resolved', ['IN_PROGRESS', 'PENDING_REVIEW', 'APPROVED_FOR_DEPLOY'], ['note' => 'تحققت'], $this->assignee],
            ['verify-failed', ['IN_PROGRESS', 'PENDING_REVIEW', 'APPROVED_FOR_DEPLOY'], ['description' => 'مشكلة ظاهرة'], $this->assignee],
        ];
        $state = $this->state();
        foreach ($matrix as [$endpoint, $statuses, $body, $as]) {
            foreach ($statuses as $status) {
                $t = $this->dticket($status);
                $this->act($t, $endpoint, $body, $as)->assertStatus(409)->assertJsonPath('code', 'INVALID_STAGE');
                $this->assertSame($status, $t->fresh()->status, "{$endpoint} left {$status} untouched");
            }
        }
        $this->assertSame($state, $this->state());

        // The assignee's own «finished» is only valid IN_PROGRESS.
        foreach (['PENDING_REVIEW', 'APPROVED_FOR_DEPLOY', 'PENDING_VERIFICATION'] as $status) {
            $this->submit($this->dticket($status))->assertStatus(409);
        }
    }

    public function test_a_deployment_ticket_cannot_be_closed_any_other_way(): void
    {
        $t = $this->dticket('PENDING_REVIEW');
        $state = $this->state();

        $this->act($t, 'approve-completion', ['actual_minutes' => 75])->assertStatus(409)->assertJsonPath('code', 'DEPLOYMENT_REQUIRED')
            ->assertJsonPath('message', 'هذه التذكرة تتطلب نشرًا على الموقع الحي؛ استخدم اعتماد للنشر.');
        $this->act($this->dticket('APPROVED_FOR_DEPLOY'), 'approve-completion', ['actual_minutes' => 75], $this->admin)->assertStatus(409)->assertJsonPath('code', 'DEPLOYMENT_REQUIRED');

        // complete-task never resolves it, whatever it is asked for.
        $working = $this->dticket();
        $this->submit($working, null, ['resolution_type' => 'RESOLVED', 'resolution_summary' => 'تم الحل نهائيًا'])->assertOk()->assertJsonPath('data.status', 'PENDING_REVIEW');
        $orphan = $this->dticket('IN_PROGRESS', ['assigned_to_id' => null]);
        $this->submit($orphan, $this->admin)->assertStatus(409)->assertJsonPath('code', 'INVALID_STAGE');
        $this->assertSame('IN_PROGRESS', $orphan->fresh()->status);

        // Bulk and the work-estimate endpoint cannot change the status.
        Sanctum::actingAs($this->leader);
        $this->postJson('/api/v1/tickets/bulk', ['action' => 'reassign', 'ticket_ids' => [$t->id], 'assigned_to_id' => $this->member->id, 'task_scope' => 'simple', 'task_points' => 30])->assertOk()->assertJsonPath('data.failed', 1);
        $this->assertSame('PENDING_REVIEW', $t->fresh()->status);
        $this->assertSame($state, $this->state());
        $this->assertSame(0, $this->awards()->count());
    }

    public function test_complete_task_is_one_click_only_for_deployment_tickets(): void
    {
        $d = $this->dticket();
        $this->submit($d)->assertOk()->assertJsonPath('data.status', 'PENDING_REVIEW');
        $this->assertNull($d->fresh()->resolution_summary, 'no placeholder is stored: it would reach the reporter as the resolution note');

        // What the form sends today keeps working, with the summary it sends.
        $d2 = $this->dticket();
        $this->submit($d2, null, ['resolution_type' => 'RESOLVED', 'resolution_summary' => 'ملخص كتبه المكلف'])->assertOk();
        $this->assertSame('ملخص كتبه المكلف', $d2->fresh()->resolution_summary);

        // A summary that IS sent is still validated; UNRESOLVED still needs its reason.
        $d3 = $this->dticket();
        $this->submit($d3, null, ['resolution_type' => 'RESOLVED', 'resolution_summary' => 'قص'])->assertStatus(422);
        $this->submit($d3, null, ['resolution_type' => 'UNRESOLVED'])->assertStatus(422);

        // Any other ticket keeps today's payload: a body-less call is refused.
        $plain = $this->dticket('IN_PROGRESS', [], false);
        $this->submit($plain)->assertStatus(422);
        $this->submit($plain, null, ['resolution_type' => 'RESOLVED'])->assertStatus(422);
        $this->submit($plain, null, ['resolution_type' => 'RESOLVED', 'resolution_summary' => 'تم الحل بالكامل'])->assertOk()->assertJsonPath('data.status', 'PENDING_REVIEW');
    }

    // ═══ Who may do what ════════════════════════════════════════════════════

    public function test_approve_for_deploy_actor_matrix(): void
    {
        $t = $this->dticket('PENDING_REVIEW');
        $medium = $this->dticket('PENDING_REVIEW', ['task_scope' => 'medium', 'task_points' => 100]);
        $onBehalf = $this->dticket('IN_PROGRESS');
        $x = $this->user('admin');
        $this->act($onBehalf, 'complete-task', [], $x)->assertOk(); // a global role submits for the assignee
        $selfLeader = $this->dticket('PENDING_REVIEW', ['assigned_to_id' => $this->leader->id]);
        $otherLeader = $this->user('programs_manager');
        Department::create(['name' => 'O'.Str::random(4), 'name_ar' => 'أخرى', 'slug' => 'o-'.Str::random(6), 'status' => 'active', 'is_active' => true, 'leader_id' => $otherLeader->id]);
        $state = $this->state();

        // A valid AND an invalid body get the same authorization answer (no 422 probing).
        foreach ([['actual_minutes' => 75], ['actual_minutes' => 0, 'task_points' => 9999]] as $body) {
            // Deliberately changed (was SELF_REVIEW): no review permission / the participation permission is missing.
            $this->approveDeploy($t, $body, $this->assignee)->assertForbidden()->assertJsonPath('code', 'REVIEW_PERMISSION_REQUIRED');
            $this->approveDeploy($onBehalf, $body, $x)->assertForbidden()->assertJsonPath('code', 'PARTICIPATION_PERMISSION_REQUIRED');
            $this->approveDeploy($selfLeader, $body, $this->leader)->assertForbidden()->assertJsonPath('code', 'PARTICIPATION_PERMISSION_REQUIRED');
            $this->approveDeploy($t, $body, $this->member)->assertForbidden()->assertJsonPath('code', 'REVIEW_PERMISSION_REQUIRED');
            $this->approveDeploy($t, $body, $this->reporter)->assertForbidden();
            $this->approveDeploy($t, $body, $otherLeader)->assertNotFound();
            $this->approveDeploy($medium, $body, $this->leader)->assertForbidden()->assertJsonPath('code', 'HR_APPROVAL_REQUIRED');
        }
        $this->assertSame($state, $this->state());
        foreach ([$t, $medium, $onBehalf, $selfLeader] as $x2) {
            $this->assertNull($x2->fresh()->actual_minutes);
            $this->assertNull($x2->fresh()->review_approved_at);
        }

        // An HR approver may approve the medium ticket; another global role may approve the on-behalf one.
        $this->approveDeploy($medium, ['actual_minutes' => 120], $this->admin)->assertOk()->assertJsonPath('data.credit.points', 100);
        $this->approveDeploy($onBehalf, ['actual_minutes' => 60], $this->user('super_admin'))->assertOk();
    }

    public function test_mark_deployed_actor_matrix(): void
    {
        $t = $this->dticket('APPROVED_FOR_DEPLOY');
        $selfLeader = $this->dticket('APPROVED_FOR_DEPLOY', ['assigned_to_id' => $this->leader->id]);
        $otherLeader = $this->user('programs_manager');
        Department::create(['name' => 'O'.Str::random(4), 'name_ar' => 'أخرى', 'slug' => 'o-'.Str::random(6), 'status' => 'active', 'is_active' => true, 'leader_id' => $otherLeader->id]);
        $body = ['live_url' => 'https://example.com/live'];

        $this->deploy($t, $body, $this->assignee)->assertForbidden();
        $this->deploy($selfLeader, $body, $this->leader)->assertForbidden()->assertJsonPath('code', 'SELF_REVIEW');
        $this->deploy($t, $body, $this->member)->assertForbidden();
        $this->deploy($t, $body, $this->reporter)->assertForbidden();
        $this->deploy($t, $body, $otherLeader)->assertNotFound();
        $this->assertSame('APPROVED_FOR_DEPLOY', $t->fresh()->status);
        $this->assertNull($t->fresh()->live_url);

        // The department leader (a different person) may deploy, as may a global role.
        $this->deploy($t, $body, $this->leader)->assertOk();
        $this->deploy($selfLeader, $body, $this->admin)->assertOk();
    }

    public function test_only_the_actual_assignee_verifies_and_a_global_role_does_not_stand_in(): void
    {
        $setup = function (): Ticket {
            $t = $this->dticket('PENDING_VERIFICATION');
            $t->forceFill(['actual_minutes' => 75, 'review_approved_by_id' => $this->leader->id, 'review_approved_at' => now(), 'deployed_by_id' => $this->admin->id, 'deployed_at' => now(), 'live_url' => 'https://example.com/x'])->save();

            return $t;
        };
        $t = $setup();
        $otherLeader = $this->user('programs_manager');
        Department::create(['name' => 'O'.Str::random(4), 'name_ar' => 'أخرى', 'slug' => 'o-'.Str::random(6), 'status' => 'active', 'is_active' => true, 'leader_id' => $otherLeader->id]);
        $state = $this->state();

        foreach (['verify-resolved' => ['note' => 'تحققت من الحل'], 'verify-failed' => ['description' => 'مشكلة على الموقع']] as $endpoint => $body) {
            $this->act($t, $endpoint, $body, $this->leader)->assertForbidden()->assertJsonPath('code', 'ASSIGNEE_ONLY');   // the reviewer who is not the assignee
            $this->act($t, $endpoint, $body, $this->admin)->assertForbidden()->assertJsonPath('code', 'ASSIGNEE_ONLY');    // a global role: no bypass
            $this->act($t, $endpoint, $body, $this->member)->assertForbidden();
            $this->act($t, $endpoint, $body, $this->reporter)->assertForbidden();
            $this->act($t, $endpoint, $body, $otherLeader)->assertNotFound();
        }
        $this->assertSame($state, $this->state());
        $this->assertSame('PENDING_VERIFICATION', $t->fresh()->status);

        $this->verify($t)->assertOk();
    }

    public function test_the_new_endpoints_refuse_ordinary_tickets(): void
    {
        $t = $this->dticket('PENDING_REVIEW', [], false);

        $this->approveDeploy($t)->assertStatus(409)->assertJsonPath('code', 'NOT_A_DEPLOYMENT_TICKET');
        $this->deploy($t)->assertStatus(409)->assertJsonPath('code', 'NOT_A_DEPLOYMENT_TICKET');
        $this->verify($t)->assertStatus(409)->assertJsonPath('code', 'NOT_A_DEPLOYMENT_TICKET');
        $this->act($t, 'verify-failed', ['description' => 'مشكلة ظاهرة'], $this->assignee)->assertStatus(409)->assertJsonPath('code', 'NOT_A_DEPLOYMENT_TICKET');
        $this->assertSame('PENDING_REVIEW', $t->fresh()->status);

        // ...and the classic approval still works for it, crediting the ACTUAL time.
        $this->act($t, 'approve-completion', ['actual_minutes' => 90])->assertOk()->assertJsonPath('data.status', 'RESOLVED')->assertJsonPath('data.credit.hours', 1.5);
    }

    // ═══ Validation ═════════════════════════════════════════════════════════

    public function test_validation_of_every_step(): void
    {
        $t = $this->dticket('PENDING_REVIEW');
        $state = $this->state();

        // approve-for-deploy: the duration, the points, the note.
        // CHANGED: in this flow the final step is the live verification, so the requirement reads «قبل الاعتماد للنشر».
        $deployRequired = 'أدخل المدة الفعلية للإنجاز (بالساعات والدقائق) قبل الاعتماد للنشر.';
        $this->approveDeploy($t, [])->assertStatus(422)->assertJsonPath('code', 'ACTUAL_DURATION_REQUIRED')
            ->assertJsonPath('message', $deployRequired)->assertJsonPath('errors.actual_minutes.0', $deployRequired);
        $this->approveDeploy($t, ['actual_minutes' => null, 'task_points' => 51])->assertStatus(422)->assertJsonPath('errors.actual_minutes.0', $deployRequired);
        // The classic approval keeps its own wording.
        $classic = $this->dticket('PENDING_REVIEW', [], false);
        $this->act($classic, 'approve-completion', [])->assertStatus(422)->assertJsonPath('errors.actual_minutes.0', 'أدخل المدة الفعلية للإنجاز (بالساعات والدقائق) قبل الاعتماد النهائي.');
        $this->approveDeploy($t, ['actual_minutes' => 0])->assertStatus(422)->assertJsonPath('code', 'ACTUAL_DURATION_INVALID');
        $this->approveDeploy($t, ['actual_minutes' => 60001])->assertStatus(422)->assertJsonPath('code', 'ACTUAL_DURATION_INVALID');
        $this->approveDeploy($t, ['actual_minutes' => 60, 'task_points' => 51])->assertStatus(422)->assertJsonStructure(['errors' => ['task_points']]);
        $this->approveDeploy($t, ['actual_minutes' => 60, 'note' => str_repeat('x', 1001)])->assertStatus(422)->assertJsonStructure(['errors' => ['note']]);
        $this->assertSame($state, $this->state());
        $this->assertSame('PENDING_REVIEW', $t->fresh()->status);
        $this->assertNull($t->fresh()->actual_minutes);

        // mark-deployed: the link.
        $a = $this->dticket('APPROVED_FOR_DEPLOY');
        foreach ([[], ['live_url' => ''], ['live_url' => 'http://example.com'], ['live_url' => 'ftp://example.com'], ['live_url' => 'javascript:alert(1)'], ['live_url' => 'not a url'], ['live_url' => 'https://example.com/'.str_repeat('a', 500)]] as $body) {
            $this->deploy($a, $body)->assertStatus(422)->assertJsonStructure(['errors' => ['live_url']]);
        }
        $this->deploy($a, ['live_url' => 'https://example.com/ok', 'note' => str_repeat('x', 1001)])->assertStatus(422)->assertJsonStructure(['errors' => ['note']]);
        $this->assertSame('APPROVED_FOR_DEPLOY', $a->fresh()->status);

        // verify-*: the note / description, 5..1000.
        $v = $this->dticket('PENDING_VERIFICATION');
        $v->forceFill(['actual_minutes' => 75, 'review_approved_by_id' => $this->leader->id])->save();
        foreach ([[], ['note' => 'قصير'], ['note' => str_repeat('x', 1001)]] as $body) {
            $this->verify($v, $body)->assertStatus(422)->assertJsonStructure(['errors' => ['note']]);
        }
        foreach ([[], ['description' => 'قصير'], ['description' => str_repeat('x', 1001)]] as $body) {
            $this->act($v, 'verify-failed', $body, $this->assignee)->assertStatus(422)->assertJsonStructure(['errors' => ['description']]);
        }
        $this->assertSame('PENDING_VERIFICATION', $v->fresh()->status);
        $this->assertSame($state, $this->state());
    }

    public function test_points_options_are_one_definition_and_every_option_validates_everywhere(): void
    {
        Sanctum::actingAs($this->leader);
        $meta = $this->getJson('/api/v1/tickets/meta?department_id='.$this->dept->id)->assertOk();
        $options = $meta->json('points_options');
        $this->assertSame(range(25, 50, 5), $options['simple']);
        $this->assertSame(range(75, 150, 5), $options['medium']);
        $this->assertSame(range(200, 300, 10), $options['large']);
        $this->assertSame([300, 350, 400, 500, 600, 750, 1000], $options['strategic']);
        $this->assertSame(['min' => 25, 'max' => 50], $meta->json('scope_ranges.simple'));
        $this->assertSame(['min' => 300, 'max' => null], $meta->json('scope_ranges.strategic'));
        $this->assertSame($options, TaskWorkflowService::pointsOptions());

        foreach ($options as $scope => $list) {
            foreach ($list as $points) {
                $this->assertTrue(TaskWorkflowService::validatePointsForScope($scope, $points)['valid'], "{$scope} {$points}");
            }
        }
        // The server judges by the RANGE: an in-range legacy value that is not an option stays valid.
        foreach ([['simple', 33], ['simple', 45], ['medium', 77], ['large', 205], ['strategic', 320], ['strategic', 1500]] as [$scope, $points]) {
            $this->assertTrue(TaskWorkflowService::validatePointsForScope($scope, $points)['valid'], "{$scope} {$points}");
        }
        foreach ([['simple', 24], ['simple', 51], ['medium', 74], ['medium', 151], ['large', 199], ['large', 301], ['strategic', 299]] as [$scope, $points]) {
            $this->assertFalse(TaskWorkflowService::validatePointsForScope($scope, $points)['valid'], "{$scope} {$points}");
        }
    }

    public function test_out_of_range_points_are_refused_on_every_path(): void
    {
        $bad = ['task_scope' => 'simple', 'task_points' => 51];

        // approve (assignment)
        $pending = $this->dticket('PENDING_APPROVAL', ['assigned_to_id' => null, 'task_scope' => null, 'task_points' => null], false);
        $this->act($pending, 'approve', ['sla_hours' => 24, 'assigned_to_id' => $this->assignee->id] + $bad)->assertStatus(422)->assertJsonStructure(['errors' => ['task_points']]);
        // reassign and bulk
        $assigned = $this->dticket('ASSIGNED', [], false);
        $this->act($assigned, 'reassign', ['assigned_to_id' => $this->member->id] + $bad)->assertStatus(422);
        $this->act($assigned, 'return-for-rework', ['reason' => 'ليست في مرحلة المراجعة'])->assertStatus(409);
        Sanctum::actingAs($this->leader);
        $this->postJson('/api/v1/tickets/bulk', ['action' => 'reassign', 'ticket_ids' => [$assigned->id], 'assigned_to_id' => $this->member->id] + $bad)->assertStatus(422);
        // work-estimate
        $this->patchJson("/api/v1/tickets/{$assigned->id}/work-estimate", $bad)->assertStatus(422);
        // approve-completion and approve-for-deploy
        $classic = $this->dticket('PENDING_REVIEW', [], false);
        $this->act($classic, 'approve-completion', ['actual_minutes' => 60] + $bad)->assertStatus(422)->assertJsonStructure(['errors' => ['task_points']]);
        $deploy = $this->dticket('PENDING_REVIEW');
        $this->approveDeploy($deploy, ['actual_minutes' => 60] + $bad)->assertStatus(422)->assertJsonStructure(['errors' => ['task_points']]);

        $this->assertSame(30, $assigned->fresh()->task_points);
        $this->assertSame(0, $this->awards()->count());
    }

    // ═══ The flag ═══════════════════════════════════════════════════════════

    public function test_a_manager_sets_the_flag_through_approve_reassign_bulk_and_the_estimate_endpoint(): void
    {
        // approve (assignment)
        $a = $this->dticket('PENDING_APPROVAL', ['assigned_to_id' => null], false);
        $this->act($a, 'approve', ['sla_hours' => 24, 'assigned_to_id' => $this->assignee->id, 'requires_deployment' => true, 'task_scope' => 'simple', 'task_points' => 30])->assertOk();
        $this->assertTrue((bool) $a->fresh()->requires_deployment);
        $this->assertContains('DEPLOYMENT_FLAG_CHANGED', $this->logs($a));

        // absent keeps; explicit false clears (reassign)
        $this->act($a, 'reassign', ['assigned_to_id' => $this->member->id, 'task_scope' => 'simple', 'task_points' => 30])->assertOk();
        $this->assertTrue((bool) $a->fresh()->requires_deployment, 'absent keeps the stored value');
        $this->act($a, 'reassign', ['assigned_to_id' => $this->assignee->id, 'task_scope' => 'simple', 'task_points' => 30, 'requires_deployment' => false])->assertOk();
        $this->assertFalse((bool) $a->fresh()->requires_deployment);

        // bulk reassign and PATCH work-estimate (IN_PROGRESS)
        $b = $this->dticket('IN_PROGRESS', [], false);
        Sanctum::actingAs($this->leader);
        $this->postJson('/api/v1/tickets/bulk', ['action' => 'reassign', 'ticket_ids' => [$b->id], 'assigned_to_id' => $this->member->id, 'task_scope' => 'simple', 'task_points' => 30, 'requires_deployment' => true])->assertOk()->assertJsonPath('data.succeeded', 1);
        $this->assertTrue((bool) $b->fresh()->requires_deployment);
        $c = $this->dticket('IN_PROGRESS', ['assigned_to_id' => $this->assignee->id], false);
        $this->patchJson("/api/v1/tickets/{$c->id}/work-estimate", ['task_scope' => 'simple', 'task_points' => 35, 'requires_deployment' => true])->assertOk();
        $this->assertTrue((bool) $c->fresh()->requires_deployment);
        $this->patchJson("/api/v1/tickets/{$c->id}/work-estimate", ['task_scope' => 'simple', 'task_points' => 35, 'requires_deployment' => 'abc'])->assertStatus(422);
    }

    public function test_the_flag_is_frozen_after_submission_and_not_writable_by_anyone_else(): void
    {
        foreach (['PENDING_REVIEW', 'APPROVED_FOR_DEPLOY', 'PENDING_VERIFICATION'] as $status) {
            $t = $this->dticket($status, [], false);
            Sanctum::actingAs($this->leader);
            $this->patchJson("/api/v1/tickets/{$t->id}/work-estimate", ['task_scope' => 'simple', 'task_points' => 30, 'requires_deployment' => true])
                ->assertStatus(409)->assertJsonPath('code', 'ESTIMATE_FROZEN');
            $this->assertFalse((bool) $t->fresh()->requires_deployment);
            $this->assertFalse($this->getJson("/api/v1/tickets/{$t->id}")->json('data.capabilities.can_edit_estimate'));
            // ...and the ticket cannot be reassigned in these stages either.
            $this->postJson("/api/v1/tickets/{$t->id}/reassign", ['assigned_to_id' => $this->member->id, 'task_scope' => 'simple', 'task_points' => 30, 'requires_deployment' => true])->assertStatus(409);
        }

        // The assignee (a leader assigned to themselves) cannot flip it, in any editable status.
        $own = $this->dticket('IN_PROGRESS', ['assigned_to_id' => $this->leader->id], false);
        Sanctum::actingAs($this->leader);
        $this->patchJson("/api/v1/tickets/{$own->id}/work-estimate", ['task_scope' => 'simple', 'task_points' => 30, 'requires_deployment' => true])->assertForbidden()->assertJsonPath('code', 'OWN_WORK_ESTIMATE');

        // Smuggling: creation, complete-task and a non-manager's reassign never store it.
        Sanctum::actingAs($this->reporter);
        $res = $this->postJson('/api/v1/tickets', ['title' => 'جديدة', 'description' => 'وصف', 'ticket_category' => 'OLD_ISSUE', 'department_id' => $this->dept->id, 'created_by_name' => 'م', 'requires_deployment' => true])->assertStatus(201);
        $this->assertFalse((bool) Ticket::find($res->json('data.id'))->requires_deployment);
        $this->assertArrayNotHasKey('requires_deployment', $res->json('data'));

        $working = $this->dticket('IN_PROGRESS', [], false);
        $this->submit($working, null, ['resolution_type' => 'RESOLVED', 'resolution_summary' => 'تم الحل بالكامل', 'requires_deployment' => true])->assertOk();
        $this->assertFalse((bool) $working->fresh()->requires_deployment);

        $assigned = $this->dticket('ASSIGNED', [], false);
        $this->act($assigned, 'reassign', ['assigned_to_id' => $this->assignee->id, 'task_scope' => 'simple', 'task_points' => 30, 'requires_deployment' => true], $this->assignee)->assertForbidden();
        $this->act($assigned, 'reassign', ['assigned_to_id' => $this->assignee->id, 'task_scope' => 'simple', 'task_points' => 30, 'requires_deployment' => true], $this->member)->assertForbidden();
        $this->assertFalse((bool) $assigned->fresh()->requires_deployment);
    }

    public function test_an_old_ticket_in_review_keeps_the_classic_flow_until_returned_and_flagged(): void
    {
        $old = $this->dticket('PENDING_REVIEW', [], false);

        // It cannot be flipped to the deployment flow while in review; the classic approval works.
        Sanctum::actingAs($this->leader);
        $this->patchJson("/api/v1/tickets/{$old->id}/work-estimate", ['task_scope' => 'simple', 'task_points' => 30, 'requires_deployment' => true])->assertStatus(409);

        // The documented path: return it, set the flag while IN_PROGRESS, the assignee resubmits, the new flow applies.
        $this->act($old, 'return-for-rework', ['reason' => 'ننقلها إلى مسار النشر'])->assertOk();
        Sanctum::actingAs($this->leader);
        $this->patchJson("/api/v1/tickets/{$old->id}/work-estimate", ['task_scope' => 'simple', 'task_points' => 30, 'requires_deployment' => true])->assertOk();
        $this->submit($old)->assertOk();
        $this->act($old, 'approve-completion', ['actual_minutes' => 60])->assertStatus(409)->assertJsonPath('code', 'DEPLOYMENT_REQUIRED');
        $this->approveDeploy($old)->assertOk();
        $this->assertSame(0, $this->awards()->count());
    }

    // ═══ Visibility, folding, capabilities ═══════════════════════════════════

    public function test_deployment_data_is_for_managers_global_roles_and_the_assignee_only(): void
    {
        $t = $this->dticket();
        $this->submit($t)->assertOk();
        $this->approveDeploy($t)->assertOk();
        $this->deploy($t, ['live_url' => 'https://example.com/secret-page', 'note' => 'ملاحظة النشر الداخلية'])->assertOk();

        foreach ([$this->leader, $this->assignee, $this->admin] as $viewer) {
            Sanctum::actingAs($viewer);
            $show = $this->getJson("/api/v1/tickets/{$t->id}")->assertOk();
            $show->assertJsonPath('data.requires_deployment', true)
                ->assertJsonPath('data.deployment.live_url', 'https://example.com/secret-page')
                ->assertJsonPath('data.deployment.round', 1)
                ->assertJsonPath('data.workflow.stage', 'pending_verification')
                ->assertJsonPath('data.actual_minutes', 75)
                ->assertJsonPath('data.credit.status', 'approved');
            foreach (['review_approved_by_id', 'deployed_by_id', 'live_url', 'verified_by_id', 'review_round', 'submitted_by_id'] as $raw) {
                $this->assertArrayNotHasKey($raw, $show->json('data'), "raw {$raw} never leaves");
            }
            $row = collect($this->getJson('/api/v1/tickets')->json('data.data'))->firstWhere('id', $t->id);
            $this->assertTrue($row['requires_deployment']);
            $this->assertSame('pending_verification', $row['workflow']['stage']);
        }

        foreach ([$this->member, $this->reporter] as $viewer) {
            Sanctum::actingAs($viewer);
            $body = json_encode([$this->getJson("/api/v1/tickets/{$t->id}")->assertOk()->json(), $this->getJson('/api/v1/tickets')->assertOk()->json(), $this->getJson('/api/v1/tickets/board')->json()], JSON_UNESCAPED_UNICODE);
            foreach (['requires_deployment', 'secret-page', 'deployment', 'workflow', 'actual_minutes', 'APPROVED_FOR_DEPLOY"', 'DEPLOY_DETAILS', 'ملاحظة النشر الداخلية', 'VERIFIED_RESOLVED'] as $leak) {
                if ($viewer->is($this->member) && in_array($leak, ['APPROVED_FOR_DEPLOY"'], true)) {
                    continue; // a department member may see the real status name
                }
                $this->assertStringNotContainsString($leak, $body, $viewer->role.' must not see '.$leak);
            }
        }

        // The board card carries the flag and the workflow for staff.
        Sanctum::actingAs($this->leader);
        $card = collect(collect($this->getJson('/api/v1/tickets/board')->json('data.columns'))->firstWhere('status', 'PENDING_VERIFICATION')['tickets'])->firstWhere('id', $t->id);
        $this->assertTrue($card['requires_deployment']);
        $this->assertSame('verify_live', $card['workflow']['next_step_key']);
        $this->assertSame('approved', $card['credit']['status']);

        // The assignee's «مهامي» row carries them too, including the page to open.
        Sanctum::actingAs($this->assignee);
        $work = collect($this->getJson('/api/v1/tickets/my-work')->json('data'))->firstWhere('id', $t->id);
        $this->assertSame('PENDING_VERIFICATION', $work['status']);
        $this->assertTrue($work['requires_deployment']);
        $this->assertSame('pending_verification', $work['workflow']['stage']);
        $this->assertSame('approved', $work['credit']['status']);
        $this->assertSame('https://example.com/secret-page', $work['deployment']['live_url']);
    }

    public function test_a_reporter_sees_every_review_stage_as_in_progress_everywhere(): void
    {
        $review = $this->dticket('PENDING_REVIEW');
        $approved = $this->dticket('APPROVED_FOR_DEPLOY');
        $live = $this->dticket('PENDING_VERIFICATION');

        Sanctum::actingAs($this->reporter);
        $counts = $this->getJson('/api/v1/tickets/stats')->assertOk()->json('data.counts');
        $this->assertSame(3, $counts['in_progress']);
        foreach (['pending_review', 'approved_for_deploy', 'pending_verification'] as $k) {
            $this->assertSame(0, $counts[$k], $k);
        }

        $board = collect($this->getJson('/api/v1/tickets/board')->json('data.columns'))->keyBy('status');
        foreach (['PENDING_REVIEW', 'APPROVED_FOR_DEPLOY', 'PENDING_VERIFICATION'] as $s) {
            $this->assertFalse($board->has($s), "no {$s} column for a reporter");
        }
        $this->assertSame(3, $board['IN_PROGRESS']['count']);
        foreach ($board['IN_PROGRESS']['tickets'] as $card) {
            $this->assertSame('IN_PROGRESS', $card['status']);
        }

        $this->assertCount(3, $this->getJson('/api/v1/tickets?status=IN_PROGRESS')->json('data.data'));
        foreach (['PENDING_REVIEW', 'APPROVED_FOR_DEPLOY', 'PENDING_VERIFICATION'] as $s) {
            $this->assertSame([], $this->getJson("/api/v1/tickets?status={$s}")->assertOk()->json('data.data'), $s);
        }
        foreach ([$review, $approved, $live] as $t) {
            $this->getJson("/api/v1/tickets/{$t->id}")->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS')->assertJsonPath('data.viewer_role', 'reporter');
        }
        $by = collect($this->getJson('/api/v1/tickets/analytics?days=30')->json('data.by_status'))->keyBy('key');
        $this->assertSame(3, $by['IN_PROGRESS']['count']);
        $this->assertSame(0, $by['APPROVED_FOR_DEPLOY']['count']);
        $this->assertSame(0, $by['PENDING_VERIFICATION']['count']);

        // Staff keep the real statuses and counts.
        Sanctum::actingAs($this->leader);
        $c = $this->getJson('/api/v1/tickets/stats')->json('data.counts');
        $this->assertSame([1, 1, 1], [$c['pending_review'], $c['approved_for_deploy'], $c['pending_verification']]);
        $this->assertSame([$approved->id], collect($this->getJson('/api/v1/tickets?status=APPROVED_FOR_DEPLOY')->json('data.data'))->pluck('id')->all());
    }

    public function test_the_attention_filter_points_each_actor_at_their_turn(): void
    {
        $review = $this->dticket('PENDING_REVIEW');
        $approved = $this->dticket('APPROVED_FOR_DEPLOY');
        $live = $this->dticket('PENDING_VERIFICATION');
        $ids = fn (User $u) => collect((function () use ($u) { Sanctum::actingAs($u); return $this->getJson('/api/v1/tickets?attention=1')->json('data.data'); })())->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$review->id, $approved->id], $ids($this->leader), 'the reviewer / deployer');
        $this->assertSame([$live->id], $ids($this->assignee), 'the assignee: only the live verification');
        $this->assertSame([], $ids($this->member));
    }

    public function test_workflow_and_capabilities_mirror_the_endpoints_at_every_stage(): void
    {
        $viewers = ['leader' => $this->leader, 'assignee' => $this->assignee, 'admin' => $this->admin, 'member' => $this->member, 'reporter' => $this->reporter];
        $keys = ['approve_completion', 'approve_for_deploy', 'mark_deployed', 'verify_resolved', 'verify_failed', 'return_for_rework', 'complete_task'];
        // [status, deployment?] => who has which capability
        $expect = [
            'PENDING_REVIEW' => ['approve_for_deploy' => ['leader', 'admin'], 'return_for_rework' => ['leader', 'admin']],
            'APPROVED_FOR_DEPLOY' => ['mark_deployed' => ['leader', 'admin'], 'return_for_rework' => ['leader', 'admin']],
            'PENDING_VERIFICATION' => ['verify_resolved' => ['assignee'], 'verify_failed' => ['assignee']],
            'IN_PROGRESS' => ['complete_task' => ['assignee', 'admin']],
        ];
        foreach ($expect as $status => $granted) {
            $t = $this->dticket($status);
            foreach ($viewers as $name => $viewer) {
                Sanctum::actingAs($viewer);
                $caps = $this->getJson("/api/v1/tickets/{$t->id}")->assertOk()->json('data.capabilities');
                foreach ($keys as $key) {
                    $this->assertSame(in_array($name, $granted[$key] ?? [], true), (bool) $caps[$key], "{$status}: {$key} for {$name}");
                }
            }
        }

        // The classic ticket in review: approve_completion, never the deployment ones.
        $classic = $this->dticket('PENDING_REVIEW', [], false);
        Sanctum::actingAs($this->leader);
        $caps = $this->getJson("/api/v1/tickets/{$classic->id}")->json('data.capabilities');
        $this->assertTrue($caps['approve_completion']);
        $this->assertFalse($caps['approve_for_deploy']);
        $this->assertFalse($caps['mark_deployed']);

        // HR gate mirrored: a leader sees approve_for_deploy false on a medium ticket.
        $medium = $this->dticket('PENDING_REVIEW', ['task_scope' => 'medium', 'task_points' => 100]);
        $this->assertFalse($this->getJson("/api/v1/tickets/{$medium->id}")->json('data.capabilities.approve_for_deploy'));
        Sanctum::actingAs($this->admin);
        $this->assertTrue($this->getJson("/api/v1/tickets/{$medium->id}")->json('data.capabilities.approve_for_deploy'));

        // The workflow object for the other statuses.
        $this->assertSame(['stage' => 'resolved', 'next_actor' => null, 'next_step_key' => 'none'], app(TicketAccess::class)->workflow($this->dticket('RESOLVED')));
        $this->assertSame(['stage' => 'pending_review', 'next_actor' => 'reviewer', 'next_step_key' => 'review'], app(TicketAccess::class)->workflow($classic));
        $this->assertNull(app(TicketAccess::class)->workflow($this->dticket('UNRESOLVED'))['stage']);
    }

    public function test_the_live_verification_notification_goes_only_to_the_assignee(): void
    {
        $t = $this->dticket('APPROVED_FOR_DEPLOY');

        $this->deploy($t)->assertOk();

        $rows = DB::table('platform_notifications')->where('type', 'ticket_live_verification_required')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($this->assignee->id, (int) $rows->first()->user_id);
        $this->assertStringContainsString("/dashboard/tickets/{$t->id}", (string) $rows->first()->action_url);
        $this->assertStringNotContainsString('https://', (string) $rows->first()->message, 'the page itself is on the ticket, not in the notification');
        $this->assertSame(0, DB::table('platform_notifications')->whereIn('user_id', [$this->leader->id, $this->admin->id, $this->reporter->id])->where('type', 'ticket_live_verification_required')->count());
    }

    // ═══ Audit and untouched flows ══════════════════════════════════════════

    public function test_the_audit_flags_broken_deployment_tickets(): void
    {
        $noValues = $this->dticket('APPROVED_FOR_DEPLOY');                                   // no approved values at all
        $noDeploy = $this->dticket('PENDING_VERIFICATION');                                  // approved but no deploy fields
        $noDeploy->forceFill(['actual_minutes' => 75, 'review_approved_by_id' => $this->leader->id, 'review_approved_at' => now()])->save();
        $fine = $this->dticket('PENDING_VERIFICATION');
        $fine->forceFill(['actual_minutes' => 75, 'review_approved_by_id' => $this->leader->id, 'review_approved_at' => now(), 'deployed_by_id' => $this->admin->id, 'deployed_at' => now(), 'live_url' => 'https://example.com'])->save();
        $unverified = $this->dticket('RESOLVED', ['resolved_at' => now()]);
        $unverified->forceFill(['credited_at' => now(), 'credited_points' => 30, 'credited_hours' => 1])->save();
        $classic = $this->dticket('RESOLVED', ['resolved_at' => now()], false);
        $classic->forceFill(['credited_at' => now(), 'credited_points' => 30, 'credited_hours' => 1])->save();
        $before = [Ticket::count(), TicketCredit::count(), VolunteerPointAward::count()];

        $this->assertSame(0, Artisan::call('tickets:audit-work-links'));
        $report = [];
        foreach (explode("\n", Artisan::output()) as $line) {
            if (preg_match('/^\|\s*([a-z_]+)\s*\|.*\|\s*(\d+)\s*\|\s*(.*?)\s*\|$/u', rtrim($line), $m)) {
                $report[$m[1]] = $m[3];
            }
        }

        $flagged = array_map('trim', explode(',', $report['deployment_stage_without_approved_values']));
        $this->assertEqualsCanonicalizing([(string) $noValues->id, (string) $noDeploy->id], $flagged);
        $this->assertSame((string) $unverified->id, $report['credited_deployment_without_verification'], 'a classic credited ticket is not flagged');
        $this->assertSame($before, [Ticket::count(), TicketCredit::count(), VolunteerPointAward::count()], 'read-only');
    }

    public function test_regular_task_credit_is_untouched(): void
    {
        $volunteer = $this->user();
        $task = \App\Models\Task::create(['title' => 'مهمة', 'description' => 'x', 'task_scope' => 'simple', 'points' => 30, 'estimated_hours' => 2, 'assigned_to' => $volunteer->id, 'created_by' => $this->admin->id, 'status' => 'completed', 'priority' => 'medium']);

        TaskWorkflowService::creditVolunteerHoursAndPoints($task, $this->admin);

        $this->assertEqualsWithDelta(2.0, $this->hours($volunteer), 0.001);
        $this->assertSame(30, VolunteerPointAward::where('source_type', \App\Models\Task::class)->sole()->points);
    }


    // ═══ Round 2: own-work guard, flag lock, stuck tickets, summary, log visibility, live_url policy ═══

    public function test_the_current_assignee_cannot_change_the_flag_or_estimate_through_reassign_or_bulk(): void
    {
        // A leader who is the ticket's assignee tries to take it out of the deployment flow, one way or the other.
        $own = $this->dticket('IN_PROGRESS', ['assigned_to_id' => $this->leader->id]);
        $body = ['assigned_to_id' => $this->member->id, 'task_scope' => 'simple', 'task_points' => 30, 'requires_deployment' => false];

        $this->act($own, 'reassign', $body)->assertForbidden()->assertJsonPath('code', 'OWN_WORK_ESTIMATE');
        $this->act($own, 'reassign', ['assigned_to_id' => $this->member->id, 'task_scope' => 'simple', 'task_points' => 50])->assertForbidden()->assertJsonPath('code', 'OWN_WORK_ESTIMATE');
        Sanctum::actingAs($this->leader);
        $res = $this->postJson('/api/v1/tickets/bulk', ['action' => 'reassign', 'ticket_ids' => [$own->id], 'assigned_to_id' => $this->member->id, 'task_scope' => 'simple', 'task_points' => 30, 'requires_deployment' => false])->assertOk();
        $this->assertSame(1, $res->json('data.failed'));
        $fresh = $own->fresh();
        $this->assertTrue((bool) $fresh->requires_deployment);
        $this->assertSame($this->leader->id, (int) $fresh->assigned_to_id, 'nothing moved');
        $this->assertSame(30, $fresh->task_points);

        // The assignment itself still works when the estimate and the flag are left alone (repeated as they are).
        $this->act($own, 'reassign', ['assigned_to_id' => $this->member->id, 'task_scope' => 'simple', 'task_points' => 30, 'requires_deployment' => true])->assertOk();
        $this->assertSame($this->member->id, (int) $own->fresh()->assigned_to_id);

        // A manager NAMING THEMSELVES as the new assignee cannot resize or re-flag in the same call either...
        $other = $this->dticket('IN_PROGRESS');
        $this->act($other, 'reassign', ['assigned_to_id' => $this->leader->id, 'task_scope' => 'simple', 'task_points' => 45])->assertForbidden()->assertJsonPath('code', 'OWN_WORK_ESTIMATE');
        $this->act($other, 'reassign', ['assigned_to_id' => $this->leader->id, 'task_scope' => 'simple', 'task_points' => 30, 'requires_deployment' => false])->assertForbidden()->assertJsonPath('code', 'OWN_WORK_ESTIMATE');
        $this->assertSame(30, $other->fresh()->task_points);
        $this->assertTrue((bool) $other->fresh()->requires_deployment);
        // ...but may take it as it is.
        $this->act($other, 'reassign', ['assigned_to_id' => $this->leader->id, 'task_scope' => 'simple', 'task_points' => 30])->assertOk();

        // Another manager can change both for a ticket they do not hold (before it was ever submitted).
        $free = $this->dticket('IN_PROGRESS');
        $this->act($free, 'reassign', ['assigned_to_id' => $this->member->id, 'task_scope' => 'simple', 'task_points' => 45, 'requires_deployment' => false], $this->admin)->assertOk();
        $this->assertSame(45, $free->fresh()->task_points);
        $this->assertFalse((bool) $free->fresh()->requires_deployment);
    }

    public function test_the_flag_cannot_be_switched_off_once_the_ticket_was_submitted_for_review(): void
    {
        $t = $this->dticket();
        $this->submit($t)->assertOk();
        $this->approveDeploy($t)->assertOk();
        $this->deploy($t)->assertOk();
        $this->act($t, 'verify-failed', ['description' => 'لم ينجح على الموقع الحي'], $this->assignee)->assertOk();
        $this->assertSame(1, $t->fresh()->review_round);

        // The manager tries to take it out of the flow after the failed verification.
        Sanctum::actingAs($this->leader);
        $this->patchJson("/api/v1/tickets/{$t->id}/work-estimate", ['task_scope' => 'simple', 'task_points' => 35, 'requires_deployment' => false])
            ->assertStatus(409)->assertJsonPath('code', 'DEPLOYMENT_FLAG_LOCKED');
        $this->act($t, 'reassign', ['assigned_to_id' => $this->member->id, 'task_scope' => 'simple', 'task_points' => 30, 'requires_deployment' => false], $this->admin)->assertStatus(409)->assertJsonPath('code', 'DEPLOYMENT_FLAG_LOCKED');
        $fresh = $t->fresh();
        $this->assertTrue((bool) $fresh->requires_deployment);
        $this->assertSame(30, $fresh->task_points, 'the refused request rolled back the estimate edit too');
        $this->assertSame($this->assignee->id, (int) $fresh->assigned_to_id);

        // Switching ON stays possible, and OFF is fine while the ticket has never been submitted.
        $classic = $this->dticket('IN_PROGRESS', ['review_round' => 1], false);
        Sanctum::actingAs($this->leader);
        $this->patchJson("/api/v1/tickets/{$classic->id}/work-estimate", ['task_scope' => 'simple', 'task_points' => 30, 'requires_deployment' => true])->assertOk();
        $fresh = $this->dticket('IN_PROGRESS');
        $this->patchJson("/api/v1/tickets/{$fresh->id}/work-estimate", ['task_scope' => 'simple', 'task_points' => 30, 'requires_deployment' => false])->assertOk();
        $this->assertFalse((bool) $fresh->fresh()->requires_deployment);
    }

    public function test_a_manager_frees_a_ticket_whose_assignee_can_no_longer_verify_it(): void
    {
        $makeLive = function (User $assignee): Ticket {
            $t = $this->dticket('PENDING_VERIFICATION', ['assigned_to_id' => $assignee->id]);
            $t->forceFill(['actual_minutes' => 75, 'review_approved_by_id' => $this->leader->id, 'review_approved_at' => now(), 'deployed_by_id' => $this->admin->id, 'deployed_at' => now(), 'live_url' => 'https://example.com/x', 'review_round' => 1])->save();

            return $t;
        };
        $reason = ['reason' => 'المكلف لم يعد متاحًا، نعيدها لإعادة الإسناد'];
        $state = $this->state();

        // An available assignee: only they send it back.
        $ok = $makeLive($this->assignee);
        $this->act($ok, 'return-for-rework', $reason)->assertStatus(409)->assertJsonPath('code', 'INVALID_STAGE');
        Sanctum::actingAs($this->leader);
        $this->assertFalse($this->getJson("/api/v1/tickets/{$ok->id}")->json('data.capabilities.return_for_rework'));

        // Inactive, deleted, withdrawn, on leave.
        $inactive = $this->deptMember();
        $inactive->update(['is_active' => false]);
        $deleted = $this->deptMember();
        $withdrawn = $this->deptMember();
        VolunteerProfile::create(['user_id' => $withdrawn->id, 'status' => VolunteerProfile::STATUS_WITHDRAWN]);
        $onLeave = $this->deptMember();
        VolunteerProfile::create(['user_id' => $onLeave->id, 'status' => VolunteerProfile::STATUS_ON_LEAVE]);

        foreach (['inactive' => $inactive, 'deleted' => $deleted, 'withdrawn' => $withdrawn, 'on leave' => $onLeave] as $name => $who) {
            $t = $makeLive($who);
            if ($name === 'deleted') {
                $who->delete();
            }
            Sanctum::actingAs($this->leader);
            $this->assertTrue($this->getJson("/api/v1/tickets/{$t->id}")->json('data.capabilities.return_for_rework'), $name);
            $this->assertFalse($this->getJson("/api/v1/tickets/{$t->id}")->json('data.capabilities.verify_resolved'));

            // Not the plain member, not the reporter; the reason is still required.
            $this->act($t, 'return-for-rework', $reason, $this->member)->assertForbidden();
            $this->act($t, 'return-for-rework', $reason, $this->reporter)->assertForbidden();
            $this->act($t, 'return-for-rework', [])->assertStatus(422);

            $this->act($t, 'return-for-rework', $reason)->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS')->assertJsonPath('data.actual_minutes', null);
            $fresh = $t->fresh();
            foreach (['actual_minutes', 'review_approved_at', 'deployed_at', 'live_url', 'verified_at'] as $f) {
                $this->assertNull($fresh->{$f}, "{$name}: {$f}");
            }
            $this->assertNull($fresh->credited_at);
            $this->assertSame('PENDING_VERIFICATION', TicketActivityLog::where('ticket_id', $t->id)->where('action', 'RETURNED_FOR_REWORK')->value('previous_state'));
        }

        $this->assertSame($state, $this->state(), 'freeing a stuck ticket never credits');
    }

    public function test_approving_for_deployment_is_refused_while_the_assignee_cannot_act(): void
    {
        $gone = $this->deptMember();
        $t = $this->dticket('PENDING_REVIEW', ['assigned_to_id' => $gone->id]);
        $gone->update(['is_active' => false]);

        $this->approveDeploy($t)->assertStatus(409)->assertJsonPath('code', 'ASSIGNEE_UNAVAILABLE');
        $this->assertSame('PENDING_REVIEW', $t->fresh()->status);
        $this->assertNull($t->fresh()->actual_minutes);
        Sanctum::actingAs($this->leader);
        $this->assertFalse($this->getJson("/api/v1/tickets/{$t->id}")->json('data.capabilities.approve_for_deploy'));
        // It can still be returned for rework and reassigned.
        $this->act($t, 'return-for-rework', ['reason' => 'المكلف غير متاح'])->assertOk();
        $this->act($t, 'reassign', ['assigned_to_id' => $this->assignee->id, 'task_scope' => 'simple', 'task_points' => 30])->assertOk();
    }

    public function test_the_audit_flags_deployment_tickets_stuck_with_an_unavailable_assignee(): void
    {
        $gone = $this->deptMember();
        $stuck = $this->dticket('PENDING_VERIFICATION', ['assigned_to_id' => $gone->id]);
        $stuck->forceFill(['actual_minutes' => 75, 'review_approved_by_id' => $this->leader->id, 'review_approved_at' => now(), 'deployed_by_id' => $this->admin->id, 'deployed_at' => now(), 'live_url' => 'https://example.com'])->save();
        $stuck2 = $this->dticket('APPROVED_FOR_DEPLOY', ['assigned_to_id' => $gone->id]);
        $stuck2->forceFill(['actual_minutes' => 75, 'review_approved_by_id' => $this->leader->id, 'review_approved_at' => now()])->save();
        $fine = $this->dticket('PENDING_VERIFICATION');
        $fine->forceFill(['actual_minutes' => 75, 'review_approved_by_id' => $this->leader->id, 'review_approved_at' => now(), 'deployed_by_id' => $this->admin->id, 'deployed_at' => now(), 'live_url' => 'https://example.com'])->save();
        $gone->update(['is_active' => false]);

        $this->assertSame(0, Artisan::call('tickets:audit-work-links'));
        $report = [];
        foreach (explode("\n", Artisan::output()) as $line) {
            if (preg_match('/^\|\s*([a-z_]+)\s*\|.*\|\s*(\d+)\s*\|\s*(.*?)\s*\|$/u', rtrim($line), $m)) {
                $report[$m[1]] = $m[3];
            }
        }
        $this->assertEqualsCanonicalizing([(string) $stuck->id, (string) $stuck2->id], array_map('trim', explode(',', $report['deployment_assignee_unavailable'])));
    }

    // ═══ What the reporter and plain members can read ═══════════════════════

    public function test_no_placeholder_summary_is_stored_and_the_reporter_never_gets_a_resolution_note_for_a_deployment_ticket(): void
    {
        $t = $this->dticket();
        $this->submit($t)->assertOk();
        $this->assertNull($t->fresh()->resolution_summary);
        $this->approveDeploy($t)->assertOk();
        $this->deploy($t)->assertOk();
        $this->verify($t)->assertOk();

        Sanctum::actingAs($this->reporter);
        $show = $this->getJson("/api/v1/tickets/{$t->id}")->assertOk();
        $show->assertJsonPath('data.status', 'RESOLVED')->assertJsonPath('data.resolution_note', null);
        $this->assertStringNotContainsString('أنهيتُ التنفيذ', json_encode($show->json(), JSON_UNESCAPED_UNICODE));
        Sanctum::actingAs($this->leader);
        $this->assertNull($this->getJson("/api/v1/tickets/{$t->id}")->json('data.resolution_summary'), 'staff see nothing misleading either');

        // Even a summary the assignee chose to write stays internal for a deployment ticket (the reporter is told it is resolved).
        $typed = $this->dticket();
        $this->submit($typed, null, ['resolution_type' => 'RESOLVED', 'resolution_summary' => 'تفاصيل تقنية داخلية للفريق'])->assertOk();
        $this->approveDeploy($typed)->assertOk();
        $this->deploy($typed)->assertOk();
        $this->verify($typed)->assertOk();
        Sanctum::actingAs($this->reporter);
        $body = json_encode($this->getJson("/api/v1/tickets/{$typed->id}")->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('تفاصيل تقنية داخلية', $body);
        $this->assertNull($this->getJson("/api/v1/tickets/{$typed->id}")->json('data.resolution_note'));

        // An ordinary ticket still shows its resolution note to the reporter.
        $plain = $this->dticket('IN_PROGRESS', [], false);
        $this->submit($plain, null, ['resolution_type' => 'RESOLVED', 'resolution_summary' => 'تم إصلاح الإعدادات'])->assertOk();
        $this->act($plain, 'approve-completion', ['actual_minutes' => 60])->assertOk();
        Sanctum::actingAs($this->reporter);
        $this->getJson("/api/v1/tickets/{$plain->id}")->assertJsonPath('data.resolution_note', 'تم إصلاح الإعدادات');
    }

    public function test_the_deployed_live_row_is_internal(): void
    {
        $t = $this->dticket('APPROVED_FOR_DEPLOY');
        $this->deploy($t)->assertOk();
        $readOnly = $this->user('quality_manager'); // may view every department, but not its internal notes

        foreach ([$this->leader, $this->assignee, $this->admin] as $viewer) {
            Sanctum::actingAs($viewer);
            $this->assertContains('DEPLOYED_LIVE', array_column($this->getJson("/api/v1/tickets/{$t->id}")->json('data.activity_logs'), 'action'));
        }
        foreach ([$this->member, $readOnly] as $viewer) {
            Sanctum::actingAs($viewer);
            $this->assertNotContains('DEPLOYED_LIVE', array_column($this->getJson("/api/v1/tickets/{$t->id}")->assertOk()->json('data.activity_logs'), 'action'), $viewer->role);
        }
        Sanctum::actingAs($this->reporter);
        $this->assertStringNotContainsString('DEPLOYED_LIVE', json_encode($this->getJson("/api/v1/tickets/{$t->id}")->json()));
    }

    // ═══ live_url policy ════════════════════════════════════════════════════

    public function test_the_live_url_must_be_a_public_https_page(): void
    {
        $t = $this->dticket('APPROVED_FOR_DEPLOY');
        $bad = [
            'http://example.com/x', 'ftp://example.com/x', 'https://user:pass@example.com/x', 'https://user@example.com/x', 'https://example.com@evil.test/x',
            'https://127.0.0.1/x', 'https://192.168.1.10/x', 'https://[::1]/x', 'https://2130706433/x', 'https://localhost/x', 'https://localhost:8443/x',
            'https://intranet/x', 'https://printer.local/x', 'https://app.internal/x', 'https://'.str_repeat('a', 490).'.example.com/x', 'javascript:alert(1)',
        ];
        foreach ($bad as $url) {
            $this->deploy($t, ['live_url' => $url])->assertStatus(422)->assertJsonStructure(['errors' => ['live_url']]);
        }
        $this->assertSame('APPROVED_FOR_DEPLOY', $t->fresh()->status);

        $this->deploy($t, ['live_url' => 'https://www.example.com:8443/pay?x=1#top'])->assertOk()->assertJsonPath('data.deployment.live_url', 'https://www.example.com:8443/pay?x=1#top');
    }

    public function test_the_configured_live_hosts_restrict_the_url(): void
    {
        config(['tickets.live_hosts' => ['example.com']]);

        foreach (['https://example.com/a', 'https://www.example.com/a', 'https://a.b.example.com/a', 'https://EXAMPLE.com/a'] as $url) {
            $t = $this->dticket('APPROVED_FOR_DEPLOY');
            $this->deploy($t, ['live_url' => $url])->assertOk();
        }
        foreach (['https://example.org/a', 'https://evil-example.com/a', 'https://example.com.evil.test/a', 'https://notexample.com/a'] as $url) {
            $t = $this->dticket('APPROVED_FOR_DEPLOY');
            $this->deploy($t, ['live_url' => $url])->assertStatus(422)->assertJsonPath('errors.live_url.0', 'رابط الموقع الحي ليس ضمن النطاقات المسموح بها لهذه المنصة.');
            $this->assertSame('APPROVED_FOR_DEPLOY', $t->fresh()->status);
        }

        // Empty (the default) means any public https host.
        config(['tickets.live_hosts' => []]);
        $this->deploy($this->dticket('APPROVED_FOR_DEPLOY'), ['live_url' => 'https://example.org/a'])->assertOk();
    }
}
