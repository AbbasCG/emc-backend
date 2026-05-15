<?php

namespace App\Http\Controllers;

use App\AI\Services\AiInsightService;
use App\Jobs\AI\GenerateStudentRecommendationsJob;
use App\Models\AiRecommendation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiRecommendationController extends Controller
{
    public function __construct(private readonly AiInsightService $insightService) {}

    /**
     * GET /api/ai/recommendations
     *
     * List active recommendations for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $query = AiRecommendation::query()
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->orderBy('priority', 'desc')
            ->orderBy('generated_at', 'desc');

        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }

        $recommendations = $query->paginate(10);

        return response()->json([
            'success' => true,
            'data'    => $recommendations->items(),
            'meta'    => [
                'current_page' => $recommendations->currentPage(),
                'last_page'    => $recommendations->lastPage(),
                'total'        => $recommendations->total(),
            ],
        ]);
    }

    /**
     * POST /api/ai/recommendations/refresh
     *
     * Queue a fresh batch of recommendations for the current user.
     */
    public function refresh(Request $request): JsonResponse
    {
        GenerateStudentRecommendationsJob::dispatch($request->user()->id)
            ->onQueue(config('ai.queue', 'default'));

        return response()->json([
            'success' => true,
            'message' => 'Recommendations are being regenerated. Check back shortly.',
        ], 202);
    }

    /**
     * PATCH /api/ai/recommendations/{id}/dismiss
     */
    public function dismiss(Request $request, AiRecommendation $recommendation): JsonResponse
    {
        abort_if($recommendation->user_id !== $request->user()->id, 403);

        $recommendation->update(['status' => 'dismissed']);

        return response()->json(['success' => true, 'message' => 'Recommendation dismissed.']);
    }
}
