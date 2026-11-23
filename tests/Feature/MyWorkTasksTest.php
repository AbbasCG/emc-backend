<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * /my-work/tasks: an ordinary member sees and answers THEIR OWN assigned tasks, without any admin-type role.
 */
class MyWorkTasksTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private User $other;

    private User $manager;

    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();

        $this->member = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $this->other = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $this->manager = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $this->dept = Department::create(['name' => 'D', 'name_ar' => 'إدارة', 'slug' => 'd-tasks', 'status' => 'active', 'is_active' => true]);
    }

    private function task(User $to, array $over = []): Task
    {
        return Task::create(array_merge([
            'title' => 'مهمة '.uniqid(), 'description' => 'وصف', 'department_id' => $this->dept->id,
            'assigned_to' => $to->id, 'created_by' => $this->manager->id, 'priority' => 'medium',
            'status' => 'idea', 'acceptance_status' => Task::ACCEPTANCE_PENDING,
        ], $over));
    }

    private function as(User $u): static
    {
        Sanctum::actingAs($u);

        return $this;
    }

    /** @return array<int, int> */
    private function ids(string $query = '', ?User $as = null): array
    {
        return collect($this->as($as ?? $this->member)->getJson('/api/my-work/tasks'.$query)->assertOk()->json('data'))->pluck('id')->all();
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/my-work/tasks')->assertUnauthorized();
        $t = $this->task($this->member);
        $this->postJson("/api/my-work/tasks/{$t->id}/accept")->assertUnauthorized();
        $this->postJson("/api/my-work/tasks/{$t->id}/reject", ['reason' => 'سبب كافٍ'])->assertUnauthorized();
        $this->assertSame('pending', $t->fresh()->acceptance_status);
    }

    public function test_a_plain_member_sees_their_pending_accepted_and_rejected_tasks_and_never_others(): void
    {
        $pending = $this->task($this->member);
        $accepted = $this->task($this->member, ['acceptance_status' => 'accepted', 'status' => 'in_progress']);
        $rejected = $this->task($this->member, ['acceptance_status' => 'rejected']);
        $done = $this->task($this->member, ['acceptance_status' => 'accepted', 'status' => 'completed']);
        $others = $this->task($this->other);
        $unassigned = $this->task($this->member, ['assigned_to' => null]);

        $res = $this->as($this->member)->getJson('/api/my-work/tasks')->assertOk()->assertJsonPath('success', true);
        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$pending->id, $accepted->id, $rejected->id, $done->id], $ids);
        $this->assertNotContains($others->id, $ids);
        $this->assertNotContains($unassigned->id, $ids);
        $res->assertJsonPath('meta', ['total' => 4, 'truncated' => false]);

        // The management route stays closed to this member: this is exactly why the new route exists.
        $this->getJson('/api/admin/tasks/my')->assertForbidden();
    }

    public function test_no_query_parameter_widens_the_scope(): void
    {
        $mine = $this->task($this->member);
        $theirs = $this->task($this->other);

        foreach (["?assigned_to={$this->other->id}", "?user_id={$this->other->id}", '?scope=all', '?all=1', "?created_by={$this->manager->id}", '?department_id='.$this->dept->id] as $q) {
            $this->assertSame([$mine->id], $this->ids($q), $q);
        }
        $this->assertSame([$theirs->id], $this->ids('', $this->other));
    }

    public function test_allow_listed_filters_only_narrow(): void
    {
        $pending = $this->task($this->member);
        $accepted = $this->task($this->member, ['acceptance_status' => 'accepted', 'status' => 'in_progress']);

        $this->assertSame([$pending->id], $this->ids('?acceptance_status=pending'));
        $this->assertSame([$accepted->id], $this->ids('?status=in_progress'));
        $this->assertSame([], $this->ids('?acceptance_status=pending&status=in_progress'));
        $this->as($this->member)->getJson('/api/my-work/tasks?acceptance_status=bogus')->assertStatus(422);
        $this->getJson('/api/my-work/tasks?status=bogus')->assertStatus(422);
    }

    public function test_newest_update_first_and_the_cap_is_reported(): void
    {
        $old = $this->task($this->member);
        $new = $this->task($this->member);
        DB::table('tasks')->where('id', $old->id)->update(['updated_at' => now()->subDays(5)]);
        DB::table('tasks')->where('id', $new->id)->update(['updated_at' => now()]);

        $this->assertSame([$new->id, $old->id], $this->ids());
        $this->assertSame(300, \App\Http\Controllers\Api\MyWorkTaskController::LIMIT);
    }

    public function test_accept_moves_the_task_to_accepted_with_the_same_status_rule_and_logs_it(): void
    {
        $t = $this->task($this->member, ['status' => 'idea']);
        $stay = $this->task($this->member, ['status' => 'in_progress']);

        $this->as($this->member)->postJson("/api/my-work/tasks/{$t->id}/accept")
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.acceptance_status', 'accepted')->assertJsonPath('data.status', 'planning');
        $fresh = $t->fresh();
        $this->assertSame('accepted', $fresh->acceptance_status);
        $this->assertNotNull($fresh->accepted_at);
        $this->assertNull($fresh->rejection_reason);

        $this->postJson("/api/my-work/tasks/{$stay->id}/accept")->assertOk();
        $this->assertSame('in_progress', $stay->fresh()->status, 'a task already past planning keeps its status');

        $this->assertSame(1, ActivityLog::where('action', 'task.accepted')->where('entity_id', $t->id)->count());

        // And it is now listed as accepted on the next call.
        $row = collect($this->getJson('/api/my-work/tasks')->json('data'))->firstWhere('id', $t->id);
        $this->assertSame('accepted', $row['acceptance_status']);
    }

    public function test_reject_needs_a_reason_and_records_it(): void
    {
        $t = $this->task($this->member);

        $this->as($this->member);
        $this->postJson("/api/my-work/tasks/{$t->id}/reject", [])->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->postJson("/api/my-work/tasks/{$t->id}/reject", ['reason' => 'ab'])->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->postJson("/api/my-work/tasks/{$t->id}/reject", ['reason' => str_repeat('س', 1001)])->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->assertSame('pending', $t->fresh()->acceptance_status);

        $this->postJson("/api/my-work/tasks/{$t->id}/reject", ['reason' => 'لدي التزام آخر'])
            ->assertOk()->assertJsonPath('data.acceptance_status', 'rejected')->assertJsonPath('data.rejection_reason', 'لدي التزام آخر');
        $this->assertNotNull($t->fresh()->rejected_at);
        $this->assertSame(1, ActivityLog::where('action', 'task.rejected')->where('entity_id', $t->id)->count());
    }

    public function test_a_second_decision_on_the_same_task_is_a_409_and_changes_nothing(): void
    {
        $t = $this->task($this->member);

        $this->as($this->member)->postJson("/api/my-work/tasks/{$t->id}/accept")->assertOk();
        $acceptedAt = $t->fresh()->accepted_at;

        $this->postJson("/api/my-work/tasks/{$t->id}/accept")->assertStatus(409);
        $this->postJson("/api/my-work/tasks/{$t->id}/reject", ['reason' => 'غيرت رأيي'])->assertStatus(409);

        $this->assertSame('accepted', $t->fresh()->acceptance_status);
        $this->assertEquals($acceptedAt, $t->fresh()->accepted_at);
        $this->assertSame(1, ActivityLog::where('entity_id', $t->id)->whereIn('action', ['task.accepted', 'task.rejected'])->count());
    }

    public function test_a_task_that_was_never_pending_cannot_be_decided(): void
    {
        $t = $this->task($this->member, ['acceptance_status' => 'accepted']);
        $r = $this->task($this->member, ['acceptance_status' => 'rejected']);

        $this->as($this->member)->postJson("/api/my-work/tasks/{$t->id}/reject", ['reason' => 'سبب كافٍ'])->assertStatus(409);
        $this->postJson("/api/my-work/tasks/{$r->id}/accept")->assertStatus(409);
    }

    public function test_someone_elses_task_cannot_be_decided_and_is_not_revealed(): void
    {
        $t = $this->task($this->other);

        $this->as($this->member)->postJson("/api/my-work/tasks/{$t->id}/accept")->assertNotFound();
        $this->postJson("/api/my-work/tasks/{$t->id}/reject", ['reason' => 'سبب كافٍ'])->assertNotFound();
        $this->postJson('/api/my-work/tasks/999999/accept')->assertNotFound();
        $this->assertSame('pending', $t->fresh()->acceptance_status);

        // No admin override on the own-work routes: even an admin acts only on their own assignments here.
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->as($admin)->postJson("/api/my-work/tasks/{$t->id}/accept")->assertNotFound();
        $this->assertSame('pending', $t->fresh()->acceptance_status);
    }

    public function test_the_restricted_management_routes_are_unaffected(): void
    {
        $t = $this->task($this->member);

        // A role from the restricted group still uses them, with the admin override intact.
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->as($admin)->getJson('/api/admin/tasks/my')->assertOk();
        $this->postJson("/api/admin/tasks/{$t->id}/accept")->assertOk()->assertJsonPath('data.acceptance_status', 'accepted');
        $this->assertSame(1, ActivityLog::where('action', 'task.accepted')->where('entity_id', $t->id)->count());

        // The member still cannot use them (unchanged), only the new routes.
        $t2 = $this->task($this->member);
        $this->as($this->member)->postJson("/api/admin/tasks/{$t2->id}/accept")->assertForbidden();
        $this->postJson("/api/operations/tasks/{$t2->id}/accept")->assertForbidden();
    }

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_tasks(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->as($this->member)->getJson('/api/my-work/tasks')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $seed = function (int $n): void {
            foreach (range(1, $n) as $i) {
                $t = $this->task($this->member, ['acceptance_status' => ['pending', 'accepted', 'rejected'][$i % 3]]);
                $this->task($this->other, ['parent_task_id' => $t->id]); // a subtask with its own assignee (subtasks.assignedTo is eager-loaded)
                $t->checklistItems()->create(['title' => 'بند', 'is_completed' => false]);
            }
        };

        $seed(3);
        $small = $count();
        $seed(30);
        $large = $count();

        $this->assertSame($small, $large, 'no per-task queries');
    }

    // ═══ collective tasks (parent + one child per member) ═══════════════════

    /** Exactly what Admin\TaskController::store builds: a parent assigned to the first member, and a child per member. */
    private function collective(array $members): array
    {
        $parent = $this->task($members[0], ['title' => 'مهمة جماعية']);
        $children = [];
        foreach ($members as $m) {
            $children[$m->id] = $this->task($m, ['title' => 'مهمة جماعية', 'parent_task_id' => $parent->id]);
        }

        return [$parent, $children];
    }

    public function test_a_collective_task_shows_once_per_member_as_their_own_child(): void
    {
        $m1 = $this->member;
        $m2 = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $m3 = User::factory()->create(['role' => 'student', 'is_active' => true]);
        [$parent, $children] = $this->collective([$m1, $m2, $m3]);
        $plain = $this->task($m1, ['title' => 'مهمة عادية']);

        foreach ([$m2, $m3] as $m) {
            $this->assertSame([$children[$m->id]->id], $this->ids('', $m), 'exactly one row: their child');
        }

        // Member 1 owns the parent AND a child: only the child (and the ordinary task) is listed.
        $res = $this->as($m1)->getJson('/api/my-work/tasks')->assertOk();
        $this->assertEqualsCanonicalizing([$children[$m1->id]->id, $plain->id], collect($res->json('data'))->pluck('id')->all());
        $this->assertNotContains($parent->id, collect($res->json('data'))->pluck('id')->all());
        $res->assertJsonPath('meta', ['total' => 2, 'truncated' => false]);

        // A parent whose children belong to other people only (the caller has no child of it) is unaffected.
        $solo = $this->task($m2, ['title' => 'أب بلا ابن للعضو']);
        $this->task($m3, ['parent_task_id' => $solo->id]);
        $this->assertContains($solo->id, $this->ids('', $m2));
    }

    public function test_the_parent_of_a_collective_task_cannot_be_answered_separately(): void
    {
        [$parent, $children] = $this->collective([$this->member, $this->other]);

        $this->as($this->member)->postJson("/api/my-work/tasks/{$parent->id}/accept")->assertStatus(409);
        $this->postJson("/api/my-work/tasks/{$parent->id}/reject", ['reason' => 'سبب كافٍ'])->assertStatus(409);
        $this->assertSame('pending', $parent->fresh()->acceptance_status);

        // The child is the one to answer.
        $this->postJson("/api/my-work/tasks/{$children[$this->member->id]->id}/accept")->assertOk();
        $this->assertSame('accepted', $children[$this->member->id]->fresh()->acceptance_status);
        $this->assertSame('pending', $children[$this->other->id]->fresh()->acceptance_status);
    }

    public function test_collective_tasks_keep_the_query_count_constant(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->as($this->member)->getJson('/api/my-work/tasks')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->collective([$this->member, $this->other]);
        $small = $count();
        foreach (range(1, 15) as $i) {
            $this->collective([$this->member, $this->other]);
        }
        $this->assertSame($small, $count());
        $this->assertCount(16, $this->ids(), 'sixteen collective assignments, sixteen rows');
    }

    // ═══ detail, comments, checklist (own-only) ═════════════════════════════

    public function test_a_member_opens_their_own_task_with_comments_checklist_and_attachments(): void
    {
        $t = $this->task($this->member, ['title' => 'مهمتي']);
        $t->comments()->create(['user_id' => $this->manager->id, 'comment' => 'ابدأ بالتقرير']);
        $item = $t->checklistItems()->create(['title' => 'الخطوة الأولى', 'is_completed' => false]);

        $res = $this->as($this->member)->getJson("/api/my-work/tasks/{$t->id}")->assertOk()->assertJsonPath('success', true);
        $data = $res->json('data');
        $this->assertSame([$t->id, 'مهمتي'], [$data['id'], $data['title']]);
        $this->assertSame('ابدأ بالتقرير', $data['comments'][0]['comment']);
        $this->assertSame([$item->id, false], [$data['checklist_items'][0]['id'], $data['checklist_items'][0]['is_completed']]);
        foreach (['department', 'assigned_to', 'created_by', 'attachments', 'subtasks', 'comments', 'checklist_items'] as $key) {
            $this->assertArrayHasKey($key, $data, $key);
        }

        // The management detail stays closed to this member.
        $this->getJson("/api/operations/tasks/{$t->id}")->assertForbidden();
    }

    public function test_anything_not_assigned_to_the_caller_is_a_404_even_for_an_admin(): void
    {
        $theirs = $this->task($this->other);
        $item = $theirs->checklistItems()->create(['title' => 'بند', 'is_completed' => false]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        foreach ([$this->member, $admin] as $user) {
            $this->as($user)->getJson("/api/my-work/tasks/{$theirs->id}")->assertNotFound()->assertJsonPath('message', 'المهمة غير موجودة أو غير مسندة إليك.');
            $this->postJson("/api/my-work/tasks/{$theirs->id}/comments", ['comment' => 'مرحبا'])->assertNotFound();
            $this->putJson("/api/my-work/tasks/{$theirs->id}/checklist/{$item->id}", ['is_completed' => true])->assertNotFound();
        }
        $this->getJson('/api/my-work/tasks/999999')->assertNotFound();
        $this->assertSame(0, $theirs->comments()->count());
        $this->assertFalse((bool) $item->fresh()->is_completed);
    }

    public function test_the_routes_require_authentication(): void
    {
        $t = $this->task($this->member);
        $item = $t->checklistItems()->create(['title' => 'بند', 'is_completed' => false]);

        $this->getJson("/api/my-work/tasks/{$t->id}")->assertUnauthorized();
        $this->postJson("/api/my-work/tasks/{$t->id}/comments", ['comment' => 'x'])->assertUnauthorized();
        $this->putJson("/api/my-work/tasks/{$t->id}/checklist/{$item->id}", ['is_completed' => true])->assertUnauthorized();
    }

    public function test_a_member_comments_as_themselves_with_validation(): void
    {
        $t = $this->task($this->member);

        $this->as($this->member)->postJson("/api/my-work/tasks/{$t->id}/comments", [])->assertStatus(422)->assertJsonPath('errors.comment.0', 'نص التعليق مطلوب.');
        $this->postJson("/api/my-work/tasks/{$t->id}/comments", ['comment' => str_repeat('س', 5001)])->assertStatus(422)->assertJsonValidationErrors(['comment']);
        $this->assertSame(0, $t->comments()->count());

        $res = $this->postJson("/api/my-work/tasks/{$t->id}/comments", ['comment' => 'بدأت العمل'])->assertCreated()->assertJsonPath('success', true);
        $this->assertSame('بدأت العمل', $res->json('data.comment'));
        $this->assertSame($this->member->id, (int) $t->comments()->sole()->user_id, 'the author is the signed-in member, never a value from the body');

        $this->postJson("/api/my-work/tasks/{$t->id}/comments", ['comment' => 'آخر', 'user_id' => $this->other->id])->assertCreated();
        $this->assertSame([$this->member->id], $t->comments()->pluck('user_id')->unique()->map(fn ($v) => (int) $v)->values()->all());
    }

    public function test_a_member_ticks_their_own_checklist_items_only(): void
    {
        $t = $this->task($this->member);
        $mine = $t->checklistItems()->create(['title' => 'بند', 'is_completed' => false]);
        $otherTask = $this->task($this->member);
        $foreign = $otherTask->checklistItems()->create(['title' => 'بند آخر', 'is_completed' => false]);

        $this->as($this->member)->putJson("/api/my-work/tasks/{$t->id}/checklist/{$mine->id}", ['is_completed' => true])
            ->assertOk()->assertJsonPath('data.is_completed', true);
        $this->assertTrue((bool) $mine->fresh()->is_completed);
        $this->putJson("/api/my-work/tasks/{$t->id}/checklist/{$mine->id}", ['is_completed' => false])->assertOk();
        $this->assertFalse((bool) $mine->fresh()->is_completed);

        // An item of ANOTHER task (even one of mine) is not addressable through this task.
        $this->putJson("/api/my-work/tasks/{$t->id}/checklist/{$foreign->id}", ['is_completed' => true])->assertNotFound();
        $this->assertFalse((bool) $foreign->fresh()->is_completed);

        // Only the tick: renaming or reordering stays with managers, and the flag is required.
        $this->putJson("/api/my-work/tasks/{$t->id}/checklist/{$mine->id}", ['title' => 'اسم جديد', 'is_completed' => true])->assertOk();
        $this->assertSame('بند', $mine->fresh()->title);
        $this->putJson("/api/my-work/tasks/{$t->id}/checklist/{$mine->id}", [])->assertStatus(422)->assertJsonValidationErrors(['is_completed']);
    }

    public function test_the_management_comment_and_checklist_routes_behave_as_before(): void
    {
        $t = $this->task($this->member);
        $item = $t->checklistItems()->create(['title' => 'بند', 'is_completed' => false]);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->as($admin)->postJson("/api/admin/tasks/{$t->id}/comments", ['comment' => 'من المدير'])->assertCreated();
        $this->assertSame($admin->id, (int) $t->comments()->sole()->user_id);
        $this->putJson("/api/admin/tasks/{$t->id}/checklist/{$item->id}", ['title' => 'مُعدَّل', 'is_completed' => true])->assertOk();
        $this->assertSame(['مُعدَّل', true], [$item->fresh()->title, (bool) $item->fresh()->is_completed]);
    }

    public function test_the_new_routes_are_throttled_and_authenticated(): void
    {
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/my-work/'));
        $this->assertCount(7, $routes);
        foreach ($routes as $r) {
            $this->assertTrue(collect($r->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')), $r->uri().' is throttled');
            $this->assertContains('auth:sanctum', $r->gatherMiddleware());
        }
    }

    // ═══ status moves (own-only) ════════════════════════════════════════════

    public function test_student_and_department_member_assignees_move_their_own_accepted_task(): void
    {
        foreach (['student', 'department_member'] as $role) {
            $user = User::factory()->create(['role' => $role, 'is_active' => true]);
            $t = $this->task($user, ['acceptance_status' => 'accepted', 'status' => 'planning']);

            // Reaching the old board route is exactly what these roles could not do.
            $this->as($user)->patchJson("/api/operations/board/tasks/{$t->id}/status", ['status' => 'in_progress'])->assertForbidden();

            $this->patchJson("/api/my-work/tasks/{$t->id}/status", ['status' => 'in_progress'])
                ->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.id', $t->id);
            $this->assertSame('in_progress', $t->fresh()->status, $role);
            $this->assertSame(1, ActivityLog::where('action', 'task.status_changed')->where('entity_id', $t->id)->count());

            $this->patchJson("/api/my-work/tasks/{$t->id}/status", ['status' => 'needs_evaluation'])->assertOk();
            $this->assertSame('needs_evaluation', $t->fresh()->status);
        }
    }

    public function test_a_member_cannot_complete_their_task_but_can_use_every_other_status(): void
    {
        $t = $this->task($this->member, ['acceptance_status' => 'accepted', 'status' => 'planning']);

        $this->as($this->member)->patchJson("/api/my-work/tasks/{$t->id}/status", ['status' => 'completed'])
            ->assertForbidden()->assertJsonPath('success', false)
            ->assertJsonPath('message', 'اعتماد الإنجاز يتم بعد تقييم المدير؛ حرّك المهمة إلى «بانتظار التقييم».');
        $this->assertSame('planning', $t->fresh()->status);
        $this->assertSame(0, ActivityLog::where('action', 'task.status_changed')->where('entity_id', $t->id)->count());

        foreach (['idea', 'under_review', 'planning', 'waiting_approval', 'in_progress', 'postponed', 'cancelled', 'needs_evaluation'] as $status) {
            $this->patchJson("/api/my-work/tasks/{$t->id}/status", ['status' => $status])->assertOk()->assertJsonPath('data.status', $status);
        }
    }

    public function test_a_completed_task_stays_completed_for_the_member(): void
    {
        $t = $this->task($this->member, ['acceptance_status' => 'accepted', 'status' => 'completed']);

        foreach (['in_progress', 'needs_evaluation', 'completed'] as $status) {
            $this->as($this->member)->patchJson("/api/my-work/tasks/{$t->id}/status", ['status' => $status])
                ->assertForbidden()->assertJsonPath('message', 'المهمة المنجزة معتمدة ولا يمكن تغيير حالتها؛ للتعديل راجع المدير.');
        }
        $this->assertSame('completed', $t->fresh()->status);
        $this->assertSame(0, ActivityLog::where('action', 'task.status_changed')->where('entity_id', $t->id)->count());

        // A manager still can, through the management route (unchanged).
        $manager = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $this->as($manager)->patchJson("/api/operations/board/tasks/{$t->id}/status", ['status' => 'in_progress'])->assertOk();
    }

    public function test_the_status_must_be_a_known_one(): void
    {
        $t = $this->task($this->member, ['acceptance_status' => 'accepted']);

        $this->as($this->member)->patchJson("/api/my-work/tasks/{$t->id}/status", [])->assertStatus(422)->assertJsonPath('errors.status.0', 'حدّد الحالة الجديدة للمهمة.');
        $this->patchJson("/api/my-work/tasks/{$t->id}/status", ['status' => 'archived'])->assertStatus(422)->assertJsonPath('errors.status.0', 'حالة المهمة غير صالحة.');
        $this->assertSame('idea', $t->fresh()->status);
    }

    public function test_a_locked_task_cannot_be_moved(): void
    {
        $t = $this->task($this->member, ['acceptance_status' => 'accepted', 'locked_at' => now()]);

        $this->as($this->member)->patchJson("/api/my-work/tasks/{$t->id}/status", ['status' => 'in_progress'])->assertStatus(423);
        $this->assertSame('idea', $t->fresh()->status);
    }

    public function test_a_task_that_is_not_accepted_yet_cannot_be_moved(): void
    {
        $pending = $this->task($this->member);
        $rejected = $this->task($this->member, ['acceptance_status' => 'rejected']);

        $this->as($this->member);
        $this->patchJson("/api/my-work/tasks/{$pending->id}/status", ['status' => 'in_progress'])->assertStatus(409);
        $this->patchJson("/api/my-work/tasks/{$rejected->id}/status", ['status' => 'in_progress'])->assertStatus(409);
        $this->assertSame(['idea', 'idea'], [$pending->fresh()->status, $rejected->fresh()->status]);

        // Accepting first unlocks the move.
        $this->postJson("/api/my-work/tasks/{$pending->id}/accept")->assertOk();
        $this->patchJson("/api/my-work/tasks/{$pending->id}/status", ['status' => 'in_progress'])->assertOk();
    }

    public function test_someone_elses_task_cannot_be_moved_not_even_by_an_admin_and_401_when_signed_out(): void
    {
        $theirs = $this->task($this->other, ['acceptance_status' => 'accepted']);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        foreach ([$this->member, $admin] as $user) {
            $this->as($user)->patchJson("/api/my-work/tasks/{$theirs->id}/status", ['status' => 'completed'])->assertNotFound();
        }
        $this->patchJson('/api/my-work/tasks/999999/status', ['status' => 'completed'])->assertNotFound();
        $this->assertSame('idea', $theirs->fresh()->status);

        $this->app['auth']->forgetGuards();
        $this->patchJson("/api/my-work/tasks/{$theirs->id}/status", ['status' => 'completed'])->assertUnauthorized();
    }

    public function test_the_management_board_status_route_is_unchanged_and_logs_the_same_entry(): void
    {
        $volunteer = User::factory()->create(['role' => 'volunteer', 'is_active' => true]);
        $mine = $this->task($volunteer, ['acceptance_status' => 'accepted']);
        $theirs = $this->task($this->other, ['acceptance_status' => 'accepted']);

        $this->as($volunteer)->patchJson("/api/operations/board/tasks/{$mine->id}/status", ['status' => 'in_progress'])->assertOk();
        $this->assertSame(1, ActivityLog::where('action', 'task.status_changed')->where('entity_id', $mine->id)->count());
        $this->patchJson("/api/operations/board/tasks/{$theirs->id}/status", ['status' => 'in_progress'])->assertForbidden();
        $this->assertSame('idea', $theirs->fresh()->status);

        $manager = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $this->as($manager)->patchJson("/api/operations/board/tasks/{$theirs->id}/status", ['status' => 'completed'])->assertOk();
        $this->assertSame('completed', $theirs->fresh()->status);
    }
}
