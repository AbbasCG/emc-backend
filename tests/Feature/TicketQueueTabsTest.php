<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketQuery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\GrantsTicketReviewPermissions;
use Tests\TestCase;

/**
 * GET /api/v1/tickets?tab=open|all|resolved|needs_action: the queue's tabs, applied in the query BEFORE pagination, with counts that
 * equal the list's totals.
 */
class TicketQueueTabsTest extends TestCase
{
    use GrantsTicketReviewPermissions;
    use RefreshDatabase;

    private const API = '/api/v1/tickets';

    private Department $deptA;

    private Department $deptB;

    private User $admin;

    private User $leaderA;

    private User $leaderB;

    private User $assignee;

    private User $member;

    private User $reporter;

    /** @var array<string, Ticket> status key => ticket of department A, assigned to $assignee */
    private array $a = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-23 12:00:00');
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->deptA = $this->dept('إدارة التقنية');
        $this->deptB = $this->dept('إدارة التسويق');
        $this->admin = $this->grantTicketReview($this->user('admin'));
        $this->leaderA = $this->grantTicketReview($this->user('programs_manager'));
        $this->leaderB = $this->grantTicketReview($this->user('programs_manager'));
        $this->deptA->update(['leader_id' => $this->leaderA->id]);
        $this->deptB->update(['leader_id' => $this->leaderB->id]);
        $this->assignee = $this->user('student');
        $this->member = $this->user('student');
        $this->deptA->users()->attach([$this->assignee->id, $this->member->id]);
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

    private function ticket(Department $d, string $status, array $over = []): Ticket
    {
        $forced = array_intersect_key($over, array_flip(['requires_deployment', 'submitted_by_id']));
        $over = array_diff_key($over, $forced);
        $assigned = ! in_array($status, ['PENDING_APPROVAL', 'REJECTED_BY_ADMIN'], true);

        $t = Ticket::create(array_merge([
            'ticket_number' => 'EMC-TCK-Q-'.Str::random(8), 'title' => 'تذكرة '.Str::random(4), 'description' => 'وصف', 'ticket_category' => 'OLD_ISSUE', 'priority' => 'MEDIUM',
            'department_id' => $d->id, 'created_by_id' => $this->reporter->id, 'created_by_name' => 'مُبلِّغ', 'status' => $status,
            'assigned_to_id' => $assigned ? $this->assignee->id : null, 'task_scope' => 'simple', 'task_points' => 30, 'estimated_hours' => 1,
            'resolved_at' => $status === 'RESOLVED' ? now()->subDay() : null,
        ], $over));
        $deploy = in_array($status, ['APPROVED_FOR_DEPLOY', 'PENDING_VERIFICATION'], true);
        $t->forceFill($forced + ($deploy ? ['requires_deployment' => true] : []))->save();

        return $t;
    }

    /** One ticket of every status in department A (assigned to $assignee, reported by $reporter). */
    private function seedA(): void
    {
        foreach (['PENDING_APPROVAL', 'ASSIGNED', 'IN_PROGRESS', 'PENDING_REVIEW', 'APPROVED_FOR_DEPLOY', 'PENDING_VERIFICATION', 'RESOLVED', 'UNRESOLVED', 'REJECTED_BY_ADMIN', 'REJECTED_BY_ASSIGNEE'] as $status) {
            $this->a[$status] = $this->ticket($this->deptA, $status);
        }
    }

    private function ids(Ticket ...$tickets): array
    {
        return collect($tickets)->pluck('id')->sort()->values()->all();
    }

    private function fetch(User $as, string $qs = '')
    {
        Sanctum::actingAs($as);

        return $this->getJson(self::API.($qs === '' ? '' : '?'.$qs));
    }

    /** @return array<int, int> ids of the rows (sorted) */
    private function rows(User $as, string $qs): array
    {
        return collect($this->fetch($as, $qs)->assertOk()->json('data.data'))->pluck('id')->sort()->values()->all();
    }

    private function open(): array
    {
        return array_values(array_diff_key($this->a, array_flip(['RESOLVED', 'UNRESOLVED', 'REJECTED_BY_ADMIN'])));
    }

    // ── Membership of each tab ───────────────────────────────────────────────

    public function test_without_a_tab_nothing_changes_and_no_tab_data_is_added(): void
    {
        $this->seedA();

        $res = $this->fetch($this->leaderA)->assertOk();
        $this->assertCount(10, $res->json('data.data'));
        $this->assertNull($res->json('meta'));
        $this->assertArrayNotHasKey('awaiting', $res->json('data.data.0'));
        $this->assertArrayNotHasKey('needs_my_action', $res->json('data.data.0'));
    }

