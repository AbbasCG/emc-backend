<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserPermissionAssignment;
use App\Support\PermissionGovernance;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\GrantsTicketReviewPermissions;
use Tests\TestCase;

/**
 * Access Control Center support: per-user ACTION permissions (grant / revoke / sources), role membership counts and lists,
 * and the permission holders audit. Ticket review is exercised through the real ticket API.
 */
class AccessControlActionPermissionsTest extends TestCase
{
    use GrantsTicketReviewPermissions;
    use RefreshDatabase;

    private const PART = 'tickets.review_participated';

    private const REVIEW = 'tickets.review_completion';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function user(string $role = 'student', array $over = []): User
    {
        return User::factory()->create(array_merge(['role' => $role, 'is_active' => true], $over));
    }

    private function ordinaryPermission(string $name = Permissions::VIEW_USERS, string $label = 'عرض المستخدمين'): Permission
    {
        return Permission::firstOrCreate(['name' => $name], ['display_name' => $name, 'label_ar' => $label, 'group' => 'users', 'description_ar' => 'وصف']);
    }

    private function url(User $u, string $suffix = ''): string
    {
        return "/api/admin/access/users/{$u->id}/action-permissions{$suffix}";
    }

    private function row(array $json, string $key): array
    {
        foreach ($json['data']['groups'] as $g) {
            foreach ($g['permissions'] as $p) {
                if ($p['key'] === $key) {
                    return $p;
                }
            }
        }
        $this->fail("permission {$key} not in the response");
    }

    private function listRow(User $actor, User $target, string $key): array
    {
        Sanctum::actingAs($actor);

        return $this->row($this->getJson($this->url($target))->assertOk()->json(), $key);
    }

    // ═══ List shape ══════════════════════════════════════════════════════════

    public function test_list_shape_groups_tickets_first_and_sources_of_root_and_explicit_only_keys(): void
    {
        $this->ordinaryPermission();
        $superAdmin = $this->user('super_admin');
        $target = $this->user('tech_admin');

        Sanctum::actingAs($superAdmin);
        $json = $this->getJson($this->url($target))->assertOk()->json();

        $this->assertTrue($json['success']);
        $this->assertSame(['id' => $target->id, 'name' => $target->name, 'email' => $target->email, 'role' => 'tech_admin', 'is_active' => true], $json['data']['user']);
        $this->assertSame('tickets', $json['data']['groups'][0]['key']);
        $this->assertSame('التذاكر', $json['data']['groups'][0]['label_ar']);

        $review = $this->row($json, self::REVIEW);
        $this->assertTrue($review['effective']);
        $this->assertSame('system', $review['source']);
        $this->assertSame('system', $review['sources'][0]['type']);
        $this->assertTrue($review['role_grants']);
        $this->assertTrue($review['still_effective_after_revoke']);
        $this->assertFalse($review['explicit_only']);
        $this->assertNull($review['direct_state']);
        $this->assertNull($review['granted_by']);

        $part = $this->row($json, self::PART);
        $this->assertFalse($part['effective']);
        $this->assertSame('none', $part['source']);
        $this->assertSame([], $part['sources']);
        $this->assertTrue($part['explicit_only']);
        $this->assertFalse($part['role_grants']);
        $this->assertFalse($part['still_effective_after_revoke']);
        $this->assertTrue($part['can_grant']);
        $this->assertFalse($part['can_revoke']);
        $this->assertSame('اعتماد التذاكر التي أشارك فيها', $part['label_ar']);

        // tech_admin sees the same list, but cannot change the participation key (view-only for it), while ordinary keys are changeable.
        $tech = $this->user('tech_admin');
        $student = $this->user('student');
        $row = $this->listRow($tech, $target, self::PART);
        $this->assertFalse($row['can_grant']);
        $this->assertFalse($this->listRow($tech, $target, Permissions::VIEW_USERS)['can_grant'], 'F4: a tech_admin target is super_admin-only');
        $this->assertTrue($this->listRow($tech, $student, Permissions::VIEW_USERS)['can_grant']);
    }

    public function test_only_super_admin_and_tech_admin_reach_the_endpoints(): void
    {
        $target = $this->user('student');
        foreach (['student', 'admin', 'programs_manager'] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->getJson($this->url($target))->assertForbidden();
            $this->postJson($this->url($target, '/'.self::PART.'/grant'))->assertForbidden();
            $this->getJson('/api/admin/permissions/'.self::PART.'/holders')->assertForbidden();
        }
    }

    // ═══ Grant / revoke ══════════════════════════════════════════════════════

