<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Meeting;
use App\Models\MeetingReport;
use App\Models\MeetingReportRating;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * THE OPERATIONS "تقارير الاجتماعات" PAGE API.
 *
 * Production incident: the page crashed into its error boundary for global
 * users. `meeting_reports` is shared with the per-meeting quick report
 * (Admin\MeetingController::storeReport), which stored rows with NULL title /
 * meeting_date / department, and the list endpoint returned those NULLs raw
 * (`r.meeting_date.slice` -> TypeError). These tests pin the guaranteed shape,
 * the department scoping and the write permissions around it.
 */
class MeetingReportsPageApiTest extends TestCase
{
    use RefreshDatabase;

    private Department $deptA;
    private Department $deptB;
    private User $leaderA;
    private User $memberA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deptA = $this->dept('إدارة البرامج');
        $this->deptB = $this->dept('إدارة الجودة');
        $this->leaderA = $this->user('department_manager');
        $this->deptA->update(['leader_id' => $this->leaderA->id]);
        $this->memberA = $this->user('instructor');
        $this->memberA->departments()->attach($this->deptA->id);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function dept(string $nameAr): Department
    {
        return Department::create([
            'name' => 'Dept '.Str::random(5), 'name_ar' => $nameAr, 'slug' => 'd-'.Str::random(8),
            'status' => 'active', 'is_active' => true,
        ]);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function report(Department $d, array $over = []): MeetingReport
    {
        return MeetingReport::create(array_merge([
            'department_id' => $d->id, 'title' => 'الاجتماع الأسبوعي', 'meeting_date' => now()->toDateString(),
            'achieved' => 'أنجزنا كذا', 'planned' => 'سننجز كذا', 'created_by' => $this->leaderA->id,
        ], $over));
    }

    /** The exact row the meetings lounge used to create: no title, date, department or author. */
    private function legacyQuickReport(array $over = []): MeetingReport
    {
        return MeetingReport::create(array_merge([
            'challenges' => 'تحدي الجدولة', 'completed_tasks_summary' => 'أُنجزت المهام',
            'next_month_goals' => 'أهداف الشهر القادم', 'executive_requests' => 'طلب دعم',
            'kpi_percentage' => 80, 'team_commitment_score' => 4, 'top_interactive_member' => 'سارة',
            'absentees' => ['أحمد', 'ليلى'], 'improvement_suggestions' => 'تحسين التواصل',
        ], $over));
    }

    private function list(User $as, string $query = '')
    {
        Sanctum::actingAs($as);

        return $this->getJson('/api/operations/meeting-reports'.$query);
    }

    private function text($response): string
    {
        return json_encode($response->json(), JSON_UNESCAPED_UNICODE);
    }

    // ═══ The crash: legacy NULL rows ═════════════════════════════════════════

    public function test_a_quick_report_row_with_null_columns_comes_back_with_a_safe_shape(): void
    {
        $this->legacyQuickReport();
        $row = $this->list($this->user('super_admin'))->assertOk()->json('data.data.0');

        $this->assertIsString($row['title']);
        $this->assertNotSame('', $row['title']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $row['meeting_date']);
        $this->assertNull($row['department']);
        $this->assertNull($row['author']);
        $this->assertSame(0, $row['ratings_count']);
        $this->assertNotNull($row['created_at']);
    }

    public function test_a_legacy_row_takes_its_title_date_and_department_from_the_linked_meeting(): void
    {
        $organizer = $this->user('super_admin');
        $meeting = Meeting::create([
            'title' => 'اجتماع المراجعة', 'department_id' => $this->deptA->id, 'organizer_id' => $organizer->id,
            'meeting_date' => '2026-09-14', 'start_time' => '10:00', 'type' => 'department', 'status' => 'completed',
        ]);
        $this->legacyQuickReport(['meeting_id' => $meeting->id]);

        $row = $this->list($organizer)->assertOk()->json('data.data.0');

        $this->assertSame('تقرير دوري — اجتماع المراجعة', $row['title']);
        $this->assertSame('2026-09-14', $row['meeting_date']);
        $this->assertSame($this->deptA->id, $row['department']['id']);
    }

    public function test_the_details_view_merges_the_quick_report_columns_into_the_standard_sections(): void
    {
        $r = $this->legacyQuickReport();
        Sanctum::actingAs($this->user('super_admin'));

        $d = $this->getJson("/api/operations/meeting-reports/{$r->id}")->assertOk()->json('data');

        $this->assertSame('أُنجزت المهام', $d['achieved']);
        $this->assertSame('أهداف الشهر القادم', $d['planned']);
        $this->assertSame('طلب دعم', $d['needs']);
        $this->assertSame('تحدي الجدولة', $d['needs_reason']);
        $this->assertSame(80, $d['extras']['kpi_percentage']);
        $this->assertSame(['أحمد', 'ليلى'], $d['extras']['absentees']);
        $this->assertSame([], $d['ratings']);
        $this->assertNotNull($d['title']);
        $this->assertNotNull($d['meeting_date']);
    }

    public function test_a_normal_report_has_empty_extras_as_an_object_not_a_list(): void
    {
        $r = $this->report($this->deptA);
        Sanctum::actingAs($this->user('super_admin'));

        $res = $this->getJson("/api/operations/meeting-reports/{$r->id}")->assertOk();

        $this->assertStringContainsString('"extras":{}', $res->getContent());
    }

    // ═══ Response shape ══════════════════════════════════════════════════════

    public function test_an_empty_list_has_a_safe_shape_and_zeroed_stats(): void
    {
        $res = $this->list($this->user('super_admin'))->assertOk();

        $this->assertSame([], $res->json('data.data'));
        $this->assertSame(0, $res->json('data.total'));
        $this->assertSame(['total' => 0, 'this_month' => 0, 'latest_at' => null, 'attendance_rate' => null], $res->json('stats'));
    }

    public function test_the_list_exposes_only_the_fields_the_page_needs(): void
    {
        $this->report($this->deptA);

        $row = $this->list($this->user('super_admin'))->assertOk()->json('data.data.0');

        $this->assertEqualsCanonicalizing(
            ['id', 'title', 'meeting_date', 'department', 'author', 'ratings_count', 'attended_count', 'created_at'],
            array_keys($row),
        );
        $this->assertArrayNotHasKey('created_by', $row);
        $this->assertArrayNotHasKey('updated_at', $row);
    }

    public function test_newest_meeting_first_with_undated_rows_last(): void
    {
        $this->report($this->deptA, ['title' => 'قديم', 'meeting_date' => '2026-01-01']);
        $this->report($this->deptA, ['title' => 'جديد', 'meeting_date' => '2026-05-01']);
        $this->legacyQuickReport();

        $titles = array_column($this->list($this->user('super_admin'))->json('data.data'), 'title');

        $this->assertSame('جديد', $titles[0]);
        $this->assertSame('قديم', $titles[1]);
        $this->assertCount(3, $titles);
    }

    public function test_per_page_is_capped(): void
    {
        $this->report($this->deptA);

        $res = $this->list($this->user('super_admin'), '?per_page=100000')->assertOk();

        $this->assertLessThanOrEqual(50, $res->json('data.per_page'));
    }

    public function test_stats_count_this_month_attendance_and_the_latest_filing(): void
    {
        $a = $this->report($this->deptA, ['meeting_date' => now()->toDateString()]);
        $this->report($this->deptA, ['meeting_date' => now()->subMonths(3)->toDateString()]);
        $u1 = $this->user('student');
        $u2 = $this->user('student');
        MeetingReportRating::create(['meeting_report_id' => $a->id, 'user_id' => $u1->id, 'attended' => true]);
        MeetingReportRating::create(['meeting_report_id' => $a->id, 'user_id' => $u2->id, 'attended' => false]);

        $stats = $this->list($this->user('super_admin'))->json('stats');

        $this->assertSame(2, $stats['total']);
        $this->assertSame(1, $stats['this_month']);
        $this->assertSame(50, $stats['attendance_rate']);
        $this->assertNotNull($stats['latest_at']);
    }

    // ═══ Filters ═════════════════════════════════════════════════════════════

    public function test_search_matches_the_title_and_the_linked_meeting_title(): void
    {
        $this->report($this->deptA, ['title' => 'خطة التسويق']);
        $this->report($this->deptA, ['title' => 'مراجعة الجودة']);

        $titles = array_column($this->list($this->user('super_admin'), '?q='.urlencode('التسويق'))->json('data.data'), 'title');

        $this->assertSame(['خطة التسويق'], $titles);
    }

    public function test_date_range_filters_by_meeting_date(): void
    {
        $this->report($this->deptA, ['title' => 'يناير', 'meeting_date' => '2026-01-10']);
        $this->report($this->deptA, ['title' => 'مارس', 'meeting_date' => '2026-03-10']);

        $titles = array_column($this->list($this->user('super_admin'), '?date_from=2026-02-01&date_to=2026-04-01')->json('data.data'), 'title');

        $this->assertSame(['مارس'], $titles);
    }

    public function test_department_filter_narrows_the_list(): void
    {
        $this->report($this->deptA, ['title' => 'أ']);
        $this->report($this->deptB, ['title' => 'ب']);

        $titles = array_column($this->list($this->user('super_admin'), "?department_id={$this->deptB->id}")->json('data.data'), 'title');

        $this->assertSame(['ب'], $titles);
    }

    // ═══ Authorization & department scope ════════════════════════════════════

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/operations/meeting-reports')->assertUnauthorized();
        $this->getJson('/api/operations/meeting-reports/1')->assertUnauthorized();
    }

