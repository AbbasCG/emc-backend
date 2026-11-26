<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use App\Support\Permissions;

/**
 * THE single rule for who may CREATE, ASSIGN and EDIT operational tasks, used by both the enforcement (TaskController, the
 * route middleware) and the capabilities the work board tells the UI (OperationsWorkBoard `meta.task_actions` and the per-card
 * `capabilities.can_edit / can_reassign`). The UI never infers anything from a role name; it reads what this class answers.
 *
 * Two layers, both required:
 *
 *   1. PERMISSION. tasks.create / tasks.assign / tasks.update are independent keys, checked with User::hasPermission() (role grant
 *      + per-user allow - per-user deny; super_admin / tech_admin hold them through Permissions::all()). Holding one never
 *      implies another.
 *
 *   2. SCOPE (department + assignee). A GLOBAL actor acts on every department: the roles DepartmentAccessService treats as global
 *      (super_admin, tech_admin, executive_admin, admin) plus operations_manager, who has always been able to edit any task
 *      (operations/tasks/{task} was role-gated to it). Whoever holds the tasks.manage_all_departments scope permission acts on every
 *      department's tasks and people too but is NOT global: it stays bound by the credit-bearing and lock guards, and a task that
 *      belongs to NO department stays a global actor's alone (see actsOnEveryDepartment). Everybody else acts only on the departments they LEAD
 *      (departments.leader_id, the canonical leadership relationship: DepartmentAccessService::canManageDepartment), and may
 *      only put a task on someone who belongs to one of those departments' rosters (DepartmentRosterService: leadership seats,
 *      active team_members, user_departments pivot, active team profiles).
 *
 * A task outside the scope (another department, or no department) stays READABLE on the board; every write is refused.
 */
class TaskWriteAccess
{
    public const CREATE = Permissions::TASKS_CREATE;

    public const ASSIGN = Permissions::TASKS_ASSIGN;

    public const UPDATE = Permissions::TASKS_UPDATE;

    /** SCOPE permission: tasks of every department (see actsOnEveryDepartment). It grants no write by itself. */
    public const MANAGE_ALL_DEPARTMENTS = Permissions::TASKS_MANAGE_ALL_DEPARTMENTS;

    /** @var array<int, array<int, int>|null> viewer id => manageable department ids (null = all), per instance */
    private array $managed = [];

    /** @var array<int, array<int, true>> viewer id => user ids of the rosters of the departments they manage */
    private array $assignees = [];

    public function __construct(
        private readonly DepartmentAccessService $departments,
        private readonly DepartmentRosterService $roster,
    ) {}

    // ── Scope ────────────────────────────────────────────────────────────────

    /**
     * A fully global actor (see the class doc): acts on every department AND is exempt from the credit-bearing guards (a delegated
     * holder never edits, reassigns, moves or locks work that is awaiting approval / approved / completed).
     */
    public function isGlobal(User $user): bool
    {
        return $this->departments->isGlobal($user) || $user->getDepartmentSpecializedRole() === 'operations_manager';
    }

    /**
     * Acts on every department's tasks and people without being global: whoever holds the SCOPE permission
     * (tasks.manage_all_departments, granted one account at a time through the role editor or the per-user action permissions).
     * It widens the departments ONLY: each action still needs its own tasks.create / assign / update, a per-user deny of any of
     * them still closes it, and the holder stays a DELEGATED actor (isGlobal() is false): the credit-bearing guards, the lock
     * ownership and the transition rules still bind them, and it implies neither evaluation nor deletion.
     */
    public function actsOnEveryDepartment(User $user): bool
    {
        return $this->isGlobal($user) || $user->hasPermission(self::MANAGE_ALL_DEPARTMENTS);
    }

