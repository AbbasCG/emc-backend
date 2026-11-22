<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentalUnit;
use App\Models\PlatformNotification;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\Ticket;
use App\Models\TicketActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ticket Operations Center: the work-queue filters, the aggregate figures (stats, board,
 * analytics), the read-only insights and hints, and the guarded writes (priority, bulk).
 *
 * The rules under test: visibility is applied first and no filter can widen it; every figure is
 * computed on the server over the whole visible set (never a page); the insights reuse the Smart
 * Operational Rules thresholds and change nothing; writes are authorized per ticket.
 */
class TicketOperationsCenterTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/v1/tickets';

    private Department $deptA;
    private Department $deptB;
    private User $leaderA;
    private User $leaderB;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 12:00:00');

        $this->deptA = $this->dept('إدارة التقنية');
        $this->deptB = $this->dept('إدارة التسويق');
        $this->leaderA = $this->leader($this->deptA);
        $this->leaderB = $this->leader($this->deptB);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function dept(string $nameAr): Department
    {
        return Department::create(['name' => 'D '.Str::random(5), 'name_ar' => $nameAr, 'slug' => 'd-'.Str::random(8), 'status' => 'active', 'is_active' => true]);
    }

    private function leader(Department $d): User
    {
        $u = User::factory()->create(['role' => 'programs_manager', 'is_active' => true]);
        $d->update(['leader_id' => $u->id]);

        return $u;
    }

    private function member(Department $d): User
    {
        $u = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $d->users()->attach($u->id);

        return $u;
    }

    private function ticket(Department $d, array $over = []): Ticket
    {
        $created = $over['created_at'] ?? now();
        unset($over['created_at']);

        $t = Ticket::create(array_merge([
            'ticket_number' => 'EMC-TCK-T-'.Str::random(8),
            'title' => 'تذكرة '.Str::random(4),
            'description' => 'وصف',
            'ticket_category' => 'OLD_ISSUE',
            'priority' => 'MEDIUM',
            'department_id' => $d->id,
            'created_by_name' => 'مُبلِّغ',
            'status' => 'PENDING_APPROVAL',
        ], $over));
        $t->forceFill(['created_at' => $created, 'updated_at' => $over['updated_at'] ?? $created])->saveQuietly();

        return $t->fresh();
    }

    private function ids($res): array
    {
        return array_column($res->json('data.data'), 'id');
    }

    private function list(User $u, string $qs = '')
    {
        return $this->actingAs($u)->getJson(self::API.($qs ? '?'.$qs : ''));
    }

    // ═══ Filtering ═══════════════════════════════════════════════════════════

    public function test_guests_cannot_use_any_operations_endpoint(): void
    {
        foreach (['/stats', '/board', '/analytics', '/insights'] as $p) {
            $this->getJson(self::API.$p)->assertStatus(401);
        }
        $this->postJson(self::API.'/bulk', [])->assertStatus(401);
        $this->patchJson(self::API.'/1/priority', [])->assertStatus(401);
    }

    public function test_static_routes_are_not_swallowed_by_the_ticket_id_route(): void
    {
        $this->actingAs($this->admin)->getJson(self::API.'/stats')->assertOk()->assertJsonStructure(['data' => ['counts', 'timings', 'scope']]);
    }

    public function test_search_matches_title_number_description_reporter_and_a_bare_id(): void
    {
        $a = $this->ticket($this->deptA, ['title' => 'الطابعة معطلة', 'ticket_number' => 'EMC-TCK-2026-101', 'description' => 'نفد الحبر', 'created_by_name' => 'سلمى']);
        $b = $this->ticket($this->deptA, ['title' => 'شاشة سوداء']);

        $this->assertSame([$a->id], $this->ids($this->list($this->admin, 'search=الطابعة')));
        $this->assertSame([$a->id], $this->ids($this->list($this->admin, 'search=2026-101')));
        $this->assertSame([$a->id], $this->ids($this->list($this->admin, 'search=الحبر')));
        $this->assertSame([$a->id], $this->ids($this->list($this->admin, 'search=سلمى')));
        // A bare number is a ticket id (it may also appear inside a ticket number, so the id match is among the results).
        $this->assertContains($b->id, $this->ids($this->list($this->admin, 'search='.$b->id)));
        $this->assertSame([], $this->ids($this->list($this->admin, 'search=987654')), 'a number that is no id and no text matches nothing');
    }

    public function test_search_treats_percent_and_underscore_literally(): void
    {
        $this->ticket($this->deptA, ['title' => 'عادية']);
        $hit = $this->ticket($this->deptA, ['title' => 'نسبة 100% مكتملة']);

        $this->assertSame([$hit->id], $this->ids($this->list($this->admin, 'search='.urlencode('100%'))));
        $this->assertSame([$hit->id], $this->ids($this->list($this->admin, 'search='.urlencode('%'))), 'a lone % matches only text that contains it');
        $this->assertSame([], $this->ids($this->list($this->admin, 'search='.urlencode('_'))), 'a lone _ is not a wildcard');
    }

    public function test_multi_status_and_priority_filters_accept_csv_and_arrays(): void
    {
        $p = $this->ticket($this->deptA, ['status' => 'PENDING_APPROVAL', 'priority' => 'HIGH']);
        $i = $this->ticket($this->deptA, ['status' => 'IN_PROGRESS', 'priority' => 'LOW']);
        $r = $this->ticket($this->deptA, ['status' => 'RESOLVED', 'priority' => 'CRITICAL']);

        $csv = $this->ids($this->list($this->admin, 'status=PENDING_APPROVAL,IN_PROGRESS'));
        $this->assertEqualsCanonicalizing([$p->id, $i->id], $csv);

        $arr = $this->ids($this->list($this->admin, 'status[]=RESOLVED&status[]=IN_PROGRESS'));
        $this->assertEqualsCanonicalizing([$r->id, $i->id], $arr);

        $this->assertEqualsCanonicalizing([$p->id, $r->id], $this->ids($this->list($this->admin, 'priority=HIGH,CRITICAL')));
        $this->assertSame([$i->id], $this->ids($this->list($this->admin, 'status=IN_PROGRESS&priority=LOW')));
    }

    public function test_invalid_filter_values_are_rejected_not_ignored(): void
    {
        $this->list($this->admin, 'status=NOPE')->assertStatus(422)->assertJsonValidationErrors(['status.0']);
        $this->list($this->admin, 'priority=URGENT')->assertStatus(422);
        $this->list($this->admin, 'per_page=500')->assertStatus(422);
        $this->list($this->admin, 'sort=password')->assertStatus(422);
        $this->list($this->admin, 'created_from=yesterday')->assertStatus(422);
    }

    public function test_department_assignee_unassigned_and_mine_filters(): void
    {
        $emp = $this->member($this->deptA);
        $a1 = $this->ticket($this->deptA, ['assigned_to_id' => $emp->id, 'status' => 'ASSIGNED']);
        $a2 = $this->ticket($this->deptA);
        $b1 = $this->ticket($this->deptB);

        $this->assertEqualsCanonicalizing([$a1->id, $a2->id], $this->ids($this->list($this->admin, 'department_id='.$this->deptA->id)));
        $this->assertSame([$b1->id], $this->ids($this->list($this->admin, 'department_id='.$this->deptB->id)));
        $this->assertSame([$a1->id], $this->ids($this->list($this->admin, 'assigned_to_id='.$emp->id)));
        $this->assertEqualsCanonicalizing([$a2->id, $b1->id], $this->ids($this->list($this->admin, 'unassigned=1')));
        $this->assertSame([$a1->id], $this->ids($this->list($emp, 'mine=1')));
    }

    public function test_attention_filter_shows_the_next_actor_their_own_work(): void
    {
        $emp = $this->member($this->deptA);
        $waiting = $this->ticket($this->deptA, ['status' => 'PENDING_APPROVAL']);
        $mineAssigned = $this->ticket($this->deptA, ['status' => 'ASSIGNED', 'assigned_to_id' => $emp->id]);
        $declined = $this->ticket($this->deptA, ['status' => 'REJECTED_BY_ASSIGNEE']);
        $other = $this->ticket($this->deptA, ['status' => 'IN_PROGRESS', 'assigned_to_id' => $this->member($this->deptA)->id]);
        $done = $this->ticket($this->deptA, ['status' => 'RESOLVED']);
        $foreign = $this->ticket($this->deptB, ['status' => 'PENDING_APPROVAL']);

        // The leader of A: approvals and declines in A, nothing in B.
        $this->assertEqualsCanonicalizing([$waiting->id, $declined->id], $this->ids($this->list($this->leaderA, 'attention=1')));
        // The assignee: only the task waiting on them.
        $this->assertSame([$mineAssigned->id], $this->ids($this->list($emp, 'attention=1')));
        // Global: every approval and decline.
        $this->assertEqualsCanonicalizing([$waiting->id, $declined->id, $foreign->id], $this->ids($this->list($this->admin, 'attention=1')));
        $this->assertNotContains($other->id, $this->ids($this->list($this->admin, 'attention=1')));
        $this->assertNotContains($done->id, $this->ids($this->list($this->admin, 'attention=1')));
    }

    public function test_overdue_state_and_sla_filters(): void
    {
        $over = $this->ticket($this->deptA, ['status' => 'IN_PROGRESS', 'expected_resolution_time' => now()->subHours(5)]);
        $soon = $this->ticket($this->deptA, ['status' => 'ASSIGNED', 'expected_resolution_time' => now()->addHours(6)]);
        $later = $this->ticket($this->deptA, ['status' => 'ASSIGNED', 'expected_resolution_time' => now()->addDays(3)]);
        $none = $this->ticket($this->deptA, ['status' => 'PENDING_APPROVAL']);
        $closedLate = $this->ticket($this->deptA, ['status' => 'RESOLVED', 'expected_resolution_time' => now()->subDays(2)]);

        $this->assertSame([$over->id], $this->ids($this->list($this->admin, 'overdue=1')));
        $this->assertSame([$over->id], $this->ids($this->list($this->admin, 'delayed_only=1')), 'the legacy flag keeps working');
        $this->assertSame([$over->id], $this->ids($this->list($this->admin, 'sla=overdue')));
        $this->assertSame([$soon->id], $this->ids($this->list($this->admin, 'sla=due_soon')));
        $this->assertSame([$later->id], $this->ids($this->list($this->admin, 'sla=on_track')));
        $this->assertSame([$none->id], $this->ids($this->list($this->admin, 'sla=none')));
        $this->assertEqualsCanonicalizing([$over->id, $soon->id, $later->id, $none->id], $this->ids($this->list($this->admin, 'state=open')));
        $this->assertSame([$closedLate->id], $this->ids($this->list($this->admin, 'state=closed')));
    }

    public function test_date_range_filters_are_inclusive_and_validated(): void
    {
        $old = $this->ticket($this->deptA, ['created_at' => Carbon::parse('2026-09-01 08:00')]);
        $edge = $this->ticket($this->deptA, ['created_at' => Carbon::parse('2026-10-10 23:30')]);
        $new = $this->ticket($this->deptA, ['created_at' => Carbon::parse('2026-10-13 09:00')]);

        $this->assertEqualsCanonicalizing([$edge->id, $new->id], $this->ids($this->list($this->admin, 'created_from=2026-10-10')));
        $this->assertEqualsCanonicalizing([$old->id, $edge->id], $this->ids($this->list($this->admin, 'created_to=2026-10-10')), 'the "to" day is included');
        $this->assertSame([$edge->id], $this->ids($this->list($this->admin, 'created_from=2026-10-10&created_to=2026-10-10')));
        $this->list($this->admin, 'created_from=2026-10-12&created_to=2026-10-01')->assertStatus(422)->assertJsonValidationErrors(['created_to']);
    }

    public function test_sorting_by_priority_deadline_and_title_with_a_stable_tiebreak(): void
    {
        $low = $this->ticket($this->deptA, ['priority' => 'LOW', 'title' => 'ب', 'expected_resolution_time' => now()->addDays(2)]);
        $crit = $this->ticket($this->deptA, ['priority' => 'CRITICAL', 'title' => 'أ', 'expected_resolution_time' => now()->addDay()]);
        $none = $this->ticket($this->deptA, ['priority' => 'HIGH', 'title' => 'ت']);

        $this->assertSame([$crit->id, $none->id, $low->id], $this->ids($this->list($this->admin, 'sort=priority&dir=desc')));
        $this->assertSame([$low->id, $none->id, $crit->id], $this->ids($this->list($this->admin, 'sort=priority&dir=asc')));
        $this->assertSame([$crit->id, $low->id, $none->id], $this->ids($this->list($this->admin, 'sort=due&dir=asc')), 'no deadline sorts last');
        $this->assertSame([$low->id, $crit->id, $none->id], $this->ids($this->list($this->admin, 'sort=due&dir=desc')), 'no deadline sorts last in either direction');
        $this->assertSame([$crit->id, $low->id, $none->id], $this->ids($this->list($this->admin, 'sort=title&dir=asc')));
    }

    public function test_pagination_keeps_the_filter_and_reports_the_filtered_total(): void
    {
        foreach (range(1, 12) as $_) {
            $this->ticket($this->deptA, ['status' => 'IN_PROGRESS']);
        }
        foreach (range(1, 5) as $_) {
            $this->ticket($this->deptA, ['status' => 'RESOLVED']);
        }

        $p1 = $this->list($this->admin, 'status=IN_PROGRESS&per_page=5&page=1');
        $p3 = $this->list($this->admin, 'status=IN_PROGRESS&per_page=5&page=3');

        $this->assertSame(12, $p1->json('data.total'));
        $this->assertCount(5, $p1->json('data.data'));
        $this->assertCount(2, $p3->json('data.data'));
        $this->assertSame(['IN_PROGRESS'], array_values(array_unique(array_column($p3->json('data.data'), 'status'))), 'page 3 is still filtered');
    }

    public function test_the_lite_payload_is_slim_and_keeps_every_field_the_table_needs(): void
    {
        $emp = $this->member($this->deptA);
        $t = $this->ticket($this->deptA, ['assigned_to_id' => $emp->id, 'status' => 'ASSIGNED', 'description' => 'نص طويل جدًا']);
        TicketActivityLog::create(['ticket_id' => $t->id, 'action' => 'COMMENT', 'performed_by' => $emp->id, 'performed_by_name' => $emp->name, 'details' => 'x', 'logged_at' => now()->subHour()]);

        $row = $this->list($this->admin, 'lite=1')->json('data.data.0');
        $full = $this->list($this->admin)->json('data.data.0');

        $this->assertArrayNotHasKey('description', $row);
        $this->assertArrayHasKey('description', $full, 'the default payload is unchanged for existing callers');
        $this->assertArrayNotHasKey('attachments', $row);
        $this->assertSame(0, $row['attachments_count']);
        $this->assertSame($emp->name, $row['assignee']['name']);
        $this->assertArrayNotHasKey('email', $row['assignee']);
        $this->assertNotNull($row['last_activity_at']);
        $this->assertArrayHasKey('capabilities', $row);
    }

    // ═══ Visibility ══════════════════════════════════════════════════════════

    public function test_a_filter_can_never_widen_what_a_user_may_see(): void
    {
        $mine = $this->ticket($this->deptA);
        $foreign = $this->ticket($this->deptB);

        $res = $this->list($this->leaderA, 'department_id='.$this->deptB->id);
        $this->assertSame([], $this->ids($res));
        $this->assertSame([$mine->id], $this->ids($this->list($this->leaderA)));
        $this->assertNotContains($foreign->id, $this->ids($this->list($this->leaderA, 'search='.$foreign->ticket_number)));
        $this->assertNotContains($foreign->id, $this->ids($this->list($this->leaderA, 'search='.$foreign->id)));
    }

    public function test_a_reporter_sees_only_their_own_tickets_in_every_view(): void
    {
        $rep = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $own = $this->ticket($this->deptA, ['created_by_id' => $rep->id]);
        $this->ticket($this->deptA);

        $this->assertSame([$own->id], $this->ids($this->list($rep)));
        $stats = $this->actingAs($rep)->getJson(self::API.'/stats')->json('data.counts');
        $this->assertSame(1, $stats['total']);
        $board = $this->actingAs($rep)->getJson(self::API.'/board')->json('data.columns');
        $this->assertSame(1, array_sum(array_column($board, 'count')));
    }

    // ═══ Statistics ══════════════════════════════════════════════════════════

    public function test_stats_count_the_whole_visible_set_not_the_current_page(): void
    {
        foreach (range(1, 30) as $_) {
            $this->ticket($this->deptA, ['status' => 'PENDING_APPROVAL']);
        }
        foreach (range(1, 4) as $_) {
            $this->ticket($this->deptA, ['status' => 'IN_PROGRESS', 'expected_resolution_time' => now()->subHour(), 'assigned_to_id' => $this->member($this->deptA)->id]);
        }
        $this->ticket($this->deptA, ['status' => 'RESOLVED', 'resolved_at' => now()]);
        $this->ticket($this->deptA, ['status' => 'REJECTED_BY_ADMIN']);

        $c = $this->actingAs($this->admin)->getJson(self::API.'/stats?per_page=5')->json('data.counts');

        $this->assertSame(36, $c['total']);
        $this->assertSame(30, $c['pending_approval']);
        $this->assertSame(4, $c['in_progress']);
        $this->assertSame(1, $c['resolved']);
        $this->assertSame(1, $c['rejected_by_admin']);
        $this->assertSame(34, $c['open']);
        $this->assertSame(2, $c['closed']);
        $this->assertSame(4, $c['overdue']);
        $this->assertSame(30, $c['unassigned']);
        $this->assertSame(36, $c['new_24h']);
    }

    public function test_stats_ignore_the_filters_the_cards_themselves_select_but_honour_the_rest(): void
    {
        $this->ticket($this->deptA, ['status' => 'PENDING_APPROVAL', 'priority' => 'HIGH']);
        $this->ticket($this->deptA, ['status' => 'IN_PROGRESS', 'priority' => 'LOW', 'assigned_to_id' => $this->member($this->deptA)->id]);
        $this->ticket($this->deptB, ['status' => 'PENDING_APPROVAL', 'priority' => 'HIGH']);

        $all = $this->actingAs($this->admin)->getJson(self::API.'/stats')->json('data.counts');
        $statusActive = $this->actingAs($this->admin)->getJson(self::API.'/stats?status=IN_PROGRESS&overdue=1&priority=LOW')->json('data.counts');
        $deptOnly = $this->actingAs($this->admin)->getJson(self::API.'/stats?department_id='.$this->deptA->id)->json('data.counts');

        $this->assertSame(3, $all['total']);
        $this->assertSame($all, $statusActive, 'card filters do not change the card numbers');
        $this->assertSame(2, $deptOnly['total'], 'a department filter does');
        $this->assertSame(1, $deptOnly['high_priority']);
    }

    public function test_stats_are_scoped_to_what_the_viewer_may_see(): void
    {
        $this->ticket($this->deptA);
        $this->ticket($this->deptB);
        $this->ticket($this->deptB);

        $this->assertSame(1, $this->actingAs($this->leaderA)->getJson(self::API.'/stats')->json('data.counts.total'));
        $this->assertSame(3, $this->actingAs($this->admin)->getJson(self::API.'/stats')->json('data.counts.total'));
    }

    public function test_timings_use_recorded_timestamps_only_and_report_their_sample(): void
    {
        // Decided after 2h (approval log) and resolved after 10h.
        $t = $this->ticket($this->deptA, ['status' => 'RESOLVED', 'created_at' => now()->subHours(20), 'resolved_at' => now()->subHours(10)]);
        TicketActivityLog::create(['ticket_id' => $t->id, 'action' => 'APPROVED_AND_ASSIGNED', 'performed_by' => $this->leaderA->id, 'performed_by_name' => 'x', 'logged_at' => now()->subHours(18)]);
        // A ticket with no recorded decision is left out, never guessed.
        $this->ticket($this->deptA, ['created_at' => now()->subHours(5)]);

        $tm = $this->actingAs($this->admin)->getJson(self::API.'/stats')->json('data.timings');

        $this->assertSame(30, $tm['window_days']);
        $this->assertSame(2.0, (float) $tm['avg_response_hours']);
        $this->assertSame(1, $tm['response_sample']);
        $this->assertSame(10.0, (float) $tm['avg_resolution_hours']);
        $this->assertSame(1, $tm['resolution_sample']);
        $this->assertFalse($tm['truncated']);
    }

    public function test_timings_are_null_when_nothing_was_recorded(): void
    {
        $this->ticket($this->deptA);

        $tm = $this->actingAs($this->admin)->getJson(self::API.'/stats')->json('data.timings');

        $this->assertNull($tm['avg_response_hours']);
        $this->assertNull($tm['avg_resolution_hours']);
        $this->assertSame(0, $tm['response_sample']);
    }

    // ═══ Board ═══════════════════════════════════════════════════════════════

    public function test_board_has_exact_counts_and_only_a_few_cards_per_column(): void
    {
        foreach (range(1, 12) as $_) {
            $this->ticket($this->deptA, ['status' => 'PENDING_APPROVAL']);
        }
        $this->ticket($this->deptA, ['status' => 'IN_PROGRESS']);

        $cols = collect($this->actingAs($this->admin)->getJson(self::API.'/board?per_column=5')->json('data.columns'))->keyBy('status');

        $this->assertSame(12, $cols['PENDING_APPROVAL']['count']);
        $this->assertCount(5, $cols['PENDING_APPROVAL']['tickets']);
        $this->assertSame(1, $cols['IN_PROGRESS']['count']);
        $this->assertSame(0, $cols['RESOLVED']['count']);
        $this->assertSame(7, $cols->count());
        $this->assertArrayHasKey('capabilities', $cols['PENDING_APPROVAL']['tickets'][0]);
    }

    public function test_board_puts_urgent_tickets_first_and_honours_filters_and_visibility(): void
    {
        $low = $this->ticket($this->deptA, ['priority' => 'LOW']);
        $crit = $this->ticket($this->deptA, ['priority' => 'CRITICAL']);
        $this->ticket($this->deptB, ['priority' => 'CRITICAL']);

        $col = fn ($u, $qs = '') => collect($this->actingAs($u)->getJson(self::API.'/board'.$qs)->json('data.columns'))->firstWhere('status', 'PENDING_APPROVAL');

        $this->assertSame([$crit->id, $low->id], array_column($col($this->leaderA)['tickets'], 'id'));
        $this->assertSame(3, $col($this->admin)['count']);
        $this->assertSame(1, $col($this->admin, '?department_id='.$this->deptB->id)['count']);
    }

    // ═══ Analytics ═══════════════════════════════════════════════════════════

    public function test_analytics_volume_is_zero_filled_and_labelled_with_its_window(): void
    {
        $this->ticket($this->deptA, ['created_at' => now()->subDays(1)]);
        $this->ticket($this->deptA, ['created_at' => now()->subDays(1)]);
        $this->ticket($this->deptA, ['status' => 'RESOLVED', 'created_at' => now()->subDays(3), 'resolved_at' => now()->subDay()]);
        $this->ticket($this->deptA, ['created_at' => now()->subDays(40)]);

        $d = $this->actingAs($this->admin)->getJson(self::API.'/analytics?days=7')->assertOk()->json('data');

        $this->assertCount(7, $d['volume']);
        $this->assertSame(7, $d['window']['days']);
        $byDate = collect($d['volume'])->keyBy('date');
        $this->assertSame(2, $byDate[now()->subDay()->toDateString()]['created']);
        $this->assertSame(1, $byDate[now()->subDay()->toDateString()]['resolved']);
        $this->assertSame(0, $byDate[now()->toDateString()]['created']);
        $this->assertSame(3, array_sum(array_column($d['volume'], 'created')), 'the ticket from 40 days ago is outside the window');
        $this->assertNotEmpty($d['notes']['overdue']);
    }

    public function test_analytics_distributions_workload_and_overdue_age(): void
    {
        $emp = $this->member($this->deptA);
        $this->ticket($this->deptA, ['status' => 'IN_PROGRESS', 'priority' => 'HIGH', 'assigned_to_id' => $emp->id, 'expected_resolution_time' => now()->subHours(30)]);
        $this->ticket($this->deptA, ['status' => 'ASSIGNED', 'assigned_to_id' => $emp->id, 'expected_resolution_time' => now()->subDays(10)]);
        $this->ticket($this->deptB, ['status' => 'PENDING_APPROVAL']);
        $this->ticket($this->deptB, ['status' => 'RESOLVED', 'resolved_at' => now()]);

        $d = $this->actingAs($this->admin)->getJson(self::API.'/analytics?days=30')->json('data');

        $status = collect($d['by_status'])->pluck('count', 'key');
        $this->assertSame(1, $status['IN_PROGRESS']);
        $this->assertSame(1, $status['RESOLVED']);
        $this->assertSame(4, array_sum($status->all()));
        $this->assertSame(1, collect($d['by_priority'])->firstWhere('key', 'HIGH')['count']);

        $work = collect($d['department_workload'])->keyBy('id');
        $this->assertSame(2, $work[$this->deptA->id]['open']);
        $this->assertSame(2, $work[$this->deptA->id]['overdue']);
        $this->assertSame(1, $work[$this->deptB->id]['open'], 'resolved tickets are not workload');

        $this->assertSame(2, $d['assignment']['top_assignees'][0]['open']);
        $this->assertSame(1, $d['assignment']['unassigned_open']);

        $age = collect($d['overdue_now'])->pluck('count', 'key');
        $this->assertSame(1, $age['d1_3']);
        $this->assertSame(1, $age['over_7d']);
    }

    public function test_analytics_resolution_trend_groups_by_week(): void
    {
        $this->ticket($this->deptA, ['status' => 'RESOLVED', 'created_at' => now()->subDays(3)->subHours(6), 'resolved_at' => now()->subDays(3)]);
        $this->ticket($this->deptA, ['status' => 'RESOLVED', 'created_at' => now()->subDays(3)->subHours(2), 'resolved_at' => now()->subDays(3)]);

        $trend = $this->actingAs($this->admin)->getJson(self::API.'/analytics?days=30')->json('data.resolution_trend');

        $this->assertCount(1, $trend);
        $this->assertSame(2, $trend[0]['resolved']);
        $this->assertSame(4.0, (float) $trend[0]['avg_hours']);
    }

    public function test_analytics_rejects_a_window_that_is_not_offered_and_is_scoped_by_visibility(): void
    {
        $this->ticket($this->deptB);
        $this->actingAs($this->admin)->getJson(self::API.'/analytics?days=45')->assertStatus(422);

        $d = $this->actingAs($this->leaderA)->getJson(self::API.'/analytics?days=30')->json('data');
        $this->assertSame(0, array_sum(array_column($d['volume'], 'created')));
        $this->assertSame([], $d['department_workload']);
    }

    // ═══ Insights ════════════════════════════════════════════════════════════

    public function test_insights_flag_tickets_using_the_smart_rule_thresholds(): void
    {
        // Smart rule defaults: unassigned > 4h; overdue once past the deadline; critical after 72h.
        $fresh = $this->ticket($this->deptA, ['created_at' => now()->subHour()]);
        $stale = $this->ticket($this->deptA, ['created_at' => now()->subHours(9)]);
        $emp = $this->member($this->deptA);
        $late = $this->ticket($this->deptA, ['status' => 'IN_PROGRESS', 'assigned_to_id' => $emp->id, 'expected_resolution_time' => now()->subHours(100), 'priority' => 'LOW', 'created_at' => now()->subDays(6)]);
        TicketActivityLog::create(['ticket_id' => $late->id, 'action' => 'COMMENT', 'performed_by' => $emp->id, 'performed_by_name' => 'x', 'details' => 'x', 'logged_at' => now()]);

        $list = collect($this->actingAs($this->admin)->getJson(self::API.'/insights')->assertOk()->json('data'))->keyBy('ticket_id');

        $this->assertArrayNotHasKey($fresh->id, $list->all(), 'a ticket inside the threshold raises nothing');
        $this->assertSame('ticket_unassigned_too_long', $list[$stale->id]['rule']);
        $this->assertSame('high', $list[$stale->id]['severity']);
        $this->assertSame('ticket_overdue', $list[$late->id]['rule']);
        $this->assertSame('critical', $list[$late->id]['severity']);
        $this->assertSame('/dashboard/tickets/'.$late->id, $list[$late->id]['url']);
        $this->assertNotEmpty($list[$late->id]['reason']);
        $this->assertNotEmpty($list[$late->id]['suggested_action']);
        $this->assertNotNull($list[$late->id]['since']);
    }

    public function test_an_overdue_ticket_gets_a_priority_suggestion_that_is_not_applied(): void
    {
        $emp = $this->member($this->deptA);
        $late = $this->ticket($this->deptA, ['status' => 'IN_PROGRESS', 'assigned_to_id' => $emp->id, 'priority' => 'LOW', 'expected_resolution_time' => now()->subHours(100)]);

        $item = collect($this->actingAs($this->admin)->getJson(self::API.'/insights')->json('data'))->firstWhere('ticket_id', $late->id);

        $this->assertSame('HIGH', $item['suggested_priority']['value']);
        $this->assertSame('LOW', $item['suggested_priority']['current']);
        $this->assertSame('LOW', $late->fresh()->priority, 'a suggestion never changes the ticket');
    }

    public function test_no_priority_suggestion_when_the_ticket_is_already_high(): void
    {
        $emp = $this->member($this->deptA);
        $late = $this->ticket($this->deptA, ['status' => 'IN_PROGRESS', 'assigned_to_id' => $emp->id, 'priority' => 'CRITICAL', 'expected_resolution_time' => now()->subHours(100)]);

        $item = collect($this->actingAs($this->admin)->getJson(self::API.'/insights')->json('data'))->firstWhere('ticket_id', $late->id);

        $this->assertNull($item['suggested_priority']);
    }

    public function test_insights_cover_a_declined_assignment_and_an_unowned_urgent_ticket_once_per_ticket(): void
    {
        $declined = $this->ticket($this->deptA, ['status' => 'REJECTED_BY_ASSIGNEE', 'created_at' => now()->subHour()]);
        $urgent = $this->ticket($this->deptA, ['priority' => 'CRITICAL', 'created_at' => now()->subHours(8)]);

        $list = collect($this->actingAs($this->admin)->getJson(self::API.'/insights')->json('data'));

        $this->assertSame('assignment_declined', $list->firstWhere('ticket_id', $declined->id)['rule']);
        $this->assertSame('high_priority_unowned', $list->firstWhere('ticket_id', $urgent->id)['rule'], 'the most severe reason wins');
        $this->assertSame($list->count(), $list->pluck('ticket_id')->unique()->count());
    }

    public function test_insights_are_scoped_read_only_and_send_nothing(): void
    {
        $mineT = $this->ticket($this->deptA, ['created_at' => now()->subHours(9)]);
        $foreign = $this->ticket($this->deptB, ['created_at' => now()->subHours(9)]);
        $before = [Ticket::count(), TicketActivityLog::count(), PlatformNotification::count(), $mineT->fresh()->updated_at->toIso8601String()];

        $ids = array_column($this->actingAs($this->leaderA)->getJson(self::API.'/insights')->json('data'), 'ticket_id');

        $this->assertSame([$mineT->id], $ids);
        $this->assertNotContains($foreign->id, $ids);
        $this->assertSame($before, [Ticket::count(), TicketActivityLog::count(), PlatformNotification::count(), $mineT->fresh()->updated_at->toIso8601String()]);
    }

    // ═══ Duplicate hints ═════════════════════════════════════════════════════

    public function test_possible_duplicates_match_on_title_words_within_the_same_department_and_category(): void
    {
        $t = $this->ticket($this->deptA, ['title' => 'الإنترنت بطيء في قاعة الاجتماعات']);
        $dup = $this->ticket($this->deptA, ['title' => 'بطء الإنترنت في قاعة الاجتماعات الرئيسية']);
        $other = $this->ticket($this->deptA, ['title' => 'طابعة المكتب لا تعمل']);
        $otherDept = $this->ticket($this->deptB, ['title' => 'الإنترنت بطيء في قاعة الاجتماعات']);
        $otherKind = $this->ticket($this->deptA, ['title' => 'الإنترنت بطيء في قاعة الاجتماعات', 'ticket_category' => 'NEW_SUGGESTION']);

        $res = $this->actingAs($this->admin)->getJson(self::API."/{$t->id}/possible-duplicates")->assertOk();
        $ids = array_column($res->json('data'), 'id');

        $this->assertContains($dup->id, $ids);
        $this->assertNotContains($other->id, $ids);
        $this->assertNotContains($otherDept->id, $ids);
        $this->assertNotContains($otherKind->id, $ids);
        $this->assertNotContains($t->id, $ids, 'never itself');
        $hit = collect($res->json('data'))->firstWhere('id', $dup->id);
        $this->assertGreaterThanOrEqual(50, $hit['word_overlap_percent']);
        $this->assertContains('اجتماعات', $hit['shared_words']);
        $this->assertSame($t->fresh()->status, 'PENDING_APPROVAL', 'nothing is merged or changed');
    }

    public function test_duplicate_hints_ignore_old_tickets_and_one_word_titles(): void
    {
        $t = $this->ticket($this->deptA, ['title' => 'مشكلة الشبكة اللاسلكية']);
        $this->ticket($this->deptA, ['title' => 'مشكلة الشبكة اللاسلكية', 'created_at' => now()->subDays(120)]);
        $short = $this->ticket($this->deptA, ['title' => 'عاجل']);

        $this->assertSame([], $this->actingAs($this->admin)->getJson(self::API."/{$t->id}/possible-duplicates")->json('data'));
        $this->assertSame([], $this->actingAs($this->admin)->getJson(self::API."/{$short->id}/possible-duplicates")->json('data'));
    }

    public function test_duplicate_hints_are_staff_only_and_scoped(): void
    {
        $rep = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $own = $this->ticket($this->deptA, ['title' => 'مشكلة الشبكة اللاسلكية', 'created_by_id' => $rep->id]);
        $this->ticket($this->deptA, ['title' => 'مشكلة الشبكة اللاسلكية الجديدة']);
        $foreign = $this->ticket($this->deptB, ['title' => 'مشكلة الشبكة اللاسلكية']);

        $this->actingAs($rep)->getJson(self::API."/{$own->id}/possible-duplicates")->assertStatus(404);
        $this->actingAs($this->leaderA)->getJson(self::API."/{$foreign->id}/possible-duplicates")->assertStatus(404);
        $this->assertCount(1, $this->actingAs($this->leaderA)->getJson(self::API."/{$own->id}/possible-duplicates")->json('data'));
    }

    // ═══ Assignment suggestions ══════════════════════════════════════════════

    public function test_suggestions_rank_eligible_people_by_open_workload_and_are_advisory(): void
    {
        $busy = $this->member($this->deptA);
        $free = $this->member($this->deptA);
        foreach (range(1, 3) as $_) {
            $this->ticket($this->deptA, ['status' => 'IN_PROGRESS', 'assigned_to_id' => $busy->id]);
        }
        $this->ticket($this->deptA, ['status' => 'RESOLVED', 'assigned_to_id' => $free->id]); // closed work is not load
        $outsider = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $t = $this->ticket($this->deptA, ['status' => 'ASSIGNED', 'assigned_to_id' => $busy->id]);

        $d = $this->actingAs($this->leaderA)->getJson(self::API."/{$t->id}/assignment-suggestions")->assertOk()->json('data');
        $c = collect($d['candidates']);

        // The department leader is a legitimate candidate too, with no open tickets.
        $this->assertContains($free->id, $c->where('open_tickets', 0)->pluck('id')->all());
        $this->assertSame(0, $c[0]['open_tickets']);
        $this->assertTrue($c[0]['recommended'], 'only the first, least-loaded candidate is recommended');
        $this->assertSame(1, $c->where('recommended', true)->count());
        $this->assertSame($busy->id, $c->last()['id'], 'the most loaded person is last');
        $this->assertSame(4, $c->firstWhere('id', $busy->id)['open_tickets']);
        $this->assertTrue($c->firstWhere('id', $busy->id)['is_current']);
        $this->assertNull($c->firstWhere('id', $outsider->id), 'only people the approve/reassign endpoints would accept');
        $this->assertNotEmpty($d['basis']);
        $this->assertSame($busy->id, $t->fresh()->assigned_to_id, 'nothing is reassigned');
    }

    public function test_every_suggestion_is_accepted_by_the_reassign_endpoint(): void
    {
        $this->member($this->deptA);
        $this->member($this->deptA);
        $t = $this->ticket($this->deptA, ['status' => 'ASSIGNED', 'assigned_to_id' => $this->member($this->deptA)->id]);

        foreach ($this->actingAs($this->leaderA)->getJson(self::API."/{$t->id}/assignment-suggestions")->json('data.candidates') as $c) {
            $this->actingAs($this->leaderA)->postJson(self::API."/{$t->id}/reassign", ['assigned_to_id' => $c['id']])->assertOk();
        }
    }

    public function test_suggestions_respect_the_unit_and_say_so_when_nobody_qualifies(): void
    {
        $unit = DepartmentalUnit::create(['department_id' => $this->deptA->id, 'code' => 'U-'.Str::random(5), 'name_ar' => 'وحدة', 'is_active' => true]);
        $inUnit = $this->unitMember($this->deptA, $unit);
        $this->member($this->deptA); // in the department, not in the unit
        $withUnit = $this->ticket($this->deptA, ['unit_id' => $unit->id, 'status' => 'ASSIGNED']);
        $emptyUnit = DepartmentalUnit::create(['department_id' => $this->deptA->id, 'code' => 'E-'.Str::random(5), 'name_ar' => 'وحدة فارغة', 'is_active' => true]);
        $empty = $this->ticket($this->deptA, ['unit_id' => $emptyUnit->id, 'status' => 'ASSIGNED']);

        $d = $this->actingAs($this->leaderA)->getJson(self::API."/{$withUnit->id}/assignment-suggestions")->json('data');
        $this->assertSame('unit', $d['scope']);
        $this->assertSame([$inUnit->id], array_column($d['candidates'], 'id'));

        $none = $this->actingAs($this->leaderA)->getJson(self::API."/{$empty->id}/assignment-suggestions")->json('data');
        $this->assertSame([], $none['candidates']);
        $this->assertNotNull($none['message'], 'a neutral explanation, not a guess');
    }

    public function test_suggestions_are_for_managers_only_and_do_not_leak_existence(): void
    {
        $t = $this->ticket($this->deptA, ['status' => 'ASSIGNED']);
        $member = $this->member($this->deptA);

        $this->actingAs($member)->getJson(self::API."/{$t->id}/assignment-suggestions")->assertStatus(403);
        $this->actingAs($this->leaderB)->getJson(self::API."/{$t->id}/assignment-suggestions")->assertStatus(404);
        $this->actingAs($this->leaderB)->getJson(self::API.'/999999/assignment-suggestions')->assertStatus(404);
    }

    // ═══ Priority ════════════════════════════════════════════════════════════

    public function test_a_manager_changes_priority_and_it_is_logged_without_any_notification(): void
    {
        $t = $this->ticket($this->deptA, ['priority' => 'MEDIUM']);
        $notifications = PlatformNotification::count();

        $this->actingAs($this->leaderA)->patchJson(self::API."/{$t->id}/priority", ['priority' => 'CRITICAL', 'reason' => 'يؤثر على الجميع'])
            ->assertOk()->assertJsonPath('data.priority', 'CRITICAL');

        $this->assertSame('CRITICAL', $t->fresh()->priority);
        $log = TicketActivityLog::where('ticket_id', $t->id)->where('action', 'PRIORITY_CHANGED')->sole();
        $this->assertSame('MEDIUM', $log->previous_state);
        $this->assertSame('CRITICAL', $log->new_state);
        $this->assertSame($this->leaderA->id, $log->performed_by);
        $this->assertStringContainsString('يؤثر على الجميع', $log->details);
        $this->assertSame($notifications, PlatformNotification::count());
    }

    public function test_priority_change_is_refused_for_non_managers_outsiders_closed_and_unchanged(): void
    {
        $t = $this->ticket($this->deptA, ['priority' => 'MEDIUM']);
        $closed = $this->ticket($this->deptA, ['status' => 'RESOLVED']);
        $member = $this->member($this->deptA);
        $assignee = $this->member($this->deptA);
        $assigned = $this->ticket($this->deptA, ['status' => 'IN_PROGRESS', 'assigned_to_id' => $assignee->id]);

        $this->actingAs($member)->patchJson(self::API."/{$t->id}/priority", ['priority' => 'HIGH'])->assertStatus(403);
        $this->actingAs($assignee)->patchJson(self::API."/{$assigned->id}/priority", ['priority' => 'HIGH'])->assertStatus(403);
        $this->actingAs($this->leaderB)->patchJson(self::API."/{$t->id}/priority", ['priority' => 'HIGH'])->assertStatus(404);
        $this->actingAs($this->leaderA)->patchJson(self::API."/{$closed->id}/priority", ['priority' => 'HIGH'])->assertStatus(409);
        $this->actingAs($this->leaderA)->patchJson(self::API."/{$t->id}/priority", ['priority' => 'MEDIUM'])->assertStatus(422)->assertJsonValidationErrors(['priority']);
        $this->actingAs($this->leaderA)->patchJson(self::API."/{$t->id}/priority", ['priority' => 'URGENT'])->assertStatus(422);

        $this->assertSame('MEDIUM', $t->fresh()->priority);
        $this->assertSame(0, TicketActivityLog::where('action', 'PRIORITY_CHANGED')->count());
    }

    public function test_only_a_manager_of_an_open_ticket_is_offered_the_priority_action(): void
    {
        $open = $this->ticket($this->deptA);
        $closed = $this->ticket($this->deptA, ['status' => 'RESOLVED']);

        $leader = collect($this->list($this->leaderA)->json('data.data'))->keyBy('id');
        $this->assertTrue($leader[$open->id]['capabilities']['change_priority']);
        $this->assertFalse($leader[$closed->id]['capabilities']['change_priority']);

        $member = $this->member($this->deptA);
        $this->assertFalse($this->list($member)->json('data.data.0.capabilities.change_priority'));
    }

    // ═══ Bulk ════════════════════════════════════════════════════════════════

    public function test_bulk_priority_applies_per_ticket_and_reports_partial_failure(): void
    {
        $ok1 = $this->ticket($this->deptA);
        $ok2 = $this->ticket($this->deptA);
        $closed = $this->ticket($this->deptA, ['status' => 'RESOLVED']);
        $foreign = $this->ticket($this->deptB);
        $same = $this->ticket($this->deptA, ['priority' => 'HIGH']);

        $res = $this->actingAs($this->leaderA)->postJson(self::API.'/bulk', [
            'action' => 'priority', 'priority' => 'HIGH', 'ticket_ids' => [$ok1->id, $ok2->id, $closed->id, $foreign->id, $same->id, 987654],
        ])->assertOk();

        $d = $res->json('data');
        $this->assertSame(6, $d['requested']);
        $this->assertSame(2, $d['succeeded']);
        $this->assertSame(4, $d['failed']);
        $this->assertTrue($res->json('success'));
        $by = collect($d['results'])->keyBy('id');
        $this->assertTrue($by[$ok1->id]['ok']);
        $this->assertFalse($by[$closed->id]['ok']);
        $this->assertFalse($by[$same->id]['ok']);

        $this->assertSame('HIGH', $ok1->fresh()->priority);
        $this->assertSame('MEDIUM', $foreign->fresh()->priority, 'a ticket outside the caller\'s authority is untouched');
        $this->assertSame('RESOLVED', $closed->fresh()->status);
        // An id that does not exist and one the caller may not see read the same and reveal nothing.
        $this->assertSame($by[987654]['message'], $by[$foreign->id]['message']);
        $this->assertNull($by[$foreign->id]['ticket_number']);
        $this->assertSame(2, TicketActivityLog::where('action', 'PRIORITY_CHANGED')->count());
    }

    public function test_bulk_reassign_uses_the_same_eligibility_as_a_single_reassign(): void
    {
        $emp = $this->member($this->deptA);
        $outsider = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $t1 = $this->ticket($this->deptA, ['status' => 'ASSIGNED', 'assigned_to_id' => $this->member($this->deptA)->id]);
        $t2 = $this->ticket($this->deptA, ['status' => 'IN_PROGRESS', 'assigned_to_id' => $this->member($this->deptA)->id, 'accepted_at' => now()]);
        $pending = $this->ticket($this->deptA, ['status' => 'PENDING_APPROVAL']);
        $notifications = PlatformNotification::count();

        $d = $this->actingAs($this->leaderA)->postJson(self::API.'/bulk', ['action' => 'reassign', 'assigned_to_id' => $emp->id, 'ticket_ids' => [$t1->id, $t2->id, $pending->id], 'reason' => 'إعادة توزيع'])->json('data');

        $this->assertSame(2, $d['succeeded']);
        $this->assertSame($emp->id, $t1->fresh()->assigned_to_id);
        $this->assertSame('ASSIGNED', $t2->fresh()->status);
        $this->assertNull($t2->fresh()->accepted_at, 'the new assignee must accept');
        $this->assertSame('PENDING_APPROVAL', $pending->fresh()->status, 'a pending ticket is approved, not reassigned');
        // No storm: exactly one "assigned to you" per reassigned ticket, to the new assignee only
        // (TicketAssignmentObserver); nobody else and nothing for the refused pending ticket.
        $this->assertSame($notifications + 2, PlatformNotification::count(), 'no notification storm');
        $this->assertSame(2, PlatformNotification::where('type', 'operations_ticket_assigned')->where('user_id', $emp->id)->count());

        $bad = $this->actingAs($this->leaderA)->postJson(self::API.'/bulk', ['action' => 'reassign', 'assigned_to_id' => $outsider->id, 'ticket_ids' => [$t1->id]])->json('data');
        $this->assertSame(0, $bad['succeeded']);
        $this->assertSame($emp->id, $t1->fresh()->assigned_to_id);
    }

    public function test_bulk_validates_its_request_and_caps_the_batch(): void
    {
        $t = $this->ticket($this->deptA);
        $leader = $this->leaderA;

        $this->actingAs($leader)->postJson(self::API.'/bulk', ['action' => 'delete', 'ticket_ids' => [$t->id]])->assertStatus(422);
        $this->actingAs($leader)->postJson(self::API.'/bulk', ['action' => 'priority', 'ticket_ids' => [$t->id]])->assertStatus(422)->assertJsonValidationErrors(['priority']);
        $this->actingAs($leader)->postJson(self::API.'/bulk', ['action' => 'priority', 'priority' => 'HIGH', 'ticket_ids' => []])->assertStatus(422);
        $this->actingAs($leader)->postJson(self::API.'/bulk', ['action' => 'priority', 'priority' => 'HIGH', 'ticket_ids' => range(1, 51)])->assertStatus(422)->assertJsonValidationErrors(['ticket_ids']);
        $this->actingAs($leader)->postJson(self::API.'/bulk', ['action' => 'reassign', 'ticket_ids' => [$t->id]])->assertStatus(422)->assertJsonValidationErrors(['assigned_to_id']);
        // There is deliberately no bulk approve/reject: each one notifies the reporter.
        $this->actingAs($leader)->postJson(self::API.'/bulk', ['action' => 'approve', 'ticket_ids' => [$t->id]])->assertStatus(422);
    }

    public function test_a_member_cannot_bulk_change_anything(): void
    {
        $t = $this->ticket($this->deptA);
        $d = $this->actingAs($this->member($this->deptA))->postJson(self::API.'/bulk', ['action' => 'priority', 'priority' => 'HIGH', 'ticket_ids' => [$t->id]])->json('data');

        $this->assertSame(0, $d['succeeded']);
        $this->assertSame('MEDIUM', $t->fresh()->priority);
    }

    public function test_bulk_ignores_duplicate_ids_in_the_payload_by_rejecting_them(): void
    {
        $t = $this->ticket($this->deptA);

        $this->actingAs($this->leaderA)->postJson(self::API.'/bulk', ['action' => 'priority', 'priority' => 'HIGH', 'ticket_ids' => [$t->id, $t->id]])->assertStatus(422)->assertJsonValidationErrors(['ticket_ids.0']);
    }

    // ═══ Internal-note privacy ═══════════════════════════════════════════════

    public function test_internal_notes_are_hidden_from_plain_department_members_and_reporters(): void
    {
        $assignee = $this->member($this->deptA);
        $member = $this->member($this->deptA);
        $rep = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $t = $this->ticket($this->deptA, ['status' => 'IN_PROGRESS', 'assigned_to_id' => $assignee->id, 'internal_notes' => 'تعليمات سرية للمكلف', 'created_by_id' => $rep->id]);
        TicketActivityLog::create(['ticket_id' => $t->id, 'action' => 'INTERNAL_NOTE', 'performed_by' => $this->leaderA->id, 'performed_by_name' => 'x', 'details' => 'ملاحظة قيادة', 'logged_at' => now()]);
        TicketActivityLog::create(['ticket_id' => $t->id, 'action' => 'COMMENT', 'performed_by' => $this->leaderA->id, 'performed_by_name' => 'x', 'details' => 'تعقيب عام', 'logged_at' => now()]);

        $actions = fn (User $u) => array_column($this->actingAs($u)->getJson(self::API."/{$t->id}")->json('data.activity_logs') ?? [], 'action');
        $notes = fn (User $u) => $this->actingAs($u)->getJson(self::API."/{$t->id}")->json('data.internal_notes');

        foreach ([$this->leaderA, $this->admin, $assignee] as $allowed) {
            $this->assertContains('INTERNAL_NOTE', $actions($allowed));
            $this->assertSame('تعليمات سرية للمكلف', $notes($allowed));
        }

        $this->assertNotContains('INTERNAL_NOTE', $actions($member));
        $this->assertContains('COMMENT', $actions($member), 'public comments stay visible');
        $this->assertNull($notes($member));
        $raw = $this->actingAs($member)->getJson(self::API."/{$t->id}")->getContent();
        $this->assertStringNotContainsString('ملاحظة قيادة', $raw);
        $this->assertStringNotContainsString('تعليمات سرية', $raw);

        // The reporter never receives the operational payload at all.
        $this->assertStringNotContainsString('تعليمات سرية', $this->actingAs($rep)->getJson(self::API."/{$t->id}")->getContent());
    }

    public function test_the_list_never_carries_internal_notes_to_people_who_may_not_read_them(): void
    {
        $member = $this->member($this->deptA);
        $this->ticket($this->deptA, ['internal_notes' => 'سري']);

        $this->assertNull($this->list($member)->json('data.data.0.internal_notes'));
        $this->assertSame('سري', $this->list($this->leaderA)->json('data.data.0.internal_notes'));
        $this->assertStringNotContainsString('سري', $this->list($member, 'lite=1')->getContent());
    }

    // ═══ Performance: query counts do not grow with the data ═════════════════

    public function test_the_queue_endpoints_do_not_query_per_ticket(): void
    {
        $queries = function (string $path, User $who): int {
            \Illuminate\Support\Facades\DB::flushQueryLog();
            \Illuminate\Support\Facades\DB::enableQueryLog();
            $this->actingAs($who)->getJson(self::API.$path)->assertOk();
            $n = count(\Illuminate\Support\Facades\DB::getQueryLog());
            \Illuminate\Support\Facades\DB::disableQueryLog();

            return $n;
        };
        $seed = function (int $count) {
            foreach (range(1, $count) as $_) {
                $emp = $this->member($this->deptA);
                $this->ticket($this->deptA, ['status' => 'IN_PROGRESS', 'assigned_to_id' => $emp->id, 'created_by_id' => $emp->id, 'priority' => 'HIGH', 'expected_resolution_time' => now()->subHours(80), 'created_at' => now()->subDays(5)]);
            }
        };

        $seed(4);
        $paths = ['?lite=1&per_page=100', '/board', '/insights', '/stats', '/analytics?days=30'];
        array_map(fn ($p) => $queries($p, $this->leaderA), $paths); // warm-up: the first request pays one-time lookups
        $small = array_map(fn ($p) => $queries($p, $this->leaderA), $paths);

        $seed(26);
        $large = array_map(fn ($p) => $queries($p, $this->leaderA), $paths);

        foreach ($paths as $i => $p) {
            $this->assertSame($small[$i], $large[$i], "{$p}: 4 tickets ran {$small[$i]} queries, 30 ran {$large[$i]}");
            $this->assertLessThanOrEqual(40, $large[$i], "{$p} runs {$large[$i]} queries");
        }
    }

    // ═══ Reporter privacy across every view ══════════════════════════════════

    public function test_a_reporter_never_receives_assignee_deadline_or_internal_data_from_any_view(): void
    {
        $rep = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $holder = $this->member($this->deptA);
        $t = $this->ticket($this->deptA, [
            'created_by_id' => $rep->id, 'created_by_email' => 'rep@example.test', 'status' => 'IN_PROGRESS', 'assigned_to_id' => $holder->id, 'approved_by_id' => $this->leaderA->id,
            'expected_resolution_time' => now()->subHours(100), 'internal_notes' => 'تعليمات سرية', 'priority' => 'LOW', 'created_at' => now()->subDays(6),
        ]);
        $name = $holder->name;

        // The list: a whitelisted row, in both payload sizes.
        foreach (['', 'lite=1'] as $qs) {
            $res = $this->list($rep, $qs);
            $row = $res->json('data.data.0');
            $this->assertSame($t->id, $row['id']);
            foreach (['assignee', 'assigned_to_id', 'approver', 'approved_by_id', 'expected_resolution_time', 'is_delayed', 'internal_notes', 'created_by_email', 'description', 'attachments'] as $hidden) {
                $this->assertArrayNotHasKey($hidden, $row, "{$hidden} must not reach a reporter");
            }
            $this->assertSame('reporter', $row['viewer_role']);
            $this->assertSame(['comment'], array_keys(array_filter($row['capabilities'])));
            $this->assertStringNotContainsString($name, $res->getContent());
            $this->assertStringNotContainsString('تعليمات سرية', $res->getContent());
        }

        // The board card for the same ticket.
        $col = collect($this->actingAs($rep)->getJson(self::API.'/board')->json('data.columns'))->firstWhere('status', 'IN_PROGRESS');
        $card = $col['tickets'][0];
        $this->assertNull($card['assignee']);
        $this->assertNull($card['expected_resolution_time']);
        $this->assertFalse($card['is_delayed']);
        $this->assertStringNotContainsString($name, json_encode($col));

        // Insights say nothing about a ticket whose only relation to the viewer is that they filed it.
        $this->assertSame([], $this->actingAs($rep)->getJson(self::API.'/insights')->json('data'));

        // Analytics: counts of their own tickets, but never the people who hold them.
        $a = $this->actingAs($rep)->getJson(self::API.'/analytics?days=30')->json('data');
        $this->assertSame([], $a['assignment']['top_assignees']);
        $this->assertStringNotContainsString($name, json_encode($a));
        $this->assertSame(1, $this->actingAs($rep)->getJson(self::API.'/stats')->json('data.counts.total'));
    }

    public function test_the_same_ticket_is_fully_visible_to_staff_who_handle_it(): void
    {
        $rep = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $holder = $this->member($this->deptA);
        $t = $this->ticket($this->deptA, ['created_by_id' => $rep->id, 'status' => 'IN_PROGRESS', 'assigned_to_id' => $holder->id, 'expected_resolution_time' => now()->subHours(100), 'created_at' => now()->subDays(6)]);

        $row = $this->list($this->leaderA, 'lite=1')->json('data.data.0');
        $this->assertSame($holder->name, $row['assignee']['name']);
        $this->assertNotNull($row['expected_resolution_time']);
        $this->assertContains($t->id, array_column($this->actingAs($this->leaderA)->getJson(self::API.'/insights')->json('data'), 'ticket_id'));
        // The assignee handles it as staff, too.
        $this->assertNotSame('reporter', $this->list($holder)->json('data.data.0.viewer_role'));
    }

    public function test_a_leader_who_also_filed_a_ticket_is_staff_for_it_not_a_reporter(): void
    {
        $t = $this->ticket($this->deptA, ['created_by_id' => $this->leaderA->id, 'status' => 'ASSIGNED', 'assigned_to_id' => $this->member($this->deptA)->id, 'expected_resolution_time' => now()->addDay()]);

        $row = $this->list($this->leaderA, 'lite=1')->json('data.data.0');

        $this->assertSame($t->id, $row['id']);
        $this->assertArrayHasKey('assignee', $row);
        $this->assertTrue($row['capabilities']['change_priority']);
    }

    // ═══ Concurrency ═════════════════════════════════════════════════════════

    public function test_a_second_approval_of_the_same_ticket_is_refused_and_changes_nothing(): void
    {
        $t = $this->ticket($this->deptA);

        $first = $this->actingAs($this->leaderA)->postJson(self::API."/{$t->id}/approve", ['sla_hours' => 24])->assertOk();
        $deadline = $t->fresh()->expected_resolution_time;
        $second = $this->actingAs($this->admin)->postJson(self::API."/{$t->id}/approve", ['sla_hours' => 72]);

        $second->assertStatus(409);
        $this->assertEquals($deadline, $t->fresh()->expected_resolution_time, 'the stale second action did not overwrite the first');
        $this->assertSame(1, TicketActivityLog::where('ticket_id', $t->id)->where('action', 'APPROVED_AND_ASSIGNED')->count());
        $this->assertSame('ASSIGNED', $first->json('data.status'));
    }

    public function test_approve_and_reject_cannot_both_win(): void
    {
        $t = $this->ticket($this->deptA);

        $this->actingAs($this->leaderA)->postJson(self::API."/{$t->id}/reject", ['admin_rejection_reason' => 'غير مبرر'])->assertOk();
        $this->actingAs($this->leaderA)->postJson(self::API."/{$t->id}/approve", ['sla_hours' => 24])->assertStatus(409);

        $this->assertSame('REJECTED_BY_ADMIN', $t->fresh()->status);
    }

    public function test_a_stale_reassign_after_a_decline_is_refused(): void
    {
        $emp = $this->member($this->deptA);
        $t = $this->ticket($this->deptA, ['status' => 'ASSIGNED', 'assigned_to_id' => $emp->id]);

        $this->actingAs($emp)->postJson(self::API."/{$t->id}/reject-task", ['assignee_rejection_reason' => 'لا أستطيع'])->assertOk();
        $this->actingAs($this->leaderA)->postJson(self::API."/{$t->id}/reassign", ['assigned_to_id' => $emp->id])->assertStatus(409);
    }

    // ═══ Existing behaviour that must not move ═══════════════════════════════

    public function test_single_reassign_keeps_its_contract(): void
    {
        $old = $this->member($this->deptA);
        $new = $this->member($this->deptA);
        $t = $this->ticket($this->deptA, ['status' => 'IN_PROGRESS', 'assigned_to_id' => $old->id, 'accepted_at' => now()]);

        $this->actingAs($this->leaderA)->postJson(self::API."/{$t->id}/reassign", ['assigned_to_id' => $new->id, 'reason' => 'نقل'])
            ->assertOk()->assertJsonPath('data.assigned_to_id', $new->id)->assertJsonPath('data.status', 'ASSIGNED');
        $this->assertSame('REASSIGNED', TicketActivityLog::where('ticket_id', $t->id)->latest('id')->value('action'));

        $this->actingAs($this->member($this->deptA))->postJson(self::API."/{$t->id}/reassign", ['assigned_to_id' => $new->id])->assertStatus(403);
        $this->actingAs($this->leaderA)->postJson(self::API."/{$t->id}/reassign", [])->assertStatus(422);
    }

    private function unitMember(Department $dept, DepartmentalUnit $unit): User
    {
        $u = User::factory()->create(['role' => 'student', 'is_active' => true]);
        $profile = TeamProfile::create(['user_id' => $u->id, 'name_ar' => $u->name, 'position_ar' => 'عضو', 'department_id' => $dept->id, 'member_type' => 'staff', 'is_active' => true]);
        TeamMember::create(['user_id' => $u->id, 'team_profile_id' => $profile->id, 'department_id' => $dept->id, 'unit_id' => $unit->id, 'status' => 'active']);

        return $u;
    }
}
