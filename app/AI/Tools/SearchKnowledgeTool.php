<?php

namespace App\AI\Tools;

use App\Models\KnowledgeArticle;

class SearchKnowledgeTool implements AiToolInterface
{
    public function name(): string
    {
        return 'search_knowledge';
    }

    public function description(): string
    {
        return 'Search the EMC knowledge base for articles, SOPs, and guides. Returns top matches.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'query'  => ['type' => 'string', 'description' => 'The search query'],
                'limit'  => ['type' => 'integer', 'description' => 'Max results (default 5)', 'default' => 5],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $args, ?int $userId = null): mixed
    {
        $query = trim($args['query'] ?? '');
        $limit = min((int) ($args['limit'] ?? 5), 10);

        if (empty($query)) {
            return ['error' => 'query is required'];
        }

        $articles = KnowledgeArticle::query()
            ->where(function ($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                    ->orWhere('content', 'like', "%{$query}%");
            })
            ->where('visibility', 'public')
            ->where('status', 'published')
            ->take($limit)
            ->get(['id', 'title', 'slug', 'summary']);

        return [
            'query'   => $query,
            'results' => $articles->map(fn ($a) => [
                'id'      => $a->id,
                'title'   => $a->title,
                'summary' => $a->summary,
                'url'     => "/knowledge/{$a->slug}",
            ])->toArray(),
        ];
    }

    public function isAllowedForRole(string $role): bool
    {
        return true;
    }
}
