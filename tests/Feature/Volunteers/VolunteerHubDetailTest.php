<?php

namespace Tests\Feature\Volunteers;

use App\Models\ActivityLog;
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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Operations Volunteer Hub drawer: the personal-data endpoint (/profile) and the real activity timeline (/activity).
 */
class VolunteerHubDetailTest extends TestCase
{
    use RefreshDatabase;

    /** Who may read the personal data of a volunteer (same audience as admin/volunteer-requests/{id}). */
    private const PROFILE_ROLES = ['hr_manager', 'admin', 'super_admin', 'tech_admin'];

    private function staff(string $role = 'hr_manager'): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function dept(string $name = 'الإعلام'): Department
    {
        return Department::create([
            'name' => $name, 'name_ar' => $name, 'slug' => 'd-' . md5($name), 'status' => 'active', 'is_active' => true, 'sort_order' => 1,
        ]);
    }

    private function fetch(string $path, ?User $as = null)
    {
        $this->app['auth']->forgetGuards();

        return $as === null ? $this->getJson($path) : $this->actingAs($as, 'sanctum')->getJson($path);
    }

    private function profileUrl(VolunteerProfile $p): string
    {
        return "/api/operations/volunteers/{$p->id}/profile";
    }

    private function activityUrl(VolunteerProfile $p): string
    {
        return "/api/operations/volunteers/{$p->id}/activity";
    }

    private function newRequest(array $over = []): VolunteerRequest
    {
        return VolunteerRequest::create(array_merge([
            'full_name' => 'سارة أحمد', 'email' => 'sara@example.com', 'phone' => '+31600000000', 'country' => 'NL', 'city' => 'Amsterdam',
            'gender' => 'female', 'desired_department' => 'الإعلام', 'status' => 'pending',
            'languages' => ['العربية', 'English'], 'linkedin_url' => 'https://www.linkedin.com/in/sara', 'professional_bio' => 'نبذة',
        ], $over));
    }

    /** A converted volunteer the way VolunteerConversionService leaves it. */
    private function converted(array $userOver = [], array $requestOver = [], bool $stampMemberId = true, bool $stampSourceId = true): array
    {
        $dept = $this->dept();
        $user = User::factory()->create(array_merge(['role' => 'department_member', 'email' => 'sara@example.com', 'name' => 'سارة أحمد', 'must_change_password' => true], $userOver));
        $req = $this->newRequest(array_merge(['status' => 'converted_to_member'], $requestOver));
        $team = TeamProfile::create([
            'user_id' => $user->id, 'department_id' => $dept->id, 'name_ar' => 'سارة أحمد', 'position_ar' => 'متطوعة', 'member_type' => 'volunteer',
            'is_active' => true, 'sort_order' => 1, 'source' => 'volunteer_request', 'source_id' => $stampSourceId ? $req->id : null,
            'phone' => '+31600000000', 'city' => 'Amsterdam', 'country' => 'NL', 'gender' => 'female', 'joined_at' => now(),
        ]);
        $converter = $this->staff();
        $req->forceFill([
            'converted_member_id' => $stampMemberId ? $team->id : null, 'converted_to_member_at' => now(),
            'accepted_at' => now()->subDay(), 'accepted_by' => $this->staff()->id, 'converted_by' => $converter->id,
        ])->save();
        $profile = VolunteerProfile::create(['user_id' => $user->id, 'department_id' => $dept->id, 'status' => 'active', 'hours_logged' => 4]);

        return [$profile, $user, $req, $team, $dept];
    }

    // ── access ───────────────────────────────────────────────────────────────

    public function test_both_endpoints_require_authentication(): void
    {
        [$profile] = $this->converted();

        $this->fetch($this->profileUrl($profile))->assertStatus(401);
        $this->fetch($this->activityUrl($profile))->assertStatus(401);
    }

    public function test_the_personal_data_endpoint_is_limited_to_the_request_review_audience(): void
    {
        [$profile] = $this->converted();

        foreach (['student', 'department_member', 'instructor', 'volunteer', 'operations_manager', 'executive_admin'] as $role) {
            $this->fetch($this->profileUrl($profile), $this->staff($role))->assertStatus(403)->assertJsonPath('success', false);
        }
        foreach (self::PROFILE_ROLES as $role) {
            $this->fetch($this->profileUrl($profile), $this->staff($role))->assertOk();
        }
    }