    public function test_each_tab_has_its_exact_membership_for_a_department_leader(): void
    {
        $this->seedA();
        $a = $this->a;

        $this->assertSame($this->ids(...$this->open()), $this->rows($this->leaderA, 'tab=open'), 'open: everything not finished (in progress, review, deploy and live test included)');
        $this->assertSame($this->ids($a['RESOLVED']), $this->rows($this->leaderA, 'tab=resolved'));
        $this->assertSame($this->ids(...array_values($a)), $this->rows($this->leaderA, 'tab=all'));
        // The leader's own steps: decide, route again, review, put it live. Nothing assigned to them, nothing finished, nothing in someone else's hands.
        $this->assertSame(
            $this->ids($a['PENDING_APPROVAL'], $a['REJECTED_BY_ASSIGNEE'], $a['PENDING_REVIEW'], $a['APPROVED_FOR_DEPLOY']),
            $this->rows($this->leaderA, 'tab=needs_action'),
        );
    }

    public function test_needs_action_is_personal_and_follows_each_actors_turn(): void
    {
        $this->seedA();
        $a = $this->a;

        // The assignee: accept / do / verify live. Never review or approve.
        $this->assertSame($this->ids($a['ASSIGNED'], $a['IN_PROGRESS'], $a['PENDING_VERIFICATION']), $this->rows($this->assignee, 'tab=needs_action'));
        // A plain department member and the reporter hold no step.
        $this->assertSame([], $this->rows($this->member, 'tab=needs_action'));
        $this->assertSame([], $this->rows($this->reporter, 'tab=needs_action'));
        // The global admin: decisions, re-routing and review/deployment of EVERY department (never acting for the assignee).
        $other = $this->ticket($this->deptB, 'PENDING_APPROVAL');
        $this->assertSame(
            $this->ids($a['PENDING_APPROVAL'], $a['REJECTED_BY_ASSIGNEE'], $a['PENDING_REVIEW'], $a['APPROVED_FOR_DEPLOY'], $other),
            $this->rows($this->admin, 'tab=needs_action'),
        );
        // The leader of ANOTHER department holds only their own department's steps.
        $this->assertSame($this->ids($other), $this->rows($this->leaderB, 'tab=needs_action'));
    }

    public function test_the_attention_filter_is_the_same_set_as_the_needs_action_tab(): void
    {
        $this->seedA();
        $this->ticket($this->deptB, 'PENDING_APPROVAL');
        $this->ticket($this->deptB, 'IN_PROGRESS', ['assigned_to_id' => $this->leaderA->id]);

        foreach ([$this->admin, $this->leaderA, $this->leaderB, $this->assignee, $this->member, $this->reporter] as $viewer) {
            $this->assertSame($this->rows($viewer, 'tab=needs_action'), $this->rows($viewer, 'attention=1'), $viewer->role);
        }
    }

    public function test_the_review_rules_of_ticket_access_decide_who_has_a_review_step(): void
    {
        // A leader WITHOUT the review permission is not asked to review (the rule lives in TicketAccess, not here).
        $noReview = $this->user('programs_manager');
        $this->deptB->update(['leader_id' => $noReview->id]);
        $review = $this->ticket($this->deptB, 'PENDING_REVIEW');
        $this->assertNotContains($review->id, $this->rows($noReview, 'tab=needs_action'));
        $this->grantTicketReview($noReview);
        $this->assertContains($review->id, $this->rows($noReview, 'tab=needs_action'));

        // A leader who CREATED the ticket needs the participation permission to review it.
        $mine = $this->ticket($this->deptB, 'PENDING_REVIEW', ['created_by_id' => $noReview->id]);
        $this->assertNotContains($mine->id, $this->rows($noReview, 'tab=needs_action'));
        $this->grantTicketReview($noReview, participated: true);
        $this->assertContains($mine->id, $this->rows($noReview, 'tab=needs_action'));

        // Nobody reviews the work they did themselves.
        $own = $this->ticket($this->deptB, 'PENDING_REVIEW', ['assigned_to_id' => $noReview->id, 'created_by_id' => $this->reporter->id]);
        $this->assertContains($own->id, $this->rows($noReview, 'tab=needs_action'), 'with the participation permission the rule allows it');
        $this->assignPermission($noReview, 'tickets.review_participated', 'deny');
        $this->assertNotContains($own->id, $this->rows($noReview, 'tab=needs_action'));
    }

