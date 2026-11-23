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
use App\Services\TaskWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
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

        // إذا تم تغيير العضو المكلف، إعادة ضبط حالة الموافقة
        if (isset($data['assigned_to']) && $data['assigned_to'] !== $task->assigned_to) {
            $data['acceptance_status'] = ($data['assigned_to'] == $request->user()->id) ? Task::ACCEPTANCE_ACCEPTED : Task::ACCEPTANCE_PENDING;
            $data['rejection_reason'] = null;
            $data['rejected_at'] = null;
        }

        $task->update($data);

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
        $data = $request->validate([
            'assigned_to' => 'required|exists:users,id',
            'notes'       => 'nullable|string',
        ]);

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

        $replayed = false;
        DB::transaction(function () use ($task, $user, $data, $collabNa, $finalScore, &$replayed) {
            // Idempotent: a task that is already completed was already credited. A repeated evaluation changes nothing
            // (and never re-adds hours or moves a completed task back to waiting_approval).
            $locked = Task::lockForUpdate()->find($task->id);
            if ($locked && $locked->status === 'completed') {
                $replayed = true;

                return;
            }

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
        DB::transaction(function () use ($task, $user, &$replayed) {
            // Idempotent: an already completed task was already credited (points are deduplicated by the ledger, hours are
            // not), so a replay answers success without crediting again.
            $locked = Task::lockForUpdate()->find($task->id);
            if ($locked && $locked->status === 'completed') {
                $replayed = true;

                return;
            }

            $task->hr_approved_at = now();
            $task->hr_approved_by = $user->id;
            $task->status = 'completed';
            $task->save();

            // إضافة النقاط وساعات التطوع التراكمية
            TaskWorkflowService::creditVolunteerHoursAndPoints($task, $user);
        });

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

    public function lock(Request $request, Task $task): JsonResponse
    {
        $task->update(['locked_at' => now(), 'locked_by' => $request->user()->id]);
        ActivityLogger::log('task.locked', $task, [], $request);

        return response()->json([
            'success' => true,
            'data'    => new TaskResource($task->load(['department', 'section', 'assignedTo', 'createdBy'])),
        ]);
    }

    public function unlock(Request $request, Task $task): JsonResponse
    {
        $task->update(['locked_at' => null, 'locked_by' => null]);
        ActivityLogger::log('task.unlocked', $task, [], $request);

        return response()->json([
            'success' => true,
            'data'    => new TaskResource($task->load(['department', 'section', 'assignedTo', 'createdBy'])),
        ]);
    }

    public function updateStatus(Request $request, Task $task): JsonResponse
    {
        if ($task->locked_at !== null) {
            return response()->json([
                'success' => false,
                'message' => 'هذه المهمة مقفلة ولا يمكن تحريكها.',
            ], 423);
        }

        $managerRoles = ['admin', 'super_admin', 'tech_admin', 'executive_admin', 'hr_manager', 'operations_manager', 'department_manager'];
        $user = $request->user();
        if (! in_array($user->role, $managerRoles, true) && $task->assigned_to !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'يمكنك تحريك المهام المعينة لك فقط.',
            ], 403);
        }

        $data = $request->validate([
            'status' => 'required|in:idea,under_review,planning,waiting_approval,in_progress,completed,postponed,cancelled,needs_evaluation',
        ]);

        TaskStatusService::move($task, $data['status'], $request);

        return response()->json([
            'success' => true,
            'data'    => new TaskResource($task->load(['department', 'section', 'assignedTo', 'createdBy'])),
        ]);
    }
}
