<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeetingActionItemResource;
use App\Http\Resources\MeetingDecisionResource;
use App\Http\Resources\MeetingReportResource;
use App\Http\Resources\MeetingResource;
use App\Models\Meeting;
use App\Models\MeetingActionItem;
use App\Models\MeetingDecision;
use App\Models\MeetingReport;
use App\Models\Task;
use App\Services\ActivityLogger;
use App\Services\DepartmentAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MeetingController extends Controller
{
    public function __construct(private readonly DepartmentAccessService $departmentAccess)
    {
    }

    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        $query = Meeting::with(['department', 'organizer', 'report']);

        // Quality & Governance is a cross-department oversight function — same
        // carve-out already established for Weekly Reports. Everyone else
        // (including global roles, via DepartmentAccessService::isGlobal) is
        // scoped through the canonical membership/leadership relationships,
        // never the legacy user.department_id field which doesn't exist on
        // the User model.
        $hasGlobalAccess = $this->departmentAccess->isGlobal($user) || $user->inDepartment('quality-governance');

        if (!$hasGlobalAccess) {
            $accessibleDeptIds = $this->departmentAccess->getAccessibleDepartmentIds($user);
            $query->where(function ($q) use ($accessibleDeptIds, $user) {
                $q->whereIn('department_id', $accessibleDeptIds)
                  ->orWhere('organizer_id', $user->id);
            });
        }

        if ($request->department_id && ($hasGlobalAccess || $this->departmentAccess->canAccessDepartment($user, (int) $request->department_id))) {
            $query->where('department_id', $request->department_id);
        }

        if ($request->type) {
            $query->where('type', $request->type);
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        $perPage = (int) $request->get('per_page', 50);
        $meetings = $query->latest('meeting_date')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => MeetingResource::collection($meetings)->response()->getData(true)
        ]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'title'         => 'required|string|max:255',
            'description'   => 'nullable|string',
            'type'          => 'in:executive,department,programs,partnerships,quality,external_partner,general,exec,departments,external',
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

        $typeAliases = [
            'exec'        => 'executive',
            'departments' => 'department',
            'external'    => 'external_partner',
        ];
        if (! empty($data['type']) && isset($typeAliases[$data['type']])) {
            $data['type'] = $typeAliases[$data['type']];
        }

        $data['organizer_id'] = $request->user()->id;

        if (empty($data['department_id'])) {
            $defaultDeptId = $this->departmentAccess->getAccessibleDepartmentIds($request->user())->first();
            if ($defaultDeptId) {
                $data['department_id'] = $defaultDeptId;
            }
        }

        $meeting = Meeting::create($data);

        ActivityLogger::log('meeting.created', $meeting, [], $request);

        return response()->json([
            'success' => true,
            'data'    => new MeetingResource($meeting->load(['department', 'organizer'])),
        ], 201);
    }

    protected function canUserAccessMeeting($user, Meeting $meeting): bool
    {
        if (!$user) {
            return false;
        }

        if ($this->departmentAccess->isGlobal($user) || $user->inDepartment('quality-governance')) {
            return true;
        }

        if ($meeting->department_id && $this->departmentAccess->canAccessDepartment($user, (int) $meeting->department_id)) {
            return true;
        }

        if ($meeting->organizer_id == $user->id) {
            return true;
        }

        return false;
    }

    public function show(Request $request, Meeting $meeting): \Illuminate\Http\JsonResponse
    {
        if (!$this->canUserAccessMeeting($request->user(), $meeting)) {
            return response()->json([
                'success' => false,
                'message' => 'غير مصرح لك باستعراض اجتماع خاص بإدارة أخرى',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data'    => new MeetingResource(
                $meeting->load(['department', 'organizer', 'decisions.decidedBy', 'actionItems.assignedTo', 'report'])
            ),
        ]);
    }

    public function update(Request $request, Meeting $meeting): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'title'         => 'string|max:255',
            'description'   => 'nullable|string',
            'type'          => 'in:executive,department,programs,partnerships,quality,external_partner,general,exec,departments,external',
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

        $typeAliases = [
            'exec'        => 'executive',
            'departments' => 'department',
            'external'    => 'external_partner',
        ];
        if (! empty($data['type']) && isset($typeAliases[$data['type']])) {
            $data['type'] = $typeAliases[$data['type']];
        }

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

    public function storeReport(Request $request, Meeting $meeting): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'absentees'               => 'nullable|array',
            'team_commitment_score'   => 'nullable|integer|min:1|max:5',
            'team_commitment_notes'   => 'nullable|string',
            'completed_tasks_summary' => 'nullable|string',
            'kpi_percentage'          => 'nullable|integer|min:0|max:100',
            'top_interactive_member'  => 'nullable|string',
            'challenges'              => 'nullable|string',
            'executive_requests'      => 'nullable|string',
            'next_month_goals'        => 'nullable|string',
            'improvement_suggestions' => 'nullable|string',
        ]);

        $report = $meeting->report()->updateOrCreate(
            ['meeting_id' => $meeting->id],
            $data
        );

        ActivityLogger::log('meeting.report_submitted', $meeting, [], $request);

        return response()->json([
            'success' => true,
            'data'    => new MeetingReportResource($report),
        ], 200);
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

    /** تحديث بند متابعة (إنجاز/نص) — تستهلكه صالة الاجتماعات. */
    public function updateActionItem(Request $request, Meeting $meeting, MeetingActionItem $actionItem): \Illuminate\Http\JsonResponse
    {
        abort_if($actionItem->meeting_id !== $meeting->id, 404);

        $data = $request->validate([
            'done'   => 'nullable|boolean',
            'text'   => 'nullable|string|max:255',
            'status' => 'nullable|in:pending,in_progress,completed,cancelled',
        ]);

        $updates = [];
        if (array_key_exists('done', $data)) {
            $updates['status'] = $data['done'] ? 'completed' : 'pending';
        }
        if (! empty($data['status'])) {
            $updates['status'] = $data['status'];
        }
        if (! empty($data['text'])) {
            $updates['title'] = $data['text'];
        }
        $actionItem->update($updates);

        return response()->json([
            'success' => true,
            'data'    => new MeetingActionItemResource($actionItem->fresh()->load('assignedTo')),
        ]);
    }

    /**
     * التقرير الدوري من صالة الاجتماعات — يُخزَّن في نظام «تقارير الاجتماعات»
     * الموحد (meeting_reports) فيظهر في الأرشيف نفسه الذي يقرؤه المدراء.
     */
    public function report(Request $request, Meeting $meeting): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'absentees'               => 'nullable|array',
            'team_commitment_score'   => 'nullable|numeric|min:0|max:10',
            'team_commitment_notes'   => 'nullable|string',
            'completed_tasks_summary' => 'nullable|string',
            'kpi_percentage'          => 'nullable|numeric|min:0|max:100',
            'top_interactive_member'  => 'nullable|string|max:255',
            'challenges'              => 'nullable|string',
            'executive_requests'      => 'nullable|string',
            'next_month_goals'        => 'nullable|string',
            'improvement_suggestions' => 'nullable|string',
            // محضر الاجتماع (تُحفظ على الاجتماع نفسه)
            'agenda'                  => 'nullable|array',
            'agenda.*'                => 'nullable|string|max:500',
            'attendees'               => 'nullable|array',
            'attendees.*.name'        => 'nullable|string|max:255',
            'attendees.*.role'        => 'nullable|string|max:255',
            'minutes'                 => 'nullable|string',
            'decisions'               => 'nullable|array',
            'decisions.*.title'       => 'nullable|string|max:255',
            'action_items'            => 'nullable|array',
            'action_items.*.title'    => 'nullable|string|max:255',
            'action_items.*.due_date' => 'nullable|date',
        ]);

        $notesParts = array_filter([
            isset($data['team_commitment_score']) ? "التزام الفريق: {$data['team_commitment_score']}/10" : null,
            isset($data['kpi_percentage']) ? "نسبة KPI: {$data['kpi_percentage']}%" : null,
            ! empty($data['top_interactive_member']) ? "الأكثر تفاعلاً: {$data['top_interactive_member']}" : null,
            ! empty($data['absentees']) ? 'الغائبون: ' . implode('، ', $data['absentees']) : null,
            $data['team_commitment_notes'] ?? null,
            $data['improvement_suggestions'] ?? null,
        ]);

        $report = DB::transaction(function () use ($request, $meeting, $data, $notesParts) {
        $report = \App\Models\MeetingReport::updateOrCreate(
            ['meeting_id' => $meeting->id],
            [
                'department_id'            => $meeting->department_id,
                'title'                    => 'تقرير دوري — ' . $meeting->title,
                'meeting_date'             => $meeting->meeting_date ?? now()->toDateString(),
                'achieved'                 => $data['completed_tasks_summary'] ?? null,
                'planned'                  => $data['next_month_goals'] ?? null,
                'needs'                    => $data['executive_requests'] ?? null,
                'needs_reason'             => $data['challenges'] ?? null,
                'notes'                    => $notesParts ? implode("\n", $notesParts) : null,
                'absentees'                => $data['absentees'] ?? null,
                'team_commitment_score'    => $data['team_commitment_score'] ?? null,
                'team_commitment_notes'    => $data['team_commitment_notes'] ?? null,
                'completed_tasks_summary'  => $data['completed_tasks_summary'] ?? null,
                'kpi_percentage'           => $data['kpi_percentage'] ?? null,
                'top_interactive_member'   => $data['top_interactive_member'] ?? null,
                'challenges'               => $data['challenges'] ?? null,
                'executive_requests'       => $data['executive_requests'] ?? null,
                'next_month_goals'         => $data['next_month_goals'] ?? null,
                'improvement_suggestions'  => $data['improvement_suggestions'] ?? null,
                'created_by'               => $request->user()->id,
            ]
        );

        $meetingUpdates = ['status' => 'completed'];

        $agenda = collect($data['agenda'] ?? [])
            ->map(fn ($v) => trim((string) $v))
            ->filter()
            ->values()
            ->all();
        if ($agenda) {
            $meetingUpdates['agenda'] = $agenda;
        }

        $attendees = collect($data['attendees'] ?? [])
            ->filter(fn ($a) => filled($a['name'] ?? null))
            ->map(fn ($a) => ['name' => trim($a['name']), 'role' => trim($a['role'] ?? '')])
            ->values()
            ->all();
        if ($attendees) {
            $meetingUpdates['attendees'] = $attendees;
        }

        if (filled($data['minutes'] ?? null)) {
            $meetingUpdates['minutes'] = trim($data['minutes']);
        }

        $meeting->update($meetingUpdates);

        // القرارات وبنود العمل: لا نمسح الموجود إلا إذا وصلت قيم جديدة غير فارغة.
        $decisions = collect($data['decisions'] ?? [])
            ->map(fn ($d) => trim((string) ($d['title'] ?? '')))
            ->filter()
            ->values();
        if ($decisions->isNotEmpty()) {
            $meeting->decisions()->delete();
            foreach ($decisions as $title) {
                $meeting->decisions()->create([
                    'title'      => $title,
                    'decided_by' => $request->user()->id,
                ]);
            }
        }

        $actionItems = collect($data['action_items'] ?? [])
            ->filter(fn ($i) => filled($i['title'] ?? null));
        if ($actionItems->isNotEmpty()) {
            // البنود التي حُوِّلت لمهام فعلية تبقى كما هي.
            $meeting->actionItems()->whereNull('task_id')->delete();
            foreach ($actionItems as $item) {
                $meeting->actionItems()->create([
                    'title'    => trim($item['title']),
                    'due_date' => $item['due_date'] ?? null,
                    'status'   => 'pending',
                ]);
            }
        }

        return $report;
        });

        return response()->json([
            'success' => true,
            'data'    => new MeetingReportResource($report),
        ], 200);
    }
}
