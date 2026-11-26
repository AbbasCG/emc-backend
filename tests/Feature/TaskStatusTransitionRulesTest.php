<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use App\Models\VolunteerPointAward;
use App\Models\VolunteerProfile;
use App\Services\TaskTransitionRules;
use App\Services\TaskWorkflowService;
use Database\Seeders\ImpersonatePermissionSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\GrantsTicketReviewPermissions;
use Tests\TestCase;

/**
 * The workflow behind a task's status: ... -> needs_evaluation -> (manager evaluates) -> [simple: completed | other sizes:
 * waiting_approval -> (HR approves) -> completed], with the volunteer's hours and points credited ONCE, by the evaluation
 * (simple) or the HR approval. `waiting_approval` and `completed` are those flows' results, so no manual status write
 * (the board's move, the edit form, «مهامي», creating a task) may record them or take a task back out of them
 * (App\Services\TaskTransitionRules). A refusal changes nothing and credits nothing.
 */
class TaskStatusTransitionRulesTest extends TestCase
{
    use GrantsTicketReviewPermissions;
    use RefreshDatabase;

    private User $admin;

    private User $leader;     // hr_manager leading deptHr, holds tasks.update

    private User $hr;         // another HR manager: approves
    private User $member;

    private Department $deptHr;

    private Department $deptOther;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, ImpersonatePermissionSeeder::class]);

        $this->admin = $this->user('admin');
        $this->leader = $this->user('hr_manager');
        $this->hr = $this->user('hr_manager');
        $this->member = $this->user('volunteer');
        $this->deptHr = $this->dept($this->leader);
        $this->deptOther = $this->dept($this->user('department_manager'));
        $this->deptHr->users()->attach($this->member->id);
        $this->grantToRole('hr_manager', 'tasks.update');
        $this->leader = $this->leader->fresh();
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true, 'password' => bcrypt('secret123')]);
    }

    private function dept(User $leader): Department
    {
        return Department::create(['name' => 'D '.Str::random(6), 'name_ar' => 'إدارة', 'slug' => 'd-'.Str::random(8), 'status' => 'active', 'is_active' => true, 'leader_id' => $leader->id]);
    }

    private function task(array $over = []): Task
    {
        return Task::create(array_merge([
            'title' => 'مهمة', 'task_scope' => Task::SCOPE_SIMPLE, 'points' => 30, 'estimated_hours' => 2, 'department_id' => $this->deptHr->id,
            'assigned_to' => $this->member->id, 'created_by' => $this->admin->id, 'priority' => 'medium', 'status' => 'in_progress', 'acceptance_status' => 'accepted',
        ], $over));
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh());

        return $this;
    }

    private function hours(): float
    {
        return (float) (VolunteerProfile::where('user_id', $this->member->id)->value('hours_logged') ?? 0);
    }

    private function awards(?Task $task = null): int
    {
        return VolunteerPointAward::where('source_type', Task::class)->when($task, fn ($q) => $q->where('source_id', $task->id))->count();
    }

    private function logs(Task $task): int
    {
        return ActivityLog::where('action', 'task.status_changed')->where('entity_id', $task->id)->count();
    }

    private function evaluate(Task $task)
    {
        return $this->postJson("/api/admin/tasks/{$task->id}/evaluate", ['delivered_on_time' => 5, 'quality_of_outputs' => 4, 'autonomy' => 4, 'responsiveness' => 5, 'collaboration_na' => true]);
    }

    /** Every route that writes a status by hand, as [label, callable($task, $status)]. */
    private function manualWrites(): array
    {
        return [
            'board move' => fn (Task $t, string $s) => $this->patchJson("/api/operations/board/tasks/{$t->id}/status", ['status' => $s]),
            'edit form (operations)' => fn (Task $t, string $s) => $this->patchJson("/api/operations/tasks/{$t->id}", ['status' => $s]),
            'edit form (admin)' => fn (Task $t, string $s) => $this->patchJson("/api/admin/tasks/{$t->id}", ['status' => $s]),
        ];
    }

    // ═══ 1. The legitimate flow is untouched ═════════════════════════════════

    public function test_the_member_delivers_the_manager_evaluates_and_a_simple_task_is_credited_once(): void
    {
        $task = $this->task(['status' => 'planning']);

        $this->as($this->member)->patchJson("/api/my-work/tasks/{$task->id}/status", ['status' => 'in_progress'])->assertOk();
        $this->patchJson("/api/my-work/tasks/{$task->id}/status", ['status' => 'needs_evaluation'])->assertOk()->assertJsonPath('data.status', 'needs_evaluation');
        $this->assertSame([0.0, 0], [$this->hours(), $this->awards()]);

        $this->as($this->admin);
        $this->evaluate($task)->assertOk();
        $fresh = $task->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertNotNull($fresh->manager_approved_at);
        $this->assertSame([2.0, 1], [$this->hours(), $this->awards($task)]);
    }

    public function test_a_larger_task_waits_for_hr_after_the_evaluation_and_is_credited_once_by_the_approval(): void
    {
        $task = $this->task(['task_scope' => Task::SCOPE_MEDIUM, 'points' => 100, 'status' => 'needs_evaluation']);

        $this->as($this->admin);
        $this->evaluate($task)->assertOk();
        $this->assertSame('waiting_approval', $task->fresh()->status);
        $this->assertSame([0.0, 0], [$this->hours(), $this->awards()], 'nothing is credited before HR approves');

        $this->as($this->hr)->postJson("/api/admin/tasks/{$task->id}/hr-approve")->assertOk();
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertSame([2.0, 1], [$this->hours(), $this->awards($task)]);
    }

    public function test_working_statuses_still_move_freely_and_writing_the_current_status_is_not_a_change(): void
    {
        $task = $this->task(['status' => 'planning']);
        $this->as($this->admin);
        foreach (['idea', 'under_review', 'in_progress', 'postponed', 'cancelled', 'needs_evaluation'] as $status) {
            foreach ($this->manualWrites() as $write) {
                $write($task, $status)->assertOk();
                $this->assertSame($status, $task->fresh()->status);
            }
        }

        // an edit that re-sends the stored status of an evaluated task (the form sends the whole record) is not a change
        $done = $this->task(['status' => 'completed']);
        $this->patchJson("/api/operations/tasks/{$done->id}", ['title' => 'عنوان جديد', 'status' => 'completed'])->assertOk();
        $this->assertSame('عنوان جديد', $done->fresh()->title);
    }

    // ═══ 2. No hand-written outcome ══════════════════════════════════════════

    public function test_no_route_writes_waiting_approval_or_completed_by_hand_and_a_refusal_changes_and_credits_nothing(): void
    {
        $actors = ['global admin' => $this->admin, 'department leader with tasks.update' => $this->leader];

        foreach ($actors as $label => $actor) {
            $this->as($actor);
            foreach (['waiting_approval', 'completed'] as $status) {
                foreach ($this->manualWrites() as $route => $write) {
                    $task = $this->task();
                    $before = $this->logs($task);
                    $write($task, $status)->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['status']]);
                    $fresh = $task->fresh();
                    $this->assertSame('in_progress', $fresh->status, "{$label} / {$route} / {$status}");
                    $this->assertNull($fresh->manager_approved_at);
                    $this->assertSame($before, $this->logs($task), 'no status change is logged');
                }
            }
        }
        $this->assertSame([0.0, 0], [$this->hours(), $this->awards()]);

        // the member cannot either, on either of their routes
        $own = $this->task();
        $this->as($this->member);
        $this->patchJson("/api/my-work/tasks/{$own->id}/status", ['status' => 'waiting_approval'])->assertStatus(422);
        $this->patchJson("/api/operations/board/tasks/{$own->id}/status", ['status' => 'waiting_approval'])->assertStatus(422);
        $this->assertSame('in_progress', $own->fresh()->status);
    }

    public function test_a_task_cannot_be_created_already_awaiting_approval_or_completed(): void
    {
        $this->as($this->admin);
        foreach (['waiting_approval', 'completed'] as $status) {
            foreach (['/api/operations/board/tasks', '/api/admin/tasks', '/api/operations/tasks'] as $url) {
                $this->postJson($url, ['title' => 'x', 'department_id' => $this->deptHr->id, 'status' => $status])->assertStatus(422)->assertJsonStructure(['errors' => ['status']]);
            }
        }
        $this->assertSame(0, Task::where('title', 'x')->count());

        $this->postJson('/api/operations/board/tasks', ['title' => 'x', 'department_id' => $this->deptHr->id, 'status' => 'in_progress'])->assertCreated();
        $this->assertSame(1, Task::where('title', 'x')->count());
    }

    public function test_a_skipped_evaluation_can_still_be_done_properly_and_is_then_credited_once(): void
    {
        // a task that an earlier release left "awaiting approval" with no evaluation: the evaluation is the way out, never a status write
        $task = $this->task(['task_scope' => Task::SCOPE_MEDIUM, 'points' => 100, 'status' => 'waiting_approval']);

        $this->as($this->hr)->postJson("/api/admin/tasks/{$task->id}/hr-approve")->assertStatus(422);
        $this->assertSame('waiting_approval', $task->fresh()->status);

        $this->as($this->admin);
        $this->evaluate($task)->assertOk();
        $this->as($this->hr)->postJson("/api/admin/tasks/{$task->id}/hr-approve")->assertOk();
        $this->assertSame([2.0, 1], [$this->hours(), $this->awards($task)]);
    }

    // ═══ 3. No re-opening of an evaluated / approved / completed task ════════

    public function test_an_evaluated_approved_or_completed_task_is_not_reopened_from_any_route_and_nothing_is_credited(): void
    {
        $cases = [
            'awaiting HR' => ['status' => 'waiting_approval', 'manager_approved_at' => now()],
            'completed' => ['status' => 'completed', 'manager_approved_at' => now(), 'hr_approved_at' => now()],
            'approved but moved off waiting_approval' => ['status' => 'in_progress', 'manager_approved_at' => now()],
        ];

        foreach ($cases as $label => $state) {
            foreach (['global admin' => $this->admin, 'department leader' => $this->leader, 'the assignee' => $this->member] as $who => $actor) {
                $this->as($actor);
                // «مهامي» is the assignee's own route (a task that is not theirs is simply not found there)
                $writes = $this->manualWrites() + ($actor->is($this->member) ? ['my work' => fn (Task $t, string $s) => $this->patchJson("/api/my-work/tasks/{$t->id}/status", ['status' => $s])] : []);
                foreach ($writes as $route => $write) {
                    $task = $this->task($state);
                    $stored = $task->fresh()->only(['status', 'manager_approved_at', 'hr_approved_at']);
                    $res = $write($task, $state['status'] === 'in_progress' ? 'planning' : 'in_progress');
                    $this->assertContains($res->status(), [403, 422], "{$label} / {$who} / {$route} must be refused, got {$res->status()}");
                    if ($who === 'global admin') {
                        $this->assertSame(422, $res->status(), "{$label} / {$route}: only the workflow rule stands between a global actor and the re-opening");
                    }
                    $this->assertEquals($stored, $task->fresh()->only(['status', 'manager_approved_at', 'hr_approved_at']), "{$label} / {$who} / {$route} left it as it was");
                }
            }
        }
        $this->assertSame([0.0, 0], [$this->hours(), $this->awards()]);
    }

    public function test_the_global_tier_cannot_reopen_a_credited_task_either_and_the_member_cannot_take_back_an_evaluation(): void
    {
        $task = $this->task(['task_scope' => Task::SCOPE_MEDIUM, 'points' => 100, 'status' => 'needs_evaluation']);
        $this->as($this->admin);
        $this->evaluate($task)->assertOk();
        $approvedAt = $task->fresh()->manager_approved_at;

        // the assignee takes it back: refused, the evaluation stays standing
        $this->as($this->member)->patchJson("/api/my-work/tasks/{$task->id}/status", ['status' => 'in_progress'])->assertStatus(422);
        $this->assertEquals([$approvedAt, 'waiting_approval'], [$task->fresh()->manager_approved_at, $task->fresh()->status]);

        // HR approves; a global actor cannot reopen the credited task through the status routes
        $this->as($this->hr)->postJson("/api/admin/tasks/{$task->id}/hr-approve")->assertOk();
        $this->as($this->admin)->patchJson("/api/operations/tasks/{$task->id}", ['status' => 'in_progress'])->assertStatus(422);
        $this->patchJson("/api/operations/board/tasks/{$task->id}/status", ['status' => 'in_progress'])->assertStatus(422);
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertSame([2.0, 1], [$this->hours(), $this->awards($task)], 'one credit, whatever was attempted');
    }

    // ═══ 3b. The evaluation is the step after delivery ═══════════════════════

    public function test_a_task_that_is_cancelled_postponed_not_started_or_not_accepted_is_not_evaluated_or_credited(): void
    {
        $this->as($this->admin);
        $cases = [
            'cancelled' => ['status' => 'cancelled'],
            'postponed' => ['status' => 'postponed'],
            'idea' => ['status' => 'idea'],
            'planning' => ['status' => 'planning'],
            'pending acceptance' => ['acceptance_status' => 'pending'],
            'rejected' => ['acceptance_status' => 'rejected'],
        ];
        foreach ($cases as $label => $state) {
            $task = $this->task($state);
            $this->evaluate($task)->assertStatus(422);
            $fresh = $task->fresh();
            $this->assertSame([$state['status'] ?? 'in_progress', null], [$fresh->status, $fresh->manager_approved_at], $label);
            $this->assertNull($fresh->evaluation, $label);
        }
        $this->assertSame([0.0, 0], [$this->hours(), $this->awards()]);

        // the working and delivered statuses are evaluated as before
        foreach (['in_progress', 'needs_evaluation'] as $status) {
            $this->evaluate($this->task(['status' => $status]))->assertOk();
        }
        $this->assertSame([4.0, 2], [$this->hours(), $this->awards()]);
    }

    public function test_nobody_evaluates_a_task_assigned_to_themselves(): void
    {
        $manager = $this->user('operations_manager');
        $own = $this->task(['assigned_to' => $manager->id]);

        $this->as($manager);
        $this->evaluate($own)->assertForbidden();
        $this->assertSame('in_progress', $own->fresh()->status);
        $this->assertSame([0.0, 0], [$this->hours(), $this->awards()]);
    }

    public function test_a_task_set_to_completed_by_hand_before_this_rule_is_credited_once_by_the_real_evaluation(): void
    {
        // legacy data: completed with no evaluation on record and no credit (the old routes allowed it). The evaluation, not a
        // re-opening, is the way to credit it; and it is credited exactly once.
        $legacy = $this->task(['status' => 'completed']);
        $this->assertNull($legacy->manager_approved_at);

        $this->as($this->admin);
        $this->patchJson("/api/operations/board/tasks/{$legacy->id}/status", ['status' => 'in_progress'])->assertStatus(422);
        $this->evaluate($legacy)->assertOk();
        $this->assertSame(['completed', 1, 2.0], [$legacy->fresh()->status, $this->awards($legacy), $this->hours()]);
        $this->assertNotNull($legacy->fresh()->manager_approved_at);

        $this->evaluate($legacy)->assertOk();
        $this->assertSame([1, 2.0], [$this->awards($legacy), $this->hours()], 'now it is a replay');
    }

    public function test_a_task_with_hours_and_no_points_is_credited_its_hours_once(): void
    {
        $task = $this->task(['points' => 0, 'estimated_hours' => 3]);

        $this->as($this->admin);
        $this->evaluate($task)->assertOk();
        $this->evaluate($task)->assertOk();
        $this->as($this->hr)->postJson("/api/admin/tasks/{$task->id}/hr-approve")->assertOk();
        $this->assertSame([3.0, 0], [$this->hours(), $this->awards($task)]);
    }

    public function test_the_delegated_credit_lock_answers_403_before_the_workflow_rule_answers_422(): void
    {
        $waiting = $this->task(['status' => 'waiting_approval', 'manager_approved_at' => now()]);

        $this->as($this->leader);
        $this->patchJson("/api/operations/tasks/{$waiting->id}", ['status' => 'completed'])->assertForbidden();
        $this->patchJson("/api/operations/board/tasks/{$waiting->id}/status", ['status' => 'completed'])->assertForbidden();
        $this->assertSame('waiting_approval', $waiting->fresh()->status);
    }

    // ═══ 4. One credit per accomplishment ════════════════════════════════════

    public function test_repeated_evaluations_and_approvals_credit_the_hours_and_points_once(): void
    {
        $simple = $this->task();
        $this->as($this->admin);
        $this->evaluate($simple)->assertOk();
        $this->evaluate($simple)->assertOk();
        $this->as($this->hr)->postJson("/api/admin/tasks/{$simple->id}/hr-approve")->assertOk();

        $medium = $this->task(['task_scope' => Task::SCOPE_MEDIUM, 'points' => 100, 'status' => 'needs_evaluation']);
        $this->as($this->admin);
        $this->evaluate($medium)->assertOk();
        $this->as($this->hr);
        $this->postJson("/api/admin/tasks/{$medium->id}/hr-approve")->assertOk();
        $this->postJson("/api/admin/tasks/{$medium->id}/hr-approve")->assertOk();
        $this->as($this->admin);
        $this->evaluate($medium)->assertOk();

        $this->assertSame([4.0, 2], [$this->hours(), $this->awards()]);
        $this->assertSame(['completed', 'completed'], [$simple->fresh()->status, $medium->fresh()->status]);
    }

    public function test_a_second_approval_that_read_the_task_before_the_first_finished_adds_no_hours(): void
    {
        // two approvals racing: the second holds a stale copy of the task that is no longer "awaiting". The credit itself is
        // idempotent (the ledger says the points are already awarded, so the hours are not added again either).
        $task = $this->task(['task_scope' => Task::SCOPE_MEDIUM, 'points' => 100, 'status' => 'waiting_approval', 'manager_approved_at' => now()]);
        $stale = Task::find($task->id);

        $this->as($this->hr)->postJson("/api/admin/tasks/{$task->id}/hr-approve")->assertOk();
        $this->assertSame([2.0, 1], [$this->hours(), $this->awards($task)]);

        TaskWorkflowService::creditVolunteerHoursAndPoints($stale, $this->hr);
        TaskWorkflowService::creditVolunteerHoursAndPoints($stale, $this->hr);
        $this->assertSame([2.0, 1], [$this->hours(), $this->awards($task)]);
    }

    // ═══ 5. Scope and lock still come first ══════════════════════════════════

    public function test_a_leader_is_refused_outside_the_department_before_the_workflow_rule_is_considered(): void
    {
        $foreign = $this->task(['department_id' => $this->deptOther->id]);
        $this->as($this->leader);
        $this->patchJson("/api/operations/board/tasks/{$foreign->id}/status", ['status' => 'completed'])->assertForbidden();
        $this->patchJson("/api/operations/tasks/{$foreign->id}", ['status' => 'completed'])->assertForbidden();
        $this->patchJson("/api/operations/board/tasks/{$foreign->id}/status", ['status' => 'planning'])->assertForbidden();
        $this->assertSame('in_progress', $foreign->fresh()->status);
    }

    public function test_a_locked_task_stays_locked_and_the_lock_wins_over_the_workflow_rule_for_those_who_may_move_it(): void
    {
        $locked = $this->task(['locked_at' => now(), 'locked_by' => $this->admin->id]);
        $this->as($this->admin)->patchJson("/api/operations/board/tasks/{$locked->id}/status", ['status' => 'completed'])->assertStatus(423);
        $this->assertSame('in_progress', $locked->fresh()->status);
    }

    // ═══ 6. The rule itself ══════════════════════════════════════════════════

    public function test_the_shared_rule_judges_a_change_only(): void
    {
        $working = new Task(['status' => 'in_progress']);
        $waiting = new Task(['status' => 'waiting_approval']);
        $done = new Task(['status' => 'completed']);
        $stale = new Task(['status' => 'in_progress']);
        $stale->manager_approved_at = now();

        foreach (['completed', 'waiting_approval'] as $outcome) {
            $this->assertNotNull(TaskTransitionRules::refusal($working, $outcome));
            $this->assertNotNull(TaskTransitionRules::creationRefusal($outcome));
        }
        $this->assertNull(TaskTransitionRules::refusal($working, 'needs_evaluation'));
        $this->assertNull(TaskTransitionRules::refusal($working, 'in_progress'), 'the same status is not a change');
        $this->assertNull(TaskTransitionRules::refusal($done, 'completed'));
        $this->assertNull(TaskTransitionRules::refusal($working, null));
        $this->assertNull(TaskTransitionRules::creationRefusal(null));
        $this->assertNull(TaskTransitionRules::creationRefusal('idea'));
        foreach ([$waiting, $done, $stale] as $credited) {
            $this->assertTrue(TaskTransitionRules::isCreditBearing($credited));
            $this->assertNotNull(TaskTransitionRules::refusal($credited, 'planning'));
        }
        $this->assertFalse(TaskTransitionRules::isCreditBearing($working));
    }
}
