<?php

namespace App\Jobs\AI;

use App\AI\Retrieval\KnowledgeRetrievalService;
use App\Models\KnowledgeArticle;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queued embedding generation for knowledge articles.
 *
 * Usage:
 *   GenerateEmbeddingJob::dispatch($article->id);
 */
class GenerateEmbeddingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;
    public int $backoff = 30;

    public function __construct(public readonly int $articleId) {}

    public function handle(KnowledgeRetrievalService $service): void
    {
        $article = KnowledgeArticle::query()->find($this->articleId);

        if (! $article) {
            return;
        }

        try {
            $service->indexArticle($article);
        } catch (\Throwable $e) {
            Log::error('GenerateEmbeddingJob failed', ['article_id' => $this->articleId, 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}
