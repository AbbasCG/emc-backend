<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RolePageDefault;
use App\Models\User;
use App\Models\VolunteerPointActivity;
use App\Models\VolunteerPointRequest;
use App\Services\EffectivePageAccessService;
use App\Services\UserPageAccessOverrideService;
use App\Support\PageAccess\PageAccessCatalog;
use App\Support\PageAccess\PageAccessCategory;
use App\Support\PageAccess\PageAccessRiskLevel;
use App\Support\PageAccess\RoleDefaultMap;
use App\Support\PageAccess\UserPageAccessState;
use App\Support\Permissions;
use Database\Seeders\GranularPermissionsSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\VolunteerPointActivitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /dashboard/hr/volunteer-points-approval — PAGE ACCESS only.
 *
 * The page used to be owned implicitly by the `/dashboard/hr` prefix (hr.dashboard), so Access Control could not
 * list, grant or revoke it. It now has its own catalog key. This file pins:
 *   - the key, its route, its category and its risk;
 *   - that it is granted to hr_manager (and to root) and to no other role by default;
 *   - that the scoped production sync touches exactly one row;
 *   - that opening the page confers no approve / reject right (those stay in the controller).
 */
class VolunteerPointsApprovalPageAccessTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'hr.volunteer_points_approval';

    private const ROUTE = '/dashboard/hr/volunteer-points-approval';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
            GranularPermissionsSeeder::class,
            VolunteerPointActivitySeeder::class,
        ]);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function canOpen(User $user): bool
    {
        return app(EffectivePageAccessService::class)->canAccess($user, self::KEY);
    }

    // ── The catalog entry ────────────────────────────────────────────────────

    public function test_the_page_has_its_own_catalog_entry_under_hr(): void
    {
        $entry = PageAccessCatalog::find(self::KEY);

        $this->assertNotNull($entry, 'the page must have a row of its own in the registry');
        $this->assertSame(self::ROUTE, $entry->primaryRoute);
        $this->assertSame('اعتماد نقاط المتطوعين', $entry->labelAr);
        $this->assertSame(PageAccessCategory::Hr, $entry->category);
        $this->assertSame(PageAccessRiskLevel::AdminOnly, $entry->riskLevel);
        $this->assertFalse($entry->delegatable);
        $this->assertFalse($entry->authenticatedBaseline, 'never part of the baseline every account gets');
        $this->assertSame(Permissions::MEMBERS_MANAGE, $entry->requiredPermission);
    }

    public function test_the_route_resolves_to_its_own_key_and_not_to_the_hr_dashboard(): void
    {
        $this->assertSame(self::KEY, PageAccessCatalog::findByRoute(self::ROUTE)?->key);
        $this->assertSame('hr.dashboard', PageAccessCatalog::findByRoute('/dashboard/hr')?->key, 'the HR home is unchanged');
    }

    public function test_the_key_is_unique_and_the_route_is_claimed_once(): void
    {
        $keys = array_map(fn ($e) => $e->key, PageAccessCatalog::all());
        $this->assertSame(1, count(array_keys($keys, self::KEY, true)));

        $owners = array_filter(PageAccessCatalog::all(), fn ($e) => $e->matchesRoute(self::ROUTE));
        $this->assertCount(1, $owners);
    }

    public function test_access_control_lists_the_page_under_hr_with_the_arabic_label(): void
    {
        $root = $this->user('super_admin');

        $response = $this->actingAs($root)->getJson('/api/admin/access-control/pages')->assertOk();

        $row = collect($response->json('data.pages') ?? $response->json('data'))->firstWhere('key', self::KEY);
        $this->assertNotNull($row, 'Access Control must list the page');
        $this->assertSame('hr', $row['category']);
        $this->assertSame('اعتماد نقاط المتطوعين', $row['label_ar']);
        $this->assertSame(self::ROUTE, $row['primary_route']);
        $this->assertSame('ADMIN_ONLY', $row['risk_level']);
        $this->assertFalse($row['delegatable']);
    }

    // ── Defaults: hr_manager and root only ───────────────────────────────────

    public function test_only_hr_manager_has_the_page_in_the_role_default_map(): void
    {
        $roles = [];
        foreach (RoleDefaultMap::all() as $role => $keys) {
            if (in_array(self::KEY, $keys, true)) {
                $roles[] = $role;
            }
        }

        $this->assertSame(['hr_manager'], $roles);
    }

    public function test_hr_manager_keeps_access_to_the_page_after_the_sync_and_root_always_has_it(): void
    {
        $this->artisan('access:sync-role-defaults', ['--apply' => true])->assertExitCode(0);

        $this->assertTrue($this->canOpen($this->user('hr_manager')));
        $this->assertTrue($this->canOpen($this->user('super_admin')));
        $this->assertTrue($this->canOpen($this->user('tech_admin')));
    }

    public function test_root_does_not_depend_on_the_sync_but_hr_manager_does(): void
    {
        // Deployment note, pinned on purpose: before access:sync-role-defaults runs for the new key, root is
        // unaffected, and hr_manager needs the scoped sync (see the PR description) to open the page.
        $this->assertTrue($this->canOpen($this->user('super_admin')));
        $this->assertFalse($this->canOpen($this->user('hr_manager')));
    }

    public function test_the_page_is_not_granted_to_any_other_role_by_default(): void
    {
        $this->artisan('access:sync-role-defaults', ['--apply' => true])->assertExitCode(0);

        foreach (Role::query()->pluck('name') as $slug) {
            if (in_array($slug, ['hr_manager', 'super_admin', 'tech_admin'], true)) {
                continue;
            }

            $this->assertFalse($this->canOpen($this->user($slug)), "{$slug} must not open the approval page by default");
        }
    }

    public function test_a_guest_or_plain_student_cannot_open_it(): void
    {
        $this->artisan('access:sync-role-defaults', ['--apply' => true])->assertExitCode(0);

        $this->assertFalse($this->canOpen($this->user('student')));
        $this->assertFalse($this->canOpen($this->user('volunteer')));
        $this->assertFalse($this->canOpen($this->user('instructor')));
    }

    // ── The scoped production sync ───────────────────────────────────────────

    public function test_the_scoped_sync_inserts_exactly_one_row_for_hr_manager(): void
    {
        $this->artisan('access:sync-role-defaults', ['--page' => self::KEY])->assertExitCode(0);
        $this->assertSame(0, RolePageDefault::count(), 'dry-run writes nothing');

        $this->artisan('access:sync-role-defaults', ['--apply' => true, '--page' => self::KEY])->assertExitCode(0);

        $rows = RolePageDefault::query()->where('page_key', self::KEY)->get();
        $this->assertSame(1, RolePageDefault::count(), 'a scoped apply must not touch any other page');
        $this->assertCount(1, $rows);
        $this->assertSame('hr_manager', Role::find($rows->first()->role_id)->name);

        $this->assertTrue($this->canOpen($this->user('hr_manager')));
        $this->assertFalse($this->canOpen($this->user('finance_manager')));
    }

    public function test_the_scoped_sync_is_idempotent(): void
    {
        $this->artisan('access:sync-role-defaults', ['--apply' => true, '--page' => self::KEY])->assertExitCode(0);
        $this->artisan('access:sync-role-defaults', ['--apply' => true, '--page' => self::KEY])->assertExitCode(0);

        $this->assertSame(1, RolePageDefault::count());
    }

    // ── Overrides respect the same rules as every other page ─────────────────

    public function test_an_explicit_deny_removes_the_page_from_hr_manager_and_cannot_touch_root(): void
    {
        $this->artisan('access:sync-role-defaults', ['--apply' => true])->assertExitCode(0);
        $root = $this->user('super_admin');
        $hr = $this->user('hr_manager');

        app(UserPageAccessOverrideService::class)->setOverride($root, $hr, self::KEY, UserPageAccessState::Deny);

        $this->assertFalse($this->canOpen($hr->fresh()));
        $this->assertTrue($this->canOpen($root->fresh()));
    }

    // ── Opening the page grants no business right ────────────────────────────

    public function test_opening_the_page_does_not_let_a_user_approve_or_reject(): void
    {
        $this->artisan('access:sync-role-defaults', ['--apply' => true])->assertExitCode(0);
        $root = $this->user('super_admin');
        $instructor = $this->user('instructor');
        $requester = $this->user('hr_manager');
        $volunteer = $this->user('volunteer');

        app(UserPageAccessOverrideService::class)->setOverride($root, $instructor, self::KEY, UserPageAccessState::Allow);
        $this->assertTrue($this->canOpen($instructor->fresh()), 'the page itself opens through the override');

        $activity = VolunteerPointActivity::where('type', 'fixed')->firstOrFail();
        $pointRequest = VolunteerPointRequest::create([
            'user_id' => $volunteer->id,
            'requester_id' => $requester->id,
            'activity_id' => $activity->id,
            'category_key' => $activity->category_key,
            'activity_name' => $activity->name_ar,
            'points_requested' => (int) $activity->points_min,
            'reason' => 'اختبار صلاحيات',
            'status' => 'pending',
        ]);

        $base = "/api/operations/volunteer-points/requests/{$pointRequest->id}";
        $this->actingAs($instructor)->postJson("{$base}/approve", ['approved_points' => (int) $activity->points_min])->assertForbidden();
        $this->actingAs($instructor)->postJson("{$base}/reject", ['rejection_reason' => 'لا'])->assertForbidden();

        $this->assertSame('pending', $pointRequest->fresh()->status);
    }

    public function test_hr_manager_can_still_approve_after_the_change(): void
    {
        $this->artisan('access:sync-role-defaults', ['--apply' => true])->assertExitCode(0);
        $hr = $this->user('hr_manager');
        $requester = $this->user('hr_manager');
        $volunteer = $this->user('volunteer');
        $activity = VolunteerPointActivity::where('type', 'fixed')->firstOrFail();
        $pointRequest = VolunteerPointRequest::create([
            'user_id' => $volunteer->id,
            'requester_id' => $requester->id,
            'activity_id' => $activity->id,
            'category_key' => $activity->category_key,
            'activity_name' => $activity->name_ar,
            'points_requested' => (int) $activity->points_min,
            'reason' => 'اختبار صلاحيات',
            'status' => 'pending',
        ]);

        $this->actingAs($hr)
            ->postJson("/api/operations/volunteer-points/requests/{$pointRequest->id}/reject", ['rejection_reason' => 'غير مكتمل'])
            ->assertSuccessful();
    }
}
