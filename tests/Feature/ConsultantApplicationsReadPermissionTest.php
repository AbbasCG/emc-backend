<?php

namespace Tests\Feature;

use App\Models\ConsultantApplication;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\ImpersonatePermissionSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\GrantsTicketReviewPermissions;
use Tests\TestCase;

/**
 * Reading the consultant applications (list + details) is gated by role (admin tier) OR the explicit permission
 * consultant_applications.view. Page access (organizational_departments.consultant_applications) only opens the page.
 * Approve / reject (PUT .../status) stays admin-tier only, whatever the read permission.
 */
class ConsultantApplicationsReadPermissionTest extends TestCase
{
    use GrantsTicketReviewPermissions;
    use RefreshDatabase;

    private const KEY = 'consultant_applications.view';

    private const PAGE = 'organizational_departments.consultant_applications';

    private const SPA = [
        'Origin' => 'http://localhost:5173',
        'Referer' => 'http://localhost:5173/login',
        'X-EMC-Auth-Mode' => 'cookie',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, ImpersonatePermissionSeeder::class]);
    }

    private function user(string $role, array $over = []): User
    {
        return User::factory()->create(array_merge(['role' => $role, 'is_active' => true, 'password' => bcrypt('secret123')], $over));
    }

    private function application(array $over = []): ConsultantApplication
    {
        return ConsultantApplication::create(array_merge([
            'full_name' => 'Dr Layla', 'email' => 'layla@example.test', 'specialty' => 'Education', 'status' => 'new',
        ], $over));
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    // ═══ The permission row ═════════════════════════════════════════════════

    public function test_the_permission_is_registered_with_arabic_label_grouped_and_granted_to_no_role_by_default(): void
    {
        $row = Permission::where('name', self::KEY)->firstOrFail();
        $this->assertSame('عرض طلبات الاستشاريين', $row->label_ar);
        $this->assertNotEmpty($row->description_ar);
        $this->assertSame('hr', $row->group);
        $this->assertSame(0, $row->roles()->count(), 'the migration grants nothing to any role');
        $this->assertSame(0, \DB::table('user_permission_assignments')->where('permission_id', $row->id)->count());
        $this->assertContains(self::KEY, Permissions::all(), 'root (super_admin / tech_admin) holds it like users.analytics.view');
        $this->assertNotContains(self::KEY, Permissions::forRole('hr_manager'));
        $this->assertNotContains(self::KEY, Permissions::forRole('operations_manager'));

        Sanctum::actingAs($this->user('super_admin'));
        $modules = $this->getJson('/api/admin/permissions')->assertOk()->json('data.modules');
        $hr = collect($modules)->firstWhere('group', 'hr');
        $this->assertSame('الموارد البشرية', $hr['group_label']);
        $found = collect($hr['permissions'])->firstWhere('key', self::KEY);
        $this->assertSame('عرض طلبات الاستشاريين', $found['label_ar']);
    }

    public function test_it_is_grantable_through_the_role_editor_and_the_role_then_reads(): void
    {
        $this->application();
        $hr = $this->user('hr_manager');
        $role = Role::where('name', 'hr_manager')->firstOrFail();
        $existing = $role->permissions()->pluck('name')->all();

        Sanctum::actingAs($this->user('super_admin'));
        $this->putJson("/api/admin/roles/{$role->id}/permissions", ['permissions' => array_merge($existing, [self::KEY])])->assertOk();

        Sanctum::actingAs($hr->fresh());
        $this->getJson('/api/admin/consultant-applications')->assertOk()->assertJsonPath('data.total', 1);
    }

    // ═══ Page access alone does not open the data ═══════════════════════════

    public function test_hr_manager_without_the_permission_is_refused_on_list_and_details_even_with_a_page_access_override(): void
    {
        $app = $this->application();
        $hr = $this->user('hr_manager');

        Sanctum::actingAs($hr);
        $this->getJson('/api/admin/consultant-applications')->assertForbidden();
        $this->getJson("/api/admin/consultant-applications/{$app->id}")->assertForbidden();
        $this->getJson('/api/admin/consultant-applications/999999')->assertForbidden(); // 403 before binding: no id oracle

        Sanctum::actingAs($this->user('super_admin'));
        $this->putJson("/api/admin/users/{$hr->id}/page-access-overrides", ['allow' => [self::PAGE], 'deny' => []])->assertOk();

        Sanctum::actingAs($hr->fresh());
        $this->getJson('/api/admin/consultant-applications')->assertForbidden();
        $this->getJson("/api/admin/consultant-applications/{$app->id}")->assertForbidden();
    }

    // ═══ With the permission ════════════════════════════════════════════════

    public function test_hr_manager_with_a_role_grant_reads_list_filters_counts_and_details(): void
    {
        $a = $this->application(['full_name' => 'Alpha Consultant', 'status' => 'new']);
        $this->application(['full_name' => 'Beta Person', 'email' => 'beta@example.test', 'status' => 'accepted']);
        $this->grantToRole('hr_manager', self::KEY);
        $hr = $this->user('hr_manager');

        Sanctum::actingAs($hr);
        $list = $this->getJson('/api/admin/consultant-applications')->assertOk();
        $list->assertJsonPath('data.total', 2)->assertJsonPath('meta.counts.new', 1)->assertJsonPath('meta.counts.accepted', 1);
        $this->getJson('/api/admin/consultant-applications?search=Alpha')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/admin/consultant-applications?status=accepted')->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.full_name', 'Beta Person');
        $this->getJson("/api/admin/consultant-applications/{$a->id}")->assertOk()
            ->assertJsonPath('data.full_name', 'Alpha Consultant')->assertJsonStructure(['data' => ['id', 'email', 'status', 'cv_view_url']]);
    }

    public function test_per_user_allow_through_the_action_permissions_endpoint_works_and_a_per_user_deny_wins_over_the_role(): void
    {
        $this->application();
        $hr = $this->user('hr_manager');
        $other = $this->user('hr_manager');
        $super = $this->user('super_admin');

        Sanctum::actingAs($super);
        $this->postJson("/api/admin/access/users/{$hr->id}/action-permissions/".self::KEY.'/grant')->assertOk();

        Sanctum::actingAs($hr->fresh());
        $this->getJson('/api/admin/consultant-applications')->assertOk();
        Sanctum::actingAs($other->fresh());
        $this->getJson('/api/admin/consultant-applications')->assertForbidden(); // same role, no grant

        // role grant + a direct deny for one person: the deny wins
        $this->grantToRole('hr_manager', self::KEY);
        $this->assignPermission($other, self::KEY, 'deny');
        Sanctum::actingAs($other->fresh());
        $this->getJson('/api/admin/consultant-applications')->assertForbidden();
    }

    public function test_a_plain_member_holding_the_permission_reads_it_is_a_permission_not_a_role(): void
    {
        $this->application();
        $member = $this->user('volunteer');
        $this->assignPermission($member, self::KEY, 'allow');

        Sanctum::actingAs($member->fresh());
        $this->getJson('/api/admin/consultant-applications')->assertOk();
    }

    // ═══ Write stays admin-tier ═════════════════════════════════════════════

    public function test_the_read_permission_never_allows_changing_the_status(): void
    {
        $app = $this->application();
        $this->grantToRole('hr_manager', self::KEY);
        $hr = $this->user('hr_manager');
        $this->assignPermission($hr, self::KEY, 'allow');

        Sanctum::actingAs($hr->fresh());
        $this->getJson("/api/admin/consultant-applications/{$app->id}")->assertOk();
        $this->putJson("/api/admin/consultant-applications/{$app->id}/status", ['status' => 'accepted'])->assertForbidden();
        $this->assertSame('new', $app->fresh()->status);

        Sanctum::actingAs($this->user('operations_manager'));
        $this->putJson("/api/admin/consultant-applications/{$app->id}/status", ['status' => 'accepted'])->assertOk();
        $this->assertSame('accepted', $app->fresh()->status);
    }

    public function test_the_admin_tier_is_unchanged(): void
    {
        $app = $this->application();

        foreach (['admin', 'super_admin', 'tech_admin', 'operations_manager'] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->getJson('/api/admin/consultant-applications')->assertOk()->assertJsonPath('data.total', 1);
            $this->getJson("/api/admin/consultant-applications/{$app->id}")->assertOk();
        }

        foreach (['student', 'volunteer', 'partner'] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->getJson('/api/admin/consultant-applications')->assertForbidden();
        }
    }

    public function test_guests_are_unauthenticated(): void
    {
        $this->getJson('/api/admin/consultant-applications')->assertUnauthorized();
    }

    // ═══ Preview (impersonation) behaves like the target ════════════════════

    public function test_an_impersonation_token_preview_follows_the_targets_permission(): void
    {
        $this->application();
        $super = $this->user('super_admin');
        $withIt = $this->user('hr_manager');
        $without = $this->user('hr_manager');
        $this->assignPermission($withIt, self::KEY, 'allow');

        $login = $super->createToken('api-token')->plainTextToken;

        $imp = $this->as($login)->postJson("/api/admin/impersonate/{$withIt->id}")->assertOk()->json('token');
        $this->as($imp)->getJson('/api/admin/consultant-applications')->assertOk()->assertJsonPath('data.total', 1);
        $this->as($imp)->getJson('/api/admin/consultant-applications/'.ConsultantApplication::first()->id)->assertOk();
        $this->as($imp)->putJson('/api/admin/consultant-applications/'.ConsultantApplication::first()->id.'/status', ['status' => 'accepted'])->assertForbidden();
        $this->as($imp)->postJson('/api/admin/impersonate/stop')->assertOk();

        $imp2 = $this->as($login)->postJson("/api/admin/impersonate/{$without->id}")->assertOk()->json('token');
        $this->as($imp2)->getJson('/api/admin/consultant-applications')->assertForbidden()
            ->assertJsonMissingPath('code'); // a plain permission refusal, not token_scope_denied / impersonation_forbidden
        $this->as($imp2)->postJson('/api/admin/impersonate/stop')->assertOk();
    }

    public function test_a_cookie_session_preview_follows_the_targets_permission(): void
    {
        $this->application();
        $super = $this->user('super_admin');
        $withIt = $this->user('hr_manager');
        $without = $this->user('hr_manager');
        $this->assignPermission($withIt, self::KEY, 'allow');

        $this->withHeaders(self::SPA)->postJson('/api/auth/login', ['email' => $super->email, 'password' => 'secret123'])->assertOk();

        $this->withHeaders(self::SPA)->postJson("/api/admin/impersonate/{$withIt->id}")->assertOk()->assertJsonPath('token', null);
        $this->app['auth']->forgetGuards();
        $this->withHeaders(self::SPA)->getJson('/api/admin/consultant-applications')->assertOk()->assertJsonPath('data.total', 1);
        $this->withHeaders(self::SPA)->postJson('/api/admin/impersonate/stop')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withHeaders(self::SPA)->postJson("/api/admin/impersonate/{$without->id}")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(self::SPA)->getJson('/api/admin/consultant-applications')->assertForbidden();
    }
}
