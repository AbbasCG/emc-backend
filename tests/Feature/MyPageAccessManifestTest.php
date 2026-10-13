<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Role;
use App\Models\RolePageDefault;
use App\Models\TeamMember;
use App\Models\TeamProfile;
use App\Models\User;
use App\Models\UserPageAccessOverride;
use App\Services\DepartmentPageDefaultService;
use App\Support\PageAccess\DepartmentPageAudience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/auth/me/page-access is what the sidebar AND the route guard read.
 *
 * The sidebar used to be a static per-role menu that was only ever trimmed, so a page
 * granted outside the role's usual set opened by URL but never appeared in the menu.
 * The frontend now builds the menu from this manifest, so the manifest has to carry
 * every layer (role, department, user allow/deny, baseline) and the navigation metadata
 * needed to place a page it did not know about. These tests pin both.
 */
class MyPageAccessManifestTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/auth/me/page-access';
    private const HR = 'hr.my_requests';

    private function user(string $role): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role, 'is_system' => false]);

        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function manifest(User $u): array
    {
        Sanctum::actingAs($u);

        return $this->getJson(self::URL)->assertOk()->json('data');
    }

    private function allowed(User $u, string $key): bool
    {
        return in_array($key, $this->manifest($u)['allowed_keys'], true);
    }

    public function test_unauthenticated_requests_cannot_read_a_manifest(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_programs_manager_has_the_hr_requests_page_and_it_carries_navigation_metadata(): void
    {
        $data = $this->manifest($this->user('programs_manager'));
        $row = $data['pages'][self::HR];

        $this->assertTrue($row['allowed']);
        $this->assertContains('authenticated_baseline', $row['sources']);
        $this->assertSame('/dashboard/hr/my-requests', $row['primary_route']);
        $this->assertContains('/dashboard/hr/my-requests', $row['route_patterns']);
        $this->assertSame('طلباتي', $row['label_ar']);
        $this->assertSame('hr', $row['category']);
        $this->assertSame('الموارد البشرية', $row['category_label_ar']);
        $this->assertSame('SAFE_DELEGATABLE', $row['risk_level']);
    }

    public function test_every_row_carries_the_metadata_the_sidebar_needs(): void
    {
        $data = $this->manifest($this->user('student'));

        foreach ($data['pages'] as $key => $row) {
            foreach (['primary_route', 'route_patterns', 'label_ar', 'category', 'category_label_ar', 'sources', 'allowed'] as $field) {
                $this->assertArrayHasKey($field, $row, "{$key} lacks {$field}");
            }
            $this->assertNotEmpty($row['category_label_ar'], "{$key} has no category label");
        }
    }

    public function test_an_explicit_user_allow_is_in_the_manifest_and_a_plain_user_does_not_have_it(): void
    {
        $granter = $this->user('super_admin');
        $target = $this->user('programs_manager');
        $other = $this->user('programs_manager');

        $this->assertFalse($this->allowed($target, 'hr.volunteers'));

        UserPageAccessOverride::create(['user_id' => $target->id, 'page_key' => 'hr.volunteers', 'state' => 'allow', 'granted_by' => $granter->id]);

        $this->assertTrue($this->allowed($target->fresh(), 'hr.volunteers'));
        $this->assertContains('user_allow', $this->manifest($target->fresh())['pages']['hr.volunteers']['sources']);
        $this->assertFalse($this->allowed($other->fresh(), 'hr.volunteers'), 'a grant to one user never reaches another');
    }

    public function test_an_explicit_user_deny_removes_even_a_baseline_page(): void
    {
        $granter = $this->user('super_admin');
        $target = $this->user('programs_manager');
        UserPageAccessOverride::create(['user_id' => $target->id, 'page_key' => self::HR, 'state' => 'deny', 'granted_by' => $granter->id]);

        $data = $this->manifest($target->fresh());

        $this->assertNotContains(self::HR, $data['allowed_keys']);
        $this->assertFalse($data['pages'][self::HR]['allowed']);
        $this->assertSame('deny', $data['pages'][self::HR]['override_state']);
    }

    public function test_a_change_made_by_the_admin_shows_on_the_very_next_request(): void
    {
        $granter = $this->user('super_admin');
        $target = $this->user('programs_manager');
        $this->assertFalse($this->allowed($target, 'hr.volunteers'));

        $row = UserPageAccessOverride::create(['user_id' => $target->id, 'page_key' => 'hr.volunteers', 'state' => 'allow', 'granted_by' => $granter->id]);
        $this->assertTrue($this->allowed($target->fresh(), 'hr.volunteers'), 'a grant needs no re-login');

        $row->delete();
        $this->assertFalse($this->allowed($target->fresh(), 'hr.volunteers'), 'a revoke needs no re-login either');
    }

    public function test_a_role_default_is_in_the_manifest(): void
    {
        $granter = $this->user('super_admin');
        $role = Role::create(['name' => 'ops_viewer', 'display_name' => 'ops_viewer', 'is_system' => false]);
        $u = User::factory()->create(['role' => 'ops_viewer', 'is_active' => true]);
        $this->assertFalse($this->allowed($u, 'hr.volunteers'));

        RolePageDefault::create(['role_id' => $role->id, 'page_key' => 'hr.volunteers', 'created_by' => $granter->id]);

        $this->assertTrue($this->allowed($u->fresh(), 'hr.volunteers'));
        $this->assertContains('role', $this->manifest($u->fresh())['pages']['hr.volunteers']['sources']);
    }

    public function test_a_department_default_is_in_the_manifest_for_members_only(): void
    {
        $granter = $this->user('super_admin');
        $dept = Department::create(['name' => 'd', 'name_ar' => 'd', 'slug' => 'd', 'status' => 'active', 'is_active' => true]);
        app(DepartmentPageDefaultService::class)->replaceDefaults($dept, DepartmentPageAudience::Member, ['hr.volunteers'], $granter);

        $member = $this->user('volunteer');
        $outsider = $this->user('volunteer');
        $profile = TeamProfile::create(['department_id' => $dept->id, 'user_id' => $member->id, 'name_ar' => 'm', 'position_ar' => 'عضو', 'is_active' => true]);
        TeamMember::create(['user_id' => $member->id, 'team_profile_id' => $profile->id, 'department_id' => $dept->id, 'status' => 'active']);

        $this->assertTrue($this->allowed($member->fresh(), 'hr.volunteers'));
        $this->assertContains('department_member', $this->manifest($member->fresh())['pages']['hr.volunteers']['sources']);
        $this->assertFalse($this->allowed($outsider->fresh(), 'hr.volunteers'));
    }

    public function test_root_authority_is_reported_as_such_so_it_is_not_mistaken_for_a_grant(): void
    {
        $row = $this->manifest($this->user('tech_admin'))['pages']['hr.volunteers'];

        $this->assertTrue($row['allowed']);
        $this->assertSame(['root_authority'], $row['sources']);
    }

    public function test_a_page_the_user_has_no_source_for_is_not_allowed(): void
    {
        $data = $this->manifest($this->user('student'));

        $this->assertNotContains('administration.users', $data['allowed_keys']);
        $this->assertFalse($data['pages']['administration.users']['allowed']);
        $this->assertSame([], $data['pages']['administration.users']['sources']);
    }
}