    // ── Per-row marker and the capabilities it mirrors ───────────────────────

    public function test_rows_say_whether_the_viewer_has_a_step_and_it_matches_the_tab_and_the_capabilities(): void
    {
        $this->seedA();
        $this->ticket($this->deptB, 'PENDING_APPROVAL');
        $this->ticket($this->deptA, 'PENDING_REVIEW', ['requires_deployment' => true]);

        foreach ([$this->admin, $this->leaderA, $this->leaderB, $this->assignee, $this->member] as $viewer) {
            $rows = collect($this->fetch($viewer, 'tab=all&per_page=100')->assertOk()->json('data.data'));
            $marked = $rows->where('needs_my_action', true)->pluck('id')->sort()->values()->all();
            $this->assertSame($marked, $this->rows($viewer, 'tab=needs_action'), "{$viewer->role}: the row marker and the tab are the same set");

            foreach ($rows->where('needs_my_action', true) as $row) {
                $c = $row['capabilities'];
                $isAssignee = $row['assigned_to_id'] === $viewer->id;
                $step = ($isAssignee && ($c['accept_task'] || $c['complete_task'] || $c['verify_resolved']))
                    || $c['approve'] || ($row['status'] === 'REJECTED_BY_ASSIGNEE' && $c['reassign'])
                    || $c['approve_completion'] || $c['approve_for_deploy'] || $c['mark_deployed'] || $c['return_for_rework'];
                $this->assertTrue($step, "{$viewer->role}: ticket {$row['id']} ({$row['status']}) offers a matching action");
            }
            // And the reverse: a ticket that offers the viewer an action of THEIR turn is marked.
            foreach ($rows as $row) {
                $c = $row['capabilities'];
                if ($c['approve'] || $c['mark_deployed'] || $c['approve_for_deploy'] || $c['approve_completion']) {
                    $this->assertTrue($row['needs_my_action'], "{$viewer->role}: ticket {$row['id']} has a decision for the viewer");
                }
            }
        }
    }

    public function test_awaiting_names_who_must_act_to_staff_and_never_to_reporters_or_plain_members(): void
    {
        $this->seedA();
        $expected = [
            'PENDING_APPROVAL' => 'approver', 'ASSIGNED' => 'assignee', 'IN_PROGRESS' => 'assignee', 'PENDING_REVIEW' => 'reviewer',
            'APPROVED_FOR_DEPLOY' => 'deployer', 'PENDING_VERIFICATION' => 'verifier', 'REJECTED_BY_ASSIGNEE' => 'approver',
            'RESOLVED' => null, 'UNRESOLVED' => null, 'REJECTED_BY_ADMIN' => null,
        ];

        foreach ([$this->leaderA, $this->admin, $this->assignee] as $viewer) {
            $rows = collect($this->fetch($viewer, 'tab=all&per_page=100')->json('data.data'))->keyBy('id');
            foreach ($expected as $status => $actor) {
                $ticket = $this->a[$status];
                $awaiting = $rows[$ticket->id]['awaiting'];
                // The assignee is internal staff for THEIR tickets only (TicketAccess::canSeeInternal); on the others they are a plain member.
                $internal = $viewer !== $this->assignee || $ticket->assigned_to_id === $this->assignee->id;
                $this->assertSame($internal ? $actor : null, $awaiting['actor'] ?? null, "{$viewer->role}: {$status}");
                if ($internal && $actor !== null) {
                    $this->assertNotSame('', $awaiting['label']);
                }
            }
            $this->assertSame($this->assignee->name, $rows[$this->a['IN_PROGRESS']->id]['awaiting']['name']);
            $this->assertNull($rows[$this->a['PENDING_REVIEW']->id]['awaiting']['name'], 'a reviewer is a role, not a named person');
        }

        // A plain department member keeps the status only (the same redaction as the ticket itself).
        foreach (collect($this->fetch($this->member, 'tab=all&per_page=100')->json('data.data')) as $row) {
            $this->assertNull($row['awaiting'], 'plain member');
        }
        // The submitter gets the folded whitelist row: no awaiting, no marker, no assignee.
        foreach (collect($this->fetch($this->reporter, 'tab=all&per_page=100')->json('data.data')) as $row) {
            $this->assertNull($row['awaiting'], 'a stable shape: the keys exist, empty');
            $this->assertFalse($row['needs_my_action']);
            $this->assertArrayNotHasKey('assigned_to_id', $row);
            $this->assertNotContains($row['status'], ['PENDING_REVIEW', 'APPROVED_FOR_DEPLOY', 'PENDING_VERIFICATION']);
        }
    }

