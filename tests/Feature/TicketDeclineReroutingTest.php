<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketActivityLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The real decline workflow: approve -> assignee declines -> the manager routes it again to someone else.
 * A declined ticket (REJECTED_BY_ASSIGNEE) must never be a dead end.
 */
class TicketDeclineReroutingTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'مشغول بتسليم مشروع آخر هذا الأسبوع';

    private Department $dept;

    private User $leader;

    private User $alice;

    private User $bob;

    private User $reporter;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-22 12:00:00');

        $this->dept = Department::create(['name' => 'D '.Str::random(5), 'name_ar' => 'إدارة التقنية', 'slug' => 'd-'.Str::random(8), 'status' => 'active', 'is_active' => true]);
        $this->leader = User::factory()->create(['role' => 'programs_manager', 'is_active' => true]);
        $this->dept->update(['leader_id' => $this->leader->id]);
        $this->alice = $this->member();
        $this->bob = $this->member();
        $this->reporter = User::factory()->create(['role' => 'student', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(): User
    {
        $u = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $this->dept->users()->attach($u->id);

        return $u;
    }

    private function ticket(array $over = []): Ticket
    {
        return Ticket::create(array_merge([
            'ticket_number' => 'EMC-TCK-D-'.Str::random(8), 'title' => 'تذكرة '.Str::random(4), 'description' => 'وصف',
            'ticket_category' => 'OLD_ISSUE', 'priority' => 'HIGH', 'department_id' => $this->dept->id,
            'created_by_id' => $this->reporter->id, 'created_by_name' => 'مُبلِّغ', 'status' => 'PENDING_APPROVAL',
        ], $over));
    }

    private function as(User $u): static
    {
        Sanctum::actingAs($u);

        return $this;
    }

    /** @return array<int, array<string, mixed>> */
    private function myWork(User $u, string $q = ''): array
    {
        return $this->as($u)->getJson('/api/v1/tickets/my-work'.$q)->assertOk()->json('data');
    }

    private function declined(): Ticket
    {
        $t = $this->ticket();
        $this->as($this->leader)->postJson("/api/v1/tickets/{$t->id}/approve", ['sla_hours' => 24, 'assigned_to_id' => $this->alice->id, 'task_scope' => 'medium', 'task_points' => 100, 'estimated_hours' => 3])->assertOk();
        $this->as($this->alice)->postJson("/api/v1/tickets/{$t->id}/reject-task", ['assignee_rejection_reason' => self::REASON])->assertOk();

        return $t->fresh();
    }

    public function test_the_whole_decline_and_reroute_flow_follows_the_real_workflow(): void
    {
        $t = $this->declined();
        $this->assertSame('REJECTED_BY_ASSIGNEE', $t->status);

        // The decliner: gone from the pending/active part of the screen, present in the closed column, no actions.
        $row = collect($this->myWork($this->alice))->firstWhere('id', $t->id);
        $this->assertSame('REJECTED_BY_ASSIGNEE', $row['status']);
        $this->assertSame(['can_accept' => false, 'can_reject' => false, 'can_complete' => false, 'can_open' => true], $row['capabilities']);
        $this->assertSame([], $this->myWork($this->alice, '?status=ASSIGNED'));
        $this->assertSame([], $this->myWork($this->bob));

        // The manager can see it waiting and act on it.
        $this->as($this->leader);
        $show = $this->getJson("/api/v1/tickets/{$t->id}")->assertOk();
        $this->assertTrue($show->json('data.capabilities.reassign'));
        $column = collect($this->getJson('/api/v1/tickets/board')->json('data.columns'))->firstWhere('status', 'REJECTED_BY_ASSIGNEE');
        $this->assertSame($t->id, $column['tickets'][0]['id']);
        $this->assertTrue($column['tickets'][0]['capabilities']['reassign']);
        $this->assertContains($t->id, collect($this->getJson('/api/v1/tickets?attention=1')->json('data.data'))->pluck('id')->all());
        $this->assertSame('assignment_declined', collect($this->getJson('/api/v1/tickets/insights')->json('data'))->firstWhere('ticket_id', $t->id)['rule']);

        // Reroute (the stored estimate is reused).
        $this->postJson("/api/v1/tickets/{$t->id}/reassign", ['assigned_to_id' => $this->bob->id])
            ->assertOk()->assertJsonPath('data.status', 'ASSIGNED')->assertJsonPath('data.assigned_to_id', $this->bob->id)
            ->assertJsonPath('data.task_points', 100)->assertJsonPath('data.assignee_rejection_reason', null);

        $fresh = $t->fresh();
        $this->assertNull($fresh->accepted_at);
        $this->assertNull($fresh->assignee_rejection_reason, 'the previous assignee\'s apology is not left on the new assignee\'s task');

        // The apology stays in the history, on the decline row and on the reroute row.
        $this->assertStringContainsString(self::REASON, (string) TicketActivityLog::where('ticket_id', $t->id)->where('action', 'REJECTED_BY_ASSIGNEE')->value('details'));
        $reroute = (string) TicketActivityLog::where('ticket_id', $t->id)->where('action', 'REASSIGNED')->value('details');
        $this->assertStringContainsString('سبب اعتذار المكلف السابق: '.self::REASON, $reroute);

        // The decliner no longer has it ANYWHERE; the new assignee has it pending receipt.
        $this->assertSame([], $this->myWork($this->alice));
        $bobRows = $this->myWork($this->bob);
        $this->assertCount(1, $bobRows);
        $this->assertSame(['ASSIGNED', true], [$bobRows[0]['status'], $bobRows[0]['capabilities']['can_accept']]);

        // And the normal path continues from there.
        $this->as($this->bob)->postJson("/api/v1/tickets/{$t->id}/accept-task")->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');
    }

    public function test_a_declined_ticket_stays_in_the_decliners_closed_column_for_30_days_only(): void
    {
        $t = $this->declined();
        $this->assertSame([$t->id], collect($this->myWork($this->alice))->pluck('id')->all());

        DB::table('tickets')->where('id', $t->id)->update(['updated_at' => now()->subDays(29)]);
        $this->assertSame([$t->id], collect($this->myWork($this->alice))->pluck('id')->all());

        DB::table('tickets')->where('id', $t->id)->update(['updated_at' => now()->subDays(31)]);
        $this->assertSame([], $this->myWork($this->alice));
    }

    public function test_only_a_manager_can_reroute_a_declined_ticket(): void
    {
        $t = $this->declined();

        foreach ([$this->alice, $this->bob, $this->reporter] as $user) {
            $this->as($user)->postJson("/api/v1/tickets/{$t->id}/reassign", ['assigned_to_id' => $this->bob->id, 'task_scope' => 'simple', 'task_points' => 30])->assertForbidden();
        }
        $this->assertSame('REJECTED_BY_ASSIGNEE', $t->fresh()->status);
        $this->assertSame($this->alice->id, $t->fresh()->assigned_to_id);
    }

    public function test_handing_a_declined_ticket_straight_back_to_the_decliner_is_refused(): void
    {
        $t = $this->declined();

        $this->as($this->leader)->postJson("/api/v1/tickets/{$t->id}/reassign", ['assigned_to_id' => $this->alice->id])
            ->assertStatus(422)->assertJsonStructure(['success', 'message', 'errors' => ['assigned_to_id']]);
        $this->assertSame('REJECTED_BY_ASSIGNEE', $t->fresh()->status);
    }

    public function test_the_estimate_rule_applies_to_a_declined_legacy_ticket_too(): void
    {
        $legacy = $this->ticket(['status' => 'REJECTED_BY_ASSIGNEE', 'assigned_to_id' => $this->alice->id, 'assignee_rejection_reason' => self::REASON]);

        $this->as($this->leader)->postJson("/api/v1/tickets/{$legacy->id}/reassign", ['assigned_to_id' => $this->bob->id])
            ->assertStatus(422)->assertJsonPath('message', 'حدّد نقاط المهمة وحجمها قبل الإسناد');
        $this->assertSame('REJECTED_BY_ASSIGNEE', $legacy->fresh()->status);
        $this->assertSame(self::REASON, $legacy->fresh()->assignee_rejection_reason, 'a refused reroute changes nothing');

        $this->postJson("/api/v1/tickets/{$legacy->id}/reassign", ['assigned_to_id' => $this->bob->id, 'task_scope' => 'simple', 'task_points' => 40])->assertOk();
        $this->assertSame([$legacy->id], collect($this->myWork($this->bob))->pluck('id')->all());
    }

    public function test_bulk_reassign_handles_declined_tickets_and_each_fails_alone(): void
    {
        $a = $this->declined();
        $b = $this->declined();
        $legacy = $this->ticket(['status' => 'REJECTED_BY_ASSIGNEE', 'assigned_to_id' => $this->alice->id]);

        // Bob: a and b succeed from stored estimates, the legacy one has none.
        $d = $this->as($this->leader)->postJson('/api/v1/tickets/bulk', ['action' => 'reassign', 'ticket_ids' => [$a->id, $b->id, $legacy->id], 'assigned_to_id' => $this->bob->id])
            ->assertOk()->json('data');
        $this->assertSame([2, 1], [$d['succeeded'], $d['failed']]);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], collect($this->myWork($this->bob))->pluck('id')->all());
        $this->assertSame([$legacy->id], collect($this->myWork($this->alice))->pluck('id')->all(), 'only the refused one is still hers');

        // The same person again is refused alone, with shared estimate parameters the legacy one now goes through.
        $d = $this->as($this->leader)->postJson('/api/v1/tickets/bulk', ['action' => 'reassign', 'ticket_ids' => [$legacy->id], 'assigned_to_id' => $this->alice->id, 'task_scope' => 'simple', 'task_points' => 30])
            ->assertOk()->json('data');
        $this->assertSame(0, $d['succeeded']);
    }

    public function test_a_declined_ticket_is_not_counted_as_the_decliners_open_load_in_suggestions(): void
    {
        $t = $this->declined();

        $candidates = collect($this->as($this->leader)->getJson("/api/v1/tickets/{$t->id}/assignment-suggestions")->assertOk()->json('data.candidates'));
        $this->assertSame(0, $candidates->firstWhere('id', $this->alice->id)['open_tickets']);
    }
}
