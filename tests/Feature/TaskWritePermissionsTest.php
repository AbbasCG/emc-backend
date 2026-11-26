<?php

namespace Tests\Feature;

use App\AI\Tools\CreateTaskTool;
use App\Models\Department;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskWriteAccess;
use App\Support\Permissions;
use Database\Seeders\ImpersonatePermissionSeeder;
use Database\Seeders\PermissionNodeSeeder;
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
 * Creating, assigning and editing operational tasks (the operations board and the task screens) is a PERMISSION, not a role list:
 * tasks.create / tasks.assign / tasks.update, independent of each other, scoped to the departments a non-global actor LEADS and to
 * the members of those departments (App\Services\TaskWriteAccess, the one rule the endpoints enforce and the board's
 * `meta.task_actions` / card `can_edit` / `can_reassign` report).
 */
class TaskWritePermissionsTest extends TestCase
{
    use GrantsTicketReviewPermissions;
    use RefreshDatabase;

    private const SPA = [
        'Origin' => 'http://localhost:5173',
        'Referer' => 'http://localhost:5173/login',
        'X-EMC-Auth-Mode' => 'cookie',
    ];

    private const BOARD = '/api/operations/work-board';

    private Department $deptHr;

    private Department $deptOther;

    private User $hr;

    private User $otherLeader;

    private User $member;      // on the roster of the HR department

    private User $foreign;     // on the roster of the OTHER department only

