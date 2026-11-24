<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TicketActivityLog;
use App\Models\TicketCredit;
use App\Models\User;
use App\Models\VolunteerPointAward;
use App\Models\VolunteerProfile;
use App\Services\TaskWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\GrantsTicketReviewPermissions;
use Tests\TestCase;

/**
 * THE MEMBER'S BALANCE RECEIVES THE ACTUAL TIME THE ASSIGNEE RECORDED, NOT THE PLANNING ESTIMATE.
 *
 * `estimated_hours` («المدة التقريبية») is planning data set at assignment and is never credited. The ASSIGNEE records the
 * ACTUAL duration (`actual_minutes`) when sending the work to review (required there); during review it is read-only, the
 * one-click «تأكيد المراجعة» only moves the stage, and the stored value is what gets credited (round(minutes / 60, 2)) once.
 */
class TicketActualDurationTest extends TestCase
{
    use RefreshDatabase;
    use GrantsTicketReviewPermissions;

    private const REQUIRED = 'أدخل المدة الفعلية التي استغرقها العمل (بالساعات والدقائق) قبل الإرسال للمراجعة.';

    private const DUPLICATE = 'تم اعتماد هذه التذكرة وإضافة رصيدها مسبقًا.';

    private Department $dept;

    private User $leader;

    private User $assignee;

    private User $member;

