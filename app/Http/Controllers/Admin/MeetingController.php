<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeetingActionItemResource;
use App\Http\Resources\MeetingDecisionResource;
use App\Http\Resources\MeetingResource;
use App\Models\Meeting;
use App\Models\MeetingActionItem;
use App\Models\MeetingDecision;
use App\Models\Task;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MeetingController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $meetings = Meeting::with(['department', 'organizer'])
            ->when($request->department_id, fn ($q) => $q->where('department_id', $request->department_id))
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest('meeting_date')
            ->paginate(20);

        return response()->json(['success' => true, 'data' => MeetingResource::collection($meetings)->response()->getData(true)]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'title'         => 'required|string|max:255',
            'description'   => 'nullable|string',
            'type'          => 'in:executive,department,programs,partnerships,quality,external_partner,general',
            'department_id' => 'nullable|exists:departments,id',
            'meeting_date'  => 'required|date',
            'start_time'    => 'required|date_format:H:i',
            'end_time'      => 'nullable|date_format:H:i',
            'meeting_url'   => 'nullable|url',
            'status'        => 'in:scheduled,in_progress,completed,cancelled',
            'agenda'        => 'nullable|array',
            'minutes'       => 'nullable|string',
            'attendees'     => 'nullable|array',
            'files'         => 'nullable|array',
            'recording_url' => 'nullable|url',
        ]);

        $data['organizer_id'] = $request->user()->id;

        $meeting = Meeting::create($data);

        ActivityLogger::log('meeting.created', $meeting, [], $request);

        return response()->json([
            'success' => true,
            'data'    => new MeetingResource($meeting->load(['department', 'organizer'])),
        ], 201);
    }

    public function show(Meeting $meeting): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new MeetingResource(
                $meeting->load(['department', 'organizer', 'decisions.decidedBy', 'actionItems.assignedTo'])
            ),
        ]);
    }

    public function update(Request $request, Meeting $meeting): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'title'         => 'string|max:255',
            'description'   => 'nullable|string',
            'type'          => 'in:executive,department,programs,partnerships,quality,external_partner,general',
            'department_id' => 'nullable|exists:departments,id',
            'meeting_date'  => 'date',
            'start_time'    => 'date_format:H:i',
            'end_time'      => 'nullable|date_format:H:i',
            'meeting_url'   => 'nullable|url',
            'status'        => 'in:scheduled,in_progress,completed,cancelled',
            'agenda'        => 'nullable|array',
            'minutes'       => 'nullable|string',
            'attendees'     => 'nullable|array',
            'files'         => 'nullable|array',
            'recording_url' => 'nullable|url',
        ]);

        $meeting->update($data);

        return response()->json([
            'success' => true,
            'data'    => new MeetingResource($meeting->load(['department', 'organizer'])),
        ]);
    }

    public function destroy(Meeting $meeting, Request $request): \Illuminate\Http\JsonResponse
    {
        ActivityLogger::log('meeting.deleted', $meeting, [], $request);
        $meeting->delete();

        return response()->json(['success' => true, 'message' => 'Meeting deleted.']);
    }

    public function storeDecision(Request $request, Meeting $meeting): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'decided_by'  => 'nullable|exists:users,id',
        ]);

        $decision = $meeting->decisions()->create($data);

        return response()->json([
            'success' => true,
            'data'    => new MeetingDecisionResource($decision->load('decidedBy')),
        ], 201);
    }

    public function storeActionItem(Request $request, Meeting $meeting): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'assigned_to' => 'nullable|exists:users,id',
            'due_date'    => 'nullable|date',
            'status'      => 'in:pending,in_progress,completed,cancelled',
        ]);

        $item = $meeting->actionItems()->create($data);

        return response()->json([
            'success' => true,
            'data'    => new MeetingActionItemResource($item->load('assignedTo')),
        ], 201);
    }

    public function convertActionItemToTask(Request $request, Meeting $meeting, MeetingActionItem $actionItem): \Illuminate\Http\JsonResponse
    {
        abort_if($actionItem->meeting_id !== $meeting->id, 404);

        $data = $request->validate([
            'department_id' => 'nullable|exists:departments,id',
            'priority'      => 'in:low,medium,high,urgent',
        ]);

        $task = DB::transaction(function () use ($actionItem, $data, $request) {
            $task = Task::create([
                'title'         => $actionItem->title,
                'assigned_to'   => $actionItem->assigned_to,
                'due_date'      => $actionItem->due_date,
                'created_by'    => $request->user()->id,
                'department_id' => $data['department_id'] ?? null,
                'priority'      => $data['priority'] ?? 'medium',
                'status'        => 'planning',
            ]);

            $actionItem->update(['task_id' => $task->id]);

            return $task;
        });

        ActivityLogger::log('task.converted_from_action_item', $task, ['action_item_id' => $actionItem->id], $request);

        return response()->json([
            'success' => true,
            'message' => 'Action item converted to task.',
            'data'    => ['task_id' => $task->id],
        ], 201);
    }
}
