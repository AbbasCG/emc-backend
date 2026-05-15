<?php

namespace App\Http\Controllers\Admin\AI;

use App\Http\Controllers\Controller;
use App\Models\AiUsageLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiUsageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $logs = AiUsageLog::query()
            ->when($request->provider, fn ($q) => $q->where('provider', $request->provider))
            ->when($request->action, fn ($q) => $q->where('action', $request->action))
            ->when($request->user_id, fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->from, fn ($q) => $q->where('created_at', '>=', $request->from))
            ->when($request->to, fn ($q) => $q->where('created_at', '<=', $request->to))
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json([
            'success' => true,
            'data'    => $logs->items(),
            'meta'    => ['total' => $logs->total(), 'current_page' => $logs->currentPage()],
        ]);
    }

    /**
     * GET /api/admin/ai/usage/summary
     *
     * Aggregate usage stats by provider/model.
     */
    public function summary(Request $request): JsonResponse
    {
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to   = $request->input('to', now()->toDateString());

        $summary = AiUsageLog::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('provider, model, COUNT(*) as calls, SUM(tokens_input) as total_input, SUM(tokens_output) as total_output, SUM(estimated_cost) as total_cost, status')
            ->groupBy('provider', 'model', 'status')
            ->get();

        $totals = AiUsageLog::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('COUNT(*) as total_calls, SUM(tokens_input + tokens_output) as total_tokens, SUM(estimated_cost) as total_cost')
            ->first();

        return response()->json([
            'success' => true,
            'data'    => [
                'period'   => ['from' => $from, 'to' => $to],
                'totals'   => $totals,
                'by_model' => $summary,
            ],
        ]);
    }
}
