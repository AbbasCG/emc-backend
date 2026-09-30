<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPageAccessOverride;
use App\Models\VolunteerPointAward;
use App\Models\VolunteerPointRedemption;
use App\Services\EffectivePageAccessService;
use App\Support\PageAccess\RoleDefaultMap;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * IMPACT POINTS — /dashboard/operations/impact-points.
 *
 * The reported bug: every non-root team member was redirected away from the
 * page. operations.impact_points was a DEFERRED role default, so
 * EffectivePageAccessService denied it to everyone but super_admin/tech_admin,
 * while VolunteerPointsController's read endpoints served all team roles. The
 * frontend guard trusts the page-access manifest, so the page bounced users
 * the API would have answered with 200.
 *
 * These tests pin the two layers to each other and keep them separate:
 *   PAGE ACCESS (may the page open)  == the read route group's canonical roles
 *   BUSINESS AUTHORIZATION (award/redeem) — its own narrower manager gate,
 *   which opening the page never grants.
 */
class ImpactPointsAccessTest extends TestCase
{
    use RefreshDatabase;

    private const READS = ['policy', 'summary', 'my-awards', 'leaderboard'];

    /** Mirrors the award/redeem middleware in routes/api.php. */
    private const AWARD_ROLES = [
        'admin', 'super_admin', 'tech_admin', 'executive_admin',
        'hr_manager', 'operations_manager', 'department_manager',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
        $this->artisan('access:sync-role-defaults', ['--apply' => true])->assertExitCode(0);
    }

    private function user(string $role, array $attrs = []): User
    {
        return User::factory()->create(array_merge(['role' => $role, 'is_active' => true], $attrs));
    }

    private function canOpenPage(User $user): bool
    {
        return app(EffectivePageAccessService::class)->canAccess($user, 'operations.impact_points');
    }

    private function readStatus(User $user, string $endpoint): int
    {
        return $this->actingAs($user)
            ->getJson("/api/operations/volunteer-points/{$endpoint}")
            ->status();
    }

    private function department(array $attrs = []): Department
    {
        $suffix = Str::random(6);

        return Department::create(array_merge([
            'name' => 'Dept '.$suffix, 'name_ar' => 'إدارة '.$suffix, 'slug' => 'dept-'.strtolower($suffix),
            'status' => 'active', 'is_active' => true,
        ], $attrs));
    }

    // ── Page access agrees with the API, for every canonical role ────────────

    public function test_page_access_matches_the_read_api_for_every_canonical_role(): void
    {
        foreach (RoleSeeder::ROLES as $roleRow) {
            $role = $roleRow['name'];
            $user = $this->user($role);

            $apiServes = $this->readStatus($user, 'summary') === 200;

            $this->assertSame(
                $apiServes,
                $this->canOpenPage($user),
                "[{$role}] page access and the read API disagree (API serves: ".($apiServes ? 'yes' : 'no').').'
            );
        }
    }

    public function test_every_team_role_opens_the_page_and_reads_all_four_endpoints(): void
    {
        $team = [
            'admin', 'executive_admin', 'programs_manager', 'instructor', 'hr_manager',
            'finance_manager', 'marketing_manager', 'quality_manager', 'support_agent',
            'operations_manager', 'partnerships_manager', 'community_manager', 'volunteer',
            'department_manager', 'section_lead', 'ai_manager', 'super_admin', 'tech_admin',
        ];

        foreach ($team as $role) {
            $user = $this->user($role);
            $this->assertTrue($this->canOpenPage($user), "[{$role}] should open the page.");

            foreach (self::READS as $endpoint) {
                $this->assertSame(200, $this->readStatus($user, $endpoint), "[{$role}] {$endpoint}");
            }
        }
    }

    public function test_student_and_partner_neither_open_the_page_nor_read(): void
    {
        foreach (['student', 'partner'] as $role) {
            $user = $this->user($role);
            $this->assertFalse($this->canOpenPage($user), "[{$role}] must not open the page.");

            foreach (self::READS as $endpoint) {
                $this->assertSame(403, $this->readStatus($user, $endpoint), "[{$role}] {$endpoint}");
            }
        }
    }

