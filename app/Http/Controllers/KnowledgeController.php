<?php

namespace App\Http\Controllers;

use App\Http\Resources\KnowledgeArticleResource;
use App\Http\Resources\KnowledgeCategoryResource;
use App\Models\KnowledgeArticle;
use App\Models\KnowledgeCategory;
use Illuminate\Http\Request;

class KnowledgeController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $articles = KnowledgeArticle::with(['category', 'author'])
            ->where('status', 'published')
            ->when(
                ! in_array($request->user()?->role, ['admin', 'teacher']),
                fn ($q) => $q->whereIn('visibility', ['public', 'internal'])
            )
            ->when($request->category, fn ($q) => $q->whereHas('category', fn ($q2) => $q2->where('slug', $request->category)))
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->when($request->q, fn ($q) => $q->where('title', 'like', '%' . $request->q . '%'))
            ->latest('published_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => KnowledgeArticleResource::collection($articles)->response()->getData(true),
        ]);
    }

    public function show(string $slug): \Illuminate\Http\JsonResponse
    {
        $article = KnowledgeArticle::with(['category', 'author', 'attachments'])
            ->where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data'    => new KnowledgeArticleResource($article),
        ]);
    }
}
