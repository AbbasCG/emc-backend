<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use App\Models\VolunteerPointAward;
use App\Models\VolunteerProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regular tasks credit exactly once: a replayed hr-approve, or a repeated evaluation of an already completed task,
 * must not add the volunteer hours again (the points ledger already deduplicated; the hours did not). The first call of
 * each endpoint behaves exactly as before.
 */
class TaskCreditIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $volunteer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->volunteer = User::factory()->create(['role' => 'student', 'is_active' => true]);
    }

    private function task(string $scope, string $status, array $over = []): Task
    {
        return Task::create(array_merge([
            'title' => 'مهمة', 'description' => 'x', 'task_scope' => $scope, 'points' => $scope === Task::SCOPE_SIMPLE ? 30 : 100,
            'estimated_hours' => 2, 'assigned_to' => $this->volunteer->id, 'created_by' => $this->admin->id, 'status' => $status, 'priority' => 'medium',
        ], $over));
    }

    private function hours(): float
    {
        return (float) (VolunteerProfile::where('user_id', $this->volunteer->id)->value('hours_logged') ?? 0);
    }

    private function awards(Task $t): int
    {
        return VolunteerPointAward::where('source_type', Task::class)->where('source_id', $t->id)->count();
    }

    private function evaluate(Task $t)
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson("/api/admin/tasks/{$t->id}/evaluate", ['delivered_on_time' => 5, 'quality_of_outputs' => 4, 'autonomy' => 4, 'responsiveness' => 5, 'collaboration_na' => true]);
    }

    private function hrApprove(Task $t)
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson("/api/admin/tasks/{$t->id}/hr-approve");
    }

    public function test_the_first_hr_approval_credits_as_before_and_a_replay_changes_nothing(): void
    {
        // awaiting HR means evaluated + manager-approved (hr-approve now requires it; previously this fixture skipped manager_approved_at)
        $t = $this->task(Task::SCOPE_MEDIUM, 'waiting_approval', ['manager_approved_at' => now()]);

        $first = $this->hrApprove($t)->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('message', 'تم اعتماد المهمة نهائياً من الموارد البشرية وإضافة النقاط وساعات التطوع التراكمية.');
        $this->assertSame('completed', $t->fresh()->status);
        $this->assertSame(1, $this->awards($t));
        $this->assertEqualsWithDelta(2.0, $this->hours(), 0.001);
        $approvedAt = $t->fresh()->hr_approved_at;

        $replay = $this->hrApprove($t)->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('message', 'تم اعتماد المهمة نهائياً من الموارد البشرية وإضافة النقاط وساعات التطوع التراكمية.');
        $this->assertSame($first->json('data.status'), $replay->json('data.status'));

        $this->assertSame(1, $this->awards($t));
        $this->assertEqualsWithDelta(2.0, $this->hours(), 0.001, 'hours are added once');
        $this->assertEquals($approvedAt, $t->fresh()->hr_approved_at, 'the approval time is not rewritten');
    }

    public function test_a_repeated_evaluation_of_a_completed_simple_task_credits_once(): void
    {
        $t = $this->task(Task::SCOPE_SIMPLE, 'in_progress');

        $this->evaluate($t)->assertOk()->assertJsonPath('message', 'تم التقييم والاعتماد الفوري وإضافة النقاط وساعات التطوع لرصيد المتطوع.');
        $this->assertSame('completed', $t->fresh()->status);
        $this->assertSame(1, $this->awards($t));
        $this->assertEqualsWithDelta(2.0, $this->hours(), 0.001);

        $this->evaluate($t)->assertOk()->assertJsonPath('message', 'تم التقييم والاعتماد الفوري وإضافة النقاط وساعات التطوع لرصيد المتطوع.');
        $this->evaluate($t)->assertOk();

        $this->assertSame(1, $this->awards($t));
        $this->assertEqualsWithDelta(2.0, $this->hours(), 0.001, 'hours are added once');
        $this->assertSame('completed', $t->fresh()->status);
    }

    public function test_evaluating_a_completed_task_never_sends_it_back_to_waiting_for_approval(): void
    {
        $t = $this->task(Task::SCOPE_MEDIUM, 'in_progress');

        $this->evaluate($t)->assertOk();
        $this->assertSame('waiting_approval', $t->fresh()->status, 'normal flow unchanged: above simple waits for HR');
        $this->assertSame(0, $this->awards($t));

        $this->hrApprove($t)->assertOk();
        $this->evaluate($t)->assertOk();
        $this->assertSame('completed', $t->fresh()->status);

        $this->hrApprove($t)->assertOk();
        $this->assertSame(1, $this->awards($t));
        $this->assertEqualsWithDelta(2.0, $this->hours(), 0.001);
    }

    public function test_a_simple_task_completed_by_evaluation_is_not_credited_again_by_hr_approve(): void
    {
        $t = $this->task(Task::SCOPE_SIMPLE, 'in_progress');

        $this->evaluate($t)->assertOk();
        $this->hrApprove($t)->assertOk();

        $this->assertSame(1, $this->awards($t));
        $this->assertEqualsWithDelta(2.0, $this->hours(), 0.001);
    }
}