    // ── Counts equal totals ──────────────────────────────────────────────────

    public function test_tab_counts_equal_each_tabs_total_with_every_other_filter_applied(): void
    {
        $this->seedA();
        foreach (['PENDING_APPROVAL', 'IN_PROGRESS', 'RESOLVED', 'PENDING_REVIEW', 'ASSIGNED'] as $status) {
            $this->ticket($this->deptB, $status, ['priority' => 'HIGH', 'title' => 'عطل في الموقع']);
        }
        $this->ticket($this->deptA, 'IN_PROGRESS', ['priority' => 'CRITICAL', 'title' => 'عطل في البريد', 'expected_resolution_time' => now()->subDay()]);

        $filters = ['', 'priority=HIGH', 'search='.urlencode('عطل'), 'department_id='.$this->deptB->id, 'status=IN_PROGRESS,RESOLVED', 'unassigned=1', 'overdue=1', 'mine=1', 'attention=1', 'sla=none',
            'ticket_category=OLD_ISSUE&priority=HIGH,CRITICAL', 'state=open', 'created_from=2026-11-01&created_to=2026-11-30'];
        $viewers = [$this->admin, $this->leaderA, $this->leaderB, $this->assignee, $this->member, $this->reporter];

        $checked = 0;
        foreach ($viewers as $viewer) {
            foreach ($filters as $filter) {
                $counts = null;
                foreach (['open', 'all', 'resolved', 'needs_action'] as $tab) {
                    $res = $this->fetch($viewer, trim("tab={$tab}&per_page=2&{$filter}", '&'))->assertOk();
                    $this->assertSame($tab, $res->json('meta.tab'));
                    $counts ??= $res->json('meta.tab_counts');
                    $this->assertSame($counts, $res->json('meta.tab_counts'), 'the counts do not depend on the tab being viewed');
                    $this->assertSame($res->json('data.total'), $counts[$tab], "{$viewer->role} / {$tab} / {$filter}: the tab's count is its total");
                    $checked++;
                }
                $this->assertSame(['open', 'all', 'resolved', 'needs_action'], array_keys($counts));
            }
        }
        $this->assertSame(count($viewers) * count($filters) * 4, $checked);
    }

    public function test_the_all_tab_is_the_union_and_open_plus_closed_partition_it(): void
    {
        $this->seedA();
        $counts = $this->fetch($this->leaderA, 'tab=all')->json('meta.tab_counts');
        $this->assertSame(10, $counts['all']);
        $this->assertSame(7, $counts['open']);
        $this->assertSame(1, $counts['resolved']);
        $this->assertSame(4, $counts['needs_action']);
        $this->assertSame($counts['all'], $counts['open'] + count(['RESOLVED', 'UNRESOLVED', 'REJECTED_BY_ADMIN']));
    }

    // ── Search, filters and pagination are combined with the tab BEFORE paging ─

    public function test_the_tab_is_applied_before_pagination_and_pages_cover_it_exactly(): void
    {
        foreach (range(1, 7) as $i) {
            $this->ticket($this->deptA, 'IN_PROGRESS', ['created_at' => now()->subMinutes($i)]);
        }
        foreach (range(1, 6) as $i) {
            $this->ticket($this->deptA, 'RESOLVED', ['created_at' => now()->subHours($i)]);
        }
        foreach (range(1, 4) as $i) {
            $this->ticket($this->deptA, 'PENDING_APPROVAL');
        }

        foreach (['open' => 11, 'resolved' => 6, 'all' => 17, 'needs_action' => 4] as $tab => $total) {
            $seen = [];
            $page = 1;
            do {
                $res = $this->fetch($this->leaderA, "tab={$tab}&per_page=3&page={$page}")->assertOk();
                $this->assertSame($total, $res->json('data.total'));
                $this->assertSame($page, $res->json('data.current_page'));
                array_push($seen, ...collect($res->json('data.data'))->pluck('id')->all());
                $last = $res->json('data.last_page');
                $page++;
            } while ($page <= $last);
            $this->assertCount($total, $seen);
            $this->assertCount($total, array_unique($seen), "{$tab}: no ticket on two pages");
            $this->assertSame($this->rows($this->leaderA, "tab={$tab}&per_page=100"), collect($seen)->sort()->values()->all(), "{$tab}: the pages are exactly the tab");
        }
    }

