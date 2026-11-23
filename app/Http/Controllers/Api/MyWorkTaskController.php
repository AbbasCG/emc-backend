<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskChecklistItemResource;
use App\Http\Resources\TaskCommentResource;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Services\TaskAcceptanceService;
use App\Services\TaskCollaborationService;
use App\Services\TaskStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * «مهامي»: the tasks assigned to the signed-in user, for ANY authenticated member.
 *
 * The management routes (/admin/tasks/my, /operations/tasks/{task}/accept ...) sit behind admin-type roles, so an
 * ordinary member could not even see a task waiting for their acceptance. These routes are OWN-ONLY by
 * construction: the scope is `assigned_to = auth user` and no request parameter can change it.
 *
 * Authority: ONLY the assignee may accept or decline here. Unlike Admin\TaskController (where admin / super_admin
 * may act for the assignee) there is no admin override on these routes; a manager who needs to act for someone
 * uses the management routes. A task that is not yours is answered as "not found" so the id reveals nothing.
 */
class MyWorkTaskController extends Controller
{
    /** Most rows returned; `meta.truncated` says when the real total is larger. */
    public const LIMIT = 300;

    private const STATUSES = TaskStatusService::STATUSES;

    // GET /my-work/tasks
    public function index(Request $request): JsonResponse
    {
        // Only narrowing filters, from an allow-list. Anything else in the query string (assigned_to, user_id ...) is never read.
        $filters = $request->validate([
            'acceptance_status' => ['sometimes', 'nullable', Rule::in([Task::ACCEPTANCE_PENDING, Task::ACCEPTANCE_ACCEPTED, Task::ACCEPTANCE_REJECTED])],
            'status' => ['sometimes', 'nullable', Rule::in(self::STATUSES)],
        ], [
            'acceptance_status.in' => 'حالة القبول غير صالحة.',
            'status.in' => 'حالة المهمة غير صالحة.',
        ]);

        $uid = $request->user()->id;

        $query = Task::query()
            ->where('assigned_to', $uid)
            // A collective task is stored as a parent plus one child per member (parent_task_id), and the parent is
            // itself assigned to its first member. Each member's assignment is the CHILD, so a parent whose child the
            // caller also owns is left out: one collective assignment shows once, never twice.
            ->whereDoesntHave('subtasks', fn ($q) => $q->where('assigned_to', $uid))
            ->when($filters['acceptance_status'] ?? null, fn ($q, $v) => $q->where('acceptance_status', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v));

        $total = (clone $query)->count();

        // The same relations the management list loads for TaskResource, in one batch each (no per-task queries).
        $tasks = $query
            ->with(['department', 'section', 'assignedTo', 'createdBy', 'checklistItems', 'evaluation', 'attachments', 'subtasks.assignedTo'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        return response()->json([
            'success' => true,
            'data' => TaskResource::collection($tasks),
            'meta' => ['total' => $total, 'truncated' => $total > self::LIMIT],
        ]);
    }

    // GET /my-work/tasks/{task}
    public function show(Request $request, int $task): JsonResponse
    {
        $found = $this->ownTask($request, $task);
        if ($found instanceof JsonResponse) {
            return $found;
        }

        // The same relations the management detail loads, so the panel renders identically.
        return response()->json([
            'success' => true,
            'data' => new TaskResource($found->load([
                'department', 'section', 'assignedTo', 'createdBy', 'comments.user', 'checklistItems', 'evaluation.evaluator', 'attachments', 'subtasks.assignedTo',
            ])),
        ]);
    }

    // POST /my-work/tasks/{task}/comments
    public function comment(Request $request, int $task): JsonResponse
    {
        $found = $this->ownTask($request, $task);
        if ($found instanceof JsonResponse) {
            return $found;
        }

        $data = $request->validate([
            'comment' => ['required', 'string', 'max:5000'],
        ], [
            'comment.required' => 'نص التعليق مطلوب.',
            'comment.string' => 'نص التعليق غير صالح.',
            'comment.max' => 'التعليق طويل جدًا؛ الحد الأقصى 5000 حرف.',
        ]);

        $comment = TaskCollaborationService::addComment($found, $request->user()->id, $data['comment']);

        return response()->json(['success' => true, 'data' => new TaskCommentResource($comment->load('user'))], 201);
    }

    // PUT /my-work/tasks/{task}/checklist/{item}
    public function checklist(Request $request, int $task, int $item): JsonResponse
    {
        $found = $this->ownTask($request, $task);
        if ($found instanceof JsonResponse) {
            return $found;
        }

        // The assignee ticks items; the list itself (adding, renaming, ordering) stays with managers.
        $data = $request->validate([
            'is_completed' => ['required', 'boolean'],
        ], [
            'is_completed.required' => 'حدّد هل البند مكتمل.',
            'is_completed.boolean' => 'قيمة الإكمال غير صالحة.',
        ]);

        $row = TaskChecklistItem::query()->where('id', $item)->where('task_id', $found->id)->first();
        if (! $row) {
            return response()->json(['success' => false, 'message' => 'البند غير موجود في هذه المهمة.'], 404);
        }

        TaskCollaborationService::updateChecklistItem($row, $data);

        return response()->json(['success' => true, 'data' => new TaskChecklistItemResource($row)]);
    }

    /** The task when it is assigned to the caller; otherwise the 404 to return (nothing about other tasks is revealed). */
    private function ownTask(Request $request, int $id): Task|JsonResponse
    {
        $task = Task::query()->where('id', $id)->where('assigned_to', $request->user()->id)->first();

        return $task ?? response()->json(['success' => false, 'message' => 'المهمة غير موجودة أو غير مسندة إليك.'], 404);
    }

    // PATCH /my-work/tasks/{task}/status
    public function status(Request $request, int $task): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(TaskStatusService::STATUSES)],
        ], [
            'status.required' => 'حدّد الحالة الجديدة للمهمة.',
            'status.in' => 'حالة المهمة غير صالحة.',
        ]);

        // Same transition rules as Admin\TaskController::updateStatus for a non-manager (any listed status, never a
        // locked task), with three member-only limits on top:
        //  - only a task the assignee has accepted can be moved (a pending or declined one is answered first);
        //  - `completed` is reserved for the evaluation -> HR-approval flow (evaluate / hrApprove set it and credit
        //    points); a member finishes by moving to `needs_evaluation`;
        //  - a task that is already `completed` stays completed for the member.
        $result = DB::transaction(function () use ($request, $task, $data) {
            $row = Task::lockForUpdate()->find($task);
            if (! $row || (int) $row->assigned_to !== (int) $request->user()->id) {
                return response()->json(['success' => false, 'message' => 'المهمة غير موجودة أو غير مسندة إليك.'], 404);
            }
            if ($row->locked_at !== null) {
                return response()->json(['success' => false, 'message' => 'هذه المهمة مقفلة ولا يمكن تحريكها.'], 423);
            }
            if ($row->acceptance_status !== Task::ACCEPTANCE_ACCEPTED) {
                return response()->json(['success' => false, 'message' => 'اقبل المهمة أولًا قبل تحريك حالتها.'], 409);
            }
            if ($row->status === 'completed') {
                return response()->json(['success' => false, 'message' => 'المهمة المنجزة معتمدة ولا يمكن تغيير حالتها؛ للتعديل راجع المدير.'], 403);
            }
            if ($data['status'] === 'completed') {
                return response()->json(['success' => false, 'message' => 'اعتماد الإنجاز يتم بعد تقييم المدير؛ حرّك المهمة إلى «بانتظار التقييم».'], 403);
            }

            return TaskStatusService::move($row, $data['status'], $request);
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json([
            'success' => true,
            'data' => new TaskResource($result->load(['department', 'section', 'assignedTo', 'createdBy'])),
        ]);
    }

    // POST /my-work/tasks/{task}/accept
    public function accept(Request $request, int $task): JsonResponse
    {
        return $this->decide($request, $task, fn (Task $t) => TaskAcceptanceService::accept($t, $request), 'تم قبول المهمة بنجاح وانتقالها إلى لوحتك.');
    }

    // POST /my-work/tasks/{task}/reject
    public function reject(Request $request, int $task): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ], [
            'reason.required' => 'سبب الاعتذار مطلوب.',
            'reason.min' => 'سبب الاعتذار قصير جدًا.',
            'reason.max' => 'سبب الاعتذار طويل جدًا؛ الحد الأقصى 1000 حرف.',
        ]);

        return $this->decide($request, $task, fn (Task $t) => TaskAcceptanceService::reject($t, $data['reason'], $request), 'تم تسجيل الاعتذار وإشعار المدير لإعادة التوجيه.');
    }

    /**
     * Re-read the task under a row lock and decide from THAT state: only the assignee, only while acceptance is
     * still pending. Two requests racing for the same task cannot both succeed; the second gets a 409.
     */
    private function decide(Request $request, int $id, callable $apply, string $message): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $id, $apply) {
            $task = Task::lockForUpdate()->find($id);
            if (! $task || (int) $task->assigned_to !== (int) $request->user()->id) {
                return response()->json(['success' => false, 'message' => 'المهمة غير موجودة أو غير مسندة إليك.'], 404);
            }
            // The parent of a collective task the caller has a child of: the answer is given on the child.
            if ($task->subtasks()->where('assigned_to', $request->user()->id)->exists()) {
                return response()->json(['success' => false, 'message' => 'هذه مهمة جماعية؛ ردّك يكون على مهمتك الفرعية.'], 409);
            }
            if ($task->acceptance_status !== Task::ACCEPTANCE_PENDING) {
                return response()->json(['success' => false, 'message' => 'لا يمكن البتّ في مهمة ليست بانتظار موافقتك.'], 409);
            }

            return $apply($task);
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => new TaskResource($result->load(['department', 'assignedTo', 'createdBy'])),
        ]);
    }
}
