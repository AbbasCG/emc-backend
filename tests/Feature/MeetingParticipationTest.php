<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Meeting;
use App\Models\MeetingActionItem;
use App\Models\MeetingDecision;
use App\Models\MeetingParticipant;
use App\Models\MeetingReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PARTICIPANTS, ATTENDANCE, REPORT PRIVACY AND THE CALENDAR.
 *
 * Who is assigned to a meeting (invited by name, or its department when nobody was
 * invited), who may join and be counted, what the official report records about
 * attendance, who may read that report, and who sees the meeting in the calendar.
 */
class MeetingParticipationTest extends TestCase
{
    use RefreshDatabase;

    private Department $deptA;
    private Department $deptB;
    private User $admin;
    private User $leaderA;
    private User $memberA1;
    private User $memberA2;
    private User $memberB;
    private User $quality;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deptA = $this->dept('إدارة البرامج');
        $this->deptB = $this->dept('إدارة الشراكات');
        $this->admin = $this->user('super_admin', 'المدير العام');
        $this->leaderA = $this->user('programs_manager', 'قائد البرامج');
        $this->deptA->update(['leader_id' => $this->leaderA->id]);
        $this->memberA1 = $this->user('instructor', 'سارة');
        $this->memberA2 = $this->user('volunteer', 'أحمد');
        $this->memberB = $this->user('instructor', 'ليلى');
        $this->memberA1->departments()->attach($this->deptA->id);
        $this->memberA2->departments()->attach($this->deptA->id);
        $this->memberB->departments()->attach($this->deptB->id);
        $this->quality = $this->user('quality_manager', 'مدير الجودة');
        $this->student = $this->user('student', 'طالب');
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function dept(string $nameAr): Department
    {
        return Department::create([
            'name' => 'Dept '.Str::random(5), 'name_ar' => $nameAr, 'slug' => 'd-'.Str::random(8),
            'status' => 'active', 'is_active' => true,
        ]);
    }

    private function user(string $role, string $name = null, array $over = []): User
    {
        return User::factory()->create(array_merge(['role' => $role, 'is_active' => true, 'name' => $name ?? 'مستخدم '.Str::random(4)], $over));
    }

    private function meeting(?Department $d, array $over = []): Meeting
    {
        return Meeting::create(array_merge([
            'title' => 'اجتماع الإدارة', 'type' => 'department', 'status' => 'scheduled',
            'department_id' => $d?->id, 'organizer_id' => $this->admin->id,
            'meeting_date' => now()->addDays(2)->toDateString(), 'start_time' => '10:00', 'end_time' => '11:30',
            'meeting_url' => 'https://meet.example.test/abc',
        ], $over));
    }

    private function invite(Meeting $m, User ...$users): void
    {
        foreach ($users as $u) {
            MeetingParticipant::create(['meeting_id' => $m->id, 'user_id' => $u->id, 'source' => 'invited', 'status' => 'invited']);
        }
    }

