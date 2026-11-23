<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Review follow-ups for the ticket work estimate: short log codes, "null keeps hours", visibility of the
 * planning data, bounded decline text, minimum hours, and the shape of validation errors.
 */
class TicketEstimateReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = ['task_scope', 'task_points', 'estimated_hours'];

    private Department $dept;

    private User $leader;

    private User $member;

    private User $assignee;

    private User $other;

    private User $reporter;

    private User $admin;

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
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
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
            'ticket_number' => 'EMC-TCK-R-'.Str::random(8), 'title' => 'تذكرة '.Str::random(4), 'description' => 'وصف',
            'ticket_category' => 'OLD_ISSUE', 'priority' => 'MEDIUM', 'department_id' => $this->dept->id,
            'created_by_id' => $this->reporter->id, 'created_by_name' => 'مُبلِّغ', 'status' => 'PENDING_APPROVAL',
        ], $over));
    }

    private function as(User $u): static
    {
        Sanctum::actingAs($u);

        return $this;
    }

    private function estimated(array $over = []): Ticket
    {
        return $this->ticket(array_merge(['status' => 'ASSIGNED', 'assigned_to_id' => $this->assignee->id, 'task_scope' => 'medium', 'task_points' => 100, 'estimated_hours' => 4], $over));
    }

    // ═══ 1. string(50) columns get short codes only ═════════════════════════

    public function test_every_state_column_this_feature_writes_fits_string_50_for_the_largest_input(): void
    {
        $big = ['task_scope' => 'strategic', 'task_points' => 1000000, 'estimated_hours' => 1000];

        // approve (sets), PATCH (changes), reassign (changes), bulk reassign (changes) — each with maximal values.
        $t = $this->ticket();
        $this->as($this->leader)->postJson("/api/v1/tickets/{$t->id}/approve", ['sla_hours' => 24, 'assigned_to_id' => $this->assignee->id] + $big)->assertOk();
        $this->patchJson("/api/v1/tickets/{$t->id}/work-estimate", ['task_scope' => 'large', 'task_points' => 300, 'estimated_hours' => 999.99])->assertOk();
        $this->patchJson("/api/v1/tickets/{$t->id}/work-estimate", $big)->assertOk();
        $this->postJson("/api/v1/tickets/{$t->id}/reassign", ['assigned_to_id' => $this->other->id, 'task_scope' => 'simple', 'task_points' => 50, 'estimated_hours' => 0.01])->assertOk();
        $this->postJson('/api/v1/tickets/bulk', ['action' => 'reassign', 'ticket_ids' => [$t->id], 'assigned_to_id' => $this->assignee->id] + $big)->assertOk()->assertJsonPath('data.succeeded', 1);

        $rows = TicketActivityLog::where('ticket_id', $t->id)->get();
        $this->assertGreaterThanOrEqual(5, $rows->where('action', 'ESTIMATE_UPDATED')->count());
        foreach ($rows as $row) {
            foreach (['previous_state', 'new_state', 'action'] as $col) {
                $this->assertLessThanOrEqual(50, mb_strlen((string) $row->{$col}), "{$row->action}.{$col}");
            }
        }
        $latest = $rows->where('action', 'ESTIMATE_UPDATED')->sortByDesc('id')->first();
        $this->assertSame('ESTIMATE:strategic', $latest->new_state);
        $this->assertStringContainsString('النقاط: 1000000', (string) $latest->details, 'the readable text is in details');
    }

    // ═══ 2. null keeps the stored hours everywhere but PATCH ════════════════

    public function test_null_or_empty_hours_keep_the_stored_value_on_reassign_bulk_and_approve(): void
    {
        foreach ([null, ''] as $empty) {
            $t = $this->estimated();
            $this->as($this->leader)->postJson("/api/v1/tickets/{$t->id}/reassign", ['assigned_to_id' => $this->other->id, 'task_scope' => 'medium', 'task_points' => 110, 'estimated_hours' => $empty])->assertOk();
            $this->assertEquals(4, $t->fresh()->estimated_hours, 'reassign keeps the hours');

            $b = $this->estimated();
            $this->postJson('/api/v1/tickets/bulk', ['action' => 'reassign', 'ticket_ids' => [$b->id], 'assigned_to_id' => $this->other->id, 'estimated_hours' => $empty])->assertOk()->assertJsonPath('data.succeeded', 1);
            $this->assertEquals(4, $b->fresh()->estimated_hours, 'bulk keeps the hours');

            $p = $this->ticket(['estimated_hours' => 6]);
            $this->postJson("/api/v1/tickets/{$p->id}/approve", ['sla_hours' => 24, 'assigned_to_id' => $this->assignee->id, 'task_scope' => 'simple', 'task_points' => 30, 'estimated_hours' => $empty])->assertOk();
            $this->assertEquals(6, $p->fresh()->estimated_hours, 'approve keeps the hours');
        }
    }

    public function test_only_the_estimate_endpoint_clears_hours_with_an_explicit_null(): void
    {
        $t = $this->estimated();

        $this->as($this->leader)->patchJson("/api/v1/tickets/{$t->id}/work-estimate", ['task_scope' => 'medium', 'task_points' => 100, 'estimated_hours' => null])
            ->assertOk()->assertJsonPath('data.estimated_hours', null);
        $this->assertNull($t->fresh()->estimated_hours);
    }

    // ═══ 4. who may see the planning data ═══════════════════════════════════

    private function ticketWithEstimateHistory(): Ticket
    {
        $t = $this->ticket();
        $this->as($this->leader)->postJson("/api/v1/tickets/{$t->id}/approve", ['sla_hours' => 24, 'assigned_to_id' => $this->assignee->id, 'task_scope' => 'medium', 'task_points' => 100, 'estimated_hours' => 3])->assertOk();
        $this->patchJson("/api/v1/tickets/{$t->id}/work-estimate", ['task_scope' => 'large', 'task_points' => 250])->assertOk();

        return $t->fresh();
    }

    private function assertNoEstimate(array $payload, string $where): void
    {
        foreach (self::KEYS as $key) {
            $this->assertArrayNotHasKey($key, $payload, "{$where}: {$key}");
        }
    }

    private function assertHasEstimate(array $payload, string $where): void
    {
        $this->assertSame(['large', 250], [$payload['task_scope'] ?? null, $payload['task_points'] ?? null], $where);
    }

    /** @return array<int, string> */
    private function actions(array $payload): array
    {
        return collect($payload['activity_logs'] ?? $payload['activityLogs'] ?? [])->pluck('action')->all();
    }

    public function test_managers_global_roles_and_the_assignee_see_the_estimate_everywhere(): void
    {
        $t = $this->ticketWithEstimateHistory();

        foreach (['leader' => $this->leader, 'admin' => $this->admin, 'assignee' => $this->assignee] as $name => $user) {
            $this->as($user);
            $show = $this->getJson("/api/v1/tickets/{$t->id}")->assertOk()->json('data');
            $this->assertHasEstimate($show, "$name show");
            $this->assertContains('ESTIMATE_UPDATED', $this->actions($show), "$name sees the estimate history");

            foreach (['', '?lite=1'] as $q) {
                $row = collect($this->getJson('/api/v1/tickets'.$q)->assertOk()->json('data.data'))->firstWhere('id', $t->id);
                $this->assertHasEstimate($row, "$name index$q");
            }

            $card = collect(collect($this->getJson('/api/v1/tickets/board')->json('data.columns'))->firstWhere('status', 'ASSIGNED')['tickets'])->firstWhere('id', $t->id);
            $this->assertHasEstimate($card, "$name board");
        }

        $mine = collect($this->as($this->assignee)->getJson('/api/v1/tickets/my-work')->json('data'))->firstWhere('id', $t->id);
        $this->assertHasEstimate($mine, 'my-work');
    }

    public function test_a_plain_department_member_and_the_reporter_never_see_the_estimate_or_its_history(): void
    {
        $t = $this->ticketWithEstimateHistory();

        foreach (['member' => $this->member, 'other member' => $this->other] as $name => $user) {
            $this->as($user);
            $show = $this->getJson("/api/v1/tickets/{$t->id}")->assertOk()->json('data');
            $this->assertNoEstimate($show, "$name show");
            $this->assertNotContains('ESTIMATE_UPDATED', $this->actions($show));
            $this->assertContains('APPROVED_AND_ASSIGNED', $this->actions($show), 'the rest of the history is still readable');

            foreach (['', '?lite=1'] as $q) {
                $row = collect($this->getJson('/api/v1/tickets'.$q)->assertOk()->json('data.data'))->firstWhere('id', $t->id);
                $this->assertNotNull($row);
                $this->assertNoEstimate($row, "$name index$q");
            }

            $card = collect(collect($this->getJson('/api/v1/tickets/board')->json('data.columns'))->firstWhere('status', 'ASSIGNED')['tickets'])->firstWhere('id', $t->id);
            $this->assertNoEstimate($card, "$name board");
        }

        // No estimate text anywhere in the rows every member can read.
        foreach (['APPROVED_AND_ASSIGNED', 'REASSIGNED'] as $action) {
            foreach (TicketActivityLog::where('ticket_id', $t->id)->where('action', $action)->pluck('details') as $details) {
                $this->assertStringNotContainsString('تقدير العمل', (string) $details);
                $this->assertStringNotContainsString('النقاط', (string) $details);
            }
        }

        $this->as($this->reporter);
        $this->assertNoEstimate($this->getJson("/api/v1/tickets/{$t->id}")->json('data'), 'reporter show');
        $this->assertNotContains('ESTIMATE_UPDATED', collect($this->getJson("/api/v1/tickets/{$t->id}")->json('data.timeline'))->pluck('key')->all());
    }

    public function test_reassign_writes_a_separate_estimate_row_and_none_in_the_reassigned_row(): void
    {
        $t = $this->estimated();

        $this->as($this->leader)->postJson("/api/v1/tickets/{$t->id}/reassign", ['assigned_to_id' => $this->other->id, 'task_scope' => 'large', 'task_points' => 220])->assertOk();

        $this->assertSame(1, TicketActivityLog::where('ticket_id', $t->id)->where('action', 'ESTIMATE_UPDATED')->count());
        $this->assertStringNotContainsString('220', (string) TicketActivityLog::where('ticket_id', $t->id)->where('action', 'REASSIGNED')->value('details'));

        // An unchanged estimate on a later reassign writes no estimate row.
        $this->postJson("/api/v1/tickets/{$t->id}/reassign", ['assigned_to_id' => $this->assignee->id])->assertOk();
        $this->assertSame(1, TicketActivityLog::where('ticket_id', $t->id)->where('action', 'ESTIMATE_UPDATED')->count());
    }

    // ═══ 5. the decline reason is bounded ═══════════════════════════════════

    public function test_a_very_long_decline_reason_is_capped_on_input_and_in_the_reroute_log(): void
    {
        $t = $this->estimated();

        $this->as($this->assignee)->postJson("/api/v1/tickets/{$t->id}/reject-task", ['assignee_rejection_reason' => str_repeat('س', 1001)])
            ->assertStatus(422)->assertJsonValidationErrors(['assignee_rejection_reason']);
        $this->assertSame('ASSIGNED', $t->fresh()->status);

        $this->postJson("/api/v1/tickets/{$t->id}/reject-task", ['assignee_rejection_reason' => str_repeat('س', 1000)])->assertOk();

        $this->as($this->leader)->postJson("/api/v1/tickets/{$t->id}/reassign", ['assigned_to_id' => $this->other->id])->assertOk()->assertJsonPath('data.status', 'ASSIGNED');
        $details = (string) TicketActivityLog::where('ticket_id', $t->id)->where('action', 'REASSIGNED')->value('details');
        $this->assertStringContainsString('سبب اعتذار المكلف السابق', $details);
        $this->assertLessThan(600, mb_strlen($details), 'the apology is cut to 500 characters in the reroute row');
    }

    // ═══ 6. minimum hours ═══════════════════════════════════════════════════

    public function test_hours_that_round_to_zero_are_refused_everywhere(): void
    {
        $t = $this->estimated();
        $base = ['task_scope' => 'medium', 'task_points' => 100];

        foreach ([0.001, 0.004, 0.009] as $hours) {
            $this->as($this->leader)->patchJson("/api/v1/tickets/{$t->id}/work-estimate", $base + ['estimated_hours' => $hours])->assertStatus(422)->assertJsonStructure(['errors' => ['estimated_hours']]);
            $this->postJson("/api/v1/tickets/{$t->id}/reassign", $base + ['assigned_to_id' => $this->other->id, 'estimated_hours' => $hours])->assertStatus(422)->assertJsonValidationErrors(['estimated_hours']);
            $this->postJson('/api/v1/tickets/bulk', ['action' => 'reassign', 'ticket_ids' => [$t->id], 'assigned_to_id' => $this->other->id, 'estimated_hours' => $hours])->assertStatus(422)->assertJsonValidationErrors(['estimated_hours']);
        }
        $this->assertEquals(4, $t->fresh()->estimated_hours);

        $this->patchJson("/api/v1/tickets/{$t->id}/work-estimate", $base + ['estimated_hours' => 0.01])->assertOk();
        $this->assertEquals(0.01, $t->fresh()->estimated_hours);
    }

    // ═══ 7. error shapes ════════════════════════════════════════════════════

    public function test_the_approve_message_is_the_estimate_message_not_an_unrelated_field(): void
    {
        $t = $this->ticket();

        // `unit_id` fails first in rule order; the message must still be the estimate's.
        $res = $this->as($this->leader)->postJson("/api/v1/tickets/{$t->id}/approve", ['sla_hours' => 24, 'unit_id' => 999999, 'estimated_hours' => 0])->assertStatus(422);
        $this->assertSame($res->json('errors.estimated_hours.0'), $res->json('message'));
        $this->assertArrayHasKey('unit_id', $res->json('errors'));

        // A broken SLA keeps its own message.
        $sla = $this->postJson("/api/v1/tickets/{$t->id}/approve", ['estimated_hours' => 0])->assertStatus(422);
        $this->assertSame('الرجاء تحديد المهلة الزمنية للـ SLA بشكل صحيح', $sla->json('message'));
    }

    public function test_the_estimate_endpoint_uses_the_standard_ticket_action_error_body_for_shape_errors(): void
    {
        $t = $this->estimated();

        $this->as($this->leader)->patchJson("/api/v1/tickets/{$t->id}/work-estimate", ['task_scope' => 'nope', 'task_points' => 30])
            ->assertStatus(422)->assertJsonPath('success', false)->assertJsonStructure(['success', 'message', 'errors' => ['task_scope']]);
        $this->patchJson("/api/v1/tickets/{$t->id}/work-estimate", [])
            ->assertStatus(422)->assertJsonPath('success', false)->assertJsonPath('message', 'حدّد نقاط المهمة وحجمها قبل الإسناد');
    }
}
