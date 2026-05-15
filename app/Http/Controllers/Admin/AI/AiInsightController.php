<?php

namespace App\Http\Controllers\Admin\AI;

use App\Http\Controllers\Controller;
use App\Jobs\AI\GenerateAiInsightsJob;
use App\Models\AiInsight;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiInsightController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $insights = AiInsight::query()
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->when($request->severity, fn ($q) => $q->where('severity', $request->severity))
            ->orderByDesc('generated_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $insights->items(),
            'meta'    => ['total' => $insights->total(), 'current_page' => $insights->currentPage()],
        ]);
    }

    /**
     * POST /api/admin/ai/insights/generate
     *
     * Trigger a fresh generation run (queued).
     */
    public function generate(): JsonResponse
    {
        GenerateAiInsightsJob::dispatch()->onQueue(config('ai.queue', 'default'));

        return response()->json([
            'success' => true,
            'message' => 'Insight generation queued. Results will appear shortly.',
        ], 202);
    }

    public function destroy(AiInsight $aiInsight): JsonResponse
    {
        $aiInsight->delete();

        return response()->json(['success' => true, 'message' => 'Insight deleted.']);
    }
}