    public function test_the_activity_endpoint_keeps_the_operations_audience_including_executive_admin(): void
    {
        [$profile] = $this->converted();

        foreach (['student', 'department_member', 'instructor', 'volunteer', 'operations_manager'] as $role) {
            $this->fetch($this->activityUrl($profile), $this->staff($role))->assertStatus(403);
        }
        foreach (['hr_manager', 'admin', 'super_admin', 'executive_admin', 'tech_admin'] as $role) {
            $this->fetch($this->activityUrl($profile), $this->staff($role))->assertOk();
        }
    }

    public function test_an_executive_admin_is_refused_the_profile_but_keeps_the_hub_and_the_timeline(): void
    {
        [$profile] = $this->converted();
        $exec = $this->staff('executive_admin');

        $this->fetch($this->profileUrl($profile), $exec)->assertStatus(403);
        $this->fetch($this->activityUrl($profile), $exec)->assertOk();
        $this->fetch('/api/operations/volunteers/hub', $exec)->assertOk();
    }

    public function test_both_endpoints_are_throttled_to_60_a_minute(): void
    {
        [$profile] = $this->converted();
        $hr = $this->staff();

        foreach (range(1, 60) as $i) {
            $this->fetch($this->profileUrl($profile), $hr)->assertOk();
        }
        $this->fetch($this->profileUrl($profile), $hr)->assertStatus(429);

        // `throttle:60,1` keeps one bucket per user across the routes that use it, so the timeline is cut off too; both routes carry it
        $this->fetch($this->activityUrl($profile), $hr)->assertStatus(429);
        foreach ([$this->profileUrl($profile), $this->activityUrl($profile)] as $url) {
            $this->assertContains('throttle:60,1', app('router')->getRoutes()->match(\Illuminate\Http\Request::create($url, 'GET'))->gatherMiddleware());
        }
    }

    // ── /profile: audit ──────────────────────────────────────────────────────

    public function test_every_profile_read_is_audited_without_personal_values(): void
    {
        [$profile, $user, $req] = $this->converted();
        $hr = $this->staff();

        $this->fetch($this->profileUrl($profile), $hr)->assertOk();
        $this->fetch($this->profileUrl($profile), $hr)->assertOk();
        $this->fetch($this->activityUrl($profile), $hr)->assertOk(); // the timeline is not "personal data": not audited here

        $logs = ActivityLog::where('action', 'volunteer.profile_viewed')->get();
        $this->assertCount(2, $logs);

        $log = $logs->first();
        $this->assertSame($hr->id, $log->user_id);
        $this->assertSame(VolunteerProfile::class, $log->entity_type);
        $this->assertSame($profile->id, (int) $log->entity_id);
        $this->assertSame(['volunteer_profile_id' => $profile->id, 'source' => 'volunteer_request', 'match' => 'team_source'], $log->new_values);

        $dump = json_encode($log->toArray(), JSON_UNESCAPED_UNICODE);
        foreach ([$user->email, $user->name, $req->phone, '+31600000000'] as $pii) {
            $this->assertStringNotContainsString($pii, $dump, 'the audit row holds ids and labels, never personal values');
        }
    }

    // ── /profile: payload and linkage ────────────────────────────────────────