    public function test_a_role_outside_the_operations_surface_cannot_list(): void
    {
        $this->report($this->deptA);

        $this->list($this->user('student'))->assertForbidden();
    }

    public function test_a_member_sees_only_their_own_departments_reports(): void
    {
        $this->report($this->deptA, ['title' => 'تقرير إدارتي']);
        $this->report($this->deptB, ['title' => 'تقرير إدارة أخرى']);
        $this->legacyQuickReport();

        $res = $this->list($this->memberA)->assertOk();

        $this->assertSame(['تقرير إدارتي'], array_column($res->json('data.data'), 'title'));
        $this->assertSame(1, $res->json('stats.total'), 'stats are scoped like the list');
        $this->assertStringNotContainsString('تقرير إدارة أخرى', $this->text($res));
    }

    public function test_a_department_filter_can_never_widen_a_members_scope(): void
    {
        $this->report($this->deptB, ['title' => 'تقرير إدارة أخرى']);

        $res = $this->list($this->memberA, "?department_id={$this->deptB->id}")->assertOk();

        $this->assertSame([], $res->json('data.data'));
    }

    public function test_a_member_cannot_open_another_departments_report(): void
    {
        $other = $this->report($this->deptB);
        Sanctum::actingAs($this->memberA);

        $this->getJson("/api/operations/meeting-reports/{$other->id}")->assertForbidden();
    }

