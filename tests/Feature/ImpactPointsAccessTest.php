<?php

namespace Tests\Feature;

use App\Http\Controllers\Operations\VolunteerPointsController;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Permission;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use App\Models\UserPageAccessOverride;
use App\Models\UserPermissionAssignment;
use App\Models\VolunteerPointAward;
use App\Models\VolunteerPointRedemption;
use App\Services\EffectivePageAccessService;
use App\Support\PageAccess\RoleDefaultMap;
use App\Support\Permissions;
use Database\Seeders\GranularPermissionsSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * IMPACT POINTS — /dashboard/operations/impact-points.
 *
 * PAGE ACCESS (may the page open) is pinned to the read API's audience.
 * BUSINESS AUTHORIZATION (who may award/redeem, to whom) is separate:
 *   - authority: members.recognize or members.manage (existing permissions,
 *     seeded exactly as production seeds them);
 *   - recipients: active EMC team members only (team_members / team_profiles),
 *     never a plain student or course instructor;
 *   - scope: members.view_department holders award only in departments they
 *     lead via departments.leader_id;
 *   - no self-award, no self-redemption, monthly cap on positive awards.
 */
class ImpactPointsAccessTest extends TestCase
{
    use RefreshDatabase;

    private const READS = ['policy', 'summary', 'my-awards', 'leaderboard'];