    public function test_profile_links_the_request_through_the_team_profile_source_id(): void
    {
        [$profile, $user, $req, $team, $dept] = $this->converted(stampMemberId: false);

        $res = $this->fetch($this->profileUrl($profile), $this->staff())->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.source', 'volunteer_request')
            ->assertJsonPath('data.match', 'team_source')
            ->assertJsonPath('data.request.id', $req->id)
            ->assertJsonPath('data.request.languages', ['العربية', 'English'])
            ->assertJsonPath('data.request.linkedin_url', 'https://www.linkedin.com/in/sara')
            ->assertJsonPath('data.volunteer.id', $profile->id)
            ->assertJsonPath('data.volunteer.user_id', $user->id)
            ->assertJsonPath('data.volunteer.name', 'سارة أحمد')
            ->assertJsonPath('data.volunteer.email', 'sara@example.com')
            ->assertJsonPath('data.volunteer.phone', '+31600000000')
            ->assertJsonPath('data.volunteer.department_id', $dept->id)
            ->assertJsonPath('data.volunteer.department_name', 'الإعلام')
            ->assertJsonPath('data.volunteer.hours_logged', 4)
            ->assertJsonPath('data.volunteer.account.has_account', true)
            ->assertJsonPath('data.volunteer.account.must_change_password', true)
            ->assertJsonPath('data.team_profile.id', $team->id)
            ->assertJsonPath('data.team_profile.position_ar', 'متطوعة')
            ->assertJsonPath('data.team_profile.member_type', 'volunteer')
            ->assertJsonPath('data.team_profile.city', 'Amsterdam');

        $this->assertArrayHasKey('email_verified', $res->json('data.volunteer.account'));
    }

    public function test_profile_links_the_request_through_converted_member_id_when_source_id_is_missing(): void
    {
        [$profile, , $req] = $this->converted(stampSourceId: false);

        $this->fetch($this->profileUrl($profile), $this->staff())->assertOk()
            ->assertJsonPath('data.source', 'volunteer_request')
            ->assertJsonPath('data.match', 'converted_member')
            ->assertJsonPath('data.request.id', $req->id);
    }

    public function test_the_email_fallback_never_attaches_a_request_nobody_decided_on(): void
    {
        $victim  = User::factory()->create(['role' => 'volunteer', 'email' => 'Victim@Example.com']);
        $profile = VolunteerProfile::create(['user_id' => $victim->id, 'status' => 'active']);

        // anybody can post the public form with somebody else's address
        foreach (['pending', 'reviewing', 'reviewed', 'rejected', 'contacted'] as $status) {
            $this->newRequest(['email' => 'victim@example.com', 'status' => $status, 'phone' => '+0-attacker', 'full_name' => 'مهاجم']);
        }

        $body = $this->fetch($this->profileUrl($profile), $this->staff())->assertOk()
            ->assertJsonPath('data.request', null)
            ->assertJsonPath('data.match', null)
            ->assertJsonPath('data.source', 'account_only')
            ->getContent();
        $this->assertStringNotContainsString('مهاجم', $body);
        $this->assertStringNotContainsString('+0-attacker', $body);

        $types = array_column($this->fetch($this->activityUrl($profile), $this->staff())->assertOk()->json('data'), 'type');
        $this->assertSame(['profile_created'], $types);
    }

    public function test_an_accepted_request_is_attached_by_email_and_marked_as_such(): void
    {
        $user    = User::factory()->create(['role' => 'volunteer', 'email' => 'Sara@Example.com']);
        $profile = VolunteerProfile::create(['user_id' => $user->id, 'status' => 'active']);

        $this->newRequest(['email' => 'sara@example.com', 'status' => 'pending']);
        $accepted = $this->newRequest(['email' => 'SARA@example.COM', 'status' => 'accepted', 'phone' => '+31611111111', 'accepted_at' => now()]);
        $this->newRequest(['email' => 'other@example.com', 'status' => 'converted_to_member']);

        $this->fetch($this->profileUrl($profile), $this->staff())->assertOk()
            ->assertJsonPath('data.source', 'volunteer_request')
            ->assertJsonPath('data.match', 'email')
            ->assertJsonPath('data.request.id', $accepted->id)
            ->assertJsonPath('data.volunteer.phone', null); // an e-mail match is not certain: its phone is not borrowed

        $events = collect($this->fetch($this->activityUrl($profile), $this->staff())->assertOk()->json('data'))->keyBy('type');
        $this->assertSame('email', $events['request_submitted']['meta']['match']);
        $this->assertSame('email', $events['request_accepted']['meta']['match']);
    }

