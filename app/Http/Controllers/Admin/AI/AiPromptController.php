<?php

namespace App\Http\Controllers\Admin\AI;

use App\Http\Controllers\Controller;
use App\Models\AiPrompt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiPromptController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $prompts = AiPrompt::query()
            ->when($request->category, fn ($q) => $q->where('category', $request->category))
            ->when($request->status, fn ($q) => $q->where('status', $request->input('status')))
            ->orderBy('category')
            ->orderBy('key')
            ->paginate(30);

        return response()->json(['success' => true, 'data' => $prompts->items(), 'meta' => ['total' => $prompts->total()]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key'           => 'required|string|max:100|unique:ai_prompts,key',
            'name'          => 'required|string|max:255',
            'description'   => 'nullable|string',
            'system_prompt' => 'required|string',
            'variables'     => 'nullable|array',
            'category'      => 'required|in:student,trainer,operations,reports,marketing,quality,support',
            'version'       => 'sometimes|integer|min:1',
            'status'        => 'sometimes|in:active,draft,deprecated',
        ]);

        $prompt = AiPrompt::create($data);

        return response()->json(['success' => true, 'data' => $prompt], 201);
    }

    public function show(AiPrompt $aiPrompt): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $aiPrompt]);
    }

    public function update(Request $request, AiPrompt $aiPrompt): JsonResponse
    {
        $data = $request->validate([
            'name'          => 'sometimes|string|max:255',
            'description'   => 'nullable|string',
            'system_prompt' => 'sometimes|string',
            'variables'     => 'nullable|array',
            'category'      => 'sometimes|in:student,trainer,operations,reports,marketing,quality,support',
            'version'       => 'sometimes|integer|min:1',
            'status'        => 'sometimes|in:active,draft,deprecated',
        ]);

        $aiPrompt->update($data);

        return response()->json(['success' => true, 'data' => $aiPrompt]);
    }

    public function destroy(AiPrompt $aiPrompt): JsonResponse
    {
        $aiPrompt->delete();

        return response()->json(['success' => true, 'message' => 'Prompt deleted.']);
    }
}