    private function as(User $u): static
    {
        Sanctum::actingAs($u);

        return $this;
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'title' => 'اجتماع جديد', 'type' => 'department', 'meeting_date' => now()->addDay()->toDateString(),
            'start_time' => '09:30', 'department_id' => $this->deptA->id,
        ], $over);
    }

    private function reportBody(array $over = []): array
    {
        return array_merge([
            'completed_tasks_summary' => 'أُنجزت خطة الربع', 'challenges' => 'ضيق الوقت', 'next_month_goals' => 'إطلاق النسخة',
            'team_commitment_score' => 4, 'kpi_percentage' => 90,
        ], $over);
    }

    private function ids($response): array
    {
        return array_column($response->json('data.data'), 'id');
    }

    private function text($response): string
    {
        return json_encode($response->json(), JSON_UNESCAPED_UNICODE);
    }

    // ═══ Assignment and visibility ═══════════════════════════════════════════

    public function test_an_admin_schedules_a_meeting_for_chosen_participants_and_only_they_see_it(): void
    {
        $res = $this->as($this->admin)->postJson('/api/department/meetings', $this->payload([
            'participant_ids' => [$this->memberA1->id],
        ]))->assertCreated();
        $id = $res->json('data.id');

        $this->assertSame('explicit', $res->json('data.audience'));
        $this->assertDatabaseHas('meeting_participants', ['meeting_id' => $id, 'user_id' => $this->memberA1->id, 'source' => 'invited']);

        $this->assertContains($id, $this->ids($this->as($this->memberA1)->getJson('/api/department/meetings')));
        // Same department, not invited: the meeting is not theirs.
        $this->assertNotContains($id, $this->ids($this->as($this->memberA2)->getJson('/api/department/meetings')));
        $this->as($this->memberA2)->getJson("/api/department/meetings/{$id}")->assertForbidden();
    }

    public function test_a_department_wide_meeting_is_seen_by_every_member_of_that_department_only(): void
    {
        $id = $this->as($this->admin)->postJson('/api/department/meetings', $this->payload())->assertCreated()->json('data.id');

        $this->assertSame('department', $this->as($this->memberA1)->getJson("/api/department/meetings/{$id}")->json('data.audience'));
        $this->assertContains($id, $this->ids($this->as($this->memberA2)->getJson('/api/department/meetings')));
        $this->assertNotContains($id, $this->ids($this->as($this->memberB)->getJson('/api/department/meetings')));
        $this->as($this->memberB)->getJson("/api/department/meetings/{$id}")->assertForbidden();
        $this->as($this->student)->getJson("/api/department/meetings/{$id}")->assertForbidden();
    }

    public function test_the_department_leader_still_sees_an_explicit_meeting_of_their_department(): void
    {
        $m = $this->meeting($this->deptA);
        $this->invite($m, $this->memberA1);

        $this->assertContains($m->id, $this->ids($this->as($this->leaderA)->getJson('/api/department/meetings')));
        $this->as($this->leaderA)->getJson("/api/department/meetings/{$m->id}")->assertOk();
    }

    public function test_a_meeting_with_no_department_and_no_invitees_is_private_to_its_organizer_and_oversight(): void
    {
        $m = $this->meeting(null, ['organizer_id' => $this->leaderA->id]);

        $this->as($this->memberA1)->getJson("/api/department/meetings/{$m->id}")->assertForbidden();
        $this->as($this->leaderA)->getJson("/api/department/meetings/{$m->id}")->assertOk();
        $this->as($this->quality)->getJson("/api/department/meetings/{$m->id}")->assertOk();
        $this->as($this->admin)->getJson("/api/department/meetings/{$m->id}")->assertOk();
    }

    public function test_the_list_filters_upcoming_live_and_past(): void
    {
        // Fixed clock: the live meeting runs from 30 minutes before to 30 minutes after "now" on ONE date, which only
        // holds away from midnight. With the real clock this failed in the half hour either side of midnight
        // (Amsterdam), when that window crossed into another day. The application was behaving correctly.
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-14 12:00:00', 'Europe/Amsterdam'));
        $today = now('Europe/Amsterdam');
        $past = $this->meeting($this->deptA, ['meeting_date' => $today->copy()->subDays(3)->toDateString()]);
        $future = $this->meeting($this->deptA, ['meeting_date' => $today->copy()->addDays(3)->toDateString()]);
        $live = $this->meeting($this->deptA, [
            'meeting_date' => $today->toDateString(),
            'start_time' => $today->copy()->subMinutes(30)->format('H:i'),
            'end_time' => $today->copy()->addMinutes(30)->format('H:i'),
        ]);
        $this->as($this->admin);

        $this->assertSame([$past->id], $this->ids($this->getJson('/api/department/meetings?when=past')));
        $this->assertSame([$live->id], $this->ids($this->getJson('/api/department/meetings?when=live')));
        $this->assertContains($future->id, $this->ids($this->getJson('/api/department/meetings?when=upcoming')));
        $this->assertNotContains($past->id, $this->ids($this->getJson('/api/department/meetings?when=upcoming')));
    }

    // ═══ Safe details ════════════════════════════════════════════════════════

    public function test_an_assigned_member_gets_complete_safe_details(): void
    {
        $m = $this->meeting($this->deptA, ['agenda' => ['افتتاح', 'مراجعة الخطة'], 'description' => 'وصف الاجتماع']);

        $d = $this->as($this->memberA1)->getJson("/api/department/meetings/{$m->id}")->assertOk()->json('data');

        $this->assertSame('اجتماع الإدارة', $d['title']);
        $this->assertSame('department', $d['type']);
        $this->assertSame($m->meeting_date->toDateString(), $d['meeting_date']);
        $this->assertSame('10:00', $d['start_time']);
        $this->assertSame('11:30', $d['end_time']);
        $this->assertSame('Europe/Amsterdam', $d['timezone']);
        $this->assertSame($this->deptA->name_ar, $d['department']['name']);
        $this->assertSame($this->admin->id, $d['organizer']['id']);
        $this->assertSame(['افتتاح', 'مراجعة الخطة'], $d['agenda']);
        $this->assertSame('https://meet.example.test/abc', $d['meeting_url']);
        $this->assertNotNull($d['created_at']);
        $this->assertNotNull($d['updated_at']);
        $this->assertSame('وصف الاجتماع', $d['description']);
    }

    public function test_a_normal_member_sees_only_their_own_attendance_not_the_participant_list(): void
    {
        $m = $this->meeting($this->deptA);

        $d = $this->as($this->memberA1)->getJson("/api/department/meetings/{$m->id}")->json('data');

        $this->assertFalse($d['participants_visible']);
        $this->assertSame([], $d['participants']);
        $this->assertNull($d['attendance_summary']);
        $this->assertNull($d['my_attendance']);
        $this->assertFalse($d['capabilities']['can_view_attendance']);
        $this->assertFalse($d['capabilities']['can_manage']);
        $this->assertFalse($d['capabilities']['can_upload_report']);
        $this->assertFalse($d['capabilities']['can_view_report']);
        $this->assertFalse($d['capabilities']['can_view_quality_data']);
        $this->assertTrue($d['capabilities']['can_view']);
        $this->assertTrue($d['capabilities']['can_join']);
        $this->assertTrue($d['capabilities']['can_confirm_attendance']);
    }

    public function test_a_manager_sees_who_is_expected_and_the_attendance_summary(): void
    {
        $m = $this->meeting($this->deptA);

        $d = $this->as($this->leaderA)->getJson("/api/department/meetings/{$m->id}")->json('data');

        $names = array_column(array_column($d['participants'], 'user'), 'name');
        $this->assertEqualsCanonicalizing(['قائد البرامج', 'سارة', 'أحمد'], $names);
        $this->assertSame(3, $d['attendance_summary']['invited']);
        $this->assertSame(0, $d['attendance_summary']['attended']);
        $this->assertTrue($d['capabilities']['can_view_attendance']);
        $this->assertTrue($d['capabilities']['can_manage']);
    }

    public function test_the_join_link_is_hidden_from_people_who_are_not_assigned(): void
    {
        $m = $this->meeting($this->deptA);

        $quality = $this->as($this->quality)->getJson("/api/department/meetings/{$m->id}")->assertOk()->json('data');

        $this->assertNull($quality['meeting_url'], 'oversight reads the meeting; it does not get the link');
        $this->assertFalse($quality['capabilities']['can_join']);
        $this->assertTrue($quality['capabilities']['can_view_quality_data']);
    }

    // ═══ Joining and attendance ══════════════════════════════════════════════

    public function test_an_assigned_user_joins_and_it_is_recorded(): void
    {
        $m = $this->meeting($this->deptA);

        $res = $this->as($this->memberA1)->postJson("/api/department/meetings/{$m->id}/join")->assertOk();

        $this->assertSame('joined', $res->json('data.my_attendance.status'));
        $this->assertNotNull($res->json('data.my_attendance.joined_at'));
        $this->assertFalse($res->json('data.my_attendance.confirmed_at') !== null);
        $row = MeetingParticipant::where(['meeting_id' => $m->id, 'user_id' => $this->memberA1->id])->sole();
        $this->assertSame('self', $row->source);
        $this->assertSame('joined', $row->status);
        $this->assertSame('https://meet.example.test/abc', $res->json('data.meeting_url'));
    }

    public function test_confirming_attendance_is_recorded_and_not_offered_twice(): void
    {
        $m = $this->meeting($this->deptA);

        $res = $this->as($this->memberA1)->postJson("/api/department/meetings/{$m->id}/confirm-attendance")->assertOk();

        $this->assertNotNull($res->json('data.my_attendance.confirmed_at'));
        $this->assertFalse($res->json('data.capabilities.can_confirm_attendance'));
    }

    public function test_joining_twice_does_not_duplicate_or_reset_the_first_time(): void
    {
        $m = $this->meeting($this->deptA);
        $this->as($this->memberA1)->postJson("/api/department/meetings/{$m->id}/join")->assertOk();
        $first = MeetingParticipant::where('meeting_id', $m->id)->sole()->joined_at;
        $this->travel(5)->minutes();

        $this->postJson("/api/department/meetings/{$m->id}/join")->assertOk();

        $this->assertSame(1, MeetingParticipant::where('meeting_id', $m->id)->count());
        $this->assertTrue($first->equalTo(MeetingParticipant::where('meeting_id', $m->id)->sole()->joined_at));
    }

    public function test_an_invited_user_keeps_their_invitation_when_they_join(): void
    {
        $m = $this->meeting($this->deptA);
        $this->invite($m, $this->memberA1);

        $this->as($this->memberA1)->postJson("/api/department/meetings/{$m->id}/join")->assertOk();

        $row = MeetingParticipant::where('meeting_id', $m->id)->sole();
        $this->assertSame('invited', $row->source);
        $this->assertSame('joined', $row->status);
    }

    public function test_an_unassigned_user_cannot_join_or_confirm(): void
    {
        $m = $this->meeting($this->deptA);

        foreach (['join', 'confirm-attendance'] as $action) {
            $this->as($this->memberB)->postJson("/api/department/meetings/{$m->id}/{$action}")->assertForbidden();
            $this->as($this->student)->postJson("/api/department/meetings/{$m->id}/{$action}")->assertForbidden();
            $this->as($this->quality)->postJson("/api/department/meetings/{$m->id}/{$action}")->assertForbidden();
        }
        $this->assertSame(0, MeetingParticipant::count());
    }

    public function test_an_uninvited_department_member_cannot_join_an_explicit_meeting(): void
    {
        $m = $this->meeting($this->deptA);
        $this->invite($m, $this->memberA1);

        $this->as($this->memberA2)->postJson("/api/department/meetings/{$m->id}/join")->assertForbidden();
    }

    public function test_a_cancelled_meeting_cannot_be_joined(): void
    {
        $m = $this->meeting($this->deptA, ['status' => 'cancelled']);

        $this->as($this->memberA1)->postJson("/api/department/meetings/{$m->id}/join")->assertForbidden();
    }

    public function test_unauthenticated_requests_are_refused(): void
    {
        $m = $this->meeting($this->deptA);

        $this->postJson("/api/department/meetings/{$m->id}/join")->assertUnauthorized();
        $this->getJson("/api/department/meetings/{$m->id}/participants")->assertUnauthorized();
        $this->getJson('/api/department/meeting-participants')->assertUnauthorized();
    }

    // ═══ The participant pool ════════════════════════════════════════════════

    public function test_scheduling_candidates_for_a_department_are_only_that_departments_people(): void
    {
        $res = $this->as($this->admin)->getJson("/api/department/meeting-participants?department_id={$this->deptA->id}")->assertOk();

        $names = array_column($res->json('data'), 'name');
        $this->assertEqualsCanonicalizing(['قائد البرامج', 'سارة', 'أحمد'], $names);
        $this->assertNotContains('ليلى', $names);
        $this->assertContains($this->deptA->name_ar, array_column($res->json('data'), 'department'));
    }

    public function test_candidates_exclude_inactive_accounts_and_lms_students(): void
    {
        $inactive = $this->user('instructor', 'غير نشط', ['is_active' => false]);
        $inactive->departments()->attach($this->deptA->id);
        $this->student->departments()->attach($this->deptA->id);

        $names = array_column($this->as($this->admin)->getJson("/api/department/meeting-participants?department_id={$this->deptA->id}")->json('data'), 'name');

        $this->assertNotContains('غير نشط', $names);
        $this->assertNotContains('طالب', $names);
    }

    public function test_with_no_department_an_admin_gets_emc_members_from_every_department_but_not_everyone(): void
    {
        $outsider = $this->user('instructor', 'بلا إدارة');

        $names = array_column($this->as($this->admin)->getJson('/api/department/meeting-participants')->assertOk()->json('data'), 'name');

        $this->assertContains('سارة', $names);
        $this->assertContains('ليلى', $names);
        $this->assertNotContains('بلا إدارة', $names, 'a user in no department is not an EMC member');
        $this->assertNotContains('طالب', $names);
        $this->assertNotContains($outsider->name, $names);
    }

    public function test_with_no_department_a_leader_gets_only_people_inside_their_own_scope(): void
    {
        $names = array_column($this->as($this->leaderA)->getJson('/api/department/meeting-participants')->assertOk()->json('data'), 'name');

        $this->assertContains('سارة', $names);
        $this->assertNotContains('ليلى', $names, 'department B is outside this leader\'s scope');
    }

    public function test_someone_who_cannot_schedule_gets_no_candidates(): void
    {
        $this->as($this->memberA1)->getJson('/api/department/meeting-participants')->assertForbidden();
        $this->as($this->leaderA)->getJson("/api/department/meeting-participants?department_id={$this->deptB->id}")->assertForbidden();
    }

    public function test_the_server_rejects_invitees_outside_the_allowed_pool(): void
    {
        $res = $this->as($this->leaderA)->postJson('/api/department/meetings', $this->payload([
            'participant_ids' => [$this->memberA1->id, $this->memberB->id],
        ]))->assertStatus(422);

        $this->assertSame('بعض المدعوّين ليسوا من أعضاء الإدارة أو نطاقك المسموح.', $res->json('message'));
        $this->assertSame(0, Meeting::count());
    }

    public function test_the_server_rejects_a_student_even_when_the_client_sends_their_id(): void
    {
        $this->as($this->admin)->postJson('/api/department/meetings', $this->payload([
            'participant_ids' => [$this->student->id],
        ]))->assertStatus(422);
    }

    public function test_the_report_pool_of_a_department_meeting_is_that_department_only(): void
    {
        $m = $this->meeting($this->deptA);

        $res = $this->as($this->leaderA)->getJson("/api/department/meetings/{$m->id}/participants")->assertOk();

        $this->assertEqualsCanonicalizing(['قائد البرامج', 'سارة', 'أحمد'], array_column($res->json('data'), 'name'));
        $this->assertSame('department', $res->json('audience'));
    }

    public function test_the_report_pool_of_an_explicit_meeting_is_exactly_the_invited_people(): void
    {
        $m = $this->meeting($this->deptA);
        $this->invite($m, $this->memberA1);

        $res = $this->as($this->leaderA)->getJson("/api/department/meetings/{$m->id}/participants")->assertOk();

        $this->assertSame(['سارة'], array_column($res->json('data'), 'name'));
        $this->assertSame('explicit', $res->json('audience'));
    }

    public function test_the_report_pool_shows_who_already_joined_so_the_form_can_prefill(): void
    {
        $m = $this->meeting($this->deptA);
        $this->as($this->memberA1)->postJson("/api/department/meetings/{$m->id}/join")->assertOk();

        $row = collect($this->as($this->leaderA)->getJson("/api/department/meetings/{$m->id}/participants")->json('data'))->firstWhere('name', 'سارة');

        $this->assertSame('joined', $row['status']);
        $this->assertNotNull($row['joined_at']);
    }

    public function test_the_report_pool_of_a_departmentless_meeting_follows_the_viewers_scope(): void
    {
        $m = $this->meeting(null);

        $global = array_column($this->as($this->admin)->getJson("/api/department/meetings/{$m->id}/participants")->json('data'), 'name');
        $this->assertContains('ليلى', $global);
        $this->assertContains('سارة', $global);
    }

    public function test_only_a_manager_may_read_the_report_pool(): void
    {
        $m = $this->meeting($this->deptA);

        $this->as($this->memberA1)->getJson("/api/department/meetings/{$m->id}/participants")->assertForbidden();
        $this->as($this->quality)->getJson("/api/department/meetings/{$m->id}/participants")->assertForbidden();
    }

    // ═══ The official report: attendance, decisions, action items ════════════

    private function file(Meeting $m, User $as, array $over = [])
    {
        return $this->as($as)->postJson("/api/department/meetings/{$m->id}/report", $this->reportBody($over));
    }

    public function test_the_report_records_attendance_and_the_participants_keep_the_verdict(): void
    {
        $m = $this->meeting($this->deptA);
        $this->as($this->memberA1)->postJson("/api/department/meetings/{$m->id}/join")->assertOk();

        $res = $this->file($m, $this->leaderA, ['attendance' => [
            ['user_id' => $this->leaderA->id, 'attended' => true],
            ['user_id' => $this->memberA1->id, 'attended' => true],
            ['user_id' => $this->memberA2->id, 'attended' => false],
        ]])->assertCreated();

        $this->assertSame('attended', MeetingParticipant::where(['meeting_id' => $m->id, 'user_id' => $this->memberA1->id])->value('status'));
        $this->assertSame('absent', MeetingParticipant::where(['meeting_id' => $m->id, 'user_id' => $this->memberA2->id])->value('status'));
        $this->assertSame($this->leaderA->id, (int) MeetingParticipant::where(['meeting_id' => $m->id, 'user_id' => $this->memberA2->id])->value('marked_by'));
        $this->assertSame(['invited' => 3, 'joined' => 0, 'attended' => 2, 'absent' => 1], $res->json('data.attendance_summary'));
        // The absent person's NAME lands in the report itself.
        $this->assertContains('أحمد', $res->json('data.report.absentees'));
    }

    public function test_attendance_is_required_when_the_meeting_has_people(): void
    {
        $m = $this->meeting($this->deptA);

        $res = $this->file($m, $this->leaderA)->assertStatus(422);

        $this->assertSame('سجّل حضور المشاركين قبل رفع التقرير.', $res->json('message'));
        $this->assertSame(0, MeetingReport::count());
    }

    public function test_attendance_of_someone_outside_the_pool_is_refused(): void
    {
        $m = $this->meeting($this->deptA);

        $res = $this->file($m, $this->leaderA, ['attendance' => [
            ['user_id' => $this->memberA1->id, 'attended' => true],
            ['user_id' => $this->memberB->id, 'attended' => true],
        ]])->assertStatus(422);

        $this->assertSame('لا يمكن تسجيل حضور مستخدم ليس من المشاركين في هذا الاجتماع.', $res->json('message'));
        $this->assertSame(0, MeetingReport::count());
        $this->assertSame(0, MeetingParticipant::count());
    }

    public function test_an_explicit_meeting_takes_attendance_only_for_the_invited(): void
    {
        $m = $this->meeting($this->deptA);
        $this->invite($m, $this->memberA1);

        $this->file($m, $this->leaderA, ['attendance' => [['user_id' => $this->memberA2->id, 'attended' => true]]])->assertStatus(422);
        $this->file($m, $this->leaderA, ['attendance' => [['user_id' => $this->memberA1->id, 'attended' => true]]])->assertCreated();
    }

    public function test_a_meeting_nobody_belongs_to_can_be_reported_without_attendance(): void
    {
        $lonely = $this->dept('إدارة فارغة');
        $m = $this->meeting($lonely);

        $this->file($m, $this->admin)->assertCreated();
    }

    public function test_decisions_and_action_items_are_recorded_with_the_report(): void
    {
        $m = $this->meeting($this->deptA);

        $res = $this->file($m, $this->leaderA, [
            'attendance' => [['user_id' => $this->memberA1->id, 'attended' => true]],
            'decisions' => [['title' => 'اعتماد الميزانية', 'description' => 'بالإجماع'], ['title' => 'تأجيل الإطلاق']],
            'action_items' => [['title' => 'إعداد العرض', 'assigned_to' => $this->memberA1->id, 'due_date' => '2026-12-01']],
        ])->assertCreated();

        $this->assertSame(2, MeetingDecision::where('meeting_id', $m->id)->count());
        $item = MeetingActionItem::where('meeting_id', $m->id)->sole();
        $this->assertSame($this->memberA1->id, (int) $item->assigned_to);
        $this->assertSame('اعتماد الميزانية', $res->json('data.decisions.0.title'));
        $this->assertSame('إعداد العرض', $res->json('data.action_items.0.title'));
    }

    public function test_an_action_item_cannot_be_assigned_to_someone_outside_the_meeting(): void
    {
        $m = $this->meeting($this->deptA);

        $res = $this->file($m, $this->leaderA, [
            'attendance' => [['user_id' => $this->memberA1->id, 'attended' => true]],
            'action_items' => [['title' => 'بند', 'assigned_to' => $this->memberB->id]],
        ])->assertStatus(422);

        $this->assertSame('المسؤول عن بند العمل يجب أن يكون من المشاركين في الاجتماع.', $res->json('message'));
        $this->assertSame(0, MeetingActionItem::count());
    }

    public function test_decision_and_action_item_text_is_required_in_arabic(): void
    {
        $m = $this->meeting($this->deptA);

        $res = $this->file($m, $this->leaderA, [
            'attendance' => [['user_id' => $this->memberA1->id, 'attended' => true]],
            'decisions' => [['title' => '']],
        ])->assertStatus(422);

        $this->assertArrayHasKey('decisions.0.title', $res->json('errors'));
    }

    public function test_a_failed_report_leaves_no_partial_records(): void
    {
        $m = $this->meeting($this->deptA);

        $this->file($m, $this->leaderA, [
            'attendance' => [['user_id' => $this->memberA1->id, 'attended' => true]],
            'decisions' => [['title' => 'قرار']],
            'action_items' => [['title' => 'بند', 'assigned_to' => $this->memberB->id]],
        ])->assertStatus(422);

        $this->assertSame(0, MeetingDecision::count());
        $this->assertSame(0, MeetingParticipant::count());
        $this->assertSame(0, MeetingReport::count());
    }

    // ═══ Who may file and who may read ═══════════════════════════════════════

    public function test_a_plain_member_cannot_file_and_still_cannot_after_joining(): void
    {
        $m = $this->meeting($this->deptA);
        $this->as($this->memberA1)->postJson("/api/department/meetings/{$m->id}/join")->assertOk();

        $this->file($m, $this->memberA1, ['attendance' => [['user_id' => $this->memberA1->id, 'attended' => true]]])->assertForbidden();
        $this->assertSame(0, MeetingReport::count());
    }

    public function test_a_second_report_is_refused_even_with_a_perfectly_valid_body(): void
    {
        $m = $this->meeting($this->deptA);
        $att = ['attendance' => [['user_id' => $this->memberA1->id, 'attended' => true]]];
        $this->file($m, $this->leaderA, $att)->assertCreated();

        $res = $this->file($m, $this->admin, $att)->assertStatus(422);

        $this->assertSame('تم رفع تقرير لهذا الاجتماع مسبقًا.', $res->json('message'));
        $this->assertSame(1, MeetingReport::where('meeting_id', $m->id)->count());
    }

    public function test_the_report_content_is_hidden_from_normal_assigned_users_everywhere(): void
    {
        $m = $this->meeting($this->deptA);
        $this->file($m, $this->leaderA, [
            'attendance' => [['user_id' => $this->memberA1->id, 'attended' => true]],
            'decisions' => [['title' => 'قرار سري']],
        ])->assertCreated();

        $list = $this->as($this->memberA1)->getJson('/api/department/meetings')->assertOk();
        $row = collect($list->json('data.data'))->firstWhere('id', $m->id);
        $this->assertTrue($row['has_report']);
        $this->assertFalse($row['capabilities']['can_view_report']);
        $this->assertNull($row['report_id']);

        $detail = $this->getJson("/api/department/meetings/{$m->id}");
        $this->assertStringNotContainsString('قرار سري', $this->text($detail));
        $this->assertStringNotContainsString('أُنجزت خطة الربع', $this->text($detail));

        $reportId = MeetingReport::where('meeting_id', $m->id)->value('id');
        $this->getJson("/api/operations/meeting-reports/{$reportId}")->assertForbidden();
        $ops = $this->getJson('/api/operations/meeting-reports')->assertOk();
        $this->assertSame([], $ops->json('data.data'));
    }

    public function test_quality_reads_the_official_report_with_attendance_decisions_and_action_items(): void
    {
        $m = $this->meeting($this->deptA, ['title' => 'اجتماع الجودة الشهري', 'type' => 'quality']);
        $this->as($this->memberA1)->postJson("/api/department/meetings/{$m->id}/join")->assertOk();
        $this->file($m, $this->leaderA, [
            'attendance' => [
                ['user_id' => $this->leaderA->id, 'attended' => true],
                ['user_id' => $this->memberA1->id, 'attended' => true],
                ['user_id' => $this->memberA2->id, 'attended' => false],
            ],
            'decisions' => [['title' => 'اعتماد الخطة']],
            'action_items' => [['title' => 'متابعة المؤشرات', 'assigned_to' => $this->memberA1->id]],
        ])->assertCreated();

        $detail = $this->as($this->quality)->getJson("/api/department/meetings/{$m->id}")->assertOk()->json('data');
        $this->assertTrue($detail['capabilities']['can_view_report']);
        $this->assertSame('أُنجزت خطة الربع', $detail['report']['completed_tasks_summary']);
        $this->assertSame('اعتماد الخطة', $detail['decisions'][0]['title']);
        $this->assertSame('متابعة المؤشرات', $detail['action_items'][0]['title']);
        $this->assertSame(2, $detail['attendance_summary']['attended']);
        $this->assertSame(1, $detail['attendance_summary']['absent']);

        $ops = $this->getJson('/api/operations/meeting-reports')->assertOk();
        $row = collect($ops->json('data.data'))->firstWhere('meeting.id', $m->id);
        $this->assertNotNull($row);
        $this->assertSame(['invited' => 3, 'attended' => 2, 'absent' => 1], $row['attendance']);
        $this->assertSame('quality', $row['meeting']['type']);
        $this->assertSame($this->leaderA->id, $row['author']['id']);

        $full = $this->getJson("/api/operations/meeting-reports/{$row['id']}")->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['قائد البرامج', 'سارة'], $full['attendance']['attendees']);
        $this->assertSame(['أحمد'], $full['attendance']['absent']);
    }

    public function test_the_leader_who_filed_it_reads_it_back(): void
    {
        $m = $this->meeting($this->deptA);
        $this->file($m, $this->leaderA, ['attendance' => [['user_id' => $this->memberA1->id, 'attended' => true]]])->assertCreated();

        $d = $this->as($this->leaderA)->getJson("/api/department/meetings/{$m->id}")->json('data');

        $this->assertTrue($d['capabilities']['can_view_report']);
        $this->assertNotNull($d['report']);
    }

    public function test_tech_admin_gets_no_extra_power_beyond_the_existing_global_architecture(): void
    {
        $tech = $this->user('tech_admin', 'تقني');
        $m = $this->meeting($this->deptB);

        // Same as every global role in the existing architecture (it is in DepartmentAccessService::GLOBAL_ROLES).
        $this->as($tech)->getJson("/api/department/meetings/{$m->id}")->assertOk();
        // An unrelated role is not elevated by being "admin-like" in name.
        $hrManager = $this->user('hr_manager', 'موارد بشرية');
        $this->as($hrManager)->getJson("/api/department/meetings/{$m->id}")->assertForbidden();
        $this->as($hrManager)->postJson("/api/department/meetings/{$m->id}/report", $this->reportBody())->assertForbidden();
    }

    // ═══ Calendar ════════════════════════════════════════════════════════════

    private function calendarMeetingIds(User $u): array
    {
        $res = $this->as($u)->getJson('/api/calendar/events?start_date='.now()->subDay()->toDateString().'&end_date='.now()->addDays(30)->toDateString())->assertOk();

        return collect($res->json('data'))->where('type', 'meeting')->pluck('id')->all();
    }

    public function test_the_calendar_shows_a_department_meeting_to_every_member_of_that_department_whatever_their_role(): void
    {
        $m = $this->meeting($this->deptA);

        $this->assertContains("meeting_{$m->id}", $this->calendarMeetingIds($this->memberA1)); // instructor
        $this->assertContains("meeting_{$m->id}", $this->calendarMeetingIds($this->memberA2)); // volunteer
        $this->assertContains("meeting_{$m->id}", $this->calendarMeetingIds($this->leaderA));
    }

    public function test_the_calendar_excludes_meetings_of_other_departments_and_unrelated_users(): void
    {
        $m = $this->meeting($this->deptA);

        $this->assertNotContains("meeting_{$m->id}", $this->calendarMeetingIds($this->memberB));
        $this->assertNotContains("meeting_{$m->id}", $this->calendarMeetingIds($this->student));
        $this->assertNotContains("meeting_{$m->id}", $this->calendarMeetingIds($this->user('hr_manager')));
    }

    public function test_an_explicit_meeting_is_in_the_calendar_of_the_invited_only(): void
    {
        $m = $this->meeting($this->deptA);
        $this->invite($m, $this->memberA1);

        $this->assertContains("meeting_{$m->id}", $this->calendarMeetingIds($this->memberA1));
        $this->assertNotContains("meeting_{$m->id}", $this->calendarMeetingIds($this->memberA2));
        $this->assertContains("meeting_{$m->id}", $this->calendarMeetingIds($this->leaderA), 'the department leader manages it');
    }

    public function test_the_calendar_shows_a_meeting_to_its_organizer_and_to_global_admins_only_otherwise(): void
    {
        $m = $this->meeting(null, ['organizer_id' => $this->leaderA->id]);

        $this->assertContains("meeting_{$m->id}", $this->calendarMeetingIds($this->leaderA));
        $this->assertContains("meeting_{$m->id}", $this->calendarMeetingIds($this->admin));
        $this->assertNotContains("meeting_{$m->id}", $this->calendarMeetingIds($this->memberA1));
        $this->assertNotContains("meeting_{$m->id}", $this->calendarMeetingIds($this->quality), 'oversight reads reports; it is not invited to every meeting');
    }

    public function test_a_calendar_meeting_carries_what_the_ui_needs_and_the_link_only_for_assigned_users(): void
    {
        $m = $this->meeting($this->deptA);

        $event = collect($this->as($this->memberA1)->getJson('/api/calendar/events?start_date='.now()->toDateString().'&end_date='.now()->addDays(30)->toDateString())->json('data'))
            ->firstWhere('id', "meeting_{$m->id}");

        $this->assertSame('اجتماع الإدارة', $event['title']);
        $this->assertSame('department', $event['meeting_type']);
        $this->assertSame("/dashboard/department/meeting-lounge/{$m->id}", $event['details_url']);
        $this->assertSame('https://meet.example.test/abc', $event['meeting_link']);
        $this->assertTrue($event['can_join']);
        $this->assertFalse($event['can_edit']);
        $this->assertSame('scheduled', $event['status']);
        $this->assertNotNull($event['start_at']);
        $this->assertNotNull($event['end_at']);
    }

    public function test_a_cancelled_meeting_offers_no_join_in_the_calendar(): void
    {
        $m = $this->meeting($this->deptA, ['status' => 'cancelled']);

        $event = collect($this->as($this->memberA1)->getJson('/api/calendar/events?start_date='.now()->toDateString().'&end_date='.now()->addDays(30)->toDateString())->json('data'))
            ->firstWhere('id', "meeting_{$m->id}");

        $this->assertFalse($event['can_join']);
        $this->assertNull($event['meeting_link']);
    }

    // ═══ Serialization and legacy rows ═══════════════════════════════════════

    public function test_dates_and_times_serialize_as_plain_strings_everywhere(): void
    {
        $m = $this->meeting($this->deptA, ['start_time' => '22:00:00', 'end_time' => '23:00:00']);

        foreach ([
            $this->as($this->admin)->getJson("/api/department/meetings/{$m->id}")->json('data'),
            collect($this->getJson('/api/department/meetings')->json('data.data'))->firstWhere('id', $m->id),
        ] as $row) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $row['meeting_date']);
            $this->assertSame('22:00', $row['start_time']);
            $this->assertSame('23:00', $row['end_time']);
            $this->assertSame($row['meeting_date'].' 22:00', $row['starts_at']);
        }
    }

    public function test_legacy_rows_with_null_columns_never_break_the_api(): void
    {
        $m = Meeting::create([
            'title' => 'قديم', 'type' => 'general', 'status' => 'scheduled', 'organizer_id' => $this->admin->id,
            'meeting_date' => now()->toDateString(), 'start_time' => '08:00',
        ]);
        MeetingReport::create(['meeting_id' => $m->id, 'challenges' => 'قديم']);

        $this->as($this->admin);
        $row = collect($this->getJson('/api/department/meetings')->assertOk()->json('data.data'))->firstWhere('id', $m->id);
        $this->assertNull($row['department']);
        $this->assertSame('general', $row['audience']);
        $d = $this->getJson("/api/department/meetings/{$m->id}")->assertOk()->json('data');
        $this->assertSame([], $d['agenda']);
        $this->assertSame([], $d['participants']);
        $this->assertSame('قديم', $d['report']['challenges']);
        $this->as($this->quality)->getJson('/api/operations/meeting-reports')->assertOk();
    }

    public function test_a_malformed_agenda_value_is_normalized_to_a_clean_list(): void
    {
        $m = $this->meeting($this->deptA, ['agenda' => ['  بند  ', '', ['title' => 'من كائن'], 7]]);

        $d = $this->as($this->leaderA)->getJson("/api/department/meetings/{$m->id}")->json('data');

        $this->assertSame(['بند', 'من كائن'], $d['agenda']);
    }

    public function test_the_agenda_lines_sent_when_scheduling_are_stored_as_a_list(): void
    {
        $res = $this->as($this->admin)->postJson('/api/department/meetings', $this->payload(['agenda' => "افتتاح\n\nمراجعة الخطة\n"]))->assertCreated();

        $this->assertSame(['افتتاح', 'مراجعة الخطة'], $res->json('data.agenda'));
    }

    public function test_changing_the_invitees_of_a_meeting_keeps_those_who_already_joined(): void
    {
        $m = $this->meeting($this->deptA);
        $this->invite($m, $this->memberA1, $this->memberA2);
        $this->as($this->memberA1)->postJson("/api/department/meetings/{$m->id}/join")->assertOk();

        $this->as($this->leaderA)->putJson("/api/department/meetings/{$m->id}", ['participant_ids' => [$this->memberA1->id]])->assertOk();

        $this->assertSame([$this->memberA1->id], MeetingParticipant::where('meeting_id', $m->id)->pluck('user_id')->map(fn ($i) => (int) $i)->all());
    }

    public function test_the_participants_stored_with_a_meeting_are_removed_with_it(): void
    {
        $m = $this->meeting($this->deptA);
        $this->invite($m, $this->memberA1);

        $m->delete();

        $this->assertSame(0, MeetingParticipant::count());
    }

    // ═══ Product decisions: audience, report privacy, attendance ═════════════

    public function test_a_meeting_must_name_a_department_or_invitees(): void
    {
        $res = $this->as($this->admin)->postJson('/api/department/meetings', $this->payload(['department_id' => null]))->assertStatus(422);

        $this->assertSame('اختر الإدارة لجعل الاجتماع لكل أعضائها، أو اختر مدعوّين بأسمائهم.', $res->json('message'));
        $this->assertSame(0, Meeting::count());
    }

    public function test_a_departmentless_meeting_is_allowed_when_it_names_its_invitees(): void
    {
        $res = $this->as($this->admin)->postJson('/api/department/meetings', $this->payload([
            'department_id' => null, 'participant_ids' => [$this->memberA1->id, $this->memberB->id],
        ]))->assertCreated();

        $this->assertSame('explicit', $res->json('data.audience'));
        $this->assertNull($res->json('data.department'));
    }

    public function test_editing_the_audience_cannot_leave_a_meeting_with_nobody(): void
    {
        $m = $this->meeting($this->deptA);

        $this->as($this->admin)->putJson("/api/department/meetings/{$m->id}", ['department_id' => null])->assertStatus(422);
        $this->assertSame($this->deptA->id, (int) $m->fresh()->department_id);
    }

    public function test_a_legacy_departmentless_meeting_can_still_be_retitled(): void
    {
        $m = $this->meeting(null, ['organizer_id' => $this->leaderA->id]);

        $this->as($this->leaderA)->putJson("/api/department/meetings/{$m->id}", ['title' => 'عنوان جديد'])->assertOk();
    }

    public function test_an_invite_only_meeting_is_visible_to_invitees_managers_and_oversight_only(): void
    {
        $manager = $this->user('department_manager', 'مدير');
        $manager->departments()->attach($this->deptA->id);
        $m = $this->meeting($this->deptA);
        $this->invite($m, $this->memberA1);

        foreach ([$this->memberA1, $this->leaderA, $manager, $this->quality, $this->admin] as $who) {
            $this->as($who)->getJson("/api/department/meetings/{$m->id}")->assertOk();
            $this->assertContains($m->id, $this->ids($this->getJson('/api/department/meetings')));
        }
        foreach ([$this->memberA2, $this->student, $this->memberB] as $who) {
            $this->as($who)->getJson("/api/department/meetings/{$m->id}")->assertForbidden();
            $this->assertNotContains($m->id, $this->ids($this->getJson('/api/department/meetings')));
        }
    }

    public function test_instructors_volunteers_and_students_never_read_official_report_details(): void
    {
        // A department-wide meeting: an instructor and a volunteer are assigned through the department.
        $dept = $this->meeting($this->deptA);
        $this->file($dept, $this->leaderA, [
            'attendance' => [['user_id' => $this->memberA1->id, 'attended' => true]],
            'decisions' => [['title' => 'قرار داخلي']],
        ])->assertCreated();

        // An invite-only meeting: the student is invited by name.
        $explicit = $this->meeting($this->deptA, ['title' => 'اجتماع مغلق']);
        $this->invite($explicit, $this->memberA1, $this->student);
        $this->file($explicit, $this->leaderA, [
            'attendance' => [['user_id' => $this->memberA1->id, 'attended' => true]],
            'decisions' => [['title' => 'قرار مغلق']],
        ])->assertCreated();

        foreach ([[$this->memberA1, $dept], [$this->memberA2, $dept], [$this->student, $explicit]] as [$who, $meeting]) {
            $detail = $this->as($who)->getJson("/api/department/meetings/{$meeting->id}")->assertOk();
            $this->assertTrue($detail->json('data.has_report'));
            $this->assertNull($detail->json('data.report'));
            $this->assertNull($detail->json('data.report_id'));
            $this->assertSame([], $detail->json('data.decisions'));
            $this->assertStringNotContainsString('قرار', $this->text($detail));
            $this->assertStringNotContainsString('أُنجزت خطة الربع', $this->text($detail));

            $reportId = MeetingReport::where('meeting_id', $meeting->id)->value('id');
            $this->getJson("/api/operations/meeting-reports/{$reportId}")->assertForbidden();
            // The archive is closed to a student outright; for the others it is simply empty.
            $who === $this->student
                ? $this->getJson('/api/operations/meeting-reports')->assertForbidden()
                : $this->assertSame([], $this->getJson('/api/operations/meeting-reports')->assertOk()->json('data.data'));
        }
    }

    public function test_a_department_manager_who_holds_manage_meetings_reads_and_files_for_their_department(): void
    {
        $manager = $this->user('department_manager', 'مدير الإدارة');
        $manager->departments()->attach($this->deptA->id);
        $m = $this->meeting($this->deptA);

        $this->file($m, $manager, ['attendance' => [['user_id' => $this->memberA1->id, 'attended' => true]]])->assertCreated();
        $this->assertNotNull($this->as($manager)->getJson("/api/department/meetings/{$m->id}")->json('data.report'));
        $this->assertContains($m->id, array_column(array_column($this->getJson('/api/operations/meeting-reports')->json('data.data'), 'meeting'), 'id'));
    }

    public function test_attendance_is_required_only_when_the_meeting_has_a_participant_pool(): void
    {
        $withPeople = $this->meeting($this->deptA);
        $this->file($withPeople, $this->leaderA)->assertStatus(422);

        $empty = $this->meeting($this->dept('إدارة بلا أعضاء'));
        $this->file($empty, $this->admin)->assertCreated();
        $this->assertSame(0, MeetingParticipant::where('meeting_id', $empty->id)->count());
    }
}