    public function test_the_email_fallback_prefers_a_converted_request_over_a_newer_accepted_one(): void
    {
        $user    = User::factory()->create(['role' => 'volunteer', 'email' => 'sara@example.com']);
        $profile = VolunteerProfile::create(['user_id' => $user->id, 'status' => 'active']);

        $converted = $this->newRequest(['email' => 'sara@EXAMPLE.com', 'status' => 'converted_to_member']);
        $this->newRequest(['email' => 'sara@example.com', 'status' => 'accepted']); // newer, but not converted

        $this->fetch($this->profileUrl($profile), $this->staff())->assertOk()
            ->assertJsonPath('data.match', 'email')
            ->assertJsonPath('data.request.id', $converted->id);
    }

    public function test_a_request_converted_to_another_profile_is_never_linked_by_email(): void
    {
        // The same address, but the request was converted into somebody else's team profile.
        $owner    = User::factory()->create(['role' => 'department_member', 'email' => 'owner@example.com']);
        $ownerTeam = TeamProfile::create(['user_id' => $owner->id, 'name_ar' => 'مالك', 'position_ar' => 'x', 'member_type' => 'volunteer', 'is_active' => true, 'sort_order' => 1]);
        $other = $this->newRequest(['email' => 'shared@example.com', 'status' => 'converted_to_member']);
        $other->forceFill(['converted_member_id' => $ownerTeam->id, 'converted_to_member_at' => now()])->save();

        $user    = User::factory()->create(['role' => 'volunteer', 'email' => 'shared@example.com']);
        $profile = VolunteerProfile::create(['user_id' => $user->id, 'status' => 'active']);

        $this->fetch($this->profileUrl($profile), $this->staff())->assertOk()
            ->assertJsonPath('data.request', null)
            ->assertJsonPath('data.match', null);

        // ... whereas a converted request that points at THIS account's own profile still links (here: no source link, e-mail only)
        $mine = TeamProfile::create(['user_id' => $user->id, 'name_ar' => 'أنا', 'position_ar' => 'x', 'member_type' => 'staff', 'is_active' => true, 'sort_order' => 2]);
        $other->forceFill(['converted_member_id' => $mine->id])->save();

        $this->fetch($this->profileUrl($profile), $this->staff())->assertOk()
            ->assertJsonPath('data.match', 'email')
            ->assertJsonPath('data.request.id', $other->id);
    }

    public function test_profile_without_any_request_reports_a_null_request_and_the_team_profile_source(): void
    {
        $user = User::factory()->create(['role' => 'department_member', 'email' => 'lone@example.com']);
        TeamProfile::create(['user_id' => $user->id, 'name_ar' => 'منفرد', 'position_ar' => 'منسق', 'member_type' => 'volunteer', 'is_active' => true, 'sort_order' => 1]);
        $profile = VolunteerProfile::create(['user_id' => $user->id, 'status' => 'active']);

        $this->fetch($this->profileUrl($profile), $this->staff())->assertOk()
            ->assertJsonPath('data.request', null)
            ->assertJsonPath('data.match', null)
            ->assertJsonPath('data.source', 'team_profile')
            ->assertJsonPath('data.team_profile.position_ar', 'منسق');
    }

    public function test_profile_of_an_account_only_volunteer(): void
    {
        $user = User::factory()->create(['role' => 'volunteer', 'email' => 'acc@example.com']);
        $profile = VolunteerProfile::create(['user_id' => $user->id, 'status' => 'active']);

        $this->fetch($this->profileUrl($profile), $this->staff())->assertOk()
            ->assertJsonPath('data.request', null)
            ->assertJsonPath('data.source', 'account_only')
            ->assertJsonPath('data.team_profile', null)
            ->assertJsonPath('data.volunteer.department_name', 'غير محدد')
            ->assertJsonPath('data.volunteer.account.has_account', true);
    }

