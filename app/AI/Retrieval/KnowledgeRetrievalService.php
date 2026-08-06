<?php

namespace App\AI\Retrieval;

use App\AI\AiManager;
use App\Models\KnowledgeArticle;
use App\Models\KnowledgeEmbedding;

/**
 * RAG-foundation retrieval service.
 *
 * Phase 8 uses cosine similarity on JSON-stored embeddings.
 * When pgvector or Pinecone is configured, swap the findSimilar() method.
 */
class KnowledgeRetrievalService
{
    public function __construct(private readonly AiManager $ai) {}

    /**
     * Find knowledge chunks semantically similar to a query.
     *
     * @param  string  $query
     * @param  int     $topK    Number of results
     * @param  string|null $type  Filter by embeddable_type
     * @return array   Ranked results with chunk text and metadata
     */
    public function search(string $query, int $topK = 5, ?string $type = null): array
    {
        $queryVector = $this->ai->embed($query);

        // If no real embedding available (mock provider) fall back to keyword search
        if (count($queryVector) <= 4) {
            return $this->keywordFallback($query, $topK, $type);
        }

        $embeddings = KnowledgeEmbedding::query()
            ->when($type, fn ($q) => $q->where('embeddable_type', $type))
            ->whereNotNull('embedding')
            ->get();

        $scored = $embeddings
            ->map(fn (KnowledgeEmbedding $e) => [
                'id'         => $e->id,
                'score'      => $e->cosineSimilarity($queryVector),
                'chunk_text' => $e->chunk_text,
                'metadata'   => $e->metadata,
                'type'       => $e->embeddable_type,
                'entity_id'  => $e->embeddable_id,
            ])
            ->sortByDesc('score')
            ->take($topK)
            ->values();

        return $scored->toArray();
    }

    /**
     * Index a knowledge article — chunk it and store embeddings.
     */
    public function indexArticle(KnowledgeArticle $article): void
    {
        $chunks = $this->chunk($article->title . "\n\n" . ($article->content ?? ''));

        KnowledgeEmbedding::query()
            ->where('embeddable_type', KnowledgeArticle::class)
            ->where('embeddable_id', $article->id)
            ->delete();

        foreach ($chunks as $i => $chunk) {
            $vector = $this->ai->embed($chunk);

            KnowledgeEmbedding::create([
                'embeddable_type' => KnowledgeArticle::class,
                'embeddable_id'   => $article->id,
                'chunk_text'      => $chunk,
                'embedding'       => $vector ?: null,
                'metadata'        => ['chunk_index' => $i, 'source' => 'knowledge_article', 'title' => $article->title],
                'model'           => $this->ai->provider()->name() . ':embed',
            ]);
        }
    }

    // -----------------------------------------------------------------------

    /** Split text into ~500-token chunks with overlap. */
    private function chunk(string $text, int $size = 500, int $overlap = 50): array
    {
        $words  = preg_split('/\s+/', trim($text));
        $chunks = [];
        $i      = 0;

        while ($i < count($words)) {
            $chunk    = implode(' ', array_slice($words, $i, $size));
            $chunks[] = $chunk;
            $i += $size - $overlap;
        }

        return array_filter($chunks);
    }

    private function keywordFallback(string $query, int $topK, ?string $type): array
    {
        return KnowledgeArticle::query()
            ->where(fn ($q) => $q->where('title', 'like', "%{$query}%")->orWhere('content', 'like', "%{$query}%"))
            ->where('status', 'published')
            ->take($topK)
            ->get(['id', 'title', 'summary'])
            ->map(fn ($a) => [
                'id'         => null,
                'score'      => 0.5,
                'chunk_text' => $a->summary ?? $a->title,
                'metadata'   => ['title' => $a->title, 'source' => 'knowledge_article', 'entity_id' => $a->id],
                'type'       => KnowledgeArticle::class,
                'entity_id'  => $a->id,
            ])
            ->toArray();
    }
}