    public function test_search_and_filters_combine_with_every_tab(): void
    {
        $match = $this->ticket($this->deptA, 'IN_PROGRESS', ['title' => 'عطل الطابعة', 'priority' => 'HIGH']);
        $other = $this->ticket($this->deptA, 'IN_PROGRESS', ['title' => 'طلب جهاز', 'priority' => 'HIGH']);
        $low = $this->ticket($this->deptA, 'IN_PROGRESS', ['title' => 'عطل الشاشة', 'priority' => 'LOW']);
        $done = $this->ticket($this->deptA, 'RESOLVED', ['title' => 'عطل قديم', 'priority' => 'HIGH']);
        $wait = $this->ticket($this->deptA, 'PENDING_APPROVAL', ['title' => 'عطل جديد', 'priority' => 'HIGH']);
        $foreign = $this->ticket($this->deptB, 'PENDING_APPROVAL', ['title' => 'عطل جديد', 'priority' => 'HIGH']);

        $q = 'search='.urlencode('عطل').'&priority=HIGH';
        $this->assertSame($this->ids($match, $wait), $this->rows($this->leaderA, "tab=open&{$q}"));
        $this->assertSame($this->ids($done), $this->rows($this->leaderA, "tab=resolved&{$q}"));
        $this->assertSame($this->ids($match, $done, $wait), $this->rows($this->leaderA, "tab=all&{$q}"));
        $this->assertSame($this->ids($wait), $this->rows($this->leaderA, "tab=needs_action&{$q}"));
        $paged = array_merge(...array_map(fn (int $p) => collect($this->fetch($this->leaderA, "tab=open&{$q}&sort=created_at&dir=asc&per_page=1&page={$p}")->json('data.data'))->pluck('id')->all(), [1, 2]));
        $this->assertSame($this->ids($match, $wait), collect($paged)->sort()->values()->all(), 'sorting and paging work inside the tab');
        $this->assertNotContains($other->id, $this->rows($this->leaderA, "tab=all&{$q}"));
        $this->assertNotContains($low->id, $this->rows($this->leaderA, "tab=all&{$q}"));
        $this->assertNotContains($foreign->id, $this->rows($this->leaderA, "tab=all&{$q}"), 'another department stays invisible');
        // The status filter narrows within a tab (it never widens it): a resolved ticket is not in `open` whatever the status filter says.
        $this->assertSame([], $this->rows($this->leaderA, 'tab=open&status=RESOLVED'));
        $this->assertSame($this->ids($done), $this->rows($this->leaderA, 'tab=resolved&status=RESOLVED'));
    }

    public function test_a_page_past_the_end_of_the_new_tab_answers_with_the_last_page(): void
    {
        foreach (range(1, 9) as $i) {
            $this->ticket($this->deptA, 'IN_PROGRESS');
        }
        $this->ticket($this->deptA, 'RESOLVED');

        // Page 3 of «open» was on screen; the user switches to «resolved» (1 ticket): the server never answers an empty page for a tab with rows.
        $res = $this->fetch($this->leaderA, 'tab=resolved&per_page=3&page=3')->assertOk();
        $this->assertSame([1, 1, 1], [$res->json('data.total'), $res->json('data.current_page'), count($res->json('data.data'))]);

        // A tab with no rows at all stays empty (page 1).
        $res = $this->fetch($this->leaderA, 'tab=needs_action&per_page=3&page=4')->assertOk();
        $this->assertSame([0, []], [$res->json('data.total'), $res->json('data.data')]);

        // Without a tab the legacy behaviour is untouched: a page past the end is simply empty.
        $res = $this->fetch($this->leaderA, 'per_page=3&page=9')->assertOk();
        $this->assertSame([10, []], [$res->json('data.total'), $res->json('data.data')]);
    }

    // ── Scope isolation and reporters ────────────────────────────────────────