    public function test_a_member_can_open_their_own_departments_report(): void
    {
        $mine = $this->report($this->deptA);
        Sanctum::actingAs($this->memberA);

        $this->getJson("/api/operations/meeting-reports/{$mine->id}")->assertOk()->assertJsonPath('data.id', $mine->id);
    }

    public function test_a_read_only_global_role_can_read_everything_but_not_create(): void
    {
        $this->report($this->deptA);
        $this->report($this->deptB);
        $quality = $this->user('quality_manager');

        $this->assertCount(2, $this->list($quality)->assertOk()->json('data.data'));

        Sanctum::actingAs($quality);
        $this->postJson('/api/operations/meeting-reports', $this->payload($this->deptA))->assertForbidden();
    }

    // ═══ Writing ═════════════════════════════════════════════════════════════

    private function payload(Department $d, array $over = []): array
    {
        return array_merge([
            'department_id' => $d->id, 'title' => 'اجتماع جديد', 'meeting_date' => '2026-10-01',
            'achieved' => 'أ', 'planned' => 'ب', 'ratings' => [],
        ], $over);
    }

    public function test_the_department_leader_can_create_a_report_for_their_department(): void
    {
        Sanctum::actingAs($this->leaderA);

        $this->postJson('/api/operations/meeting-reports', $this->payload($this->deptA))->assertCreated();

        $this->assertDatabaseHas('meeting_reports', ['title' => 'اجتماع جديد', 'created_by' => $this->leaderA->id]);
    }

