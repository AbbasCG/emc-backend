<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\FinancialRequest;
use App\Models\HrRequest;
use App\Models\Meeting;
use App\Models\MeetingReport;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePageDefault;
use App\Models\Task;
use App\Models\TeamProfile;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserPageAccessOverride;
use App\Models\WeeklyDepartmentReport;
use App\Services\EffectivePageAccessService;
use App\Support\PageAccess\PageAccessCatalog;
use App\Support\PageAccess\PageAccessRiskLevel;
use App\Support\PageAccess\RoleDefaultMap;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * TECH ADMIN: cross-department dashboard viewer.
 *
 * A read-only monitoring page. Two layers, deliberately separate and both proven here:
 *   PAGE ACCESS   (catalog key technical_system.department_dashboards) opens the page and
 *                 shows the sidebar entry; it grants no data.
 *   AUTHORIZATION (permission tech.department_dashboards.view) is what the API checks.
 * Nothing in the feature looks at a role name.
 */
class TechDepartmentDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'technical_system.department_dashboards';
    private const URL = '/api/tech/department-dashboards';

    protected function setUp(): void
    {
        parent::setUp();

        // The state production is in after the seeders have run.
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function user(string $role): User
    {
        Role::firstOrCreate(['name' => $role], ['display_name' => $role, 'is_system' => false]);

        return User::factory()->create(['role' => $role, 'is_active' => true]);
    }

    private function dept(string $slug, string $status = 'active', ?User $leader = null): Department
    {
        return Department::create([
            'name' => $slug, 'name_ar' => 'إدارة '.$slug, 'slug' => $slug,
            'status' => $status, 'is_active' => $status === 'active', 'leader_id' => $leader?->id,
        ]);
    }

    private function pageAllowed(User $u): bool
    {
        return app(EffectivePageAccessService::class)->resolveAll($u)[self::KEY]->allowed;
    }

    /** All the data shapes a department can own, each with sensitive content that must never be returned. */
    private function populate(Department $d, User $by): void
    {
        $profile = fn ($active) => TeamProfile::create(['department_id' => $d->id, 'name_ar' => 'SECRET-MEMBER-NAME', 'position_ar' => 'عضو', 'email' => 'secret.member@example.com', 'is_active' => $active]);
        $profile(true);
        $profile(true);
        $profile(false);

        Task::forceCreate(['title' => 'SECRET-TASK-TITLE', 'description' => 'SECRET-TASK-DESC', 'department_id' => $d->id, 'created_by' => $by->id, 'status' => 'in_progress', 'due_date' => today()->addDays(3)]);
        Task::forceCreate(['title' => 'SECRET-OVERDUE', 'department_id' => $d->id, 'created_by' => $by->id, 'status' => 'idea', 'due_date' => today()->subDays(2)]);
        Task::forceCreate(['title' => 'done', 'department_id' => $d->id, 'created_by' => $by->id, 'status' => 'completed']);

        $this->ticket($d, 'PENDING_APPROVAL', now()->subDays(5));
        $this->ticket($d, 'IN_PROGRESS');
        $this->ticket($d, 'RESOLVED');

        FinancialRequest::forceCreate(['requester_id' => $by->id, 'department_id' => $d->id, 'title' => 'SECRET-FIN-TITLE', 'description' => 'SECRET-FIN-DESC', 'request_type' => 'purchase', 'amount' => 98765.43, 'status' => 'submitted', 'finance_note' => 'SECRET-FIN-NOTE']);
        FinancialRequest::forceCreate(['requester_id' => $by->id, 'department_id' => $d->id, 'title' => 'draft', 'request_type' => 'purchase', 'amount' => 5, 'status' => 'draft']);
        HrRequest::forceCreate(['department_id' => $d->id, 'skills_and_specs' => 'SECRET-HR-SPECS', 'notes' => 'SECRET-HR-NOTES', 'status' => 'pending', 'created_by' => $by->id]);

        Meeting::forceCreate(['title' => 'SECRET-MEETING', 'department_id' => $d->id, 'organizer_id' => $by->id, 'meeting_date' => today()->addDays(2), 'start_time' => '10:00', 'status' => 'scheduled']);
        MeetingReport::forceCreate(['department_id' => $d->id, 'meeting_date' => today()->subDays(3), 'achieved' => 'SECRET-ACHIEVED', 'created_by' => $by->id]);
        WeeklyDepartmentReport::forceCreate(['department_id' => $d->id, 'week_start' => CarbonImmutable::now()->startOfWeek(CarbonImmutable::MONDAY)->toDateString(), 'achievements' => 'SECRET-WEEKLY', 'planned' => 'x', 'submitted_by' => $by->id, 'submitted_at' => now()]);
    }

    private function ticket(Department $d, string $status, $createdAt = null): void
    {
        $t = Ticket::forceCreate([
            'ticket_number' => 'T-'.uniqid(), 'title' => 'SECRET-TICKET-TITLE', 'description' => 'SECRET-TICKET-DESC',
            'department_id' => $d->id, 'created_by_name' => 'SECRET-REPORTER', 'created_by_email' => 'secret.reporter@example.com',
            'status' => $status, 'internal_notes' => 'SECRET-INTERNAL',
        ]);
        if ($createdAt) {
            $t->forceFill(['created_at' => $createdAt])->save();
        }
    }

    private function card(array $json, string $key)
    {
        foreach ($json['data']['summary']['cards'] as $c) {
            if ($c['key'] === $key) {
                return $c['value'];
            }
        }
        $this->fail("card {$key} missing");
    }

    private function metric(array $json, string $section, string $key)
    {
        foreach ($json['data']['sections'] as $s) {
            if ($s['key'] === $section) {
                foreach ($s['metrics'] as $m) {
                    if ($m['key'] === $key) {
                        return $m['value'];
                    }
                }
            }
        }
        $this->fail("metric {$section}.{$key} missing");
    }

    // ═══ 1. Who can call the API ═════════════════════════════════════════════

    public function test_unauthenticated_requests_are_refused(): void
    {
        $d = $this->dept('a');

        $this->getJson(self::URL)->assertUnauthorized();
        $this->getJson(self::URL.'/'.$d->id)->assertUnauthorized();
    }

    public function test_the_permission_exists_is_registered_and_is_not_a_role_name(): void
    {
        $this->assertSame('tech.department_dashboards.view', Permissions::TECH_DEPARTMENT_DASHBOARDS_VIEW);
        $this->assertContains(Permissions::TECH_DEPARTMENT_DASHBOARDS_VIEW, Permissions::all());
        $this->assertNotNull(Permission::where('name', Permissions::TECH_DEPARTMENT_DASHBOARDS_VIEW)->first());
        $this->assertTrue(\Illuminate\Support\Facades\Gate::has(Permissions::TECH_DEPARTMENT_DASHBOARDS_VIEW));
    }

    public function test_the_roles_that_hold_the_permission_can_use_the_api(): void
    {
        $this->dept('a');

        foreach (['tech_admin', 'super_admin', 'admin', 'executive_admin'] as $role) {
            $u = $this->user($role);
            $this->assertTrue($u->fresh()->hasPermission(Permissions::TECH_DEPARTMENT_DASHBOARDS_VIEW), "{$role} should hold the permission");
            Sanctum::actingAs($u);
            $this->getJson(self::URL)->assertOk()->assertJsonPath('success', true);
        }
    }

    public function test_every_other_role_is_refused_even_with_page_access_defaults(): void
    {
        $d = $this->dept('a');

        foreach (['student', 'instructor', 'volunteer', 'partner', 'support_agent', 'quality_manager', 'finance_manager', 'hr_manager', 'operations_manager', 'department_manager', 'programs_manager', 'marketing_manager'] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->getJson(self::URL)->assertForbidden();
            $this->getJson(self::URL.'/'.$d->id)->assertForbidden();
        }
    }

    public function test_a_department_leader_is_not_a_tech_viewer(): void
    {
        $leader = $this->user('department_manager');
        $d = $this->dept('a', 'active', $leader);

        Sanctum::actingAs($leader);

        $this->getJson(self::URL)->assertForbidden();
        $this->getJson(self::URL.'/'.$d->id)->assertForbidden();
    }

    // ═══ 2. Access comes only through the permission / page-access system ════

    public function test_a_custom_role_is_refused_until_it_is_granted_the_permission(): void
    {
        $this->dept('a');
        $role = Role::create(['name' => 'tech_observer', 'display_name' => 'tech_observer', 'is_system' => false]);
        $u = User::factory()->create(['role' => 'tech_observer', 'is_active' => true]);
        Sanctum::actingAs($u);

        $this->getJson(self::URL)->assertForbidden();

        $role->grantPermission(Permissions::TECH_DEPARTMENT_DASHBOARDS_VIEW);

        // A new request resolves permissions afresh (the per-instance cache is per request in production).
        Sanctum::actingAs($u->fresh());
        $this->getJson(self::URL)->assertOk();
    }

    public function test_page_access_alone_never_returns_data(): void
    {
        $this->dept('a');
        $u = $this->user('support_agent');
        $admin = $this->user('super_admin');
        UserPageAccessOverride::create(['user_id' => $u->id, 'page_key' => self::KEY, 'state' => 'allow', 'granted_by' => $admin->id]);
        Sanctum::actingAs($u);

        $this->assertTrue($this->pageAllowed($u), 'the page is open to them');
        $this->getJson(self::URL)->assertForbidden();
    }

    public function test_the_permission_alone_does_not_make_the_page_visible(): void
    {
        $role = Role::create(['name' => 'tech_observer', 'display_name' => 'tech_observer', 'is_system' => false]);
        $role->grantPermission(Permissions::TECH_DEPARTMENT_DASHBOARDS_VIEW);
        $u = User::factory()->create(['role' => 'tech_observer', 'is_active' => true]);

        // Sidebar and route guard follow page access, which is a separate layer.
        $this->assertFalse($this->pageAllowed($u));
    }

    public function test_page_access_and_permission_together_give_a_custom_role_the_page_and_the_data(): void
    {
        $this->dept('a');
        $role = Role::create(['name' => 'tech_observer', 'display_name' => 'tech_observer', 'is_system' => false]);
        $role->grantPermission(Permissions::TECH_DEPARTMENT_DASHBOARDS_VIEW);
        RolePageDefault::create(['role_id' => $role->id, 'page_key' => self::KEY, 'created_by' => $this->user('super_admin')->id]);
        $u = User::factory()->create(['role' => 'tech_observer', 'is_active' => true]);
        Sanctum::actingAs($u);

        $this->assertTrue($this->pageAllowed($u));
        $this->getJson(self::URL)->assertOk();
    }

    public function test_a_user_deny_override_hides_the_page_without_touching_the_permission(): void
    {
        $admin = $this->user('admin');
        UserPageAccessOverride::create(['user_id' => $admin->id, 'page_key' => self::KEY, 'state' => 'deny', 'granted_by' => $this->user('super_admin')->id]);
        $this->seedRoleDefaults();

        $this->assertFalse($this->pageAllowed($admin->fresh()));
    }

    public function test_page_access_follows_the_role_defaults_and_the_catalog(): void
    {
        $this->seedRoleDefaults();

        foreach (['admin', 'executive_admin', 'tech_admin', 'super_admin'] as $role) {
            $this->assertTrue($this->pageAllowed($this->user($role)), "{$role} should see the page");
        }
        foreach (['student', 'instructor', 'volunteer', 'support_agent', 'quality_manager', 'finance_manager', 'hr_manager', 'department_manager', 'operations_manager'] as $role) {
            $this->assertFalse($this->pageAllowed($this->user($role)), "{$role} must not see the page");
        }
    }

    public function test_the_manifest_the_sidebar_reads_lists_the_page_only_for_those_who_may_open_it(): void
    {
        $this->seedRoleDefaults();

        Sanctum::actingAs($this->user('student'));
        $this->assertNotContains(self::KEY, $this->getJson('/api/auth/me/page-access')->assertOk()->json('data.allowed_keys'));

        Sanctum::actingAs($this->user('tech_admin'));
        $res = $this->getJson('/api/auth/me/page-access')->assertOk();
        $this->assertContains(self::KEY, $res->json('data.allowed_keys'));
        $page = $res->json('data.pages')[self::KEY];
        $this->assertTrue($page['allowed']);
        $this->assertSame('root_authority', $page['primary_source']);
        $this->assertContains('/dashboard/tech-admin/department-dashboards', $page['route_patterns']);
    }

    public function test_the_catalog_entry_is_admin_only_and_not_delegatable_or_protected(): void
    {
        $e = PageAccessCatalog::find(self::KEY);

        $this->assertNotNull($e);
        $this->assertSame('/dashboard/tech-admin/department-dashboards', $e->primaryRoute);
        $this->assertSame('لوحات الإدارات', $e->labelAr);
        $this->assertSame(PageAccessRiskLevel::AdminOnly, $e->riskLevel);
        $this->assertFalse($e->delegatable);
        $this->assertFalse($e->authenticatedBaseline);
        $this->assertSame(Permissions::TECH_DEPARTMENT_DASHBOARDS_VIEW, $e->requiredPermission);
    }

    public function test_role_defaults_cover_exactly_admin_and_executive_admin(): void
    {
        $holders = array_keys(array_filter(RoleDefaultMap::all(), fn ($keys) => in_array(self::KEY, $keys, true)));
        sort($holders);

        $this->assertSame(['admin', 'executive_admin'], $holders);
    }

    // ═══ 3. Department list ══════════════════════════════════════════════════

    public function test_only_active_departments_are_listed(): void
    {
        $leader = $this->user('department_manager');
        $a = $this->dept('alpha', 'active', $leader);
        $b = $this->dept('beta');
        $this->dept('gamma', 'archived');
        $this->dept('delta', 'inactive');
        Sanctum::actingAs($this->user('tech_admin'));

        $res = $this->getJson(self::URL)->assertOk();
        $ids = collect($res->json('data.departments'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids);
        $this->assertNull($res->json('data.selected_department'));
        $row = collect($res->json('data.departments'))->firstWhere('id', $a->id);
        $this->assertSame(['id', 'name', 'slug', 'status', 'leader'], array_keys($row));
        $this->assertSame(['id' => $leader->id, 'name' => $leader->name], $row['leader']);
        $this->assertNull(collect($res->json('data.departments'))->firstWhere('id', $b->id)['leader']);
    }

    public function test_an_empty_platform_returns_an_empty_list_not_an_error(): void
    {
        Sanctum::actingAs($this->user('tech_admin'));

        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.departments', []);
    }

    public function test_archived_and_unknown_departments_are_the_same_404(): void
    {
        $archived = $this->dept('old', 'archived');
        Sanctum::actingAs($this->user('tech_admin'));

        $a = $this->getJson(self::URL.'/'.$archived->id)->assertNotFound();
        $b = $this->getJson(self::URL.'/999999')->assertNotFound();
        $this->assertSame($a->json(), $b->json());
        $this->getJson(self::URL.'/abc')->assertNotFound();
    }

    // ═══ 4. The summary ══════════════════════════════════════════════════════

    public function test_the_summary_reports_real_counts_for_the_selected_department_only(): void
    {
        $by = $this->user('admin');
        $d = $this->dept('alpha', 'active', $this->user('department_manager'));
        $other = $this->dept('beta');
        $this->populate($d, $by);
        $this->populate($other, $by);
        $this->ticket($other, 'IN_PROGRESS');
        Sanctum::actingAs($this->user('tech_admin'));

        $json = $this->getJson(self::URL.'/'.$d->id)->assertOk()->json();

        $this->assertSame($d->id, $json['data']['selected_department']['id']);
        $this->assertSame(2, $this->metric($json, 'members', 'active_members'));
        $this->assertSame(3, $this->metric($json, 'members', 'total_members'));
        $this->assertSame(2, $this->metric($json, 'operations', 'open_tasks'));
        $this->assertSame(1, $this->metric($json, 'operations', 'overdue_tasks'));
        $this->assertSame(1, $this->metric($json, 'operations', 'upcoming_meetings'));
        $this->assertSame(2, $this->metric($json, 'support', 'open_tickets'));
        $this->assertSame(1, $this->metric($json, 'support', 'stale_pending_tickets'));
        $this->assertSame(1, $this->metric($json, 'requests', 'pending_financial_requests'));
        $this->assertSame(1, $this->metric($json, 'requests', 'pending_hr_requests'));
        $this->assertSame(1, $this->metric($json, 'reports', 'meeting_reports_30d'));
        $this->assertSame('مُسلَّم', $this->metric($json, 'reports', 'weekly_report_submitted'));
        // open tasks 2 + open tickets 2 + financial 1 + hr 1 + volunteers 0
        $this->assertSame(6, $this->card($json, 'open_items'));
        $this->assertSame(2, $this->card($json, 'pending_tasks'));
        $this->assertSame(2, $this->card($json, 'active_tickets'));
    }

    public function test_the_payload_has_the_documented_shape(): void
    {
        $d = $this->dept('alpha');
        Sanctum::actingAs($this->user('tech_admin'));

        $data = $this->getJson(self::URL.'/'.$d->id)->assertOk()->json('data');

        foreach (['departments', 'selected_department', 'summary', 'modules', 'sections', 'warnings', 'links', 'diagnostics', 'capabilities'] as $k) {
            $this->assertArrayHasKey($k, $data);
        }
        $this->assertSame(['can_view' => true, 'can_refresh' => true, 'can_view_sensitive' => false, 'read_only' => true], $data['capabilities']);
        $this->assertSame('overview', $data['modules'][0]['key']);
        foreach (['generated_at', 'last_activity_at', 'data_source', 'stale', 'sections_failed'] as $k) {
            $this->assertArrayHasKey($k, $data['diagnostics']);
        }
        $this->assertSame('organizational_departments.department_overview', $data['links'][0]['page_key']);
    }

    public function test_a_department_with_no_leader_and_no_data_does_not_crash_and_says_what_is_missing(): void
    {
        $d = $this->dept('empty');
        Sanctum::actingAs($this->user('tech_admin'));

        $json = $this->getJson(self::URL.'/'.$d->id)->assertOk()->json();

        $this->assertNull($json['data']['selected_department']['leader']);
        $this->assertSame(0, $this->card($json, 'open_items'));
        $this->assertNull($json['data']['diagnostics']['last_activity_at']);
        $codes = array_column($json['data']['warnings'], 'code');
        $this->assertContains('no_leader', $codes);
        $this->assertContains('no_active_members', $codes);
        $this->assertContains('weekly_report_missing', $codes);
        $this->assertSame(0, $json['data']['diagnostics']['sections_failed']);
    }

    public function test_overdue_work_raises_a_warning(): void
    {
        $by = $this->user('admin');
        $d = $this->dept('alpha');
        $this->populate($d, $by);
        Sanctum::actingAs($this->user('tech_admin'));

        $codes = array_column($this->getJson(self::URL.'/'.$d->id)->json('data.warnings'), 'code');

        $this->assertContains('overdue_tasks', $codes);
        $this->assertContains('stale_tickets', $codes);
    }

    public function test_one_failing_section_degrades_to_a_warning_instead_of_failing_the_page(): void
    {
        $d = $this->dept('alpha');
        Schema::drop('hr_requests');
        Sanctum::actingAs($this->user('tech_admin'));

        $json = $this->getJson(self::URL.'/'.$d->id)->assertOk()->json();

        $this->assertSame(1, $json['data']['diagnostics']['sections_failed']);
        $failed = collect($json['data']['modules'])->firstWhere('key', 'requests');
        $this->assertFalse($failed['available']);
        $this->assertContains('section_requests_failed', array_column($json['data']['warnings'], 'code'));
        $this->assertSame(0, $this->metric($json, 'members', 'active_members'));
    }

    // ═══ 5. Sensitive data ═══════════════════════════════════════════════════

    public function test_no_sensitive_content_is_ever_returned(): void
    {
        $by = $this->user('admin');
        $leader = $this->user('department_manager');
        $d = $this->dept('alpha', 'active', $leader);
        $this->populate($d, $by);
        Sanctum::actingAs($this->user('tech_admin'));

        $body = $this->get(self::URL.'/'.$d->id, ['Accept' => 'application/json'])->assertOk()->getContent();
        $list = $this->get(self::URL, ['Accept' => 'application/json'])->assertOk()->getContent();
        $decoded = json_encode(json_decode($body, true), JSON_UNESCAPED_UNICODE);

        foreach (['SECRET-', '98765', 'secret.member', 'secret.reporter', $leader->email, $by->email] as $needle) {
            $this->assertStringNotContainsString($needle, $decoded, "leaked: {$needle}");
            $this->assertStringNotContainsString($needle, $list, "leaked in list: {$needle}");
        }
        foreach (['"email"', '"phone"', '"amount"', '"description"', '"title"', '"notes"', '"password"', '"token"'] as $field) {
            $this->assertStringNotContainsString($field, $decoded, "sensitive field {$field}");
        }
    }

    // ═══ 6. Read-only, no impersonation, no role change ══════════════════════

    public function test_the_endpoints_accept_no_writes(): void
    {
        $d = $this->dept('alpha');
        Sanctum::actingAs($this->user('super_admin'));

        foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $verb) {
            $this->{$verb}(self::URL)->assertStatus(405);
            $this->{$verb}(self::URL.'/'.$d->id)->assertStatus(405);
        }
    }

    public function test_viewing_a_department_changes_nothing_and_never_switches_identity(): void
    {
        $by = $this->user('admin');
        $d = $this->dept('alpha');
        $this->populate($d, $by);
        $viewer = $this->user('tech_admin');
        Sanctum::actingAs($viewer);
        $before = [Task::count(), Ticket::count(), FinancialRequest::count(), User::count(), \App\Models\AuditLog::count()];

        $res = $this->getJson(self::URL.'/'.$d->id)->assertOk();

        $this->assertSame($before, [Task::count(), Ticket::count(), FinancialRequest::count(), User::count(), \App\Models\AuditLog::count()]);
        $this->assertSame('tech_admin', $viewer->fresh()->role);
        $this->assertArrayNotHasKey('token', $res->json('data'));
        $this->assertArrayNotHasKey('user', $res->json('data'));
        $this->getJson('/api/auth/me')->assertJsonPath('data.user.id', $viewer->id)->assertJsonPath('data.role', 'tech_admin');
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** Production state after `access:sync-role-defaults --apply`. */
    private function seedRoleDefaults(): void
    {
        $actor = $this->user('super_admin');
        foreach (RoleDefaultMap::all() as $roleName => $keys) {
            $role = Role::firstOrCreate(['name' => $roleName], ['display_name' => $roleName, 'is_system' => false]);
            foreach ($keys as $key) {
                RolePageDefault::firstOrCreate(['role_id' => $role->id, 'page_key' => $key], ['created_by' => $actor->id]);
            }
        }
    }
}