    /** The departments this actor may write tasks in: null = every department, otherwise the list (possibly empty). @return array<int, int>|null */
    public function manageableDepartmentIds(User $user): ?array
    {
        if (array_key_exists($user->id, $this->managed)) {
            return $this->managed[$user->id];
        }

        return $this->managed[$user->id] = $this->actsOnEveryDepartment($user)
            ? null
            : $this->departments->getLedDepartmentIds($user)->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * What the UI is told (`manageable_department_ids`): null ONLY for a global actor. A holder of the all-departments scope is not
     * global (a department is still required, credit-bearing work stays frozen), so they get the explicit list of every active
     * department instead of the null the frontend reads as "global".
     *
     * @return array<int, int>|null
     */
    public function reportedDepartmentIds(User $user): ?array
    {
        $ids = $this->manageableDepartmentIds($user);
        if ($ids !== null || $this->isGlobal($user)) {
            return $ids;
        }

        return Department::query()->active()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    public function inScope(User $user, ?int $departmentId): bool
    {
        $ids = $this->manageableDepartmentIds($user);
        if ($ids === null) {
            // every department, but a task that belongs to NO department (a personal task) is a global actor's alone
            return $departmentId !== null || $this->isGlobal($user);
        }

        return $departmentId !== null && in_array($departmentId, $ids, true);
    }

    /** May this actor put a task on this user? Global: any user. Otherwise: someone on the roster of a department they manage. */
    public function assigneeInScope(User $actor, int $assigneeId): bool
    {
        $ids = $this->manageableDepartmentIds($actor);
        if ($ids === null) {
            return true;
        }

        if (! isset($this->assignees[$actor->id])) {
            $set = [];
            foreach (Department::query()->whereIn('id', $ids)->get() as $department) {
                foreach ($this->roster->members($department) as $member) {
                    $set[(int) $member['id']] = true;
                }
            }
            $this->assignees[$actor->id] = $set;
        }

        return isset($this->assignees[$actor->id][$assigneeId]);
    }

    // ── Permission + scope ───────────────────────────────────────────────────

    public function holds(User $user, string $permission): bool
    {
        return $user->hasPermission($permission);
    }

    /** Holds the key AND has somewhere to act (a non-global actor who leads no department can act nowhere). */
    public function canAnywhere(User $user, string $permission): bool
    {
        if (! $this->holds($user, $permission)) {
            return false;
        }
        $ids = $this->manageableDepartmentIds($user);

        return $ids === null || $ids !== [];
    }

    /** May this actor edit this task right now: tasks.update, the task's department in scope, and not locked. */
    public function canEdit(User $user, Task $task): bool
    {
        return $task->locked_at === null && $this->holds($user, self::UPDATE) && $this->inScope($user, $this->departmentOf($task));
    }

    /** May this actor reassign this task right now: tasks.assign, the task's department in scope, and not locked. */
    public function canReassign(User $user, Task $task): bool
    {
        if (! $this->isGlobal($user) && $this->isCreditLocked($task)) {
            return false; // a delegated holder never moves the credit of a task that is awaiting approval / approved / completed
        }

        return $task->locked_at === null && $this->holds($user, self::ASSIGN) && $this->inScope($user, $this->departmentOf($task));
    }

    /** Awaiting approval, approved by the manager or HR, or completed: its points, size, hours, status and assignee are credit-bearing. */
    public function isCreditLocked(Task $task): bool
    {
        return TaskTransitionRules::isCreditBearing($task);
    }

    public function departmentOf(Task $task): ?int
    {
        return $task->department_id === null ? null : (int) $task->department_id;
    }

    // ── What the UI is told ──────────────────────────────────────────────────

    /**
     * `meta.task_actions` of the work board. `manageable_department_ids` is null for an actor who acts on every department, the
     * list of led department ids otherwise (an empty list = nowhere, and then no can_* flag is true).
     *
     * @return array{can_create: bool, can_assign: bool, can_update: bool, manageable_department_ids: array<int, int>|null}
     */
    public function summary(User $user): array
    {
        return [
            'can_create' => $this->canAnywhere($user, self::CREATE),
            'can_assign' => $this->canAnywhere($user, self::ASSIGN),
            'can_update' => $this->canAnywhere($user, self::UPDATE),
            'manageable_department_ids' => $this->reportedDepartmentIds($user),
        ];
    }

    /** The two write flags of a TASK card. @return array{can_edit: bool, can_reassign: bool} */
    public function cardCapabilities(User $user, Task $task): array
    {
        return ['can_edit' => $this->canEdit($user, $task), 'can_reassign' => $this->canReassign($user, $task)];
    }

    /** True when the actor holds ANY of the three keys (the entry rule of the scope / prefill endpoints). */
    public function holdsAny(User $user): bool
    {
        return $this->holds($user, self::CREATE) || $this->holds($user, self::ASSIGN) || $this->holds($user, self::UPDATE);
    }

    /**
     * Active departments the actor may write in, as {id, name, name_ar}. A GLOBAL actor gets exactly what operations/departments
     * (Admin\DepartmentController::index) listed for them: top-level departments only (parent_id null), active, by sort_order.
     * Anyone else gets exactly the departments they lead (active), a child department included.
     */
    public function departmentOptions(User $user): array
    {
        $ids = $this->manageableDepartmentIds($user);
        // a global actor gets the top-level departments (the old listing); a scope holder every active department, a child included
        // (inScope accepts it), because they are not global and must pick one
        $topLevelOnly = $ids === null && $this->isGlobal($user);

        return Department::query()
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids), fn ($q) => $topLevelOnly ? $q->whereNull('parent_id') : $q)
            ->active()
            ->orderBy('sort_order')
            ->get(['id', 'name', 'name_ar'])
            ->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name, 'name_ar' => $d->name_ar])
            ->values()
            ->all();
    }
}
