<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/** tickets:audit-work-links reports legacy rows that affect «مهامي» and changes nothing. */
class TicketAuditWorkLinksCommandTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;

    private User $good;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dept = Department::create(['name' => 'D '.Str::random(5), 'name_ar' => 'إدارة', 'slug' => 'd-'.Str::random(8), 'status' => 'active', 'is_active' => true]);
        $this->good = $this->member();
    }

    private function member(array $attrs = []): User
    {
        $u = User::factory()->create(array_merge(['role' => 'student', 'is_active' => true], $attrs));
        $this->dept->users()->attach($u->id);

        return $u;
    }

    private function ticket(array $over = []): Ticket
    {
        return Ticket::create(array_merge([
            'ticket_number' => 'EMC-TCK-A-'.Str::random(8),
            'title' => 'تذكرة', 'description' => 'وصف', 'ticket_category' => 'OLD_ISSUE', 'priority' => 'MEDIUM',
            'department_id' => $this->dept->id, 'created_by_name' => 'مُبلِّغ', 'status' => 'ASSIGNED',
            'assigned_to_id' => $this->good->id, 'task_scope' => 'simple', 'task_points' => 30,
        ], $over));
    }

    /** @return array<string, array{count: int, ids: string}> */
    private function audit(string $args = ''): array
    {
        $code = Artisan::call('tickets:audit-work-links'.($args ? ' '.$args : ''));
        $this->assertSame(0, $code, 'the audit always exits 0');

        $out = [];
        foreach (explode("\n", Artisan::output()) as $line) {
            if (preg_match('/^\|\s*([a-z_]+)\s*\|.*\|\s*(\d+)\s*\|\s*(.*?)\s*\|$/u', rtrim($line), $m)) {
                $out[$m[1]] = ['count' => (int) $m[2], 'ids' => $m[3]];
            }
        }

        return $out;
    }

    private function snapshot(): array
    {
        return [
            'tickets' => DB::table('tickets')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'logs' => DB::table('ticket_activity_logs')->count(),
            'notifications' => DB::table('platform_notifications')->count(),
        ];
    }

    public function test_a_clean_database_reports_nothing(): void
    {
        $this->ticket();
        $this->ticket(['status' => 'IN_PROGRESS', 'accepted_at' => now()]);
        $this->ticket(['status' => 'RESOLVED', 'resolved_at' => now()]);

        $report = $this->audit();
        $this->assertCount(19, $report, 'every check is listed even when it finds nothing');
        foreach ($report as $key => $row) {
            $this->assertSame(0, $row['count'], $key);
        }
        $this->assertStringContainsString('No problems found', (function () {
            Artisan::call('tickets:audit-work-links');

            return Artisan::output();
        })());
    }

    public function test_it_flags_every_kind_of_legacy_problem_with_exact_ids_and_changes_nothing(): void
    {
        $noAssignee = $this->ticket(['assigned_to_id' => null]);
        $gone = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $this->dept->users()->attach($gone->id);
        $deletedAssignee = $this->ticket(['assigned_to_id' => $gone->id]);
        $gone->delete();
        $inactive = $this->member(['is_active' => false]);
        $inactiveAssignee = $this->ticket(['assigned_to_id' => $inactive->id, 'status' => 'IN_PROGRESS', 'accepted_at' => now()]);
        $outsider = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $ineligible = $this->ticket(['assigned_to_id' => $outsider->id]);
        $noAccepted = $this->ticket(['status' => 'IN_PROGRESS', 'accepted_at' => null]);
        $acceptedButAssigned = $this->ticket(['status' => 'ASSIGNED', 'accepted_at' => now()]);
        $noEstimate = $this->ticket(['task_scope' => null, 'task_points' => null]);
        $halfEstimate = $this->ticket(['task_points' => null]);
        $resolvedNoTime = $this->ticket(['status' => 'RESOLVED', 'resolved_at' => null]);
        $stampedButOpen = $this->ticket(['status' => 'IN_PROGRESS', 'accepted_at' => now(), 'resolved_at' => now()]);

        // A duplicate number needs the unique index out of the way (it is exactly what the audit exists to catch).
        $dupA = $this->ticket(['ticket_number' => 'EMC-TCK-DUP-1']);
        Schema::table('tickets', fn ($t) => $t->dropUnique(['ticket_number']));
        $dupB = $this->ticket(['ticket_number' => 'EMC-TCK-DUP-1']);

        // A ticket whose department row is gone.
        $deptGone = Department::create(['name' => 'G '.Str::random(5), 'name_ar' => 'محذوفة', 'slug' => 'g-'.Str::random(8), 'status' => 'active', 'is_active' => true]);
        $orphan = $this->ticket(['department_id' => $deptGone->id, 'assigned_to_id' => null, 'status' => 'PENDING_APPROVAL']);
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('departments')->where('id', $deptGone->id)->delete();

        // Never a problem: closed / soft-deleted rows must not be flagged as active work.
        $closedOk = $this->ticket(['status' => 'RESOLVED', 'resolved_at' => now(), 'assigned_to_id' => null, 'task_scope' => null, 'task_points' => null]);
        $deletedTicket = $this->ticket(['assigned_to_id' => null]);
        $deletedTicket->delete();

        $before = $this->snapshot();
        $r = $this->audit('--limit=50');
        $this->assertSame($before, $this->snapshot(), 'the audit is read-only');

        $ids = fn (array ...$tickets) => collect($tickets)->flatten()->map(fn ($t) => $t->id)->sort()->values()->implode(', ');

        $this->assertSame(1, $r['active_without_assignee']['count']);
        $this->assertSame((string) $noAssignee->id, $r['active_without_assignee']['ids']);

        $this->assertSame((string) $deletedAssignee->id, $r['assignee_deleted']['ids']);
        $this->assertSame((string) $inactiveAssignee->id, $r['assignee_inactive']['ids']);
        $this->assertSame((string) $ineligible->id, $r['assignee_ineligible']['ids']);

        $this->assertSame($ids([$noAccepted]), $r['in_progress_without_accepted_at']['ids']);
        $this->assertSame($ids([$acceptedButAssigned]), $r['assigned_with_accepted_at']['ids']);

        // Active + assigned + missing scope or points; the assignee-less ticket is its own check, the closed one is not active.
        $missingEstimate = collect([$noEstimate, $halfEstimate])->pluck('id')->all();
        foreach ($missingEstimate as $id) {
            $this->assertStringContainsString((string) $id, $r['active_without_estimate']['ids']);
        }
        $this->assertStringNotContainsString((string) $closedOk->id, $r['active_without_estimate']['ids']);
        $this->assertStringNotContainsString((string) $noAssignee->id, $r['active_without_estimate']['ids']);

        $this->assertSame(2, $r['duplicate_ticket_number']['count']);
        $this->assertSame($ids([$dupA, $dupB]), $r['duplicate_ticket_number']['ids']);

        $this->assertSame((string) $orphan->id, $r['department_missing']['ids']);

        $this->assertSame($ids([$resolvedNoTime]), $r['resolved_without_resolved_at']['ids']);
        $this->assertSame($ids([$stampedButOpen]), $r['resolved_at_on_unresolved_status']['ids']);

        $this->assertStringNotContainsString((string) $deletedTicket->id, implode(' ', array_column($r, 'ids')), 'soft-deleted tickets are not audited as live work');
    }

    public function test_the_id_list_is_truncated_but_the_count_is_exact(): void
    {
        foreach (range(1, 7) as $i) {
            $this->ticket(['status' => 'IN_PROGRESS', 'accepted_at' => null]);
        }

        $r = $this->audit('--limit=3');
        $this->assertSame(7, $r['in_progress_without_accepted_at']['count']);
        $this->assertStringContainsString('(+4)', $r['in_progress_without_accepted_at']['ids']);
    }
}
