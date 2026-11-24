<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TicketActivityLog;
use App\Models\User;
use App\Models\VolunteerPointAward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A ticket that becomes WORK FOR A PERSON must carry its size and points (an estimate only), on every
 * path that assigns it: approve with an assignee, reassign, bulk reassign. The work-estimate endpoint
 * edits it later. Reporters never see it, and nothing here credits points or creates a Task.
 */
class TicketWorkEstimateTest extends TestCase
{
    use RefreshDatabase;

    private const MISSING = 'حدّد نقاط المهمة وحجمها قبل الإسناد';

    private Department $dept;

    private User $leader;

    private User $member;

    private User $assignee;

    private User $other;

    private User $reporter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dept = Department::create(['name' => 'D '.Str::random(5), 'name_ar' => 'إدارة التقنية', 'slug' => 'd-'.Str::random(8), 'status' => 'active', 'is_active' => true]);
        $this->leader = User::factory()->create(['role' => 'programs_manager', 'is_active' => true]);
        $this->dept->update(['leader_id' => $this->leader->id]);
        $this->member = $this->deptMember();
        $this->assignee = $this->deptMember();
        $this->other = $this->deptMember();
        $this->reporter = User::factory()->create(['role' => 'student', 'is_active' => true]);
    }

    private function deptMember(): User
    {
        $u = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $this->dept->users()->attach($u->id);

        return $u;
    }

    private function ticket(array $over = []): Ticket
    {
        return Ticket::create(array_merge([
            'ticket_number' => 'EMC-TCK-W-'.Str::random(8),
            'title' => 'تذكرة '.Str::random(4),
            'description' => 'وصف التذكرة',
            'ticket_category' => 'OLD_ISSUE',
            'priority' => 'MEDIUM',
            'department_id' => $this->dept->id,
            'created_by_id' => $this->reporter->id,
            'created_by_name' => 'مُبلِّغ',
            'status' => 'PENDING_APPROVAL',
        ], $over));
    }

    private function approve(Ticket $t, array $body = [], ?User $as = null)
    {
        Sanctum::actingAs($as ?? $this->leader);

        return $this->postJson("/api/v1/tickets/{$t->id}/approve", array_merge(['sla_hours' => 24], $body));
    }

    private function reassign(Ticket $t, array $body = [], ?User $as = null)
    {
        Sanctum::actingAs($as ?? $this->leader);

        return $this->postJson("/api/v1/tickets/{$t->id}/reassign", array_merge(['assigned_to_id' => $this->assignee->id], $body));
    }

    private function estimate(Ticket $t, array $body, ?User $as = null)
    {
        Sanctum::actingAs($as ?? $this->leader);

        return $this->patchJson("/api/v1/tickets/{$t->id}/work-estimate", $body);
    }

    // ═══ Approve ════════════════════════════════════════════════════════════

    public function test_approve_with_an_assignee_requires_scope_and_points(): void
    {
        $t = $this->ticket();

        $this->approve($t, ['assigned_to_id' => $this->assignee->id])
            ->assertStatus(422)
            ->assertJsonPath('message', self::MISSING)
            ->assertJsonPath('errors.task_points.0', self::MISSING)
            ->assertJsonPath('errors.task_scope.0', self::MISSING);

        $fresh = $t->fresh();
        $this->assertSame('PENDING_APPROVAL', $fresh->status, 'a refused approval changes nothing');
        $this->assertNull($fresh->assigned_to_id);
        $this->assertSame(0, TicketActivityLog::where('ticket_id', $t->id)->count());
    }

    public function test_approve_with_scope_but_no_points_or_points_but_no_scope_is_refused(): void
    {
        $t = $this->ticket();
        $this->approve($t, ['assigned_to_id' => $this->assignee->id, 'task_scope' => 'simple'])
            ->assertStatus(422)->assertJsonPath('errors.task_points.0', self::MISSING);
        $this->approve($t, ['assigned_to_id' => $this->assignee->id, 'task_points' => 40])
            ->assertStatus(422)->assertJsonPath('errors.task_scope.0', self::MISSING);
        $this->assertSame('PENDING_APPROVAL', $t->fresh()->status);
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function outOfRange(): array
    {
        return [
            'simple below' => ['simple', 24], 'simple above' => ['simple', 51],
            'medium below' => ['medium', 74], 'medium above' => ['medium', 151],
            'large below' => ['large', 199], 'large above' => ['large', 301],
            'strategic below' => ['strategic', 299],
        ];
    }

    #[DataProvider('outOfRange')]
    public function test_approve_refuses_points_outside_the_scope_range(string $scope, int $points): void
    {
        $t = $this->ticket();

        $this->approve($t, ['assigned_to_id' => $this->assignee->id, 'task_scope' => $scope, 'task_points' => $points])
            ->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors' => ['task_points']]);

        $this->assertSame('PENDING_APPROVAL', $t->fresh()->status);
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function inRange(): array
    {
        return [
            'simple min' => ['simple', 25], 'simple max' => ['simple', 50],
            'medium min' => ['medium', 75], 'medium max' => ['medium', 150],
            'large min' => ['large', 200], 'large max' => ['large', 300],
            'strategic min' => ['strategic', 300], 'strategic is open ended' => ['strategic', 5000],
        ];
    }

    #[DataProvider('inRange')]
    public function test_approve_accepts_every_range_boundary_and_stores_the_estimate(string $scope, int $points): void
    {
        $t = $this->ticket();

        $this->approve($t, ['assigned_to_id' => $this->assignee->id, 'task_scope' => $scope, 'task_points' => $points, 'estimated_hours' => 2.5])
            ->assertOk()
            ->assertJsonPath('data.task_scope', $scope)
            ->assertJsonPath('data.task_points', $points)
            ->assertJsonPath('data.estimated_hours', 2.5)
            ->assertJsonPath('data.status', 'ASSIGNED');

        $fresh = $t->fresh();
        $this->assertSame($scope, $fresh->task_scope);
        $this->assertSame($points, $fresh->task_points);
        $this->assertSame($this->assignee->id, $fresh->assigned_to_id);
    }

    public function test_approve_validates_the_shape_of_each_field(): void
    {
        $t = $this->ticket();
        $base = ['assigned_to_id' => $this->assignee->id, 'task_scope' => 'simple', 'task_points' => 30];

        $this->approve($t, ['task_scope' => 'huge'] + $base)->assertStatus(422)->assertJsonValidationErrors(['task_scope']);
        $this->approve($t, ['task_points' => 'abc'] + $base)->assertStatus(422)->assertJsonValidationErrors(['task_points']);
        $this->approve($t, ['task_points' => 30.5] + $base)->assertStatus(422)->assertJsonValidationErrors(['task_points']);
        $this->approve($t, ['task_points' => 0] + $base)->assertStatus(422)->assertJsonValidationErrors(['task_points']);
        $this->approve($t, ['estimated_hours' => 0] + $base)->assertStatus(422)->assertJsonValidationErrors(['estimated_hours']);
        $this->approve($t, ['estimated_hours' => -1] + $base)->assertStatus(422)->assertJsonValidationErrors(['estimated_hours']);
        $this->approve($t, ['estimated_hours' => 1000.01] + $base)->assertStatus(422)->assertJsonValidationErrors(['estimated_hours']);
        $this->assertSame('PENDING_APPROVAL', $t->fresh()->status);

        $this->approve($t, ['estimated_hours' => 1000] + $base)->assertOk();
    }

    public function test_approve_without_an_assignee_still_works_without_points(): void
    {
        $t = $this->ticket();

        $this->approve($t)->assertOk()->assertJsonPath('data.status', 'ASSIGNED')->assertJsonPath('data.assigned_to_id', null)
            ->assertJsonPath('data.task_points', null)->assertJsonPath('data.task_scope', null);

        $this->assertNull($t->fresh()->task_points);
    }

    public function test_approve_without_an_assignee_refuses_a_half_given_estimate_but_keeps_a_valid_one(): void
    {
        $half = $this->ticket();
        $this->approve($half, ['task_points' => 40])->assertStatus(422)->assertJsonPath('errors.task_scope.0', self::MISSING);
        $this->assertSame('PENDING_APPROVAL', $half->fresh()->status);

        $full = $this->ticket();
        $this->approve($full, ['task_scope' => 'medium', 'task_points' => 100])->assertOk()->assertJsonPath('data.task_points', 100);
        $this->assertNull($full->fresh()->assigned_to_id);
    }

    // ═══ Reassign ═══════════════════════════════════════════════════════════

    public function test_reassigning_a_legacy_ticket_without_points_is_refused_and_nothing_changes(): void
    {
        $t = $this->ticket(['status' => 'IN_PROGRESS', 'assigned_to_id' => $this->member->id, 'accepted_at' => now()]);

        $this->reassign($t)
            ->assertStatus(422)
            ->assertJsonPath('message', self::MISSING)
            ->assertJsonPath('errors.task_points.0', self::MISSING);

        $fresh = $t->fresh();
        $this->assertSame($this->member->id, $fresh->assigned_to_id);
        $this->assertSame('IN_PROGRESS', $fresh->status);
        $this->assertNull($fresh->task_points, 'no value is ever invented');
    }

    public function test_reassign_accepts_the_estimate_in_the_request(): void
    {
        $t = $this->ticket(['status' => 'ASSIGNED', 'assigned_to_id' => $this->member->id]);

        $this->reassign($t, ['task_scope' => 'large', 'task_points' => 250, 'estimated_hours' => 6])
            ->assertOk()
            ->assertJsonPath('data.task_scope', 'large')
            ->assertJsonPath('data.task_points', 250)
            ->assertJsonPath('data.estimated_hours', 6)
            ->assertJsonPath('data.assigned_to_id', $this->assignee->id);

        // The estimate has its own (restricted) log row; the widely visible REASSIGNED row carries none of it.
        $this->assertStringNotContainsString('تقدير العمل', (string) TicketActivityLog::where('ticket_id', $t->id)->where('action', 'REASSIGNED')->value('details'));
        $log = TicketActivityLog::where('ticket_id', $t->id)->where('action', 'ESTIMATE_UPDATED')->sole();
        $this->assertStringContainsString('النقاط: 250', (string) $log->details);
    }

    public function test_reassign_reuses_the_stored_estimate_and_checks_a_changed_scope_against_it(): void
    {
        $t = $this->ticket(['status' => 'ASSIGNED', 'assigned_to_id' => $this->member->id, 'task_scope' => 'simple', 'task_points' => 30, 'estimated_hours' => 2]);

        // Stored values are enough.
        $this->reassign($t)->assertOk()->assertJsonPath('data.task_points', 30);

        // Changing only the scope leaves the stored points outside the new range: refused, nothing changes.
        $this->reassign($t, ['assigned_to_id' => $this->other->id, 'task_scope' => 'large'])->assertStatus(422)->assertJsonStructure(['errors' => ['task_points']]);
        $this->assertSame($this->assignee->id, $t->fresh()->assigned_to_id);
        $this->assertSame('simple', $t->fresh()->task_scope);
    }

    public function test_reassign_refuses_an_out_of_range_estimate(): void
    {
        $t = $this->ticket(['status' => 'ASSIGNED', 'assigned_to_id' => $this->member->id]);

        $this->reassign($t, ['task_scope' => 'simple', 'task_points' => 500])->assertStatus(422)->assertJsonStructure(['errors' => ['task_points']]);
        $this->assertSame($this->member->id, $t->fresh()->assigned_to_id);
    }

    // ═══ Bulk reassign ══════════════════════════════════════════════════════

    public function test_bulk_reassign_applies_the_rule_per_ticket_and_a_failing_ticket_fails_alone(): void
    {
        $withPoints = $this->ticket(['status' => 'ASSIGNED', 'assigned_to_id' => $this->member->id, 'task_scope' => 'medium', 'task_points' => 90, 'estimated_hours' => 3]);
        $legacy = $this->ticket(['status' => 'ASSIGNED', 'assigned_to_id' => $this->member->id]);

        Sanctum::actingAs($this->leader);
        $d = $this->postJson('/api/v1/tickets/bulk', ['action' => 'reassign', 'ticket_ids' => [$withPoints->id, $legacy->id], 'assigned_to_id' => $this->assignee->id])
            ->assertOk()->json('data');

        $this->assertSame(1, $d['succeeded']);
        $this->assertSame(1, $d['failed']);
        $byId = collect($d['results'])->keyBy('id');
        $this->assertTrue($byId[$withPoints->id]['ok']);
        $this->assertSame(90, $byId[$withPoints->id]['task_points']);
        $this->assertFalse($byId[$legacy->id]['ok']);
        $this->assertSame(self::MISSING, $byId[$legacy->id]['message']);
        $this->assertSame($this->assignee->id, $withPoints->fresh()->assigned_to_id);
        $this->assertSame($this->member->id, $legacy->fresh()->assigned_to_id, 'the refused one is untouched');
    }

    public function test_bulk_reassign_accepts_shared_estimate_parameters(): void
    {
        $a = $this->ticket(['status' => 'ASSIGNED', 'assigned_to_id' => $this->member->id]);
        $b = $this->ticket(['status' => 'IN_PROGRESS', 'assigned_to_id' => $this->member->id, 'accepted_at' => now()]);

        Sanctum::actingAs($this->leader);
        $this->postJson('/api/v1/tickets/bulk', [
            'action' => 'reassign', 'ticket_ids' => [$a->id, $b->id], 'assigned_to_id' => $this->assignee->id,
            'task_scope' => 'simple', 'task_points' => 40, 'estimated_hours' => 1.5,
        ])->assertOk()->assertJsonPath('data.succeeded', 2);

        foreach ([$a, $b] as $t) {
            $f = $t->fresh();
            $this->assertSame([$this->assignee->id, 'simple', 40], [$f->assigned_to_id, $f->task_scope, $f->task_points]);
            $this->assertEquals(1.5, $f->estimated_hours);
        }
    }

    public function test_bulk_reassign_refuses_an_inconsistent_shared_estimate_up_front(): void
    {
        $a = $this->ticket(['status' => 'ASSIGNED', 'assigned_to_id' => $this->member->id]);

        Sanctum::actingAs($this->leader);
        $this->postJson('/api/v1/tickets/bulk', ['action' => 'reassign', 'ticket_ids' => [$a->id], 'assigned_to_id' => $this->assignee->id, 'task_scope' => 'simple', 'task_points' => 999, 'estimated_hours' => 2])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['task_points']]);
        $this->postJson('/api/v1/tickets/bulk', ['action' => 'reassign', 'ticket_ids' => [$a->id], 'assigned_to_id' => $this->assignee->id, 'task_scope' => 'bogus'])
            ->assertStatus(422)->assertJsonValidationErrors(['task_scope']);
        $this->assertSame($this->member->id, $a->fresh()->assigned_to_id);
    }

    // ═══ PATCH /work-estimate ═══════════════════════════════════════════════

    public function test_a_leader_can_update_the_estimate_and_it_is_logged(): void
    {
        $t = $this->ticket(['status' => 'IN_PROGRESS', 'assigned_to_id' => $this->assignee->id, 'task_scope' => 'simple', 'task_points' => 30, 'estimated_hours' => 1]);

        $this->estimate($t, ['task_scope' => 'medium', 'task_points' => 100, 'estimated_hours' => 4.25])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.task_scope', 'medium')
            ->assertJsonPath('data.task_points', 100)
            ->assertJsonPath('data.estimated_hours', 4.25)
            ->assertJsonPath('data.capabilities.can_edit_estimate', true);

        $f = $t->fresh();
        $this->assertSame(['medium', 100], [$f->task_scope, $f->task_points]);
        $this->assertSame('IN_PROGRESS', $f->status, 'the status is not touched');
        $this->assertSame($this->assignee->id, $f->assigned_to_id);

        $log = TicketActivityLog::where('ticket_id', $t->id)->where('action', 'ESTIMATE_UPDATED')->sole();
        $this->assertSame($this->leader->id, (int) $log->performed_by);
        $this->assertStringContainsString('بسيطة', (string) $log->details, 'previous value is in the details');
        $this->assertStringContainsString('متوسطة', (string) $log->details, 'new value is in the details');
        $this->assertStringContainsString('النقاط: 30', (string) $log->details);
        $this->assertStringContainsString('النقاط: 100', (string) $log->details);
        // previous_state / new_state are string(50) columns: short codes only.
        $this->assertSame(['ESTIMATE:simple', 'ESTIMATE:medium'], [$log->previous_state, $log->new_state]);
    }

    public function test_hours_are_kept_when_absent_and_cleared_when_null(): void
    {
        $t = $this->ticket(['status' => 'ASSIGNED', 'assigned_to_id' => $this->assignee->id, 'task_scope' => 'simple', 'task_points' => 30, 'estimated_hours' => 2]);

        $this->estimate($t, ['task_scope' => 'simple', 'task_points' => 35])->assertOk()->assertJsonPath('data.estimated_hours', 2);
        $this->estimate($t, ['task_scope' => 'simple', 'task_points' => 35, 'estimated_hours' => null])->assertOk()->assertJsonPath('data.estimated_hours', null);
        $this->assertNull($t->fresh()->estimated_hours);
    }

    public function test_an_unchanged_estimate_writes_no_log_row(): void
    {
        $t = $this->ticket(['status' => 'ASSIGNED', 'assigned_to_id' => $this->assignee->id, 'task_scope' => 'simple', 'task_points' => 30]);

        $this->estimate($t, ['task_scope' => 'simple', 'task_points' => 30])->assertOk();
        $this->assertSame(0, TicketActivityLog::where('ticket_id', $t->id)->where('action', 'ESTIMATE_UPDATED')->count());
    }

    public function test_the_assignee_and_other_members_cannot_edit_the_estimate(): void
    {
        $t = $this->ticket(['status' => 'IN_PROGRESS', 'assigned_to_id' => $this->assignee->id, 'task_scope' => 'simple', 'task_points' => 30]);
        $body = ['task_scope' => 'strategic', 'task_points' => 900];

        $this->estimate($t, $body, $this->assignee)->assertForbidden();
        $this->estimate($t, $body, $this->member)->assertForbidden();
        $this->estimate($t, $body, $this->reporter)->assertForbidden(); // the submitter can view it, never manage it

        $outsider = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $this->estimate($t, $body, $outsider)->assertNotFound(); // cannot even see it: revealed nothing

        $this->assertSame(30, $t->fresh()->task_points);
        $this->assertSame(0, TicketActivityLog::where('ticket_id', $t->id)->count());
    }

    public function test_a_global_role_can_edit_the_estimate(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $t = $this->ticket(['status' => 'ASSIGNED', 'assigned_to_id' => $this->assignee->id]);

        $this->estimate($t, ['task_scope' => 'simple', 'task_points' => 30], $admin)->assertOk();
    }

    public function test_the_estimate_of_a_closed_ticket_is_refused_with_409(): void
    {
        foreach (['RESOLVED', 'UNRESOLVED', 'REJECTED_BY_ADMIN'] as $status) {
            $t = $this->ticket(['status' => $status, 'assigned_to_id' => $this->assignee->id, 'task_scope' => 'simple', 'task_points' => 30]);
            $this->estimate($t, ['task_scope' => 'simple', 'task_points' => 40])->assertStatus(409);
            $this->assertSame(30, $t->fresh()->task_points);
        }
    }

    public function test_work_estimate_validation_and_authentication(): void
    {
        $t = $this->ticket(['status' => 'ASSIGNED', 'assigned_to_id' => $this->assignee->id]);

        $this->estimate($t, [])->assertStatus(422)->assertJsonValidationErrors(['task_scope', 'task_points']);
        $this->estimate($t, ['task_scope' => 'simple', 'task_points' => 51])->assertStatus(422)->assertJsonStructure(['errors' => ['task_points']]);
        $this->estimate($t, ['task_scope' => 'nope', 'task_points' => 30])->assertStatus(422)->assertJsonValidationErrors(['task_scope']);
        $this->estimate($t, ['task_scope' => 'simple', 'task_points' => 30, 'estimated_hours' => 0])->assertStatus(422)->assertJsonValidationErrors(['estimated_hours']);
        $this->estimate($t, ['task_scope' => 'simple', 'task_points' => 30, 'estimated_hours' => 1001])->assertStatus(422)->assertJsonValidationErrors(['estimated_hours']);

        $this->app['auth']->forgetGuards();
        $this->patchJson("/api/v1/tickets/{$t->id}/work-estimate", ['task_scope' => 'simple', 'task_points' => 30])->assertUnauthorized();
        $this->assertNull($t->fresh()->task_points);
    }

    // ═══ Exposure ═══════════════════════════════════════════════════════════

    public function test_staff_payloads_expose_the_estimate_and_the_capability(): void
    {
        $t = $this->ticket(['status' => 'IN_PROGRESS', 'assigned_to_id' => $this->assignee->id, 'task_scope' => 'medium', 'task_points' => 100, 'estimated_hours' => 3]);

        Sanctum::actingAs($this->leader);
        $show = $this->getJson("/api/v1/tickets/{$t->id}")->assertOk();
        $show->assertJsonPath('data.task_scope', 'medium')->assertJsonPath('data.task_points', 100)->assertJsonPath('data.estimated_hours', 3)
            ->assertJsonPath('data.capabilities.can_edit_estimate', true);

        $row = collect($this->getJson('/api/v1/tickets?lite=1')->assertOk()->json('data.data'))->firstWhere('id', $t->id);
        $this->assertSame([100, 'medium'], [$row['task_points'], $row['task_scope']]);
        $this->assertTrue($row['capabilities']['can_edit_estimate']);

        $card = collect($this->getJson('/api/v1/tickets/board')->assertOk()->json('data.columns'))->firstWhere('status', 'IN_PROGRESS')['tickets'][0];
        $this->assertSame([100, 'medium'], [$card['task_points'], $card['task_scope']]);

        Sanctum::actingAs($this->member);
        $this->assertFalse($this->getJson("/api/v1/tickets/{$t->id}")->json('data.capabilities.can_edit_estimate'), 'a plain member cannot edit it');
    }

    public function test_can_edit_estimate_is_off_for_closed_tickets(): void
    {
        $t = $this->ticket(['status' => 'RESOLVED', 'assigned_to_id' => $this->assignee->id, 'task_scope' => 'simple', 'task_points' => 30]);

        Sanctum::actingAs($this->leader);
        $this->assertFalse($this->getJson("/api/v1/tickets/{$t->id}")->json('data.capabilities.can_edit_estimate'));
    }

    public function test_the_submitter_never_sees_the_estimate(): void
    {
        $t = $this->ticket(['status' => 'IN_PROGRESS', 'assigned_to_id' => $this->assignee->id, 'task_scope' => 'large', 'task_points' => 250, 'estimated_hours' => 8]);

        Sanctum::actingAs($this->reporter);

        $detail = $this->getJson("/api/v1/tickets/{$t->id}")->assertOk()->json('data');
        $this->assertSame('reporter', $detail['viewer_role']);
        foreach (['task_scope', 'task_points', 'estimated_hours'] as $key) {
            $this->assertArrayNotHasKey($key, $detail);
        }

        $list = $this->getJson('/api/v1/tickets')->assertOk()->json('data.data');
        $this->assertNotEmpty($list);
        foreach ($list as $row) {
            foreach (['task_scope', 'task_points', 'estimated_hours'] as $key) {
                $this->assertArrayNotHasKey($key, $row);
            }
            $this->assertFalse($row['capabilities']['can_edit_estimate']);
        }

        $card = collect($this->getJson('/api/v1/tickets/board')->assertOk()->json('data.columns'))->firstWhere('status', 'IN_PROGRESS')['tickets'][0];
        foreach (['task_scope', 'task_points', 'estimated_hours'] as $key) {
            $this->assertArrayNotHasKey($key, $card);
        }
    }

    public function test_creating_a_ticket_does_not_echo_the_planning_fields_to_the_submitter(): void
    {
        Sanctum::actingAs($this->reporter);
        $data = $this->postJson('/api/v1/tickets', [
            'title' => 'مشكلة', 'description' => 'وصف', 'ticket_category' => 'OLD_ISSUE', 'department_id' => $this->dept->id, 'created_by_name' => 'مُبلِّغ',
            // A submitter cannot plant an estimate either.
            'task_scope' => 'simple', 'task_points' => 30,
        ])->assertCreated()->json('data');

        foreach (['task_scope', 'task_points', 'estimated_hours'] as $key) {
            $this->assertArrayNotHasKey($key, $data);
        }
        $this->assertNull(Ticket::find($data['id'])->task_points);
    }

    // ═══ No credit, no Task rows ════════════════════════════════════════════

    public function test_assigning_accepting_and_completing_credit_nothing_and_create_no_task(): void
    {
        $t = $this->ticket();
        $awards = VolunteerPointAward::count();
        $tasks = Task::count();

        $this->approve($t, ['assigned_to_id' => $this->assignee->id, 'task_scope' => 'large', 'task_points' => 250, 'estimated_hours' => 5])->assertOk();

        Sanctum::actingAs($this->assignee);
        $this->postJson("/api/v1/tickets/{$t->id}/accept-task")->assertOk();
        $this->postJson("/api/v1/tickets/{$t->id}/complete-task", ['resolution_type' => 'RESOLVED', 'resolution_summary' => 'تم إصلاح المشكلة بالكامل', 'actual_minutes' => 60])->assertOk();

        $this->assertSame('PENDING_REVIEW', $t->fresh()->status, 'the assignee only submits the work for review');
        $this->assertSame($awards, VolunteerPointAward::count(), 'submitting for review credits nothing');
        $this->assertSame($tasks, Task::count(), 'a ticket never creates a Task row');
        $this->assertSame(250, $t->fresh()->task_points, 'the estimate stays an estimate');
    }

    // ═══ Concurrency-style double accept ════════════════════════════════════

    public function test_a_second_accept_of_the_same_ticket_is_refused_with_409(): void
    {
        $t = $this->ticket(['status' => 'ASSIGNED', 'assigned_to_id' => $this->assignee->id, 'task_scope' => 'simple', 'task_points' => 30]);

        Sanctum::actingAs($this->assignee);
        $this->postJson("/api/v1/tickets/{$t->id}/accept-task")->assertOk();
        $acceptedAt = $t->fresh()->accepted_at;

        // The "second tab": the row is re-read under lock, sees IN_PROGRESS and refuses.
        $this->postJson("/api/v1/tickets/{$t->id}/accept-task")->assertStatus(409);
        $this->postJson("/api/v1/tickets/{$t->id}/reject-task", ['assignee_rejection_reason' => 'لا أستطيع الآن'])->assertStatus(409);

        $this->assertEquals($acceptedAt, $t->fresh()->accepted_at, 'acceptance is not rewritten');
        $this->assertSame(1, TicketActivityLog::where('ticket_id', $t->id)->where('action', 'ACCEPTED_BY_ASSIGNEE')->count());
        $this->assertSame('IN_PROGRESS', $t->fresh()->status);
    }
}
