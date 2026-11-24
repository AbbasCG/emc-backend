<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskComment;
use App\Models\TaskEvaluation;
use App\Models\Ticket;
use App\Models\User;
use App\Services\OperationsWorkBoard;
use App\Support\WorkBoardColumns;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/operations/work-board: the GLOBAL operations board. Every task and every assigned ticket of every assignee, in the same
 * columns as «مهامي ومسؤولياتي», as public-safe cards whose actions are computed with the viewer's real access.
 */
class OperationsWorkBoardTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/operations/work-board';

    /** Text planted in every sensitive field: it must never appear in the board's response. */
    private const SECRETS = ['SECRET-DESC', 'SECRET-NOTES', 'SECRET-REJECT', 'SECRET-FEEDBACK', 'SECRET-COMMENT', 'SECRET-FILE', 'SECRET-INTERNAL', 'SECRET-LIVE', 'secret-mail@example.com', 'SECRET-RESOLUTION', 'SECRET-REPORTER'];

    private Department $deptA;

    private Department $deptB;

    private User $admin;

    private User $leaderA;

    private User $viewer;

    private User $alice;

    private User $bob;

    private User $reporter;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-23 12:00:00');
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->deptA = $this->dept('إدارة التقنية');
        $this->deptB = $this->dept('إدارة التسويق');
        $this->admin = $this->user('admin');
        $this->leaderA = $this->user('programs_manager');
        $this->deptA->update(['leader_id' => $this->leaderA->id]);
        // The «view-only» viewer: an ordinary volunteer who belongs to department A.
        $this->viewer = $this->user('volunteer');
        $this->deptA->users()->attach($this->viewer->id);
        $this->alice = $this->user('volunteer');
        $this->bob = $this->user('volunteer');
        $this->deptA->users()->attach([$this->alice->id, $this->bob->id]);
        $this->reporter = $this->user('student');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function dept(string $nameAr): Department
    {
        return Department::create(['name' => 'D '.Str::random(6), 'name_ar' => $nameAr, 'slug' => 'd-'.Str::random(8), 'status' => 'active', 'is_active' => true]);
    }

    private function task(array $over = []): Task
    {
        return Task::create(array_merge([
            'title' => 'مهمة '.Str::random(4), 'department_id' => $this->deptA->id, 'assigned_to' => $this->alice->id, 'created_by' => $this->admin->id,
            'priority' => 'medium', 'status' => 'in_progress', 'acceptance_status' => 'accepted',
        ], $over));
    }

    private function ticket(array $over = []): Ticket
    {
        $forced = array_intersect_key($over, array_flip(['requires_deployment', 'submitted_by_id']));
        $over = array_diff_key($over, $forced);

        $t = Ticket::create(array_merge([
            'ticket_number' => 'EMC-TCK-B-'.Str::random(8), 'title' => 'تذكرة '.Str::random(4), 'description' => 'وصف', 'ticket_category' => 'OLD_ISSUE', 'priority' => 'MEDIUM',
            'department_id' => $this->deptA->id, 'created_by_id' => $this->reporter->id, 'created_by_name' => 'مُبلِّغ', 'status' => 'IN_PROGRESS',
            'assigned_to_id' => $this->alice->id, 'task_scope' => 'simple', 'task_points' => 30, 'estimated_hours' => 1.5,
        ], $over));
        if ($forced !== []) {
            $t->forceFill($forced)->save();
        }

        return $t;
    }

    private function board(User $as, string $query = ''): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($as);

        return $this->getJson(self::URL.$query);
    }

    /** @return array<string, array<string, mixed>> column key => column */
    private function columns(User $as, string $query = ''): array
    {
        return collect($this->board($as, $query)->assertOk()->assertJsonPath('success', true)->json('data.columns'))->keyBy('key')->all();
    }

    /** @return array<string, string> item key => column key */
    private function placement(User $as, string $query = ''): array
    {
        $out = [];
        foreach ($this->columns($as, $query) as $key => $col) {
            foreach ($col['items'] as $item) {
                $out[$item['key']] = $key;
            }
        }

        return $out;
    }

    // ── Who may read it ──────────────────────────────────────────────────────

    public function test_it_requires_authentication(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_roles_outside_the_operations_audience_are_refused_and_the_page_audience_is_served(): void
    {
        $this->board($this->reporter)->assertForbidden();
        $this->board($this->user('partner'))->assertForbidden();

        foreach (\App\Http\Controllers\Operations\OperationsBoardController::WORK_BOARD_ROLES as $role) {
            $this->board($this->user($role))->assertOk();
        }
    }

    // ── Content and columns ──────────────────────────────────────────────────

    public function test_it_lists_the_tasks_and_assigned_tickets_of_every_assignee_in_the_my_work_columns(): void
    {
        $tasks = [
            'ideas' => $this->task(['status' => 'idea']),
            'planning' => $this->task(['status' => 'planning', 'assigned_to' => $this->bob->id]),
            'in_progress' => $this->task(['status' => 'in_progress']),
            'review' => $this->task(['status' => 'needs_evaluation', 'assigned_to' => $this->bob->id]),
            'completed' => $this->task(['status' => 'completed']),
            'on_hold' => $this->task(['status' => 'cancelled']),
        ];
        $pendingTask = $this->task(['status' => 'in_progress', 'acceptance_status' => 'pending']);
        $declinedTask = $this->task(['status' => 'planning', 'acceptance_status' => 'rejected']);
        $unassignedTask = $this->task(['status' => 'planning', 'assigned_to' => null]);

        $assigned = $this->ticket(['status' => 'ASSIGNED']);
        $progress = $this->ticket(['status' => 'IN_PROGRESS', 'assigned_to_id' => $this->bob->id]);
        $review = $this->ticket(['status' => 'PENDING_REVIEW']);
        $approved = $this->ticket(['status' => 'APPROVED_FOR_DEPLOY', 'requires_deployment' => true]);
        $verify = $this->ticket(['status' => 'PENDING_VERIFICATION', 'requires_deployment' => true, 'assigned_to_id' => $this->bob->id]);
        $resolved = $this->ticket(['status' => 'RESOLVED', 'resolved_at' => now()->subDays(2)]);
        $unresolved = $this->ticket(['status' => 'UNRESOLVED']);
        $declined = $this->ticket(['status' => 'REJECTED_BY_ASSIGNEE']);
        // Not on the board: nobody holds it yet / rejected by the department / a finished one past the window.
        $unassignedTicket = $this->ticket(['status' => 'PENDING_APPROVAL', 'assigned_to_id' => null]);
        $rejected = $this->ticket(['status' => 'REJECTED_BY_ADMIN', 'assigned_to_id' => null]);
        $old = $this->ticket(['status' => 'RESOLVED', 'resolved_at' => now()->subDays(45)]);

        $placed = $this->placement($this->admin);

        foreach ($tasks as $column => $task) {
            $this->assertSame($column, $placed["task:{$task->id}"], "task in {$task->status}");
        }
        $this->assertSame('pending', $placed["task:{$pendingTask->id}"], 'waiting for the member to accept');
        $this->assertSame('closed', $placed["task:{$declinedTask->id}"], 'declined by the member');
        $this->assertSame('planning', $placed["task:{$unassignedTask->id}"], 'a task nobody holds yet is still on the board');

        foreach ([[$assigned, 'pending'], [$progress, 'in_progress'], [$review, 'review'], [$approved, 'review'], [$verify, 'review'], [$resolved, 'completed'], [$unresolved, 'closed'], [$declined, 'closed']] as [$ticket, $column]) {
            $this->assertSame($column, $placed["ticket:{$ticket->id}"], "ticket in {$ticket->status}");
        }
        foreach ([$unassignedTicket, $rejected, $old] as $absent) {
            $this->assertArrayNotHasKey("ticket:{$absent->id}", $placed, "ticket {$absent->status} is not on the board");
        }

        // The response lists every column, in the order of «مهامي», with exact counts.
        $columns = $this->columns($this->admin);
        $this->assertSame(array_keys(WorkBoardColumns::COLUMNS), array_keys($columns));
        $this->assertSame(WorkBoardColumns::COLUMNS['review'], $columns['review']['label']);
        $this->assertSame(['tasks' => 1, 'tickets' => 3], $columns['review']['counts']);
        $this->assertSame(4, $columns['review']['count']);
        $meta = $this->board($this->admin)->json('meta');
        $this->assertSame(count($placed), $meta['counts']['all']);
        $this->assertSame($meta['counts']['all'], $meta['counts']['tasks'] + $meta['counts']['tickets']);
    }

    public function test_every_item_of_my_work_is_on_the_global_board_in_the_same_column(): void
    {
        // Alice's own work: tasks of several states (incl. a collective one) and tickets.
        $parent = $this->task(['title' => 'جماعية', 'status' => 'planning']);
        $child = $this->task(['parent_task_id' => $parent->id, 'status' => 'planning', 'acceptance_status' => 'pending']);
        $this->task(['parent_task_id' => $parent->id, 'status' => 'planning', 'assigned_to' => $this->bob->id]);
        $mine = [
            $this->task(['status' => 'idea']), $this->task(['status' => 'under_review']), $this->task(['status' => 'waiting_approval']),
            $this->task(['status' => 'completed']), $this->task(['status' => 'postponed']), $this->task(['status' => 'in_progress', 'acceptance_status' => 'pending']),
            $this->task(['status' => 'planning', 'acceptance_status' => 'rejected']), $child,
        ];
        // Bob's items must not reach Alice's «مهامي».
        $bobsTask = $this->task(['assigned_to' => $this->bob->id]);
        $bobsTicket = $this->ticket(['assigned_to_id' => $this->bob->id]);
        foreach (['ASSIGNED', 'IN_PROGRESS', 'PENDING_REVIEW', 'RESOLVED', 'UNRESOLVED', 'REJECTED_BY_ASSIGNEE'] as $status) {
            $this->ticket(['status' => $status, 'resolved_at' => $status === 'RESOLVED' ? now()->subDay() : null]);
        }

        $global = $this->placement($this->admin);
        $mineBoard = $this->placement($this->alice, '?scope=mine');

        Sanctum::actingAs($this->alice);
        $myTasks = $this->getJson('/api/my-work/tasks')->assertOk()->json('data');
        $myTickets = $this->getJson('/api/v1/tickets/my-work')->assertOk()->json('data');

        $expected = [];
        foreach ($myTasks as $t) {
            $expected["task:{$t['id']}"] = WorkBoardColumns::forTask($t['status'], $t['acceptance_status']);
        }
        foreach ($myTickets as $t) {
            // The row carries its board column; the board must agree with it.
            $this->assertSame(WorkBoardColumns::forTicket($t['status']), $t['column']);
            $expected["ticket:{$t['id']}"] = $t['column'];
        }
        $this->assertNotEmpty($expected);
        $this->assertNotContains($parent->id, collect($myTasks)->pluck('id')->all(), 'the collective parent is not Alice\'s assignment: her child is');

        foreach ($expected as $key => $column) {
            $this->assertSame($column, $global[$key] ?? null, "{$key} is on the global board in the same column as in «مهامي»");
        }
        // Alice's own view of the board is exactly her «مهامي»; the global one also has Bob's items.
        $this->assertEqualsCanonicalizing(array_keys($expected), array_keys($mineBoard));
        ksort($expected);
        ksort($mineBoard);
        $this->assertSame($expected, $mineBoard);
        $this->assertArrayHasKey("task:{$bobsTask->id}", $global);
        $this->assertArrayHasKey("ticket:{$bobsTicket->id}", $global);
        $this->assertArrayNotHasKey("task:{$bobsTask->id}", $expected);
        $this->assertArrayNotHasKey("ticket:{$bobsTicket->id}", $expected);
        $this->assertNotEmpty($mine);
    }

    public function test_the_my_work_ticket_rows_carry_the_same_column_the_board_uses(): void
    {
        foreach (array_keys(WorkBoardColumns::TICKET_STATUS_COLUMN) as $status) {
            $this->assertSame(WorkBoardColumns::TICKET_STATUS_COLUMN[$status], WorkBoardColumns::forTicket($status));
        }
        $t = $this->ticket(['status' => 'PENDING_REVIEW']);
        Sanctum::actingAs($this->alice);
        $this->assertSame('review', collect($this->getJson('/api/v1/tickets/my-work')->json('data'))->firstWhere('id', $t->id)['column']);
    }

    public function test_the_php_column_map_equals_the_frontend_map_when_the_frontend_checkout_is_present(): void
    {
        $file = base_path('../emc-frontend/src/lib/myWorkColumns.ts');
        if (! is_file($file)) {
            $this->markTestSkipped('the frontend checkout is not next to this repository');
        }
        $src = (string) file_get_contents($file);
        $block = function (string $name) use ($src): array {
            $this->assertSame(1, preg_match('/const '.$name.': Record<string, MyWorkColumnKey> = \{(.*?)\n\}/s', $src, $m), "{$name} found in the frontend");
            preg_match_all("/^\s*([A-Za-z_]+):\s*'([a-z_]+)'/m", $m[1], $pairs, PREG_SET_ORDER);

            return collect($pairs)->mapWithKeys(fn ($p) => [$p[1] => $p[2]])->all();
        };

        $this->assertEquals($block('TASK_STATUS_COLUMN'), WorkBoardColumns::TASK_STATUS_COLUMN);
        $this->assertEquals($block('TICKET_STATUS_COLUMN'), WorkBoardColumns::TICKET_STATUS_COLUMN);
        preg_match_all("/key: '([a-z_]+)', label: '([^']+)'/", $src, $cols, PREG_SET_ORDER);
        $this->assertSame(collect($cols)->mapWithKeys(fn ($c) => [$c[1] => $c[2]])->all(), WorkBoardColumns::COLUMNS);
    }

    // ── Duplicates ───────────────────────────────────────────────────────────

    public function test_no_card_appears_twice(): void
    {
        // A collective task: the parent (assigned to its first member) and one child per member.
        $parent = $this->task(['title' => 'جماعية', 'assigned_to' => $this->alice->id]);
        $childA = $this->task(['parent_task_id' => $parent->id, 'assigned_to' => $this->alice->id]);
        $childB = $this->task(['parent_task_id' => $parent->id, 'assigned_to' => $this->bob->id]);
        // A container without an assignee, and a parent whose assignee has NO child of their own (it is that person's own work).
        $container = $this->task(['assigned_to' => null]);
        $containerChild = $this->task(['parent_task_id' => $container->id, 'assigned_to' => $this->bob->id]);
        $soloParent = $this->task(['assigned_to' => $this->viewer->id]);
        $soloChild = $this->task(['parent_task_id' => $soloParent->id, 'assigned_to' => $this->bob->id]);
        // A ticket and a task may share a numeric id: their keys differ and both stay.
        $ticket = $this->ticket(['status' => 'IN_PROGRESS']);
        DB::table('tasks')->where('id', $childA->id)->update(['status' => 'in_progress']);

        $cards = collect($this->columns($this->admin))->pluck('items')->flatten(1);
        $keys = $cards->pluck('key');
        $this->assertSame($keys->unique()->count(), $keys->count(), 'one card per item');

        $this->assertNotContains("task:{$parent->id}", $keys->all(), 'a parent whose assignee also owns a child is the child\'s container');
        $this->assertContains("task:{$childA->id}", $keys->all());
        $this->assertContains("task:{$childB->id}", $keys->all());
        $this->assertNotContains("task:{$container->id}", $keys->all(), 'a parent nobody holds is only a container');
        $this->assertContains("task:{$containerChild->id}", $keys->all());
        $this->assertContains("task:{$soloParent->id}", $keys->all(), 'a parent whose assignee has no child of their own is that person\'s work (as in «مهامي»)');
        $this->assertContains("task:{$soloChild->id}", $keys->all());
        $this->assertContains("ticket:{$ticket->id}", $keys->all());
        $this->assertSame($parent->id, $cards->firstWhere('key', "task:{$childA->id}")['group_id']);
        // No row of the tickets table is mirrored into the tasks table.
        $this->assertSame(0, Task::query()->whereNotNull('related_type')->count());
    }

    // ── Public-safe projection ───────────────────────────────────────────────

    public function test_cards_are_a_whitelist_and_leak_nothing_sensitive(): void
    {
        $task = $this->task([
            'description' => 'SECRET-DESC', 'notes' => 'SECRET-NOTES', 'rejection_reason' => 'SECRET-REJECT', 'status' => 'needs_evaluation',
            'due_datetime' => now()->addDay(), 'task_scope' => 'simple', 'points' => 30, 'estimated_hours' => 2,
        ]);
        TaskEvaluation::create(['task_id' => $task->id, 'evaluator_id' => $this->admin->id, 'delivered_on_time' => 5, 'quality_of_outputs' => 5, 'autonomy' => 5, 'responsiveness' => 5, 'collaboration_na' => true, 'final_score' => 4.5, 'feedback' => 'SECRET-FEEDBACK']);
        TaskComment::create(['task_id' => $task->id, 'user_id' => $this->admin->id, 'comment' => 'SECRET-COMMENT']);
        TaskAttachment::create(['task_id' => $task->id, 'user_id' => $this->admin->id, 'file_path' => 'tasks/SECRET-FILE.pdf', 'file_name' => 'SECRET-FILE.pdf']);

        $ticket = $this->ticket([
            'status' => 'PENDING_VERIFICATION', 'requires_deployment' => true, 'description' => 'SECRET-DESC', 'internal_notes' => 'SECRET-INTERNAL',
            'created_by_email' => 'secret-mail@example.com', 'created_by_name' => 'SECRET-REPORTER', 'resolution_summary' => 'SECRET-RESOLUTION',
            'expected_resolution_time' => now()->addDay(),
        ]);
        $ticket->forceFill(['live_url' => 'https://SECRET-LIVE.example.com', 'credited_points' => 30, 'credited_hours' => 1.5, 'actual_minutes' => 90])->save();

        foreach ([$this->admin, $this->viewer, $this->leaderA, $this->alice] as $viewer) {
            $raw = $this->board($viewer)->assertOk()->getContent();
            foreach (self::SECRETS as $secret) {
                $this->assertStringNotContainsString($secret, (string) $raw, "{$secret} must not reach {$viewer->role}");
            }
        }

        $cards = collect($this->columns($this->admin))->pluck('items')->flatten(1)->keyBy('key');
        $allowed = ['key', 'kind', 'id', 'reference', 'title', 'masked', 'column', 'status', 'acceptance', 'stage', 'stage_badge', 'next_step', 'priority', 'due_at', 'is_overdue',
            'assignee', 'department', 'scope', 'points', 'hours', 'estimate_hidden', 'is_locked', 'group_id', 'is_mine', 'updated_at', 'capabilities'];
        foreach ($cards as $card) {
            $this->assertSame([], array_values(array_diff(array_keys($card), $allowed)), 'only whitelisted keys on a card');
            $this->assertSame(['id', 'name'], array_keys($card['assignee']));
            $this->assertSame(['id', 'name'], array_keys($card['department']));
        }
        foreach (['description', 'notes', 'comments', 'attachments', 'evaluation', 'checklist_items', 'internal_notes', 'credit', 'deployment', 'live_url', 'created_by_name', 'created_by_email', 'rejection_reason', 'activity_logs'] as $key) {
            foreach ($cards as $card) {
                $this->assertArrayNotHasKey($key, $card);
            }
        }

        $card = $cards["ticket:{$ticket->id}"];
        $this->assertSame('PENDING_VERIFICATION', $card['status']);
        $this->assertSame('pending_verification', $card['stage']);
        $this->assertSame('بانتظار اختبار المكلّف', $card['stage_badge']);
        $this->assertSame($ticket->ticket_number, $card['reference']);
        $this->assertSame(['id' => $this->alice->id, 'name' => $this->alice->name], $card['assignee']);
        $this->assertSame(['id' => $this->deptA->id, 'name' => 'إدارة التقنية'], $card['department']);
        $this->assertSame('2026-11-24T12:00:00+00:00', $card['due_at']);

        $taskCard = $cards["task:{$task->id}"];
        $this->assertSame(['task', null, 'review', 'needs_evaluation'], [$taskCard['kind'], $taskCard['reference'], $taskCard['column'], $taskCard['status']]);
        $this->assertEquals(['simple', 30, 2.0, false], [$taskCard['scope'], $taskCard['points'], $taskCard['hours'], $taskCard['estimate_hidden']]);
    }

    public function test_a_tickets_estimate_is_shown_only_to_those_who_may_read_it_on_the_ticket(): void
    {
        $t = $this->ticket(['status' => 'IN_PROGRESS']);
        $read = fn (User $u) => collect($this->columns($u)['in_progress']['items'])->firstWhere('key', "ticket:{$t->id}");

        foreach ([$this->admin, $this->leaderA, $this->alice] as $internal) {
            $card = $read($internal);
            $this->assertEquals([30, 1.5, 'simple', false], [$card['points'], $card['hours'], $card['scope'], $card['estimate_hidden']], $internal->role);
        }
        $plain = $read($this->viewer);
        $this->assertSame([null, null, null, true], [$plain['points'], $plain['hours'], $plain['scope'], $plain['estimate_hidden']]);
    }

    public function test_a_submitter_keeps_the_folded_review_stage_on_the_board(): void
    {
        // A volunteer who only REPORTED the ticket (not its assignee, not a leader): the internal steps read IN_PROGRESS to them.
        $reporter = $this->user('volunteer');
        $t = $this->ticket(['status' => 'PENDING_REVIEW', 'created_by_id' => $reporter->id]);

        $own = collect($this->columns($reporter)['in_progress']['items'])->firstWhere('key', "ticket:{$t->id}");
        $this->assertNotNull($own);
        $this->assertSame(['IN_PROGRESS', 'in_progress', null, null], [$own['status'], $own['column'], $own['stage'], $own['stage_badge']]);
        $this->assertSame(0, $this->columns($reporter)['review']['count'], 'counts are folded too');

        $other = collect($this->columns($this->viewer)['review']['items'])->firstWhere('key', "ticket:{$t->id}");
        $this->assertSame(['PENDING_REVIEW', 'review', 'pending_review'], [$other['status'], $other['column'], $other['stage']]);
    }

    // ── Authorisation stays independent ──────────────────────────────────────

    public function test_can_open_is_computed_with_the_viewers_real_access(): void
    {
        $inA = $this->ticket(['department_id' => $this->deptA->id]);
        $inB = $this->ticket(['department_id' => $this->deptB->id]);
        $bobsInB = $this->ticket(['department_id' => $this->deptB->id, 'assigned_to_id' => $this->viewer->id]);
        $createdByViewer = $this->ticket(['department_id' => $this->deptB->id, 'created_by_id' => $this->viewer->id, 'assigned_to_id' => $this->bob->id]);
        $aliceTask = $this->task();
        $viewerTask = $this->task(['assigned_to' => $this->viewer->id, 'department_id' => $this->deptB->id]);

        $open = function (User $u) {
            $cards = collect($this->columns($u))->pluck('items')->flatten(1)->keyBy('key');

            return $cards->map(fn ($c) => $c['capabilities']['can_open'])->all();
        };

        $mine = $open($this->viewer);
        $this->assertTrue($mine["ticket:{$inA->id}"], 'a member of the ticket\'s department');
        $this->assertFalse($mine["ticket:{$inB->id}"], 'another department: visible on the board, NOT openable');
        $this->assertTrue($mine["ticket:{$bobsInB->id}"], 'the assignee');
        $this->assertTrue($mine["ticket:{$createdByViewer->id}"], 'the creator');
        $this->assertFalse($mine["task:{$aliceTask->id}"], 'a task of someone else');
        $this->assertTrue($mine["task:{$viewerTask->id}"], 'own task');

        $admin = $open($this->admin);
        $this->assertTrue($admin["ticket:{$inB->id}"]);
        $this->assertTrue($admin["task:{$aliceTask->id}"], 'a task-management role');

        $leader = $open($this->leaderA);
        $this->assertTrue($leader["ticket:{$inA->id}"]);
        $this->assertFalse($leader["ticket:{$inB->id}"]);
        $this->assertFalse($leader["task:{$aliceTask->id}"], 'a department leader is not a task-management role');
        $this->assertTrue($open($this->user('operations_manager'))["task:{$aliceTask->id}"]);

        // The flag is not decoration: a refused viewer really cannot open it.
        Sanctum::actingAs($this->viewer);
        $this->getJson("/api/v1/tickets/{$inA->id}")->assertOk();
        $this->getJson("/api/v1/tickets/{$inB->id}")->assertForbidden();
    }

    public function test_a_view_only_viewer_gets_no_administrative_action_from_the_board(): void
    {
        $this->task(['status' => 'needs_evaluation']);
        $this->task(['status' => 'planning', 'acceptance_status' => 'rejected']);
        $this->task(['status' => 'planning', 'assigned_to' => null]);
        foreach (['ASSIGNED', 'IN_PROGRESS', 'PENDING_REVIEW', 'APPROVED_FOR_DEPLOY', 'REJECTED_BY_ASSIGNEE'] as $status) {
            $this->ticket(['status' => $status, 'requires_deployment' => in_array($status, ['APPROVED_FOR_DEPLOY'], true)]);
        }

        foreach ([$this->viewer, $this->leaderA, $this->admin] as $viewer) {
            foreach (collect($this->columns($viewer))->pluck('items')->flatten(1) as $card) {
                $this->assertSame(['can_open', 'can_accept', 'can_reject', 'can_complete'], array_keys($card['capabilities']), 'no approve / reassign / review / lock / evaluate flag exists on a card');
                $this->assertFalse($card['capabilities']['can_accept'] || $card['capabilities']['can_reject'] || $card['capabilities']['can_complete'], "{$viewer->role} is nobody's assignee here: no personal action either");
                $this->assertFalse($card['is_mine']);
            }
        }
    }

    public function test_the_assignees_own_actions_are_offered_to_the_assignee_only(): void
    {
        $assigned = $this->ticket(['status' => 'ASSIGNED']);
        $running = $this->ticket(['status' => 'IN_PROGRESS']);
        $pendingTask = $this->task(['acceptance_status' => 'pending']);

        $cards = collect($this->columns($this->alice))->pluck('items')->flatten(1)->keyBy('key');
        $this->assertSame(['can_open' => true, 'can_accept' => true, 'can_reject' => true, 'can_complete' => false], $cards["ticket:{$assigned->id}"]['capabilities']);
        $this->assertSame(['can_open' => true, 'can_accept' => false, 'can_reject' => false, 'can_complete' => true], $cards["ticket:{$running->id}"]['capabilities']);
        $this->assertSame(['can_open' => true, 'can_accept' => true, 'can_reject' => true, 'can_complete' => false], $cards["task:{$pendingTask->id}"]['capabilities']);
        $this->assertTrue($cards["ticket:{$running->id}"]['is_mine']);

        // A global role could act for the assignee elsewhere; the board never offers it.
        $admin = collect($this->columns($this->admin))->pluck('items')->flatten(1)->keyBy('key');
        $this->assertFalse($admin["ticket:{$assigned->id}"]['capabilities']['can_accept']);
        $this->assertFalse($admin["task:{$pendingTask->id}"]['capabilities']['can_accept']);
    }

    // ── Filters, paging, cost ────────────────────────────────────────────────

    public function test_filters_narrow_every_count_and_card(): void
    {
        $a1 = $this->task(['title' => 'تقرير الأسبوع', 'department_id' => $this->deptA->id]);
        $b1 = $this->task(['title' => 'حملة التسويق', 'department_id' => $this->deptB->id]);
        $tA = $this->ticket(['title' => 'عطل البريد', 'department_id' => $this->deptA->id]);
        $tB = $this->ticket(['title' => 'عطل الموقع', 'department_id' => $this->deptB->id]);
        $special = $this->task(['title' => '100% جاهز_تماماً']);

        $ids = fn (string $q) => array_keys($this->placement($this->admin, $q));

        $this->assertEqualsCanonicalizing(["task:{$a1->id}", "ticket:{$tA->id}", "task:{$special->id}"], $ids('?department_id='.$this->deptA->id));
        $this->assertEqualsCanonicalizing(["task:{$b1->id}", "ticket:{$tB->id}"], $ids('?department_id='.$this->deptB->id));
        $this->assertEqualsCanonicalizing(["ticket:{$tA->id}", "ticket:{$tB->id}"], $ids('?kind=tickets'));
        $this->assertEqualsCanonicalizing(["task:{$a1->id}", "task:{$b1->id}", "task:{$special->id}"], $ids('?kind=tasks'));
        $this->assertSame(["ticket:{$tA->id}", "ticket:{$tB->id}"], collect($ids('?search='.urlencode('عطل')))->sort()->values()->all());
        $this->assertSame(["ticket:{$tA->id}"], $ids('?search='.urlencode($tA->ticket_number)));
        $this->assertSame(["task:{$special->id}"], $ids('?search='.urlencode('100%')), 'a typed % is searched literally');
        $this->assertSame(["task:{$special->id}"], $ids('?search='.urlencode('%')), 'a bare % is a character, not a wildcard');
        $this->assertSame(["task:{$special->id}"], $ids('?search='.urlencode('_')), 'a bare _ is a character, not a wildcard');

        $meta = $this->board($this->admin, '?kind=tickets&department_id='.$this->deptA->id)->json('meta');
        $this->assertSame(['all' => 1, 'tasks' => 0, 'tickets' => 1], $meta['counts']);
    }

    public function test_invalid_parameters_are_refused(): void
    {
        foreach (['?scope=everyone', '?kind=x', '?column=nope', '?per_column=0', '?per_column=101', '?department_id=abc', '?page=2', '?column=review&page=0'] as $q) {
            $this->board($this->admin, $q)->assertStatus(422);
        }
        $this->board($this->admin, '?column=review&page=50&per_column=100')->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_a_column_is_capped_with_exact_counts_and_can_be_paged(): void
    {
        foreach (range(1, 35) as $i) {
            $this->task(['status' => 'in_progress', 'title' => "م{$i}", 'updated_at' => now()->subMinutes($i)]);
        }
        foreach (range(1, 5) as $i) {
            $this->ticket(['status' => 'IN_PROGRESS']);
        }

        $first = $this->columns($this->admin, '?per_column=10')['in_progress'];
        $this->assertSame([40, 10, true], [$first['count'], count($first['items']), $first['has_more']]);
        $this->assertSame(0, $this->columns($this->admin, '?per_column=10')['planning']['count']);
        $whole = $this->board($this->admin, '?per_column=10')->json('meta.counts');
        $this->assertSame(['all' => 40, 'tasks' => 35, 'tickets' => 5], $whole);

        $seen = [];
        foreach ([1, 2, 3, 4] as $page) {
            $res = $this->board($this->admin, "?column=in_progress&per_column=10&page={$page}")->assertOk();
            $col = $res->json('data.columns');
            $this->assertCount(1, $col, 'one column when a column is asked for');
            $this->assertSame($page, $res->json('meta.page'));
            $this->assertSame($whole, $res->json('meta.counts'), 'with a column asked for, meta.counts are still the totals of the whole board');
            foreach ($col[0]['items'] as $item) {
                $this->assertArrayNotHasKey($item['key'], $seen, 'no card on two pages');
                $seen[$item['key']] = true;
            }
            $this->assertSame($page < 4, $col[0]['has_more']);
        }
        $this->assertCount(40, $seen);
    }

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_cards(): void
    {
        $seed = function (int $n) {
            foreach (range(1, $n) as $i) {
                foreach (['idea', 'planning', 'in_progress', 'needs_evaluation', 'completed', 'cancelled'] as $status) {
                    $this->task(['status' => $status, 'assigned_to' => [$this->alice->id, $this->bob->id][$i % 2], 'department_id' => [$this->deptA->id, $this->deptB->id][$i % 2], 'due_date' => now()->addDays($i)]);
                }
                foreach (['ASSIGNED', 'IN_PROGRESS', 'PENDING_REVIEW', 'RESOLVED', 'UNRESOLVED'] as $status) {
                    $this->ticket(['status' => $status, 'assigned_to_id' => [$this->alice->id, $this->bob->id][$i % 2], 'department_id' => [$this->deptA->id, $this->deptB->id][$i % 2], 'resolved_at' => $status === 'RESOLVED' ? now()->subDay() : null]);
                }
            }
        };
        $count = function (): int {
            Sanctum::actingAs($this->viewer);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(self::URL)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $seed(2);
        $small = $count();
        $seed(12);
        $large = $count();

        $this->assertSame($small, $large, 'a fixed number of queries whatever the number of cards');
        $this->assertLessThanOrEqual(45, $large, 'and a small one');
    }

    public function test_the_existing_operations_board_endpoint_is_unchanged(): void
    {
        $t = $this->task(['status' => 'planning', 'description' => 'SECRET-DESC']);
        $ticket = $this->ticket();

        $ops = $this->user('operations_manager');
        Sanctum::actingAs($ops);
        $board = $this->getJson('/api/operations/board')->assertOk()->json('data');
        $this->assertSame(['idea', 'under_review', 'planning', 'waiting_approval', 'in_progress', 'needs_evaluation', 'completed', 'postponed', 'cancelled'], array_keys($board));
        $this->assertSame($t->id, $board['planning'][0]['id']);
        $this->assertSame('SECRET-DESC', $board['planning'][0]['description'], 'the management board still carries the full task (its own consumers)');
        $this->assertNotContains($ticket->id, collect($board)->flatten(1)->pluck('ticket_number')->all());
    }

    public function test_the_response_envelope(): void
    {
        $task = $this->task(['status' => 'planning']);
        $json = $this->board($this->admin)->assertOk()->json();

        $this->assertSame(['success', 'data', 'meta'], array_keys($json));
        $this->assertSame(['columns'], array_keys($json['data']));
        $this->assertSame(['key', 'label', 'count', 'counts', 'items', 'has_more'], array_keys($json['data']['columns'][0]));
        $this->assertSame(['scope', 'kind', 'per_column', 'column', 'page', 'counts', 'generated_at'], array_keys($json['meta']));
        $this->assertSame(OperationsWorkBoard::DEFAULT_PER_COLUMN, $json['meta']['per_column']);
        $this->assertSame('all', $json['meta']['scope']);
        $this->assertSame($task->id, collect($json['data']['columns'])->firstWhere('key', 'planning')['items'][0]['id']);
    }

    // ── Masking, search and reporters ────────────────────────────────────────

    public function test_a_ticket_the_viewer_cannot_open_is_masked_but_keeps_its_place_on_the_board(): void
    {
        $open = $this->ticket(['title' => 'عنوان ظاهر', 'department_id' => $this->deptA->id, 'status' => 'PENDING_REVIEW', 'expected_resolution_time' => now()->subDay()]);
        $hidden = $this->ticket(['title' => 'عنوان سري للمُبلِّغ', 'department_id' => $this->deptB->id, 'status' => 'PENDING_REVIEW', 'priority' => 'HIGH', 'expected_resolution_time' => now()->subDays(2)]);

        $raw = $this->board($this->viewer)->assertOk()->getContent();
        $this->assertStringNotContainsString('عنوان سري للمُبلِّغ', (string) $raw);
        $this->assertStringNotContainsString($hidden->ticket_number, (string) $raw);

        $cards = collect($this->columns($this->viewer)['review']['items'])->keyBy('key');
        $mask = $cards["ticket:{$hidden->id}"];
        $this->assertSame([true, \App\Services\OperationsWorkBoard::MASKED_TITLE, null, false], [$mask['masked'], $mask['title'], $mask['reference'], $mask['capabilities']['can_open']]);
        // What shows the work distribution stays.
        $this->assertSame(['review', 'PENDING_REVIEW', 'pending_review', 'high', $this->alice->id, 'إدارة التسويق'], [$mask['column'], $mask['status'], $mask['stage'], $mask['priority'], $mask['assignee']['id'], $mask['department']['name']]);
        $this->assertSame('بانتظار المراجعة', $mask['stage_badge']);
        $this->assertSame([null, false], [$mask['due_at'], $mask['is_overdue']], 'lateness of a ticket the viewer cannot open does not leak');

        $shown = $cards["ticket:{$open->id}"];
        $this->assertSame([false, 'عنوان ظاهر', $open->ticket_number], [$shown['masked'], $shown['title'], $shown['reference']]);
        $this->assertSame([true, now()->subDay()->toIso8601String()], [$shown['is_overdue'], $shown['due_at']], 'an openable ticket keeps its real deadline');
        // The column is still ordered by the REAL deadlines (the more overdue, hidden one comes first).
        $this->assertSame(["ticket:{$hidden->id}", "ticket:{$open->id}"], collect($this->columns($this->viewer)['review']['items'])->pluck('key')->take(2)->all());
        // A viewer who can open everything sees everything; tasks are never masked.
        $this->assertSame('عنوان سري للمُبلِّغ', collect($this->columns($this->admin)['review']['items'])->firstWhere('key', "ticket:{$hidden->id}")['title']);
        $task = $this->task(['title' => 'عنوان مهمة']);
        $this->assertSame([false, 'عنوان مهمة'], [collect($this->columns($this->viewer)['in_progress']['items'])->firstWhere('key', "task:{$task->id}")['masked'], 'عنوان مهمة']);
    }

    public function test_search_never_matches_a_ticket_the_viewer_cannot_open(): void
    {
        $mine = $this->ticket(['title' => 'عطل ظاهر', 'department_id' => $this->deptA->id]);
        $hidden = $this->ticket(['title' => 'عطل مخفي', 'department_id' => $this->deptB->id]);

        $ids = fn (User $u, string $q) => array_keys($this->placement($u, '?search='.urlencode($q)));
        $this->assertSame(["ticket:{$mine->id}"], $ids($this->viewer, 'عطل'));
        $this->assertSame([], $ids($this->viewer, 'مخفي'), 'no title oracle');
        $this->assertSame([], $ids($this->viewer, $hidden->ticket_number), 'nor a number oracle');
        $this->assertSame(["ticket:{$hidden->id}"], $ids($this->admin, 'مخفي'));
        // The department filter itself is unaffected: the masked card is still listed.
        $this->assertContains("ticket:{$hidden->id}", array_keys($this->placement($this->viewer, '?department_id='.$this->deptB->id)));
        $this->assertSame(['all' => 0, 'tasks' => 0, 'tickets' => 0], $this->board($this->viewer, '?search='.urlencode('مخفي'))->json('meta.counts'));
    }

    public function test_a_submitter_gets_no_assignee_and_no_deadline_on_the_board(): void
    {
        $reporter = $this->user('volunteer');
        $t = $this->ticket(['status' => 'IN_PROGRESS', 'created_by_id' => $reporter->id, 'expected_resolution_time' => now()->subDay()]);

        $own = collect($this->columns($reporter)['in_progress']['items'])->firstWhere('key', "ticket:{$t->id}");
        $this->assertNotNull($own);
        $this->assertSame([null, null, false, true, false], [$own['assignee'], $own['due_at'], $own['is_overdue'], $own['capabilities']['can_open'], $own['masked']]);

        $other = collect($this->columns($this->viewer)['in_progress']['items'])->firstWhere('key', "ticket:{$t->id}");
        $this->assertSame([$this->alice->id, true], [$other['assignee']['id'], $other['is_overdue']]);
        $this->assertNotNull($other['due_at']);
    }
}