    private User $outsider;    // on no roster

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        // Like production: role rows WITH their grants (the migration's grants, mirrored by RolePermissionSeeder / forRole).
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class, ImpersonatePermissionSeeder::class]);

        $this->hr = $this->user('hr_manager');
        $this->otherLeader = $this->user('department_manager');
        $this->deptHr = $this->dept('الموارد البشرية', $this->hr);
        $this->deptOther = $this->dept('التسويق', $this->otherLeader);
        $this->member = $this->user('volunteer');
        $this->foreign = $this->user('volunteer');
        $this->outsider = $this->user('volunteer');
        $this->deptHr->users()->attach($this->member->id);
        $this->deptOther->users()->attach($this->foreign->id);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function user(string $role, array $over = []): User
    {
        return User::factory()->create(array_merge(['role' => $role, 'is_active' => true, 'password' => bcrypt('secret123')], $over));
    }

    private function dept(string $nameAr, ?User $leader = null): Department
    {
        return Department::create(['name' => 'D '.Str::random(6), 'name_ar' => $nameAr, 'slug' => 'd-'.Str::random(8), 'status' => 'active', 'is_active' => true, 'leader_id' => $leader?->id]);
    }

    private function task(array $over = []): Task
    {
        return Task::create(array_merge([
            'title' => 'مهمة '.Str::random(4), 'department_id' => $this->deptHr->id, 'assigned_to' => $this->member->id, 'created_by' => $this->hr->id,
            'priority' => 'medium', 'status' => 'in_progress', 'acceptance_status' => 'accepted',
        ], $over));
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh());

        return $this;
    }

    /** The HR manager holding exactly these keys through the ROLE row (the role editor path). */
    private function hrWith(string ...$keys): User
    {
        foreach ($keys as $key) {
            $this->grantToRole('hr_manager', $key);
        }

        return $this->hr->fresh();
    }

    private function payload(array $over = []): array
    {
        return array_merge(['title' => 'مهمة جديدة', 'department_id' => $this->deptHr->id, 'priority' => 'medium'], $over);
    }

    // ═══ 1. Root cause + the permission rows ═════════════════════════════════

    public function test_the_three_permissions_exist_with_arabic_labels_under_the_tasks_group_and_are_in_all(): void
    {
        $expected = [
            'tasks.create' => 'إنشاء مهمة تشغيلية',
            'tasks.assign' => 'إسناد المهام وإعادة إسنادها',
            'tasks.update' => 'تعديل المهام القائمة',
        ];
        foreach ($expected as $key => $label) {
            $row = Permission::where('name', $key)->firstOrFail();
            $this->assertSame($label, $row->label_ar);
            $this->assertSame('tasks', $row->group);
            $this->assertNotEmpty($row->description_ar);
            $this->assertContains($key, Permissions::all(), 'super_admin / tech_admin hold it like every ordinary key');
            $this->assertContains($key, Permissions::grouped()['tasks']);
        }

        $this->as($this->user('super_admin'));
        $modules = $this->getJson('/api/admin/permissions')->assertOk()->json('data.modules');
        $group = collect($modules)->firstWhere('group', 'tasks');
        $this->assertSame('المهام والعمليات', $group['group_label']);
        $this->assertEqualsCanonicalizing(array_keys($expected), array_column($group['permissions'], 'key'));

        $role = Role::where('name', 'hr_manager')->firstOrFail();
        $roleModules = $this->getJson("/api/admin/roles/{$role->id}/permissions")->assertOk()->json('data.modules');
        $this->assertSame('المهام والعمليات', collect($roleModules)->firstWhere('group', 'tasks')['group_label'], 'the role editor shows the same group');

        // «إدارة الوصول والصلاحيات ← المستخدمون ← صلاحيات الإجراءات»
        $panel = $this->getJson("/api/admin/access/users/{$this->hr->id}/action-permissions")->assertOk()->json('data.groups');
        $tasks = collect($panel)->firstWhere('key', 'tasks');
        $this->assertSame('المهام والعمليات', $tasks['label_ar']);
        $this->assertEqualsCanonicalizing(array_keys($expected), array_column($tasks['permissions'], 'key'));
    }

    public function test_default_grants_keep_the_admin_tier_and_give_hr_manager_nothing(): void
    {
        $all = [Permissions::TASKS_CREATE, Permissions::TASKS_ASSIGN, Permissions::TASKS_UPDATE];
        $expected = [
            'admin' => $all,
            'operations_manager' => $all,
            'executive_admin' => [Permissions::TASKS_CREATE],
            'department_manager' => [Permissions::TASKS_CREATE],
        ];

        foreach ($expected as $role => $keys) {
            $this->assertEqualsCanonicalizing($keys, array_values(array_intersect($all, Permissions::forRole($role))), "static default of {$role}");
            $row = Role::where('name', $role)->firstOrFail();
            $this->assertEqualsCanonicalizing($keys, array_values(array_intersect($all, $row->permissions()->pluck('name')->all())), "role row of {$role}");
        }
        foreach (['hr_manager', 'programs_manager', 'community_manager', 'section_lead', 'volunteer', 'student', 'finance_manager'] as $role) {
            $this->assertSame([], array_values(array_intersect($all, Permissions::forRole($role))), "{$role} has none by default");
            $this->assertSame([], array_values(array_intersect($all, Role::where('name', $role)->firstOrFail()->permissions()->pluck('name')->all())));
        }
        $this->assertSame([], array_values(array_diff($all, $this->user('super_admin')->allPermissions())));
        $this->assertSame([], array_values(array_diff($all, $this->user('tech_admin')->allPermissions())));
    }

    // ═══ 2. hr_manager WITHOUT a grant: reads, never writes ══════════════════

    public function test_hr_manager_without_a_grant_reads_the_board_but_every_task_write_is_refused(): void
    {
        $task = $this->task();
        $before = Task::count();
        $snapshot = Task::find($task->id)->only(['title', 'assigned_to', 'department_id', 'priority']);

        $this->as($this->hr);
        $board = $this->getJson(self::BOARD)->assertOk();
        $this->assertSame(['can_create' => false, 'can_assign' => false, 'can_update' => false, 'manageable_department_ids' => [$this->deptHr->id]], $board->json('meta.task_actions'));
        $card = collect($board->json('data.columns'))->pluck('items')->flatten(1)->firstWhere('key', "task:{$task->id}");
        $this->assertFalse($card['capabilities']['can_edit']);
        $this->assertFalse($card['capabilities']['can_reassign']);

        foreach (['/api/operations/board/tasks', '/api/operations/tasks', '/api/admin/tasks'] as $url) {
            $this->postJson($url, $this->payload())->assertForbidden();
        }
        $this->patchJson("/api/operations/tasks/{$task->id}", ['title' => 'x'])->assertForbidden();
        $this->patchJson("/api/admin/tasks/{$task->id}", ['title' => 'x'])->assertForbidden();
        $this->putJson("/api/admin/tasks/{$task->id}", ['title' => 'x'])->assertForbidden();
        $this->postJson("/api/operations/tasks/{$task->id}/reassign", ['assigned_to' => $this->member->id])->assertForbidden();
        $this->postJson("/api/admin/tasks/{$task->id}/reassign", ['assigned_to' => $this->member->id])->assertForbidden();

        $this->assertSame($before, Task::count());
        $this->assertSame($snapshot, Task::find($task->id)->only(['title', 'assigned_to', 'department_id', 'priority']));

        // the helper endpoints of the writing UI are closed too
        $this->getJson('/api/operations/board/task-scope/departments')->assertForbidden();
        $this->getJson("/api/operations/board/task-scope/departments/{$this->deptHr->id}/members")->assertForbidden();
        $this->getJson("/api/operations/board/tasks/{$task->id}/editable")->assertForbidden();
    }

    // ═══ 3. create + assign only ═════════════════════════════════════════════

    public function test_create_plus_assign_creates_and_assigns_inside_the_led_department_but_cannot_edit(): void
    {
        $this->as($this->hrWith('tasks.create', 'tasks.assign'));

        $res = $this->postJson('/api/operations/board/tasks', $this->payload(['assigned_to' => $this->member->id]))->assertCreated();
        $id = $res->json('data.id');
        $task = Task::findOrFail($id);
        $this->assertSame($this->deptHr->id, $task->department_id);
        $this->assertSame($this->member->id, $task->assigned_to);
        $this->assertSame($this->hr->id, $task->created_by);

        // the persisted task is on the board, assigned, with no edit right (tasks.update is missing)
        $board = $this->getJson(self::BOARD)->assertOk();
        $this->assertSame(['can_create' => true, 'can_assign' => true, 'can_update' => false, 'manageable_department_ids' => [$this->deptHr->id]], $board->json('meta.task_actions'));
        $card = collect($board->json('data.columns'))->pluck('items')->flatten(1)->firstWhere('key', "task:{$id}");
        $this->assertSame($this->member->id, $card['assignee']['id']);
        $this->assertSame([false, true], [$card['capabilities']['can_edit'], $card['capabilities']['can_reassign']]);

        $this->patchJson("/api/operations/tasks/{$id}", ['title' => 'تعديل'])->assertForbidden();
        $this->patchJson("/api/admin/tasks/{$id}", ['assigned_to' => $this->member->id])->assertForbidden();
        $this->assertSame($task->title, Task::find($id)->title);

        // reassign is an assignment: allowed (to a member of the department)
        $other = $this->user('volunteer');
        $this->deptHr->users()->attach($other->id);
        $this->postJson("/api/admin/tasks/{$id}/reassign", ['assigned_to' => $other->id])->assertOk();
        $this->assertSame($other->id, Task::find($id)->assigned_to);
    }

    public function test_create_without_assign_cannot_put_the_task_on_anybody(): void
    {
        $this->as($this->hrWith('tasks.create'));

        $this->postJson('/api/admin/tasks', $this->payload(['assigned_to' => $this->member->id]))->assertForbidden();
        $this->postJson('/api/admin/tasks', $this->payload(['assigned_users' => [$this->member->id]]))->assertForbidden();
        $this->assertSame(0, Task::count());

        $this->postJson('/api/admin/tasks', $this->payload())->assertCreated();
        $this->assertNull(Task::first()->assigned_to);
    }

    public function test_the_scope_of_a_leader_on_create(): void
    {
        $this->as($this->hrWith('tasks.create', 'tasks.assign'));

        // another department / no department
        $this->postJson('/api/admin/tasks', $this->payload(['department_id' => $this->deptOther->id]))->assertForbidden()
            ->assertJsonPath('message', 'هذه الإدارة خارج نطاق الإدارات التي تقودها، فلا يمكنك إدارة مهامها.');
        $this->postJson('/api/admin/tasks', ['title' => 'بلا إدارة'])->assertStatus(422);
        $this->postJson('/api/admin/tasks', $this->payload(['department_id' => 999999]))->assertStatus(422)->assertJsonValidationErrors(['department_id']);

        // assignee outside the led departments: a member of another department, a user on no roster
        foreach ([$this->foreign, $this->outsider, $this->otherLeader] as $stranger) {
            $this->postJson('/api/admin/tasks', $this->payload(['assigned_to' => $stranger->id]))->assertForbidden()
                ->assertJsonPath('message', 'لا يمكنك إسناد مهمة إلا لأعضاء الإدارات التي تقودها.');
            $this->postJson('/api/admin/tasks', $this->payload(['assigned_users' => [$this->member->id, $stranger->id]]))->assertForbidden();
        }
        $this->postJson('/api/admin/tasks', $this->payload(['assigned_to' => 999999]))->assertStatus(422)->assertJsonValidationErrors(['assigned_to']);
        // a section of another department
        $foreignSection = \App\Models\DepartmentSection::create(['department_id' => $this->deptOther->id, 'name' => 'قسم', 'status' => 'active']);
        $this->postJson('/api/admin/tasks', $this->payload(['section_id' => $foreignSection->id]))->assertStatus(422)->assertJsonValidationErrors(['section_id']);

        $this->assertSame(0, Task::count(), 'nothing was created by any refusal');

        // the leader may assign to herself, and a group task to several members of the department
        $second = $this->user('volunteer');
        $this->deptHr->users()->attach($second->id);
        $this->postJson('/api/admin/tasks', $this->payload(['assigned_users' => [$this->member->id, $second->id, $this->hr->id]]))->assertCreated();
        $this->assertSame(3, Task::whereNotNull('parent_task_id')->count());
    }

    public function test_the_existing_assignability_check_still_applies(): void
    {
        \App\Models\VolunteerProfile::create(['user_id' => $this->member->id, 'status' => \App\Models\VolunteerProfile::STATUS_ON_LEAVE]);
        $this->as($this->hrWith('tasks.create', 'tasks.assign'));

        $this->postJson('/api/admin/tasks', $this->payload(['assigned_to' => $this->member->id]))->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن إسناد مهام لعضو في فترة إجازة.');
    }

    // ═══ 4. update ═══════════════════════════════════════════════════════════

    public function test_update_edits_only_tasks_of_the_led_department(): void
    {
        $mine = $this->task();
        $theirs = $this->task(['department_id' => $this->deptOther->id, 'assigned_to' => $this->foreign->id, 'title' => 'مهمة تسويق']);
        $none = $this->task(['department_id' => null, 'title' => 'بلا إدارة']);

        $this->as($this->hrWith('tasks.update'));
        $this->patchJson("/api/operations/tasks/{$mine->id}", ['title' => 'عُدِّلت', 'priority' => 'high'])->assertOk()->assertJsonPath('data.title', 'عُدِّلت');
        $this->assertSame(['عُدِّلت', 'high'], [Task::find($mine->id)->title, Task::find($mine->id)->priority]);
        $this->putJson("/api/admin/tasks/{$mine->id}", ['notes' => 'ملاحظة'])->assertOk();

        foreach ([$theirs, $none] as $task) {
            $title = $task->title;
            $this->patchJson("/api/operations/tasks/{$task->id}", ['title' => 'اختراق'])->assertForbidden()
                ->assertJsonPath('message', 'هذه المهمة خارج نطاق الإدارات التي تقودها، فلا يمكنك تعديلها.');
            $this->putJson("/api/admin/tasks/{$task->id}", ['title' => 'اختراق'])->assertForbidden();
            $this->assertSame($title, Task::find($task->id)->title);
        }

        // a task cannot be moved out of the led departments, nor left without one
        $this->patchJson("/api/operations/tasks/{$mine->id}", ['department_id' => $this->deptOther->id])->assertForbidden();
        $this->patchJson("/api/operations/tasks/{$mine->id}", ['department_id' => null])->assertStatus(422);
        $this->assertSame($this->deptHr->id, Task::find($mine->id)->department_id);

        // locked: refused as always
        $mine->forceFill(['locked_at' => now(), 'locked_by' => $this->hr->id])->save();
        $this->patchJson("/api/operations/tasks/{$mine->id}", ['title' => 'x'])->assertStatus(423);
    }

    public function test_changing_the_assignee_through_update_needs_assign_and_a_member_of_the_department(): void
    {
        $task = $this->task();
        $second = $this->user('volunteer');
        $this->deptHr->users()->attach($second->id);

        $this->as($this->hrWith('tasks.update'));
        $this->patchJson("/api/operations/tasks/{$task->id}", ['assigned_to' => $second->id])->assertForbidden();
        // sending the CURRENT assignee again is not an assignment
        $this->patchJson("/api/operations/tasks/{$task->id}", ['assigned_to' => $this->member->id, 'title' => 'نفس المكلَّف'])->assertOk();
        $this->assertSame($this->member->id, Task::find($task->id)->assigned_to);

        $this->as($this->hrWith('tasks.assign'));
        $this->patchJson("/api/operations/tasks/{$task->id}", ['assigned_to' => $this->foreign->id])->assertForbidden();
        $this->patchJson("/api/operations/tasks/{$task->id}", ['assigned_to' => $second->id])->assertOk();
        $this->assertSame($second->id, Task::find($task->id)->assigned_to);
        $this->assertSame('pending', Task::find($task->id)->acceptance_status);
    }

    public function test_reassign_scope_lock_and_validation(): void
    {
        $mine = $this->task();
        $theirs = $this->task(['department_id' => $this->deptOther->id, 'assigned_to' => $this->foreign->id]);
        $none = $this->task(['department_id' => null]);

        $this->as($this->hrWith('tasks.assign'));
        foreach ([$theirs, $none] as $task) {
            $assignee = $task->assigned_to;
            $this->postJson("/api/admin/tasks/{$task->id}/reassign", ['assigned_to' => $this->member->id])->assertForbidden()
                ->assertJsonPath('message', 'هذه المهمة خارج نطاق الإدارات التي تقودها، فلا يمكنك إعادة إسنادها.');
            $this->assertSame($assignee, Task::find($task->id)->assigned_to);
        }
        $this->postJson("/api/admin/tasks/{$mine->id}/reassign", ['assigned_to' => $this->foreign->id])->assertForbidden();
        $this->postJson("/api/admin/tasks/{$mine->id}/reassign", ['assigned_to' => $this->outsider->id])->assertForbidden();
        $this->postJson("/api/admin/tasks/{$mine->id}/reassign", ['assigned_to' => 999999])->assertStatus(422);
        $this->postJson("/api/admin/tasks/{$mine->id}/reassign", [])->assertStatus(422);
        $this->assertSame($this->member->id, Task::find($mine->id)->assigned_to);

        $mine->forceFill(['locked_at' => now(), 'locked_by' => $this->hr->id])->save();
        $this->postJson("/api/admin/tasks/{$mine->id}/reassign", ['assigned_to' => $this->hr->id])->assertStatus(423);
    }

    // ═══ 5. Grants are live: per-user allow / deny / revoke ══════════════════

    public function test_a_per_user_allow_works_without_any_role_grant(): void
    {
        $task = $this->task();
        $this->assignPermission($this->hr, 'tasks.update', 'allow');

        $this->as($this->hr)->patchJson("/api/operations/tasks/{$task->id}", ['title' => 'بمنح شخصي'])->assertOk();
        $this->as($this->user('hr_manager'))->patchJson("/api/operations/tasks/{$task->id}", ['title' => 'x'])->assertForbidden(); // same role, no grant
        $this->assertSame('بمنح شخصي', Task::find($task->id)->title);
    }

    public function test_a_per_user_grant_through_the_access_control_endpoint_works(): void
    {
        $this->as($this->user('super_admin'))->postJson("/api/admin/access/users/{$this->hr->id}/action-permissions/tasks.create/grant")->assertOk();

        $this->as($this->hr)->postJson('/api/admin/tasks', $this->payload())->assertCreated();
        $this->as($this->user('super_admin'))->postJson("/api/admin/access/users/{$this->hr->id}/action-permissions/tasks.create/revoke")->assertOk();
        $this->as($this->hr)->postJson('/api/admin/tasks', $this->payload())->assertForbidden();
    }

    public function test_a_per_user_deny_beats_a_role_grant_and_revoking_refuses_again_immediately(): void
    {
        $task = $this->task();
        $this->grantToRole('hr_manager', 'tasks.update');
        $denied = $this->user('hr_manager');
        $this->deptHr->update(['leader_id' => $this->hr->id]);

        $this->as($this->hr)->patchJson("/api/operations/tasks/{$task->id}", ['title' => 'الدور يكفي'])->assertOk();

        // deny on a second leader of the same role
        $second = $this->dept('إدارة ثانية', $denied);
        $t2 = $this->task(['department_id' => $second->id, 'assigned_to' => null]);
        $this->as($denied)->patchJson("/api/operations/tasks/{$t2->id}", ['title' => 'a'])->assertOk();
        $this->assignPermission($denied, 'tasks.update', 'deny');
        $this->as($denied)->patchJson("/api/operations/tasks/{$t2->id}", ['title' => 'b'])->assertForbidden();
        $this->assertSame('a', Task::find($t2->id)->title);

        // revoke the ROLE grant: the other holder is refused at once
        Role::where('name', 'hr_manager')->firstOrFail()->revokePermission('tasks.update');
        $this->as($this->hr)->patchJson("/api/operations/tasks/{$task->id}", ['title' => 'بعد السحب'])->assertForbidden();
        $this->assertSame('الدور يكفي', Task::find($task->id)->title);
    }

    public function test_revoking_the_role_grant_or_the_personal_allow_refuses_create_and_assign_again_at_once(): void
    {
        $this->as($this->hrWith('tasks.create', 'tasks.assign'))->postJson('/api/operations/board/tasks', $this->payload(['assigned_to' => $this->member->id]))->assertCreated();

        // revoke ONLY the assign half: creating without an assignee still works, with one it does not (and nothing is persisted)
        Role::where('name', 'hr_manager')->firstOrFail()->revokePermission('tasks.assign');
        $count = Task::count();
        $this->as($this->hr)->postJson('/api/operations/board/tasks', $this->payload(['assigned_to' => $this->member->id]))->assertForbidden();
        $this->assertSame($count, Task::count());
        $this->postJson('/api/operations/board/tasks', $this->payload())->assertCreated();

        Role::where('name', 'hr_manager')->firstOrFail()->revokePermission('tasks.create');
        $this->as($this->hr)->postJson('/api/operations/board/tasks', $this->payload())->assertForbidden();

        // a personal allow, then revoked through the access-control endpoint
        $this->assignPermission($this->hr, 'tasks.create', 'allow');
        $this->as($this->hr)->postJson('/api/operations/board/tasks', $this->payload())->assertCreated();
        $this->as($this->user('super_admin'))->postJson("/api/admin/access/users/{$this->hr->id}/action-permissions/tasks.create/revoke")->assertOk();
        $this->as($this->hr)->postJson('/api/operations/board/tasks', $this->payload())->assertForbidden();

        // root is not affected by any of it (even though the hr_manager role row lost the keys)
        foreach (['super_admin', 'tech_admin'] as $role) {
            $this->as($this->user($role))->postJson('/api/operations/board/tasks', $this->payload(['assigned_to' => $this->member->id]))->assertCreated();
        }
    }

    // ═══ 6. The previous holders are unchanged ═══════════════════════════════

    public function test_global_roles_create_edit_and_assign_everywhere(): void
    {
        foreach (['super_admin', 'tech_admin', 'admin', 'operations_manager'] as $role) {
            $actor = $this->user($role);
            $this->as($actor);

            foreach ([$this->deptHr->id, $this->deptOther->id, null] as $dept) {
                $res = $this->postJson('/api/admin/tasks', ['title' => "{$role} task", 'department_id' => $dept, 'assigned_to' => $this->outsider->id])->assertCreated();
                $id = $res->json('data.id');
                $this->patchJson("/api/operations/tasks/{$id}", ['title' => "{$role} edited"])->assertOk();
                $this->putJson("/api/admin/tasks/{$id}", ['priority' => 'high'])->assertOk();
                $this->postJson("/api/operations/tasks/{$id}/reassign", ['assigned_to' => $this->foreign->id])->assertOk();
                $this->postJson("/api/admin/tasks/{$id}/reassign", ['assigned_to' => $this->member->id])->assertOk();
                $this->assertSame($this->member->id, Task::find($id)->assigned_to);
            }
            $this->postJson('/api/operations/board/tasks', $this->payload(['department_id' => $this->deptOther->id]))->assertCreated();
            $this->postJson('/api/operations/tasks', $this->payload(['department_id' => $this->deptOther->id]))->assertCreated();

            $summary = $this->getJson(self::BOARD)->assertOk()->json('meta.task_actions');
            $this->assertSame(['can_create' => true, 'can_assign' => true, 'can_update' => true, 'manageable_department_ids' => null], $summary, $role);
        }
    }

    public function test_executive_admin_and_department_manager_keep_exactly_create(): void
    {
        $task = $this->task(['department_id' => $this->deptOther->id, 'assigned_to' => $this->foreign->id]);

        // executive_admin: global create, no assignee (assigning is a separate key), no edit, no reassign
        $exec = $this->user('executive_admin');
        $this->as($exec)->postJson('/api/operations/board/tasks', $this->payload(['department_id' => $this->deptOther->id]))->assertCreated();
        $this->postJson('/api/operations/board/tasks', $this->payload(['assigned_to' => $this->member->id]))->assertForbidden();
        $this->patchJson("/api/operations/tasks/{$task->id}", ['title' => 'x'])->assertForbidden();
        $this->postJson("/api/admin/tasks/{$task->id}/reassign", ['assigned_to' => $this->member->id])->assertForbidden();

        // department_manager: create in the department it leads only
        $this->as($this->otherLeader)->postJson('/api/operations/board/tasks', $this->payload(['department_id' => $this->deptOther->id]))->assertCreated();
        $this->postJson('/api/operations/board/tasks', $this->payload(['department_id' => $this->deptHr->id]))->assertForbidden();
        $this->patchJson("/api/operations/tasks/{$task->id}", ['title' => 'x'])->assertForbidden();
        $this->postJson("/api/operations/tasks/{$task->id}/reassign", ['assigned_to' => $this->foreign->id])->assertForbidden();
        $this->assertSame(['can_create' => true, 'can_assign' => false, 'can_update' => false, 'manageable_department_ids' => [$this->deptOther->id]], $this->getJson(self::BOARD)->json('meta.task_actions'));

        // a department_manager who leads nothing cannot create anywhere (no scope), and the board says so
        $idle = $this->user('department_manager');
        $this->as($idle)->postJson('/api/operations/board/tasks', $this->payload())->assertForbidden();
        $this->assertSame(['can_create' => false, 'can_assign' => false, 'can_update' => false, 'manageable_department_ids' => []], $this->getJson(self::BOARD)->json('meta.task_actions'));
        $this->assertSame(0, Task::where('title', 'x')->count());
    }

    public function test_lock_unlock_evaluate_delete_and_status_keep_their_own_rules(): void
    {
        $task = $this->task();
        $granted = $this->hrWith('tasks.create', 'tasks.assign', 'tasks.update');

        $this->as($granted);
        $this->postJson("/api/operations/tasks/{$task->id}/lock")->assertOk();
        $this->postJson("/api/operations/tasks/{$task->id}/unlock")->assertOk();
        $this->patchJson("/api/operations/board/tasks/{$task->id}/status", ['status' => 'completed'])->assertOk(); // a status move now follows tasks.update + department scope (test_board_status_move_*)
        $this->deleteJson("/api/admin/tasks/{$task->id}")->assertForbidden(); // deletion stays admin-tier
        $this->assertNotNull(Task::find($task->id));
        $this->getJson("/api/operations/tasks/{$task->id}")->assertForbidden(); // reading task details: unchanged, not implied
    }

    public function test_the_assignee_keeps_accept_reject_and_status(): void
    {
        $pending = $this->task(['acceptance_status' => 'pending']);
        $second = $this->task(['acceptance_status' => 'pending']);

        $this->as($this->member);
        $this->postJson("/api/my-work/tasks/{$pending->id}/accept")->assertOk();
        $this->postJson("/api/my-work/tasks/{$second->id}/reject", ['reason' => 'سبب كافٍ للاعتذار'])->assertOk();
        $this->patchJson("/api/operations/board/tasks/{$pending->id}/status", ['status' => 'needs_evaluation'])->assertOk();
        $this->assertSame('accepted', Task::find($pending->id)->acceptance_status);
        $this->assertSame('rejected', Task::find($second->id)->acceptance_status);

        // and the assignee gets no write right from being the assignee
        $this->patchJson("/api/operations/tasks/{$pending->id}", ['title' => 'x'])->assertForbidden();
        $this->postJson("/api/admin/tasks/{$pending->id}/reassign", ['assigned_to' => $this->member->id])->assertForbidden();
    }

    // ═══ 7. Capabilities are the enforcement ═════════════════════════════════

    /** @return array<string, array{0: User, 1: array<int, string>}> */
    private function actors(): array
    {
        $only = function (string ...$keys): User {
            $u = $this->user('hr_manager');
            $this->dept('إدارة '.Str::random(3), $u);
            foreach ($keys as $k) {
                $this->assignPermission($u, $k, 'allow');
            }

            return $u->fresh();
        };

        return [
            'hr none' => [$this->hr->fresh()],
            'leader create' => [$only('tasks.create')],
            'leader assign' => [$only('tasks.assign')],
            'leader update' => [$only('tasks.update')],
            'leader all three' => [$only('tasks.create', 'tasks.assign', 'tasks.update')],
            'idle manager' => [$this->user('department_manager')],
            'department_manager' => [$this->otherLeader->fresh()],
            'executive_admin' => [$this->user('executive_admin')],
            'operations_manager' => [$this->user('operations_manager')],
            'admin' => [$this->user('admin')],
            'super_admin' => [$this->user('super_admin')],
            'tech_admin' => [$this->user('tech_admin')],
        ];
    }

    public function test_meta_and_card_capabilities_match_what_the_endpoints_enforce(): void
    {
        foreach ($this->actors() as $name => [$actor]) {
            // Where this actor may write (a global one: the HR department; an idle manager: nowhere, the HR department is foreign to them).
            $scope = app(TaskWriteAccess::class)->manageableDepartmentIds($actor);
            $inDept = $scope === null || $scope === [] ? $this->deptHr : Department::findOrFail($scope[0]);
            $inDept->users()->syncWithoutDetaching([$this->member->id]); // a valid assignee for that department

            $mine = $this->task(['title' => "{$name} mine", 'department_id' => $inDept->id, 'assigned_to' => null]);
            $theirs = $this->task(['title' => "{$name} theirs", 'department_id' => $this->deptOther->id, 'assigned_to' => $this->foreign->id]);
            $none = $this->task(['title' => "{$name} none", 'department_id' => null, 'assigned_to' => null]);
            $locked = $this->task(['title' => "{$name} locked", 'department_id' => $inDept->id, 'assigned_to' => null]);
            $locked->forceFill(['locked_at' => now(), 'locked_by' => $this->hr->id])->save();

            $this->as($actor);
            $board = $this->getJson(self::BOARD)->assertOk();
            $meta = $board->json('meta.task_actions');
            $cards = collect($board->json('data.columns'))->pluck('items')->flatten(1)->keyBy('key');

            // can_create == a valid create (in a department the actor may manage, nobody assigned) really succeeds
            $created = $this->postJson('/api/admin/tasks', ['title' => "parity {$name}", 'department_id' => $inDept->id])->status() === 201;
            $this->assertSame($meta['can_create'], $created, "{$name}: can_create");

            foreach (['mine' => $mine, 'theirs' => $theirs, 'none' => $none, 'locked' => $locked] as $label => $task) {
                $card = $cards["task:{$task->id}"];
                $before = Task::find($task->id)->only(['assigned_to', 'notes']);

                // can_reassign == reassigning to a member of the actor's own department really succeeds
                $reassigned = $this->postJson("/api/admin/tasks/{$task->id}/reassign", ['assigned_to' => $this->member->id])->status() === 200;
                $this->assertSame($card['capabilities']['can_reassign'], $reassigned, "{$name}/{$label}: can_reassign");
                Task::whereKey($task->id)->update(['assigned_to' => $before['assigned_to']]);

                // can_edit == an edit that does not touch the assignee really succeeds
                $edited = $this->patchJson("/api/admin/tasks/{$task->id}", ['notes' => "n-{$name}"])->status() === 200;
                $this->assertSame($card['capabilities']['can_edit'], $edited, "{$name}/{$label}: can_edit");
            }

            // the summary never claims less than the cards deliver, and the flags only ever exist on task cards
            $this->assertTrue($meta['can_update'] || ! in_array(true, $cards->pluck('capabilities.can_edit')->all(), true), "{$name}: no editable card without can_update");
            $this->assertTrue($meta['can_assign'] || ! in_array(true, $cards->pluck('capabilities.can_reassign')->all(), true), "{$name}: no reassignable card without can_assign");
        }
    }

    // ═══ 8. The UI helper endpoints ══════════════════════════════════════════

    public function test_the_scope_endpoints_return_only_the_departments_and_members_inside_the_scope(): void
    {
        $task = $this->task();
        $this->as($this->hrWith('tasks.create'));

        $deps = $this->getJson('/api/operations/board/task-scope/departments')->assertOk();
        $this->assertSame([$this->deptHr->id], array_column($deps->json('data'), 'id'));
        $this->assertSame([$this->deptHr->id], $deps->json('meta.manageable_department_ids'));
        $this->assertSame('الموارد البشرية', $deps->json('data.0.name_ar'));

        $members = $this->getJson("/api/operations/board/task-scope/departments/{$this->deptHr->id}/members")->assertOk()->json('data');
        $ids = array_column($members, 'id');
        $this->assertContains($this->member->id, $ids);
        $this->assertContains($this->hr->id, $ids, 'the leader is on the roster');
        $this->assertNotContains($this->foreign->id, $ids);
        $this->assertNotContains($this->outsider->id, $ids);
        $this->getJson("/api/operations/board/task-scope/departments/{$this->deptOther->id}/members")->assertForbidden();

        // the edit prefill: needs update/assign on THIS task
        $this->getJson("/api/operations/board/tasks/{$task->id}/editable")->assertForbidden(); // create alone does not edit
        $this->as($this->hrWith('tasks.update'));
        $res = $this->getJson("/api/operations/board/tasks/{$task->id}/editable")->assertOk();
        $this->assertSame([true, false], [$res->json('data.capabilities.can_edit'), $res->json('data.capabilities.can_reassign')]);
        $this->assertSame($task->title, $res->json('data.title'));
        $foreignTask = $this->task(['department_id' => $this->deptOther->id]);
        $this->getJson("/api/operations/board/tasks/{$foreignTask->id}/editable")->assertForbidden();

        // a global actor sees every active TOP-LEVEL department (the listing operations/departments gave them)
        $this->as($this->user('admin'));
        $all = array_column($this->getJson('/api/operations/board/task-scope/departments')->assertOk()->json('data'), 'id');
        $this->assertEqualsCanonicalizing(Department::active()->whereNull('parent_id')->pluck('id')->all(), $all);
        $this->assertNull($this->getJson('/api/operations/board/task-scope/departments')->json('meta.manageable_department_ids'));
        $this->getJson("/api/operations/board/task-scope/departments/{$this->deptOther->id}/members")->assertOk();
    }

    public function test_the_department_options_mirror_the_old_listing_for_global_actors_and_the_led_departments_for_leaders(): void
    {
        $child = $this->dept('فرع الموارد', $this->user('department_manager'));
        $child->update(['parent_id' => $this->deptHr->id]);
        $childLeader = User::find($child->leader_id);
        $this->hrWith('tasks.create');
        $this->grantToRole('department_manager', 'tasks.create');

        foreach (['admin', 'operations_manager', 'super_admin'] as $role) {
            $ids = array_column($this->as($this->user($role))->getJson('/api/operations/board/task-scope/departments')->assertOk()->json('data'), 'id');
            $this->assertNotContains($child->id, $ids, "{$role}: no child department in the flat list");
            $this->assertContains($this->deptHr->id, $ids);
            $this->assertContains($this->deptOther->id, $ids);
        }

        // the leader of a child department still gets exactly the department they lead
        $res = $this->as($childLeader)->getJson('/api/operations/board/task-scope/departments')->assertOk();
        $this->assertSame([$child->id], array_column($res->json('data'), 'id'));
        $this->assertSame([$child->id], $res->json('meta.manageable_department_ids'));
    }

    // ═══ 9. Preview, scoped tokens, guests ═══════════════════════════════════

    public function test_an_impersonation_preview_acts_as_the_target_and_a_scoped_token_is_not_widened(): void
    {
        $super = $this->user('super_admin');
        $withGrant = $this->user('hr_manager');
        $this->dept('إدارة المعاين', $withGrant);
        $this->assignPermission($withGrant, 'tasks.create', 'allow');
        $withoutGrant = $this->hr;

        $login = $super->createToken('api-token')->plainTextToken;
        $as = function (string $token): static {
            $this->app['auth']->forgetGuards();

            return $this->withToken($token);
        };

        $imp = $as($login)->postJson("/api/admin/impersonate/{$withGrant->id}")->assertOk()->json('token');
        $led = Department::where('leader_id', $withGrant->id)->firstOrFail();
        $as($imp)->postJson('/api/admin/tasks', ['title' => 'من المعاينة', 'department_id' => $led->id])->assertCreated();
        $as($imp)->postJson('/api/admin/tasks', ['title' => 'خارج النطاق', 'department_id' => $this->deptOther->id])->assertForbidden();
        $as($imp)->postJson('/api/admin/impersonate/stop')->assertOk();

        $imp2 = $as($login)->postJson("/api/admin/impersonate/{$withoutGrant->id}")->assertOk()->json('token');
        $as($imp2)->postJson('/api/admin/tasks', ['title' => 'x', 'department_id' => $this->deptHr->id])->assertForbidden();
        $as($imp2)->getJson(self::BOARD)->assertOk()->assertJsonPath('meta.task_actions.can_create', false);
        $as($imp2)->postJson('/api/admin/impersonate/stop')->assertOk();

        $this->assertSame(['من المعاينة'], Task::pluck('title')->all());

        // a SCOPED integration token of a super_admin is refused on every task write (no route declares a token scope)
        $scoped = $super->createToken('integration', ['read:courses'])->plainTextToken;
        $as($scoped)->postJson('/api/admin/tasks', ['title' => 'من رمز مقيد', 'department_id' => $this->deptHr->id])->assertForbidden();
        $task = Task::first();
        $as($scoped)->patchJson("/api/operations/tasks/{$task->id}", ['title' => 'x'])->assertForbidden();
        $as($scoped)->postJson("/api/admin/tasks/{$task->id}/reassign", ['assigned_to' => $this->member->id])->assertForbidden();
        $this->assertSame(1, Task::count());
    }

    public function test_a_cookie_session_preview_acts_as_the_target(): void
    {
        $super = $this->user('super_admin');
        $withGrant = $this->user('hr_manager');
        $led = $this->dept('إدارة المعاين', $withGrant);
        $this->assignPermission($withGrant, 'tasks.create', 'allow');

        $this->withHeaders(self::SPA)->postJson('/api/auth/login', ['email' => $super->email, 'password' => 'secret123'])->assertOk();
        $this->withHeaders(self::SPA)->postJson("/api/admin/impersonate/{$withGrant->id}")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(self::SPA)->postJson('/api/admin/tasks', ['title' => 'جلسة', 'department_id' => $led->id])->assertCreated();
        $this->withHeaders(self::SPA)->postJson('/api/admin/tasks', ['title' => 'جلسة 2', 'department_id' => $this->deptHr->id])->assertForbidden();
        $this->assertSame(1, Task::count());
    }

    public function test_guests_and_plain_members_are_refused(): void
    {
        $task = $this->task();
        $this->postJson('/api/admin/tasks', $this->payload())->assertUnauthorized();
        $this->patchJson("/api/operations/tasks/{$task->id}", ['title' => 'x'])->assertUnauthorized();
        $this->getJson('/api/operations/board/task-scope/departments')->assertUnauthorized();

        foreach (['student', 'volunteer', 'instructor'] as $role) {
            $this->as($this->user($role));
            $this->postJson('/api/admin/tasks', $this->payload())->assertForbidden();
            $this->postJson('/api/operations/board/tasks', $this->payload())->assertForbidden();
        }
        $this->assertSame(1, Task::count());
    }

    // ═══ 9b. Review fixes ════════════════════════════════════════════════════

    public function test_an_update_only_holder_cannot_unassign_a_task(): void
    {
        $task = $this->task();
        $this->as($this->hrWith('tasks.update'));

        $this->patchJson("/api/operations/tasks/{$task->id}", ['assigned_to' => null])->assertForbidden();
        $this->assertSame($this->member->id, Task::find($task->id)->assigned_to);

        // with assign it is allowed (un-assigning is an assignment)
        $this->as($this->hrWith('tasks.assign'))->patchJson("/api/operations/tasks/{$task->id}", ['assigned_to' => null])->assertOk();
        $this->assertNull(Task::find($task->id)->assigned_to);
    }

    public function test_an_out_of_scope_locked_task_is_403_not_423_for_update_and_reassign(): void
    {
        $theirs = $this->task(['department_id' => $this->deptOther->id, 'assigned_to' => $this->foreign->id]);
        $theirs->forceFill(['locked_at' => now(), 'locked_by' => $this->hr->id])->save();
        $none = $this->task(['department_id' => null]);
        $none->forceFill(['locked_at' => now(), 'locked_by' => $this->hr->id])->save();

        $this->as($this->hrWith('tasks.update', 'tasks.assign'));
        foreach ([$theirs, $none] as $task) {
            $this->patchJson("/api/operations/tasks/{$task->id}", ['title' => 'x'])->assertForbidden();
            $this->postJson("/api/admin/tasks/{$task->id}/reassign", ['assigned_to' => $this->member->id])->assertForbidden();
        }
    }

    public function test_editable_gives_description_and_notes_only_to_a_holder_who_can_edit(): void
    {
        $task = $this->task(['description' => 'وصف خاص', 'notes' => 'ملاحظات خاصة']);

        $this->as($this->hrWith('tasks.assign'));
        $res = $this->getJson("/api/operations/board/tasks/{$task->id}/editable")->assertOk();
        $this->assertSame([false, true], [$res->json('data.capabilities.can_edit'), $res->json('data.capabilities.can_reassign')]);
        $this->assertArrayNotHasKey('description', $res->json('data'));
        $this->assertArrayNotHasKey('notes', $res->json('data'));
        $this->assertStringNotContainsString('وصف خاص', (string) $res->getContent());

        $this->as($this->hrWith('tasks.update'));
        $res = $this->getJson("/api/operations/board/tasks/{$task->id}/editable")->assertOk();
        $this->assertSame(['وصف خاص', 'ملاحظات خاصة'], [$res->json('data.description'), $res->json('data.notes')]);
    }

    public function test_the_query_count_of_the_board_does_not_grow_with_the_cards_for_a_leader_holding_update_and_assign(): void
    {
        $this->hrWith('tasks.update', 'tasks.assign');
        $seed = function (int $n): void {
            foreach (range(1, $n) as $i) {
                foreach (['idea', 'planning', 'in_progress', 'needs_evaluation', 'completed', 'cancelled'] as $status) {
                    $this->task(['status' => $status, 'department_id' => [$this->deptHr->id, $this->deptOther->id, null][$i % 3]]);
                }
            }
        };
        $count = function (): int {
            $this->as($this->hr);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson(self::BOARD)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $seed(2);
        $small = $count();
        $seed(12);
        $large = $count();

        $this->assertSame($small, $large, 'the per-card capabilities add no query per card');
    }

    public function test_a_delegated_holder_cannot_change_credit_bearing_fields_once_the_task_is_awaiting_approval_or_done(): void
    {
        $waiting = $this->task(['status' => 'waiting_approval', 'task_scope' => 'medium', 'points' => 100, 'estimated_hours' => 2, 'manager_approved_at' => now()]);
        $done = $this->task(['status' => 'completed', 'task_scope' => 'medium', 'points' => 100, 'estimated_hours' => 2]);
        $second = $this->user('volunteer');
        $this->deptHr->users()->attach($second->id);

        $this->as($this->hrWith('tasks.update', 'tasks.assign'));
        foreach ([$waiting, $done] as $task) {
            foreach ([['points' => 150], ['task_scope' => 'large'], ['estimated_hours' => 9], ['status' => 'in_progress'], ['assigned_to' => $second->id]] as $change) {
                $res = $this->patchJson("/api/operations/tasks/{$task->id}", $change)->assertForbidden();
                $this->assertArrayHasKey(array_key_first($change), $res->json('errors'));
            }
            $this->postJson("/api/admin/tasks/{$task->id}/reassign", ['assigned_to' => $second->id])->assertForbidden();
            $fresh = Task::find($task->id);
            $this->assertSame([100, 'medium', 2.0, $this->member->id], [$fresh->points, $fresh->task_scope, $fresh->estimated_hours, $fresh->assigned_to]);

            // what is not credit-bearing is still editable, and re-sending the SAME values is not a change
            $this->patchJson("/api/operations/tasks/{$task->id}", ['title' => 'عنوان جديد', 'points' => 100, 'task_scope' => 'medium', 'estimated_hours' => 2, 'status' => $task->status, 'assigned_to' => $this->member->id])->assertOk();
        }

        // the board tells the UI the same: not reassignable, still editable
        $card = collect($this->getJson(self::BOARD)->json('data.columns'))->pluck('items')->flatten(1)->firstWhere('key', "task:{$waiting->id}");
        $this->assertSame([true, false], [$card['capabilities']['can_edit'], $card['capabilities']['can_reassign']]);

        // the global tier is exactly as before
        $this->as($this->user('admin'));
        $this->patchJson("/api/operations/tasks/{$waiting->id}", ['points' => 150, 'estimated_hours' => 3, 'status' => 'planning'])->assertOk();
        $this->postJson("/api/admin/tasks/{$done->id}/reassign", ['assigned_to' => $second->id])->assertOk();
        $this->assertSame([150, $second->id], [Task::find($waiting->id)->points, Task::find($done->id)->assigned_to]);
    }

    public function test_a_string_assignee_equal_to_the_current_one_neither_resets_acceptance_nor_bypasses_the_guard(): void
    {
        $task = $this->task(['acceptance_status' => 'accepted']);
        $second = $this->user('volunteer');
        $this->deptHr->users()->attach($second->id);

        // update-only holder: the SAME assignee sent as a string is not an assignment
        $this->as($this->hrWith('tasks.update'));
        $this->patchJson("/api/operations/tasks/{$task->id}", ['assigned_to' => (string) $this->member->id, 'title' => 'ثابت'])->assertOk();
        $this->assertSame(['accepted', $this->member->id], [Task::find($task->id)->acceptance_status, Task::find($task->id)->assigned_to]);

        // ...and a DIFFERENT assignee as a string is still caught by the assign guard
        $this->patchJson("/api/operations/tasks/{$task->id}", ['assigned_to' => (string) $second->id])->assertForbidden();
        $this->assertSame($this->member->id, Task::find($task->id)->assigned_to);

        // a real change as a string stores an int and resets acceptance to pending
        $this->as($this->user('admin'))->patchJson("/api/operations/tasks/{$task->id}", ['assigned_to' => (string) $second->id])->assertOk();
        $this->assertSame([$second->id, 'pending'], [Task::find($task->id)->assigned_to, Task::find($task->id)->acceptance_status]);
    }

    public function test_the_ai_create_task_tool_follows_the_permission_and_the_scope(): void
    {
        $tool = new CreateTaskTool();
        $run = fn (array $args, ?int $uid) => $tool->execute($args, $uid);

        // global roles keep working (acceptance like TaskController::store)
        $admin = $this->user('admin');
        $res = $run(['title' => 'من المساعد', 'assigned_to' => $this->member->id], $admin->id);
        $this->assertArrayHasKey('task_id', $res);
        $this->assertSame('pending', Task::find($res['task_id'])->acceptance_status);
        $self = $run(['title' => 'لنفسي', 'assigned_to' => $admin->id], $admin->id);
        $this->assertSame('accepted', Task::find($self['task_id'])->acceptance_status);
        $this->assertArrayHasKey('task_id', $run(['title' => 'root'], $this->user('super_admin')->id));

        // no actor, or an actor the role gate does not offer the tool to
        $before = Task::count();
        $this->assertArrayHasKey('error', $run(['title' => 'x'], null));
        $this->assertArrayHasKey('error', $run(['title' => 'x'], $this->hr->id));
        $this->assertSame($before, Task::count());

        // a department manager: scoped to the department it leads, and assigning needs tasks.assign
        $dm = $this->otherLeader;
        $this->assertArrayHasKey('error', $run(['title' => 'بلا إدارة'], $dm->id));
        $this->assertArrayHasKey('error', $run(['title' => 'إدارة غريبة', 'department_id' => $this->deptHr->id], $dm->id));
        $this->assertArrayHasKey('task_id', $run(['title' => 'إدارتي', 'department_id' => $this->deptOther->id], $dm->id));
        $this->assertArrayHasKey('error', $run(['title' => 'مع مكلَّف', 'department_id' => $this->deptOther->id, 'assigned_to' => $this->foreign->id], $dm->id));

        $this->grantToRole('department_manager', 'tasks.assign');
        $this->assertArrayHasKey('task_id', $run(['title' => 'مع مكلَّف', 'department_id' => $this->deptOther->id, 'assigned_to' => $this->foreign->id], $dm->id));
        $this->assertArrayHasKey('error', $run(['title' => 'مكلَّف غريب', 'department_id' => $this->deptOther->id, 'assigned_to' => $this->member->id], $dm->id));

        // a revoked grant (personal deny) refuses at once
        $this->assignPermission($dm, 'tasks.create', 'deny');
        $count = Task::count();
        $this->assertArrayHasKey('error', $run(['title' => 'بعد السحب', 'department_id' => $this->deptOther->id], $dm->id));
        $this->assertSame($count, Task::count());
    }

    // ═══ 10. The migration ═══════════════════════════════════════════════════

    private function migration(): object
    {
        return require base_path('database/migrations/2026_11_30_000001_create_task_write_permissions.php');
    }

    public function test_the_migration_is_idempotent_grants_only_the_previous_holders_and_never_touches_other_grants(): void
    {
        // Production state BEFORE the migration: role rows with their old grants, no tasks.* rows.
        DB::table('permission_role')->whereIn('permission_id', Permission::whereIn('name', ['tasks.create', 'tasks.assign', 'tasks.update'])->pluck('id'))->delete();
        $hrRole = Role::where('name', 'hr_manager')->firstOrFail();
        $hrBefore = $hrRole->permissions()->pluck('name')->sort()->values()->all();
        $totalBefore = DB::table('permission_role')->count();
        Permission::where('name', 'tasks.create')->update(['label_ar' => 'تسمية معدّلة من المدير']);

        $migration = $this->migration();
        $migration->up();
        $migration->up(); // re-run

        $grants = fn (string $role) => Role::where('name', $role)->firstOrFail()->permissions()->pluck('name')->filter(fn ($n) => str_starts_with($n, 'tasks.'))->sort()->values()->all();
        $this->assertSame(['tasks.assign', 'tasks.create', 'tasks.update'], $grants('admin'));
        $this->assertSame(['tasks.assign', 'tasks.create', 'tasks.update'], $grants('operations_manager'));
        $this->assertSame(['tasks.create'], $grants('executive_admin'));
        $this->assertSame(['tasks.create'], $grants('department_manager'));
        foreach (Role::whereNotIn('name', ['admin', 'operations_manager', 'executive_admin', 'department_manager'])->get() as $role) {
            $this->assertSame([], $grants($role->name), "{$role->name} gets nothing");
        }
        $this->assertSame($hrBefore, $hrRole->permissions()->pluck('name')->sort()->values()->all(), 'hr_manager is untouched');
        $this->assertSame($totalBefore + 8, DB::table('permission_role')->count(), '3+3+1+1 grants, once each, nothing else added or removed');
        $this->assertSame(3, Permission::whereIn('name', ['tasks.create', 'tasks.assign', 'tasks.update'])->count());
        $this->assertSame('تسمية معدّلة من المدير', Permission::where('name', 'tasks.create')->value('label_ar'), 'an edited label is never overwritten');

        // down() removes exactly the rows (and their grants), then up() restores them
        $migration->down();
        $this->assertSame(0, Permission::whereIn('name', ['tasks.create', 'tasks.assign', 'tasks.update'])->count());
        $this->assertSame($totalBefore, DB::table('permission_role')->count());
    }

    public function test_the_migration_adds_the_delegation_nodes_only_when_the_tree_exists(): void
    {
        $this->seed(PermissionNodeSeeder::class);
        DB::table('permission_nodes')->whereIn('key', ['tasks.create', 'tasks.assign', 'tasks.update', 'section.tasks'])->delete();

        $this->migration()->up();
        $this->migration()->up();

        $section = DB::table('permission_nodes')->where('key', 'section.tasks')->first();
        $this->assertSame('المهام والعمليات', $section->label_ar);
        $nodes = DB::table('permission_nodes')->where('parent_id', $section->id)->orderBy('key')->get();
        $this->assertSame(['tasks.assign', 'tasks.create', 'tasks.update'], $nodes->pluck('key')->all());
        $this->assertSame(['إسناد المهام وإعادة إسنادها', 'إنشاء مهمة تشغيلية', 'تعديل المهام القائمة'], $nodes->pluck('label_ar')->all());
    }

    // ═══ 10b. The board's status move follows the same rule as the edit ══════

    private function move(Task $task, string $status = 'in_progress')
    {
        return $this->patchJson("/api/operations/board/tasks/{$task->id}/status", ['status' => $status]);
    }

    public function test_board_status_move_needs_tasks_update_and_the_tasks_department_for_a_role_that_is_not_global(): void
    {
        $mine = $this->task(['status' => 'planning']);
        $foreign = $this->task(['department_id' => $this->deptOther->id, 'status' => 'planning']);
        $noDept = $this->task(['department_id' => null, 'status' => 'planning']);

        // the role name alone is no longer a pass: the leader WITHOUT tasks.update is refused
        $this->as($this->hr);
        $this->move($mine)->assertForbidden()->assertJsonPath('message', 'ليست لديك صلاحية تعديل المهام.');
        $this->assertSame('planning', $mine->fresh()->status);

        // with the permission: the department they lead only
        $granted = $this->hrWith('tasks.update');
        $this->as($granted);
        $this->move($mine)->assertOk();
        $this->assertSame('in_progress', $mine->fresh()->status);
        $this->move($foreign)->assertForbidden();
        $this->move($noDept)->assertForbidden();
        $this->assertSame(['planning', 'planning'], [$foreign->fresh()->status, $noDept->fresh()->status]);

        // a department_manager of ANOTHER department with the permission cannot move this one
        $this->assignPermission($this->otherLeader, 'tasks.update', 'allow');
        $this->as($this->otherLeader);
        $this->move($mine)->assertForbidden();
        $this->move($foreign)->assertOk();

        // revoking it (per-user deny) closes the move at once
        $this->assignPermission($granted, 'tasks.update', 'deny');
        $this->as($granted);
        $this->move($mine, 'planning')->assertForbidden();
        $this->assertSame('in_progress', $mine->fresh()->status);
    }

    public function test_board_status_move_is_refused_to_an_hr_manager_who_does_not_lead_the_department_even_with_the_permission(): void
    {
        $task = $this->task(['status' => 'planning']);
        $second = $this->user('hr_manager');
        $this->deptHr->users()->attach($second->id); // a member of HR, not its leader
        $this->grantToRole('hr_manager', 'tasks.update');

        $this->as($second->fresh());
        $this->move($task)->assertForbidden();
        $this->assertSame('planning', $task->fresh()->status);
    }

    public function test_board_status_move_does_not_reopen_or_move_credit_bearing_work_for_a_delegated_leader(): void
    {
        $this->grantToRole('hr_manager', 'tasks.update');
        $leader = $this->hr->fresh();

        $waiting = $this->task(['status' => 'waiting_approval']);
        $done = $this->task(['status' => 'completed']);
        $approved = $this->task(['status' => 'in_progress', 'manager_approved_at' => now()]);
        $hrApproved = $this->task(['status' => 'in_progress', 'hr_approved_at' => now()]);

        $this->as($leader);
        foreach ([$waiting, $done, $approved, $hrApproved] as $t) {
            $before = $t->fresh()->status;
            $this->move($t, 'in_progress')->assertForbidden();
            $this->assertSame($before, $t->fresh()->status);
        }

        // global roles are unchanged: they still move (and reopen) any task, any department
        foreach (['admin', 'executive_admin', 'tech_admin', 'super_admin', 'operations_manager'] as $role) {
            $this->as($this->user($role));
            $this->move($done, 'in_progress')->assertOk();
            $this->assertSame('in_progress', $done->fresh()->status);
            $this->move($done, 'completed')->assertOk();
        }
    }

    public function test_board_status_move_keeps_the_assignee_path_and_hides_the_lock_from_an_unauthorised_caller(): void
    {
        $mine = $this->task(['assigned_to' => $this->member->id, 'status' => 'planning']);
        $locked = $this->task(['status' => 'planning', 'locked_at' => now()]);

        // the assignee moves their own task with no permission at all; somebody else's stays refused
        $this->as($this->member);
        $this->move($mine)->assertOk();
        $this->move($this->task(['assigned_to' => $this->foreign->id]))->assertForbidden();

        // a locked task answers 403 to who may not move it (no leak), 423 to who may
        $this->as($this->outsider);
        $this->move($locked)->assertForbidden();
        $this->as($this->hrWith('tasks.update'));
        $this->move($locked)->assertStatus(423);
        $this->as($this->user('admin'));
        $this->move($locked)->assertStatus(423);
        $this->assertSame('planning', $locked->fresh()->status);
    }

    public function test_board_status_move_gives_a_plain_assignee_the_member_rules_of_my_work(): void
    {
        $this->as($this->member);

        // a completed task stays completed, `completed` is reserved for the evaluation flow, and an unaccepted task is answered first
        $done = $this->task(['assigned_to' => $this->member->id, 'status' => 'completed']);
        $this->move($done, 'in_progress')->assertForbidden();
        $this->assertSame('completed', $done->fresh()->status);

        $own = $this->task(['assigned_to' => $this->member->id, 'status' => 'in_progress']);
        $this->move($own, 'completed')->assertForbidden();
        $this->assertSame('in_progress', $own->fresh()->status);
        $this->move($own, 'needs_evaluation')->assertOk();

        $pending = $this->task(['assigned_to' => $this->member->id, 'status' => 'idea', 'acceptance_status' => 'pending']);
        $this->move($pending, 'in_progress')->assertStatus(409);
        $this->assertSame('idea', $pending->fresh()->status);
    }

    public function test_board_status_move_keeps_a_managers_own_rules_when_the_manager_is_also_the_assignee(): void
    {
        // a global actor who happens to be the assignee still moves (and completes / reopens) as a manager
        $admin = $this->user('admin');
        $task = $this->task(['assigned_to' => $admin->id, 'status' => 'in_progress']);
        $this->as($admin);
        $this->move($task, 'completed')->assertOk();
        $this->move($task, 'in_progress')->assertOk();

        // a delegated leader who is the assignee of a credit-locked task gets the member rules, not the manager ones
        $leader = $this->hrWith('tasks.update');
        $done = $this->task(['assigned_to' => $leader->id, 'status' => 'completed']);
        $this->as($leader);
        $this->move($done, 'in_progress')->assertForbidden();
        $this->assertSame('completed', $done->fresh()->status);
    }

    // ═══ 10c. Lock / unlock follow the same permission + department scope ═══

    public function test_lock_and_unlock_need_tasks_update_and_the_tasks_department_for_a_role_that_is_not_global(): void
    {
        $mine = $this->task();
        $foreign = $this->task(['department_id' => $this->deptOther->id]);
        $noDept = $this->task(['department_id' => null]);
        $lock = fn (Task $t) => $this->postJson("/api/operations/tasks/{$t->id}/lock");
        $unlock = fn (Task $t) => $this->postJson("/api/operations/tasks/{$t->id}/unlock");

        // the role name alone is no longer a pass
        $this->as($this->hr);
        $lock($mine)->assertForbidden()->assertJsonPath('message', 'ليست لديك صلاحية تعديل المهام.');
        $this->assertNull($mine->fresh()->locked_at);

        // with the permission: the department they lead only
        $granted = $this->hrWith('tasks.update');
        $this->as($granted);
        $lock($mine)->assertOk();
        $this->assertNotNull($mine->fresh()->locked_at);
        $lock($foreign)->assertForbidden();
        $lock($noDept)->assertForbidden();
        $this->assertNull($foreign->fresh()->locked_at);
        $unlock($mine)->assertOk();
        $this->assertNull($mine->fresh()->locked_at);

        // credit-bearing work is never frozen by a delegated leader
        foreach (['waiting_approval', 'completed'] as $status) {
            $credited = $this->task(['status' => $status]);
            $lock($credited)->assertForbidden();
            $this->assertNull($credited->fresh()->locked_at);
        }
        $approved = $this->task(['status' => 'in_progress', 'manager_approved_at' => now()]);
        $lock($approved)->assertForbidden();

        // a member of HR who is not its leader gets nothing from the permission
        $second = $this->user('hr_manager');
        $this->deptHr->users()->attach($second->id);
        $this->as($second->fresh());
        $lock($mine)->assertForbidden();

        // revoking it closes both at once
        $this->as($granted);
        $lock($mine)->assertOk();
        $this->assignPermission($granted, 'tasks.update', 'deny');
        $this->as($granted);
        $unlock($mine)->assertForbidden();
        $this->assertNotNull($mine->fresh()->locked_at);
    }

    public function test_a_delegated_leader_cannot_lift_a_lock_set_by_somebody_else_but_global_actors_still_do_everything(): void
    {
        $admin = $this->user('admin');
        $task = $this->task();
        $leader = $this->hrWith('tasks.update');

        $this->as($admin);
        $this->postJson("/api/operations/tasks/{$task->id}/lock")->assertOk();
        $this->as($leader);
        $this->postJson("/api/operations/tasks/{$task->id}/unlock")->assertForbidden();
        $this->assertNotNull($task->fresh()->locked_at);
        // re-locking it must not take the lock over (and then lift it): the owner stays the admin
        $this->postJson("/api/operations/tasks/{$task->id}/lock")->assertForbidden();
        $this->assertSame($admin->id, (int) $task->fresh()->locked_by);
        $this->postJson("/api/operations/tasks/{$task->id}/unlock")->assertForbidden();
        $this->assertNotNull($task->fresh()->locked_at);

        // a lock with no owner (its locker's account was deleted) is a global actor's to lift, not the leader's
        $task->forceFill(['locked_by' => null])->save();
        $this->postJson("/api/operations/tasks/{$task->id}/unlock")->assertForbidden();
        $this->as($admin);
        $this->postJson("/api/operations/tasks/{$task->id}/unlock")->assertOk();
        $this->postJson("/api/operations/tasks/{$task->id}/lock")->assertOk();
        $this->as($leader);

        // the lock is the admin's to lift, and any other global actor may too
        foreach (['admin', 'executive_admin', 'tech_admin', 'super_admin', 'operations_manager'] as $role) {
            $this->as($admin);
            $this->postJson("/api/operations/tasks/{$task->id}/lock")->assertOk();
            $this->as($this->user($role));
            $this->postJson("/api/operations/tasks/{$task->id}/unlock")->assertOk();
            $this->assertNull($task->fresh()->locked_at);
        }

        // a global actor locks a task of any department, with no department, and the log still records it
        $other = $this->task(['department_id' => $this->deptOther->id]);
        $this->as($admin);
        $this->postJson("/api/operations/tasks/{$other->id}/lock")->assertOk();
        $this->assertSame($admin->id, (int) $other->fresh()->locked_by);
    }

    // ═══ 11. One rule, one place ═════════════════════════════════════════════

    public function test_the_service_is_the_single_source_of_the_rule(): void
    {
        $svc = app(TaskWriteAccess::class);
        $this->assertTrue($svc->isGlobal($this->user('operations_manager')));
        $this->assertTrue($svc->isGlobal($this->user('executive_admin')));
        $this->assertFalse($svc->isGlobal($this->hr));
        $this->assertSame([$this->deptHr->id], $svc->manageableDepartmentIds($this->hr));
        $this->assertTrue($svc->assigneeInScope($this->hr, $this->member->id));
        $this->assertFalse($svc->assigneeInScope($this->hr, $this->foreign->id));
        $this->assertTrue($svc->assigneeInScope($this->user('admin'), $this->outsider->id));
    }
}
