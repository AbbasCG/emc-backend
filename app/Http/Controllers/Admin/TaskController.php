<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskChecklistItemResource;
use App\Http\Resources\TaskCommentResource;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskComment;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    private function baseQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Task::with(['department', 'section', 'assignedTo', 'createdBy', 'checklistItems']);
    }

    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $tasks = $this->baseQuery()
            ->when($request->department_id, fn ($q) => $q->where('department_id', $request->department_id))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->priority, fn ($q) => $q->where('priority', $request->priority))
            ->when($request->assigned_to, fn ($q) => $q->where('assigned_to', $request->assigned_to))
            ->latest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => TaskResource::collection($tasks)->response()->getData(true)]);
    }

    public function myTasks(Request $request): \Illuminate\Http\JsonResponse
    {
        $tasks = $this->baseQuery()
            ->where('assigned_to', $request->user()->id)
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->get();

        return response()->json(['success' => true, 'data' => TaskResource::collection($tasks)]);
    }

    public function overdue(Request $request): \Illuminate\Http\JsonResponse
    {
        $tasks = $this->baseQuery()
            ->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->latest()
            ->get();

        return response()->json(['success' => true, 'data' => TaskResource::collection($tasks)]);
    }

    public function kanban(Request $request): \Illuminate\Http\JsonResponse
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
                    ->get()
            );
        }

        return response()->json(['success' => true, 'data' => $board]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'title'        => 'required|string|max:255',
            'description'  => 'nullable|string',
            'department_id'=> 'nullable|exists:departments,id',
            'section_id'   => 'nullable|exists:department_sections,id',
            'assigned_to'  => 'nullable|exists:users,id',
            'priority'     => 'in:low,medium,high,urgent',
            'status'       => 'in:idea,under_review,planning,waiting_approval,in_progress,completed,postponed,cancelled,needs_evaluation',
            'start_date'   => 'nullable|date',
            'due_date'     => 'nullable|date',
            'related_type' => 'nullable|string',
            'related_id'   => 'nullable|integer',
            'tags'         => 'nullable|array',
        ]);

        $data['created_by'] = $request->user()->id;

        $task = Task::create($data);

        ActivityLogger::log('task.created', $task, [], $request);

        return response()->json([
            'success' => true,
            'data'    => new TaskResource($task->load(['department', 'section', 'assignedTo', 'createdBy'])),
        ], 201);
    }

    public function show(Task $task): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new TaskResource(
                $task->load(['department', 'section', 'assignedTo', 'createdBy', 'comments.user', 'checklistItems'])
            ),
        ]);
    }

    public function update(Request $request, Task $task): \Illuminate\Http\JsonResponse
    {
        if ($task->locked_at !== null) {
            return response()->json([
                'success' => false,
                'message' => 'هذه المهمة مقفلة ولا يمكن تعديلها. اطلب فتحها من مدير مخوَّل.',
            ], 423);
        }

        $data = $request->validate([
            'title'        => 'string|max:255',
            'description'  => 'nullable|string',
            'department_id'=> 'nullable|exists:departments,id',
            'section_id'   => 'nullable|exists:department_sections,id',
            'assigned_to'  => 'nullable|exists:users,id',
            'priority'     => 'in:low,medium,high,urgent',
            'status'       => 'in:idea,under_review,planning,waiting_approval,in_progress,completed,postponed,cancelled,needs_evaluation',
            'start_date'   => 'nullable|date',
            'due_date'     => 'nullable|date',
            'related_type' => 'nullable|string',
            'related_id'   => 'nullable|integer',
            'tags'         => 'nullable|array',
        ]);

        $task->update($data);

        ActivityLogger::log('task.updated', $task, $data, $request);

        return response()->json([
            'success' => true,
            'data'    => new TaskResource($task->load(['department', 'section', 'assignedTo', 'createdBy'])),
        ]);
    }

    public function destroy(Task $task, Request $request): \Illuminate\Http\JsonResponse
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

    public function storeComment(Request $request, Task $task): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate(['comment' => 'required|string']);

        $comment = $task->comments()->create([
            'user_id' => $request->user()->id,
            'comment' => $data['comment'],
        ]);

        return response()->json([
            'success' => true,
            'data'    => new TaskCommentResource($comment->load('user')),
        ], 201);
    }

    public function storeChecklist(Request $request, Task $task): \Illuminate\Http\JsonResponse
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

    public function updateChecklist(Request $request, Task $task, TaskChecklistItem $item): \Illuminate\Http\JsonResponse
    {
        abort_if($item->task_id !== $task->id, 404);

        $data = $request->validate([
            'title'        => 'string|max:255',
            'is_completed' => 'boolean',
            'sort_order'   => 'integer',
        ]);

        $item->update($data);

        return response()->json([
            'success' => true,
            'data'    => new TaskChecklistItemResource($item),
        ]);
    }

    /** قفل مهمة مُسلَّمة: تصبح للقراءة فقط على لوحة التشغيل، ولا تُعدَّل حتى تُفتح. */
    public function lock(Request $request, Task $task): \Illuminate\Http\JsonResponse
    {
        $task->update(['locked_at' => now(), 'locked_by' => $request->user()->id]);
        ActivityLogger::log('task.locked', $task, [], $request);

        return response()->json([
            'success' => true,
            'data'    => new TaskResource($task->load(['department', 'section', 'assignedTo', 'createdBy'])),
        ]);
    }

    public function unlock(Request $request, Task $task): \Illuminate\Http\JsonResponse
    {
        $task->update(['locked_at' => null, 'locked_by' => null]);
        ActivityLogger::log('task.unlocked', $task, [], $request);

        return response()->json([
            'success' => true,
            'data'    => new TaskResource($task->load(['department', 'section', 'assignedTo', 'createdBy'])),
        ]);
    }
}