    public function test_direct_grant_reaches_only_that_user_not_another_with_the_same_role_and_revoke_removes_it_with_audit(): void
    {
        $superAdmin = $this->user('super_admin');
        $a = $this->user('tech_admin');
        $b = $this->user('tech_admin');

        Sanctum::actingAs($superAdmin);
        $res = $this->postJson($this->url($a, '/'.self::PART.'/grant'))->assertOk();
        $this->assertTrue($res->json('data.effective'));
        $this->assertSame('direct', $res->json('data.source'));
        $this->assertSame('allow', $res->json('data.direct_state'));
        $this->assertSame(['id' => $superAdmin->id, 'name' => $superAdmin->name], $res->json('data.granted_by'));
        $this->assertSame('direct_allow', $res->json('data.sources.0.type'));
        $this->assertFalse($res->json('data.still_effective_after_revoke'), 'nothing else grants it, so revoking removes it');
        $this->assertSame($res->json('data.key'), $res->json('data.permission.key'));
        $this->assertSame($a->id, $res->json('data.user.id'));

        $this->assertTrue($a->fresh()->hasPermission(self::PART));
        $this->assertFalse($b->fresh()->hasPermission(self::PART), 'same role, no grant');
        $this->assertFalse($this->listRow($superAdmin, $b, self::PART)['effective']);
        $this->assertTrue($this->listRow($superAdmin, $a, self::PART)['effective']);

        $log = ActivityLog::where('action', 'user_permission_granted')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($superAdmin->id, (int) $log->user_id);
        $this->assertSame([self::PART], $log->new_values['added']);
        $this->assertSame([self::PART], $log->new_values['guarded']);
        $this->assertSame($a->id, $log->new_values['target_user_id']);

        $res = $this->postJson($this->url($a, '/'.self::PART.'/revoke'))->assertOk();
        $this->assertFalse($res->json('data.effective'));
        $this->assertNull($res->json('data.direct_state'));
        $this->assertSame(0, UserPermissionAssignment::where('user_id', $a->id)->count());
        $this->assertFalse($a->fresh()->hasPermission(self::PART));

        $log = ActivityLog::where('action', 'user_permission_revoked')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame([self::PART], $log->new_values['removed']);
    }

    public function test_role_grant_is_reported_and_still_effective_after_revoke_and_a_direct_deny_overrides_it(): void
    {
        $perm = $this->ordinaryPermission();
        $this->grantToRole('instructor', $perm->name);
        $superAdmin = $this->user('super_admin');
        $u = $this->user('instructor');

        $row = $this->listRow($superAdmin, $u, $perm->name);
        $this->assertTrue($row['effective']);
        $this->assertSame('role', $row['source']);
        $this->assertTrue($row['role_grants']);
        $this->assertTrue($row['still_effective_after_revoke']);

        Sanctum::actingAs($superAdmin);
        $res = $this->postJson($this->url($u, "/{$perm->name}/grant"))->assertOk();
        $this->assertSame(['role', 'direct_allow'], array_column($res->json('data.sources'), 'type'));
        $this->assertSame('role', $res->json('data.source'));
        $this->assertTrue($res->json('data.still_effective_after_revoke'));

        // A per-user deny wins over the role; revoking the deny restores the role grant.
        $this->assignPermission($u, $perm->name, 'deny');
        $row = $this->listRow($superAdmin, $u, $perm->name);
        $this->assertFalse($row['effective']);
        $this->assertSame('none', $row['source']);
        $this->assertSame('deny', $row['direct_state']);
        $this->assertContains('direct_deny', array_column($row['sources'], 'type'));
        $this->assertTrue($row['still_effective_after_revoke']);
        $res = $this->postJson($this->url($u, "/{$perm->name}/revoke"))->assertOk();
        $this->assertTrue($res->json('data.effective'));
        $this->assertSame('role', $res->json('data.source'));
    }

    public function test_governance_tech_admin_refused_on_participation_super_admin_allowed_other_rules(): void
    {
        $perm = $this->ordinaryPermission();
        $tech = $this->user('tech_admin');
        $target = $this->user('student');

        Sanctum::actingAs($tech);
        $this->postJson($this->url($target, '/'.self::PART.'/grant'))->assertForbidden()->assertJsonPath('message', \App\Support\PermissionGovernance::MESSAGE);
        $this->postJson($this->url($target, '/'.self::PART.'/revoke'))->assertForbidden();
        $this->assertSame(0, UserPermissionAssignment::count());
        $this->assertSame(0, ActivityLog::where('action', 'user_permission_granted')->count());

        // Ordinary key: tech_admin may (existing delegation rules), audited.
        $this->postJson($this->url($target, "/{$perm->name}/grant"))->assertOk()->assertJsonPath('data.effective', true);
        $this->assertSame(1, ActivityLog::where('action', 'user_permission_granted')->count());

        // Never one's own direct grants.
        $this->postJson($this->url($tech, "/{$perm->name}/grant"))->assertForbidden();

        // F4: accounts of the top roles are only editable by a super_admin.
        foreach (['super_admin', 'tech_admin', 'admin', 'executive_admin'] as $role) {
            $this->postJson($this->url($this->user($role), "/{$perm->name}/grant"))->assertForbidden();
        }

        // Unknown key, bad expiry.
        $this->postJson($this->url($target, '/no.such_permission/grant'))->assertNotFound()->assertJsonPath('success', false);
        Sanctum::actingAs($this->user('super_admin'));
        $this->postJson($this->url($target, "/{$perm->name}/grant"), ['expires_at' => now()->subDay()->toDateTimeString()])->assertStatus(422);
    }