    private User $reporter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dept = Department::create(['name' => 'D '.Str::random(5), 'name_ar' => 'إدارة', 'slug' => 'd-'.Str::random(8), 'status' => 'active', 'is_active' => true]);
        $this->leader = $this->user('programs_manager');
        $this->dept->update(['leader_id' => $this->leader->id]);
        $this->assignee = $this->deptMember();
        $this->grantTicketReview($this->leader);
        $this->member = $this->deptMember();
        $this->reporter = $this->user('student');
    }

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

    private function ticket(array $over = []): Ticket
    {
        // actual_minutes (recorded by the assignee at submission) is not mass assignable: set it the way the app does.
        $actual = $over['actual_minutes'] ?? null;
        unset($over['actual_minutes']);

        $ticket = Ticket::create(array_merge([
            'ticket_number' => 'EMC-TCK-D-'.Str::random(8), 'title' => 'تذكرة', 'description' => 'وصف', 'ticket_category' => 'OLD_ISSUE', 'priority' => 'MEDIUM',
            'department_id' => $this->dept->id, 'created_by_id' => $this->reporter->id, 'created_by_name' => 'مُبلِّغ', 'status' => 'PENDING_APPROVAL',
        ], $over));
        if ($actual !== null) {
            $ticket->forceFill(['actual_minutes' => $actual])->save();
        }

        return $ticket;
    }

    /** Submitted for review: simple, 30 points, an ESTIMATE of 2 hours (never credited) and 75 actual minutes recorded by the assignee (a ticket not in review has none unless a test sets it). */
    private function submitted(array $over = []): Ticket
    {
        $inReview = ($over['status'] ?? 'PENDING_REVIEW') === 'PENDING_REVIEW';

        return $this->ticket(array_merge([
            'status' => 'PENDING_REVIEW', 'assigned_to_id' => $this->assignee->id, 'approved_by_id' => $this->leader->id, 'accepted_at' => now()->subHour(),
            'task_scope' => 'simple', 'task_points' => 30, 'estimated_hours' => 2, 'resolution_summary' => 'تم الحل',
            'actual_minutes' => $inReview ? 75 : null,
        ], $over));
    }

    private function approve(Ticket $t, ?User $as = null, array $body = [])
    {
        Sanctum::actingAs($as ?? $this->leader);

        return $this->postJson("/api/v1/tickets/{$t->id}/approve-completion", $body);
    }

    private function hours(User $u): float
    {
        return (float) (VolunteerProfile::where('user_id', $u->id)->value('hours_logged') ?? 0);
    }

    private function state(): array
    {
        return [VolunteerPointAward::count(), (float) VolunteerProfile::sum('hours_logged'), TicketCredit::count(), TicketActivityLog::count()];
    }

    // ═══ What is credited ═══════════════════════════════════════════════════

    public function test_the_actual_duration_is_credited_not_the_estimate_and_only_once(): void
    {
        $t = $this->submitted(); // estimate: 2 h, actual recorded by the assignee: 75 min

        $this->assertEqualsWithDelta(0.0, $this->hours($this->assignee), 0.001, 'nothing before the confirmation');

        $res = $this->approve($t)->assertOk()
            ->assertJsonPath('data.credit.status', 'credited')
            ->assertJsonPath('data.credit.hours', 1.25)
            ->assertJsonPath('data.credit.minutes', 75)
            ->assertJsonPath('data.actual_minutes', 75)
            ->assertJsonPath('data.estimated_hours', 2);

        $this->assertEqualsWithDelta(1.25, $this->hours($this->assignee), 0.0001, '+1.25 h, not +2');
        $fresh = $t->fresh();
        $this->assertSame(75, $fresh->actual_minutes);
        $this->assertSame(75, $fresh->credited_minutes);
        $this->assertEqualsWithDelta(1.25, $fresh->credited_hours, 0.0001);
        $this->assertEqualsWithDelta(2.0, (float) $fresh->estimated_hours, 0.0001, 'the planning estimate is untouched');

        $credit = TicketCredit::where('ticket_id', $t->id)->sole();
        $this->assertSame(75, $credit->minutes);
        $this->assertEqualsWithDelta(1.25, $credit->hours, 0.0001);

        $log = (string) TicketActivityLog::where('ticket_id', $t->id)->where('action', 'CREDIT_ADDED')->value('details');
        // CHANGED: Arabic wording with only the non-zero parts (was «1 ساعة 15 دقيقة»).
        $this->assertStringContainsString('المدة الفعلية: ساعة و15 دقيقة', $log);

        // Replay, and replay with a DIFFERENT value: 409, nothing changes.
        $state = $this->state();
        $this->approve($t)->assertStatus(409)->assertJsonPath('code', 'ALREADY_CREDITED')->assertJsonPath('message', self::DUPLICATE);
        $this->approve($t, null, ['actual_minutes' => 600])->assertStatus(409)->assertJsonPath('code', 'ALREADY_CREDITED');
        $this->approve($t, $this->user('admin'), ['actual_minutes' => 600])->assertStatus(409)->assertJsonPath('code', 'ALREADY_CREDITED');
        $this->assertSame($state, $this->state());
        $this->assertEqualsWithDelta(1.25, $this->hours($this->assignee), 0.0001);
        $this->assertSame(75, $t->fresh()->actual_minutes);
        $this->assertSame(1, VolunteerPointAward::where('source_type', Ticket::class)->where('source_id', $t->id)->count());
    }

    public function test_minutes_convert_to_hours_with_two_decimals(): void
    {
        foreach ([[1, 0.02], [20, 0.33], [90, 1.5], [60000, 1000.0]] as [$minutes, $hours]) {
            $assignee = $this->deptMember();
            $t = $this->submitted(['assigned_to_id' => $assignee->id, 'actual_minutes' => $minutes]);

            $this->approve($t)->assertOk()->assertJsonPath('data.credit.minutes', $minutes);
            $this->assertEqualsWithDelta($hours, $this->hours($assignee), 0.0001, "{$minutes} min");
        }
    }

    /** CHANGED (was: ACTUAL_DURATION_REQUIRED at the approval). A ticket whose actual duration was never recorded cannot be confirmed: 422 REVIEW_DATA_INCOMPLETE. */
    public function test_an_estimate_without_an_actual_duration_is_never_credited(): void
    {
        $t = $this->submitted(['actual_minutes' => null]); // estimate 2 h present, no recorded actual
        $state = $this->state();

        $res = $this->approve($t)->assertStatus(422)->assertJsonPath('code', 'REVIEW_DATA_INCOMPLETE');
        $this->assertNotEmpty($res->json('errors.actual_minutes'));
        // Sending a value in the confirmation is refused, never stored.
        $this->approve($t, null, ['actual_minutes' => 90])->assertStatus(422)->assertJsonPath('code', 'REVIEW_VALUES_LOCKED');
        $this->approve($t, null, ['estimated_hours' => 9, 'task_points' => 45])->assertStatus(422)->assertJsonPath('code', 'REVIEW_VALUES_LOCKED');

        $this->assertSame($state, $this->state(), 'no credit row, no ledger, no hours, no log');
        $fresh = $t->fresh();
        $this->assertSame('PENDING_REVIEW', $fresh->status);
        $this->assertNull($fresh->actual_minutes);
        $this->assertNull($fresh->credited_at);
        $this->assertEqualsWithDelta(2.0, (float) $fresh->estimated_hours, 0.0001, 'no estimate edit was stored');
        $this->assertSame(30, $fresh->task_points);
        $this->assertEqualsWithDelta(0.0, $this->hours($this->assignee), 0.001);
    }

    /** CHANGED: the actual duration is entered by the assignee when submitting; the same codes and messages, refused before anything is written. */
    public function test_an_invalid_actual_duration_is_refused_with_arabic_messages(): void
    {
        $t = $this->submitted(['status' => 'IN_PROGRESS']);
        $state = $this->state();
        Sanctum::actingAs($this->assignee);
        $submit = fn (array $extra) => $this->postJson("/api/v1/tickets/{$t->id}/complete-task", ['resolution_type' => 'RESOLVED', 'resolution_summary' => 'تم الحل بالكامل'] + $extra);

        $submit([])->assertStatus(422)->assertJsonPath('code', 'ACTUAL_DURATION_REQUIRED')->assertJsonPath('message', self::REQUIRED)->assertJsonPath('errors.actual_minutes.0', self::REQUIRED);
        $submit(['actual_minutes' => null])->assertStatus(422)->assertJsonPath('code', 'ACTUAL_DURATION_REQUIRED');
        $cases = [
            [0, 'المدة الفعلية يجب ألا تقل عن دقيقة واحدة.'],
            [-5, 'المدة الفعلية يجب ألا تقل عن دقيقة واحدة.'],
            [60001, 'المدة الفعلية لا تتجاوز 1000 ساعة (60000 دقيقة).'],
            [1.5, 'المدة الفعلية يجب أن تكون عددًا صحيحًا من الدقائق.'],
            ['abc', 'المدة الفعلية يجب أن تكون عددًا صحيحًا من الدقائق.'],
            [true, 'المدة الفعلية يجب أن تكون عددًا صحيحًا من الدقائق.'],
            [[1], 'المدة الفعلية يجب أن تكون عددًا صحيحًا من الدقائق.'],
        ];
        foreach ($cases as [$value, $message]) {
            $submit(['actual_minutes' => $value])->assertStatus(422)
                ->assertJsonPath('code', 'ACTUAL_DURATION_INVALID')->assertJsonPath('errors.actual_minutes.0', $message);
        }

        $this->assertSame($state, $this->state());
        $this->assertSame('IN_PROGRESS', $t->fresh()->status, 'the submission was refused as a whole');
        $this->assertNull($t->fresh()->actual_minutes);

        // A numeric string is accepted and stored.
        $submit(['actual_minutes' => '60'])->assertOk()->assertJsonPath('data.status', 'PENDING_REVIEW')->assertJsonPath('data.actual_minutes', 60);
        $this->assertSame(60, $t->fresh()->actual_minutes);
    }

    /** CHANGED: a legacy review ticket with nothing recorded is refused with the full list of what is missing; the confirmation supplies none of it. */
    public function test_a_legacy_review_ticket_with_no_estimate_and_no_actual_needs_both(): void
    {
        $t = $this->submitted(['task_scope' => null, 'task_points' => null, 'estimated_hours' => null, 'actual_minutes' => null]);
        $state = $this->state();

        $res = $this->approve($t)->assertStatus(422)->assertJsonPath('code', 'REVIEW_DATA_INCOMPLETE');
        foreach (['task_scope', 'task_points', 'estimated_hours', 'actual_minutes'] as $field) {
            $this->assertNotEmpty($res->json("errors.{$field}"), $field);
        }

        $this->approve($t, null, ['task_scope' => 'simple', 'task_points' => 30, 'actual_minutes' => 30])->assertStatus(422)->assertJsonPath('code', 'REVIEW_VALUES_LOCKED');
        $this->assertSame($state, $this->state());
        $this->assertNull($t->fresh()->task_scope);
    }

    // ═══ Who can enter it ═══════════════════════════════════════════════════

    public function test_only_the_authorised_reviewer_can_enter_it_and_nothing_is_stored_for_anyone_else(): void
    {
        $t = $this->submitted();
        $medium = $this->submitted(['task_scope' => 'medium', 'task_points' => 100]);
        $submitted = $this->submitted();
        $submitted->forceFill(['submitted_by_id' => $this->member->id])->save();
        $otherLeader = $this->user('programs_manager');
        Department::create(['name' => 'O'.Str::random(4), 'name_ar' => 'أخرى', 'slug' => 'o-'.Str::random(6), 'status' => 'active', 'is_active' => true, 'leader_id' => $otherLeader->id]);
        $state = $this->state();

        // A valid AND an invalid value: the answer is the authorization refusal either way (never a 422 probe).
        foreach ([75, 0, null] as $value) {
            $body = ['actual_minutes' => $value];
            // Deliberately changed: the assignee / submitter used to get SELF_REVIEW; now an ordinary member simply lacks the review permission.
            $this->approve($t, $this->assignee, $body)->assertForbidden()->assertJsonPath('code', 'REVIEW_PERMISSION_REQUIRED');
            $this->approve($submitted, $this->member, $body)->assertForbidden()->assertJsonPath('code', 'REVIEW_PERMISSION_REQUIRED');
            $this->approve($t, $this->deptMember(), $body)->assertForbidden();
            $this->approve($t, $this->reporter, $body)->assertForbidden();
            $this->approve($t, $otherLeader, $body)->assertNotFound();
            $this->approve($medium, $this->leader, $body)->assertForbidden()->assertJsonPath('code', 'HR_APPROVAL_REQUIRED');
        }

        $this->assertSame($state, $this->state());
        foreach ([$t, $medium, $submitted] as $x) {
            $this->assertSame(75, $x->fresh()->actual_minutes);
            $this->assertSame('PENDING_REVIEW', $x->fresh()->status);
        }
    }

    /** CHANGED (was: only the final approval writes it). Only the ASSIGNEE's submission writes it; every manager endpoint ignores the key. */
    public function test_the_actual_duration_can_only_be_written_by_the_final_approval(): void
    {
        $inProgress = $this->submitted(['status' => 'IN_PROGRESS']);
        $assigned = $this->submitted(['status' => 'ASSIGNED']);
        $pending = $this->ticket();

        // PATCH work-estimate: the key is ignored (not part of the estimate), by a manager, and refused for the assignee.
        Sanctum::actingAs($this->leader);
        $this->patchJson("/api/v1/tickets/{$inProgress->id}/work-estimate", ['task_scope' => 'simple', 'task_points' => 40, 'actual_minutes' => 600])->assertOk();
        $this->assertSame(40, $inProgress->fresh()->task_points);
        $this->assertNull($inProgress->fresh()->actual_minutes);

        // approve (assignment), reassign, bulk reassign.
        $this->postJson("/api/v1/tickets/{$pending->id}/approve", ['sla_hours' => 24, 'assigned_to_id' => $this->assignee->id, 'task_scope' => 'simple', 'task_points' => 30, 'estimated_hours' => 2, 'actual_minutes' => 600])->assertOk();
        $this->assertNull($pending->fresh()->actual_minutes);
        $this->postJson("/api/v1/tickets/{$assigned->id}/reassign", ['assigned_to_id' => $this->member->id, 'task_scope' => 'simple', 'task_points' => 30, 'actual_minutes' => 600])->assertOk();
        $this->assertNull($assigned->fresh()->actual_minutes);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->postJson('/api/v1/tickets/bulk', ['action' => 'reassign', 'ticket_ids' => [$assigned->id], 'assigned_to_id' => $this->assignee->id, 'task_scope' => 'simple', 'task_points' => 30, 'actual_minutes' => 600])->assertOk();
        $this->assertNull($assigned->fresh()->actual_minutes);

        // create: the submitter cannot set it and never sees it.
        Sanctum::actingAs($this->reporter);
        $res = $this->postJson('/api/v1/tickets', ['title' => 'جديدة', 'description' => 'وصف', 'ticket_category' => 'OLD_ISSUE', 'department_id' => $this->dept->id, 'created_by_name' => 'م', 'actual_minutes' => 600])->assertStatus(201);
        $this->assertArrayNotHasKey('actual_minutes', $res->json('data'));
        $this->assertNull(Ticket::find($res->json('data.id'))->actual_minutes);

        // The assignee records it when submitting the work (and only then).
        $working = $this->submitted(['status' => 'IN_PROGRESS']);
        Sanctum::actingAs($this->assignee);
        $this->postJson("/api/v1/tickets/{$working->id}/complete-task", ['resolution_type' => 'RESOLVED', 'resolution_summary' => 'تم الحل بالكامل', 'actual_minutes' => 600])->assertOk();
        $this->assertSame(600, $working->fresh()->actual_minutes);
        $this->assertSame('PENDING_REVIEW', $working->fresh()->status);

        // And the assignee's own PATCH is refused outright.
        $this->patchJson("/api/v1/tickets/{$inProgress->id}/work-estimate", ['task_scope' => 'simple', 'task_points' => 40, 'actual_minutes' => 600])->assertForbidden();
        $this->assertNull($inProgress->fresh()->actual_minutes);
    }

    public function test_the_gates_still_hold_and_the_hr_gate_comes_before_the_duration_check(): void
    {
        $medium = $this->submitted(['task_scope' => 'medium', 'task_points' => 100, 'estimated_hours' => 4, 'actual_minutes' => 240]);
        $incomplete = $this->submitted(['task_scope' => 'medium', 'task_points' => 100, 'actual_minutes' => null]);

        // Non-HR leader: the HR refusal, before the data check.
        $this->approve($medium)->assertForbidden()->assertJsonPath('code', 'HR_APPROVAL_REQUIRED');
        $this->approve($incomplete)->assertForbidden()->assertJsonPath('code', 'HR_APPROVAL_REQUIRED');

        // An HR approver credits the ACTUAL time of the medium ticket.
        $this->approve($medium, $this->user('admin'))->assertOk()->assertJsonPath('data.credit.hours', 4);
        $this->assertSame(100, VolunteerPointAward::where('source_id', $medium->id)->sole()->points, 'points rules unchanged');

        // Wrong status comes before everything else: nothing to validate for a ticket that is not in review.
        $working = $this->submitted(['status' => 'IN_PROGRESS']);
        $this->approve($working)->assertStatus(409)->assertJsonPath('code', 'NOT_PENDING_REVIEW');
    }

    // ═══ Visibility ═════════════════════════════════════════════════════════

    public function test_the_actual_duration_is_for_managers_global_roles_and_the_assignee_only(): void
    {
        $t = $this->submitted();
        $this->approve($t)->assertOk();

        foreach ([$this->leader, $this->assignee, $this->user('admin')] as $viewer) {
            Sanctum::actingAs($viewer);
            $show = $this->getJson("/api/v1/tickets/{$t->id}")->assertOk();
            $show->assertJsonPath('data.actual_minutes', 75)->assertJsonPath('data.credit.minutes', 75)->assertJsonPath('data.credit.hours', 1.25);
            $this->assertArrayNotHasKey('credited_minutes', $show->json('data'));
            $row = collect($this->getJson('/api/v1/tickets')->json('data.data'))->firstWhere('id', $t->id);
            $this->assertSame(75, $row['actual_minutes']);
            $this->assertSame(75, $row['credit']['minutes']);
        }

        Sanctum::actingAs($this->leader);
        $card = collect(collect($this->getJson('/api/v1/tickets/board')->json('data.columns'))->firstWhere('status', 'RESOLVED')['tickets'])->firstWhere('id', $t->id);
        $this->assertSame(75, $card['actual_minutes']);
        $this->assertSame(75, $card['credit']['minutes']);

        Sanctum::actingAs($this->assignee);
        $work = collect($this->getJson('/api/v1/tickets/my-work')->json('data'))->firstWhere('id', $t->id);
        $this->assertSame(75, $work['actual_minutes']);
        $this->assertSame(75, $work['credit']['minutes']);

        foreach ([$this->member, $this->reporter] as $viewer) {
            Sanctum::actingAs($viewer);
            $body = json_encode([
                $this->getJson("/api/v1/tickets/{$t->id}")->assertOk()->json(),
                $this->getJson('/api/v1/tickets')->assertOk()->json(),
            ], JSON_UNESCAPED_UNICODE);
            foreach (['actual_minutes', 'credited_minutes', '"minutes"', 'CREDIT_ADDED', 'المدة الفعلية'] as $leak) {
                $this->assertStringNotContainsString($leak, $body, $leak);
            }
        }
    }

    public function test_staff_see_the_recorded_actual_duration_before_the_approval(): void
    {
        $t = $this->submitted();

        Sanctum::actingAs($this->leader);
        $data = $this->getJson("/api/v1/tickets/{$t->id}")->assertOk()->json('data');
        $this->assertArrayHasKey('actual_minutes', $data);
        $this->assertSame(75, $data['actual_minutes'], 'recorded by the assignee at submission, read-only during review');
        $this->assertSame(75, $data['credit']['minutes'] ?? 75);
        $this->assertSame('pending_review', $data['credit']['status']);
    }

    public function test_a_credit_made_before_actual_durations_existed_keeps_its_hours_and_has_null_minutes(): void
    {
        $t = $this->ticket(['status' => 'RESOLVED', 'resolved_at' => now()->subMonth(), 'assigned_to_id' => $this->assignee->id, 'task_scope' => 'simple', 'task_points' => 30, 'estimated_hours' => 2]);
        $t->forceFill(['credited_points' => 30, 'credited_hours' => 2, 'credited_at' => now()->subMonth(), 'credited_by_id' => $this->leader->id])->save();

        Sanctum::actingAs($this->leader);
        $credit = $this->getJson("/api/v1/tickets/{$t->id}")->assertOk()->json('data.credit');
        $this->assertSame('credited', $credit['status']);
        $this->assertSame(2, (int) $credit['hours']);
        $this->assertNull($credit['minutes']);
        $this->assertNull($t->fresh()->actual_minutes);
    }

    // ═══ Regular tasks are unchanged ════════════════════════════════════════

    public function test_regular_tasks_still_credit_their_own_estimated_hours(): void
    {
        $volunteer = $this->user();
        $admin = $this->user('admin');
        $task = Task::create(['title' => 'مهمة', 'description' => 'x', 'task_scope' => Task::SCOPE_SIMPLE, 'points' => 30, 'estimated_hours' => 2, 'assigned_to' => $volunteer->id, 'created_by' => $admin->id, 'status' => 'completed', 'priority' => 'medium']);

        TaskWorkflowService::creditVolunteerHoursAndPoints($task, $admin);

        $this->assertEqualsWithDelta(2.0, $this->hours($volunteer), 0.001);
    }

    public function test_the_columns_exist_and_are_nullable_integers(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumns('tickets', ['actual_minutes', 'credited_minutes']));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('ticket_credits', 'minutes'));
        $t = $this->submitted(['status' => 'IN_PROGRESS']);
        $this->assertNull($t->fresh()->actual_minutes);
        $this->assertNull(DB::table('tickets')->where('id', $t->id)->value('credited_minutes'));
        $this->assertSame(0, Ticket::make()->isFillable('actual_minutes') ? 1 : 0, 'actual_minutes is not mass assignable');
    }


    // ═══ Round 2: order of checks, no stored fallback, one late 422, codes, Arabic wording ═══

    public function test_the_409s_come_before_the_hr_gate_and_the_hr_gate_before_the_422(): void
    {
        // A non-HR leader with a stale screen on a medium ticket.
        $credited = $this->submitted(['task_scope' => 'medium', 'task_points' => 100]);
        $this->approve($credited, $this->user('admin'))->assertOk();
        $this->approve($credited, null, ['actual_minutes' => 60])->assertStatus(409)->assertJsonPath('code', 'ALREADY_CREDITED');
        $this->approve($credited)->assertStatus(409)->assertJsonPath('code', 'ALREADY_CREDITED');

        $returned = $this->submitted(['task_scope' => 'medium', 'task_points' => 100, 'status' => 'IN_PROGRESS']);
        $this->approve($returned, null, ['actual_minutes' => 60])->assertStatus(409)->assertJsonPath('code', 'NOT_PENDING_REVIEW');
        $this->approve($returned)->assertStatus(409)->assertJsonPath('code', 'NOT_PENDING_REVIEW');

        // Still pending: the HR refusal wins over every validation error.
        $pending = $this->submitted(['task_scope' => 'medium', 'task_points' => 100, 'actual_minutes' => null]);
        $this->approve($pending)->assertForbidden()->assertJsonPath('code', 'HR_APPROVAL_REQUIRED');
        $this->approve($pending, null, ['task_points' => 9999, 'actual_minutes' => 0, 'note' => str_repeat('x', 1001)])->assertForbidden()->assertJsonPath('code', 'HR_APPROVAL_REQUIRED');
        $this->approve($pending, null, ['task_scope' => 'large', 'task_points' => 250])->assertForbidden()->assertJsonPath('code', 'HR_APPROVAL_REQUIRED');
    }

    /** CHANGED (was: a stored duration never satisfied the approval, only a typed one did). Now ONLY the stored duration counts; a typed one is refused. */
    public function test_a_stored_actual_duration_never_satisfies_the_requirement(): void
    {
        $t = $this->submitted(['actual_minutes' => 90]);
        $state = $this->state();

        $this->approve($t, null, ['actual_minutes' => 30])->assertStatus(422)->assertJsonPath('code', 'REVIEW_VALUES_LOCKED');
        $this->approve($t, null, ['actual_minutes' => ''])->assertStatus(422)->assertJsonPath('code', 'REVIEW_VALUES_LOCKED');
        $this->assertSame($state, $this->state());
        $this->assertSame('PENDING_REVIEW', $t->fresh()->status);

        // The STORED value is what is credited.
        $this->approve($t)->assertOk()->assertJsonPath('data.credit.minutes', 90);
        $this->assertEqualsWithDelta(1.5, $this->hours($this->assignee), 0.0001);
    }

    public function test_an_invalid_estimate_field_or_note_gets_the_same_refusal_as_a_valid_one_for_unauthorised_callers(): void
    {
        $t = $this->submitted();
        $otherLeader = $this->user('programs_manager');
        Department::create(['name' => 'O'.Str::random(4), 'name_ar' => 'أخرى', 'slug' => 'o-'.Str::random(6), 'status' => 'active', 'is_active' => true, 'leader_id' => $otherLeader->id]);
        $callers = [
            'reporter' => [$this->reporter, 403],
            'plain member' => [$this->deptMember(), 403],
            'assignee' => [$this->assignee, 403],
            'other-department leader' => [$otherLeader, 404],
            'stranger' => [$this->user('student'), 404],
        ];
        $valid = ['actual_minutes' => 60, 'task_points' => 35, 'note' => 'ملاحظة'];
        $invalid = ['actual_minutes' => 0, 'task_points' => 9999, 'estimated_hours' => -1, 'task_scope' => 'giant', 'note' => str_repeat('x', 1001)];
        $state = $this->state();

        foreach ($callers as $name => [$who, $status]) {
            $this->approve($t, $who, $valid)->assertStatus($status);
            $this->approve($t, $who, $invalid)->assertStatus($status);
            $this->approve($t, $who, [])->assertStatus($status);
        }

        $this->assertSame($state, $this->state());
        $this->assertSame(30, $t->fresh()->task_points);
    }

    public function test_one_422_carries_every_error_and_the_code_only_when_the_duration_is_the_only_problem(): void
    {
        $t = $this->submitted(['actual_minutes' => null, 'estimated_hours' => null]);

        // Incomplete stored data: one 422 naming every missing value, with the stable code; a bad note is its own 422 first.
        $res = $this->approve($t)->assertStatus(422)->assertJsonPath('code', 'REVIEW_DATA_INCOMPLETE');
        foreach (['estimated_hours', 'actual_minutes'] as $field) {
            $this->assertNotEmpty($res->json("errors.{$field}"), $field);
        }
        $this->assertArrayNotHasKey('task_scope', $res->json('errors'));
        $this->approve($t, null, ['note' => str_repeat('x', 1001)])->assertStatus(422)->assertJsonPath('errors.note.0', 'الملاحظة طويلة جدًا؛ الحد الأقصى 1000 حرف.');

        // Values in the body: refused with the locked code, whatever else is wrong.
        $this->approve($t, null, ['task_points' => 9999, 'estimated_hours' => -1, 'actual_minutes' => 0])->assertStatus(422)->assertJsonPath('code', 'REVIEW_VALUES_LOCKED');

        $this->assertSame(0, VolunteerPointAward::count());
    }

    public function test_the_credit_notification_states_the_actual_time_in_natural_arabic(): void
    {
        $t = $this->submitted(['estimated_hours' => 5]);
        $this->approve($t)->assertOk();

        $message = (string) DB::table('platform_notifications')->where('type', 'ticket_completion_approved')->where('user_id', $this->assignee->id)->value('message');
        $this->assertStringContainsString('ساعة و15 دقيقة', $message);
        $this->assertStringContainsString('30 نقطة', $message);
        $this->assertStringNotContainsString('1.25', $message);
        $this->assertStringNotContainsString('5 ساعات', $message, 'the estimate is never mentioned as credited time');

        $other = $this->deptMember();
        $short = $this->submitted(['assigned_to_id' => $other->id, 'actual_minutes' => 1]);
        $this->approve($short)->assertOk();
        $message = (string) DB::table('platform_notifications')->where('type', 'ticket_completion_approved')->where('user_id', $other->id)->value('message');
        $this->assertStringContainsString('دقيقة.', $message);
        $this->assertStringNotContainsString('0.02', $message);
    }
}
