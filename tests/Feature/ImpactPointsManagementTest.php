<?php

namespace Tests\Feature;

use App\Http\Controllers\Operations\VolunteerPointsController;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use App\Models\VolunteerPointAward;
use App\Models\VolunteerPointRedemption;
use Database\Seeders\GranularPermissionsSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * IMPACT POINTS — the HR management surface: who may list the people with
 * points, read another person's history, and append a correction.
 *
 * Authority is the existing business permission (members.recognize /
 * members.manage) with the existing department scope; opening the page grants
 * none of it. Roles are seeded exactly as production seeds them.
 */
class ImpactPointsManagementTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/operations/volunteer-points';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, GranularPermissionsSeeder::class]);
        User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->artisan('access:sync-role-defaults', ['--apply' => true])->assertExitCode(0);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function user(string $role, array $attrs = []): User
    {
        return User::factory()->create(array_merge(['role' => $role, 'is_active' => true], $attrs));
    }

    private function department(array $attrs = []): Department
    {
        $suffix = Str::random(6);

        return Department::create(array_merge([
            'name' => 'إدارة '.$suffix, 'name_ar' => 'إدارة '.$suffix, 'slug' => 'dept-'.strtolower($suffix),
            'status' => 'active', 'is_active' => true,
        ], $attrs));
    }

    private function member(Department $dept, string $role = 'volunteer', string $title = 'منسق', array $attrs = []): User
    {
        $u = $this->user($role, $attrs);
        $profile = TeamProfile::create([
            'department_id' => $dept->id, 'user_id' => $u->id, 'name_ar' => $u->name,
            'position_ar' => 'عضو فريق', 'is_active' => true,
        ]);
        TeamMember::create([
            'user_id' => $u->id, 'team_profile_id' => $profile->id, 'department_id' => $dept->id,
            'status' => 'active', 'role_title' => $title,
        ]);

        return $u;
    }

    private function seedPoints(User $to, int $points, array $attrs = []): VolunteerPointAward
    {
        return VolunteerPointAward::create(array_merge([
            'user_id' => $to->id, 'category' => 'task', 'points' => $points,
            'reason' => 'seed', 'awarded_by' => null, 'awarded_at' => now(),
        ], $attrs));
    }

    private function adjust(User $actor, User $target, array $body = [])
    {
        return $this->actingAs($actor)->postJson(self::BASE."/users/{$target->id}/adjust", $body + [
            'direction' => 'add', 'points' => 50, 'reason' => 'تصحيح موثق',
        ]);
    }

    private function members(User $actor, string $query = '')
    {
        return $this->actingAs($actor)->getJson(self::BASE.'/members'.$query);
    }

    private function total(User $u): int
    {
        return (int) VolunteerPointAward::where('user_id', $u->id)->sum('points');
    }

    // ── 1–3. The ordinary member: own data only ──────────────────────────────

    public function test_a_volunteer_sees_their_own_points_and_history(): void
    {
        $me = $this->member($this->department());
        $this->seedPoints($me, 120);

        $this->actingAs($me)->getJson(self::BASE.'/summary')->assertOk()->assertJsonPath('data.lifetime_points', 120);
        $this->actingAs($me)->getJson(self::BASE.'/my-awards')->assertOk()->assertJsonCount(1, 'data.data');
        $this->actingAs($me)->getJson(self::BASE."/users/{$me->id}/history")->assertOk()
            ->assertJsonPath('data.summary.lifetime_points', 120)
            ->assertJsonCount(1, 'data.entries.data');
    }

    public function test_a_volunteer_cannot_list_everyone_or_the_recipient_picker(): void
    {
        $me = $this->member($this->department());

        $this->members($me)->assertForbidden();
        $this->actingAs($me)->getJson(self::BASE.'/recipients')->assertForbidden();
    }

    public function test_a_volunteer_cannot_adjust_award_or_read_anyone_elses_history(): void
    {
        $dept = $this->department();
        $me = $this->member($dept);
        $other = $this->member($dept);
        $this->seedPoints($other, 300);

        $this->adjust($me, $other)->assertForbidden();
        $this->actingAs($me)->getJson(self::BASE."/users/{$other->id}/history")->assertForbidden();
        $this->actingAs($me)->postJson(self::BASE.'/awards', ['user_id' => $other->id, 'category' => 'task', 'points' => 10, 'reason' => 'x'])->assertForbidden();

        $this->assertSame(300, $this->total($other));
    }

    // ── 4–5. HR Manager: list and history ────────────────────────────────────

    public function test_hr_manager_lists_everyone_who_received_points_with_context(): void
    {
        $hr = $this->user('hr_manager');
        $programs = $this->department(['name' => 'إدارة البرامج', 'name_ar' => 'إدارة البرامج']);
        $media = $this->department(['name' => 'إدارة الإعلام', 'name_ar' => 'إدارة الإعلام']);
        $sara = $this->member($programs, 'volunteer', 'منسقة برامج', ['name' => 'سارة']);
        $khaled = $this->member($media, 'volunteer', 'مصمم', ['name' => 'خالد']);
        $nopoints = $this->member($media, 'volunteer', 'محرر', ['name' => 'بلا نقاط']);
        $this->seedPoints($sara, 900);
        $this->seedPoints($khaled, 400);

        $res = $this->members($hr)->assertOk();

        $rows = collect($res->json('data'));
        $this->assertSame([$sara->id, $khaled->id], $rows->pluck('user_id')->all(), 'only people with points, highest first');
        $first = $rows->first();
        $this->assertSame('إدارة البرامج', $first['department']);
        $this->assertSame('منسقة برامج', $first['role_title']);
        $this->assertSame(900, $first['lifetime_points']);
        $this->assertSame('volunteer', $first['level']['id']);
        $this->assertNotNull($first['last_activity_at']);
        $this->assertSame(1, $first['awards_count']);
        $this->assertArrayNotHasKey('email', $first);

        $res->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.kpis.total_points', 1300)
            ->assertJsonPath('meta.kpis.members_with_points', 2)
            ->assertJsonPath('meta.kpis.scope_members', 3)
            ->assertJsonPath('meta.kpis.top_contributor_month.user_id', $sara->id);

        $this->members($hr, '?only_with_points=0')->assertOk()->assertJsonPath('meta.total', 3);
        $this->assertNotNull($nopoints);
    }

    public function test_hr_manager_can_open_a_members_history_including_the_internal_note(): void
    {
        $hr = $this->user('hr_manager');
        $member = $this->member($this->department());
        $this->seedPoints($member, 200, ['reason' => 'ورشة', 'awarded_by' => $hr->id, 'note' => 'ملاحظة داخلية']);

        $res = $this->actingAs($hr)->getJson(self::BASE."/users/{$member->id}/history")->assertOk();

        $entry = $res->json('data.entries.data.0');
        $this->assertSame('ورشة', $entry['reason']);
        $this->assertSame('ملاحظة داخلية', $entry['note'], 'staff reading someone else\'s history see the internal note');
        $this->assertSame($hr->id, $entry['awarder']['id']);
        $this->assertSame('award', $entry['kind']);
    }

    public function test_the_internal_note_never_reaches_the_recipient(): void
    {
        $hr = $this->user('hr_manager');
        $member = $this->member($this->department());
        $this->seedPoints($member, 300);

        $this->adjust($hr, $member, ['direction' => 'subtract', 'points' => 20, 'note' => 'سري للموارد البشرية'])->assertCreated();

        foreach ([self::BASE.'/my-awards', self::BASE."/users/{$member->id}/history"] as $url) {
            $body = $this->actingAs($member)->getJson($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('سري للموارد البشرية', $body, "$url leaked the internal note");
        }
    }

    // ── 6–8. Adjustments ─────────────────────────────────────────────────────

    public function test_hr_manager_adds_points_as_an_attributed_ledger_row(): void
    {
        $hr = $this->user('hr_manager');
        $member = $this->member($this->department());
        $this->seedPoints($member, 100);

        $res = $this->adjust($hr, $member, ['direction' => 'add', 'points' => 75, 'reason' => 'تعويض عن حضور غير مسجل', 'note' => 'راجعت المحضر'])
            ->assertCreated()
            ->assertJsonPath('data.total_before', 100)
            ->assertJsonPath('data.total_after', 175);

        $row = VolunteerPointAward::where('category', VolunteerPointAward::CATEGORY_ADJUSTMENT)->sole();
        $this->assertSame(75, $row->points);
        $this->assertSame($hr->id, $row->awarded_by);
        $this->assertSame('تعويض عن حضور غير مسجل', $row->reason);
        $this->assertSame('راجعت المحضر', $row->note);
        $this->assertSame($member->id, $row->user_id);
        $this->assertNotNull($row->awarded_at);
        $res->assertJsonPath('data.entry.kind', 'adjustment');

        $this->assertSame(175, $this->total($member));
        $this->assertSame(2, VolunteerPointAward::count(), 'the old row is untouched; the correction is a NEW row');
    }

    public function test_hr_manager_subtracts_points_with_a_reason(): void
    {
        $hr = $this->user('hr_manager');
        $member = $this->member($this->department());
        $this->seedPoints($member, 500);

        $this->adjust($hr, $member, ['direction' => 'subtract', 'points' => 120, 'reason' => 'منحة مكررة'])
            ->assertCreated()->assertJsonPath('data.total_after', 380);

        $this->assertSame(-120, VolunteerPointAward::where('category', 'adjustment')->value('points'));
        $this->assertSame(380, $this->total($member));
    }

    public function test_an_adjustment_without_a_reason_is_rejected_and_writes_nothing(): void
    {
        $hr = $this->user('hr_manager');
        $member = $this->member($this->department());

        foreach ([null, '', '   ', 'ab'] as $reason) {
            $body = ['direction' => 'add', 'points' => 10];
            if ($reason !== null) {
                $body['reason'] = $reason;
            }
            $this->actingAs($hr)->postJson(self::BASE."/users/{$member->id}/adjust", $body)
                ->assertStatus(422)->assertJsonValidationErrors('reason');
        }

        $this->assertSame(0, VolunteerPointAward::count());
    }

    public function test_adjustment_input_is_validated(): void
    {
        $hr = $this->user('hr_manager');
        $member = $this->member($this->department());

        foreach ([
            ['direction' => 'overwrite', 'points' => 10],
            ['direction' => 'add', 'points' => 0],
            ['direction' => 'add', 'points' => -5],
            ['direction' => 'add', 'points' => 5001],
            ['direction' => 'add', 'points' => 'abc'],
            ['points' => 10],
        ] as $bad) {
            $this->actingAs($hr)->postJson(self::BASE."/users/{$member->id}/adjust", $bad + ['reason' => 'سبب كاف'])->assertStatus(422);
        }
        $this->assertSame(0, VolunteerPointAward::count());
    }

    // ── 9–12. Who counts as a recipient ──────────────────────────────────────

    public function test_a_non_emc_user_cannot_be_adjusted(): void
    {
        $hr = $this->user('hr_manager');
        $student = $this->user('student');
        $instructor = $this->user('instructor');
        $outsider = $this->user('volunteer');

        foreach ([$student, $instructor, $outsider] as $nope) {
            $this->adjust($hr, $nope)->assertStatus(422)->assertJsonPath('code', 'RECIPIENT_NOT_ELIGIBLE');
        }
        $this->assertSame(0, VolunteerPointAward::count());
    }

    public function test_students_and_course_instructors_are_excluded_from_the_people_list(): void
    {
        $hr = $this->user('hr_manager');
        $dept = $this->department();
        $emc = $this->member($dept);
        $student = $this->user('student');
        $instructor = $this->user('instructor');
        $this->seedPoints($emc, 10);
        $this->seedPoints($student, 5000);
        $this->seedPoints($instructor, 5000);

        $ids = collect($this->members($hr, '?only_with_points=0')->json('data'))->pluck('user_id')->all();

        $this->assertSame([$emc->id], $ids);
    }

    public function test_an_emc_worker_or_volunteer_is_included_even_if_they_are_also_an_instructor_or_student(): void
    {
        $hr = $this->user('hr_manager');
        $dept = $this->department();
        $teachingVolunteer = $this->member($dept, 'instructor');
        $studentVolunteer = $this->member($dept, 'student');
        $this->seedPoints($teachingVolunteer, 10);
        $this->seedPoints($studentVolunteer, 10);

        $ids = collect($this->members($hr)->json('data'))->pluck('user_id')->all();

        $this->assertEqualsCanonicalizing([$teachingVolunteer->id, $studentVolunteer->id], $ids);
        $this->adjust($hr, $teachingVolunteer)->assertCreated();
    }

    public function test_a_deactivated_or_suspended_member_is_not_listed_or_adjustable(): void
    {
        $hr = $this->user('hr_manager');
        $dept = $this->department();
        $inactiveUser = $this->member($dept, 'volunteer', 'x', ['is_active' => false]);
        $suspended = $this->member($dept);
        TeamMember::where('user_id', $suspended->id)->update(['status' => 'inactive']);
        $this->seedPoints($inactiveUser, 10);
        $this->seedPoints($suspended, 10);

        $this->assertSame([], $this->members($hr)->json('data'));
        $this->adjust($hr, $suspended)->assertStatus(422);
        $this->adjust($hr, $inactiveUser)->assertStatus(422);
    }

    // ── 13. Self ─────────────────────────────────────────────────────────────

    public function test_self_adjustment_is_blocked_but_the_own_row_is_listed_for_viewing(): void
    {
        $hr = $this->user('hr_manager');
        $dept = $this->department();
        $profile = TeamProfile::create(['department_id' => $dept->id, 'user_id' => $hr->id, 'name_ar' => 'HR', 'position_ar' => 'مدير', 'is_active' => true]);
        TeamMember::create(['user_id' => $hr->id, 'team_profile_id' => $profile->id, 'department_id' => $dept->id, 'status' => 'active', 'role_title' => 'مدير']);
        $this->seedPoints($hr, 100);

        $this->adjust($hr, $hr)->assertStatus(422)->assertJsonPath('code', 'SELF_ADJUSTMENT');
        $this->assertSame(100, $this->total($hr));

        $row = collect($this->members($hr)->json('data'))->firstWhere('user_id', $hr->id);
        $this->assertTrue($row['is_self'], 'the actor\'s own row is flagged so the UI can disable editing it');
    }

    // ── 14. Direct API bypass ────────────────────────────────────────────────

    public function test_roles_without_the_business_permission_are_refused_on_every_management_endpoint(): void
    {
        $dept = $this->department();
        $target = $this->member($dept);
        $this->seedPoints($target, 100);

        foreach (['executive_admin', 'operations_manager', 'quality_manager', 'finance_manager', 'programs_manager', 'instructor', 'volunteer', 'ai_manager', 'support_agent'] as $role) {
            $user = $this->user($role);
            $this->members($user)->assertForbidden();
            $this->adjust($user, $target)->assertForbidden();
            $this->actingAs($user)->getJson(self::BASE."/users/{$target->id}/history")->assertForbidden();
        }

        $this->assertSame(100, $this->total($target));
        $this->assertSame(1, VolunteerPointAward::count());
    }

    public function test_page_access_alone_never_grants_management(): void
    {
        $user = $this->user('quality_manager');
        $this->actingAs($user)->getJson(self::BASE.'/summary')->assertOk()->assertJsonPath('data.capabilities.can_manage', false);
        $this->members($user)->assertForbidden();
    }

    public function test_unauthenticated_and_student_requests_are_rejected(): void
    {
        $target = $this->member($this->department());

        $this->getJson(self::BASE.'/members')->assertUnauthorized();
        $this->getJson(self::BASE."/users/{$target->id}/history")->assertUnauthorized();
        $this->postJson(self::BASE."/users/{$target->id}/adjust", [])->assertUnauthorized();

        $student = $this->user('student');
        $this->members($student)->assertForbidden();
        $this->adjust($student, $target)->assertForbidden();
    }

    public function test_an_unknown_user_is_a_404_not_a_leak(): void
    {
        $hr = $this->user('hr_manager');

        $this->actingAs($hr)->postJson(self::BASE.'/users/999999/adjust', ['direction' => 'add', 'points' => 5, 'reason' => 'سبب'])->assertNotFound();
        $this->actingAs($hr)->getJson(self::BASE.'/users/999999/history')->assertNotFound();
    }

    public function test_there_is_no_way_to_edit_or_delete_a_ledger_row(): void
    {
        $hr = $this->user('hr_manager');
        $member = $this->member($this->department());
        $row = $this->seedPoints($member, 50);

        foreach (['putJson', 'patchJson', 'deleteJson'] as $verb) {
            $this->actingAs($hr)->{$verb}(self::BASE."/awards/{$row->id}", ['points' => 9999])->assertNotFound();
            $this->actingAs($hr)->{$verb}(self::BASE."/users/{$member->id}/adjust", ['points' => 9999])->assertStatus(405);
        }
        $this->assertSame(50, $row->fresh()->points);
    }

    // ── 15. Capabilities from the server ─────────────────────────────────────

    public function test_capabilities_are_computed_by_the_server_per_role(): void
    {
        $expect = [
            'super_admin' => [true, 'organization'], 'tech_admin' => [true, 'organization'],
            'admin' => [true, 'organization'], 'hr_manager' => [true, 'organization'],
            'department_manager' => [true, 'department'],
            'executive_admin' => [false, 'none'], 'operations_manager' => [false, 'none'],
            'quality_manager' => [false, 'none'], 'finance_manager' => [false, 'none'],
            'programs_manager' => [false, 'none'], 'instructor' => [false, 'none'],
            'volunteer' => [false, 'none'], 'support_agent' => [false, 'none'],
        ];

        foreach ($expect as $role => [$manages, $scope]) {
            $caps = $this->actingAs($this->user($role))->getJson(self::BASE.'/summary')->assertOk()->json('data.capabilities');

            $this->assertSame($manages, $caps['can_manage'], "[$role] can_manage");
            $this->assertSame($manages, $caps['can_view_all'], "[$role] can_view_all");
            $this->assertSame($manages, $caps['can_award'], "[$role] can_award");
            $this->assertSame($manages, $caps['can_adjust'], "[$role] can_adjust");
            $this->assertSame($manages, $caps['can_view_history'], "[$role] can_view_history");
            $this->assertSame($scope, $caps['scope'], "[$role] scope");
        }
    }

    public function test_the_legacy_can_award_flag_still_matches_the_capability(): void
    {
        $this->actingAs($this->user('hr_manager'))->getJson(self::BASE.'/summary')->assertJsonPath('data.can_award', true);
        $this->actingAs($this->user('volunteer'))->getJson(self::BASE.'/summary')->assertJsonPath('data.can_award', false);
    }

    // ── 16. History records actor and reason ─────────────────────────────────

    public function test_history_distinguishes_awards_deductions_and_adjustments_with_actor_and_reason(): void
    {
        $hr = $this->user('hr_manager');
        $member = $this->member($this->department());

        $this->actingAs($hr)->postJson(self::BASE.'/awards', ['user_id' => $member->id, 'category' => 'task', 'points' => 100, 'reason' => 'مهمة'])->assertCreated();
        $this->actingAs($hr)->postJson(self::BASE.'/awards', ['user_id' => $member->id, 'category' => 'other', 'points' => -10, 'reason' => 'خصم'])->assertCreated();
        $this->adjust($hr, $member, ['reason' => 'تصحيح'])->assertCreated();

        $entries = collect($this->actingAs($hr)->getJson(self::BASE."/users/{$member->id}/history")->json('data.entries.data'));

        $this->assertEqualsCanonicalizing(['award', 'deduction', 'adjustment'], $entries->pluck('kind')->all());
        $this->assertSame([$hr->id], $entries->pluck('awarder.id')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(['مهمة', 'خصم', 'تصحيح'], $entries->pluck('reason')->all());
        $this->assertTrue($entries->every(fn ($e) => ! empty($e['awarded_at'])));
    }

    public function test_every_adjustment_is_audit_logged_with_before_and_after_totals(): void
    {
        $hr = $this->user('hr_manager');
        $member = $this->member($this->department());
        $this->seedPoints($member, 100);

        $this->adjust($hr, $member, ['direction' => 'subtract', 'points' => 30, 'reason' => 'سبب التدقيق', 'note' => 'x'])->assertCreated();

        $log = AuditLog::where('action', 'IMPACT_POINTS_ADJUSTED')->sole();

        $this->assertSame(['total_points' => 100], $log->old_values);
        $this->assertSame(['total_points' => 70], $log->new_values);
        $this->assertSame($hr->id, (int) $log->user_id, 'the audit row names the actor');
        $this->assertSame('VolunteerPointAward', $log->entity_type);
        $this->assertSame(VolunteerPointAward::where('category', 'adjustment')->value('id'), (int) $log->entity_id);

        $meta = json_encode($log->metadata, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('subtract', $meta);
        $this->assertStringContainsString('سبب التدقيق', $meta);
        $this->assertStringNotContainsString('"x"', $meta, 'the internal note text is not copied into the log');
    }

    // ── 17. Totals can never go negative / below what was redeemed ───────────

    public function test_a_deduction_larger_than_the_balance_is_rejected(): void
    {
        $hr = $this->user('hr_manager');
        $member = $this->member($this->department());
        $this->seedPoints($member, 100);

        $this->adjust($hr, $member, ['direction' => 'subtract', 'points' => 101])
            ->assertStatus(422)->assertJsonPath('code', 'NEGATIVE_BALANCE');
        $this->assertSame(100, $this->total($member));

        $this->adjust($hr, $member, ['direction' => 'subtract', 'points' => 100])->assertCreated();
        $this->assertSame(0, $this->total($member));
    }

    public function test_points_already_redeemed_cannot_be_deducted_away(): void
    {
        $hr = $this->user('hr_manager');
        $member = $this->member($this->department());
        $this->seedPoints($member, 1000);
        VolunteerPointRedemption::create(['user_id' => $member->id, 'points' => 600, 'purpose' => 'course', 'approved_by' => $hr->id]);

        $this->adjust($hr, $member, ['direction' => 'subtract', 'points' => 401])->assertStatus(422)->assertJsonPath('code', 'NEGATIVE_BALANCE');
        $this->adjust($hr, $member, ['direction' => 'subtract', 'points' => 400])->assertCreated();
    }

    public function test_an_ordinary_negative_award_follows_the_same_balance_rule(): void
    {
        $hr = $this->user('hr_manager');
        $member = $this->member($this->department());
        $this->seedPoints($member, 50);

        $this->actingAs($hr)->postJson(self::BASE.'/awards', ['user_id' => $member->id, 'category' => 'other', 'points' => -60, 'reason' => 'خصم'])
            ->assertStatus(422)->assertJsonPath('code', 'NEGATIVE_BALANCE');
    }

    public function test_a_positive_adjustment_respects_the_monthly_cap(): void
    {
        $hr = $this->user('hr_manager');
        $member = $this->member($this->department());
        $this->seedPoints($member, VolunteerPointsController::MONTHLY_CAP - 10);

        $this->adjust($hr, $member, ['direction' => 'add', 'points' => 11])->assertStatus(422)->assertJsonPath('code', 'MONTHLY_CAP_EXCEEDED');
        $this->adjust($hr, $member, ['direction' => 'add', 'points' => 10])->assertCreated();
    }

    // ── Scope (department leaders) and IDOR ──────────────────────────────────

    public function test_a_department_leader_sees_and_adjusts_only_their_own_departments_people(): void
    {
        $manager = $this->user('department_manager');
        $own = $this->department(['leader_id' => $manager->id]);
        $other = $this->department();
        $mine = $this->member($own);
        $theirs = $this->member($other);
        $this->seedPoints($mine, 10);
        $this->seedPoints($theirs, 10);

        $this->assertSame([$mine->id], collect($this->members($manager)->assertOk()->json('data'))->pluck('user_id')->all());
        $this->adjust($manager, $mine)->assertCreated();
        $this->adjust($manager, $theirs)->assertStatus(422)->assertJsonPath('code', 'RECIPIENT_NOT_ELIGIBLE');
    }

    public function test_a_department_leader_cannot_read_another_departments_history_by_id(): void
    {
        $manager = $this->user('department_manager');
        $this->department(['leader_id' => $manager->id]);
        $theirs = $this->member($this->department());
        $this->seedPoints($theirs, 700);

        $res = $this->actingAs($manager)->getJson(self::BASE."/users/{$theirs->id}/history")->assertForbidden();
        $this->assertStringNotContainsString('700', $res->getContent());
    }

    public function test_an_org_wide_manager_may_open_the_history_of_someone_who_left_the_team(): void
    {
        $hr = $this->user('hr_manager');
        $gone = $this->member($this->department());
        $this->seedPoints($gone, 90, ['reason' => 'قبل المغادرة']);
        TeamMember::where('user_id', $gone->id)->update(['status' => 'inactive']);

        $this->actingAs($hr)->getJson(self::BASE."/users/{$gone->id}/history")->assertOk()->assertJsonPath('data.entries.data.0.reason', 'قبل المغادرة');
        $this->adjust($hr, $gone)->assertStatus(422);
    }

    public function test_the_history_of_a_user_with_no_ledger_and_no_team_record_is_not_available(): void
    {
        $hr = $this->user('hr_manager');
        $stranger = $this->user('student');

        $this->actingAs($hr)->getJson(self::BASE."/users/{$stranger->id}/history")->assertForbidden();
    }

    // ── Filters, sorting, pagination ─────────────────────────────────────────

    public function test_search_department_level_sort_and_pagination(): void
    {
        $hr = $this->user('hr_manager');
        $a = $this->department(['name' => 'إدارة ألف', 'name_ar' => 'إدارة ألف']);
        $b = $this->department(['name' => 'إدارة باء', 'name_ar' => 'إدارة باء']);
        $ali = $this->member($a, 'volunteer', 'مدرب', ['name' => 'علي']);
        $omar = $this->member($b, 'volunteer', 'محلل بيانات', ['name' => 'عمر']);
        $nada = $this->member($b, 'volunteer', 'منسقة', ['name' => 'ندى']);
        $this->seedPoints($ali, 300);
        $this->seedPoints($omar, 100);
        $this->seedPoints($nada, 200);

        $ids = fn (string $q) => collect($this->members($hr, $q)->assertOk()->json('data'))->pluck('user_id')->all();

        $this->assertSame([$ali->id, $nada->id, $omar->id], $ids(''), 'default: most points first');
        $this->assertSame([$omar->id, $nada->id, $ali->id], $ids('?sort=points&direction=asc'));
        $this->assertSame([$omar->id], $ids('?search=محلل'), 'matches the title');
        $this->assertSame([$ali->id], $ids('?search=ألف'), 'matches the department');
        $this->assertSame([$ali->id], $ids('?search=علي'), 'matches the name');
        $this->assertEqualsCanonicalizing([$omar->id, $nada->id], $ids('?department_id='.$b->id));
        $this->assertEqualsCanonicalizing([$ali->id, $omar->id, $nada->id], $ids('?level=volunteer'));
        $this->assertSame([], $ids('?level=senior'));
        $byName = collect($this->members($hr, '?sort=name&direction=asc')->json('data'))->pluck('name')->all();
        $sorted = $byName;
        sort($sorted);
        $this->assertSame($sorted, $byName, 'name sort is ascending');

        $page = $this->members($hr, '?per_page=2&page=2')->assertOk();
        $this->assertSame([$omar->id], collect($page->json('data'))->pluck('user_id')->all());
        $page->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.current_page', 2);

        $this->assertSame(['إدارة ألف', 'إدارة باء'], collect($this->members($hr)->json('meta.filters.departments'))->pluck('name')->all());
    }

    public function test_search_with_no_match_is_an_empty_page_not_an_error(): void
    {
        $hr = $this->user('hr_manager');
        $this->seedPoints($this->member($this->department()), 10);

        $this->members($hr, '?search=لا-يوجد-أحد')->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);
    }

    public function test_the_list_input_is_validated(): void
    {
        $hr = $this->user('hr_manager');

        foreach (['?sort=password', '?direction=sideways', '?per_page=500', '?per_page=0', '?page=0', '?department_id=abc'] as $q) {
            $this->members($hr, $q)->assertStatus(422);
        }
    }

    public function test_an_empty_organization_returns_empty_kpis_not_an_error(): void
    {
        $this->members($this->user('hr_manager'))->assertOk()
            ->assertJsonPath('meta.kpis.total_points', 0)
            ->assertJsonPath('meta.kpis.top_contributor_month', null)
            ->assertJsonPath('data', []);
    }
}