    public function test_a_plain_member_cannot_create_a_report(): void
    {
        Sanctum::actingAs($this->memberA);

        $this->postJson('/api/operations/meeting-reports', $this->payload($this->deptA))->assertForbidden();

        $this->assertDatabaseMissing('meeting_reports', ['title' => 'اجتماع جديد']);
    }

    public function test_a_leader_cannot_create_a_report_for_another_department(): void
    {
        Sanctum::actingAs($this->leaderA);

        $this->postJson('/api/operations/meeting-reports', $this->payload($this->deptB))->assertForbidden();
    }

    public function test_global_admins_can_create_for_any_department(): void
    {
        foreach (['super_admin', 'tech_admin', 'executive_admin', 'admin'] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->postJson('/api/operations/meeting-reports', $this->payload($this->deptB, ['title' => "من {$role}"]))->assertCreated();
        }
    }

    public function test_create_validation_errors_come_back_as_a_clean_422(): void
    {
        Sanctum::actingAs($this->leaderA);

        $res = $this->postJson('/api/operations/meeting-reports', ['department_id' => $this->deptA->id, 'ratings' => []])->assertStatus(422);

        $this->assertArrayHasKey('title', $res->json('errors'));
        $this->assertArrayHasKey('meeting_date', $res->json('errors'));
    }

    // ═══ New quick reports are filed correctly (the source of the NULL rows) ═

    public function test_a_new_quick_report_is_filed_with_the_meetings_title_date_department_and_author(): void
    {
        $organizer = $this->user('super_admin');
        $meeting = Meeting::create([
            'title' => 'اجتماع الإنجاز', 'department_id' => $this->deptA->id, 'organizer_id' => $organizer->id,
            'meeting_date' => '2026-09-20', 'start_time' => '09:00', 'type' => 'department', 'status' => 'completed',
        ]);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/department/meetings/{$meeting->id}/report", ['challenges' => 'تحدٍّ'])->assertOk();

        $row = MeetingReport::where('meeting_id', $meeting->id)->sole();
        $this->assertSame('تقرير دوري — اجتماع الإنجاز', $row->title);
        $this->assertSame('2026-09-20', $row->meeting_date->toDateString());
        $this->assertSame($this->deptA->id, (int) $row->department_id);
        $this->assertSame($organizer->id, (int) $row->created_by);
    }

    public function test_resubmitting_a_quick_report_updates_it_instead_of_duplicating_or_retitling(): void
    {
        $organizer = $this->user('super_admin');
        $meeting = Meeting::create([
            'title' => 'اجتماع', 'department_id' => $this->deptA->id, 'organizer_id' => $organizer->id,
            'meeting_date' => '2026-09-20', 'start_time' => '09:00', 'type' => 'department', 'status' => 'completed',
        ]);
        Sanctum::actingAs($organizer);

        $this->postJson("/api/department/meetings/{$meeting->id}/report", ['challenges' => 'الأول'])->assertOk();
        MeetingReport::where('meeting_id', $meeting->id)->update(['title' => 'عنوان معدَّل']);
        $this->postJson("/api/department/meetings/{$meeting->id}/report", ['challenges' => 'الثاني'])->assertOk();

        $this->assertSame(1, MeetingReport::where('meeting_id', $meeting->id)->count());
        $row = MeetingReport::where('meeting_id', $meeting->id)->sole();
        $this->assertSame('الثاني', $row->challenges);
        $this->assertSame('عنوان معدَّل', $row->title);
    }
}