    protected function setUp(): void
    {
        parent::setUp();

        // Same order as DatabaseSeeder — the permission rows are the authority.
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
            'name' => 'Dept '.$suffix, 'name_ar' => 'إدارة '.$suffix, 'slug' => 'dept-'.strtolower($suffix),
            'status' => 'active', 'is_active' => true,
        ], $attrs));
    }

    /**
     * A team membership row for an existing user. team_members.team_profile_id
     * is NOT NULL (every membership belongs to a profile), so the profile is
     * created first, exactly as the HR/volunteer flows do.
     */
    private function membership(User $u, Department $dept, string $status = 'active', ?string $title = 'منسق', bool $profileActive = true): TeamMember
    {
        $profile = TeamProfile::create([
            'department_id' => $dept->id, 'user_id' => $u->id, 'name_ar' => $u->name,
            'position_ar' => 'عضو فريق', 'is_active' => $profileActive,
        ]);

        return TeamMember::create([
            'user_id' => $u->id, 'team_profile_id' => $profile->id, 'department_id' => $dept->id,
            'status' => $status, 'role_title' => $title,
        ]);
    }

    /** A volunteer with an active team_members row in $dept. */
    private function teamMember(Department $dept, string $role = 'volunteer', string $title = 'منسق'): User
    {
        $u = $this->user($role);
        $this->membership($u, $dept, 'active', $title);

        return $u;
    }

    /** A user known only through an active team_profiles row. */
    private function profiled(Department $dept, string $role = 'volunteer'): User
    {
        $u = $this->user($role);
        TeamProfile::create([
            'department_id' => $dept->id, 'user_id' => $u->id, 'name_ar' => $u->name,
            'position_ar' => 'متطوع ميداني', 'is_active' => true,
        ]);

        return $u;
    }

    private function award(User $actor, User $to, int $points = 100, string $category = 'task')
    {
        return $this->actingAs($actor)->postJson('/api/operations/volunteer-points/awards', [
            'user_id' => $to->id, 'category' => $category, 'points' => $points, 'reason' => 'سبب موثق',
        ]);
    }

    private function seedPoints(User $to, int $points): void
    {
        VolunteerPointAward::create([
            'user_id' => $to->id, 'category' => 'task', 'points' => $points,
            'reason' => 'seed', 'awarded_by' => null, 'awarded_at' => now(),
        ]);
    }

    private function recipientIds(User $actor): array
    {
        return collect($this->actingAs($actor)->getJson('/api/operations/volunteer-points/recipients')
            ->assertOk()->json('data'))->pluck('user_id')->all();
    }

    // ── Page access agrees with the read API ─────────────────────────────────

    public function test_page_access_matches_the_read_api_for_every_canonical_role(): void
    {
        foreach (RoleSeeder::ROLES as $row) {
            $user = $this->user($row['name']);
            $apiServes = $this->actingAs($user)->getJson('/api/operations/volunteer-points/summary')->status() === 200;

            $this->assertSame($apiServes, app(EffectivePageAccessService::class)->canAccess($user, 'operations.impact_points'), "[{$row['name']}]");
        }
    }

    public function test_team_roles_read_and_student_partner_do_not(): void
    {
        foreach (['volunteer', 'instructor', 'quality_manager', 'finance_manager', 'super_admin'] as $role) {
            $user = $this->user($role);
            foreach (self::READS as $e) {
                $this->actingAs($user)->getJson("/api/operations/volunteer-points/{$e}")->assertOk();
            }
        }
        foreach (['student', 'partner'] as $role) {
            $this->actingAs($this->user($role))->getJson('/api/operations/volunteer-points/summary')->assertForbidden();
        }
        $this->assertNotContains('operations.impact_points', RoleDefaultMap::DEFERRED_PRODUCT_DECISION);
    }

    public function test_unauthenticated_and_inactive_are_rejected(): void
    {
        foreach ([...self::READS, 'recipients'] as $e) {
            $this->getJson("/api/operations/volunteer-points/{$e}")->assertUnauthorized();
        }
        $this->postJson('/api/operations/volunteer-points/awards', [])->assertUnauthorized();
        $this->postJson('/api/operations/volunteer-points/redemptions', [])->assertUnauthorized();

        $this->actingAs($this->user('volunteer', ['is_active' => false]))
            ->getJson('/api/operations/volunteer-points/summary')->assertForbidden();
    }

    // ── Who may award (business authorization) ───────────────────────────────

    public function test_summary_reports_award_authority_from_the_server(): void
    {
        $this->actingAs($this->user('hr_manager'))->getJson('/api/operations/volunteer-points/summary')
            ->assertOk()->assertJsonPath('data.can_award', true)
            ->assertJsonPath('data.monthly_cap', VolunteerPointsController::MONTHLY_CAP);
        $this->actingAs($this->user('volunteer'))->getJson('/api/operations/volunteer-points/summary')
            ->assertOk()->assertJsonPath('data.can_award', false);
    }

    public function test_org_wide_authority_holders_can_award_any_team_member(): void
    {
        $dept = $this->department();
        $recipient = $this->teamMember($dept);

        foreach (['super_admin', 'tech_admin', 'admin', 'hr_manager'] as $role) {
            $this->award($this->user($role), $recipient, 10)->assertCreated();
        }

        $this->assertSame(40, (int) VolunteerPointAward::where('user_id', $recipient->id)->sum('points'));
        $this->assertSame(4, AuditLog::where('action', 'IMPACT_POINTS_AWARDED')->count());
    }

    public function test_roles_without_the_permission_cannot_award_even_with_the_page(): void
    {
        $recipient = $this->teamMember($this->department());

        foreach (['executive_admin', 'operations_manager', 'quality_manager', 'finance_manager', 'programs_manager', 'instructor', 'volunteer', 'ai_manager', 'support_agent'] as $role) {
            $user = $this->user($role);
            $this->assertTrue(app(EffectivePageAccessService::class)->canAccess($user, 'operations.impact_points'), "[{$role}] opens the page");
            $this->award($user, $recipient)->assertForbidden();
            $this->actingAs($user)->getJson('/api/operations/volunteer-points/recipients')->assertForbidden();
            $this->actingAs($user)->postJson('/api/operations/volunteer-points/redemptions', [
                'user_id' => $recipient->id, 'points' => 100, 'purpose' => 'x',
            ])->assertForbidden();
        }

        $this->assertSame(0, VolunteerPointAward::count());
    }

    public function test_a_page_allow_override_grants_no_business_authority(): void
    {
        $student = $this->user('student');
        UserPageAccessOverride::create(['user_id' => $student->id, 'page_key' => 'operations.impact_points', 'state' => 'allow']);

        $this->assertTrue(app(EffectivePageAccessService::class)->canAccess($student, 'operations.impact_points'));
        $this->actingAs($student)->getJson('/api/operations/volunteer-points/summary')->assertForbidden();
        $this->award($student, $this->teamMember($this->department()))->assertForbidden();
    }

    public function test_the_permission_can_be_granted_per_user_through_the_rights_system(): void
    {
        $volunteer = $this->user('volunteer');
        $recipient = $this->teamMember($this->department());
        $this->award($volunteer, $recipient)->assertForbidden();

        // The exact row DepartmentPermissionController::grant() writes when a
        // leader delegates a permission to one person.
        UserPermissionAssignment::create([
            'user_id' => $volunteer->id,
            'permission_id' => Permission::where('name', Permissions::MEMBERS_RECOGNIZE)->value('id'),
            'state' => 'allow',
            'scope_type' => 'department',
            'source_type' => 'delegation',
        ]);

        $this->award($volunteer->fresh(), $recipient)->assertCreated();
    }

    // ── Department scope (canonical leadership) ──────────────────────────────

    public function test_department_manager_awards_only_in_departments_they_lead(): void
    {
        $manager = $this->user('department_manager');
        $own = $this->department(['leader_id' => $manager->id]);
        $other = $this->department();
        $inOwn = $this->teamMember($own);
        $inOther = $this->teamMember($other);

        $this->assertSame([$inOwn->id], $this->recipientIds($manager));
        $this->award($manager, $inOwn)->assertCreated();
        $this->award($manager, $inOther)->assertStatus(422)->assertJsonPath('code', 'RECIPIENT_NOT_ELIGIBLE');
    }

    public function test_a_department_manager_who_leads_nothing_can_award_nobody(): void
    {
        $manager = $this->user('department_manager');
        $recipient = $this->teamMember($this->department());

        $this->assertSame([], $this->recipientIds($manager));
        $this->award($manager, $recipient)->assertStatus(422);
    }

    public function test_team_profile_is_leader_is_not_leadership(): void
    {
        $manager = $this->user('department_manager');
        $dept = $this->department();
        TeamProfile::create(['department_id' => $dept->id, 'user_id' => $manager->id, 'name_ar' => 'م', 'position_ar' => 'قائد', 'is_active' => true, 'is_leader' => true]);
        $recipient = $this->teamMember($dept);

        $this->award($manager, $recipient)->assertStatus(422);
    }

    // ── Valid recipients ─────────────────────────────────────────────────────

    public function test_recipients_are_team_members_with_department_and_title(): void
    {
        $hr = $this->user('hr_manager');
        $dept = $this->department(['name' => 'إدارة البرامج']);
        $member = $this->teamMember($dept, 'volunteer', 'منسق برامج');
        $profiled = $this->profiled($dept);
        $plainStudent = $this->user('student');
        $plainInstructor = $this->user('instructor');
        $pivotOnly = $this->user('volunteer');
        $pivotOnly->departments()->attach($dept->id);
        $inactive = $this->teamMember($dept);
        $inactive->update(['is_active' => false]);
        // Deactivated membership with a still-active profile: the deactivation wins.
        $suspendedRow = $this->user('volunteer');
        $this->membership($suspendedRow, $dept, 'inactive');

        $rows = collect($this->actingAs($hr)->getJson('/api/operations/volunteer-points/recipients')->assertOk()->json('data'));

        $this->assertEqualsCanonicalizing([$member->id, $profiled->id], $rows->pluck('user_id')->all());
        $row = $rows->firstWhere('user_id', $member->id);
        $this->assertSame('إدارة البرامج', $row['department']);
        $this->assertSame('منسق برامج', $row['role_title']);
        $this->assertSame('متطوع ميداني', $rows->firstWhere('user_id', $profiled->id)['role_title']);
        $this->assertArrayNotHasKey('email', $row, 'the picker exposes no contact data');

        foreach ([$plainStudent, $plainInstructor, $pivotOnly, $inactive, $suspendedRow] as $nope) {
            $this->award($hr, $nope)->assertStatus(422)->assertJsonPath('code', 'RECIPIENT_NOT_ELIGIBLE');
        }
    }

    public function test_a_student_or_instructor_with_a_real_team_record_is_eligible(): void
    {
        $hr = $this->user('hr_manager');
        $dept = $this->department();

        $this->award($hr, $this->teamMember($dept, 'student'))->assertCreated();
        $this->award($hr, $this->profiled($dept, 'instructor'))->assertCreated();
    }

    public function test_awarders_never_see_or_award_themselves(): void
    {
        $hr = $this->user('hr_manager');
        $dept = $this->department();
        $this->membership($hr, $dept);

        $this->assertNotContains($hr->id, $this->recipientIds($hr));
        $this->award($hr, $hr)->assertStatus(422)->assertJsonPath('code', 'SELF_AWARD');
        $this->assertSame(0, VolunteerPointAward::count());
    }

    // ── Monthly cap ──────────────────────────────────────────────────────────

    public function test_positive_awards_respect_the_monthly_cap_and_deductions_do_not(): void
    {
        $hr = $this->user('hr_manager');
        $recipient = $this->teamMember($this->department());
        $this->seedPoints($recipient, VolunteerPointsController::MONTHLY_CAP - 100);

        $this->award($hr, $recipient, 101)->assertStatus(422)->assertJsonPath('code', 'MONTHLY_CAP_EXCEEDED');
        $this->award($hr, $recipient, 100)->assertCreated();
        $this->award($hr, $recipient, -300, 'other')->assertCreated();
    }

    // ── Redeem ───────────────────────────────────────────────────────────────

    public function test_redeem_rules(): void
    {
        $hr = $this->user('hr_manager');
        $recipient = $this->teamMember($this->department());
        $this->seedPoints($recipient, 150);

        $this->actingAs($hr)->postJson('/api/operations/volunteer-points/redemptions', [
            'user_id' => $recipient->id, 'points' => 200, 'purpose' => 'course',
        ])->assertStatus(422);

        $this->actingAs($hr)->postJson('/api/operations/volunteer-points/redemptions', [
            'user_id' => $recipient->id, 'points' => 100, 'purpose' => 'course',
        ])->assertCreated();

        $this->membership($hr, $this->department());
        $this->seedPoints($hr, 500);
        $this->actingAs($hr)->postJson('/api/operations/volunteer-points/redemptions', [
            'user_id' => $hr->id, 'points' => 100, 'purpose' => 'course',
        ])->assertStatus(422)->assertJsonPath('code', 'SELF_REDEMPTION');

        $this->assertSame(1, VolunteerPointRedemption::count());
    }

    // ── Personal reads: no IDOR ──────────────────────────────────────────────

    public function test_reads_are_always_the_callers_own(): void
    {
        $me = $this->user('volunteer');
        $other = $this->user('volunteer');
        $this->seedPoints($other, 900);
        $this->seedPoints($me, 40);

        $this->actingAs($me)->getJson("/api/operations/volunteer-points/summary?user_id={$other->id}")
            ->assertOk()->assertJsonPath('data.lifetime_points', 40);
        $this->assertCount(1, $this->actingAs($me)->getJson("/api/operations/volunteer-points/my-awards?user_id={$other->id}")->json('data.data'));
    }

    public function test_there_are_no_update_or_delete_endpoints_for_awards(): void
    {
        $hr = $this->user('hr_manager');

        $this->actingAs($hr)->putJson('/api/operations/volunteer-points/awards/1', [])->assertStatus(404);
        $this->actingAs($hr)->deleteJson('/api/operations/volunteer-points/awards/1')->assertStatus(404);
    }

    public function test_leaderboard_never_returns_a_null_user_for_a_soft_deleted_account(): void
    {
        $gone = $this->user('volunteer');
        $here = $this->user('volunteer');
        $this->seedPoints($gone, 500);
        $this->seedPoints($here, 500);
        $gone->delete();

        $rows = $this->actingAs($here)->getJson('/api/operations/volunteer-points/leaderboard')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($here->id, $rows[0]['user']['id']);
    }
}