    public function test_a_viewer_never_sees_a_ticket_outside_their_visibility_in_any_tab_or_count(): void
    {
        $this->seedA();
        $foreign = [];
        $bMember = $this->user('student');
        $this->deptB->users()->attach($bMember->id);
        foreach (['PENDING_APPROVAL', 'IN_PROGRESS', 'RESOLVED', 'PENDING_REVIEW', 'REJECTED_BY_ASSIGNEE'] as $status) {
            $foreign[] = $this->ticket($this->deptB, $status, ['assigned_to_id' => $status === 'PENDING_APPROVAL' ? null : $bMember->id]);
        }
        $foreignIds = collect($foreign)->pluck('id')->all();

        foreach ([$this->member, $this->assignee, $this->leaderA] as $viewer) {
            foreach (['open', 'all', 'resolved', 'needs_action'] as $tab) {
                $this->assertSame([], array_values(array_intersect($foreignIds, $this->rows($viewer, "tab={$tab}&per_page=100"))), "{$viewer->role} / {$tab}");
            }
            $this->assertSame(10, $this->fetch($viewer, 'tab=all')->json('meta.tab_counts.all'), "{$viewer->role}: only department A's ten tickets are counted");
        }
        // The B leader sees B's tickets and none of A's.
        $this->assertSame($this->ids(...$foreign), $this->rows($this->leaderB, 'tab=all&per_page=100'));
        $this->assertSame(5, $this->fetch($this->leaderB, 'tab=all')->json('meta.tab_counts.all'));
        $this->assertSame([], array_values(array_intersect(collect($this->a)->pluck('id')->all(), $this->rows($this->leaderB, 'tab=all&per_page=100'))));
    }

    public function test_a_reporter_keeps_the_folded_view_in_every_tab(): void
    {
        $this->seedA();
        $res = $this->fetch($this->reporter, 'tab=open&per_page=100')->assertOk();

        // The submitter's «open» tab lists their unfinished tickets, the review steps read IN_PROGRESS, and no staff data appears.
        $rows = collect($res->json('data.data'))->keyBy('id');
        $this->assertSame($this->ids(...$this->open()), $rows->keys()->sort()->values()->all());
        $this->assertSame('IN_PROGRESS', $rows[$this->a['PENDING_REVIEW']->id]['status']);
        $this->assertSame('IN_PROGRESS', $rows[$this->a['APPROVED_FOR_DEPLOY']->id]['status']);
        $this->assertSame('IN_PROGRESS', $rows[$this->a['PENDING_VERIFICATION']->id]['status']);
        foreach ($rows as $row) {
            $this->assertSame('reporter', $row['viewer_role']);
            foreach (['assignee', 'assigned_to_id', 'expected_resolution_time', 'internal_notes', 'workflow', 'credit'] as $key) {
                $this->assertArrayNotHasKey($key, $row);
            }
            $this->assertSame([false, null], [$row['needs_my_action'], $row['awaiting']]);
        }
        $this->assertSame(['open' => 7, 'all' => 10, 'resolved' => 1, 'needs_action' => 0], $res->json('meta.tab_counts'));
        // Filtering the folded status through a tab cannot reveal the review stage either.
        $this->assertSame([], $this->rows($this->reporter, 'tab=open&status=PENDING_REVIEW'));
    }

    // ── Misc contract ────────────────────────────────────────────────────────

    public function test_an_unknown_tab_is_refused(): void
    {
        $this->fetch($this->admin, 'tab=everything')->assertStatus(422)->assertJsonValidationErrors('tab');
        $this->fetch($this->admin, 'tab[]=open')->assertStatus(422);
    }

    public function test_the_statistics_ignore_the_tab(): void
    {
        $this->seedA();
        $plain = $this->fetch($this->leaderA, '')->status();
        Sanctum::actingAs($this->leaderA);
        $without = $this->getJson(self::API.'/stats')->assertOk()->json('data.counts');
        $with = $this->getJson(self::API.'/stats?tab=resolved')->assertOk()->json('data.counts');
        $this->assertSame($without, $with);
        $this->assertSame(200, $plain);
        $this->assertContains('tab', $this->getJson(self::API.'/stats')->json('data.scope.ignored_filters'));
    }

