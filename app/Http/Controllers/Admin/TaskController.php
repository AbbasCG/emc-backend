<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskChecklistItemResource;
use App\Http\Resources\TaskCommentResource;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskChecklistItem;
use App\Models\TaskComment;
use App\Models\TaskEvaluation;
use App\Services\ActivityLogger;
use App\Services\TaskAcceptanceService;
use App\Services\TaskCollaborationService;
use App\Services\TaskStatusService;
use App\Services\TaskTransitionRules;
use App\Services\TaskWriteAccess;
use App\Services\TaskWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    /**
     * The write rule, rebuilt at the start of every write action: its per-actor scope caches must live for ONE request only (the
     * router keeps a controller instance per route, so a constructor-injected service would outlive the request in a long process).
     */
    private TaskWriteAccess $writeAccess;

    public function __construct()
    {
        $this->writeAccess = app(TaskWriteAccess::class);
    }

    /** 403 with an Arabic message when the actor does not hold the write permission (the routes check it too: defence in depth). */
    private function refuseWithout(Request $request, string $permission, string $message): ?JsonResponse
    {
        return $this->writeAccess->holds($request->user(), $permission)
            ? null
            : response()->json(['success' => false, 'message' => $message], 403);
    }

    /**
     * The department / assignee scope of a write (see TaskWriteAccess). Returns the refusal response, or null when allowed.
     * $departmentId is the department the task will live in; $assigneeIds every user the write puts the task on.
     *
     * @param  array<int, int>  $assigneeIds
     */
    private function refuseOutOfScope(Request $request, ?int $departmentId, array $assigneeIds = []): ?JsonResponse
    {
        $user = $request->user();
        if ($this->writeAccess->manageableDepartmentIds($user) === null) {
            return null; // acts on every department and everybody
        }

        if ($departmentId === null) {
            return response()->json(['success' => false, 'message' => 'حدّد الإدارة التي ستتبعها المهمة؛ صلاحيتك مقتصرة على الإدارات التي تقودها.', 'errors' => ['department_id' => ['حدّد الإدارة.']]], 422);
        }
        if (! $this->writeAccess->inScope($user, $departmentId)) {
            return response()->json(['success' => false, 'message' => 'هذه الإدارة خارج نطاق الإدارات التي تقودها، فلا يمكنك إدارة مهامها.'], 403);
        }
        foreach ($assigneeIds as $assigneeId) {
            if (! $this->writeAccess->assigneeInScope($user, (int) $assigneeId)) {
                return response()->json(['success' => false, 'message' => 'لا يمكنك إسناد مهمة إلا لأعضاء الإدارات التي تقودها.'], 403);
            }
        }

        return null;
    }

    /**
     * Moving a task's status as someone who is NOT its assignee (see updateStatus). Global actors keep moving any task; everybody else
     * needs tasks.update, the task's department among the ones they lead, and a task that is not awaiting approval / approved /
     * completed (that credit belongs to the evaluators, exactly as in update()). Returns the refusal, or null when allowed.
     */
    private function refuseStatusMove(Request $request, Task $task): ?JsonResponse
    {
        $user = $request->user();
        if ($this->writeAccess->isGlobal($user)) {
            return null;
        }
        if ($refusal = $this->refuseWithout($request, TaskWriteAccess::UPDATE, 'ليست لديك صلاحية تعديل المهام.')) {
            return $refusal;
        }
        if (! $this->writeAccess->inScope($user, $this->writeAccess->departmentOf($task))) {
            return response()->json(['success' => false, 'message' => 'هذه المهمة خارج نطاق الإدارات التي تقودها، فلا يمكنك تحريكها.'], 403);
        }
        if ($this->writeAccess->isCreditLocked($task)) {
            return response()->json(['success' => false, 'message' => 'لا يمكنك تغيير حالة المهمة بعد أن أصبحت بانتظار الاعتماد أو معتمدة أو مكتملة.'], 403);
        }

        return null;
    }

    /** Did the sent value change the stored one (numbers compared as numbers, so "10" == 10)? */
    private function differs(mixed $sent, mixed $stored, string $cast): bool
    {
        $norm = fn ($v) => $v === null ? null : match ($cast) {
            'int' => (int) $v,
            'float' => (float) $v,
            default => (string) $v,
        };

        return $norm($sent) !== $norm($stored);
    }

    /** A section must belong to the department the task is in (only enforced for a department-scoped actor). */
    private function refuseForeignSection(Request $request, ?int $sectionId, ?int $departmentId): ?JsonResponse
    {
        if ($sectionId === null || $this->writeAccess->manageableDepartmentIds($request->user()) === null) {
            return null;
        }
        $belongs = $departmentId !== null
            && \App\Models\DepartmentSection::query()->whereKey($sectionId)->where('department_id', $departmentId)->exists();

        return $belongs
            ? null
            : response()->json(['success' => false, 'message' => 'القسم المختار لا يتبع هذه الإدارة.', 'errors' => ['section_id' => ['القسم لا يتبع الإدارة.']]], 422);
    }

    private function baseQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Task::with([
            'department',
            'section',
            'assignedTo',
            'createdBy',
            'checklistItems',
            'evaluation',
            'attachments',
            'subtasks.assignedTo',
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $tasks = $this->baseQuery()
            ->when($request->department_id, fn ($q) => $q->where('department_id', $request->department_id))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->priority, fn ($q) => $q->where('priority', $request->priority))
            ->when($request->task_scope, fn ($q) => $q->where('task_scope', $request->task_scope))
            ->when($request->acceptance_status, fn ($q) => $q->where('acceptance_status', $request->acceptance_status))
            ->when($request->assigned_to, fn ($q) => $q->where('assigned_to', $request->assigned_to))
            ->latest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => TaskResource::collection($tasks)->response()->getData(true)]);
    }

    public function myTasks(Request $request): JsonResponse
    {
        $tasks = $this->baseQuery()
            ->where('assigned_to', $request->user()->id)
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->acceptance_status, fn ($q) => $q->where('acceptance_status', $request->acceptance_status))
            ->latest()
            ->get();

        return response()->json(['success' => true, 'data' => TaskResource::collection($tasks)]);
    }

    /** مهام بانتظار موافقة العضو */
    public function myPendingAcceptance(Request $request): JsonResponse
    {
        $tasks = $this->baseQuery()
            ->where('assigned_to', $request->user()->id)
            ->where('acceptance_status', Task::ACCEPTANCE_PENDING)
            ->latest()
            ->get();

        return response()->json(['success' => true, 'data' => TaskResource::collection($tasks)]);
    }

    /** مهام بانتظار التقييم للمدير */
    public function pendingEvaluation(Request $request): JsonResponse
    {
        $tasks = $this->baseQuery()
            ->where('status', 'needs_evaluation')
            ->when($request->department_id, fn ($q) => $q->where('department_id', $request->department_id))
            ->latest()
            ->get();

        return response()->json(['success' => true, 'data' => TaskResource::collection($tasks)]);
    }

    /** مهام بانتظار اعتماد الموارد البشرية */
    public function pendingHrApproval(Request $request): JsonResponse
    {
        $tasks = $this->baseQuery()
            ->where('status', 'waiting_approval')
            ->whereNotNull('manager_approved_at')
            ->whereNull('hr_approved_at')
            ->latest()
            ->get();

        return response()->json(['success' => true, 'data' => TaskResource::collection($tasks)]);
    }

    /** مهام الإدارة غير المسندة لعضو (مسندة من المدير التنفيذي) */
    public function unassignedDepartment(Request $request): JsonResponse
    {
        $user = $request->user();
        $tasks = $this->baseQuery()
            ->whereNull('assigned_to')
            ->when($request->department_id, fn ($q) => $q->where('department_id', $request->department_id))
            ->when(! $request->department_id && $user->department_id, fn ($q) => $q->where('department_id', $user->department_id))
            ->latest()
            ->get();

        return response()->json(['success' => true, 'data' => TaskResource::collection($tasks)]);
    }

    public function overdue(Request $request): JsonResponse
    {
        $tasks = $this->baseQuery()
            ->where(function ($q) {
                $q->where(function ($sq) {
                    $sq->whereNotNull('due_datetime')->where('due_datetime', '<', now());
                })->orWhere(function ($sq) {
                    $sq->whereNull('due_datetime')->whereNotNull('due_date')->where('due_date', '<', now());
                });
            })
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->latest()
            ->get();

        return response()->json(['success' => true, 'data' => TaskResource::collection($tasks)]);
    }

    public function kanban(Request $request): JsonResponse
    {
        $statuses = [
            'idea', 'under_review', 'planning', 'waiting_approval',
            'in_progress', 'completed', 'postponed', 'cancelled', 'needs_evaluation',
        ];

        $board = [];
        foreach ($statuses as $status) {
            $board[$status] = TaskResource::collection(
                $this->baseQuery()
                    ->where('status', $status)
                    ->when($request->department_id, fn ($q) => $q->where('department_id', $request->department_id))
                    ->when($request->scope === 'mine', fn ($q) => $q->where('assigned_to', $request->user()->id)->where('acceptance_status', Task::ACCEPTANCE_ACCEPTED))
                    ->get()
            );
        }

        return response()->json(['success' => true, 'data' => $board]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->writeAccess = app(TaskWriteAccess::class);
        if ($refusal = $this->refuseWithout($request, TaskWriteAccess::CREATE, 'ليست لديك صلاحية إنشاء المهام.')) {
            return $refusal;
        }

        $data = $request->validate([
            'title'            => 'required|string|max:255',
            'description'      => 'nullable|string',
            'notes'            => 'nullable|string',
            'department_id'    => 'nullable|exists:departments,id',
            'section_id'       => 'nullable|exists:department_sections,id',
            'assigned_to'      => 'nullable|exists:users,id',
            'assigned_users'   => 'nullable|array',
            'assigned_users.*' => 'exists:users,id',
            'priority'         => 'in:low,medium,high,urgent',
            'task_scope'       => 'nullable|in:simple,medium,large,strategic',
            'points'           => 'nullable|integer|min:0',
            'estimated_hours'  => 'nullable|numeric|min:0',
            'status'           => 'in:idea,under_review,planning,waiting_approval,in_progress,completed,postponed,cancelled,needs_evaluation',
            'start_date'       => 'nullable|date',
            'due_date'         => 'nullable|date',
            'due_datetime'     => 'nullable|date',
            'related_type'     => 'nullable|string',
            'related_id'       => 'nullable|integer',
            'tags'             => 'nullable|array',
            'attachments.*'    => 'nullable|file|max:20480', // 20MB max
        ]);

        // التحقق من تقييد النقاط بحسب حجم المهمة
        if (! empty($data['task_scope']) && isset($data['points'])) {
            $validation = TaskWorkflowService::validatePointsForScope($data['task_scope'], (int) $data['points']);
            if (! $validation['valid']) {
                return response()->json(['success' => false, 'message' => $validation['message']], 422);
            }
        }

        // A task is born in a working status: the outcomes (awaiting approval / completed) come from the evaluation and HR approval.
        if ($message = TaskTransitionRules::creationRefusal($data['status'] ?? null)) {
            return response()->json(['success' => false, 'message' => $message, 'errors' => ['status' => [$message]]], 422);
        }

        // Who the task is put on: creating it WITH an assignee also needs tasks.assign.
        $assigneeIds = array_values(array_unique(array_map('intval', array_merge(
            empty($data['assigned_to']) ? [] : [$data['assigned_to']],
            $data['assigned_users'] ?? [],
        ))));
        if ($assigneeIds !== [] && ($refusal = $this->refuseWithout($request, TaskWriteAccess::ASSIGN, 'ليست لديك صلاحية إسناد المهام.'))) {
            return $refusal;
        }
        // Department + assignee scope for anyone who is not global (a leader acts only inside the departments they lead).
        $departmentId = isset($data['department_id']) ? (int) $data['department_id'] : null;
        if ($refusal = $this->refuseOutOfScope($request, $departmentId, $assigneeIds)) {
            return $refusal;
        }
        if ($refusal = $this->refuseForeignSection($request, isset($data['section_id']) ? (int) $data['section_id'] : null, $departmentId)) {
            return $refusal;
        }

        // التحقق من أهلية العضو المكلف (منع المنقطع أو المجاز أو المنسحب)
        if (! empty($data['assigned_to'])) {
            $check = TaskWorkflowService::canAssignTaskToUser((int) $data['assigned_to']);
            if (! $check['allowed']) {
                return response()->json(['success' => false, 'message' => $check['message']], 422);
            }
        }
        if (! empty($data['assigned_users'])) {
            foreach ($data['assigned_users'] as $uId) {
                $check = TaskWorkflowService::canAssignTaskToUser((int) $uId);
                if (! $check['allowed']) {
                    return response()->json(['success' => false, 'message' => $check['message']], 422);
                }
            }
        }

        $user = $request->user();
        $data['created_by'] = $user->id;

        // حالة الموافقة: إذا أسندها لنفسه أو بلا عضو مقبولة تلقائياً، وإذا أسندها لغيره معلقة بانتظار موافقة العضو
        if (! empty($data['assigned_to']) && $data['assigned_to'] != $user->id) {
            $data['acceptance_status'] = Task::ACCEPTANCE_PENDING;
        } else {
            $data['acceptance_status'] = Task::ACCEPTANCE_ACCEPTED;
            $data['accepted_at'] = now();
        }

        $assignedUsers = $data['assigned_users'] ?? [];
        unset($data['assigned_users']);

        $task = DB::transaction(function () use ($data, $request, $assignedUsers, $user) {
            // المهمة الرئيسية
            $mainTask = Task::create($data);

            // حفظ المرفقات إن وجدت
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $path = $file->store('tasks/attachments', 'public');
                    $mainTask->attachments()->create([
                        'user_id'   => $user->id,
                        'file_path' => $path,
                        'file_name' => $file->getClientOriginalName(),
                        'file_size' => $file->getSize(),
                        'mime_type' => $file->getClientMimeType(),
                    ]);
                }
            }

            // إذا كانت مهمة جماعية (أكثر من عضو محدد): إنشاء نسخ فرعية لكل عضو مرتبطين بالمهمة الرئيسية
            if (! empty($assignedUsers)) {
                foreach ($assignedUsers as $memberId) {
                    $childData = $data;
                    $childData['parent_task_id'] = $mainTask->id;
                    $childData['assigned_to'] = $memberId;
                    $childData['acceptance_status'] = ($memberId == $user->id) ? Task::ACCEPTANCE_ACCEPTED : Task::ACCEPTANCE_PENDING;
                    if ($childData['acceptance_status'] === Task::ACCEPTANCE_ACCEPTED) {
                        $childData['accepted_at'] = now();
                    }

                    $subTask = Task::create($childData);

                    // ربط نفس المرفقات بالمهمة الفرعية
                    foreach ($mainTask->attachments as $att) {
                        TaskAttachment::create([
                            'task_id'   => $subTask->id,
                            'user_id'   => $user->id,
                            'file_path' => $att->file_path,
                            'file_name' => $att->file_name,
                            'file_size' => $att->file_size,
                            'mime_type' => $att->mime_type,
                        ]);
                    }
                }
            }

            return $mainTask;
        });

        ActivityLogger::log('task.created', $task, [], $request);

        return response()->json([
            'success' => true,
            'data'    => new TaskResource($task->load(['department', 'section', 'assignedTo', 'createdBy', 'attachments', 'subtasks.assignedTo'])),
        ], 201);
    }

    public function show(Task $task): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new TaskResource(
                $task->load([
                    'department',
                    'section',
                    'assignedTo',
                    'createdBy',
                    'comments.user',
                    'checklistItems',
                    'evaluation.evaluator',
                    'attachments',
                    'subtasks.assignedTo',
                ])
            ),
        ]);
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $this->writeAccess = app(TaskWriteAccess::class);
        if ($refusal = $this->refuseWithout($request, TaskWriteAccess::UPDATE, 'ليست لديك صلاحية تعديل المهام.')) {
            return $refusal;
        }
        // The task must be in the actor's scope (a task of another department, or with no department, stays read-only to a leader).
        if ($this->writeAccess->manageableDepartmentIds($request->user()) !== null && ! $this->writeAccess->inScope($request->user(), $this->writeAccess->departmentOf($task))) {
            return response()->json(['success' => false, 'message' => 'هذه المهمة خارج نطاق الإدارات التي تقودها، فلا يمكنك تعديلها.'], 403);
        }

        if ($task->locked_at !== null) {
            return response()->json([
                'success' => false,
                'message' => 'هذه المهمة مقفلة ولا يمكن تعديلها.',
            ], 423);
        }

        $data = $request->validate([
            'title'           => 'string|max:255',
            'description'     => 'nullable|string',
            'notes'           => 'nullable|string',
            'department_id'   => 'nullable|exists:departments,id',
            'section_id'      => 'nullable|exists:department_sections,id',
            'assigned_to'     => 'nullable|exists:users,id',
            'priority'        => 'in:low,medium,high,urgent',
            'task_scope'      => 'nullable|in:simple,medium,large,strategic',
            'points'          => 'nullable|integer|min:0',
            'estimated_hours' => 'nullable|numeric|min:0',
            'status'          => 'in:idea,under_review,planning,waiting_approval,in_progress,completed,postponed,cancelled,needs_evaluation',
            'start_date'      => 'nullable|date',
            'due_date'        => 'nullable|date',
            'due_datetime'    => 'nullable|date',
            'related_type'    => 'nullable|string',
            'related_id'      => 'nullable|integer',
            'tags'            => 'nullable|array',
        ]);

        if (! empty($data['task_scope']) && isset($data['points'])) {
            $validation = TaskWorkflowService::validatePointsForScope($data['task_scope'], (int) $data['points']);
            if (! $validation['valid']) {
                return response()->json(['success' => false, 'message' => $validation['message']], 422);
            }
        }

        // A department change must stay inside the scope; a leader cannot move a task out of the departments they lead.
        $departmentId = array_key_exists('department_id', $data) ? ($data['department_id'] === null ? null : (int) $data['department_id']) : $this->writeAccess->departmentOf($task);
        if (array_key_exists('department_id', $data) && ($refusal = $this->refuseOutOfScope($request, $departmentId))) {
            return $refusal;
        }
        $sectionId = array_key_exists('section_id', $data) ? ($data['section_id'] === null ? null : (int) $data['section_id']) : ($task->section_id === null ? null : (int) $task->section_id);
        if ((array_key_exists('section_id', $data) || array_key_exists('department_id', $data)) && ($refusal = $this->refuseForeignSection($request, $sectionId, $departmentId))) {
            return $refusal;
        }

        // The assignee is cast ONCE (a form field arrives as the string "12") and that value drives the assign guard, the stored value
        // and the acceptance reset alike.
        $assigneeSent = array_key_exists('assigned_to', $data);
        $newAssignee = $assigneeSent && $data['assigned_to'] !== null ? (int) $data['assigned_to'] : null;
        if ($assigneeSent) {
            $data['assigned_to'] = $newAssignee;
        }
        $assigneeChanged = $assigneeSent && $newAssignee !== ($task->assigned_to === null ? null : (int) $task->assigned_to);

        // A delegated (non-global) holder never touches what is already credited or about to be: once a task is awaiting approval,
        // approved or completed, its size, points, hours, status and assignee are the evaluators' (global roles are unchanged).
        if ($this->writeAccess->manageableDepartmentIds($request->user()) !== null && $this->writeAccess->isCreditLocked($task)) {
            $touched = [];
            foreach (['points' => 'int', 'task_scope' => 'string', 'estimated_hours' => 'float', 'status' => 'string'] as $field => $cast) {
                if (array_key_exists($field, $data) && $this->differs($data[$field], $task->{$field}, $cast)) {
                    $touched[] = $field;
                }
            }
            if ($assigneeChanged) {
                $touched[] = 'assigned_to';
            }
            if ($touched !== []) {
                return response()->json(['success' => false, 'message' => 'لا يمكنك تغيير النقاط أو حجم المهمة أو الساعات أو الحالة أو المكلَّف بعد أن أصبحت المهمة بانتظار الاعتماد أو معتمدة أو مكتملة.', 'errors' => array_fill_keys($touched, ['لا يمكن تغييره في هذه المرحلة.'])], 403);
            }
        }

        // A status CHANGE written by hand follows the shared workflow rules for EVERYBODY (global actors included): the outcomes
        // `waiting_approval` / `completed` are recorded by the evaluation and HR approval only, and an evaluated / approved /
        // completed task is not re-opened here (TaskTransitionRules).
        if (array_key_exists('status', $data) && ($message = TaskTransitionRules::refusal($task, $data['status']))) {
            return response()->json(['success' => false, 'message' => $message, 'errors' => ['status' => [$message]]], 422);
        }

        // Changing WHO the task is on (including un-assigning it) is an assignment: tasks.assign + the assignee's scope.
        if ($assigneeChanged) {
            if ($refusal = $this->refuseWithout($request, TaskWriteAccess::ASSIGN, 'ليست لديك صلاحية إسناد المهام.')) {
                return $refusal;
            }
            if ($newAssignee !== null) {
                if ($refusal = $this->refuseOutOfScope($request, $departmentId, [$newAssignee])) {
                    return $refusal;
                }
                $check = TaskWorkflowService::canAssignTaskToUser($newAssignee);
                if (! $check['allowed']) {
                    return response()->json(['success' => false, 'message' => $check['message']], 422);
                }
            }
        }

        // إذا تم تغيير العضو المكلف، إعادة ضبط حالة الموافقة
        if ($assigneeChanged && $newAssignee !== null) {
            $data['acceptance_status'] = ($newAssignee === (int) $request->user()->id) ? Task::ACCEPTANCE_ACCEPTED : Task::ACCEPTANCE_PENDING;
            $data['rejection_reason'] = null;
            $data['rejected_at'] = null;
        }

        // The status change is judged again on the row LOCKED for the write (see updateStatus), so an evaluation / approval that commits
        // meanwhile is never overwritten.
        $stale = DB::transaction(function () use ($task, $data) {
            $row = Task::lockForUpdate()->find($task->id);
            if ($row && array_key_exists('status', $data) && ($message = TaskTransitionRules::refusal($row, $data['status']))) {
                return $message;
            }
            $task->update($data);

            return null;
        });
        if ($stale !== null) {
            return response()->json(['success' => false, 'message' => $stale, 'errors' => ['status' => [$stale]]], 422);
        }

        ActivityLogger::log('task.updated', $task, $data, $request);

        return response()->json([
            'success' => true,
            'data'    => new TaskResource($task->load(['department', 'section', 'assignedTo', 'createdBy', 'evaluation', 'attachments'])),
        ]);
    }

    /** موافقة العضو على استلام المهمة */
    public function accept(Request $request, Task $task): JsonResponse
    {
        $user = $request->user();
        if ($task->assigned_to !== $user->id && ! in_array($user->role, ['admin', 'super_admin'])) {
            return response()->json(['success' => false, 'message' => 'غير مصرح لك بقبول هذه المهمة.'], 403);
        }

        TaskAcceptanceService::accept($task, $request);

        return response()->json([
            'success' => true,
            'message' => 'تم قبول المهمة بنجاح وانتقالها إلى لوحتك.',
            'data'    => new TaskResource($task->load(['department', 'assignedTo', 'createdBy'])),
        ]);
    }

    /** رفض العضو للمهمة مع ذكر السبب */
    public function reject(Request $request, Task $task): JsonResponse
    {
        $user = $request->user();
        if ($task->assigned_to !== $user->id && ! in_array($user->role, ['admin', 'super_admin'])) {
            return response()->json(['success' => false, 'message' => 'غير مصرح لك بالاعتذار عن هذه المهمة.'], 403);
        }

        $data = $request->validate([
            'reason' => 'required|string|min:3|max:1000',
        ]);

        TaskAcceptanceService::reject($task, $data['reason'], $request);

        return response()->json([
            'success' => true,
            'message' => 'تم تسجيل الاعتذار وإشعار المدير لإعادة التوجيه.',
            'data'    => new TaskResource($task->load(['department', 'assignedTo', 'createdBy'])),
        ]);
    }

    /** إعادة إسناد المهمة المرفوضة أو غير المسندة لعضو آخر */
    public function reassign(Request $request, Task $task): JsonResponse
    {
        $this->writeAccess = app(TaskWriteAccess::class);
        if ($refusal = $this->refuseWithout($request, TaskWriteAccess::ASSIGN, 'ليست لديك صلاحية إسناد المهام.')) {
            return $refusal;
        }
        if ($this->writeAccess->manageableDepartmentIds($request->user()) !== null && ! $this->writeAccess->inScope($request->user(), $this->writeAccess->departmentOf($task))) {
            return response()->json(['success' => false, 'message' => 'هذه المهمة خارج نطاق الإدارات التي تقودها، فلا يمكنك إعادة إسنادها.'], 403);
        }
        if ($task->locked_at !== null) {
            return response()->json(['success' => false, 'message' => 'هذه المهمة مقفلة ولا يمكن إعادة إسنادها.'], 423);
        }
        // A delegated holder cannot move the credit of a task that is awaiting approval, approved or completed.
        if ($this->writeAccess->manageableDepartmentIds($request->user()) !== null && $this->writeAccess->isCreditLocked($task)) {
            return response()->json(['success' => false, 'message' => 'لا يمكنك إعادة إسناد مهمة بانتظار الاعتماد أو معتمدة أو مكتملة.'], 403);
        }

        $data = $request->validate([
            'assigned_to' => 'required|exists:users,id',
            'notes'       => 'nullable|string',
        ]);

        if ($refusal = $this->refuseOutOfScope($request, $this->writeAccess->departmentOf($task), [(int) $data['assigned_to']])) {
            return $refusal;
        }

        $check = TaskWorkflowService::canAssignTaskToUser((int) $data['assigned_to']);
        if (! $check['allowed']) {
            return response()->json(['success' => false, 'message' => $check['message']], 422);
        }

        $task->update([
            'assigned_to'       => $data['assigned_to'],
            'acceptance_status' => Task::ACCEPTANCE_PENDING,
            'rejection_reason'  => null,
            'rejected_at'       => null,
            'notes'             => $data['notes'] ?? $task->notes,
        ]);

        ActivityLogger::log('task.reassigned', $task, ['new_assigned_to' => $data['assigned_to']], $request);

        return response()->json([
            'success' => true,
            'message' => 'تم إعادة إسناد المهمة بنجاح.',
            'data'    => new TaskResource($task->load(['department', 'assignedTo', 'createdBy'])),
        ]);
    }

    /** استلام مدير الإدارة أو العضو لمهمة عامة تابعة للإدارة */
    public function claim(Request $request, Task $task): JsonResponse
    {
        $user = $request->user();

        $check = TaskWorkflowService::canAssignTaskToUser((int) $user->id);
        if (! $check['allowed']) {
            return response()->json(['success' => false, 'message' => $check['message']], 422);
        }

        $task->update([
            'assigned_to'       => $user->id,
            'acceptance_status' => Task::ACCEPTANCE_ACCEPTED,
            'accepted_at'       => now(),
        ]);

        ActivityLogger::log('task.claimed', $task, [], $request);

        return response()->json([
            'success' => true,
            'message' => 'تم استلام المهمة بنجاح.',
            'data'    => new TaskResource($task->load(['department', 'assignedTo', 'createdBy'])),
        ]);
    }

    /**
     * تقييم المهمة من قِبل المدير وفق المعايير الخمسة (Likert Scale)
     */
    public function evaluate(Request $request, Task $task): JsonResponse
    {
        $user = $request->user();
        $managerRoles = ['admin', 'super_admin', 'tech_admin', 'executive_admin', 'hr_manager', 'operations_manager', 'department_manager'];
        if (! in_array($user->role, $managerRoles, true)) {
            return response()->json(['success' => false, 'message' => 'تقييم المهام متاح للمدراء فقط.'], 403);
        }

        $data = $request->validate([
            'delivered_on_time'  => 'required|integer|min:1|max:5',
            'quality_of_outputs' => 'required|integer|min:1|max:5',
            'autonomy'           => 'required|integer|min:1|max:5',
            'responsiveness'     => 'required|integer|min:1|max:5',
            'collaboration'      => 'nullable|integer|min:1|max:5',
            'collaboration_na'   => 'nullable|boolean',
            'feedback'           => 'nullable|string|max:1000',
        ]);

        $collabNa = ! empty($data['collaboration_na']);
        $scores = [
            $data['delivered_on_time'],
            $data['quality_of_outputs'],
            $data['autonomy'],
            $data['responsiveness'],
        ];

        if (! $collabNa && isset($data['collaboration'])) {
            $scores[] = $data['collaboration'];
        }

        $finalScore = round(array_sum($scores) / count($scores), 2);

        // Nobody evaluates their own task (hr-approve refuses the same): the credit would go to the evaluator.
        if ($task->assigned_to !== null && (int) $task->assigned_to === (int) $user->id) {
            return response()->json(['success' => false, 'message' => 'لا يمكنك تقييم مهمة مسندة إليك. يقيّمها مدير آخر.'], 403);
        }

        $replayed = false;
        $refusal = null;
        DB::transaction(function () use ($task, $user, $data, $collabNa, $finalScore, &$replayed, &$refusal) {
            // Idempotent: a task that is already completed AND evaluated was already credited. A repeated evaluation changes nothing
            // (and never re-adds hours or moves a completed task back to waiting_approval). A task someone once set to `completed` by
            // hand (no evaluation on record, never credited) is not a replay: the evaluation is how it is finally credited, once.
            $locked = Task::lockForUpdate()->find($task->id);
            if ($locked && $locked->status === 'completed' && $locked->manager_approved_at !== null) {
                $replayed = true;

                return;
            }

            // The evaluation is the step AFTER delivery: only a task that is being worked on, delivered (needs_evaluation) or awaiting
            // HR (to correct the evaluation) can be evaluated, and only once its assignee accepted it. A cancelled, postponed or
            // not-yet-started task, or one nobody accepted, is never credited by it.
            if (! $locked || ! in_array($locked->status, ['in_progress', 'needs_evaluation', 'waiting_approval', 'completed'], true) || $locked->acceptance_status !== Task::ACCEPTANCE_ACCEPTED) {
                $refusal = response()->json(['success' => false, 'message' => 'لا يُقيَّم إلا عمل جارٍ أو مُسلَّم قبله المكلَّف؛ هذه المهمة ليست في مرحلة التقييم (ملغاة أو مؤجلة أو لم تبدأ أو لم يقبلها المكلَّف).'], 422);

                return;
            }
            $task->refresh(); // credit from the row as it is NOW (assignee, points, hours), not the copy route binding read

            TaskEvaluation::updateOrCreate(
                ['task_id' => $task->id],
                [
                    'evaluator_id'       => $user->id,
                    'delivered_on_time'  => $data['delivered_on_time'],
                    'quality_of_outputs' => $data['quality_of_outputs'],
                    'autonomy'           => $data['autonomy'],
                    'responsiveness'     => $data['responsiveness'],
                    'collaboration'      => $collabNa ? null : ($data['collaboration'] ?? null),
                    'collaboration_na'   => $collabNa,
                    'final_score'        => $finalScore,
                    'feedback'           => $data['feedback'] ?? null,
                ]
            );

            $task->manager_approved_at = now();
            $task->manager_approved_by = $user->id;

            // إذا كانت المهمة بسيطة: لا حاجة لاعتماد الموارد البشرية، وتعتمد وتضاف النقاط والساعات فوراً
            if ($task->task_scope === Task::SCOPE_SIMPLE) {
                $task->status = 'completed';
                $task->save();
                TaskWorkflowService::creditVolunteerHoursAndPoints($task, $user);
            } else {
                // إذا كانت أعلى من بسيطة: تبقى بانتظار اعتماد الموارد البشرية
                $task->status = 'waiting_approval';
                $task->save();
            }
        });

        if ($refusal) {
            return $refusal;
        }

        if (! $replayed) {
            ActivityLogger::log('task.evaluated', $task, ['final_score' => $finalScore], $request);
        }

        return response()->json([
            'success' => true,
            'message' => ($task->task_scope === Task::SCOPE_SIMPLE)
                ? 'تم التقييم والاعتماد الفوري وإضافة النقاط وساعات التطوع لرصيد المتطوع.'
                : 'تم التقييم بنجاح وإحالة المهمة لاعتماد الموارد البشرية النهائي.',
            'data'    => new TaskResource($task->load(['department', 'assignedTo', 'evaluation.evaluator'])),
        ]);
    }

    /** اعتماد الموارد البشرية النهائي للمهام غير البسيطة */
    public function hrApprove(Request $request, Task $task): JsonResponse
    {
        $user = $request->user();
        $hrRoles = ['admin', 'super_admin', 'hr_manager', 'executive_admin'];
        if (! in_array($user->role, $hrRoles, true)) {
            return response()->json(['success' => false, 'message' => 'اعتماد الموارد البشرية متاح لمسؤولي HR فقط.'], 403);
        }

        $replayed = false;
        $refusal = null;
        DB::transaction(function () use ($task, $user, &$replayed, &$refusal) {
            // Idempotent: an already completed task was already credited (points are deduplicated by the ledger, hours are
            // not), so a replay answers success without crediting again.
            $locked = Task::lockForUpdate()->find($task->id);
            if ($locked && $locked->status === 'completed') {
                $replayed = true;

                return;
            }

            // The task must really be awaiting HR approval (the exact set pendingHrApproval lists): evaluated and approved by the
            // manager, not yet approved by HR. Anything else (in progress, pending, cancelled, never evaluated) is refused.
            if (! $locked || $locked->status !== 'waiting_approval' || $locked->manager_approved_at === null || $locked->hr_approved_at !== null) {
                $refusal = response()->json(['success' => false, 'message' => 'هذه المهمة ليست بانتظار اعتماد الموارد البشرية (يجب أن تكون مقيَّمة ومعتمدة من مدير الإدارة ولم تُعتمد نهائياً بعد).'], 422);

                return;
            }

            // No self-approval: nobody credits points and hours to themselves, whatever their role.
            if ($locked->assigned_to !== null && (int) $locked->assigned_to === (int) $user->id) {
                $refusal = response()->json(['success' => false, 'message' => 'لا يمكنك اعتماد مهمة مسندة إليك. يعتمدها مسؤول موارد بشرية آخر.'], 403);

                return;
            }

            $task->refresh(); // credit from the row as it is NOW, not the copy route binding read
            $task->hr_approved_at = now();
            $task->hr_approved_by = $user->id;
            $task->status = 'completed';
            $task->save();

            // إضافة النقاط وساعات التطوع التراكمية
            TaskWorkflowService::creditVolunteerHoursAndPoints($task, $user);
        });

        if ($refusal) {
            return $refusal;
        }

        if (! $replayed) {
            ActivityLogger::log('task.hr_approved', $task, [], $request);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم اعتماد المهمة نهائياً من الموارد البشرية وإضافة النقاط وساعات التطوع التراكمية.',
            'data'    => new TaskResource($task->load(['department', 'assignedTo', 'evaluation', 'hrApprover'])),
        ]);
    }

    public function destroy(Request $request, Task $task): JsonResponse
    {
        if ($task->locked_at !== null) {
            return response()->json([
                'success' => false,
                'message' => 'هذه المهمة مقفلة ولا يمكن حذفها.',
            ], 423);
        }

        ActivityLogger::log('task.deleted', $task, [], $request);
        $task->delete();

        return response()->json(['success' => true, 'message' => 'Task deleted.']);
    }

    public function storeComment(Request $request, Task $task): JsonResponse
    {
        $data = $request->validate(['comment' => 'required|string']);

        $comment = TaskCollaborationService::addComment($task, $request->user()->id, $data['comment']);

        return response()->json([
            'success' => true,
            'data'    => new TaskCommentResource($comment->load('user')),
        ], 201);
    }

    public function storeChecklist(Request $request, Task $task): JsonResponse
    {
        $data = $request->validate([
            'title'      => 'required|string|max:255',
            'sort_order' => 'integer',
        ]);

        $item = $task->checklistItems()->create($data);

        return response()->json([
            'success' => true,
            'data'    => new TaskChecklistItemResource($item),
        ], 201);
    }

    public function updateChecklist(Request $request, Task $task, TaskChecklistItem $item): JsonResponse
    {
        abort_if($item->task_id !== $task->id, 404);

        $data = $request->validate([
            'title'        => 'string|max:255',
            'is_completed' => 'boolean',
            'sort_order'   => 'integer',
        ]);

        TaskCollaborationService::updateChecklistItem($item, $data);

        return response()->json([
            'success' => true,
            'data'    => new TaskChecklistItemResource($item),
        ]);
    }

    /**
     * Locking / unlocking (the final-delivery freeze). A GLOBAL actor does both on any task, unchanged. Everybody else the route lets
     * in (hr_manager / department_manager) needs tasks.update AND the task's department among the ones they lead, and may only lift
     * a lock THEY placed (a lock set by a global actor is that actor's decision). Returns the refusal, or null when allowed.
     */
    private function refuseLockChange(Request $request, Task $task): ?JsonResponse
    {
        $this->writeAccess = app(TaskWriteAccess::class);
        $user = $request->user();
        if ($this->writeAccess->isGlobal($user)) {
            return null;
        }
        if ($refusal = $this->refuseWithout($request, TaskWriteAccess::UPDATE, 'ليست لديك صلاحية تعديل المهام.')) {
            return $refusal;
        }
        if (! $this->writeAccess->inScope($user, $this->writeAccess->departmentOf($task))) {
            return response()->json(['success' => false, 'message' => 'هذه المهمة خارج نطاق الإدارات التي تقودها، فلا يمكنك قفلها أو فتحها.'], 403);
        }
        // Like every other delegated write, never on credit-bearing work (awaiting approval / approved / completed): freezing it
        // would stall the evaluators' flow.
        if ($task->locked_at === null && $this->writeAccess->isCreditLocked($task)) {
            return response()->json(['success' => false, 'message' => 'لا يمكنك قفل مهمة بانتظار الاعتماد أو معتمدة أو مكتملة.'], 403);
        }
        // An existing lock that is not theirs is untouchable for them, in BOTH directions: re-locking would take over `locked_by` and
        // let them lift it afterwards. A lock with no owner (its locker's account was deleted) is a global actor's to lift.
        if ($task->locked_at !== null && ($task->locked_by === null || (int) $task->locked_by !== (int) $user->id)) {
            return response()->json(['success' => false, 'message' => 'هذه المهمة مقفلة بقفل لا تملكه، فلا يغيّره إلا من وضعه أو مدير عام.'], 403);
        }

        return null;
    }

    public function lock(Request $request, Task $task): JsonResponse
    {
        if ($refusal = $this->refuseLockChange($request, $task)) {
            return $refusal;
        }

        $task->update(['locked_at' => now(), 'locked_by' => $request->user()->id]);
        ActivityLogger::log('task.locked', $task, [], $request);

        return response()->json([
            'success' => true,
            'data'    => new TaskResource($task->load(['department', 'section', 'assignedTo', 'createdBy'])),
        ]);
    }

    public function unlock(Request $request, Task $task): JsonResponse
    {
        if ($refusal = $this->refuseLockChange($request, $task)) {
            return $refusal;
        }

        $task->update(['locked_at' => null, 'locked_by' => null]);
        ActivityLogger::log('task.unlocked', $task, [], $request);

        return response()->json([
            'success' => true,
            'data'    => new TaskResource($task->load(['department', 'section', 'assignedTo', 'createdBy'])),
        ]);
    }

    public function updateStatus(Request $request, Task $task): JsonResponse
    {
        // WHO may move a task is decided BEFORE anything about the task is revealed (a locked task would otherwise answer 423 to anyone).
        // Two kinds of mover. A MANAGER of the task: a GLOBAL actor (the roles TaskWriteAccess treats as global, unchanged) or a
        // department leader who holds tasks.update AND leads this task's department AND is not moving credit-bearing work. A bare
        // role name (hr_manager / department_manager) is no longer enough: the same rule as PATCH operations/tasks/{task}
        // (TaskController::update), so the board route cannot do what the edit route refuses. Anybody else must be the ASSIGNEE,
        // and an assignee who is not a manager of the task moves it under the member rules of /my-work/tasks/{task}/status.
        $this->writeAccess = app(TaskWriteAccess::class);
        $user = $request->user();
        $isAssignee = $task->assigned_to !== null && (int) $task->assigned_to === (int) $user->id;
        $managerRefusal = $this->refuseStatusMove($request, $task);
        if ($managerRefusal !== null && ! $isAssignee) {
            return $managerRefusal;
        }
        $asMember = $managerRefusal !== null;

        $data = $request->validate([
            'status' => 'required|in:idea,under_review,planning,waiting_approval,in_progress,completed,postponed,cancelled,needs_evaluation',
        ]);

        // Everything below is decided on the row LOCKED for this write, not on the copy route binding read earlier: an evaluation or an
        // HR approval that commits between the two must not be overwritten by a move that was judged against the old state.
        $result = DB::transaction(function () use ($request, $task, $data, $asMember) {
            $row = Task::lockForUpdate()->find($task->id);
            if (! $row) {
                return response()->json(['success' => false, 'message' => 'المهمة غير موجودة.'], 404);
            }
            if ($row->locked_at !== null) {
                return response()->json(['success' => false, 'message' => 'هذه المهمة مقفلة ولا يمكن تحريكها.'], 423);
            }

            // A department leader never moves credit-bearing work (re-checked on the locked row).
            if (! $asMember && ! $this->writeAccess->isGlobal($request->user()) && $this->writeAccess->isCreditLocked($row)) {
                return response()->json(['success' => false, 'message' => 'لا يمكنك تغيير حالة المهمة بعد أن أصبحت بانتظار الاعتماد أو معتمدة أو مكتملة.'], 403);
            }

            // The member limits, identical to MyWorkTaskController::status: an accepted task only; `completed` is reserved for the
            // evaluation -> HR-approval flow (a member finishes by moving to needs_evaluation); and a completed task stays completed.
            if ($asMember) {
                if ($row->acceptance_status !== Task::ACCEPTANCE_ACCEPTED) {
                    return response()->json(['success' => false, 'message' => 'اقبل المهمة أولًا قبل تحريك حالتها.'], 409);
                }
                if ($row->status === 'completed') {
                    return response()->json(['success' => false, 'message' => 'المهمة المنجزة معتمدة ولا يمكن تغيير حالتها؛ للتعديل راجع المدير.'], 403);
                }
                if ($data['status'] === 'completed') {
                    return response()->json(['success' => false, 'message' => 'اعتماد الإنجاز يتم بعد تقييم المدير؛ حرّك المهمة إلى «بانتظار التقييم».'], 403);
                }
            }

            // The shared workflow rules, for the manager and the member alike (global actors included): an outcome status is recorded
            // by the evaluation / HR approval only, and an evaluated / approved / completed task is never re-opened by a status move.
            if ($message = TaskTransitionRules::refusal($row, $data['status'])) {
                return response()->json(['success' => false, 'message' => $message, 'errors' => ['status' => [$message]]], 422);
            }

            return TaskStatusService::move($row, $data['status'], $request);
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json([
            'success' => true,
            'data'    => new TaskResource($result->load(['department', 'section', 'assignedTo', 'createdBy'])),
        ]);
    }
}