    public function test_every_explicit_only_key_is_super_admin_only_to_grant_and_to_revoke(): void
    {
        $target = $this->user('student');
        $tech = $this->user('tech_admin');
        $root = $this->user('super_admin');
        foreach ([self::PART, Permissions::EXECUTIVE_COMMAND_CENTER_VIEW, 'future.explicit_only_key'] as $key) {
            Permission::firstOrCreate(['name' => $key], ['display_name' => $key, 'label_ar' => $key, 'group' => 'system']);
            $this->assignPermission($target, $key); // an existing grant to try to revoke
            Sanctum::actingAs($tech);
            $this->postJson($this->url($target, "/{$key}/grant"))->assertForbidden();
            $this->postJson($this->url($target, "/{$key}/revoke"))->assertForbidden();
            $this->assertSame(1, UserPermissionAssignment::where('user_id', $target->id)->whereHas('permission', fn ($q) => $q->where('name', $key))->count(), $key);
            $this->assertFalse($this->listRow($tech, $target, $key)['can_revoke'], $key);
        }
        Sanctum::actingAs($root);
        $this->assertTrue(PermissionGovernance::mayChange($root, [Permissions::EXECUTIVE_COMMAND_CENTER_VIEW]));
        $this->assertFalse(PermissionGovernance::mayChange($tech, [Permissions::EXECUTIVE_COMMAND_CENTER_VIEW]));

        // users.impersonate keeps its approved holder rule: a tech_admin that does NOT hold it is refused, one that ACTUALLY holds it may delegate it.
        $this->assertTrue(PermissionGovernance::mayChange($tech, [Permissions::IMPERSONATE_USERS]));
        Permission::firstOrCreate(['name' => Permissions::IMPERSONATE_USERS], ['display_name' => 'imp', 'label_ar' => 'imp', 'group' => 'users']);
        $other = $this->user('student');
        Sanctum::actingAs($tech);
        $this->postJson($this->url($other, '/'.Permissions::IMPERSONATE_USERS.'/grant'))->assertForbidden();
        $holder = $this->user('tech_admin');
        $this->assignPermission($holder, Permissions::IMPERSONATE_USERS);
        Sanctum::actingAs($holder->fresh());
        $this->postJson($this->url($other, '/'.Permissions::IMPERSONATE_USERS.'/grant'))->assertOk()->assertJsonPath('data.effective', true);
        Sanctum::actingAs($root);
        $this->postJson($this->url($target, '/'.Permissions::EXECUTIVE_COMMAND_CENTER_VIEW.'/revoke'))->assertOk();
        $this->assertSame(0, UserPermissionAssignment::where('user_id', $target->id)->whereHas('permission', fn ($q) => $q->where('name', Permissions::EXECUTIVE_COMMAND_CENTER_VIEW))->count());
    }