    public function test_impact_points_is_mapped_only_to_non_root_roles(): void
    {
        foreach (RoleDefaultMap::ROOT_ROLES as $root) {
            $this->assertArrayNotHasKey($root, RoleDefaultMap::all());
        }

        $this->assertNotContains('operations.impact_points', RoleDefaultMap::DEFERRED_PRODUCT_DECISION);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        foreach (self::READS as $endpoint) {
            $this->getJson("/api/operations/volunteer-points/{$endpoint}")->assertUnauthorized();
        }

        $this->postJson('/api/operations/volunteer-points/awards', [])->assertUnauthorized();
        $this->postJson('/api/operations/volunteer-points/redemptions', [])->assertUnauthorized();
    }

    public function test_an_inactive_account_is_rejected_even_with_a_team_role(): void
    {
        $user = $this->user('volunteer', ['is_active' => false]);

        $this->assertSame(403, $this->readStatus($user, 'summary'));
    }

    // ── PAGE ACCESS != BUSINESS AUTHORIZATION ────────────────────────────────

    public function test_a_page_allow_override_does_not_grant_the_api(): void
    {
        $student = $this->user('student');

        UserPageAccessOverride::create([
            'user_id' => $student->id,
            'page_key' => 'operations.impact_points',
            'state' => 'allow',
        ]);

        $this->assertTrue($this->canOpenPage($student), 'the override opens the page');
        $this->assertSame(403, $this->readStatus($student, 'summary'), '…but never the data behind it');
        $this->actingAs($student)->postJson('/api/operations/volunteer-points/awards', [
            'user_id' => $student->id, 'category' => 'task', 'points' => 100, 'reason' => 'x',
        ])->assertForbidden();
    }

    public function test_opening_the_page_does_not_grant_award_or_redeem(): void
    {
        $recipient = $this->user('volunteer');

        foreach (['quality_manager', 'finance_manager', 'instructor', 'volunteer', 'programs_manager', 'ai_manager'] as $role) {
            $user = $this->user($role);
            $this->assertTrue($this->canOpenPage($user), "[{$role}] opens the page");

            $this->actingAs($user)->postJson('/api/operations/volunteer-points/awards', [
                'user_id' => $recipient->id, 'category' => 'task', 'points' => 100, 'reason' => 'x',
            ])->assertForbidden();

            $this->actingAs($user)->postJson('/api/operations/volunteer-points/redemptions', [
                'user_id' => $recipient->id, 'points' => 100, 'purpose' => 'x',
            ])->assertForbidden();
        }

        $this->assertSame(0, VolunteerPointAward::count());
        $this->assertSame(0, VolunteerPointRedemption::count());
    }

    public function test_canonical_department_leadership_alone_does_not_grant_awarding(): void
    {
        // A volunteer who is departments.leader_id leads a department, but the
        // award gate is a role gate: leadership is not, by itself, award
        // authority in the current design.
        $leader = $this->user('volunteer');
        $this->department(['leader_id' => $leader->id]);
        $member = $this->user('volunteer');

        $this->actingAs($leader)->postJson('/api/operations/volunteer-points/awards', [
            'user_id' => $member->id, 'category' => 'task', 'points' => 100, 'reason' => 'x',
        ])->assertForbidden();
    }

    public function test_award_roles_can_award(): void
    {
        $recipient = $this->user('volunteer');

        foreach (self::AWARD_ROLES as $role) {
            $this->actingAs($this->user($role))->postJson('/api/operations/volunteer-points/awards', [
                'user_id' => $recipient->id, 'category' => 'task', 'points' => 10, 'reason' => "by {$role}",
            ])->assertCreated();
        }

        $this->assertSame(count(self::AWARD_ROLES) * 10, (int) VolunteerPointAward::where('user_id', $recipient->id)->sum('points'));
    }

