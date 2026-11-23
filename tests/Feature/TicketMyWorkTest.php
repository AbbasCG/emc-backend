<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /v1/tickets/my-work: the caller's OWN assigned tickets, derived from tickets (no Task rows).
 */
class TicketMyWorkTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/tickets/my-work';

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
            'ticket_number' => 'EMC-TCK-M-'.Str::random(8),
            'title' => 'تذكرة '.Str::random(4),
            'description' => "وصف\n  طويل   للتذكرة",
            'ticket_category' => 'OLD_ISSUE',
            'priority' => 'MEDIUM',
            'department_id' => $this->dept->id,
            'created_by_id' => $this->reporter->id,
            'created_by_name' => 'مُبلِّغ',
            'status' => 'ASSIGNED',
            'assigned_to_id' => $this->alice->id,
            'task_scope' => 'simple', 'task_points' => 30, 'estimated_hours' => 1.5,
        ], $over));
    }

    private function myWork(User $as, string $query = '')
    {
        Sanctum::actingAs($as);

        return $this->getJson(self::URL.$query);
    }

    /** @return array<int, int> */
    private function ids(User $as, string $query = ''): array
    {
        return collect($this->myWork($as, $query)->assertOk()->json('data'))->pluck('id')->all();
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_it_returns_only_the_callers_own_assignments(): void
    {
        $mine = $this->ticket();
        $mineActive = $this->ticket(['status' => 'IN_PROGRESS', 'accepted_at' => now()]);
        $bobs = $this->ticket(['assigned_to_id' => $this->bob->id]);
        $unassigned = $this->ticket(['assigned_to_id' => null]);
        $pending = $this->ticket(['status' => 'PENDING_APPROVAL', 'assigned_to_id' => null]);

        $ids = $this->ids($this->alice);
        $this->assertEqualsCanonicalizing([$mine->id, $mineActive->id], $ids);
        $this->assertNotContains($bobs->id, $ids);
        $this->assertNotContains($unassigned->id, $ids);
        $this->assertNotContains($pending->id, $ids);

        $this->assertSame([$bobs->id], $this->ids($this->bob));
    }

    public function test_no_query_parameter_can_widen_the_set(): void
    {
        $mine = $this->ticket();
        $bobs = $this->ticket(['assigned_to_id' => $this->bob->id]);
        $otherDept = Department::create(['name' => 'X '.Str::random(5), 'name_ar' => 'أخرى', 'slug' => 'x-'.Str::random(8), 'status' => 'active', 'is_active' => true]);
        $foreign = $this->ticket(['assigned_to_id' => $this->bob->id, 'department_id' => $otherDept->id]);

        foreach ([
            "?assigned_to_id={$this->bob->id}", "?user_id={$this->bob->id}", "?assignee={$this->bob->id}", '?mine=0', '?all=1',
            "?department_id={$otherDept->id}", '?scope=all', '?per_page=1000', "?created_by_id={$this->reporter->id}", '?assigned_to_id=', '?assigned_to_id[]=1&assigned_to_id[]=2',
        ] as $query) {
            $this->assertSame([$mine->id], $this->ids($this->alice, $query), "query {$query} must not widen the set");
        }

        // A department leader and a global admin get their OWN assignments too, not the whole department's.
        $this->assertSame([], $this->ids($this->leader));
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->assertSame([], $this->ids($admin));
        $this->assertContains($bobs->id, $this->ids($this->bob));
        $this->assertContains($foreign->id, $this->ids($this->bob));
    }

    public function test_the_submitter_of_a_ticket_does_not_see_it_as_work(): void
    {
        $this->ticket();

        $this->assertSame([], $this->ids($this->reporter));
    }

    public function test_row_contract_and_capabilities_per_status(): void
    {
        $unit = \App\Models\DepartmentalUnit::create(['department_id' => $this->dept->id, 'code' => 'U'.Str::random(5), 'name_ar' => 'وحدة الدعم', 'is_active' => true]);
        $pending = $this->ticket(['unit_id' => $unit->id, 'expected_resolution_time' => now()->addDay(), 'priority' => 'HIGH']);
        $active = $this->ticket(['status' => 'IN_PROGRESS', 'accepted_at' => now()->subHour(), 'expected_resolution_time' => now()->subHour()]);

        $rows = collect($this->myWork($this->alice)->assertOk()->assertJsonPath('success', true)->json('data'))->keyBy('id');

        $row = $rows[$pending->id];
        foreach (['id', 'ticket_number', 'title', 'description', 'status', 'priority', 'department', 'unit', 'created_by_name', 'assigned_to_id', 'expected_resolution_time', 'is_delayed',
            'accepted_at', 'resolved_at', 'task_scope', 'task_points', 'estimated_hours', 'created_at', 'updated_at', 'capabilities'] as $key) {
            $this->assertArrayHasKey($key, $row, "missing {$key}");
        }
        $this->assertSame(['id' => $this->dept->id, 'name' => 'إدارة التقنية'], $row['department']);
        $this->assertSame(['id' => $unit->id, 'name' => 'وحدة الدعم'], $row['unit']);
        $this->assertSame('وصف طويل للتذكرة', $row['description'], 'whitespace collapsed');
        $this->assertSame([30, 'simple', 1.5, 'HIGH'], [$row['task_points'], $row['task_scope'], $row['estimated_hours'], $row['priority']]);
        $this->assertSame(['can_accept' => true, 'can_reject' => true, 'can_complete' => false, 'can_open' => true], $row['capabilities']);
        $this->assertFalse($row['is_delayed']);

        $this->assertSame(['can_accept' => false, 'can_reject' => false, 'can_complete' => true, 'can_open' => true], $rows[$active->id]['capabilities']);
        $this->assertTrue($rows[$active->id]['is_delayed']);

        // Internal planning/management data does not leak into the row.
        foreach (['internal_notes', 'admin_rejection_reason', 'created_by_email'] as $key) {
            $this->assertArrayNotHasKey($key, $row);
        }
    }

    public function test_pending_receipt_comes_first_then_by_due_date_with_no_deadline_last(): void
    {
        $inProgressSoon = $this->ticket(['status' => 'IN_PROGRESS', 'expected_resolution_time' => now()->addHours(1)]);
        $assignedLater = $this->ticket(['expected_resolution_time' => now()->addDays(3)]);
        $assignedNoDue = $this->ticket(['expected_resolution_time' => null]);
        $assignedSoon = $this->ticket(['expected_resolution_time' => now()->addHours(5)]);
        $inProgressNoDue = $this->ticket(['status' => 'IN_PROGRESS', 'expected_resolution_time' => null]);

        $this->assertSame(
            [$assignedSoon->id, $assignedLater->id, $assignedNoDue->id, $inProgressSoon->id, $inProgressNoDue->id],
            $this->ids($this->alice),
        );
    }

    public function test_accepting_moves_the_ticket_to_in_progress_on_the_next_call(): void
    {
        $t = $this->ticket();

        $before = collect($this->myWork($this->alice)->json('data'))->firstWhere('id', $t->id);
        $this->assertSame('ASSIGNED', $before['status']);
        $this->assertTrue($before['capabilities']['can_accept']);

        $this->postJson("/api/v1/tickets/{$t->id}/accept-task")->assertOk();

        $after = collect($this->myWork($this->alice)->json('data'))->firstWhere('id', $t->id);
        $this->assertSame('IN_PROGRESS', $after['status']);
        $this->assertNotNull($after['accepted_at']);
        $this->assertSame(['can_accept' => false, 'can_reject' => false, 'can_complete' => true, 'can_open' => true], $after['capabilities']);
    }

    public function test_reassigning_moves_the_ticket_between_users_immediately(): void
    {
        $t = $this->ticket(['status' => 'IN_PROGRESS', 'accepted_at' => now()]);
        $this->assertSame([$t->id], $this->ids($this->alice));
        $this->assertSame([], $this->ids($this->bob));

        Sanctum::actingAs($this->leader);
        $this->postJson("/api/v1/tickets/{$t->id}/reassign", ['assigned_to_id' => $this->bob->id])->assertOk();

        $this->assertSame([], $this->ids($this->alice), 'the previous assignee stops seeing it at once');
        $bobRows = $this->myWork($this->bob)->json('data');
        $this->assertCount(1, $bobRows);
        $this->assertSame('ASSIGNED', $bobRows[0]['status'], 'the new assignee sees it pending receipt');
        $this->assertNull($bobRows[0]['accepted_at']);
        $this->assertTrue($bobRows[0]['capabilities']['can_accept']);
    }

    public function test_the_closed_column_shows_the_last_30_days_of_the_users_own_outcomes(): void
    {
        $resolved = $this->ticket(['status' => 'RESOLVED', 'resolved_at' => now()->subDays(5)]);
        $resolvedOld = $this->ticket(['status' => 'RESOLVED', 'resolved_at' => now()->subDays(40)]);
        $unresolved = $this->ticket(['status' => 'UNRESOLVED']);
        $declined = $this->ticket(['status' => 'REJECTED_BY_ASSIGNEE']);
        $declinedOld = $this->ticket(['status' => 'REJECTED_BY_ASSIGNEE']);
        $rejectedByAdmin = $this->ticket(['status' => 'REJECTED_BY_ADMIN']);
        $deleted = $this->ticket(['status' => 'ASSIGNED']);

        // updated_at is what dates an outcome that has no timestamp of its own.
        DB::table('tickets')->where('id', $resolvedOld->id)->update(['updated_at' => now()->subDays(40)]);
        DB::table('tickets')->where('id', $declinedOld->id)->update(['updated_at' => now()->subDays(31)]);
        DB::table('tickets')->where('id', $resolved->id)->update(['updated_at' => now()->subDays(5)]);
        $deleted->delete();

        $ids = $this->ids($this->alice);
        $this->assertEqualsCanonicalizing([$resolved->id, $unresolved->id, $declined->id], $ids);
        $this->assertNotContains($resolvedOld->id, $ids);
        $this->assertNotContains($declinedOld->id, $ids);
        $this->assertNotContains($rejectedByAdmin->id, $ids);
        $this->assertNotContains($deleted->id, $ids);

        $closedRow = collect($this->myWork($this->alice)->json('data'))->firstWhere('id', $resolved->id);
        $this->assertSame(['can_accept' => false, 'can_reject' => false, 'can_complete' => false, 'can_open' => true], $closedRow['capabilities']);
        $this->assertNotNull($closedRow['resolved_at']);
    }

    public function test_the_status_filter_only_narrows(): void
    {
        $assigned = $this->ticket();
        $active = $this->ticket(['status' => 'IN_PROGRESS']);
        $resolved = $this->ticket(['status' => 'RESOLVED', 'resolved_at' => now()->subDay()]);

        $this->assertSame([$assigned->id], $this->ids($this->alice, '?status=ASSIGNED'));
        $this->assertEqualsCanonicalizing([$active->id, $resolved->id], $this->ids($this->alice, '?status=IN_PROGRESS,RESOLVED'));
        $this->assertSame([], $this->ids($this->bob, '?status=ASSIGNED'));
        $this->assertCount(3, $this->ids($this->alice, '?status='), 'an empty filter means no filter');

        // A status this screen does not show (and a made-up one) is refused, not silently widened.
        $this->myWork($this->alice, '?status=PENDING_APPROVAL')->assertStatus(422)->assertJsonPath('success', false);
        $this->myWork($this->alice, '?status=BOGUS')->assertStatus(422);
    }

    public function test_opening_the_original_ticket_keeps_its_normal_authorization(): void
    {
        $t = $this->ticket();

        Sanctum::actingAs($this->alice);
        $this->getJson("/api/v1/tickets/{$t->id}")->assertOk()->assertJsonPath('data.id', $t->id);

        // Someone who is not the assignee, the submitter or in the department still cannot open it.
        $outsider = User::factory()->create(['role' => 'student', 'is_active' => true]);
        Sanctum::actingAs($outsider);
        $this->getJson("/api/v1/tickets/{$t->id}")->assertForbidden();

        // my-work, not the detail endpoint, decides what "mine" means.
        $this->assertSame([], $this->ids($outsider));
    }

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_tickets(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->myWork($this->alice)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $seed = function (int $n): void {
            foreach (range(1, $n) as $i) {
                $this->ticket(['status' => ['ASSIGNED', 'IN_PROGRESS', 'RESOLVED', 'UNRESOLVED'][$i % 4], 'resolved_at' => now()->subDay()]);
            }
        };

        $seed(4); // every status family present, so every query type already runs
        $small = $count();

        $seed(40);
        $large = $count();

        $this->assertSame($small, $large, 'no per-ticket queries');
    }

    public function test_it_never_creates_task_rows_or_changes_tickets(): void
    {
        $t = $this->ticket();
        $before = DB::table('tickets')->where('id', $t->id)->first();
        $tasks = DB::table('tasks')->count();

        $this->myWork($this->alice)->assertOk();

        $this->assertEquals($before, DB::table('tickets')->where('id', $t->id)->first(), 'reading never writes');
        $this->assertSame($tasks, DB::table('tasks')->count());
    }

    // ═══ meta, caps, ordering, boundaries ═══════════════════════════════════

    public function test_meta_reports_exact_totals_and_no_truncation_under_the_caps(): void
    {
        $this->ticket();
        $this->ticket(['status' => 'IN_PROGRESS']);
        $this->ticket(['status' => 'RESOLVED', 'resolved_at' => now()->subDay()]);

        $this->myWork($this->alice)->assertOk()->assertJsonPath('meta', ['open_total' => 2, 'closed_total' => 1, 'truncated' => false]);
        $this->assertSame([300, 100], [\App\Services\TicketMyWork::OPEN_LIMIT, \App\Services\TicketMyWork::CLOSED_LIMIT]);
    }

    public function test_the_caps_truncate_the_rows_but_not_the_totals_and_say_so(): void
    {
        $this->app->bind(\App\Services\TicketMyWork::class, fn ($app) => (new \App\Services\TicketMyWork($app->make(\App\Services\TicketAccess::class)))->withLimits(3, 2));

        $openIds = [];
        foreach (range(1, 5) as $i) {
            $openIds[] = $this->ticket(['expected_resolution_time' => now()->addHours($i)])->id;
        }
        // Closed outcomes, newest first by their OWN date (resolved_at for RESOLVED, updated_at for the others).
        $newest = $this->ticket(['status' => 'RESOLVED', 'resolved_at' => now()->subDays(1)]);
        $middle = $this->ticket(['status' => 'UNRESOLVED']);
        $oldest = $this->ticket(['status' => 'RESOLVED', 'resolved_at' => now()->subDays(20)]);
        DB::table('tickets')->where('id', $newest->id)->update(['updated_at' => now()->subDays(25)]); // an old touch must not hide a recent resolution
        DB::table('tickets')->where('id', $middle->id)->update(['updated_at' => now()->subDays(10)]);
        DB::table('tickets')->where('id', $oldest->id)->update(['updated_at' => now()]); // a fresh touch must not float an old resolution up

        $res = $this->myWork($this->alice)->assertOk();
        $res->assertJsonPath('meta', ['open_total' => 5, 'closed_total' => 3, 'truncated' => true]);
        $rows = collect($res->json('data'));
        $this->assertSame(array_slice($openIds, 0, 3), $rows->take(3)->pluck('id')->all(), 'the nearest deadlines survive the cap');
        $this->assertSame([$newest->id, $middle->id], $rows->slice(3)->pluck('id')->values()->all(), 'the closed group is cut at its oldest outcome');
    }

    public function test_a_resolved_ticket_without_resolved_at_falls_back_to_its_last_update(): void
    {
        $recent = $this->ticket(['status' => 'RESOLVED', 'resolved_at' => null]);
        $old = $this->ticket(['status' => 'RESOLVED', 'resolved_at' => null]);
        DB::table('tickets')->where('id', $recent->id)->update(['updated_at' => now()->subDays(3)]);
        DB::table('tickets')->where('id', $old->id)->update(['updated_at' => now()->subDays(45)]);

        $this->assertSame([$recent->id], $this->ids($this->alice));
    }

    public function test_the_30_day_window_boundary_is_inclusive(): void
    {
        $exactly = $this->ticket(['status' => 'REJECTED_BY_ASSIGNEE']);
        $justOver = $this->ticket(['status' => 'REJECTED_BY_ASSIGNEE']);
        $resolvedExactly = $this->ticket(['status' => 'RESOLVED', 'resolved_at' => now()->subDays(30)]);
        $resolvedOver = $this->ticket(['status' => 'RESOLVED', 'resolved_at' => now()->subDays(30)->subSecond()]);
        DB::table('tickets')->where('id', $exactly->id)->update(['updated_at' => now()->subDays(30)]);
        DB::table('tickets')->where('id', $justOver->id)->update(['updated_at' => now()->subDays(30)->subSecond()]);
        DB::table('tickets')->whereIn('id', [$resolvedExactly->id, $resolvedOver->id])->update(['updated_at' => now()->subDays(100)]);

        $this->assertEqualsCanonicalizing([$exactly->id, $resolvedExactly->id], $this->ids($this->alice), 'exactly 30 days is still in; a second more is out');
    }

    public function test_an_inactive_or_deleted_assignee_cannot_use_my_work_and_the_ticket_leaks_to_nobody_else(): void
    {
        $inactive = $this->member();
        $gone = $this->member();
        $t1 = $this->ticket(['assigned_to_id' => $inactive->id]);
        $t2 = $this->ticket(['assigned_to_id' => $gone->id]);
        $inactive->update(['is_active' => false]);
        $gone->delete();

        // Documented behaviour: my-work is a pure function of tickets.assigned_to_id, and the API itself refuses
        // an inactive account (403). A (soft-)deleted one cannot authenticate at all. Either way the ticket never
        // shows up for anybody else: it waits, assigned to them, for a manager to reroute it (the audit command
        // lists such tickets).
        $this->myWork($inactive)->assertForbidden();
        foreach ([$this->alice, $this->bob, $this->leader] as $user) {
            $this->assertNotContains($t1->id, $this->ids($user));
            $this->assertNotContains($t2->id, $this->ids($user));
        }

        // The manager can still route it (this is what the audit command lists for them).
        Sanctum::actingAs($this->leader);
        $this->postJson("/api/v1/tickets/{$t2->id}/reassign", ['assigned_to_id' => $this->bob->id, 'task_scope' => 'simple', 'task_points' => 30])->assertOk();
        $this->assertContains($t2->id, $this->ids($this->bob));
    }

    public function test_the_description_is_read_as_a_bounded_slice(): void
    {
        $this->ticket(['description' => str_repeat('كلمة ', 1000)]);

        $row = $this->myWork($this->alice)->json('data.0');
        $this->assertLessThanOrEqual(203, mb_strlen($row['description']), '200 characters plus the ellipsis');
        $this->assertStringEndsWith('...', $row['description']);
    }
}
