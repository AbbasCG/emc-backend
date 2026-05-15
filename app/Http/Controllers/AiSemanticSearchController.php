<?php

namespace App\Http\Controllers;

use App\AI\Retrieval\KnowledgeRetrievalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiSemanticSearchController extends Controller
{
    public function __construct(private readonly KnowledgeRetrievalService $retrieval) {}

    /**
     * POST /api/ai/search
     *
     * Semantic search across the knowledge base.
     * Falls back to keyword search when embeddings are unavailable.
     */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'query'   => 'required|string|min:2|max:500',
            'top_k'   => 'sometimes|integer|min:1|max:20',
            'type'    => 'sometimes|string|nullable',
        ]);

        $results = $this->retrieval->search(
            $data['query'],
            $data['top_k'] ?? 5,
            $data['type'] ?? null,
        );

        // Group results by source type
        $grouped = [];
        foreach ($results as $result) {
            $source             = class_basename($result['type'] ?? 'Unknown');
            $grouped[$source][] = $result;
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'query'   => $data['query'],
                'count'   => count($results),
                'results' => $results,
                'grouped' => $grouped,
            ],
        ]);
    }
}
