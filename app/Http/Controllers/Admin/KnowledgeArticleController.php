<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\KnowledgeArticleResource;
use App\Models\KnowledgeArticle;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class KnowledgeArticleController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $articles = KnowledgeArticle::with(['category', 'author'])
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->when($request->category_id, fn ($q) => $q->where('category_id', $request->category_id))
            ->when($request->q, fn ($q) => $q->where('title', 'like', '%' . $request->q . '%'))
            ->latest()
            ->paginate(20);

        return response()->json(['success' => true, 'data' => KnowledgeArticleResource::collection($articles)->response()->getData(true)]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'category_id'   => 'nullable|exists:knowledge_categories,id',
            'department_id' => 'nullable|exists:departments,id',
            'title'         => 'required|string|max:255',
            'slug'          => 'nullable|string|unique:knowledge_articles,slug',
            'excerpt'       => 'nullable|string',
            'content'       => 'required|string',
            'type'          => 'in:policy,sop,guide,template,report,lesson_learned,training_material,internal_note',
            'visibility'    => 'in:public,internal,department,role_based',
            'status'        => 'in:draft,published,archived',
            'tags'          => 'nullable|array',
            'published_at'  => 'nullable|date',
        ]);

        $data['slug']      = $data['slug'] ?? Str::slug($data['title']) . '-' . Str::random(4);
        $data['author_id'] = $request->user()->id;

        if (($data['status'] ?? 'draft') === 'published' && empty($data['published_at'])) {
            $data['published_at'] = now();
        }

        $article = KnowledgeArticle::create($data);

        return response()->json([
            'success' => true,
            'data'    => new KnowledgeArticleResource($article->load(['category', 'author'])),
        ], 201);
    }

    public function show(KnowledgeArticle $article): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => new KnowledgeArticleResource($article->load(['category', 'author', 'attachments'])),
        ]);
    }

    public function update(Request $request, KnowledgeArticle $article): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'category_id'   => 'nullable|exists:knowledge_categories,id',
            'department_id' => 'nullable|exists:departments,id',
            'title'         => 'string|max:255',
            'slug'          => 'string|unique:knowledge_articles,slug,' . $article->id,
            'excerpt'       => 'nullable|string',
            'content'       => 'string',
            'type'          => 'in:policy,sop,guide,template,report,lesson_learned,training_material,internal_note',
            'visibility'    => 'in:public,internal,department,role_based',
            'status'        => 'in:draft,published,archived',
            'tags'          => 'nullable|array',
            'published_at'  => 'nullable|date',
        ]);

        if (isset($data['status']) && $data['status'] === 'published' && ! $article->published_at) {
            $data['published_at'] = now();
        }

        $article->update($data);

        return response()->json([
            'success' => true,
            'data'    => new KnowledgeArticleResource($article->load(['category', 'author'])),
        ]);
    }

    public function destroy(KnowledgeArticle $article): \Illuminate\Http\JsonResponse
    {
        $article->delete();

        return response()->json(['success' => true, 'message' => 'Article deleted.']);
    }
}