    public function test_profile_uses_the_hub_department_fallback_for_the_name(): void
    {
        $dept = $this->dept('التقنية');
        $user = User::factory()->create(['role' => 'department_member']);
        TeamProfile::create(['user_id' => $user->id, 'name_ar' => 'x', 'position_ar' => 'x', 'member_type' => 'volunteer', 'department_id' => $dept->id, 'is_active' => true, 'sort_order' => 1]);
        $profile = VolunteerProfile::create(['user_id' => $user->id, 'status' => 'active']); // no department of its own

        $this->fetch($this->profileUrl($profile), $this->staff())->assertOk()
            ->assertJsonPath('data.volunteer.department_id', $dept->id)
            ->assertJsonPath('data.volunteer.department_name', 'التقنية');
    }

    public function test_a_volunteer_whose_account_is_soft_deleted_keeps_its_request_link_but_has_no_account(): void
    {
        [$profile, $user, $req] = $this->converted();
        $user->delete(); // the relation resolves to null; volunteer_profiles.user_id still points at the row

        $hr = $this->staff();
        $this->fetch($this->profileUrl($profile), $hr)->assertOk()
            ->assertJsonPath('data.volunteer.name', 'متطوع')
            ->assertJsonPath('data.volunteer.email', null)
            ->assertJsonPath('data.volunteer.account.has_account', false)
            ->assertJsonPath('data.volunteer.account.must_change_password', false)
            ->assertJsonPath('data.volunteer.account.created_at', null)
            // steps 1 and 2 follow user_id, not the user row: the link through the team profile survives
            ->assertJsonPath('data.match', 'team_source')
            ->assertJsonPath('data.request.id', $req->id);

        $this->fetch($this->activityUrl($profile), $hr)->assertOk();
    }

    public function test_a_soft_deleted_account_is_never_matched_by_email(): void
    {
        $user    = User::factory()->create(['role' => 'volunteer', 'email' => 'gone@example.com']);
        $profile = VolunteerProfile::create(['user_id' => $user->id, 'status' => 'active']);
        $this->newRequest(['email' => 'gone@example.com', 'status' => 'accepted']);
        $user->delete();

        $this->fetch($this->profileUrl($profile), $this->staff())->assertOk()
            ->assertJsonPath('data.request', null)
            ->assertJsonPath('data.match', null);
    }