    public function test_rows_of_a_pair_are_collapsed_and_deny_or_delegation_rows_need_a_super_admin(): void
    {
        $perm = $this->ordinaryPermission();
        $tech = $this->user('tech_admin');
        $root = $this->user('super_admin');
        $target = $this->user('student');
        $dept = Department::create(['name' => 'D '.Str::random(5), 'name_ar' => 'إدارة', 'slug' => 'd-'.Str::random(8), 'status' => 'active', 'is_active' => true]);
        $delegation = fn (string $state = 'allow') => UserPermissionAssignment::create([
            'user_id' => $target->id, 'permission_id' => $perm->id, 'state' => $state, 'scope_type' => 'department', 'scope_id' => $dept->id,
            'source_type' => 'delegation', 'granted_by' => $root->id, 'expires_at' => now()->addDay(),
        ]);

        // A department-scoped delegation is not overwritten by a tech_admin (409), nor revoked (403); nothing changes.
        $delegation();
        Sanctum::actingAs($tech);
        $this->postJson($this->url($target, "/{$perm->name}/grant"))->assertStatus(409)->assertJsonPath('code', 'DELEGATION_CONFLICT');
        $this->postJson($this->url($target, "/{$perm->name}/revoke"))->assertForbidden();
        $this->assertSame(1, UserPermissionAssignment::where('user_id', $target->id)->count());
        $row = $this->listRow($tech, $target, $perm->name);
        $this->assertFalse($row['can_grant']);
        $this->assertFalse($row['can_revoke']);

        // A super_admin collapses every row of the pair into one global direct_admin allow, and the audit keeps what was replaced.
        $delegation('deny');
        Sanctum::actingAs($root);
        $this->postJson($this->url($target, "/{$perm->name}/grant"))->assertOk()->assertJsonPath('data.direct_state', 'allow');
        $rows = UserPermissionAssignment::where('user_id', $target->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(['allow', 'global', 'direct_admin', $root->id], [$rows[0]->state, $rows[0]->scope_type, $rows[0]->source_type, (int) $rows[0]->granted_by]);
        $log = ActivityLog::where('action', 'user_permission_granted')->latest('id')->first();
        $previous = $log->new_values['previous_rows'];
        $this->assertCount(2, $previous);
        $this->assertSame(['delegation', 'department', $dept->id], [$previous[0]['source_type'], $previous[0]['scope_type'], $previous[0]['scope_id']]);
        $this->assertNotNull($previous[0]['expires_at']);
        $this->assertSame($root->id, $previous[0]['granted_by']);

        // A deny row (even a plain one) is revoked by a super_admin only; revoke deletes ALL rows of the pair.
        UserPermissionAssignment::query()->update(['state' => 'deny']);
        $delegation();
        Sanctum::actingAs($tech);
        $this->postJson($this->url($target, "/{$perm->name}/revoke"))->assertForbidden();
        $this->assertSame(2, UserPermissionAssignment::where('user_id', $target->id)->count());
        Sanctum::actingAs($root);
        $this->postJson($this->url($target, "/{$perm->name}/revoke"))->assertOk();
        $this->assertSame(0, UserPermissionAssignment::where('user_id', $target->id)->count());
        $log = ActivityLog::where('action', 'user_permission_revoked')->latest('id')->first();
        $this->assertCount(2, $log->new_values['previous_rows']);
        $this->assertSame('deny', $log->new_values['previous_rows'][0]['state']);
    }

    public function test_expiry_is_recorded_and_an_expired_assignment_no_longer_counts(): void
    {
        $perm = $this->ordinaryPermission();
        $superAdmin = $this->user('super_admin');
        $u = $this->user('student');

        Sanctum::actingAs($superAdmin);
        $res = $this->postJson($this->url($u, "/{$perm->name}/grant"), ['expires_at' => now()->addDays(3)->toDateTimeString()])->assertOk();
        $this->assertNotNull($res->json('data.direct_expires_at'));
        $this->assertTrue($res->json('data.effective'));

        UserPermissionAssignment::where('user_id', $u->id)->update(['expires_at' => now()->subMinute()]);
        $row = $this->listRow($superAdmin, $u, $perm->name);
        $this->assertFalse($row['effective']);
        $this->assertNull($row['direct_state']);
        $this->assertSame($u->fresh()->hasPermission($perm->name), $row['effective']);
    }

    // ═══ Search ══════════════════════════════════════════════════════════════

    public function test_search_by_arabic_label_by_key_and_arabic_accent_insensitive_and_group_filter(): void
    {
        $this->ordinaryPermission();
        Sanctum::actingAs($this->user('super_admin'));
        $target = $this->user('student');
        $keys = fn (array $json) => collect($json['data']['groups'])->flatMap(fn ($g) => array_column($g['permissions'], 'key'))->all();

        $byLabel = $this->getJson($this->url($target).'?search='.urlencode('التي أشارك'))->assertOk()->json();
        $this->assertSame([self::PART], $keys($byLabel));

        $byKey = $this->getJson($this->url($target).'?search=REVIEW_PARTICIPATED')->assertOk()->json();
        $this->assertSame([self::PART], $keys($byKey));

        // ة / ه folded: «مراجعه» finds «مراجعة واعتماد إنجاز التذاكر».
        $folded = $this->getJson($this->url($target).'?search='.urlencode('مراجعه'))->assertOk()->json();
        $this->assertContains(self::REVIEW, $keys($folded));

        $this->assertSame([], $this->getJson($this->url($target).'?search=zzzzzz')->assertOk()->json('data.groups'));

        $group = $this->getJson($this->url($target).'?group=users')->assertOk()->json();
        $this->assertSame(['users'], array_column($group['data']['groups'], 'key'));
    }

    // ═══ Effective preview parity ═══════════════════════════════════════════

    public function test_effective_page_access_preview_carries_action_permissions_equal_to_has_permission(): void
    {
        $perm = $this->ordinaryPermission();
        $this->grantToRole('instructor', $perm->name);
        $target = $this->user('tech_admin');
        $this->assignPermission($target, self::PART);
        $this->assignPermission($target, self::REVIEW, 'deny');
        $other = $this->user('instructor');

        Sanctum::actingAs($this->user('super_admin'));
        foreach ([$target, $other] as $u) {
            $json = $this->getJson("/api/admin/users/{$u->id}/effective-page-access")->assertOk()->json();
            $this->assertArrayHasKey('allowed_keys', $json['data'], 'existing keys intact');
            $this->assertArrayHasKey('pages', $json['data']);
            $rows = collect($json['data']['action_permissions']);
            $this->assertNotEmpty($rows);
            $fresh = $u->fresh();
            foreach ($rows as $r) {
                $this->assertSame($fresh->hasPermission($r['key']), $r['effective'], $r['key']);
                $this->assertArrayHasKey('sources', $r);
            }
        }
        $json = $this->getJson("/api/admin/users/{$target->id}/effective-page-access")->json();
        $this->assertTrue(collect($json['data']['action_permissions'])->firstWhere('key', self::PART)['effective']);
        $this->assertFalse(collect($json['data']['action_permissions'])->firstWhere('key', self::REVIEW)['effective']);
    }

    // ═══ End to end through the ticket API ══════════════════════════════════

    private function ticketFor(Department $dept, array $over): Ticket
    {
        $reporter = $this->user('student');

        return Ticket::create(array_merge([
            'ticket_number' => 'EMC-TCK-A-'.Str::random(8), 'title' => 'تذكرة', 'description' => 'وصف', 'ticket_category' => 'OLD_ISSUE', 'priority' => 'MEDIUM',
            'department_id' => $dept->id, 'created_by_id' => $reporter->id, 'created_by_name' => 'مبلّغ', 'status' => 'PENDING_REVIEW',
            'accepted_at' => now()->subHour(), 'task_scope' => 'simple', 'task_points' => 30, 'estimated_hours' => 2, 'resolution_summary' => 'تم',
        ], $over));
    }

    public function test_granting_participation_makes_approve_completion_available_to_that_user_only_and_page_access_alone_is_refused(): void
    {
        $dept = Department::create(['name' => 'D '.Str::random(5), 'name_ar' => 'إدارة', 'slug' => 'd-'.Str::random(8), 'status' => 'active', 'is_active' => true]);
        $superAdmin = $this->user('super_admin');
        $a = $this->user('tech_admin');
        $b = $this->user('tech_admin');
        $mineA = $this->ticketFor($dept, ['assigned_to_id' => $a->id]);
        $mineB = $this->ticketFor($dept, ['assigned_to_id' => $b->id]);
        $show = function (Ticket $t, User $as) {
            Sanctum::actingAs($as->fresh()); // a new request loads the user afresh (no per-instance permission cache from an earlier call)

            return $this->getJson("/api/v1/tickets/{$t->id}");
        };

        // Before: the assignee is refused (review_completion alone is not enough on one's own ticket).
        $this->assertFalse($show($mineA, $a)->json('data.capabilities.approve_completion'));
        Sanctum::actingAs($a->fresh());
        $this->postJson("/api/v1/tickets/{$mineA->id}/approve-completion", ['actual_minutes' => 60])->assertForbidden()->assertJsonPath('code', 'PARTICIPATION_PERMISSION_REQUIRED');

        // The grant goes through the Access Control API.
        Sanctum::actingAs($superAdmin);
        $this->postJson($this->url($a, '/'.self::PART.'/grant'))->assertOk();

        $this->assertTrue($show($mineA, $a)->json('data.capabilities.approve_completion'));
        $this->assertFalse($show($mineB, $b)->json('data.capabilities.approve_completion'), 'another tech_admin is unaffected');
        Sanctum::actingAs($b->fresh());
        $this->postJson("/api/v1/tickets/{$mineB->id}/approve-completion", ['actual_minutes' => 60])->assertForbidden()->assertJsonPath('code', 'PARTICIPATION_PERMISSION_REQUIRED');
        Sanctum::actingAs($a->fresh());
        $this->postJson("/api/v1/tickets/{$mineA->id}/approve-completion", ['actual_minutes' => 60])->assertOk()->assertJsonPath('data.status', 'RESOLVED');

        // A user who merely has page access (a department leader without the review permission) is refused with REVIEW_PERMISSION_REQUIRED.
        $leader = $this->user('programs_manager');
        $dept->update(['leader_id' => $leader->id]);
        $other = $this->ticketFor($dept, []);
        $this->assertFalse($show($other, $leader)->json('data.capabilities.approve_completion'));
        Sanctum::actingAs($leader);
        $this->postJson("/api/v1/tickets/{$other->id}/approve-completion", ['actual_minutes' => 60])->assertForbidden()->assertJsonPath('code', 'REVIEW_PERMISSION_REQUIRED');

        // Revoking takes the capability away again.
        Sanctum::actingAs($superAdmin);
        $this->postJson($this->url($a, '/'.self::PART.'/revoke'))->assertOk();
        $again = $this->ticketFor($dept, ['assigned_to_id' => $a->id]);
        $this->assertFalse($show($again, $a)->json('data.capabilities.approve_completion'));
    }

    // ═══ Role membership ════════════════════════════════════════════════════

    private function role(string $name): Role
    {
        return Role::firstOrCreate(['name' => $name], ['display_name' => $name, 'is_system' => true]);
    }

    private function roleRow(string $name, ?User $actor = null): array
    {
        Sanctum::actingAs($actor ?? $this->user('super_admin'));

        return collect($this->getJson('/api/admin/roles')->assertOk()->json('data'))->firstWhere('name', $name);
    }

    public function test_tech_admin_role_count_is_not_zero_and_equals_the_members_list_total(): void
    {
        $this->role('tech_admin');
        $this->role('student');
        // 25 alphabetically-earlier accounts push the tech admin off the first page of /admin/users, which is what the page counted from.
        for ($i = 0; $i < 25; $i++) {
            $this->user('student', ['name' => sprintf('Aaa %02d', $i)]);
        }
        $tech = $this->user('tech_admin', ['name' => 'Zzz Tech']);

        Sanctum::actingAs($this->user('super_admin', ['name' => 'Mmm Root']));
        $page1 = $this->getJson('/api/admin/users?status=all')->assertOk();
        $this->assertCount(20, $page1->json('data'));
        $this->assertNotContains($tech->id, array_column($page1->json('data'), 'id'), 'root cause: the client tally only ever saw page 1');

        $row = $this->roleRow('tech_admin');
        $this->assertSame(1, $row['users_count']);
        $this->assertSame(25, $this->roleRow('student')['users_count']);

        $list = $this->getJson('/api/admin/roles/tech_admin/users')->assertOk()->json('data');
        $this->assertSame(1, $list['total']);
        $this->assertSame([['id' => $tech->id, 'name' => 'Zzz Tech', 'email' => $tech->email, 'is_active' => true, 'role_source' => 'primary']], $list['items']);

        $this->assertSame(1, $this->getJson('/api/admin/roles/tech_admin')->assertOk()->json('data.users_count'));
    }

    public function test_role_membership_excludes_soft_deleted_dedupes_includes_inactive_and_paginates_and_searches(): void
    {
        $this->role('instructor');
        $this->role('finance_manager');
        $this->role('department_manager');

        $deleted = $this->user('instructor', ['name' => 'Deleted One']);
        $deleted->delete();
        $inactive = $this->user('instructor', ['name' => 'Inactive Two', 'is_active' => false, 'email' => 'inactive.two@example.test']);
        $teacher = $this->user('teacher', ['name' => 'Teacher Three']);
        $this->user('instructor', ['name' => 'Plain Four']);

        // A department_manager leading the active "finance" department acts as finance_manager: counted once there (specialized) and
        // once as department_manager (primary); a real finance_manager is primary; nobody is counted twice within one role.
        $dm = $this->user('department_manager', ['name' => 'Leader Five']);
        Department::create(['name' => 'Finance', 'name_ar' => 'المالية', 'slug' => 'finance', 'status' => 'active', 'is_active' => true, 'leader_id' => $dm->id]);
        // Leading two departments that map to the same role is still one membership row.
        $this->role('ai_manager');
        $dm3 = $this->user('department_manager', ['name' => 'Leader Eight']);
        Department::create(['name' => 'AI1', 'name_ar' => 'ذكاء١', 'slug' => 'ai-transformation', 'status' => 'active', 'is_active' => true, 'leader_id' => $dm3->id]);
        Department::create(['name' => 'AI2', 'name_ar' => 'ذكاء٢', 'slug' => 'ai-digital-transformation', 'status' => 'active', 'is_active' => true, 'leader_id' => $dm3->id]);
        $fm = $this->user('finance_manager', ['name' => 'Fin Six']);
        // An INACTIVE department confers nothing.
        $dm2 = $this->user('department_manager', ['name' => 'Leader Seven']);
        $this->role('advisors_manager');
        Department::create(['name' => 'Adv', 'name_ar' => 'x', 'slug' => 'advisors', 'status' => 'archived', 'is_active' => false, 'leader_id' => $dm2->id]);

        $root = $this->user('super_admin');
        $this->assertSame(3, $this->roleRow('instructor', $root)['users_count'], 'soft-deleted excluded, teacher alias + inactive included');
        $fmRow = $this->roleRow('finance_manager', $root);
        $this->assertSame(2, $fmRow['users_count']);
        $this->assertSame(3, $this->roleRow('department_manager', $root)['users_count']);
        $this->assertSame(1, $this->roleRow('ai_manager', $root)['users_count'], 'two departments, one membership');
        $this->assertSame(0, $this->roleRow('advisors_manager', $root)['users_count'], 'an inactive department confers nothing');

        foreach (['instructor', 'finance_manager', 'department_manager', 'ai_manager'] as $name) {
            $list = $this->getJson("/api/admin/roles/{$name}/users?per_page=100")->assertOk()->json('data');
            $count = $this->roleRow($name, $root)['users_count'];
            $this->assertSame($count, $list['total'], $name);
            $ids = array_column($list['items'], 'id');
            $this->assertSame(array_values(array_unique($ids)), $ids, "{$name}: de-duplicated");
            $this->assertCount($count, $ids);
        }

        $fin = $this->getJson('/api/admin/roles/finance_manager/users')->json('data.items');
        $this->assertEqualsCanonicalizing([[$dm->id, 'specialized'], [$fm->id, 'primary']], array_map(fn ($i) => [$i['id'], $i['role_source']], $fin));
        $this->assertSame(['Fin Six', 'Leader Five'], array_column($fin, 'name'), 'stable name order');

        $inst = $this->getJson('/api/admin/roles/instructor/users')->json('data.items');
        $this->assertNotContains($deleted->id, array_column($inst, 'id'));
        $this->assertContains($teacher->id, array_column($inst, 'id'));
        $this->assertFalse(collect($inst)->firstWhere('id', $inactive->id)['is_active']);

        // Search by name / email, pagination metadata.
        $this->assertSame(['Teacher Three'], array_column($this->getJson('/api/admin/roles/instructor/users?search=Three')->json('data.items'), 'name'));
        $this->assertSame([$inactive->id], array_column($this->getJson('/api/admin/roles/instructor/users?search=inactive.two')->json('data.items'), 'id'));
        $p = $this->getJson('/api/admin/roles/instructor/users?per_page=2&page=2')->assertOk()->json('data');
        $this->assertSame(3, $p['total']);
        $this->assertSame(2, $p['page']);
        $this->assertSame(2, $p['per_page']);
        $this->assertSame(2, $p['last_page']);
        $this->assertCount(1, $p['items']);

        // Members list is admin-tier only (operations_manager can reach the roles group, not the members).
        Sanctum::actingAs($this->user('operations_manager'));
        $this->getJson('/api/admin/roles/instructor/users')->assertForbidden();
    }

    // ═══ Holders ═════════════════════════════════════════════════════════════

    public function test_holders_for_the_explicit_only_participation_key(): void
    {
        $this->role('super_admin');
        $this->role('tech_admin');
        $superAdmin = $this->user('super_admin', ['name' => 'Root One']);
        $a = $this->user('tech_admin', ['name' => 'Tech A']);
        $b = $this->user('tech_admin', ['name' => 'Tech B']);
        $c = $this->user('student', ['name' => 'Student C']);
        $denied = $this->user('student', ['name' => 'Student D']);

        Sanctum::actingAs($superAdmin);
        $this->postJson($this->url($a, '/'.self::PART.'/grant'))->assertOk();
        $this->postJson($this->url($c, '/'.self::PART.'/grant'), ['expires_at' => now()->addWeek()->toDateTimeString()])->assertOk();
        $this->assignPermission($denied, self::PART, 'deny');

        $json = $this->getJson('/api/admin/permissions/'.self::PART.'/holders')->assertOk()->json('data');
        $this->assertSame(self::PART, $json['permission']['key']);
        $this->assertTrue($json['permission']['explicit_only']);
        $this->assertSame('tickets', $json['permission']['group']);
        $this->assertSame([], $json['roles'], 'no role grants it: not even through all()');

        $this->assertSame(3, $json['direct_users']['total']);
        $states = collect($json['direct_users']['items'])->pluck('state', 'name')->all();
        $this->assertSame(['Student C' => 'allow', 'Student D' => 'deny', 'Tech A' => 'allow'], $states);
        $this->assertSame($superAdmin->id, collect($json['direct_users']['items'])->firstWhere('name', 'Tech A')['granted_by']['id']);

        $eff = $json['effective_users'];
        $this->assertSame(2, $eff['total']);
        $this->assertSame(['Student C', 'Tech A'], array_column($eff['items'], 'name'));
        $this->assertSame('direct_allow', $eff['items'][0]['sources'][0]['type']);
        $this->assertNotContains($b->id, array_column($eff['items'], 'id'));
    }

    public function test_holders_for_an_all_permission_matches_has_permission_dedupes_and_pages(): void
    {
        $this->role('super_admin');
        $this->role('tech_admin');
        $s1 = $this->user('super_admin', ['name' => 'S1']);
        $t1 = $this->user('tech_admin', ['name' => 'T1']);
        $t2 = $this->user('tech_admin', ['name' => 'T2']);
        $gone = $this->user('tech_admin', ['name' => 'Gone']);
        $gone->delete();
        $st = $this->user('student', ['name' => 'St']);
        $this->assignPermission($st, self::REVIEW); // also direct: still one row
        $this->assignPermission($t2, self::REVIEW, 'deny'); // deny beats system
        $nobody = $this->user('student', ['name' => 'Nobody']);

        Sanctum::actingAs($s1);
        $json = $this->getJson('/api/admin/permissions/'.self::REVIEW.'/holders?per_page=100')->assertOk()->json('data');

        $this->assertFalse($json['permission']['explicit_only']);
        $names = collect($json['roles'])->pluck('source', 'name')->all();
        $this->assertSame(['super_admin' => 'system', 'tech_admin' => 'system'], $names);
        $this->assertSame(2, collect($json['roles'])->firstWhere('name', 'tech_admin')['users_count'], 'tech_admin members: T1, T2 (Gone is soft-deleted)');

        $ids = array_column($json['effective_users']['items'], 'id');
        $this->assertSame(array_values(array_unique($ids)), $ids);
        $expected = User::all()->filter(fn (User $u) => $u->fresh()->hasPermission(self::REVIEW))->pluck('id')->sort()->values()->all();
        $this->assertSame($expected, collect($ids)->sort()->values()->all(), 'parity with User::hasPermission');
        $this->assertSame(count($expected), $json['effective_users']['total']);
        $this->assertNotContains($t2->id, $ids);
        $this->assertNotContains($gone->id, $ids);
        $this->assertNotContains($nobody->id, $ids);
        $st_row = collect($json['effective_users']['items'])->firstWhere('id', $st->id);
        $this->assertSame(['direct_allow'], array_column($st_row['sources'], 'type'));

        $page = $this->getJson('/api/admin/permissions/'.self::REVIEW.'/holders?per_page=1&page=2')->json('data.effective_users');
        $this->assertSame(1, $page['per_page']);
        $this->assertSame(2, $page['page']);
        $this->assertSame(count($expected), $page['last_page']);
        $this->assertSame(1, count($this->getJson('/api/admin/permissions/'.self::REVIEW.'/holders?search=S1')->json('data.effective_users.items')));

        $this->getJson('/api/admin/permissions/no.such_key/holders')->assertNotFound();

        // F7: bounded paging and the direct list reports its full total.
        $this->getJson('/api/admin/permissions/'.self::REVIEW.'/holders?page=10001')->assertStatus(422);
        $this->getJson('/api/admin/roles/tech_admin/users?page=10001')->assertStatus(422);
        $direct = $this->getJson('/api/admin/permissions/'.self::REVIEW.'/holders')->json('data.direct_users');
        $this->assertSame(count($direct['items']), $direct['total']);
        $this->assertFalse($direct['capped']);
    }

    public function test_a_leader_of_two_mapped_departments_has_one_specialised_role_in_counts_and_holders(): void
    {
        foreach (['department_manager', 'finance_manager', 'hr_manager'] as $r) {
            $this->role($r);
        }
        $perm = $this->ordinaryPermission();
        $this->grantToRole('hr_manager', $perm->name);
        $this->grantToRole('finance_manager', $perm->name);

        // finance (created first, lower id) wins over human-resources: User::getDepartmentSpecializedRole() takes the first mapped one.
        $dm = $this->user('department_manager', ['name' => 'Two Depts']);
        Department::create(['name' => 'Fin', 'name_ar' => 'المالية', 'slug' => 'finance', 'status' => 'active', 'is_active' => true, 'leader_id' => $dm->id]);
        Department::create(['name' => 'HR', 'name_ar' => 'الموارد', 'slug' => 'human-resources', 'status' => 'active', 'is_active' => true, 'leader_id' => $dm->id]);
        $this->assertSame('finance_manager', $dm->fresh()->getDepartmentSpecializedRole());

        $root = $this->user('super_admin');
        $this->assertSame(1, $this->roleRow('finance_manager', $root)['users_count']);
        $this->assertSame(0, $this->roleRow('hr_manager', $root)['users_count'], 'the second department confers no second role');
        $this->assertSame([], $this->getJson('/api/admin/roles/hr_manager/users')->json('data.items'));

        $eff = $this->getJson('/api/admin/permissions/'.$perm->name.'/holders?search=Two')->assertOk()->json('data.effective_users');
        $this->assertSame([$dm->id], array_column($eff['items'], 'id'));
        $this->assertSame(1, $eff['total']);
        $this->assertSame($dm->fresh()->hasPermission($perm->name), true);
        $this->assertCount(1, $eff['items'][0]['sources']);

        // Only hr_manager grants it -> the two-department leader (acting as finance_manager) does NOT hold it, in the list or the holders.
        $this->role('hr_manager')->permissions()->detach();
        $this->role('finance_manager')->permissions()->detach();
        $this->grantToRole('hr_manager', $perm->name);
        $this->assertFalse($dm->fresh()->hasPermission($perm->name));
        $this->assertSame([], $this->getJson('/api/admin/permissions/'.$perm->name.'/holders?search=Two')->json('data.effective_users.items'));
    }

    public function test_the_effective_access_preview_omits_action_permissions_for_a_department_leader(): void
    {
        $dept = Department::create(['name' => 'D '.Str::random(5), 'name_ar' => 'إدارة', 'slug' => 'd-'.Str::random(8), 'status' => 'active', 'is_active' => true]);
        $leader = $this->user('programs_manager');
        $dept->update(['leader_id' => $leader->id]);
        $member = $this->user('student');
        $dept->users()->attach($member->id);

        Sanctum::actingAs($leader);
        $res = $this->getJson("/api/admin/users/{$member->id}/effective-page-access")->assertOk();
        $this->assertArrayHasKey('allowed_keys', $res->json('data'));
        $this->assertArrayNotHasKey('action_permissions', $res->json('data'));

        foreach (['super_admin', 'tech_admin'] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->assertNotEmpty($this->getJson("/api/admin/users/{$member->id}/effective-page-access")->assertOk()->json('data.action_permissions'), $role);
        }
    }
}
