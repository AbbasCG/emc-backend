<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\KnowledgeCategoryResource;
use App\Models\KnowledgeCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class KnowledgeCategoryController extends Controller
{
    public function index(): \Illuminate\Http\JsonResponse
    {
        $categories = KnowledgeCategory::with(['department', 'children'])
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->get();

        return response()->json(['success' => true, 'data' => KnowledgeCategoryResource::collection($categories)]);
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'parent_id'     => 'nullable|exists:knowledge_categories,id',
            'department_id' => 'nullable|exists:departments,id',
            'name'          => 'required|string|max:255',
            'slug'          => 'nullable|string|unique:knowledge_categories,slug',
            'description'   => 'nullable|string',
            'sort_order'    => 'integer',
            'status'        => 'in:active,inactive',
        ]);

        $data['slug'] = $data['slug'] ?? Str::slug($data['name']);
        $cat          = KnowledgeCategory::create($data);

        return response()->json(['success' => true, 'data' => new KnowledgeCategoryResource($cat)], 201);
    }

    public function update(Request $request, KnowledgeCategory $knowledgeCategory): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'parent_id'     => 'nullable|exists:knowledge_categories,id',
            'department_id' => 'nullable|exists:departments,id',
            'name'          => 'string|max:255',
            'slug'          => 'string|unique:knowledge_categories,slug,' . $knowledgeCategory->id,
            'description'   => 'nullable|string',
            'sort_order'    => 'integer',
            'status'        => 'in:active,inactive',
        ]);

        $knowledgeCategory->update($data);

        return response()->json(['success' => true, 'data' => new KnowledgeCategoryResource($knowledgeCategory)]);
    }

    public function destroy(KnowledgeCategory $knowledgeCategory): \Illuminate\Http\JsonResponse
    {
        $knowledgeCategory->delete();

        return response()->json(['success' => true, 'message' => 'Category deleted.']);
    }
}
