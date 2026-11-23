<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** GET /operations/board: cards carry cheap subtask / checklist counters (no relation rows are loaded for them). */
class OperationsBoardCountersTest extends TestCase
{
    use RefreshDatabase;

    private User $viewer;

    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();

        $this->viewer = User::factory()->create(['role' => 'operations_manager', 'is_active' => true]);
        $this->dept = Department::create(['name' => 'D', 'name_ar' => 'إدارة', 'slug' => 'd-board', 'status' => 'active', 'is_active' => true]);
    }

    private function task(array $over = []): Task
    {
        return Task::create(array_merge([
            'title' => 'مهمة', 'department_id' => $this->dept->id, 'assigned_to' => $this->viewer->id, 'created_by' => $this->viewer->id,
            'priority' => 'medium', 'status' => 'planning', 'acceptance_status' => 'accepted',
        ], $over));
    }

    private function board(string $query = ''): array
    {
        Sanctum::actingAs($this->viewer);

        return $this->getJson('/api/operations/board'.$query)->assertOk()->json('data');
    }

    public function test_cards_report_subtask_and_checklist_counters(): void
    {
        $parent = $this->task(['title' => 'أب']);
        foreach (range(1, 3) as $i) {
            $this->task(['parent_task_id' => $parent->id, 'status' => 'planning']);
        }
        $parent->checklistItems()->create(['title' => 'أ', 'is_completed' => true]);
        $parent->checklistItems()->create(['title' => 'ب', 'is_completed' => true]);
        $parent->checklistItems()->create(['title' => 'ج', 'is_completed' => false]);
        $plain = $this->task(['title' => 'عادية', 'status' => 'in_progress']);

        $board = $this->board();
        $card = collect($board['planning'])->firstWhere('id', $parent->id);
        $this->assertSame([3, 3, 2], [$card['subtasks_count'], $card['checklist_items_count'], $card['checklist_done_count']]);
        // Children are cards of their own, with zero counters.
        $child = collect($board['planning'])->firstWhere('parent_task_id', $parent->id);
        $this->assertSame([0, 0, 0], [$child['subtasks_count'], $child['checklist_items_count'], $child['checklist_done_count']]);
        $this->assertSame([0, 0, 0], [collect($board['in_progress'])->firstWhere('id', $plain->id)['subtasks_count'], 0, 0]);

        // The rest of the shape is unchanged: nothing was eager-loaded for the counters.
        $this->assertArrayNotHasKey('subtasks', $card);
        $this->assertArrayNotHasKey('checklist_items', $card);
        $this->assertSame(['idea', 'under_review', 'planning', 'waiting_approval', 'in_progress', 'needs_evaluation', 'completed', 'postponed', 'cancelled'], array_keys($board));
    }

    public function test_the_counters_do_not_add_queries_per_card(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->board();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        // Every status column that will be filled later is filled already, so the baseline runs every query type.
        foreach (['planning', 'in_progress', 'completed'] as $status) {
            $p = $this->task(['status' => $status]);
            $this->task(['parent_task_id' => $p->id, 'status' => $status]);
            $p->checklistItems()->create(['title' => 'أ', 'is_completed' => false]);
        }
        $small = $count();

        foreach (range(1, 20) as $i) {
            $t = $this->task(['status' => ['planning', 'in_progress', 'completed'][$i % 3]]);
            $this->task(['parent_task_id' => $t->id]);
            $t->checklistItems()->create(['title' => 'ب', 'is_completed' => true]);
        }

        $this->assertSame($small, $count(), 'counters are subqueries of the status queries, not extra queries');
    }

    public function test_the_mine_scope_still_returns_only_accepted_tasks_of_the_caller(): void
    {
        $mine = $this->task();
        $pending = $this->task(['acceptance_status' => 'pending']);
        $others = $this->task(['assigned_to' => User::factory()->create(['role' => 'student', 'is_active' => true])->id]);

        $ids = collect($this->board('?scope=mine'))->flatten(1)->pluck('id')->all();
        $this->assertSame([$mine->id], $ids);
        $this->assertNotContains($pending->id, $ids);
        $this->assertNotContains($others->id, $ids);
    }
}
