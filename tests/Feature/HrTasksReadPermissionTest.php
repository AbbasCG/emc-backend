<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Models\VolunteerPointAward;
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
 * The HR tasks page (/dashboard/hr/tasks) reads two endpoints: GET /api/admin/tasks/pending-hr-approval and GET /api/operations/tasks
 * (the «سجل المهام العام» tab). They are gated by role (admin tier) OR the explicit permission hr_tasks.view. Page access (hr.tasks)
 * only opens the page. Task details and every action (hr-approve, evaluate, reject, reassign, claim...) stay as they were.
 */
class HrTasksReadPermissionTest extends TestCase
{
    use GrantsTicketReviewPermissions;
    use RefreshDatabase;

    private const KEY = 'hr_tasks.view';

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

    /** A medium task the manager already approved and that now waits for HR. */
    private function pendingTask(array $over = []): Task
    {
        $creator = $this->user('admin');
        $volunteer = $this->user('student');

        return Task::create(array_merge([
            'title' => 'مهمة بانتظار HR', 'description' => 'x', 'task_scope' => Task::SCOPE_MEDIUM, 'points' => 100, 'estimated_hours' => 2,
            'assigned_to' => $volunteer->id, 'created_by' => $creator->id, 'status' => 'waiting_approval', 'priority' => 'medium',
            'manager_approved_at' => now(),
        ], $over));
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function hrWithPermission(): User
    {
        $hr = $this->user('hr_manager');
        $this->assignPermission($hr, self::KEY, 'allow');

        return $hr->fresh();
    }

    // ═══ The permission row ═════════════════════════════════════════════════

    public function test_the_permission_is_registered_with_arabic_label_grouped_under_hr_and_granted_to_no_role_by_default(): void
    {
        $row = Permission::where('name', self::KEY)->firstOrFail();
        $this->assertSame('عرض مهام واعتمادات الموارد البشرية', $row->label_ar);
        $this->assertStringContainsString('حساسة', $row->description_ar, 'the description warns that the read exposes all tasks with evaluation notes');
        $this->assertSame('hr', $row->group);
        $this->assertSame(0, $row->roles()->count(), 'the migration grants nothing to any role');
        $this->assertSame(0, \DB::table('user_permission_assignments')->where('permission_id', $row->id)->count());
        $this->assertContains(self::KEY, Permissions::all());
        foreach (['hr_manager', 'operations_manager', 'department_manager', 'executive_admin', 'volunteer', 'student'] as $role) {
            $this->assertNotContains(self::KEY, Permissions::forRole($role));
        }

        Sanctum::actingAs($this->user('super_admin'));
        $modules = $this->getJson('/api/admin/permissions')->assertOk()->json('data.modules');
        $hr = collect($modules)->firstWhere('group', 'hr');
        $this->assertSame('الموارد البشرية', $hr['group_label']);
        $this->assertSame('عرض مهام واعتمادات الموارد البشرية', collect($hr['permissions'])->firstWhere('key', self::KEY)['label_ar']);
    }

    public function test_it_is_grantable_through_the_role_editor_and_the_role_then_reads(): void
    {
        $task = $this->pendingTask();
        $hr = $this->user('hr_manager');
        $role = Role::where('name', 'hr_manager')->firstOrFail();
        $existing = $role->permissions()->pluck('name')->all();

        Sanctum::actingAs($this->user('super_admin'));
        $this->putJson("/api/admin/roles/{$role->id}/permissions", ['permissions' => array_merge($existing, [self::KEY])])->assertOk();

        Sanctum::actingAs($hr->fresh());
        $this->getJson('/api/admin/tasks/pending-hr-approval')->assertOk()->assertJsonPath('data.0.id', $task->id);
    }

    // ═══ Without the permission (page access alone) ═════════════════════════

    public function test_hr_manager_without_the_permission_is_refused_on_both_reads(): void
    {
        $this->pendingTask();

        Sanctum::actingAs($this->user('hr_manager'));
        $this->getJson('/api/admin/tasks/pending-hr-approval')->assertForbidden();
        $this->getJson('/api/operations/tasks')->assertForbidden();
        $this->getJson('/api/operations/tasks?scope=all')->assertForbidden();
    }

    public function test_a_plain_member_and_guests_are_refused(): void
    {
        $this->pendingTask();
        $this->getJson('/api/admin/tasks/pending-hr-approval')->assertUnauthorized();
        $this->getJson('/api/operations/tasks')->assertUnauthorized();

        foreach (['student', 'volunteer', 'partner'] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->getJson('/api/admin/tasks/pending-hr-approval')->assertForbidden();
            $this->getJson('/api/operations/tasks')->assertForbidden();
        }
    }

    // ═══ With the permission ════════════════════════════════════════════════

    public function test_hr_manager_with_the_permission_reads_the_pending_list_and_the_history_tab(): void
    {
        $pending = $this->pendingTask();
        $this->pendingTask(['title' => 'ليست جاهزة', 'status' => 'in_progress', 'manager_approved_at' => null]);
        $this->pendingTask(['title' => 'معتمدة سابقًا', 'hr_approved_at' => now()]);

        Sanctum::actingAs($this->hrWithPermission());

        $list = $this->getJson('/api/admin/tasks/pending-hr-approval')->assertOk();
        $this->assertCount(1, $list->json('data'), 'same scope as for admin: waiting_approval + manager approved + not yet HR approved');
        $list->assertJsonPath('data.0.id', $pending->id);

        $history = $this->getJson('/api/operations/tasks?scope=all')->assertOk();
        $this->assertSame(3, $history->json('data.meta.total'));
    }

    public function test_per_user_allow_through_the_action_permissions_endpoint_works_and_a_per_user_deny_wins_over_the_role(): void
    {
        $this->pendingTask();
        $hr = $this->user('hr_manager');
        $other = $this->user('hr_manager');

        Sanctum::actingAs($this->user('super_admin'));
        $this->postJson("/api/admin/access/users/{$hr->id}/action-permissions/".self::KEY.'/grant')->assertOk();

        Sanctum::actingAs($hr->fresh());
        $this->getJson('/api/admin/tasks/pending-hr-approval')->assertOk();
        Sanctum::actingAs($other->fresh());
        $this->getJson('/api/admin/tasks/pending-hr-approval')->assertForbidden(); // same role, no grant

        $this->grantToRole('hr_manager', self::KEY);
        $this->assignPermission($other, self::KEY, 'deny');
        Sanctum::actingAs($other->fresh());
        $this->getJson('/api/admin/tasks/pending-hr-approval')->assertForbidden();
        $this->getJson('/api/operations/tasks')->assertForbidden();
    }

    public function test_a_plain_member_holding_the_permission_reads_it_is_a_permission_not_a_role(): void
    {
        $this->pendingTask();
        $member = $this->user('volunteer');
        $this->assignPermission($member, self::KEY, 'allow');

        Sanctum::actingAs($member->fresh());
        $this->getJson('/api/admin/tasks/pending-hr-approval')->assertOk();
        $this->getJson('/api/operations/tasks')->assertOk();
    }

    // ═══ The read permission reaches nothing else ═══════════════════════════

    public function test_the_read_permission_does_not_reach_details_other_lists_or_any_action(): void
    {
        $task = $this->pendingTask();
        $hr = $this->hrWithPermission();
        Sanctum::actingAs($hr);

        // other reads of the same resource
        $this->getJson("/api/admin/tasks/{$task->id}")->assertForbidden();
        $this->getJson("/api/operations/tasks/{$task->id}")->assertForbidden();
        $this->getJson('/api/admin/tasks')->assertForbidden();
        $this->getJson('/api/admin/tasks/pending-evaluation')->assertForbidden();
        $this->getJson('/api/admin/tasks/overdue')->assertForbidden();
        $this->getJson('/api/admin/tasks/kanban')->assertForbidden();

        // actions
        // (admin/.../hr-approve is role-gated for hr_manager, see the hr-approve tests below; the operations/* copy is untouched)
        $this->postJson("/api/operations/tasks/{$task->id}/hr-approve")->assertForbidden();
        $this->postJson("/api/admin/tasks/{$task->id}/reject", ['reason' => 'x'])->assertForbidden();
        $this->postJson("/api/admin/tasks/{$task->id}/evaluate", ['delivered_on_time' => 5, 'quality_of_outputs' => 4, 'autonomy' => 4, 'responsiveness' => 5, 'collaboration_na' => true])->assertForbidden();
        $this->postJson("/api/admin/tasks/{$task->id}/reassign", ['assigned_to' => $hr->id])->assertForbidden();
        $this->postJson("/api/admin/tasks/{$task->id}/claim")->assertForbidden();
        $this->postJson("/api/admin/tasks/{$task->id}/lock")->assertForbidden();
        $this->postJson('/api/operations/tasks', ['title' => 'x'])->assertForbidden();
        $this->patchJson("/api/operations/tasks/{$task->id}", ['title' => 'y'])->assertForbidden();

        $fresh = $task->fresh();
        $this->assertSame('waiting_approval', $fresh->status);
        $this->assertNull($fresh->hr_approved_at);
    }

    // ═══ hr-approve: role-gated (admin tier + hr_manager), independent of hr_tasks.view ═══════════

    private function awards(Task $t): int
    {
        return VolunteerPointAward::where('source_type', Task::class)->where('source_id', $t->id)->count();
    }

    public function test_admin_approves_as_before(): void
    {
        $task = $this->pendingTask();

        Sanctum::actingAs($this->user('admin'));
        $this->postJson("/api/admin/tasks/{$task->id}/hr-approve")->assertOk()->assertJsonPath('success', true);
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertNotNull($task->fresh()->hr_approved_at);
        $this->assertSame(1, $this->awards($task));
        $this->getJson('/api/admin/tasks/pending-hr-approval')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_hr_manager_approves_with_or_without_the_read_permission_and_credit_happens_once(): void
    {
        foreach ([true, false] as $withRead) {
            $task = $this->pendingTask();
            $hr = $withRead ? $this->hrWithPermission() : $this->user('hr_manager');

            Sanctum::actingAs($hr);
            $this->postJson("/api/admin/tasks/{$task->id}/hr-approve")->assertOk()->assertJsonPath('success', true)
                ->assertJsonPath('message', 'تم اعتماد المهمة نهائياً من الموارد البشرية وإضافة النقاط وساعات التطوع التراكمية.');
            $fresh = $task->fresh();
            $this->assertSame('completed', $fresh->status);
            $this->assertSame($hr->id, (int) $fresh->hr_approved_by);
            $this->assertSame(1, $this->awards($task));

            // replay: success, no second credit (rules unchanged)
            $this->postJson("/api/admin/tasks/{$task->id}/hr-approve")->assertOk();
            $this->assertSame(1, $this->awards($task));
        }
    }

    public function test_hr_approve_refuses_a_task_that_is_not_awaiting_hr_and_credits_nothing(): void
    {
        $cases = [
            'in progress, never evaluated' => ['status' => 'in_progress', 'manager_approved_at' => null],
            'waiting but not manager-approved' => ['status' => 'waiting_approval', 'manager_approved_at' => null],
            'pending (planning)' => ['status' => 'planning', 'manager_approved_at' => null],
            'cancelled' => ['status' => 'cancelled', 'manager_approved_at' => null],
            'cancelled after manager approval' => ['status' => 'cancelled'],
            'in progress after manager approval' => ['status' => 'in_progress'],
            'waiting but already HR-approved' => ['status' => 'waiting_approval', 'hr_approved_at' => now()],
        ];

        foreach (['hr_manager', 'admin', 'super_admin'] as $role) {
            Sanctum::actingAs($this->user($role));
            foreach ($cases as $label => $over) {
                $task = $this->pendingTask($over);
                $this->postJson("/api/admin/tasks/{$task->id}/hr-approve")->assertStatus(422)->assertJsonPath('success', false);
                $fresh = $task->fresh();
                $this->assertSame($over['status'], $fresh->status, "$role / $label: status untouched");
                $this->assertSame(0, $this->awards($task), "$role / $label: nothing credited");
                $this->assertNull($fresh->hr_approved_by);
            }
        }
    }

    public function test_nobody_approves_a_task_assigned_to_themselves(): void
    {
        foreach (['hr_manager', 'admin', 'super_admin'] as $role) {
            $actor = $this->user($role);
            $task = $this->pendingTask(['assigned_to' => $actor->id]);

            Sanctum::actingAs($actor);
            $this->postJson("/api/admin/tasks/{$task->id}/hr-approve")->assertForbidden()->assertJsonPath('success', false);
            $this->assertSame('waiting_approval', $task->fresh()->status, $role);
            $this->assertSame(0, $this->awards($task), $role);

            // someone else of the same role can approve it
            Sanctum::actingAs($this->user($role));
            $this->postJson("/api/admin/tasks/{$task->id}/hr-approve")->assertOk();
            $this->assertSame(1, $this->awards($task), $role);
        }
    }

    public function test_the_read_permission_alone_never_approves(): void
    {
        foreach (['volunteer', 'student', 'department_manager'] as $role) {
            $task = $this->pendingTask();
            $member = $this->user($role);
            $this->assignPermission($member, self::KEY, 'allow');

            Sanctum::actingAs($member->fresh());
            $this->getJson('/api/admin/tasks/pending-hr-approval')->assertOk();
            $this->postJson("/api/admin/tasks/{$task->id}/hr-approve")->assertForbidden();
            $this->assertSame('waiting_approval', $task->fresh()->status);
            $this->assertSame(0, $this->awards($task));
        }
    }

    public function test_hr_manager_gets_nothing_else_on_the_tasks_surface(): void
    {
        $task = $this->pendingTask();
        Sanctum::actingAs($this->user('hr_manager')); // no read permission

        $this->postJson("/api/admin/tasks/{$task->id}/reject", ['reason' => 'x'])->assertForbidden();
        $this->postJson("/api/admin/tasks/{$task->id}/evaluate", ['delivered_on_time' => 5, 'quality_of_outputs' => 4, 'autonomy' => 4, 'responsiveness' => 5, 'collaboration_na' => true])->assertForbidden();
        $this->postJson("/api/admin/tasks/{$task->id}/reassign", ['assigned_to' => 1])->assertForbidden();
        $this->postJson("/api/admin/tasks/{$task->id}/claim")->assertForbidden();
        $this->postJson("/api/admin/tasks/{$task->id}/lock")->assertForbidden();
        $this->postJson("/api/operations/tasks/{$task->id}/hr-approve")->assertForbidden(); // the operations/* copy is untouched
        $this->getJson("/api/admin/tasks/{$task->id}")->assertForbidden();
        $this->getJson('/api/admin/tasks')->assertForbidden();
        $this->getJson('/api/admin/tasks/pending-evaluation')->assertForbidden();
        $this->assertSame('waiting_approval', $task->fresh()->status);
    }

    public function test_executive_admin_and_operations_manager_hr_approve_are_unchanged(): void
    {
        $task = $this->pendingTask();

        // executive_admin is not in the route role list: still refused by the route
        Sanctum::actingAs($this->user('executive_admin'));
        $this->postJson("/api/admin/tasks/{$task->id}/hr-approve")->assertForbidden();

        // operations_manager passes the route and is refused by the controller's HR role list, exactly as before
        Sanctum::actingAs($this->user('operations_manager'));
        $this->postJson("/api/admin/tasks/{$task->id}/hr-approve")->assertForbidden()
            ->assertJsonPath('message', 'اعتماد الموارد البشرية متاح لمسؤولي HR فقط.');

        Sanctum::actingAs($this->user('super_admin'));
        $this->postJson("/api/admin/tasks/{$task->id}/hr-approve")->assertOk();
        $this->assertSame('completed', $task->fresh()->status);
    }

    // ═══ The admin tier is unchanged ════════════════════════════════════════

    public function test_the_admin_tier_is_unchanged(): void
    {
        $task = $this->pendingTask();

        foreach (['admin', 'super_admin', 'tech_admin', 'operations_manager'] as $role) {
            Sanctum::actingAs($this->user($role));
            $this->getJson('/api/admin/tasks/pending-hr-approval')->assertOk()->assertJsonPath('data.0.id', $task->id);
            $this->getJson('/api/operations/tasks')->assertOk();
            $this->getJson("/api/admin/tasks/{$task->id}")->assertOk();
            $this->getJson("/api/operations/tasks/{$task->id}")->assertOk();
        }
    }

    // ═══ Preview (impersonation) behaves like the target ════════════════════

    public function test_an_impersonation_token_preview_follows_the_targets_permission(): void
    {
        $task = $this->pendingTask();
        $super = $this->user('super_admin');
        $withIt = $this->hrWithPermission();
        $without = $this->user('hr_manager');

        $login = $super->createToken('api-token')->plainTextToken;

        $imp = $this->as($login)->postJson("/api/admin/impersonate/{$withIt->id}")->assertOk()->json('token');
        $this->as($imp)->getJson('/api/admin/tasks/pending-hr-approval')->assertOk()->assertJsonPath('data.0.id', $task->id);
        $this->as($imp)->getJson('/api/operations/tasks?scope=all')->assertOk();
        $this->as($imp)->postJson("/api/admin/tasks/{$task->id}/reject", ['reason' => 'x'])->assertForbidden();
        $this->as($imp)->postJson('/api/admin/impersonate/stop')->assertOk();

        // hr_manager without the read permission: reads refused, approval (role-gated) still follows the target's role
        $imp2 = $this->as($login)->postJson("/api/admin/impersonate/{$without->id}")->assertOk()->json('token');
        $this->as($imp2)->getJson('/api/admin/tasks/pending-hr-approval')->assertForbidden()->assertJsonMissingPath('code');
        $this->as($imp2)->getJson('/api/operations/tasks')->assertForbidden();
        $this->as($imp2)->postJson("/api/admin/tasks/{$task->id}/hr-approve")->assertOk();
        $this->as($imp2)->postJson('/api/admin/impersonate/stop')->assertOk();

        $this->assertSame('completed', $task->fresh()->status);

        // a member holding only the read permission: preview reads, never approves
        $task2 = $this->pendingTask();
        $member = $this->user('volunteer');
        $this->assignPermission($member, self::KEY, 'allow');
        $imp3 = $this->as($login)->postJson("/api/admin/impersonate/{$member->id}")->assertOk()->json('token');
        $this->as($imp3)->getJson('/api/admin/tasks/pending-hr-approval')->assertOk();
        $this->as($imp3)->postJson("/api/admin/tasks/{$task2->id}/hr-approve")->assertForbidden();
        $this->assertSame('waiting_approval', $task2->fresh()->status);
    }

    public function test_a_cookie_session_preview_follows_the_targets_permission(): void
    {
        $this->pendingTask();
        $super = $this->user('super_admin');
        $withIt = $this->hrWithPermission();
        $without = $this->user('hr_manager');

        $this->withHeaders(self::SPA)->postJson('/api/auth/login', ['email' => $super->email, 'password' => 'secret123'])->assertOk();

        $this->withHeaders(self::SPA)->postJson("/api/admin/impersonate/{$withIt->id}")->assertOk()->assertJsonPath('token', null);
        $this->app['auth']->forgetGuards();
        $this->withHeaders(self::SPA)->getJson('/api/admin/tasks/pending-hr-approval')->assertOk()->assertJsonCount(1, 'data');
        $this->withHeaders(self::SPA)->getJson('/api/operations/tasks?scope=all')->assertOk();
        $this->withHeaders(self::SPA)->postJson('/api/admin/impersonate/stop')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withHeaders(self::SPA)->postJson("/api/admin/impersonate/{$without->id}")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(self::SPA)->getJson('/api/admin/tasks/pending-hr-approval')->assertForbidden();
        $this->withHeaders(self::SPA)->getJson('/api/operations/tasks')->assertForbidden();
        // approval is role-gated: the same preview (hr_manager without the read permission) can still approve
        $task = Task::where('status', 'waiting_approval')->firstOrFail();
        $this->withHeaders(self::SPA)->postJson("/api/admin/tasks/{$task->id}/hr-approve")->assertOk();
        $this->assertSame('completed', $task->fresh()->status);
    }
}