    public function test_the_tab_constants_are_the_contract(): void
    {
        $this->assertSame(['open', 'all', 'resolved', 'needs_action'], TicketQuery::TABS);
    }

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_rows(): void
    {
        // Every kind of row that costs something: review stages, approved-for-deploy, a deployment ticket waiting for the live test
        // (each with its OWN assignee, so a per-assignee lookup would show), with and without lite, with and without a tab.
        // Three people hold all of it: TicketAccess answers «is this assignee still available» once per PERSON (a list shares it), so
        // the rows grow while the people stay the same. (A list of N distinct live-test assignees costs one lookup each: reported.)
        $pool = [$this->assignee, $this->user('student'), $this->user('student')];
        $seed = function (int $n) use ($pool) {
            foreach (range(1, $n) as $i) {
                $dept = $i % 2 ? $this->deptA : $this->deptB;
                foreach (['PENDING_APPROVAL', 'ASSIGNED', 'IN_PROGRESS', 'RESOLVED', 'REJECTED_BY_ASSIGNEE', 'PENDING_REVIEW', 'APPROVED_FOR_DEPLOY', 'PENDING_VERIFICATION'] as $status) {
                    $this->ticket($dept, $status, ['assigned_to_id' => $status === 'PENDING_APPROVAL' ? null : $pool[$i % 3]->id]);
                }
            }
        };
        $modes = [];
        foreach ([null, 'open', 'all', 'resolved', 'needs_action'] as $tab) {
            foreach ([0, 1] as $lite) {
                $modes[] = [$tab, $lite];
            }
        }
        $count = function (?string $tab, int $lite): int {
            Sanctum::actingAs($this->leaderA);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(self::API.'?per_page=100'.($tab ? "&tab={$tab}" : '').($lite ? '&lite=1' : ''))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $measure = function () use ($modes, $count): array {
            $out = [];
            foreach ($modes as [$tab, $lite]) {
                $out[($tab ?? 'none').'/lite='.$lite] = $count($tab, $lite);
            }

            return $out;
        };

        // The first batch already holds every one of the three people (so a lookup per person is in both measurements); the second
        // multiplies the rows by five.
        $seed(6);
        $measure(); // the first requests of a process also warm the role and permission lookups
        $small = $measure();
        $seed(24);
        $large = $measure();

        fwrite(STDERR, "\nQUERIES small=".json_encode($small)."\nQUERIES large=".json_encode($large)."\n");
        $this->assertSame($small, $large, 'the number of queries does not depend on the number of rows, in any mode');
    }

    // ── The SQL review predicate equals the per-row TicketAccess check ───────

    public function test_the_sql_needs_action_set_equals_the_per_row_ticket_access_check_for_every_kind_of_viewer(): void
    {
        $assigneeOf = [
            'available' => $this->assignee,
            'inactive' => tap($this->user('student'), fn ($u) => $u->forceFill(['is_active' => false])->save()),
            'on_leave' => tap($this->user('student'), fn ($u) => \App\Models\VolunteerProfile::create(['user_id' => $u->id, 'status' => \App\Models\VolunteerProfile::STATUS_ON_LEAVE])),
            'withdrawn' => tap($this->user('student'), fn ($u) => \App\Models\VolunteerProfile::create(['user_id' => $u->id, 'status' => \App\Models\VolunteerProfile::STATUS_WITHDRAWN])),
            'deleted' => tap($this->user('student'), fn ($u) => $u->delete()),
        ];

        $viewers = [
            'none' => $this->user('programs_manager'),
            'review' => $this->grantTicketReview($this->user('programs_manager')),
            'both' => $this->grantTicketReview($this->user('programs_manager'), participated: true),
            'participated_only' => tap($this->user('programs_manager'), fn ($u) => $this->assignPermission($u, 'tickets.review_participated')),
            'global' => $this->grantTicketReview($this->user('admin')),
            'global_none' => $this->user('admin'),
            'tech_admin' => $this->user('tech_admin'),
            'plain' => $this->user('student'),
        ];
        // Each programs_manager leads a department of their own (so there is something to review there).
        $own = [];
        foreach (['none', 'review', 'both', 'participated_only'] as $key) {
            $own[$key] = $this->dept('إدارة '.$key);
            $own[$key]->update(['leader_id' => $viewers[$key]->id]);
        }

        foreach ($viewers as $key => $viewer) {
            foreach ([$own[$key] ?? $this->deptA, $this->deptB] as $dept) {
                foreach (['PENDING_REVIEW', 'APPROVED_FOR_DEPLOY', 'PENDING_VERIFICATION'] as $status) {
                    foreach (array_keys($assigneeOf) as $who) {
                        if ($who !== 'available' && $status !== 'PENDING_VERIFICATION') {
                            continue;
                        }
                        foreach (['created', 'assigned', 'submitted', 'unrelated'] as $relation) {
                            foreach ([true, false] as $deploy) {
                                $this->ticket($dept, $status, [
                                    'assigned_to_id' => ($relation === 'assigned' ? $viewer : $assigneeOf[$who])->id,
                                    'created_by_id' => $relation === 'created' ? $viewer->id : $this->reporter->id,
                                    'submitted_by_id' => $relation === 'submitted' ? $viewer->id : null,
                                    'requires_deployment' => $deploy,
                                ]);
                            }
                        }
                    }
                }
            }
        }
        // More than the 500 candidates the old PHP loop looked at: every one of them is decided in SQL.
        $template = (array) DB::table('tickets')->orderBy('id')->first();
        unset($template['id']);
        $template = array_merge($template, ['status' => 'PENDING_REVIEW', 'department_id' => $this->deptA->id, 'assigned_to_id' => $this->assignee->id, 'created_by_id' => $this->reporter->id, 'submitted_by_id' => null, 'requires_deployment' => 0, 'deleted_at' => null]);
        foreach (array_chunk(range(1, 520), 100) as $chunk) {
            DB::table('tickets')->insert(array_map(fn ($i) => array_merge($template, ['ticket_number' => 'EMC-BULK-'.$i.'-'.Str::random(4)]), $chunk));
        }
        $this->assertGreaterThan(520, Ticket::count());

        $query = app(TicketQuery::class);
        $access = app(\App\Services\TicketAccess::class);
        $sets = [];
        $checked = 0;
        foreach ($viewers as $key => $viewer) {
            $viewer = User::find($viewer->id);
            $visible = $query->visible($viewer)->get();
            // Reference 1: the per-row predicate. Reference 2: the capability flags the buttons are drawn from.
            $perRow = $visible->filter(fn (Ticket $t) => $query->needsMyAction($viewer, $t))->pluck('id')->sort()->values()->all();
            $perCapability = $visible->filter(function (Ticket $t) use ($viewer, $access) {
                $c = $access->capabilities($viewer, $t);
                $mine = $access->isTicketAssignee($viewer, $t) && ($c['accept_task'] || $c['complete_task'] || $c['verify_resolved']);
                $manager = $access->canManage($viewer, (int) $t->department_id) && in_array($t->status, ['PENDING_APPROVAL', 'REJECTED_BY_ASSIGNEE'], true);

                return $mine || $manager || $c['mark_deployed'] || $c['return_for_rework'];
            })->pluck('id')->sort()->values()->all();
            $sql = $query->filter($query->visible($viewer), ['tab' => 'needs_action'], $viewer)->pluck('tickets.id')->sort()->values()->all();

            $this->assertSame($perRow, $sql, "{$key}: the SQL set is the per-row set");
            $this->assertSame($perCapability, $sql, "{$key}: the SQL set is the capability set");
            $sets[$key] = $sql;
            $checked += $visible->count();
        }
        $this->assertGreaterThan(1000, $checked);

        // The matrix is not vacuous: the participation rule removes exactly the tickets the viewer took part in (review tickets that
        // are not deployment ones, so the separate «put it live» branch does not interfere).
        foreach (['review' => false, 'both' => true] as $key => $holdsParticipation) {
            $viewerId = $viewers[$key]->id;
            $took = Ticket::query()->where('status', 'PENDING_REVIEW')->where('requires_deployment', false)
                ->where(fn ($q) => $q->where('created_by_id', $viewerId)->orWhere('submitted_by_id', $viewerId)->orWhere('assigned_to_id', $viewerId))
                ->whereIn('department_id', [$own[$key]->id, $this->deptB->id])->pluck('id')->all();
            $this->assertNotEmpty($took);
            $inSet = array_values(array_intersect($took, $sets[$key]));
            // Only the tickets of the department they lead can be theirs to review.
            $mineToReview = Ticket::query()->whereIn('id', $took)->where('department_id', $own[$key]->id)->pluck('id')->all();
            $holdsParticipation ? $this->assertEqualsCanonicalizing($mineToReview, array_values(array_intersect($inSet, $mineToReview))) : $this->assertSame([], $inSet, "{$key}: tickets they took part in are not theirs to review");
            $this->assertNotEmpty(array_intersect($sets[$key], Ticket::query()->where('status', 'PENDING_REVIEW')->where('department_id', $own[$key]->id)->where('created_by_id', $this->reporter->id)->pluck('id')->all()), "{$key}: the others' review tickets are");
        }
        $this->assertSame([], $sets['none'] === [] ? [] : array_values(array_intersect($sets['none'], Ticket::query()->where('status', 'PENDING_REVIEW')->pluck('id')->all())), 'without the review permission there is no review step');
        $this->assertGreaterThan(count($sets['none']), count($sets['review']), 'without the review permission only the deployment step remains');
        $this->assertGreaterThan(520, count($sets['global']), 'a global reviewer is asked about every one of the 520+ review tickets');
    }
}
