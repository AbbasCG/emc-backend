<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Meeting;
use App\Models\MeetingActionItem;
use App\Models\MeetingReport;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * THE MEETING WORKFLOW END TO END.
 *
 *   admin schedules a meeting (/dashboard/admin/meetings)
 *   -> it shows in the department lounge for people in scope
 *   -> one authorized person files its single official report
 *   -> a second report is refused
 *   -> quality management reads it in the operations "تقارير الاجتماعات" list
 *
 * Production findings pinned here: the lounge routes were registered twice and
 * the unrestricted copy won, so any signed-in user could create/edit meetings
 * and file reports; the frontend sent type names the API rejects; and nothing
 * stopped a meeting from collecting several reports.
 */
class MeetingLoungeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Department $deptA;
    private Department $deptB;
    private User $admin;
    private User $leaderA;
    private User $memberA;      // plain member of A
    private User $managerA;     // department_manager (manage_meetings) member of A
    private User $quality;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deptA = $this->dept('إدارة البرامج');
        $this->deptB = $this->dept('إدارة الشراكات');
        $this->admin = $this->user('super_admin');
        $this->leaderA = $this->user('programs_manager');
        $this->deptA->update(['leader_id' => $this->leaderA->id]);
        $this->memberA = $this->user('instructor');
        $this->memberA->departments()->attach($this->deptA->id);
        $this->managerA = $this->user('department_manager');
        $this->managerA->departments()->attach($this->deptA->id);
        $this->quality = $this->user('quality_manager');
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

    private function meeting(Department $d, array $over = []): Meeting
    {
        return Meeting::create(array_merge([
            'title' => 'اجتماع الإدارة', 'type' => 'department', 'status' => 'scheduled',
            'department_id' => $d->id, 'organizer_id' => $this->admin->id,
            'meeting_date' => now()->addDays(3)->toDateString(), 'start_time' => '10:00',
        ], $over));
    }

    private function as(User $u): static
    {
        Sanctum::actingAs($u);

        return $this;
    }

    private function report(array $over = []): array
    {
        return array_merge([
            'completed_tasks_summary' => 'أُنجزت خطة الربع', 'challenges' => 'ضيق الوقت', 'next_month_goals' => 'إطلاق النسخة',
            'team_commitment_score' => 4, 'kpi_percentage' => 90, 'absentees' => ['أحمد'],
        ], $over);
    }

    private function newPayload(array $over = []): array
    {
        return array_merge([
            'title' => 'اجتماع جديد', 'type' => 'department', 'meeting_date' => now()->addDay()->toDateString(),
            'start_time' => '09:30', 'department_id' => $this->deptA->id,
        ], $over);
    }

    private function text($response): string
    {
        return json_encode($response->json(), JSON_UNESCAPED_UNICODE);
    }

    private function ids($response): array
    {
        return array_column($response->json('data.data'), 'id');
    }

    // ═══ Scheduling: it saves, and it shows up ═══════════════════════════════

    public function test_an_admin_schedules_a_meeting_and_it_appears_in_the_admin_list(): void
    {
        $res = $this->as($this->admin)->postJson('/api/department/meetings', $this->newPayload())->assertCreated();
        $id = $res->json('data.id');

        $list = $this->getJson('/api/department/meetings')->assertOk();
        $this->assertContains($id, $this->ids($list));
        $this->assertSame('اجتماع جديد', collect($list->json('data.data'))->firstWhere('id', $id)['title']);
    }

    public function test_the_saved_meeting_comes_back_in_the_full_consistent_shape(): void
    {
        $data = $this->as($this->admin)->postJson('/api/department/meetings', $this->newPayload(['end_time' => '11:00']))->assertCreated()->json('data');

        foreach (['id', 'title', 'type', 'status', 'display_status', 'meeting_date', 'start_time', 'end_time', 'starts_at', 'department',
            'organizer', 'has_report', 'report_id', 'capabilities', 'created_at', 'updated_at'] as $key) {
            $this->assertArrayHasKey($key, $data, "missing {$key}");
        }
        $this->assertSame('scheduled', $data['display_status']);
        $this->assertFalse($data['has_report']);
        $this->assertNull($data['report_id']);
        $this->assertSame($this->deptA->id, $data['department']['id']);
        $this->assertSame($this->admin->id, $data['organizer']['id']);
        $this->assertSame('09:30', $data['start_time']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $data['meeting_date']);
        $this->assertTrue($data['capabilities']['can_manage']);
        $this->assertTrue($data['capabilities']['can_upload_report']);
        $this->assertFalse($data['capabilities']['can_view_report']);
    }

    public function test_a_meeting_saved_by_an_admin_appears_in_the_lounge_for_the_department_but_not_for_another(): void
    {
        $id = $this->as($this->admin)->postJson('/api/department/meetings', $this->newPayload())->json('data.id');

        $this->assertContains($id, $this->ids($this->as($this->memberA)->getJson('/api/department/meetings')));

        $outsider = $this->user('instructor');
        $outsider->departments()->attach($this->deptB->id);
        $this->assertNotContains($id, $this->ids($this->as($outsider)->getJson('/api/department/meetings')));
    }

    public function test_the_organizer_always_sees_the_meeting_they_scheduled(): void
    {
        $id = $this->as($this->managerA)->postJson('/api/department/meetings', $this->newPayload())->assertCreated()->json('data.id');

        $this->assertContains($id, $this->ids($this->getJson('/api/department/meetings')));
    }

    public function test_the_older_frontend_type_names_are_accepted_and_stored_canonically(): void
    {
        foreach (['exec' => 'executive', 'departments' => 'department', 'external' => 'external_partner'] as $sent => $stored) {
            $res = $this->as($this->admin)->postJson('/api/department/meetings', $this->newPayload(['type' => $sent]))->assertCreated();
            $this->assertSame($stored, $res->json('data.type'));
        }
    }

    public function test_an_unknown_type_is_refused_with_an_arabic_message(): void
    {
        $res = $this->as($this->admin)->postJson('/api/department/meetings', $this->newPayload(['type' => 'party']))->assertStatus(422);

        $this->assertStringContainsString('نوع الاجتماع غير صالح', $this->text($res));
    }

    public function test_validation_errors_are_arabic_and_per_field(): void
    {
        $res = $this->as($this->admin)->postJson('/api/department/meetings', [])->assertStatus(422);

        $errors = $res->json('errors');
        $this->assertSame('اكتب عنوان الاجتماع.', $errors['title'][0]);
        $this->assertSame('حدّد تاريخ الاجتماع.', $errors['meeting_date'][0]);
        $this->assertSame('حدّد وقت بدء الاجتماع.', $errors['start_time'][0]);
    }

    public function test_the_end_time_must_be_after_the_start(): void
    {
        $res = $this->as($this->admin)->postJson('/api/department/meetings', $this->newPayload(['start_time' => '10:00', 'end_time' => '09:00']))->assertStatus(422);

        $this->assertSame('يجب أن يكون وقت الانتهاء بعد وقت البدء.', $res->json('errors.end_time.0'));
    }

    // ═══ Who may schedule ════════════════════════════════════════════════════

    public function test_a_plain_member_cannot_schedule_a_meeting(): void
    {
        $this->as($this->memberA)->postJson('/api/department/meetings', $this->newPayload())->assertForbidden();

        $this->assertDatabaseMissing('meetings', ['title' => 'اجتماع جديد']);
    }

    public function test_a_student_cannot_schedule_a_meeting(): void
    {
        $this->as($this->user('student'))->postJson('/api/department/meetings', $this->newPayload())->assertForbidden();
    }

    public function test_the_department_leader_and_a_manage_meetings_member_can_schedule_for_their_department(): void
    {
        $this->as($this->leaderA)->postJson('/api/department/meetings', $this->newPayload())->assertCreated();
        $this->as($this->managerA)->postJson('/api/department/meetings', $this->newPayload(['title' => 'من المدير']))->assertCreated();
    }

    public function test_nobody_but_global_roles_can_schedule_for_another_department(): void
    {
        $this->as($this->leaderA)->postJson('/api/department/meetings', $this->newPayload(['department_id' => $this->deptB->id]))->assertForbidden();
        $this->as($this->managerA)->postJson('/api/department/meetings', $this->newPayload(['department_id' => $this->deptB->id]))->assertForbidden();
        $this->as($this->admin)->postJson('/api/department/meetings', $this->newPayload(['department_id' => $this->deptB->id]))->assertCreated();
    }

    public function test_a_leader_who_names_no_department_gets_their_own(): void
    {
        $data = $this->as($this->leaderA)->postJson('/api/department/meetings', $this->newPayload(['department_id' => null]))->assertCreated()->json('data');

        $this->assertSame($this->deptA->id, $data['department']['id']);
    }

    public function test_a_non_global_user_with_nothing_to_schedule_for_is_refused(): void
    {
        $this->as($this->memberA)->postJson('/api/department/meetings', $this->newPayload(['department_id' => null]))->assertForbidden();
    }

    public function test_the_list_tells_the_ui_who_may_schedule_and_for_which_departments(): void
    {
        $leader = $this->as($this->leaderA)->getJson('/api/department/meetings')->json('access');
        $this->assertTrue($leader['can_create']);
        $this->assertSame([$this->deptA->id], array_column($leader['creatable_departments'], 'id'));

        $member = $this->as($this->memberA)->getJson('/api/department/meetings')->json('access');
        $this->assertFalse($member['can_create']);

        $admin = $this->as($this->admin)->getJson('/api/department/meetings')->json('access');
        $this->assertTrue($admin['can_create']);
        $this->assertNull($admin['creatable_departments'], 'null = every department');
    }

    public function test_unauthenticated_requests_are_rejected_everywhere(): void
    {
        $m = $this->meeting($this->deptA);

        $this->getJson('/api/department/meetings')->assertUnauthorized();
        $this->postJson('/api/department/meetings', $this->newPayload())->assertUnauthorized();
        $this->putJson("/api/department/meetings/{$m->id}", ['title' => 'x'])->assertUnauthorized();
        $this->postJson("/api/department/meetings/{$m->id}/report", $this->report())->assertUnauthorized();
    }

    // ═══ Editing ═════════════════════════════════════════════════════════════

    public function test_only_someone_who_manages_the_meeting_can_edit_it(): void
    {
        $m = $this->meeting($this->deptA);

        $this->as($this->memberA)->putJson("/api/department/meetings/{$m->id}", ['title' => 'مخترق'])->assertForbidden();
        $this->as($this->user('student'))->putJson("/api/department/meetings/{$m->id}", ['title' => 'مخترق'])->assertForbidden();
        $this->assertSame('اجتماع الإدارة', $m->fresh()->title);

        $this->as($this->leaderA)->putJson("/api/department/meetings/{$m->id}", ['title' => 'معدّل'])->assertOk();
        $this->assertSame('معدّل', $m->fresh()->title);
    }

    public function test_a_leader_cannot_move_a_meeting_into_a_department_they_do_not_manage(): void
    {
        $m = $this->meeting($this->deptA);

        $this->as($this->leaderA)->putJson("/api/department/meetings/{$m->id}", ['department_id' => $this->deptB->id])->assertForbidden();
        $this->assertSame($this->deptA->id, (int) $m->fresh()->department_id);
    }

    public function test_action_items_follow_the_same_rule(): void
    {
        $m = $this->meeting($this->deptA);
        $item = MeetingActionItem::create(['meeting_id' => $m->id, 'title' => 'بند']);

        $this->as($this->memberA)->patchJson("/api/department/meetings/{$m->id}/action-items/{$item->id}", ['done' => true])->assertForbidden();
        $this->as($this->memberA)->postJson("/api/department/meetings/{$m->id}/action-items", ['title' => 'جديد'])->assertForbidden();
        $this->as($this->leaderA)->patchJson("/api/department/meetings/{$m->id}/action-items/{$item->id}", ['done' => true])->assertOk();

        $mine = MeetingActionItem::create(['meeting_id' => $m->id, 'title' => 'بندي', 'assigned_to' => $this->memberA->id]);
        $this->as($this->memberA)->patchJson("/api/department/meetings/{$m->id}/action-items/{$mine->id}", ['done' => true])->assertOk();
    }

    // ═══ Lounge list ═════════════════════════════════════════════════════════

    public function test_the_lounge_marks_a_meeting_without_a_report(): void
    {
        $m = $this->meeting($this->deptA);

        $row = collect($this->as($this->memberA)->getJson('/api/department/meetings')->json('data.data'))->firstWhere('id', $m->id);

        $this->assertFalse($row['has_report']);
        $this->assertNull($row['report_id']);
        $this->assertSame('scheduled', $row['display_status']);
    }

    public function test_display_status_reflects_time_and_progress(): void
    {
        $past = $this->meeting($this->deptA, ['meeting_date' => now()->subDays(2)->toDateString()]);
        $live = $this->meeting($this->deptA, ['status' => 'in_progress']);
        $done = $this->meeting($this->deptA, ['status' => 'completed']);
        $off = $this->meeting($this->deptA, ['status' => 'cancelled']);
        $future = $this->meeting($this->deptA);

        $rows = collect($this->as($this->admin)->getJson('/api/department/meetings')->json('data.data'))->keyBy('id');

        $this->assertSame('awaiting_report', $rows[$past->id]['display_status']);
        $this->assertSame('in_progress', $rows[$live->id]['display_status']);
        $this->assertSame('awaiting_report', $rows[$done->id]['display_status']);
        $this->assertSame('cancelled', $rows[$off->id]['display_status']);
        $this->assertSame('scheduled', $rows[$future->id]['display_status']);
    }

    public function test_filters_search_type_status_report_state_dates_and_department(): void
    {
        $a = $this->meeting($this->deptA, ['title' => 'خطة التسويق', 'type' => 'programs', 'meeting_date' => '2026-03-10', 'status' => 'completed']);
        $b = $this->meeting($this->deptB, ['title' => 'مراجعة الجودة', 'type' => 'quality', 'meeting_date' => '2026-05-10']);
        $this->as($this->admin)->postJson("/api/department/meetings/{$a->id}/report", $this->report())->assertCreated();

        $q = fn (string $query) => $this->ids($this->getJson('/api/department/meetings'.$query));

        $this->assertSame([$a->id], $q('?q='.urlencode('التسويق')));
        $this->assertSame([$b->id], $q('?type=quality'));
        $this->assertSame([$a->id], $q('?status=completed'));
        $this->assertSame([$a->id], $q('?report_status=submitted'));
        $this->assertSame([$b->id], $q('?report_status=pending'));
        $this->assertSame([$b->id], $q('?date_from=2026-04-01&date_to=2026-06-01'));
        $this->assertSame([$a->id], $q("?department_id={$this->deptA->id}"));
        $this->assertSame([$a->id], $q('?type=programs'), 'canonical filter');
    }

    public function test_the_list_filter_accepts_the_older_frontend_type_names(): void
    {
        $m = $this->meeting($this->deptA, ['type' => 'department']);
        $this->meeting($this->deptA, ['type' => 'general']);

        $this->assertSame([$m->id], $this->ids($this->as($this->admin)->getJson('/api/department/meetings?type=departments')));
    }

    public function test_stats_count_total_month_awaiting_and_submitted(): void
    {
        $this->meeting($this->deptA, ['meeting_date' => now()->toDateString(), 'status' => 'completed']);        // awaiting
        $done = $this->meeting($this->deptA, ['meeting_date' => now()->toDateString(), 'status' => 'completed']);
        $this->meeting($this->deptA, ['meeting_date' => now()->addDays(40)->toDateString()]);                    // future, not late
        $this->meeting($this->deptA, ['meeting_date' => now()->subDays(40)->toDateString(), 'status' => 'cancelled']);
        $this->as($this->admin)->postJson("/api/department/meetings/{$done->id}/report", $this->report())->assertCreated();

        $stats = $this->getJson('/api/department/meetings')->json('stats');

        $this->assertSame(4, $stats['total']);
        $this->assertSame(2, $stats['this_month']);
        $this->assertSame(1, $stats['awaiting_report']);
        $this->assertSame(1, $stats['with_report']);
    }

    public function test_stats_and_the_list_share_the_same_department_scope(): void
    {
        $this->meeting($this->deptA);
        $this->meeting($this->deptB);

        $res = $this->as($this->memberA)->getJson('/api/department/meetings');

        $this->assertCount(1, $res->json('data.data'));
        $this->assertSame(1, $res->json('stats.total'));
    }

    // ═══ One official report per meeting ═════════════════════════════════════

    public function test_an_authorized_user_files_one_report_linked_to_that_exact_meeting(): void
    {
        $m = $this->meeting($this->deptA);
        $other = $this->meeting($this->deptA, ['title' => 'اجتماع آخر']);

        $res = $this->as($this->leaderA)->postJson("/api/department/meetings/{$m->id}/report", $this->report())->assertCreated();

        $row = MeetingReport::where('meeting_id', $m->id)->sole();
        $this->assertSame($this->leaderA->id, (int) $row->created_by);
        $this->assertSame($this->deptA->id, (int) $row->department_id);
        $this->assertSame('تقرير دوري — اجتماع الإدارة', $row->title);
        $this->assertSame(0, MeetingReport::where('meeting_id', $other->id)->count());
        $this->assertTrue($res->json('data.has_report'));
        $this->assertSame($row->id, $res->json('data.report_id'));
        $this->assertSame('report_submitted', $res->json('data.display_status'));
        $this->assertSame('completed', $m->fresh()->status);
    }

    public function test_a_second_report_for_the_same_meeting_is_refused_in_arabic(): void
    {
        $m = $this->meeting($this->deptA);
        $this->as($this->leaderA)->postJson("/api/department/meetings/{$m->id}/report", $this->report())->assertCreated();

        $res = $this->postJson("/api/department/meetings/{$m->id}/report", $this->report(['challenges' => 'محاولة ثانية']))->assertStatus(422);

        $this->assertSame('تم رفع تقرير لهذا الاجتماع مسبقًا.', $res->json('message'));
        $this->assertSame('تم رفع تقرير لهذا الاجتماع مسبقًا.', $res->json('errors.meeting.0'));
        $this->assertSame(1, MeetingReport::where('meeting_id', $m->id)->count());
        $this->assertSame('ضيق الوقت', MeetingReport::where('meeting_id', $m->id)->sole()->challenges);
    }

    public function test_a_different_authorized_user_cannot_file_a_second_report_either(): void
    {
        $m = $this->meeting($this->deptA);
        $this->as($this->leaderA)->postJson("/api/department/meetings/{$m->id}/report", $this->report())->assertCreated();

        $this->as($this->admin)->postJson("/api/department/meetings/{$m->id}/report", $this->report())->assertStatus(422);
        $this->as($this->managerA)->postJson("/api/department/meetings/{$m->id}/report", $this->report())->assertStatus(422);
    }

    public function test_the_database_itself_refuses_a_second_report_row_for_a_meeting(): void
    {
        $m = $this->meeting($this->deptA);
        MeetingReport::create(['meeting_id' => $m->id, 'title' => 'أول']);

        $this->expectException(UniqueConstraintViolationException::class);
        MeetingReport::create(['meeting_id' => $m->id, 'title' => 'ثان']);
    }

    public function test_reports_without_a_meeting_are_unaffected_by_the_unique_index(): void
    {
        MeetingReport::create(['title' => 'محضر إدارة 1', 'department_id' => $this->deptA->id]);
        MeetingReport::create(['title' => 'محضر إدارة 2', 'department_id' => $this->deptA->id]);

        $this->assertSame(2, MeetingReport::whereNull('meeting_id')->count());
    }

    public function test_after_the_report_the_lounge_flags_it_and_the_upload_capability_turns_off(): void
    {
        $m = $this->meeting($this->deptA);
        $this->as($this->leaderA)->postJson("/api/department/meetings/{$m->id}/report", $this->report())->assertCreated();

        $row = collect($this->getJson('/api/department/meetings')->json('data.data'))->firstWhere('id', $m->id);

        $this->assertTrue($row['has_report']);
        $this->assertNotNull($row['report_id']);
        $this->assertSame('report_submitted', $row['display_status']);
        $this->assertFalse($row['capabilities']['can_upload_report']);
        $this->assertTrue($row['capabilities']['can_view_report']);
        $this->assertNull($row['report'], 'the list carries no report body');
    }

    public function test_the_detail_view_carries_the_report_body_for_someone_allowed_to_read_it(): void
    {
        $m = $this->meeting($this->deptA);
        $this->as($this->leaderA)->postJson("/api/department/meetings/{$m->id}/report", $this->report())->assertCreated();

        $d = $this->as($this->memberA)->getJson("/api/department/meetings/{$m->id}")->assertOk()->json('data');

        $this->assertSame('أُنجزت خطة الربع', $d['report']['completed_tasks_summary']);
        $this->assertSame(['أحمد'], $d['report']['absentees']);
        $this->assertSame($this->leaderA->id, $d['report']['uploaded_by']['id']);
        $this->assertNotNull($d['report']['uploaded_at']);
    }

    // ═══ Who may file the report ═════════════════════════════════════════════

    public function test_a_plain_member_cannot_file_the_report(): void
    {
        $m = $this->meeting($this->deptA);

        $this->as($this->memberA)->postJson("/api/department/meetings/{$m->id}/report", $this->report())->assertForbidden();

        $this->assertSame(0, MeetingReport::where('meeting_id', $m->id)->count());
    }

    public function test_a_member_sees_that_they_cannot_upload(): void
    {
        $m = $this->meeting($this->deptA);

        $row = collect($this->as($this->memberA)->getJson('/api/department/meetings')->json('data.data'))->firstWhere('id', $m->id);

        $this->assertFalse($row['capabilities']['can_upload_report']);
        $this->assertFalse($row['capabilities']['can_manage']);
    }

    public function test_an_outsider_and_a_student_cannot_file_or_read(): void
    {
        $m = $this->meeting($this->deptA);
        $leaderB = $this->user('programs_manager');
        $this->deptB->update(['leader_id' => $leaderB->id]);

        foreach ([$this->user('student'), $leaderB] as $who) {
            $this->as($who)->postJson("/api/department/meetings/{$m->id}/report", $this->report())->assertForbidden();
            $this->getJson("/api/department/meetings/{$m->id}")->assertForbidden();
        }
        $this->assertSame(0, MeetingReport::count());
    }

    public function test_the_organizer_may_file_the_report_for_their_own_meeting(): void
    {
        $m = $this->as($this->managerA)->postJson('/api/department/meetings', $this->newPayload())->json('data.id');

        $this->postJson("/api/department/meetings/{$m}/report", $this->report())->assertCreated();
    }

    public function test_a_cancelled_meeting_takes_no_report(): void
    {
        $m = $this->meeting($this->deptA, ['status' => 'cancelled']);

        $res = $this->as($this->leaderA)->postJson("/api/department/meetings/{$m->id}/report", $this->report())->assertStatus(422);

        $this->assertSame('لا يمكن رفع تقرير لاجتماع ملغى.', $res->json('message'));
    }

    public function test_an_empty_report_is_refused_so_it_cannot_lock_the_meeting(): void
    {
        $m = $this->meeting($this->deptA);

        $res = $this->as($this->leaderA)->postJson("/api/department/meetings/{$m->id}/report", ['team_commitment_score' => 3])->assertStatus(422);

        $this->assertStringContainsString('أضف محتوى للتقرير', $res->json('message'));
        $this->assertSame(0, MeetingReport::where('meeting_id', $m->id)->count());
    }

    public function test_report_field_validation_is_arabic(): void
    {
        $m = $this->meeting($this->deptA);

        $res = $this->as($this->leaderA)->postJson("/api/department/meetings/{$m->id}/report", $this->report(['team_commitment_score' => 9, 'kpi_percentage' => 400]))->assertStatus(422);

        $this->assertSame('مقياس التزام الفريق من 1 إلى 5.', $res->json('errors.team_commitment_score.0'));
        $this->assertSame('نسبة تحقيق الأهداف بين 0 و100.', $res->json('errors.kpi_percentage.0'));
    }

    // ═══ Quality management ══════════════════════════════════════════════════

    public function test_quality_sees_every_meeting_and_the_uploaded_report_in_the_operations_list(): void
    {
        $m = $this->meeting($this->deptB, ['title' => 'اجتماع الشراكات', 'type' => 'partnerships']);
        $leaderB = $this->user('programs_manager');
        $this->deptB->update(['leader_id' => $leaderB->id]);
        $this->as($leaderB)->postJson("/api/department/meetings/{$m->id}/report", $this->report())->assertCreated();

        // The lounge: quality is outside department B yet reads its meeting and report.
        $lounge = $this->as($this->quality)->getJson('/api/department/meetings')->assertOk();
        $row = collect($lounge->json('data.data'))->firstWhere('id', $m->id);
        $this->assertTrue($row['has_report']);
        $this->assertTrue($row['capabilities']['can_view_report']);
        $this->assertFalse($row['capabilities']['can_upload_report']);
        $this->assertFalse($row['capabilities']['can_manage'], 'oversight reads; it does not edit');

        // The operations list: the same report, linked back to its meeting.
        $ops = $this->getJson('/api/operations/meeting-reports')->assertOk();
        $report = collect($ops->json('data.data'))->firstWhere('meeting.id', $m->id);
        $this->assertNotNull($report);
        $this->assertSame('تقرير دوري — اجتماع الشراكات', $report['title']);
        $this->assertSame('partnerships', $report['meeting']['type']);
        $this->assertSame($this->deptB->id, $report['department']['id']);
        $this->assertSame($leaderB->id, $report['author']['id']);
        $this->assertNotNull($report['created_at']);
    }

    public function test_quality_reads_the_report_detail_with_the_filed_text(): void
    {
        $m = $this->meeting($this->deptB);
        $this->as($this->admin)->postJson("/api/department/meetings/{$m->id}/report", $this->report())->assertCreated();
        $reportId = MeetingReport::where('meeting_id', $m->id)->value('id');

        $d = $this->as($this->quality)->getJson("/api/operations/meeting-reports/{$reportId}")->assertOk()->json('data');

        $this->assertSame('أُنجزت خطة الربع', $d['achieved']);
        $this->assertSame('ضيق الوقت', $d['needs_reason']);
        $this->assertSame(90, $d['extras']['kpi_percentage']);
    }

    public function test_quality_cannot_file_a_report_or_schedule_by_that_role_alone(): void
    {
        $m = $this->meeting($this->deptA);

        $this->as($this->quality)->postJson("/api/department/meetings/{$m->id}/report", $this->report())->assertForbidden();
        $this->postJson('/api/department/meetings', $this->newPayload())->assertForbidden();
    }

    public function test_people_outside_quality_and_the_department_do_not_see_the_report(): void
    {
        $m = $this->meeting($this->deptB);
        $this->as($this->admin)->postJson("/api/department/meetings/{$m->id}/report", $this->report())->assertCreated();
        $reportId = MeetingReport::where('meeting_id', $m->id)->value('id');

        // Department A member: not in B, so neither the meeting nor its report.
        $ops = $this->as($this->memberA)->getJson('/api/operations/meeting-reports')->assertOk();
        $this->assertNotContains($reportId, array_column($ops->json('data.data'), 'id'));
        $this->getJson("/api/operations/meeting-reports/{$reportId}")->assertForbidden();
        $this->assertNotContains($m->id, $this->ids($this->getJson('/api/department/meetings')));

        // A student cannot reach the operations list at all.
        $this->as($this->user('student'))->getJson('/api/operations/meeting-reports')->assertForbidden();
    }

    public function test_a_report_is_hidden_from_a_member_when_they_cannot_view_the_meeting(): void
    {
        $m = $this->meeting($this->deptB);
        $this->as($this->admin)->postJson("/api/department/meetings/{$m->id}/report", $this->report())->assertCreated();

        $this->as($this->memberA)->getJson("/api/department/meetings/{$m->id}")->assertForbidden();
    }

    // ═══ Legacy and odd rows never crash the API ═════════════════════════════

    public function test_a_meeting_with_no_department_lists_safely_for_admin_and_quality(): void
    {
        $m = Meeting::create(['title' => 'قديم', 'type' => 'general', 'status' => 'scheduled', 'organizer_id' => $this->admin->id, 'meeting_date' => now()->toDateString(), 'start_time' => '08:00']);

        foreach ([$this->admin, $this->quality] as $who) {
            $row = collect($this->as($who)->getJson('/api/department/meetings')->assertOk()->json('data.data'))->firstWhere('id', $m->id);
            $this->assertNull($row['department']);
            $this->assertIsString($row['title']);
        }
        $this->getJson("/api/department/meetings/{$m->id}")->assertOk();
    }

    public function test_a_legacy_quick_report_row_with_nulls_is_still_listed_and_readable(): void
    {
        $m = $this->meeting($this->deptA);
        $legacy = MeetingReport::create(['meeting_id' => $m->id, 'challenges' => 'تحدٍّ قديم']); // no title/date/department/author

        $ops = $this->as($this->quality)->getJson('/api/operations/meeting-reports')->assertOk();
        $row = collect($ops->json('data.data'))->firstWhere('id', $legacy->id);
        $this->assertNotNull($row['title']);
        $this->assertNotNull($row['meeting_date']);

        $lounge = collect($this->getJson('/api/department/meetings')->json('data.data'))->firstWhere('id', $m->id);
        $this->assertTrue($lounge['has_report']);
        $this->assertFalse($lounge['capabilities']['can_upload_report'], 'a legacy report still counts as the official one');

        $detail = $this->getJson("/api/department/meetings/{$m->id}")->assertOk()->json('data');
        $this->assertSame('تحدٍّ قديم', $detail['report']['challenges']);
        $this->assertNull($detail['report']['uploaded_by']);
    }

    public function test_a_second_report_is_also_refused_when_the_first_is_a_legacy_row(): void
    {
        $m = $this->meeting($this->deptA);
        MeetingReport::create(['meeting_id' => $m->id, 'challenges' => 'قديم']);

        $this->as($this->leaderA)->postJson("/api/department/meetings/{$m->id}/report", $this->report())->assertStatus(422);
    }

    // ═══ Duplicate finder command ════════════════════════════════════════════

    public function test_the_duplicate_command_reports_a_clean_table(): void
    {
        $this->meeting($this->deptA);

        $this->artisan('meetings:duplicate-reports')->expectsOutput('No meeting has more than one report.')->assertSuccessful();
    }

    public function test_the_duplicate_command_lists_but_never_deletes_and_refuses_to_index_while_duplicates_remain(): void
    {
        $m = $this->meeting($this->deptA, ['title' => 'اجتماع مكرر']);
        // Simulate a table that predates the index.
        Schema::table('meeting_reports', fn ($t) => $t->dropUnique('meeting_reports_meeting_id_unique'));
        MeetingReport::create(['meeting_id' => $m->id, 'title' => 'أول']);
        MeetingReport::create(['meeting_id' => $m->id, 'title' => 'ثان']);

        $this->artisan('meetings:duplicate-reports')->assertFailed();
        $this->artisan('meetings:duplicate-reports --create-index')->expectsOutput('Index NOT created: duplicates remain.')->assertFailed();

        $this->assertSame(2, MeetingReport::where('meeting_id', $m->id)->count(), 'nothing was deleted');
    }

    public function test_the_duplicate_command_adds_the_index_once_the_table_is_clean(): void
    {
        $m = $this->meeting($this->deptA);
        Schema::table('meeting_reports', fn ($t) => $t->dropUnique('meeting_reports_meeting_id_unique'));
        MeetingReport::create(['meeting_id' => $m->id, 'title' => 'وحيد']);

        $this->artisan('meetings:duplicate-reports --create-index')->expectsOutput('Unique index meeting_reports_meeting_id_unique created.')->assertSuccessful();

        $this->expectException(UniqueConstraintViolationException::class);
        MeetingReport::create(['meeting_id' => $m->id, 'title' => 'ثان']);
    }
}
