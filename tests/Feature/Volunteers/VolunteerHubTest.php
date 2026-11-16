<?php

namespace Tests\Feature\Volunteers;

use App\Models\Department;
use App\Models\TeamProfile;
use App\Models\User;
use App\Models\VolunteerPointAward;
use App\Models\VolunteerPointRedemption;
use App\Models\VolunteerProfile;
use App\Models\VolunteerRequest;
use App\Models\VolunteerStatusLog;
use App\Models\WeeklyDepartmentReport;
use App\Models\WeeklyReportRating;
use App\Services\VolunteerHubSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Operations Volunteer Hub: lists EVERY volunteer, derives the department, warns about volunteers without an account,
 * and its three detail endpoints (points ledger / status logs / weekly ratings) answer 200.
 */
class VolunteerHubTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'hr_manager'): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function dept(string $name): Department
    {
        return Department::create([
            'name' => $name, 'name_ar' => $name, 'slug' => 'd-' . md5($name), 'status' => 'active', 'is_active' => true, 'sort_order' => 1,
        ]);
    }

    private function hub(array $query = [], ?User $as = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as ?? $this->staff(), 'sanctum')->getJson('/api/operations/volunteers/hub' . ($query ? '?' . http_build_query($query) : ''));
    }

    private function volunteerTeamProfile(?User $user, ?Department $dept, array $extra = []): TeamProfile
    {
        return TeamProfile::create(array_merge([
            'user_id' => $user?->id, 'name_ar' => $user?->name ?? 'بلا حساب', 'position_ar' => 'متطوع', 'member_type' => 'volunteer',
            'department_id' => $dept?->id, 'is_active' => true, 'sort_order' => 1, 'source' => 'volunteer_request',
        ], $extra));
    }

    // ── completeness ─────────────────────────────────────────────────────────

    public function test_a_volunteer_with_only_a_team_profile_link_is_listed(): void
    {
        $dept = $this->dept('الإعلام');
        $user = User::factory()->create(['role' => 'department_member', 'name' => 'مها']);
        $this->volunteerTeamProfile($user, $dept);

        $this->assertSame(0, VolunteerProfile::count());

        $rows = collect($this->hub()->assertOk()->json('data.volunteers'));

        $this->assertCount(1, $rows);
        $this->assertSame($user->id, $rows[0]['user_id']);
        $this->assertSame('مها', $rows[0]['name']);
        $this->assertSame($dept->id, $rows[0]['department_id']);
        $this->assertSame('الإعلام', $rows[0]['department_name']);
        $this->assertSame('active', $rows[0]['status']);
        $this->assertSame(1, VolunteerProfile::count(), 'the missing profile was created');
    }

    public function test_every_source_of_a_volunteer_is_covered(): void
    {
        $dept = $this->dept('التقنية');

        $byRole = User::factory()->create(['role' => 'volunteer']);                                   // (i)
        $byTeam = User::factory()->create(['role' => 'department_member']);                           // (ii)
        $this->volunteerTeamProfile($byTeam, $dept);
        $byRequest = User::factory()->create(['role' => 'instructor']);                               // (iii) converted request, profile not typed volunteer
        $profile = TeamProfile::create(['user_id' => $byRequest->id, 'name_ar' => 'محول', 'position_ar' => 'منسق', 'member_type' => 'staff', 'department_id' => $dept->id, 'sort_order' => 2]);
        VolunteerRequest::create([
            'full_name' => 'محول', 'email' => 'c@x.com', 'phone' => '1', 'country' => 'SA', 'city' => 'R', 'gender' => 'male',
            'desired_department' => 'التقنية', 'experience_level' => 'x', 'availability' => 'x', 'motivation' => str_repeat('x', 50),
            'status' => 'converted_to_member', 'converted_member_id' => $profile->id, 'converted_to_member_at' => now(),
        ]);
        User::factory()->create(['role' => 'student']);                                                // not a volunteer
        $trashed = User::factory()->create(['role' => 'volunteer']);
        $trashed->delete();

        $ids = collect($this->hub()->assertOk()->json('data.volunteers'))->pluck('user_id')->sort()->values()->all();

        $this->assertSame(collect([$byRole->id, $byTeam->id, $byRequest->id])->sort()->values()->all(), $ids);
    }

    public function test_the_hub_does_not_cap_the_list(): void
    {
        $dept = $this->dept('التقنية');
        foreach (range(1, 130) as $i) {
            $this->volunteerTeamProfile(User::factory()->create(['role' => 'volunteer', 'name' => "متطوع {$i}"]), $dept);
        }

        $data = $this->hub()->assertOk()->json('data');

        $this->assertCount(130, $data['volunteers']);
        $this->assertSame(130, $data['summary']['total_volunteers']);
    }

    public function test_the_sync_is_idempotent(): void
    {
        $user = User::factory()->create(['role' => 'volunteer']);

        $this->hub()->assertOk();
        $this->hub()->assertOk();
        $this->hub()->assertOk();

        $this->assertSame(1, VolunteerProfile::where('user_id', $user->id)->count());
    }

    public function test_inactive_team_profiles_create_inactive_hub_rows_and_status_filters_work(): void
    {
        $dept = $this->dept('التقنية');
        $active   = User::factory()->create(['role' => 'volunteer', 'name' => 'نشط']);
        $inactive = User::factory()->create(['role' => 'department_member', 'name' => 'متوقف']);
        $leave    = User::factory()->create(['role' => 'volunteer', 'name' => 'إجازة']);
        $this->volunteerTeamProfile($inactive, $dept, ['is_active' => false]);
        $this->hub()->assertOk();
        VolunteerProfile::where('user_id', $leave->id)->update(['status' => 'on_leave']);

        $all = $this->hub()->assertOk()->json('data');
        $this->assertSame(['active' => 1, 'inactive' => 1, 'on_leave' => 1, 'withdrawn' => 0], $all['summary']['status_counts']);

        foreach (['active' => 'نشط', 'inactive' => 'متوقف', 'on_leave' => 'إجازة'] as $status => $name) {
            $rows = $this->hub(['status' => $status])->assertOk()->json('data.volunteers');
            $this->assertSame([$name], array_column($rows, 'name'), $status);
        }

        $this->assertSame(['متوقف'], array_column($this->hub(['search' => 'متوقف'])->json('data.volunteers'), 'name'));
        $this->assertSame(['متوقف'], array_column($this->hub(['department_id' => $dept->id, 'status' => 'inactive'])->json('data.volunteers'), 'name'));
    }

    // ── department fallback ──────────────────────────────────────────────────

    public function test_the_department_is_never_unspecified_when_it_can_be_derived(): void
    {
        $profileDept = $this->dept('من الملف');
        $teamDept    = $this->dept('من الفريق');
        $primaryDept = $this->dept('الأساسية');
        $textDept    = $this->dept('من النص');

        // 1. the hub profile already has a department
        $a = User::factory()->create(['role' => 'volunteer', 'name' => 'a']);
        VolunteerProfile::create(['user_id' => $a->id, 'department_id' => $profileDept->id, 'status' => 'active']);
        // 2. only the team profile has it
        $b = User::factory()->create(['role' => 'volunteer', 'name' => 'b']);
        $this->volunteerTeamProfile($b, $teamDept);
        // 3. only the primary membership
        $c = User::factory()->create(['role' => 'volunteer', 'name' => 'c']);
        $c->departments()->attach([$this->dept('ثانوية')->id => ['is_primary' => false], $primaryDept->id => ['is_primary' => true]]);
        // 4. only users.department text
        $d = User::factory()->create(['role' => 'volunteer', 'name' => 'd', 'department' => 'من النص']);
        // 5. nothing
        $e = User::factory()->create(['role' => 'volunteer', 'name' => 'e', 'department' => null]);

        $rows = collect($this->hub()->assertOk()->json('data.volunteers'))->keyBy('name');

        $this->assertSame('من الملف', $rows['a']['department_name']);
        $this->assertSame('من الفريق', $rows['b']['department_name']);
        $this->assertSame($teamDept->id, $rows['b']['department_id']);
        $this->assertSame('الأساسية', $rows['c']['department_name']);
        $this->assertSame('من النص', $rows['d']['department_name']);
        $this->assertSame($textDept->id, $rows['d']['department_id']);
        $this->assertSame('غير محدد', $rows['e']['department_name']);
        $this->assertNull($rows['e']['department_id']);
    }

    public function test_the_fallback_chain_order_team_profile_then_primary_then_text(): void
    {
        $team = $this->dept('الفريق');
        $primary = $this->dept('الأساسية');
        $text = $this->dept('النص');

        $u = User::factory()->create(['department' => 'النص']);
        $u->departments()->attach($primary->id, ['is_primary' => true]);
        $this->volunteerTeamProfile($u, $team);
        $v = User::factory()->create(['department' => 'النص']);
        $v->departments()->attach($primary->id, ['is_primary' => true]);
        $w = User::factory()->create(['department' => 'النص']);

        $map = app(VolunteerHubSyncService::class)->derivedDepartments([$u->id, $v->id, $w->id]);

        $this->assertSame($team->id, $map[$u->id]->id);
        $this->assertSame($primary->id, $map[$v->id]->id);
        $this->assertSame($text->id, $map[$w->id]->id);
    }

    public function test_a_hub_row_without_a_department_is_filled_from_the_chain_when_it_is_synced(): void
    {
        $dept = $this->dept('الإعلام');
        $user = User::factory()->create(['role' => 'volunteer']);
        $this->volunteerTeamProfile($user, $dept);
        VolunteerProfile::create(['user_id' => $user->id, 'department_id' => null, 'status' => 'active']);

        $this->hub()->assertOk();

        $this->assertSame($dept->id, VolunteerProfile::where('user_id', $user->id)->value('department_id'));
    }

    // ── without_account ──────────────────────────────────────────────────────

    public function test_summary_counts_active_volunteer_team_profiles_without_an_account(): void
    {
        $dept = $this->dept('التقنية');
        $this->volunteerTeamProfile(null, $dept);
        $this->volunteerTeamProfile(null, $dept);
        $this->volunteerTeamProfile(null, $dept, ['is_active' => false]);                              // inactive: not counted
        $this->volunteerTeamProfile(User::factory()->create(), $dept);                                  // has an account: not counted
        TeamProfile::create(['name_ar' => 'موظف', 'position_ar' => 'منسق', 'member_type' => 'staff', 'sort_order' => 1]);        // not a volunteer: not counted

        $this->assertSame(2, $this->hub()->assertOk()->json('data.summary.without_account'));
        $this->assertSame(2, $this->hub(['search' => 'لا يوجد أحد بهذا الاسم'])->assertOk()->json('data.summary.without_account'), 'also on the empty response');
    }

    public function test_existing_response_keys_are_kept(): void
    {
        $this->volunteerTeamProfile(User::factory()->create(), $this->dept('التقنية'));

        $data = $this->hub()->assertOk()->json('data');

        $this->assertEqualsCanonicalizing(['volunteers', 'departments', 'summary'], array_keys($data));
        foreach (['total_volunteers', 'total_actual_points', 'total_used_points', 'total_balance', 'status_counts', 'without_account'] as $k) {
            $this->assertArrayHasKey($k, $data['summary']);
        }
        foreach (['id', 'user_id', 'name', 'email', 'avatar', 'department_id', 'department_name', 'is_leader', 'status', 'hours_logged', 'actual_points', 'used_points', 'balance', 'last_weekly_rating', 'created_at'] as $k) {
            $this->assertArrayHasKey($k, $data['volunteers'][0]);
        }
    }

    public function test_only_staff_roles_may_open_the_hub(): void
    {
        foreach (['student', 'instructor', 'department_member', 'volunteer'] as $role) {
            $this->hub([], $this->staff($role))->assertStatus(403);
        }
        foreach (['hr_manager', 'admin', 'super_admin', 'executive_admin', 'tech_admin'] as $role) {
            $this->hub([], $this->staff($role))->assertOk();
        }
    }

    // ── concurrency + command ────────────────────────────────────────────────

    public function test_a_concurrent_sync_is_skipped_not_duplicated(): void
    {
        User::factory()->create(['role' => 'volunteer']);
        $lock = Cache::lock('volunteers:sync-hub-profiles', 60);
        $this->assertTrue($lock->get());

        $result = app(VolunteerHubSyncService::class)->ensureProfiles();
        $this->assertTrue($result['skipped_locked']);
        $this->assertSame(0, VolunteerProfile::count());
        $this->hub()->assertOk();                                                                       // the hub still answers while another sync runs

        $lock->release();
        $this->assertSame(1, app(VolunteerHubSyncService::class)->ensureProfiles()['created']);
        $this->assertSame(0, app(VolunteerHubSyncService::class)->ensureProfiles()['created']);
        $this->assertSame(1, VolunteerProfile::count());
    }

    public function test_the_sync_command_dry_run_writes_nothing_and_the_real_run_is_idempotent(): void
    {
        $dept = $this->dept('التقنية');
        User::factory()->create(['role' => 'volunteer']);
        $viaTeam = User::factory()->create(['role' => 'department_member']);
        $this->volunteerTeamProfile($viaTeam, $dept);
        $this->volunteerTeamProfile(null, $dept);

        $this->assertSame(0, Artisan::call('volunteers:sync-hub-profiles', ['--dry-run' => true]));
        $out = Artisan::output();
        $this->assertStringContainsString('DRY RUN', $out);
        $this->assertStringContainsString('would be created', $out);
        $this->assertSame(0, VolunteerProfile::count());

        $this->assertSame(0, Artisan::call('volunteers:sync-hub-profiles'));
        $this->assertSame(2, VolunteerProfile::count());
        $this->assertSame($dept->id, VolunteerProfile::where('user_id', $viaTeam->id)->value('department_id'));

        $before = VolunteerProfile::count();
        Artisan::call('volunteers:sync-hub-profiles');
        $this->assertSame($before, VolunteerProfile::count());
        $this->assertSame(0, app(VolunteerHubSyncService::class)->ensureProfiles(true)['created']);
    }

    // ── detail endpoints (points-ledger used to 500: awardedBy vs awarder) ──────

    private function profileWithLedger(): array
    {
        $volunteer = User::factory()->create(['role' => 'volunteer', 'name' => 'متطوع']);
        $profile   = VolunteerProfile::create(['user_id' => $volunteer->id, 'status' => 'active']);
        $awarder   = User::factory()->create(['role' => 'hr_manager', 'name' => 'مانح']);

        $award = fn (array $a) => VolunteerPointAward::create(array_merge(['user_id' => $volunteer->id, 'awarded_at' => now()], $a));
        $award(['category' => 'task', 'points' => 50, 'reason' => 'مهمة', 'awarded_by' => $awarder->id, 'source_type' => 'App\\Models\\Task', 'source_id' => 7]);
        $award(['category' => 'meetings', 'points' => -10, 'reason' => 'غياب', 'awarded_by' => $awarder->id]);
        $award(['category' => VolunteerPointAward::CATEGORY_ADJUSTMENT, 'points' => 5, 'reason' => 'تصحيح', 'note' => 'ملاحظة داخلية', 'awarded_by' => null]);
        VolunteerPointRedemption::create(['user_id' => $volunteer->id, 'points' => 20, 'purpose' => 'دورة', 'approved_by' => $awarder->id]);

        return [$profile, $volunteer];
    }

    public function test_points_ledger_returns_200_with_the_ledger_shape(): void
    {
        [$profile] = $this->profileWithLedger();

        $this->app['auth']->forgetGuards();
        $res = $this->actingAs($this->staff(), 'sanctum')->getJson("/api/operations/volunteers/{$profile->id}/points-ledger")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.actual_points', 55)      // 50 + 5
            ->assertJsonPath('data.used_points', 30)        // 10 deduction + 20 redeemed
            ->assertJsonPath('data.balance', 25);

        $ledger = collect($res->json('data.ledger'));
        $this->assertCount(4, $ledger);
        foreach ($ledger as $row) {
            foreach (['id', 'date', 'category', 'reason', 'points', 'type', 'source', 'creator'] as $k) {
                $this->assertArrayHasKey($k, $row);
            }
        }

        $byReason = $ledger->keyBy('reason');
        $this->assertSame(['earn', 50, 'مانح', 'Task'], [$byReason['مهمة']['type'], $byReason['مهمة']['points'], $byReason['مهمة']['creator'], $byReason['مهمة']['source']]);
        $this->assertSame(['deduction', -10], [$byReason['غياب']['type'], $byReason['غياب']['points']]);
        $this->assertSame(['earn', 5, 'النظام'], [$byReason['تصحيح']['type'], $byReason['تصحيح']['points'], $byReason['تصحيح']['creator']], 'an adjustment without an awarder shows as the system');
        $this->assertSame(['redemption', -20, 'مانح'], [$byReason['دورة']['type'], $byReason['دورة']['points'], $byReason['دورة']['creator']]);
        $this->assertStringNotContainsString('ملاحظة داخلية', $res->getContent(), 'the internal note is not part of this ledger');
    }

    public function test_points_ledger_of_a_volunteer_with_no_movements_is_200(): void
    {
        $profile = VolunteerProfile::create(['user_id' => User::factory()->create(['role' => 'volunteer'])->id, 'status' => 'active']);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->staff(), 'sanctum')->getJson("/api/operations/volunteers/{$profile->id}/points-ledger")
            ->assertOk()->assertJsonPath('data.ledger', [])->assertJsonPath('data.balance', 0);
    }

    public function test_status_logs_smoke(): void
    {
        $hr = $this->staff();
        $profile = VolunteerProfile::create(['user_id' => User::factory()->create(['role' => 'volunteer'])->id, 'status' => 'active']);

        $this->app['auth']->forgetGuards();
        $this->actingAs($hr, 'sanctum')->getJson("/api/operations/volunteers/{$profile->id}/status-logs")->assertOk()->assertJsonPath('data', []);

        $this->app['auth']->forgetGuards();
        $this->actingAs($hr, 'sanctum')->putJson("/api/operations/volunteers/{$profile->id}/status", ['status' => 'on_leave', 'reason' => 'سفر طويل'])->assertOk();

        $this->app['auth']->forgetGuards();
        $res = $this->actingAs($hr, 'sanctum')->getJson("/api/operations/volunteers/{$profile->id}/status-logs")->assertOk();
        $this->assertCount(1, $res->json('data'));
        foreach (['id', 'old_status', 'new_status', 'reason', 'changed_by_name', 'changed_by_id', 'created_at', 'date_formatted'] as $k) {
            $this->assertArrayHasKey($k, $res->json('data.0'));
        }
        $this->assertSame($hr->name, $res->json('data.0.changed_by_name'));
        $this->assertSame(1, VolunteerStatusLog::count());
    }

    public function test_weekly_ratings_smoke(): void
    {
        $hr = $this->staff();
        $dept = $this->dept('التقنية');
        $volunteer = User::factory()->create(['role' => 'volunteer']);
        $profile = VolunteerProfile::create(['user_id' => $volunteer->id, 'status' => 'active']);

        // none yet
        $this->app['auth']->forgetGuards();
        $this->actingAs($hr, 'sanctum')->getJson("/api/operations/volunteers/{$profile->id}/weekly-ratings")
            ->assertOk()->assertJsonPath('data.ratings', [])->assertJsonPath('data.overall_avg', null);

        foreach (['2026-11-02' => 8.0, '2026-11-09' => 6.0] as $week => $avg) {
            $report = WeeklyDepartmentReport::create([
                'department_id' => $dept->id, 'week_start' => $week, 'achievements' => 'x', 'planned' => 'y',
                'submitted_by' => $hr->id, 'submitted_at' => now(),
            ]);
            WeeklyReportRating::create(['weekly_department_report_id' => $report->id, 'user_id' => $volunteer->id, 'scores' => ['quality' => $avg, 'speed' => $avg], 'avg_score' => $avg, 'note' => 'ok']);
        }

        $this->app['auth']->forgetGuards();
        $res = $this->actingAs($hr, 'sanctum')->getJson("/api/operations/volunteers/{$profile->id}/weekly-ratings")->assertOk();

        $this->assertCount(2, $res->json('data.ratings'));
        $this->assertSame(7.0, (float) $res->json('data.overall_avg'));
        $this->assertSame('2026-11-09', $res->json('data.ratings.0.date'), 'newest first');
        $this->assertSame('التقنية', $res->json('data.ratings.0.department_name'));
        $this->assertSame(7.0, (float) $res->json('data.criteria_averages.quality'));
        $this->assertCount(1, $res->json('data.monthly_averages'));
        foreach (['month_key', 'month_label', 'avg_score', 'count'] as $k) {
            $this->assertArrayHasKey($k, $res->json('data.monthly_averages.0'));
        }

        // the hub row shows the latest rating
        $this->assertSame(6.0, (float) collect($this->hub()->json('data.volunteers'))->firstWhere('user_id', $volunteer->id)['last_weekly_rating']);
    }

    // ── round 2: throttle, display-only free text, query shape ───────────────

    public function test_the_hub_syncs_at_most_once_a_minute_but_the_command_never_waits(): void
    {
        User::factory()->create(['role' => 'volunteer', 'name' => 'الأول']);
        $this->assertCount(1, $this->hub()->json('data.volunteers'));

        User::factory()->create(['role' => 'volunteer', 'name' => 'الثاني']);
        $this->assertCount(1, $this->hub()->json('data.volunteers'), 'within the minute the hub does not sync again');

        $this->travel(61)->seconds();
        $this->assertCount(2, $this->hub()->json('data.volunteers'), 'after the minute it does');

        User::factory()->create(['role' => 'volunteer', 'name' => 'الثالث']);
        $this->assertCount(2, $this->hub()->json('data.volunteers'));
        Artisan::call('volunteers:sync-hub-profiles');                                                // unthrottled
        $this->assertCount(3, $this->hub()->json('data.volunteers'));
    }

    public function test_a_hub_call_that_found_the_sync_busy_does_not_start_the_minute(): void
    {
        User::factory()->create(['role' => 'volunteer']);
        $lock = Cache::lock(VolunteerHubSyncService::LOCK_KEY, 60);
        $lock->get();

        $this->assertTrue(app(VolunteerHubSyncService::class)->ensureProfilesThrottled()['skipped_locked']);
        $this->assertFalse(Cache::has(VolunteerHubSyncService::HUB_THROTTLE_KEY), 'a skipped run leaves no throttle key behind');

        $lock->release();
        $this->assertSame(1, app(VolunteerHubSyncService::class)->ensureProfilesThrottled()['created']);
        $this->assertNull(app(VolunteerHubSyncService::class)->ensureProfilesThrottled(), 'and now it is throttled');
    }

    public function test_the_free_text_department_is_shown_but_never_written(): void
    {
        $textDept = $this->dept('من النص');
        $teamDept = $this->dept('من الفريق');
        $textOnly = User::factory()->create(['role' => 'volunteer', 'name' => 'نصي', 'department' => 'من النص']);
        $viaTeam  = User::factory()->create(['role' => 'volunteer', 'name' => 'فريقي']);
        $this->volunteerTeamProfile($viaTeam, $teamDept);
        $blankRow = User::factory()->create(['role' => 'volunteer', 'name' => 'فارغ', 'department' => 'من النص']);
        VolunteerProfile::create(['user_id' => $blankRow->id, 'department_id' => null, 'status' => 'active']);

        $rows = collect($this->hub()->assertOk()->json('data.volunteers'))->keyBy('name');

        // display: all three show a department ...
        $this->assertSame('من النص', $rows['نصي']['department_name']);
        $this->assertSame($textDept->id, $rows['نصي']['department_id']);
        $this->assertSame('من الفريق', $rows['فريقي']['department_name']);
        $this->assertSame('من النص', $rows['فارغ']['department_name']);
        // ... but only the reliable source (the team profile) was persisted
        $this->assertNull(VolunteerProfile::where('user_id', $textOnly->id)->value('department_id'));
        $this->assertNull(VolunteerProfile::where('user_id', $blankRow->id)->value('department_id'));
        $this->assertSame($teamDept->id, VolunteerProfile::where('user_id', $viaTeam->id)->value('department_id'));
    }

    public function test_dry_run_reports_how_many_departments_it_would_backfill_and_writes_nothing(): void
    {
        $dept = $this->dept('التقنية');
        $withTeam = User::factory()->create(['role' => 'volunteer']);
        $this->volunteerTeamProfile($withTeam, $dept);
        VolunteerProfile::create(['user_id' => $withTeam->id, 'department_id' => null, 'status' => 'active']);
        $textOnly = User::factory()->create(['role' => 'volunteer', 'department' => 'التقنية']);
        VolunteerProfile::create(['user_id' => $textOnly->id, 'department_id' => null, 'status' => 'active']);

        $this->assertSame(0, Artisan::call('volunteers:sync-hub-profiles', ['--dry-run' => true]));
        $out = Artisan::output();

        $this->assertMatchesRegularExpression('/would be filled on existing profiles\s*\|\s*1\s*\|/', $out, 'only the reliable source is counted');
        $this->assertNull(VolunteerProfile::where('user_id', $withTeam->id)->value('department_id'));
    }

    public function test_the_sync_looks_up_missing_volunteers_in_sql_and_derives_departments_once(): void
    {
        $dept = $this->dept('التقنية');
        foreach (range(1, 40) as $i) {
            $this->volunteerTeamProfile(User::factory()->create(['role' => 'volunteer']), $dept);
        }
        Artisan::call('volunteers:sync-hub-profiles');                                               // everything complete now
        User::factory()->create(['role' => 'volunteer', 'name' => 'جديد']);

        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $this->hub()->assertOk();

        $count = fn (string $table) => count(array_filter($queries, fn ($sql) => str_contains($sql, '"' . $table . '"') && str_starts_with(strtolower($sql), 'select')));

        $this->assertSame(1, $count('user_departments'), 'the departments are derived once, not again by the controller');
        $this->assertLessThan(40, count($queries), 'no per-volunteer queries from the sync (' . count($queries) . ' queries in all)');
        $this->assertSame(1, VolunteerProfile::whereHas('user', fn ($q) => $q->where('name', 'جديد'))->count());
    }
}
