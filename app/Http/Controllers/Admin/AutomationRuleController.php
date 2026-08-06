<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AutomationRuleResource;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use Illuminate\Http\Request;

class AutomationRuleController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $rules = AutomationRule::withCount('runs')
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->trigger_type, fn ($q) => $q->where('trigger_type', $request->trigger_type))
            ->latest()
            ->get();

        return response()->json(['success' => true, 'data' => AutomationRuleResource::collection($rules)]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'name'         => 'required|string|max:255',
            'trigger_type' => 'required|string|max:100',
            'conditions'   => 'nullable|array',
            'actions'      => 'required|array',
            'status'       => 'in:active,inactive',
        ]);

        $data['created_by'] = $request->user()->id;
        $rule = AutomationRule::create($data);

        return response()->json(['success' => true, 'data' => new AutomationRuleResource($rule)], 201);
    }

    public function show(AutomationRule $automationRule): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new AutomationRuleResource($automationRule->load('createdBy')),
        ]);
    }

    public function update(Request $request, AutomationRule $automationRule): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'name'         => 'string|max:255',
            'trigger_type' => 'string|max:100',
            'conditions'   => 'nullable|array',
            'actions'      => 'array',
            'status'       => 'in:active,inactive',
        ]);

        $automationRule->update($data);

        return response()->json(['success' => true, 'data' => new AutomationRuleResource($automationRule)]);
    }

    public function destroy(AutomationRule $automationRule): \Illuminate\Http\JsonResponse
    {
        $automationRule->delete();

        return response()->json(['success' => true, 'message' => 'Automation rule deleted.']);
    }

    public function runs(Request $request): \Illuminate\Http\JsonResponse
    {
        $runs = AutomationRun::with('rule')
            ->when($request->rule_id, fn ($q) => $q->where('rule_id', $request->rule_id))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->latest('executed_at')
            ->paginate(50);

        return response()->json([
            'success' => true,
            'data'    => $runs->through(fn ($r) => [
                'id'          => $r->id,
                'rule'        => $r->rule?->name,
                'status'      => $r->status,
                'executed_at' => $r->executed_at,
                'result'      => $r->result,
            ]),
        ]);
    }
}
