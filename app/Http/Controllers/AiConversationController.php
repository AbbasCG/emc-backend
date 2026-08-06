<?php

namespace App\Http\Controllers;

use App\AI\Services\AiConversationService;
use App\AI\Tools\CreateTaskTool;
use App\AI\Tools\GetKpiMetricsTool;
use App\AI\Tools\GetStudentProgressTool;
use App\AI\Tools\GetUpcomingSessionsTool;
use App\AI\Tools\SearchKnowledgeTool;
use App\AI\Tools\ToolRegistry;
use App\Jobs\AI\GenerateAiResponseJob;
use App\Models\AiConversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiConversationController extends Controller
{
    public const VALID_TYPES = [
        'student_assistant',
        'trainer_assistant',
        'admin_assistant',
        'operations_assistant',
        'report_assistant',
        'support_assistant',
    ];

    public function index(Request $request): JsonResponse
    {
        $conversations = AiConversation::query()
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->withCount('messages')
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $conversations->items(),
            'meta'    => [
                'current_page' => $conversations->currentPage(),
                'last_page'    => $conversations->lastPage(),
                'total'        => $conversations->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'        => 'nullable|string|max:255',
            'type'         => 'sometimes|string|in:' . implode(',', self::VALID_TYPES),
            'context_type' => 'nullable|string|max:100',
            'context_id'   => 'nullable|integer',
        ]);

        $conversation = AiConversation::create([
            'user_id'      => $request->user()->id,
            'title'        => $data['title'] ?? 'New conversation',
            'type'         => $data['type'] ?? $this->defaultTypeForRole($request->user()->role),
            'visibility'   => 'private',
            'context_type' => $data['context_type'] ?? null,
            'context_id'   => $data['context_id'] ?? null,
            'status'       => 'active',
        ]);

        return response()->json([
            'success' => true,
            'data'    => ['id' => $conversation->id, 'title' => $conversation->title, 'type' => $conversation->type],
        ], 201);
    }

    public function show(Request $request, AiConversation $conversation): JsonResponse
    {
        abort_if($conversation->user_id !== $request->user()->id && ! $request->user()->isAdmin(), 403);

        return response()->json([
            'success' => true,
            'data'    => [
                'id'       => $conversation->id,
                'title'    => $conversation->title,
                'type'     => $conversation->type,
                'messages' => $conversation->messages->map(fn ($m) => [
                    'id'          => $m->id,
                    'role'        => $m->role,
                    'content'     => $m->content,
                    'token_usage' => $m->token_usage,
                    'model'       => $m->model,
                    'created_at'  => $m->created_at,
                ]),
            ],
        ]);
    }

    /**
     * POST /api/ai/conversations/{id}/messages
     *
     * By default: sync response (waits for AI reply).
     * Pass `?async=true` to queue and return immediately.
     */
    public function sendMessage(Request $request, AiConversation $conversation): JsonResponse
    {
        abort_if($conversation->user_id !== $request->user()->id, 403);

        $data = $request->validate(['content' => 'required|string|max:4000']);

        if ($request->boolean('async')) {
            GenerateAiResponseJob::dispatch($conversation->id, $data['content'], $request->user()->id)
                ->onQueue(config('ai.queue', 'default'));

            return response()->json([
                'success' => true,
                'message' => 'Message queued. Response will appear in conversation shortly.',
            ], 202);
        }

        $registry = $this->buildRegistry();
        $service  = new AiConversationService(app(\App\AI\AiManager::class), $registry);
        $reply    = $service->sendMessage($conversation, $data['content'], $request->user()->id);

        return response()->json([
            'success' => true,
            'data'    => [
                'role'        => 'assistant',
                'content'     => $reply->content,
                'token_usage' => $reply->token_usage,
                'model'       => $reply->model,
                'created_at'  => $reply->created_at,
            ],
        ]);
    }

    public function destroy(Request $request, AiConversation $conversation): JsonResponse
    {
        abort_if($conversation->user_id !== $request->user()->id && ! $request->user()->isAdmin(), 403);

        $conversation->update(['status' => 'archived']);

        return response()->json(['success' => true, 'message' => 'Conversation archived.']);
    }

    // -----------------------------------------------------------------------

    private function defaultTypeForRole(string $role): string
    {
        return match ($role) {
            'teacher'            => 'trainer_assistant',
            'admin', 'super_admin' => 'admin_assistant',
            'department_manager' => 'operations_assistant',
            'finance_manager'    => 'report_assistant',
            default              => 'student_assistant',
        };
    }

    private function buildRegistry(): ToolRegistry
    {
        $registry = new ToolRegistry();
        $registry->register(new GetStudentProgressTool());
        $registry->register(new SearchKnowledgeTool());
        $registry->register(new GetKpiMetricsTool());
        $registry->register(new CreateTaskTool());
        $registry->register(new GetUpcomingSessionsTool());

        return $registry;
    }
}
