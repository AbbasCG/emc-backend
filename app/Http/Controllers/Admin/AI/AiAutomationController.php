<?php

namespace App\Http\Controllers\Admin\AI;

use App\Http\Controllers\Controller;
use App\Models\AiAutomationExecution;
use App\Models\AiAutomationRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiAutomationController extends Controller
{
    public const SUPPORTED_TRIGGERS = [
        'meeting_created',
        'meeting_completed',
        'overdue_assignment',
        'failed_payment',
        'low_attendance',
        'registration_created',
        'certificate_issued',
        'task_overdue',
        'student_inactive',
        'weekly_schedule',
    ];

    public const SUPPORTED_ACTIONS = [
        'summarize_meeting',
        'create_task',
        'generate_report',
        'send_notification',
        'generate_recommendations',
        'generate_insights',
        'send_email',
    ];

    public function index(Request $request): JsonResponse
    {
        $rules = AiAutomationRule::query()
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->withCount('executions')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $rules->items(),
            'meta'    => [
                'total'               => $rules->total(),
                'supported_triggers'  => self::SUPPORTED_TRIGGERS,
                'supported_actions'   => self::SUPPORTED_ACTIONS,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'          => 'required|string|max:255',
            'trigger'       => 'required|in:' . implode(',', self::SUPPORTED_TRIGGERS),
            'conditions'    => 'nullable|array',
            'ai_action'     => 'required|in:' . implode(',', self::SUPPORTED_ACTIONS),
            'action_config' => 'nullable|array',
            'status'        => 'sometimes|in:active,inactive',
        ]);

        $rule = AiAutomationRule::create([
            ...$data,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['success' => true, 'data' => $rule], 201);
    }

    public function show(AiAutomationRule $aiAutomationRule): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $aiAutomationRule->load('executions'),
        ]);
    }

    public function update(Request $request, AiAutomationRule $aiAutomationRule): JsonResponse
    {
        $data = $request->validate([
            'name'          => 'sometimes|string|max:255',
            'trigger'       => 'sometimes|in:' . implode(',', self::SUPPORTED_TRIGGERS),
            'conditions'    => 'nullable|array',
            'ai_action'     => 'sometimes|in:' . implode(',', self::SUPPORTED_ACTIONS),
            'action_config' => 'nullable|array',
            'status'        => 'sometimes|in:active,inactive',
        ]);

        $aiAutomationRule->update($data);

        return response()->json(['success' => true, 'data' => $aiAutomationRule]);
    }

    public function destroy(AiAutomationRule $aiAutomationRule): JsonResponse
    {
        $aiAutomationRule->delete();

        return response()->json(['success' => true, 'message' => 'Automation rule deleted.']);
    }

    public function executions(AiAutomationRule $aiAutomationRule): JsonResponse
    {
        $executions = $aiAutomationRule->executions()->paginate(20);

        return response()->json(['success' => true, 'data' => $executions->items()]);
    }
}
