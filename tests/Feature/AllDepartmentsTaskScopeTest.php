<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\DepartmentSection;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Models\UserPermissionAssignment;
use App\Services\TaskWriteAccess;
use App\Support\Permissions;
use Database\Seeders\ImpersonatePermissionSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\GrantsTicketReviewPermissions;
use Tests\TestCase;

/**
 * tasks.manage_all_departments is a SCOPE permission, granted one account at a time: it widens the departments a holder writes tasks
 * in and nothing else. Two HR managers share the same role row (which holds tasks.create / assign / update, as in production);
 * only the one given the scope acts outside the departments they lead. The write keys stay required for each action, a per-user deny
 * still closes it, and the holder stays a DELEGATED actor (credit-bearing, lock-ownership and transition guards still bind them).
 */
class AllDepartmentsTaskScopeTest extends TestCase
{
    use GrantsTicketReviewPermissions;
    use RefreshDatabase;

    private const SCOPE = 'tasks.manage_all_departments';

    private User $hrScoped;     // hr_manager WITH the scope grant

    private User $hrPlain;      // hr_manager, same role, WITHOUT it

    private User $marketingLeader;

    private User $hrMember;

    private User $marketingMember;

    private Department $deptHr;

    private Department $deptMarketing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, ImpersonatePermissionSeeder::class]);

        // the production shape: neither HR manager leads the HR department
        $hrLeader = $this->user('department_manager');
        $this->hrScoped = $this->user('hr_manager');
        $this->hrPlain = $this->user('hr_manager');
        $this->marketingLeader = $this->user('department_manager');
        $this->deptHr = $this->dept($hrLeader);
        $this->deptMarketing = $this->dept($this->marketingLeader);
        $this->hrMember = $this->user('volunteer');
        $this->marketingMember = $this->user('volunteer');
        $this->deptHr->users()->attach($this->hrMember->id);
        $this->deptMarketing->users()->attach($this->marketingMember->id);

        foreach (['tasks.create', 'tasks.assign', 'tasks.update'] as $key) {
            $this->grantToRole('hr_manager', $key);   // the role row, shared by both
        }
        $this->assignPermission($this->hrScoped, self::SCOPE, 'allow');   // the scope: ONE account only
        $this->hrScoped = $this->hrScoped->fresh();
        $this->hrPlain = $this->hrPlain->fresh();
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true, 'password' => bcrypt('secret123')]);
    }

    private function dept(User $leader): Department
    {
        return Department::create(['name' => 'D '.Str::random(6), 'name_ar' => 'إدارة '.Str::random(3), 'slug' => 'd-'.Str::random(8), 'status' => 'active', 'is_active' => true, 'leader_id' => $leader->id]);
    }

    private function task(array $over = []): Task
    {
        return Task::create(array_merge([
            'title' => 'مهمة '.Str::random(4), 'department_id' => $this->deptMarketing->id, 'assigned_to' => $this->marketingMember->id, 'created_by' => $this->marketingLeader->id,
            'priority' => 'medium', 'status' => 'planning', 'acceptance_status' => 'accepted',
        ], $over));
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh());

        return $this;
    }

    private function move(Task $task, string $status)
    {
        return $this->patchJson("/api/operations/board/tasks/{$task->id}/status", ['status' => $status]);
    }

    private function revokeScopeFromUser(User $user): void
    {
        UserPermissionAssignment::query()->where('user_id', $user->id)->where('permission_id', Permission::where('name', self::SCOPE)->value('id'))->delete();
        $user->flushPermissionCache();
    }

    // ── The permission row, and what it is NOT granted to ────────────────────

    public function test_the_permission_exists_under_the_tasks_group_and_is_granted_to_nobody_by_default(): void
    {
        $row = Permission::where('name', self::SCOPE)->firstOrFail();
        $this->assertSame('tasks', $row->group);
        $this->assertContains(self::SCOPE, Permissions::all());
        $this->assertContains(self::SCOPE, Permissions::grouped()['tasks']);

        // no role row holds it, no static default holds it, and nobody has it assigned (setUp assigned only one test account)
        $holders = DB::table('permission_role')->join('roles', 'roles.id', '=', 'permission_role.role_id')->where('permission_role.permission_id', $row->id)->pluck('roles.name')->sort()->values()->all();
        $this->assertSame(['super_admin', 'tech_admin'], $holders, 'only the two roles seeded with all(); hr_manager and every other role: none');
        foreach (Role::pluck('name') as $role) {
            if (! in_array($role, ['super_admin', 'tech_admin'], true)) {
                $this->assertNotContains(self::SCOPE, Permissions::forRole($role), "{$role} has no static default");
            }
        }
        $this->assertSame(1, UserPermissionAssignment::where('permission_id', $row->id)->count());
        $this->assertTrue($this->user('super_admin')->hasPermission(self::SCOPE));
        $this->assertFalse($this->user('hr_manager')->hasPermission(self::SCOPE));
    }

    public function test_the_migration_creates_the_row_with_the_arabic_label_and_grants_nothing(): void
    {
        $migration = require base_path('database/migrations/2026_12_01_000001_create_tasks_manage_all_departments_permission.php');
        $migration->down();
        $this->assertSame(0, Permission::where('name', self::SCOPE)->count());

        $migration->up();
        $migration->up(); // idempotent
        $row = Permission::where('name', self::SCOPE)->firstOrFail();
        $this->assertSame('إدارة مهام كل الأقسام', $row->label_ar);
        $this->assertSame('tasks', $row->group);
        $this->assertNotEmpty($row->description_ar);
        $this->assertSame(0, DB::table('permission_role')->where('permission_id', $row->id)->count(), 'no role is granted it');
        $this->assertSame(0, UserPermissionAssignment::where('permission_id', $row->id)->count(), 'no account is granted it');
        $this->assertSame(1, Permission::where('name', self::SCOPE)->count());
    }

    public function test_the_permission_is_listed_and_grantable_one_account_at_a_time_from_the_access_center(): void
    {
        $this->revokeScopeFromUser($this->hrScoped);
        $admin = $this->user('super_admin');

        $this->as($admin);
        $groups = $this->getJson("/api/admin/access/users/{$this->hrPlain->id}/action-permissions")->assertOk()->json('data.groups');
        $tasks = collect($groups)->firstWhere('key', 'tasks');
        $entry = collect($tasks['permissions'])->firstWhere('key', self::SCOPE);
        $this->assertSame('إدارة مهام كل الأقسام', $entry['label_ar']);

        $this->postJson("/api/admin/access/users/{$this->hrPlain->id}/action-permissions/".self::SCOPE.'/grant')->assertOk();
        $this->assertTrue(app(TaskWriteAccess::class)->actsOnEveryDepartment($this->hrPlain->fresh()));
        // the other HR manager (same role) is untouched
        $this->assertFalse(app(TaskWriteAccess::class)->actsOnEveryDepartment($this->hrScoped->fresh()));

        $this->postJson("/api/admin/access/users/{$this->hrPlain->id}/action-permissions/".self::SCOPE.'/revoke')->assertOk();
        $this->assertFalse(app(TaskWriteAccess::class)->actsOnEveryDepartment($this->hrPlain->fresh()));
    }

    public function test_a_department_leader_cannot_delegate_the_scope_even_when_holding_it_with_a_delegatable_row(): void
    {
        $leader = $this->marketingLeader;
        $this->assignPermission($leader, self::SCOPE, 'allow');
        \App\Models\DelegatablePermission::create(['permission_id' => Permission::where('name', self::SCOPE)->value('id'), 'user_id' => $leader->id, 'can_grant' => true]);
        $svc = app(\App\Services\PermissionDelegationService::class);

        $this->assertFalse($svc->canDelegate($leader->fresh(), $this->marketingMember, self::SCOPE), 'a department-scoped delegation would grant every department');
        $this->assertTrue($svc->canDelegate($this->user('super_admin'), $this->marketingMember, self::SCOPE), 'super_admin keeps the blanket right');
    }

    // ── Two accounts, same role: with and without the scope ──────────────────

    public function test_the_service_scopes_only_the_account_holding_the_permission_and_it_is_not_global(): void
    {
        $svc = app(TaskWriteAccess::class);

        $this->assertTrue($svc->actsOnEveryDepartment($this->hrScoped));
        $this->assertFalse($svc->isGlobal($this->hrScoped), 'delegated: the credit-bearing and lock guards still bind them');
        $this->assertNull($svc->manageableDepartmentIds($this->hrScoped));
        $this->assertTrue($svc->inScope($this->hrScoped, $this->deptMarketing->id));
        $this->assertFalse($svc->inScope($this->hrScoped, null), "a task of no department is a global actor's alone");
        $this->assertTrue($svc->assigneeInScope($this->hrScoped, $this->marketingMember->id));

        $this->assertFalse($svc->actsOnEveryDepartment($this->hrPlain));
        $this->assertSame([], $svc->manageableDepartmentIds($this->hrPlain), 'same role, no grant: leads nothing, acts nowhere');
        $this->assertFalse($svc->inScope($this->hrPlain, $this->deptMarketing->id));
        $this->assertSame([$this->deptMarketing->id], $svc->manageableDepartmentIds($this->marketingLeader));
    }

    public function test_the_scoped_account_works_on_a_department_it_does_not_lead_and_the_plain_one_cannot(): void
    {
        $task = $this->task();

        // the plain HR manager: every write refused
        $this->as($this->hrPlain);
        $this->postJson('/api/admin/tasks', ['title' => 'x', 'department_id' => $this->deptMarketing->id])->assertForbidden();
        $this->patchJson("/api/operations/tasks/{$task->id}", ['title' => 'x'])->assertForbidden();
        $this->postJson("/api/operations/tasks/{$task->id}/reassign", ['assigned_to' => $this->hrMember->id])->assertForbidden();
        $this->move($task, 'in_progress')->assertForbidden();
        $this->postJson("/api/operations/tasks/{$task->id}/lock")->assertForbidden();
        $plainBoard = $this->getJson('/api/operations/work-board')->assertOk();
        $this->assertSame(['can_create' => false, 'can_assign' => false, 'can_update' => false, 'manageable_department_ids' => []], $plainBoard->json('meta.task_actions'));
        $this->assertSame('planning', $task->fresh()->status);

        // the scoped one: create / edit / reassign / move / lock
        $this->as($this->hrScoped);
        $this->postJson('/api/admin/tasks', ['title' => 'من الموارد', 'department_id' => $this->deptMarketing->id, 'assigned_to' => $this->marketingMember->id, 'priority' => 'medium'])->assertCreated();
        $this->assertSame($this->deptMarketing->id, (int) Task::where('title', 'من الموارد')->value('department_id'));
        $this->postJson('/api/operations/board/tasks', ['title' => 'مهمة أخرى', 'department_id' => $this->deptHr->id, 'assigned_to' => $this->marketingMember->id, 'priority' => 'low'])->assertCreated();
        $this->patchJson("/api/operations/tasks/{$task->id}", ['title' => 'عدّلتها الموارد'])->assertOk();
        $this->postJson("/api/operations/tasks/{$task->id}/reassign", ['assigned_to' => $this->hrMember->id])->assertOk();
        $this->move($task, 'in_progress')->assertOk();
        $this->postJson("/api/operations/tasks/{$task->id}/lock")->assertOk();
        $this->postJson("/api/operations/tasks/{$task->id}/unlock")->assertOk();
        $fresh = $task->fresh();
        $this->assertSame(['عدّلتها الموارد', $this->hrMember->id, 'in_progress'], [$fresh->title, (int) $fresh->assigned_to, $fresh->status]);
    }

    public function test_the_board_reports_what_the_server_allows_and_nothing_else(): void
    {
        $task = $this->task();

        $this->as($this->hrScoped);
        $board = $this->getJson('/api/operations/work-board')->assertOk();
        // null means GLOBAL to the UI: a scope holder gets the explicit list of every active department instead
        $all = Department::active()->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();
        $this->assertSame(['can_create' => true, 'can_assign' => true, 'can_update' => true, 'manageable_department_ids' => $all], $board->json('meta.task_actions'));
        $card = collect($board->json('data.columns'))->pluck('items')->flatten(1)->firstWhere('key', "task:{$task->id}");
        $this->assertTrue($card['capabilities']['can_edit']);
        $this->assertTrue($card['capabilities']['can_reassign']);
        $scope = $this->getJson('/api/operations/board/task-scope/departments')->assertOk();
        $this->assertSame($all, $scope->json('meta.manageable_department_ids'));
        $ids = collect($scope->json('data'))->pluck('id')->all();
        $this->assertContains($this->deptHr->id, $ids);
        $this->assertContains($this->deptMarketing->id, $ids);

        // a global actor is the only one told null
        $this->as($this->user('admin'));
        $this->assertNull($this->getJson('/api/operations/work-board')->assertOk()->json('meta.task_actions.manageable_department_ids'));

        $this->as($this->hrPlain);
        $card = collect($this->getJson('/api/operations/work-board')->assertOk()->json('data.columns'))->pluck('items')->flatten(1)->firstWhere('key', "task:{$task->id}");
        $this->assertFalse($card['capabilities']['can_edit']);
        $this->assertFalse($card['capabilities']['can_reassign']);
        $this->assertSame([], $this->getJson('/api/operations/board/task-scope/departments')->assertOk()->json('data'));
    }

    // ── Revoking, deny, and the scope without write keys ─────────────────────

    public function test_revoking_the_grant_closes_it_at_once(): void
    {
        $task = $this->task();
        $this->as($this->hrScoped)->patchJson("/api/operations/tasks/{$task->id}", ['title' => 'قبل السحب'])->assertOk();

        $this->revokeScopeFromUser($this->hrScoped);
        $this->as($this->hrScoped);
        $this->patchJson("/api/operations/tasks/{$task->id}", ['title' => 'بعد السحب'])->assertForbidden();
        $this->move($task, 'in_progress')->assertForbidden();
        $this->assertSame('قبل السحب', $task->fresh()->title);
        $this->assertSame([], app(TaskWriteAccess::class)->manageableDepartmentIds($this->hrScoped->fresh()));
    }

    public function test_a_per_user_deny_of_the_scope_beats_a_role_grant_of_it(): void
    {
        $this->grantToRole('hr_manager', self::SCOPE);   // a deliberate ROLE-level grant (the owner may do this)
        $task = $this->task();

        $this->as($this->hrPlain)->patchJson("/api/operations/tasks/{$task->id}", ['title' => 'بمنح الدور'])->assertOk();

        $this->assignPermission($this->hrPlain, self::SCOPE, 'deny');
        $this->as($this->hrPlain)->patchJson("/api/operations/tasks/{$task->id}", ['title' => 'x'])->assertForbidden();
        $this->assertSame('بمنح الدور', $task->fresh()->title);
    }

    public function test_a_per_user_deny_of_a_write_key_still_closes_that_action_for_the_scoped_account(): void
    {
        $task = $this->task();
        $this->assignPermission($this->hrScoped, 'tasks.update', 'deny');

        $this->as($this->hrScoped);
        $this->patchJson("/api/operations/tasks/{$task->id}", ['title' => 'x'])->assertForbidden();
        $this->move($task, 'in_progress')->assertForbidden();
        $this->postJson("/api/operations/tasks/{$task->id}/lock")->assertForbidden();
        // the other actions are still theirs
        $this->postJson("/api/operations/tasks/{$task->id}/reassign", ['assigned_to' => $this->hrMember->id])->assertOk();
        $this->postJson('/api/admin/tasks', ['title' => 'جديدة', 'department_id' => $this->deptHr->id])->assertCreated();
        $board = $this->getJson('/api/operations/work-board')->assertOk();
        $this->assertSame(['can_create' => true, 'can_assign' => true, 'can_update' => false, 'manageable_department_ids' => Department::active()->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all()], $board->json('meta.task_actions'));
        $this->assertSame('planning', $task->fresh()->status);
    }

    public function test_the_scope_without_any_write_key_writes_nothing_and_opens_nothing(): void
    {
        $holder = $this->user('hr_manager');                       // role row: tasks.* ...
        foreach (['tasks.create', 'tasks.assign', 'tasks.update'] as $key) {
            $this->assignPermission($holder, $key, 'deny');       // ... all denied for this account
        }
        $this->assignPermission($holder, self::SCOPE, 'allow');
        $task = $this->task();

        $this->as($holder);
        $this->postJson('/api/admin/tasks', ['title' => 'x', 'department_id' => $this->deptMarketing->id])->assertForbidden();
        $this->patchJson("/api/operations/tasks/{$task->id}", ['title' => 'x'])->assertForbidden();
        $this->postJson("/api/operations/tasks/{$task->id}/reassign", ['assigned_to' => $this->hrMember->id])->assertForbidden();
        $this->move($task, 'in_progress')->assertForbidden();
        $this->postJson("/api/operations/tasks/{$task->id}/lock")->assertForbidden();
        $this->getJson("/api/operations/board/tasks/{$task->id}/editable")->assertForbidden();
        $this->getJson("/api/operations/board/task-scope/departments/{$this->deptMarketing->id}/members")->assertForbidden();
        $board = $this->getJson('/api/operations/work-board')->assertOk();
        $this->assertSame(['can_create' => false, 'can_assign' => false, 'can_update' => false, 'manageable_department_ids' => Department::active()->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all()], $board->json('meta.task_actions'));
        $this->assertSame('planning', $task->fresh()->status);

        // a plain volunteer holding ONLY the scope: no route of the task writes opens
        $volunteer = $this->user('volunteer');
        $this->assignPermission($volunteer, self::SCOPE, 'allow');
        $this->as($volunteer);
        $this->postJson('/api/admin/tasks', ['title' => 'x', 'department_id' => $this->deptMarketing->id])->assertForbidden();
        $this->patchJson("/api/operations/tasks/{$task->id}", ['title' => 'x'])->assertForbidden();
        $this->assertSame(0, Task::where('title', 'x')->count());
    }

    public function test_the_scope_is_a_permission_not_a_role_name_and_widens_nobody_else(): void
    {
        $task = $this->task();
        $leader = $this->marketingLeader;
        $this->grantToRole('department_manager', 'tasks.update');
        $hrTask = $this->task(['department_id' => $this->deptHr->id, 'assigned_to' => $this->hrMember->id]);

        // a department leader WITHOUT the scope stays in their department
        $this->as($leader)->patchJson("/api/operations/tasks/{$hrTask->id}", ['title' => 'x'])->assertForbidden();

        // the same leader WITH the grant works on the other department as well
        $this->assignPermission($leader, self::SCOPE, 'allow');
        $this->as($leader)->patchJson("/api/operations/tasks/{$hrTask->id}", ['title' => 'من القائد'])->assertOk();
        $this->assertSame('من القائد', $hrTask->fresh()->title);

        // another role with a per-user write grant and no scope stays scoped to what it leads
        $finance = $this->user('finance_manager');
        $this->assignPermission($finance, 'tasks.update', 'allow');
        $this->as($finance)->patchJson("/api/operations/tasks/{$task->id}", ['title' => 'x'])->assertForbidden();
    }

    // ── What the scope does not imply ────────────────────────────────────────

    public function test_the_scope_never_opens_evaluation_deletion_or_the_global_exemptions(): void
    {
        $task = $this->task(['status' => 'needs_evaluation']);
        foreach (['hr_manager' => $this->hrScoped, 'department_manager' => $this->marketingLeader] as $user) {
            foreach (['tasks.create', 'tasks.assign', 'tasks.update'] as $key) {
                $this->assignPermission($user, $key, 'allow');
            }
            $this->assignPermission($user, self::SCOPE, 'allow');
            $this->as($user);
            $this->postJson("/api/admin/tasks/{$task->id}/evaluate", ['rating' => 5])->assertForbidden();
            $this->postJson("/api/operations/tasks/{$task->id}/evaluate", ['rating' => 5])->assertForbidden();
            $this->deleteJson("/api/admin/tasks/{$task->id}")->assertForbidden();
        }
        $this->assertNotNull(Task::find($task->id), 'nothing was deleted');
        $this->assertNull($task->fresh()->manager_approved_at);
        $this->assertSame('needs_evaluation', $task->fresh()->status);
    }

    public function test_a_task_of_no_department_stays_out_of_reach(): void
    {
        $orphan = $this->task(['department_id' => null]);
        $this->as($this->hrScoped);

        $this->patchJson("/api/operations/tasks/{$orphan->id}", ['title' => 'x'])->assertForbidden();
        $this->postJson("/api/operations/tasks/{$orphan->id}/reassign", ['assigned_to' => $this->hrMember->id])->assertForbidden();
        $this->move($orphan, 'in_progress')->assertForbidden();
        $this->postJson("/api/operations/tasks/{$orphan->id}/lock")->assertForbidden();
        $this->postJson('/api/admin/tasks', ['title' => 'بلا إدارة', 'priority' => 'medium'])->assertStatus(422)->assertJsonValidationErrors(['department_id']);
        $own = $this->task();
        $this->patchJson("/api/operations/tasks/{$own->id}", ['department_id' => null])->assertStatus(422);
        $this->assertSame($this->deptMarketing->id, (int) $own->fresh()->department_id);

        $this->as($this->user('admin'))->patchJson("/api/operations/tasks/{$orphan->id}", ['title' => 'مدير عام'])->assertOk();
    }

    public function test_a_credit_bearing_task_is_never_touched_wherever_it_is(): void
    {
        $waiting = $this->task(['status' => 'waiting_approval', 'manager_approved_at' => now(), 'task_scope' => 'medium', 'points' => 100]);
        $done = $this->task(['status' => 'completed']);
        $approved = $this->task(['status' => 'in_progress', 'manager_approved_at' => now()]);
        $this->as($this->hrScoped);

        foreach ([$waiting, $done, $approved] as $t) {
            $before = $t->fresh()->only(['status', 'assigned_to', 'points', 'department_id']);
            $this->move($t, 'in_progress')->assertForbidden();
            $this->postJson("/api/operations/tasks/{$t->id}/reassign", ['assigned_to' => $this->hrMember->id])->assertForbidden();
            $this->patchJson("/api/operations/tasks/{$t->id}", ['status' => 'planning'])->assertForbidden();
            $this->patchJson("/api/operations/tasks/{$t->id}", ['points' => 150])->assertForbidden();
            $this->patchJson("/api/operations/tasks/{$t->id}", ['department_id' => $this->deptHr->id])->assertForbidden();
            $this->postJson("/api/operations/tasks/{$t->id}/lock")->assertForbidden();
            $this->assertSame($before, $t->fresh()->only(['status', 'assigned_to', 'points', 'department_id']));
            $this->assertNull($t->fresh()->locked_at);
        }

        $card = collect($this->getJson('/api/operations/work-board')->assertOk()->json('data.columns'))->pluck('items')->flatten(1)->firstWhere('key', "task:{$waiting->id}");
        $this->assertFalse($card['capabilities']['can_reassign']);
    }

    public function test_the_transition_rules_and_lock_ownership_still_bind_the_scoped_account(): void
    {
        $task = $this->task(['status' => 'in_progress']);
        $this->as($this->hrScoped);
        $this->move($task, 'completed')->assertStatus(422);
        $this->move($task, 'waiting_approval')->assertStatus(422);
        $this->patchJson("/api/operations/tasks/{$task->id}", ['status' => 'completed'])->assertStatus(422);
        $this->move($task, 'needs_evaluation')->assertOk();

        $admin = $this->user('admin');
        $locked = $this->task();
        $this->as($admin)->postJson("/api/operations/tasks/{$locked->id}/lock")->assertOk();
        $this->as($this->hrScoped);
        $this->postJson("/api/operations/tasks/{$locked->id}/unlock")->assertForbidden();
        $this->postJson("/api/operations/tasks/{$locked->id}/lock")->assertForbidden();
        $this->move($locked, 'in_progress')->assertStatus(423);
        $this->assertSame($admin->id, (int) $locked->fresh()->locked_by);
    }

    public function test_points_sent_alone_are_judged_against_the_stored_size_and_the_section_must_match(): void
    {
        $task = $this->task(['task_scope' => 'simple', 'points' => 30]);
        $this->as($this->hrScoped);

        $this->patchJson("/api/operations/tasks/{$task->id}", ['points' => 1000000])->assertStatus(422)->assertJsonValidationErrors(['points']);
        $this->patchJson("/api/operations/tasks/{$task->id}", ['task_scope' => 'strategic'])->assertStatus(422);
        $this->assertSame(30, (int) $task->fresh()->points);
        $this->patchJson("/api/operations/tasks/{$task->id}", ['points' => 40])->assertOk();

        $foreignSection = DepartmentSection::create(['department_id' => $this->deptMarketing->id, 'name' => 'قسم', 'status' => 'active']);
        $this->postJson('/api/admin/tasks', ['title' => 'x', 'department_id' => $this->deptHr->id, 'section_id' => $foreignSection->id])
            ->assertStatus(422)->assertJsonValidationErrors(['section_id']);
    }

    // ── HR approval: the creator never approves ──────────────────────────────

    private function awaitingApproval(User $creator, ?User $assignee = null): Task
    {
        return $this->task([
            'created_by' => $creator->id, 'assigned_to' => ($assignee ?? $this->marketingMember)->id, 'status' => 'waiting_approval',
            'task_scope' => 'medium', 'points' => 100, 'estimated_hours' => 2, 'manager_approved_at' => now(), 'manager_approved_by' => $this->user('admin')->id,
        ]);
    }

    public function test_the_creator_cannot_hr_approve_even_after_another_manager_evaluated(): void
    {
        $task = $this->awaitingApproval($this->hrScoped);

        $this->as($this->hrScoped)->postJson("/api/admin/tasks/{$task->id}/hr-approve")->assertForbidden();
        $task = $task->fresh();
        $this->assertSame('waiting_approval', $task->status);
        $this->assertNull($task->hr_approved_at);
        $this->assertSame(0, DB::table('volunteer_point_awards')->where('source_id', $task->id)->count());

        // the same refusal for any role that can reach the route (admin included)
        $adminTask = $this->awaitingApproval($admin = $this->user('admin'));
        $this->as($admin)->postJson("/api/admin/tasks/{$adminTask->id}/hr-approve")->assertForbidden();
        $this->assertSame('waiting_approval', $adminTask->fresh()->status);
    }

    public function test_the_assignee_still_cannot_approve_their_own_task(): void
    {
        $task = $this->awaitingApproval($this->marketingLeader, $this->hrPlain);

        $this->as($this->hrPlain)->postJson("/api/admin/tasks/{$task->id}/hr-approve")->assertForbidden();
        $this->assertSame('waiting_approval', $task->fresh()->status);
    }

    public function test_another_authorised_hr_account_approves_and_the_credit_lands_once(): void
    {
        $task = $this->awaitingApproval($this->hrScoped);

        $this->as($this->hrPlain)->postJson("/api/admin/tasks/{$task->id}/hr-approve")->assertOk();
        $this->as($this->hrPlain)->postJson("/api/admin/tasks/{$task->id}/hr-approve")->assertOk(); // replay
        $done = $task->fresh();
        $this->assertSame('completed', $done->status);
        $this->assertSame($this->hrPlain->id, (int) $done->hr_approved_by);
        $this->assertSame(1, DB::table('volunteer_point_awards')->where('source_id', $task->id)->count());
    }
}