    public function test_profile_never_exposes_secrets(): void
    {
        [$profile, $user] = $this->converted();
        $user->forceFill(['remember_token' => 'rmb-token-value-1', 'password' => 'pw-hash-marker-1'])->save();

        $res = $this->fetch($this->profileUrl($profile), $this->staff())->assertOk();
        $body = $res->getContent();

        // No key anywhere in the payload names a secret (must_change_password is a flag, not a secret, and is the one allowed match).
        $keys = [];
        $walk = function (array $a) use (&$walk, &$keys) {
            foreach ($a as $k => $v) {
                $keys[] = (string) $k;
                if (is_array($v)) {
                    $walk($v);
                }
            }
        };
        $walk($res->json());
        foreach (array_unique($keys) as $key) {
            if ($key === 'must_change_password') {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/password|token|secret|two_factor|remember/i', $key, "payload key {$key} looks like a secret");
        }

        foreach (['rmb-token-value-1', 'pw-hash-marker-1', (string) $user->fresh()->password] as $value) {
            $this->assertStringNotContainsString($value, $body);
        }
    }

    // ── /activity ────────────────────────────────────────────────────────────

    public function test_a_converted_volunteer_gets_a_full_newest_first_timeline(): void
    {
        [$profile, $user, $req] = $this->converted();
        $hr = $this->staff();
        $awarder = User::factory()->create(['role' => 'admin', 'name' => 'المانح']);

        // fixed, distinct timestamps (oldest -> newest)
        $req->forceFill(['created_at' => Carbon::parse('2026-11-01 09:00'), 'accepted_at' => Carbon::parse('2026-11-02 09:00'), 'converted_to_member_at' => Carbon::parse('2026-11-03 09:00')])->save();
        $profile->forceFill(['created_at' => Carbon::parse('2026-11-03 09:05')])->save();

        $log = VolunteerStatusLog::create(['volunteer_profile_id' => $profile->id, 'user_id' => $user->id, 'old_status' => 'active', 'new_status' => 'on_leave', 'reason' => 'سفر', 'changed_by' => $hr->id]);
        $log->forceFill(['created_at' => Carbon::parse('2026-11-04 09:00')])->save();

        VolunteerPointAward::create(['user_id' => $user->id, 'category' => 'task', 'points' => 10, 'reason' => 'مهمة', 'awarded_by' => $awarder->id, 'awarded_at' => Carbon::parse('2026-11-05 09:00')]);
        VolunteerPointAward::create(['user_id' => $user->id, 'category' => VolunteerPointAward::CATEGORY_ADJUSTMENT, 'points' => -3, 'reason' => 'تصحيح', 'note' => 'سري داخلي', 'awarded_by' => $awarder->id, 'awarded_at' => Carbon::parse('2026-11-06 09:00')]);
        $redemption = VolunteerPointRedemption::create(['user_id' => $user->id, 'points' => 4, 'purpose' => 'دورة', 'approved_by' => $awarder->id]);
        $redemption->forceFill(['created_at' => Carbon::parse('2026-11-07 09:00')])->save();

        $report = WeeklyDepartmentReport::create(['department_id' => $profile->department_id, 'week_start' => '2026-11-02', 'achievements' => 'x', 'planned' => 'y', 'submitted_by' => $hr->id, 'submitted_at' => now()]);
        $rating = WeeklyReportRating::create(['weekly_department_report_id' => $report->id, 'user_id' => $user->id, 'scores' => ['quality' => 8], 'avg_score' => 8.0]);
        $rating->forceFill(['created_at' => Carbon::parse('2026-11-08 09:00')])->save();

        $res = $this->fetch($this->activityUrl($profile), $hr)->assertOk()->assertJsonPath('success', true);
        $events = $res->json('data');

        $this->assertSame(
            ['weekly_rating', 'points_redeemed', 'points_deducted', 'points_awarded', 'status_change', 'profile_created', 'converted_to_member', 'request_accepted', 'request_submitted'],
            array_column($events, 'type'),
            'newest first'
        );

        $byType = array_column($events, null, 'type');
        $this->assertSame('قدّم طلب التطوع', $byType['request_submitted']['title']);
        $this->assertSame('قُبل طلب التطوع', $byType['request_accepted']['title']);
        $this->assertSame('تحويل إلى عضو وإنشاء حساب', $byType['converted_to_member']['title']);
        $this->assertSame('الإعلام', $byType['converted_to_member']['description']);
        $this->assertSame('إنشاء الملف التطوعي في مركز المتطوعين', $byType['profile_created']['title']);
        $this->assertNotNull($byType['request_accepted']['actor_name']);
        $this->assertNotNull($byType['converted_to_member']['actor_name']);
        $this->assertSame('team_source', $byType['request_submitted']['meta']['match']);

        $this->assertSame('on_leave', $byType['status_change']['meta']['new_status']);
        $this->assertSame('active', $byType['status_change']['meta']['old_status']);
        $this->assertSame('سفر', $byType['status_change']['meta']['reason']);
        $this->assertSame($hr->name, $byType['status_change']['actor_name']);

        $this->assertSame(10, $byType['points_awarded']['meta']['points']);
        $this->assertSame('المانح', $byType['points_awarded']['actor_name']);
        $this->assertSame(-3, $byType['points_deducted']['meta']['points']);
        $this->assertSame(4, $byType['points_redeemed']['meta']['points']);
        $this->assertSame('المانح', $byType['points_redeemed']['actor_name']);
        $this->assertSame(8.0, (float) $byType['weekly_rating']['meta']['avg_score']);
        $this->assertSame('2026-11-02', $byType['weekly_rating']['meta']['week_start']);

        foreach ($events as $e) {
            foreach (['id', 'type', 'title', 'description', 'occurred_at', 'date_formatted', 'actor_name', 'meta'] as $key) {
                $this->assertArrayHasKey($key, $e);
            }
            $this->assertNotEmpty($e['date_formatted']);
            $this->assertStringNotContainsString('سري داخلي', json_encode($e, JSON_UNESCAPED_UNICODE), 'the staff-only note never leaks');
        }
        $this->assertSame(count($events), count(array_unique(array_column($events, 'id'))), 'event ids are unique');

        $times = array_column($events, 'occurred_at');
        $sorted = $times;
        rsort($sorted);
        $this->assertSame($sorted, $times);
    }

    public function test_a_missing_actor_is_null_never_an_invented_name(): void
    {
        $user    = User::factory()->create(['role' => 'volunteer']);
        $profile = VolunteerProfile::create(['user_id' => $user->id, 'status' => 'active']);

        VolunteerStatusLog::create(['volunteer_profile_id' => $profile->id, 'user_id' => $user->id, 'old_status' => 'active', 'new_status' => 'inactive', 'reason' => 'آلي', 'changed_by' => null]);
        VolunteerPointAward::create(['user_id' => $user->id, 'category' => 'task', 'points' => 2, 'reason' => 'آلي', 'awarded_by' => null, 'awarded_at' => now()]);
        VolunteerPointRedemption::create(['user_id' => $user->id, 'points' => 1, 'purpose' => 'دورة', 'approved_by' => null]);

        $events = $this->fetch($this->activityUrl($profile), $this->staff())->assertOk()->json('data');

        $this->assertCount(4, $events);
        foreach ($events as $e) {
            $this->assertNull($e['actor_name'], "{$e['type']} has no recorded actor");
        }
    }

    public function test_a_legacy_profile_with_nothing_else_still_has_a_creation_event(): void
    {
        $user = User::factory()->create(['role' => 'volunteer']);
        $profile = VolunteerProfile::create(['user_id' => $user->id, 'status' => 'active']);

        $data = $this->fetch($this->activityUrl($profile), $this->staff())->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('profile_created', $data[0]['type']);
    }

    public function test_events_with_the_same_instant_keep_a_stable_logical_order(): void
    {
        [$profile, $user, $req] = $this->converted();
        $at = Carbon::parse('2026-11-10 12:00:00');

        $req->forceFill(['created_at' => $at, 'accepted_at' => $at, 'converted_to_member_at' => $at])->save();
        $profile->forceFill(['created_at' => $at])->save();
        $log = VolunteerStatusLog::create(['volunteer_profile_id' => $profile->id, 'user_id' => $user->id, 'old_status' => 'active', 'new_status' => 'inactive', 'reason' => 'x', 'changed_by' => null]);
        $log->forceFill(['created_at' => $at])->save();
        VolunteerPointAward::create(['user_id' => $user->id, 'category' => 'task', 'points' => 1, 'reason' => 'x', 'awarded_at' => $at]);

        $types = array_column($this->fetch($this->activityUrl($profile), $this->staff())->assertOk()->json('data'), 'type');

        $this->assertSame(['points_awarded', 'status_change', 'profile_created', 'converted_to_member', 'request_accepted', 'request_submitted'], $types);

        // and it is the same on every call
        $this->assertSame($types, array_column($this->fetch($this->activityUrl($profile), $this->staff())->json('data'), 'type'));
    }

    public function test_the_timeline_keeps_the_newest_100_events_and_drops_the_oldest(): void
    {
        $user    = User::factory()->create(['role' => 'volunteer']);
        $profile = VolunteerProfile::create(['user_id' => $user->id, 'status' => 'active']);
        $profile->forceFill(['created_at' => Carbon::parse('2026-01-01 00:00')])->save();
        $base    = Carbon::parse('2026-06-01 00:00');

        // 60 awards at minutes 0,2,4,... and 60 status changes at minutes 1,3,5,... : 120 events interleaved, plus the creation event
        foreach (range(0, 59) as $i) {
            VolunteerPointAward::create(['user_id' => $user->id, 'category' => 'task', 'points' => 1, 'reason' => "award-{$i}", 'awarded_at' => $base->copy()->addMinutes($i * 2)]);
            $log = VolunteerStatusLog::create(['volunteer_profile_id' => $profile->id, 'user_id' => $user->id, 'old_status' => 'active', 'new_status' => 'inactive', 'reason' => "log-{$i}", 'changed_by' => null]);
            $log->forceFill(['created_at' => $base->copy()->addMinutes($i * 2 + 1)])->save();
        }

        $data = $this->fetch($this->activityUrl($profile), $this->staff())->assertOk()->json('data');

        $this->assertCount(100, $data);

        // newest first, strictly
        $times = array_column($data, 'occurred_at');
        $sorted = $times;
        rsort($sorted);
        $this->assertSame($sorted, $times);
        $this->assertSame($base->copy()->addMinutes(119)->toIso8601String(), $data[0]['occurred_at'], 'the very newest event is kept');
        $this->assertSame('log-59', $data[0]['meta']['reason']);

        // the oldest are dropped: 121 events exist, the last 100 stay, so minutes 0..19 (events 0-19) and the creation event are gone
        $this->assertSame($base->copy()->addMinutes(20)->toIso8601String(), $data[99]['occurred_at']);
        $this->assertNotContains('profile_created', array_column($data, 'type'), 'the oldest event (creation) fell off the cap');
        $reasons = array_filter(array_map(fn ($e) => $e['meta']['reason'] ?? null, $data));
        $this->assertNotContains('award-0', $reasons);
        $this->assertNotContains('log-9', $reasons);
        $this->assertContains('award-10', $reasons);
    }

    public function test_the_timeline_query_count_does_not_grow_with_the_number_of_events(): void
    {
        [$profile, $user] = $this->converted();
        $hr = $this->staff();

        $count = function () use ($profile, $hr): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->fetch($this->activityUrl($profile), $hr)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $report = WeeklyDepartmentReport::create(['department_id' => $profile->department_id, 'week_start' => '2026-11-02', 'achievements' => 'x', 'planned' => 'y', 'submitted_by' => $hr->id, 'submitted_at' => now()]);
        $add = function (int $i) use ($profile, $user, $hr) {
            VolunteerPointAward::create(['user_id' => $user->id, 'category' => 'task', 'points' => 1, 'reason' => "r{$i}", 'awarded_by' => $hr->id, 'awarded_at' => now()->addMinutes($i)]);
            VolunteerStatusLog::create(['volunteer_profile_id' => $profile->id, 'user_id' => $user->id, 'old_status' => 'active', 'new_status' => 'inactive', 'reason' => "s{$i}", 'changed_by' => $hr->id]);
            VolunteerPointRedemption::create(['user_id' => $user->id, 'points' => 1, 'purpose' => "p{$i}", 'approved_by' => $hr->id]);
        };

        // one event of every kind
        $add(1);
        $other = WeeklyDepartmentReport::create(['department_id' => $profile->department_id, 'week_start' => '2026-11-09', 'achievements' => 'x', 'planned' => 'y', 'submitted_by' => $hr->id, 'submitted_at' => now()]);
        WeeklyReportRating::create(['weekly_department_report_id' => $report->id, 'user_id' => $user->id, 'scores' => ['q' => 5], 'avg_score' => 5.0]);
        $this->fetch($this->activityUrl($profile), $hr)->assertOk(); // warm-up (routes, caches)
        $few = $count();

        foreach (range(2, 11) as $i) {
            $add($i);
        }
        WeeklyReportRating::create(['weekly_department_report_id' => $other->id, 'user_id' => $user->id, 'scores' => ['q' => 6], 'avg_score' => 6.0]);
        $many = $count();

        $this->assertSame($few, $many, "the timeline ran {$few} queries for few events and {$many} for many: it must not grow");
    }

    public function test_the_existing_detail_endpoints_keep_their_shape(): void
    {
        [$profile] = $this->converted();
        $hr = $this->staff();

        $this->fetch("/api/operations/volunteers/{$profile->id}/status-logs", $hr)->assertOk()->assertJsonPath('data', []);
        $this->fetch("/api/operations/volunteers/{$profile->id}/points-ledger", $hr)->assertOk()->assertJsonStructure(['data' => ['ledger', 'actual_points', 'used_points', 'balance']]);
        $this->fetch("/api/operations/volunteers/{$profile->id}/weekly-ratings", $hr)->assertOk()->assertJsonStructure(['data' => ['ratings', 'monthly_averages', 'criteria_averages', 'overall_avg']]);
    }
}