    public function test_redeem_cannot_exceed_the_available_balance(): void
    {
        $hr = $this->user('hr_manager');
        $recipient = $this->user('volunteer');
        VolunteerPointAward::create([
            'user_id' => $recipient->id, 'category' => 'task', 'points' => 150,
            'reason' => 'seed', 'awarded_by' => $hr->id, 'awarded_at' => now(),
        ]);

        $this->actingAs($hr)->postJson('/api/operations/volunteer-points/redemptions', [
            'user_id' => $recipient->id, 'points' => 200, 'purpose' => 'course',
        ])->assertStatus(422);

        $this->assertSame(0, VolunteerPointRedemption::count());
    }

    public function test_there_are_no_update_or_delete_endpoints_for_awards(): void
    {
        $hr = $this->user('hr_manager');

        $this->actingAs($hr)->putJson('/api/operations/volunteer-points/awards/1', [])->assertStatus(404);
        $this->actingAs($hr)->deleteJson('/api/operations/volunteer-points/awards/1')->assertStatus(404);
    }

    // ── Personal data: no IDOR through the read endpoints ────────────────────

    public function test_summary_and_my_awards_are_always_the_callers_own(): void
    {
        $hr = $this->user('hr_manager');
        $me = $this->user('volunteer');
        $other = $this->user('volunteer');

        VolunteerPointAward::create([
            'user_id' => $other->id, 'category' => 'task', 'points' => 900,
            'reason' => 'other', 'awarded_by' => $hr->id, 'awarded_at' => now(),
        ]);
        VolunteerPointAward::create([
            'user_id' => $me->id, 'category' => 'task', 'points' => 40,
            'reason' => 'mine', 'awarded_by' => $hr->id, 'awarded_at' => now(),
        ]);

        // A user_id parameter is ignored: the caller always sees their own data.
        $this->actingAs($me)->getJson("/api/operations/volunteer-points/summary?user_id={$other->id}")
            ->assertOk()->assertJsonPath('data.lifetime_points', 40);

        $awards = $this->actingAs($me)->getJson("/api/operations/volunteer-points/my-awards?user_id={$other->id}")
            ->assertOk()->json('data.data');
        $this->assertCount(1, $awards);
        $this->assertSame('mine', $awards[0]['reason']);
    }

    public function test_department_membership_does_not_change_what_a_member_reads(): void
    {
        $hr = $this->user('hr_manager');
        $deptA = $this->department();
        $deptB = $this->department();
        $inA = $this->user('volunteer');
        $inB = $this->user('volunteer');
        $inA->departments()->attach($deptA->id);
        $inB->departments()->attach($deptB->id);

        VolunteerPointAward::create([
            'user_id' => $inB->id, 'category' => 'task', 'points' => 300,
            'reason' => 'b', 'awarded_by' => $hr->id, 'awarded_at' => now(),
        ]);

        // Personal endpoints: A never sees B's ledger regardless of departments.
        $this->actingAs($inA)->getJson('/api/operations/volunteer-points/summary')
            ->assertOk()->assertJsonPath('data.lifetime_points', 0);
        $this->assertSame([], $this->actingAs($inA)->getJson('/api/operations/volunteer-points/my-awards')->json('data.data'));
    }

    // ── Leaderboard regression: soft-deleted users ───────────────────────────

    public function test_leaderboard_never_returns_a_null_user_for_a_soft_deleted_account(): void
    {
        $hr = $this->user('hr_manager');
        $gone = $this->user('volunteer');
        $here = $this->user('volunteer');

        foreach ([$gone, $here] as $u) {
            VolunteerPointAward::create([
                'user_id' => $u->id, 'category' => 'task', 'points' => 500,
                'reason' => 'x', 'awarded_by' => $hr->id, 'awarded_at' => now(),
            ]);
        }

        $gone->delete(); // soft delete — the award row survives

        $rows = $this->actingAs($here)->getJson('/api/operations/volunteer-points/leaderboard')
            ->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertNotNull($rows[0]['user']);
        $this->assertSame($here->id, $rows[0]['user']['id']);
    }
}
