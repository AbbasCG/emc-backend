<?php

namespace Tests\Feature;

use App\Exceptions\TicketActionException;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketActivityLog;
use App\Models\TicketCredit;
use App\Models\User;
use App\Models\VolunteerPointAward;
use App\Models\VolunteerProfile;
use App\Services\TicketDeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\GrantsTicketReviewPermissions;
use Tests\TestCase;

/**
 * THE ONE-CLICK «تأكيد المراجعة» (POST /api/v1/tickets/{id}/confirm-review).
 *
 * Size, points and the approximate duration are set AT ASSIGNMENT; the ASSIGNEE records the ACTUAL duration when sending the work
 * to review; during review all four are READ-ONLY. The confirmation is a stage transition only: a classic ticket is closed and
 * credited once from the stored values, a deployment ticket moves to APPROVED_FOR_DEPLOY (nothing credited). Missing/invalid stored
 * data is a 422 REVIEW_DATA_INCOMPLETE (return for rework to complete it); any attempt to send or edit a value is REVIEW_VALUES_LOCKED.
 */
class TicketConfirmReviewTest extends TestCase
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
        // The reviewer holds ONLY tickets.review_completion (never the participation permission) and leads the department.
        $this->leader = $this->grantTicketReview($this->user('programs_manager'));
        $this->dept->update(['leader_id' => $this->leader->id]);
        $this->assignee = $this->deptMember();
        $this->member = $this->deptMember();
        $this->reporter = $this->user('student');
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

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

    /** A ticket waiting for review: simple, 30 points, 2 h estimated, 75 minutes recorded by the assignee. */
    private function ticket(array $over = [], bool $deployment = false): Ticket
    {
        $actual = array_key_exists('actual_minutes', $over) ? $over['actual_minutes'] : 75;
        $submittedBy = $over['submitted_by_id'] ?? null;
        unset($over['actual_minutes'], $over['submitted_by_id']);

        $t = Ticket::create(array_merge([
            'ticket_number' => 'EMC-TCK-V-'.Str::random(8), 'title' => 'تعديل صفحة الدفع', 'description' => 'وصف', 'ticket_category' => 'OLD_ISSUE', 'priority' => 'MEDIUM',
            'department_id' => $this->dept->id, 'created_by_id' => $this->reporter->id, 'created_by_name' => 'مُبلِّغ', 'status' => 'PENDING_REVIEW',
            'assigned_to_id' => $this->assignee->id, 'approved_by_id' => $this->leader->id, 'accepted_at' => now()->subHour(),
            'task_scope' => 'simple', 'task_points' => 30, 'estimated_hours' => 2, 'resolution_summary' => 'تم الحل',
        ], $over));
        $t->forceFill(['actual_minutes' => $actual, 'requires_deployment' => $deployment]);
        if ($submittedBy !== null) {
            $t->forceFill(['submitted_by_id' => $submittedBy, 'submitted_at' => now()]);
        }
        $t->save();

        return $t;
    }

    private function confirm(Ticket $t, ?User $as = null, array $body = [])
    {
        Sanctum::actingAs($as ?? $this->leader);

        return $this->postJson("/api/v1/tickets/{$t->id}/confirm-review", $body);
    }

    private function act(Ticket $t, string $endpoint, User $as, array $body = [])
    {
        Sanctum::actingAs($as);

        return $this->postJson("/api/v1/tickets/{$t->id}/{$endpoint}", $body);
    }

    private function show(Ticket $t, User $as)
    {
        Sanctum::actingAs($as);

        return $this->getJson("/api/v1/tickets/{$t->id}");
    }

    private function state(): array
    {
        return [VolunteerPointAward::count(), (float) VolunteerProfile::sum('hours_logged'), TicketCredit::count(), TicketActivityLog::count()];
    }

    private function hours(User $u): float
    {
        return (float) (VolunteerProfile::where('user_id', $u->id)->value('hours_logged') ?? 0);
    }

    // ═══ The transition ═════════════════════════════════════════════════════

    public function test_a_reviewer_holding_only_the_review_permission_confirms_a_classic_ticket_it_did_not_create(): void
    {
        $t = $this->ticket();
        $this->assertSame(0, VolunteerPointAward::count());

        $this->confirm($t, null, ['note' => 'عمل ممتاز'])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'RESOLVED')
            ->assertJsonPath('data.credit.status', 'credited')
            ->assertJsonPath('data.credit.points', 30)
            ->assertJsonPath('data.credit.minutes', 75)
            ->assertJsonPath('data.credit.hours', 1.25)
            ->assertJsonPath('data.task_scope', 'simple')
            ->assertJsonPath('data.task_points', 30)
            ->assertJsonPath('data.actual_minutes', 75);

        $fresh = $t->fresh();
        $this->assertSame('simple', $fresh->task_scope);
        $this->assertSame(30, $fresh->task_points);
        $this->assertEqualsWithDelta(2.0, (float) $fresh->estimated_hours, 0.0001, 'the values are exactly the stored ones');
        $this->assertSame(75, $fresh->actual_minutes);
        $this->assertSame(1, VolunteerPointAward::where('source_type', Ticket::class)->where('source_id', $t->id)->count());
        $this->assertEqualsWithDelta(1.25, $this->hours($this->assignee), 0.0001);
        $this->assertSame($this->leader->id, (int) $fresh->credited_by_id);

        // The log says «تأكيد المراجعة», keeps the existing action constant and the reviewer relation, and stores the note.
        $row = TicketActivityLog::where('ticket_id', $t->id)->where('action', 'APPROVED_COMPLETION')->sole();
        $this->assertStringContainsString('تأكيد المراجعة', $row->details);
        $this->assertSame($this->leader->id, (int) $row->performed_by);
        $this->assertSame([], $row->makeVisible('metadata')->metadata['reviewer_relation']);
        $this->assertStringContainsString('عمل ممتاز', (string) TicketActivityLog::where('ticket_id', $t->id)->where('action', 'CREDIT_ADDED')->value('details'));
    }

    public function test_a_deployment_ticket_moves_to_approved_for_deploy_without_credit_and_still_needs_deploy_and_live_verification(): void
    {
        $t = $this->ticket([], deployment: true);
        $before = [VolunteerPointAward::count(), TicketCredit::count()];

        $this->confirm($t, null, ['note' => 'جاهز للنشر'])->assertOk()
            ->assertJsonPath('data.status', 'APPROVED_FOR_DEPLOY')
            ->assertJsonPath('data.credit.status', 'approved')
            ->assertJsonPath('data.credit.credited_at', null)
            ->assertJsonPath('data.actual_minutes', 75)
            ->assertJsonPath('data.deployment.review_approved_by_name', $this->leader->name);

        $fresh = $t->fresh();
        $this->assertSame($before, [VolunteerPointAward::count(), TicketCredit::count()], 'nothing credited');
        $this->assertNull($fresh->credited_at);
        $this->assertSame(75, $fresh->actual_minutes);
        $this->assertSame($this->leader->id, (int) $fresh->review_approved_by_id);
        $this->assertNotNull($fresh->review_approved_at);
        $this->assertSame(1, TicketActivityLog::where('ticket_id', $t->id)->where('action', 'APPROVED_FOR_DEPLOY')->count());

        // The rest of the cycle is unchanged: no verification before the deployment, and the credit only at the live verification.
        $this->act($t, 'verify-resolved', $this->assignee, ['note' => 'تحققت من الموقع'])->assertStatus(409)->assertJsonPath('code', 'INVALID_STAGE');
        $admin = $this->grantTicketReview($this->user('admin'));
        $this->act($t, 'mark-deployed', $admin, ['live_url' => 'https://example.com/live'])->assertOk();
        $this->assertSame($before, [VolunteerPointAward::count(), TicketCredit::count()], 'deploying credits nothing');
        $this->act($t, 'verify-resolved', $this->assignee, ['note' => 'تحققت من الموقع'])->assertOk()->assertJsonPath('data.credit.points', 30)->assertJsonPath('data.credit.minutes', 75);
        $this->assertSame(1, VolunteerPointAward::where('source_id', $t->id)->count());
    }

    public function test_a_replay_changes_nothing_sequentially_and_through_the_service(): void
    {
        $classic = $this->ticket();
        $this->confirm($classic)->assertOk();
        $state = $this->state();
        $this->confirm($classic)->assertStatus(409)->assertJsonPath('code', 'ALREADY_CREDITED');
        $this->confirm($classic, $this->grantTicketReview($this->user('admin')))->assertStatus(409)->assertJsonPath('code', 'ALREADY_CREDITED');
        $this->assertSame($state, $this->state());
        $this->assertSame(1, VolunteerPointAward::where('source_id', $classic->id)->count());

        // A second call that read the ticket before the first one finished (service level): refused, nothing doubled.
        $second = $this->ticket();
        $service = app(TicketDeploymentService::class);
        $service->confirmReview($second->id, $this->leader);
        $afterFirst = $this->state();
        try {
            $service->confirmReview($second->id, $this->leader);
            $this->fail('the second confirmation must be refused');
        } catch (TicketActionException $e) {
            $this->assertSame(409, $e->status);
            $this->assertSame('ALREADY_CREDITED', $e->errorCode);
        }
        $this->assertSame($afterFirst, $this->state());

        // Deployment ticket: the second call finds it past the review stage.
        $deploy = $this->ticket([], deployment: true);
        $this->confirm($deploy)->assertOk()->assertJsonPath('data.status', 'APPROVED_FOR_DEPLOY');
        $state = $this->state();
        $this->confirm($deploy)->assertStatus(409)->assertJsonPath('code', 'INVALID_STAGE');
        $this->assertSame($state, $this->state());
        $this->assertSame(1, TicketActivityLog::where('ticket_id', $deploy->id)->where('action', 'APPROVED_FOR_DEPLOY')->count());
    }

    public function test_the_stage_is_checked(): void
    {
        foreach (['IN_PROGRESS', 'ASSIGNED', 'PENDING_APPROVAL'] as $status) {
            $t = $this->ticket(['status' => $status]);
            $this->confirm($t)->assertStatus(409)->assertJsonPath('code', 'NOT_PENDING_REVIEW');
            $this->assertSame($status, $t->fresh()->status);
        }
        $d = $this->ticket(['status' => 'IN_PROGRESS'], deployment: true);
        $this->confirm($d)->assertStatus(409)->assertJsonPath('code', 'INVALID_STAGE');
        $this->assertSame(0, VolunteerPointAward::count());
    }

    // ═══ Incomplete data ═══════════════════════════════════════════════════

    public function test_incomplete_stored_data_is_refused_with_the_list_and_completed_through_rework(): void
    {
        $t = $this->ticket(['estimated_hours' => null, 'actual_minutes' => null]);
        $state = $this->state();

        $res = $this->confirm($t)->assertStatus(422)->assertJsonPath('code', 'REVIEW_DATA_INCOMPLETE');
        $this->assertStringContainsString('المدة التقريبية', $res->json('message'));
        $this->assertStringContainsString('المدة الفعلية', $res->json('message'));
        $this->assertEqualsCanonicalizing(['estimated_hours', 'actual_minutes'], array_keys($res->json('errors')));
        $this->assertSame($state, $this->state());
        $this->assertSame('PENDING_REVIEW', $t->fresh()->status);

        // The reviewer sees the blocker and a false capability.
        $detail = $this->show($t, $this->leader)->assertOk()->json('data');
        $this->assertFalse($detail['capabilities']['confirm_review']);
        $this->assertFalse($detail['capabilities']['approve_completion']);
        $this->assertTrue($detail['capabilities']['return_for_rework']);
        $this->assertSame('REVIEW_DATA_INCOMPLETE', $detail['review_blockers'][0]['code']);
        $this->assertSame(['estimated_hours', 'actual_minutes'], $detail['review_blockers'][0]['missing']);
        $this->assertNotEmpty($detail['review_blockers'][0]['message_ar']);

        // Out-of-range stored points are missing too (e.g. points outside the size's range).
        $bad = $this->ticket(['task_points' => 500]);
        $this->confirm($bad)->assertStatus(422)->assertJsonPath('code', 'REVIEW_DATA_INCOMPLETE')->assertJsonStructure(['errors' => ['task_points']]);

        // Rework -> the manager completes the estimate (IN_PROGRESS) -> the assignee resubmits with the actual duration -> confirm.
        $this->act($t, 'return-for-rework', $this->leader, ['reason' => 'ينقص تقدير المدة'])->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');
        Sanctum::actingAs($this->leader);
        $this->patchJson("/api/v1/tickets/{$t->id}/work-estimate", ['task_scope' => 'simple', 'task_points' => 30, 'estimated_hours' => 1.5])->assertOk();
        $this->act($t, 'complete-task', $this->assignee, ['resolution_type' => 'RESOLVED', 'resolution_summary' => 'تم استكمال العمل', 'actual_minutes' => 90])->assertOk()->assertJsonPath('data.status', 'PENDING_REVIEW');
        $this->assertSame(90, $t->fresh()->actual_minutes);

        $this->confirm($t)->assertOk()->assertJsonPath('data.status', 'RESOLVED')->assertJsonPath('data.credit.minutes', 90);
        $this->assertEqualsWithDelta(1.5, $this->hours($this->assignee), 0.0001);
        $this->assertSame(1, VolunteerPointAward::where('source_id', $t->id)->count());
    }

    // ═══ Values are read-only during review ═════════════════════════════════

    public function test_a_body_carrying_any_value_field_is_refused_on_every_review_endpoint(): void
    {
        $classic = $this->ticket();
        $deploy = $this->ticket([], deployment: true);
        $state = $this->state();

        foreach (['task_scope' => 'medium', 'task_points' => 45, 'estimated_hours' => 3, 'actual_minutes' => 10] as $field => $value) {
            $this->confirm($classic, null, [$field => $value])->assertStatus(422)->assertJsonPath('code', 'REVIEW_VALUES_LOCKED')->assertJsonStructure(['message', 'errors' => [$field]]);
            $this->confirm($deploy, null, [$field => $value])->assertStatus(422)->assertJsonPath('code', 'REVIEW_VALUES_LOCKED');
            $this->act($classic, 'approve-completion', $this->leader, [$field => $value])->assertStatus(422)->assertJsonPath('code', 'REVIEW_VALUES_LOCKED');
            $this->act($deploy, 'approve-for-deploy', $this->leader, [$field => $value])->assertStatus(422)->assertJsonPath('code', 'REVIEW_VALUES_LOCKED');
        }
        // Even an unchanged or null value is an attempt.
        $this->confirm($classic, null, ['task_points' => 30])->assertStatus(422)->assertJsonPath('code', 'REVIEW_VALUES_LOCKED');
        $this->confirm($classic, null, ['actual_minutes' => null])->assertStatus(422)->assertJsonPath('code', 'REVIEW_VALUES_LOCKED');

        $this->assertSame($state, $this->state());
        foreach ([$classic, $deploy] as $x) {
            $this->assertSame('PENDING_REVIEW', $x->fresh()->status);
            $this->assertSame(30, $x->fresh()->task_points);
            $this->assertSame(75, $x->fresh()->actual_minutes);
        }
        // The note alone is fine, and an invalid note is a plain 422.
        $this->confirm($classic, null, ['note' => str_repeat('x', 1001)])->assertStatus(422)->assertJsonStructure(['errors' => ['note']]);
        $this->confirm($classic, null, ['note' => 'ممتاز'])->assertOk();
    }

    public function test_every_writer_of_the_four_values_is_locked_while_the_work_is_under_review(): void
    {
        $admin = $this->grantTicketReview($this->user('admin'));
        $admin2 = $this->user('super_admin');
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach (['PENDING_REVIEW', 'APPROVED_FOR_DEPLOY', 'PENDING_VERIFICATION'] as $status) {
            $t = $this->ticket(['status' => $status], deployment: true);

            // PATCH work-estimate (manager and global role), reassign, bulk reassign.
            foreach ([$this->leader, $admin, $admin2] as $who) {
                Sanctum::actingAs($who);
                $this->patchJson("/api/v1/tickets/{$t->id}/work-estimate", ['task_scope' => 'medium', 'task_points' => 100, 'estimated_hours' => 4])
                    ->assertStatus(409)->assertJsonPath('code', 'REVIEW_VALUES_LOCKED');
                $this->postJson("/api/v1/tickets/{$t->id}/reassign", ['assigned_to_id' => $this->member->id, 'task_scope' => 'medium', 'task_points' => 100, 'estimated_hours' => 4])
                    ->assertStatus(409)->assertJsonPath('code', 'REVIEW_VALUES_LOCKED');
                $this->postJson("/api/v1/tickets/{$t->id}/reassign", ['assigned_to_id' => $this->member->id, 'estimated_hours' => 4])
                    ->assertStatus(409)->assertJsonPath('code', 'REVIEW_VALUES_LOCKED');
                $bulk = $this->postJson('/api/v1/tickets/bulk', ['action' => 'reassign', 'ticket_ids' => [$t->id], 'assigned_to_id' => $this->member->id, 'task_scope' => 'medium', 'task_points' => 100, 'estimated_hours' => 4])->json('data');
                $this->assertSame(1, $bulk['failed']);
                $this->assertFalse($bulk['results'][0]['ok']);
            }
            // The deployment flag is a related value: frozen too.
            Sanctum::actingAs($this->leader);
            $this->patchJson("/api/v1/tickets/{$t->id}/work-estimate", ['task_scope' => 'simple', 'task_points' => 30, 'requires_deployment' => false])->assertStatus(409)->assertJsonPath('code', 'REVIEW_VALUES_LOCKED');

            // The assignee never edits it.
            Sanctum::actingAs($this->assignee);
            $this->patchJson("/api/v1/tickets/{$t->id}/work-estimate", ['task_scope' => 'simple', 'task_points' => 30, 'estimated_hours' => 9])->assertForbidden();
            // approve is a PENDING_APPROVAL-only action.
            Sanctum::actingAs($this->leader);
            $this->postJson("/api/v1/tickets/{$t->id}/approve", ['sla_hours' => 24, 'assigned_to_id' => $this->member->id, 'task_scope' => 'medium', 'task_points' => 100, 'estimated_hours' => 4])->assertStatus(409);

            $fresh = $t->fresh();
            $this->assertSame($status, $fresh->status);
            $this->assertSame($this->assignee->id, (int) $fresh->assigned_to_id);
            $this->assertSame('simple', $fresh->task_scope);
            $this->assertSame(30, $fresh->task_points);
            $this->assertEqualsWithDelta(2.0, (float) $fresh->estimated_hours, 0.0001);
            $this->assertSame(75, $fresh->actual_minutes);
            $this->assertTrue((bool) $fresh->requires_deployment);
        }

        // Returning for rework unlocks (and clears the approved actual duration as before).
        $t = $this->ticket();
        $this->act($t, 'return-for-rework', $this->leader, ['reason' => 'يلزم تعديل التقدير'])->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS')->assertJsonPath('data.actual_minutes', null);
        Sanctum::actingAs($this->leader);
        $this->patchJson("/api/v1/tickets/{$t->id}/work-estimate", ['task_scope' => 'medium', 'task_points' => 100, 'estimated_hours' => 4])->assertOk()->assertJsonPath('data.task_points', 100);
    }

    // ═══ Gates ══════════════════════════════════════════════════════════════

    public function test_the_hr_gate_applies_to_work_above_simple_and_is_reported_as_a_blocker(): void
    {
        $t = $this->ticket(['task_scope' => 'medium', 'task_points' => 100]);
        $state = $this->state();

        $this->confirm($t)->assertForbidden()->assertJsonPath('code', 'HR_APPROVAL_REQUIRED');
        $this->assertSame($state, $this->state());

        $detail = $this->show($t, $this->leader)->assertOk()->json('data');
        $this->assertFalse($detail['capabilities']['confirm_review']);
        $this->assertSame(['HR_APPROVAL_REQUIRED'], array_column($detail['review_blockers'], 'code'));
        $this->assertNotEmpty($detail['review_blockers'][0]['message_ar']);

        $admin = $this->grantTicketReview($this->user('admin'));
        $this->assertTrue($this->show($t, $admin)->json('data.capabilities.confirm_review'));
        $this->assertSame([], $this->show($t, $admin)->json('data.review_blockers'));
        $this->confirm($t, $admin)->assertOk()->assertJsonPath('data.credit.points', 100);

        // A simple ticket needs no HR approver.
        $this->assertTrue($this->show($this->ticket(), $this->leader)->json('data.capabilities.confirm_review'));
    }

    public function test_the_creator_assignee_and_submitter_need_the_participation_permission(): void
    {
        // Creator.
        $byLeader = $this->ticket(['created_by_id' => $this->leader->id]);
        $this->confirm($byLeader)->assertForbidden()->assertJsonPath('code', 'PARTICIPATION_PERMISSION_REQUIRED');
        // Assignee.
        $own = $this->ticket(['assigned_to_id' => $this->leader->id]);
        $this->confirm($own)->assertForbidden()->assertJsonPath('code', 'PARTICIPATION_PERMISSION_REQUIRED');
        // Submitter (a global role submitted it for the assignee).
        $admin = $this->grantTicketReview($this->user('admin'));
        $submitted = $this->ticket(['submitted_by_id' => $admin->id]);
        $this->confirm($submitted, $admin)->assertForbidden()->assertJsonPath('code', 'PARTICIPATION_PERMISSION_REQUIRED');
        $this->assertSame(0, VolunteerPointAward::count());
        $this->assertFalse($this->show($own, $this->leader)->json('data.capabilities.confirm_review'));
        $this->assertSame([], $this->show($own, $this->leader)->json('data.review_blockers'), 'a viewer who cannot review has nothing to unblock');

        // With the explicit grant they can, and the relation is recorded.
        $this->grantTicketReview($this->leader, participated: true);
        $this->assertTrue($this->show($byLeader, $this->leader)->json('data.capabilities.confirm_review'));
        $this->confirm($byLeader)->assertOk();
        $this->assertSame(['creator'], TicketActivityLog::where('ticket_id', $byLeader->id)->where('action', 'APPROVED_COMPLETION')->first()->makeVisible('metadata')->metadata['reviewer_relation']);
    }

    public function test_page_access_alone_or_no_leadership_is_refused_and_outsiders_get_404(): void
    {
        $t = $this->ticket();
        $state = $this->state();

        // No review permission (an ordinary member, a plain department leader without the grant).
        $this->confirm($t, $this->member)->assertForbidden()->assertJsonPath('code', 'REVIEW_PERMISSION_REQUIRED');
        $noGrant = $this->user('programs_manager');
        $other = Department::create(['name' => 'O'.Str::random(4), 'name_ar' => 'أخرى', 'slug' => 'o-'.Str::random(6), 'status' => 'active', 'is_active' => true, 'leader_id' => $noGrant->id]);
        $this->assertNotNull($other);
        $this->confirm($t, $noGrant)->assertNotFound();
        $this->confirm($t, $this->reporter)->assertForbidden();
        $this->confirm($t, $this->assignee)->assertForbidden()->assertJsonPath('code', 'REVIEW_PERMISSION_REQUIRED');
        // Holds the permission, but not the leader of this department and not global: the plain leaders-only refusal.
        $holder = $this->grantTicketReview($this->deptMember());
        $this->confirm($t, $holder)->assertForbidden()->assertJsonMissingPath('code');
        // Another department's leader with the permission cannot even see it.
        $foreign = $this->grantTicketReview($this->user('programs_manager'));
        Department::create(['name' => 'F'.Str::random(4), 'name_ar' => 'ثالثة', 'slug' => 'f-'.Str::random(6), 'status' => 'active', 'is_active' => true, 'leader_id' => $foreign->id]);
        $this->confirm($t, $foreign)->assertNotFound();
        // Unauthenticated.
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/tickets/{$t->id}/confirm-review")->assertUnauthorized();

        $this->assertSame($state, $this->state());
        $this->assertSame('PENDING_REVIEW', $t->fresh()->status);
        // A refused caller never learns the shape of the body.
        $this->confirm($t, $this->member, ['task_points' => 99, 'note' => str_repeat('x', 2000)])->assertForbidden()->assertJsonPath('code', 'REVIEW_PERMISSION_REQUIRED');
    }

    // ═══ The assignee's submission and the assignment ═══════════════════════

    public function test_submitting_for_review_requires_the_actual_duration_and_stores_it(): void
    {
        $t = $this->ticket(['status' => 'IN_PROGRESS', 'actual_minutes' => null]);
        $payload = ['resolution_type' => 'RESOLVED', 'resolution_summary' => 'تم الحل بالكامل'];

        $this->act($t, 'complete-task', $this->assignee, $payload)->assertStatus(422)->assertJsonPath('code', 'ACTUAL_DURATION_REQUIRED');
        $this->act($t, 'complete-task', $this->assignee, $payload + ['actual_minutes' => 0])->assertStatus(422)->assertJsonPath('code', 'ACTUAL_DURATION_INVALID');
        $this->act($t, 'complete-task', $this->assignee, $payload + ['actual_minutes' => 60001])->assertStatus(422)->assertJsonPath('code', 'ACTUAL_DURATION_INVALID');
        $this->assertSame('IN_PROGRESS', $t->fresh()->status);
        $this->assertNull($t->fresh()->actual_minutes);

        $this->act($t, 'complete-task', $this->assignee, $payload + ['actual_minutes' => 125])->assertOk()->assertJsonPath('data.status', 'PENDING_REVIEW')->assertJsonPath('data.actual_minutes', 125);
        $this->assertSame(125, $t->fresh()->actual_minutes);

        // Not required for an UNRESOLVED outcome (nothing goes to review).
        $u = $this->ticket(['status' => 'IN_PROGRESS', 'actual_minutes' => null]);
        $this->act($u, 'complete-task', $this->assignee, ['resolution_type' => 'UNRESOLVED', 'unresolved_reason' => 'تعذر الحل لقيود خارجية'])->assertOk()->assertJsonPath('data.status', 'UNRESOLVED');
    }

    public function test_assignment_requires_size_points_and_the_approximate_duration_unless_the_ticket_already_carries_them(): void
    {
        Sanctum::actingAs($this->leader);
        $pending = fn () => $this->ticket(['status' => 'PENDING_APPROVAL', 'assigned_to_id' => null, 'task_scope' => null, 'task_points' => null, 'estimated_hours' => null, 'actual_minutes' => null, 'accepted_at' => null]);

        // approve with an assignee: each missing value is refused and nothing changes.
        $a = $pending();
        $this->postJson("/api/v1/tickets/{$a->id}/approve", ['sla_hours' => 24, 'assigned_to_id' => $this->assignee->id, 'task_scope' => 'simple', 'task_points' => 30])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['estimated_hours']]);
        $this->postJson("/api/v1/tickets/{$a->id}/approve", ['sla_hours' => 24, 'assigned_to_id' => $this->assignee->id, 'estimated_hours' => 2])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['task_scope']]);
        $this->assertSame('PENDING_APPROVAL', $a->fresh()->status);
        $this->postJson("/api/v1/tickets/{$a->id}/approve", ['sla_hours' => 24, 'assigned_to_id' => $this->assignee->id, 'task_scope' => 'simple', 'task_points' => 30, 'estimated_hours' => 2])->assertOk();
        $this->assertEqualsWithDelta(2.0, (float) $a->fresh()->estimated_hours, 0.0001);

        // approve WITHOUT an assignee needs none of it.
        $b = $pending();
        $this->postJson("/api/v1/tickets/{$b->id}/approve", ['sla_hours' => 24])->assertOk();

        // reassign / bulk reassign: a legacy ticket with hours missing is refused, one that carries them keeps working.
        $legacy = $this->ticket(['status' => 'ASSIGNED', 'estimated_hours' => null, 'actual_minutes' => null]);
        $this->postJson("/api/v1/tickets/{$legacy->id}/reassign", ['assigned_to_id' => $this->member->id])->assertStatus(422)->assertJsonStructure(['errors' => ['estimated_hours']]);
        $bulk = $this->postJson('/api/v1/tickets/bulk', ['action' => 'reassign', 'ticket_ids' => [$legacy->id], 'assigned_to_id' => $this->member->id])->json('data');
        $this->assertSame(1, $bulk['failed']);
        $this->assertSame($this->assignee->id, (int) $legacy->fresh()->assigned_to_id);
        $this->postJson("/api/v1/tickets/{$legacy->id}/reassign", ['assigned_to_id' => $this->member->id, 'estimated_hours' => 1.5])->assertOk();

        $complete = $this->ticket(['status' => 'ASSIGNED', 'actual_minutes' => null]);
        $this->postJson("/api/v1/tickets/{$complete->id}/reassign", ['assigned_to_id' => $this->member->id])->assertOk();

        // While ASSIGNED / IN_PROGRESS a manager may still correct them.
        $working = $this->ticket(['status' => 'IN_PROGRESS', 'actual_minutes' => null]);
        $this->patchJson("/api/v1/tickets/{$working->id}/work-estimate", ['task_scope' => 'medium', 'task_points' => 100, 'estimated_hours' => 6])->assertOk()->assertJsonPath('data.estimated_hours', 6);
    }

    // ═══ Capabilities and payloads ══════════════════════════════════════════

    public function test_capabilities_and_blockers_are_for_reviewers_only_and_mirror_the_endpoint(): void
    {
        $t = $this->ticket();
        $d = $this->ticket([], deployment: true);

        $caps = $this->show($t, $this->leader)->assertOk()->json('data');
        $this->assertTrue($caps['capabilities']['confirm_review']);
        $this->assertTrue($caps['capabilities']['approve_completion'], 'kept for compatibility');
        $this->assertFalse($caps['capabilities']['approve_for_deploy']);
        $this->assertSame([], $caps['review_blockers']);
        $this->assertTrue($caps['review_context']['can_review']);

        $dcaps = $this->show($d, $this->leader)->json('data.capabilities');
        $this->assertTrue($dcaps['confirm_review']);
        $this->assertTrue($dcaps['approve_for_deploy']);
        $this->assertFalse($dcaps['approve_completion']);

        // List rows carry the same capabilities.
        Sanctum::actingAs($this->leader);
        $row = collect($this->getJson('/api/v1/tickets')->assertOk()->json('data.data'))->firstWhere('id', $t->id);
        $this->assertTrue($row['capabilities']['confirm_review']);

        // Not for the assignee, a plain member, the reporter or someone without the permission; reporters get no blockers at all.
        foreach ([$this->assignee, $this->member, $this->reporter] as $viewer) {
            $res = $this->show($t, $viewer);
            if ($res->status() !== 200) {
                continue;
            }
            $this->assertFalse((bool) ($res->json('data.capabilities.confirm_review')), 'viewer '.$viewer->id);
            $this->assertSame([], (array) ($res->json('data.review_blockers') ?? []));
        }
        $reporter = $this->show($t, $this->reporter)->assertOk();
        $this->assertArrayNotHasKey('review_blockers', $reporter->json('data'));
        $this->assertArrayNotHasKey('review_context', $reporter->json('data'));
        $this->assertFalse($reporter->json('data.capabilities.confirm_review'));
        $member = $this->show($t, $this->member)->assertOk();
        $this->assertArrayNotHasKey('review_blockers', $member->json('data'));
        $this->assertFalse($member->json('data.capabilities.confirm_review'));

        // Outside the review stage nothing is available.
        $working = $this->ticket(['status' => 'IN_PROGRESS']);
        $this->assertFalse($this->show($working, $this->leader)->json('data.capabilities.confirm_review'));
        $this->assertSame([], $this->show($working, $this->leader)->json('data.review_blockers'));
    }

    public function test_the_ticket_leaves_the_review_column_after_the_confirmation(): void
    {
        $t = $this->ticket();
        $this->confirm($t)->assertOk();

        Sanctum::actingAs($this->assignee);
        $work = collect($this->getJson('/api/v1/tickets/my-work')->assertOk()->json('data'))->firstWhere('id', $t->id);
        $this->assertSame('RESOLVED', $work['status']);

        Sanctum::actingAs($this->leader);
        $columns = collect($this->getJson('/api/v1/tickets/board')->assertOk()->json('data.columns'))->keyBy('status');
        $this->assertContains($t->id, collect($columns['RESOLVED']['tickets'])->pluck('id')->all());
        $this->assertNotContains($t->id, collect($columns['PENDING_REVIEW']['tickets'] ?? [])->pluck('id')->all());
    }

    public function test_the_legacy_endpoints_keep_working_as_the_same_transition(): void
    {
        $t = $this->ticket();
        $this->act($t, 'approve-completion', $this->leader, ['note' => 'قديم'])->assertOk()->assertJsonPath('data.status', 'RESOLVED')->assertJsonPath('data.credit.minutes', 75);
        $d = $this->ticket([], deployment: true);
        $this->act($d, 'approve-for-deploy', $this->leader, [])->assertOk()->assertJsonPath('data.status', 'APPROVED_FOR_DEPLOY');
        // Each keeps its own kind check.
        $this->act($this->ticket([], deployment: true), 'approve-completion', $this->leader)->assertStatus(409)->assertJsonPath('code', 'DEPLOYMENT_REQUIRED');
        $this->act($this->ticket(), 'approve-for-deploy', $this->leader)->assertStatus(409)->assertJsonPath('code', 'NOT_A_DEPLOYMENT_TICKET');
    }

    // ═══ Stale-screen protection and the submission audit ═══════════════════

    public function test_review_round_is_exposed_to_internal_viewers_and_a_stale_round_is_refused(): void
    {
        // Real journey: classic ticket, submitted for the first time -> round 1.
        $t = $this->ticket(['status' => 'IN_PROGRESS', 'actual_minutes' => null]);
        $this->act($t, 'complete-task', $this->assignee, ['resolution_type' => 'RESOLVED', 'resolution_summary' => 'تم الحل بالكامل', 'actual_minutes' => 40])->assertOk();
        $this->assertSame(1, (int) $t->fresh()->review_round, 'classic tickets count rounds too');
        $this->assertSame(1, $this->show($t, $this->leader)->json('data.review_round'));
        $this->assertSame(1, $this->show($t, $this->assignee)->json('data.review_round'));
        $this->assertArrayNotHasKey('review_round', $this->show($t, $this->reporter)->json('data'));
        $this->assertArrayNotHasKey('review_round', $this->show($t, $this->member)->json('data'));

        // The reviewer opened round 1; then it is returned and resubmitted (round 2).
        $this->act($t, 'return-for-rework', $this->leader, ['reason' => 'يلزم استكمال الاختبار'])->assertOk();
        $this->act($t, 'complete-task', $this->assignee, ['resolution_type' => 'RESOLVED', 'resolution_summary' => 'استكملت العمل', 'actual_minutes' => 55])->assertOk();
        $this->assertSame(2, $this->show($t, $this->leader)->json('data.review_round'));
        $state = $this->state();

        foreach ([1, '1', 0, 3] as $old) {
            $this->confirm($t, null, ['review_round' => $old])->assertStatus(409)->assertJsonPath('code', 'STALE_REVIEW')
                ->assertJsonPath('message', 'تغيّرت التذكرة منذ فتحتها (أُعيدت أو أُعيد إرسالها)؛ حدّث الصفحة وراجع القيم الجديدة.');
        }
        $this->act($t, 'approve-completion', $this->leader, ['review_round' => 1])->assertStatus(409)->assertJsonPath('code', 'STALE_REVIEW');
        $this->confirm($t, null, ['review_round' => 'abc'])->assertStatus(422)->assertJsonStructure(['errors' => ['review_round']]);
        $this->assertSame($state, $this->state());
        $this->assertSame('PENDING_REVIEW', $t->fresh()->status);

        // The matching round passes (a review round is not a locked value) and the credit uses the round-2 duration.
        $this->confirm($t, null, ['review_round' => 2, 'note' => 'ok'])->assertOk()->assertJsonPath('data.status', 'RESOLVED')->assertJsonPath('data.credit.minutes', 55);
    }

    public function test_an_absent_round_still_works_and_the_deployment_round_stays_consistent(): void
    {
        $d = $this->ticket(['status' => 'IN_PROGRESS', 'actual_minutes' => null], deployment: true);
        $this->act($d, 'complete-task', $this->assignee, ['actual_minutes' => 30])->assertOk();
        $detail = $this->show($d, $this->leader)->json('data');
        $this->assertSame(1, $detail['review_round']);
        $this->assertSame($detail['review_round'], $detail['deployment']['round']);

        $this->act($d, 'approve-for-deploy', $this->leader, ['review_round' => 5])->assertStatus(409)->assertJsonPath('code', 'STALE_REVIEW');
        $this->confirm($d)->assertOk()->assertJsonPath('data.status', 'APPROVED_FOR_DEPLOY')->assertJsonPath('data.review_round', 1);
    }

    public function test_the_claimed_duration_is_logged_internally_at_submission(): void
    {
        $t = $this->ticket(['status' => 'IN_PROGRESS', 'actual_minutes' => null]);
        $this->act($t, 'complete-task', $this->assignee, ['resolution_type' => 'RESOLVED', 'resolution_summary' => 'تم الحل بالكامل', 'actual_minutes' => 40])->assertOk();
        $this->act($t, 'return-for-rework', $this->leader, ['reason' => 'يلزم استكمال الاختبار'])->assertOk();
        $this->assertNull($t->fresh()->actual_minutes, 'rework clears it');

        $row = TicketActivityLog::where('ticket_id', $t->id)->where('action', 'SUBMITTED_FOR_REVIEW')->sole();
        $this->assertSame(40, $row->makeVisible('metadata')->metadata['claimed_actual_minutes']);
        $this->assertSame(1, $row->metadata['review_round']);

        // Internal viewers get the metadata; the reporter never sees it.
        $internal = collect($this->show($t, $this->leader)->json('data.activity_logs'))->firstWhere('action', 'SUBMITTED_FOR_REVIEW');
        $this->assertSame(40, $internal['metadata']['claimed_actual_minutes']);
        $reporter = json_encode($this->show($t, $this->reporter)->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('claimed_actual_minutes', $reporter);
        $this->assertStringNotContainsString('review_round', $reporter);
    }
}
