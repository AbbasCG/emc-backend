<?php

namespace App\AI\Services;

use App\AI\AiManager;
use App\AI\DTOs\AiRequest;
use App\AI\Tools\ToolRegistry;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiPrompt;

/**
 * Manages AI conversation lifecycle: context building, message exchange, tool calls.
 */
class AiConversationService
{
    public function __construct(
        private readonly AiManager $ai,
        private readonly ToolRegistry $toolRegistry,
    ) {}

    /**
     * Send a message in a conversation and return the assistant reply.
     * Handles system prompt lookup, context window, tool execution.
     */
    public function sendMessage(AiConversation $conversation, string $userContent, int $userId): AiMessage
    {
        // Persist the user message
        AiMessage::create([
            'conversation_id' => $conversation->id,
            'role'            => 'user',
            'content'         => $userContent,
        ]);

        // Build context from history
        $history = $conversation->messages()
            ->latest()
            ->take(config('ai.context_window', 20))
            ->get()
            ->sortBy('id')
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->toArray();

        // Resolve system prompt
        $systemPrompt = AiPrompt::render($conversation->type)
            ?? $this->defaultSystemPrompt($conversation->type);

        // Build AI request (optionally attach tools)
        $user  = \App\Models\User::query()->find($userId);
        $role  = $user?->role ?? 'student';
        $tools = config('ai.tools_enabled', true)
            ? $this->toolRegistry->openAiDefinitions($role)
            : [];

        $request = AiRequest::fromConversation($history, $systemPrompt, [
            'tools'    => $tools,
            'metadata' => ['type' => $conversation->type, 'conversation_id' => $conversation->id],
        ]);

        $response = $this->ai->chat($request, $userId, 'chat');

        // Handle tool call if provider invoked a function
        $finalContent = $response->content;
        if ($response->toolCall && config('ai.tools_enabled', true)) {
            $toolResult   = $this->toolRegistry->execute(
                $response->toolCall['name'],
                $response->toolCall['arguments'],
                $userId,
            );
            $finalContent = $this->replyWithToolResult($conversation, $history, $systemPrompt, $response->toolCall, $toolResult, $userId);
        }

        // Persist assistant reply
        return AiMessage::create([
            'conversation_id' => $conversation->id,
            'role'            => 'assistant',
            'content'         => $finalContent ?: '*(no response)*',
            'token_usage'     => $response->totalTokens(),
            'model'           => $response->model,
            'metadata'        => $response->toolCall ? ['tool_call' => $response->toolCall] : null,
        ]);
    }

    // -----------------------------------------------------------------------

    private function replyWithToolResult(
        AiConversation $conv,
        array $history,
        string $systemPrompt,
        array $toolCall,
        mixed $toolResult,
        int $userId,
    ): string {
        // Send tool result back to the model to get a natural language response
        $extended   = $history;
        $extended[] = ['role' => 'assistant', 'content' => null, 'tool_calls' => [
            ['id' => 'call_1', 'type' => 'function', 'function' => [
                'name'      => $toolCall['name'],
                'arguments' => json_encode($toolCall['arguments']),
            ]],
        ]];
        $extended[] = ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => json_encode($toolResult)];

        $request  = AiRequest::fromConversation($extended, $systemPrompt);
        $response = $this->ai->chat($request, $userId, 'tool_result');

        return $response->content;
    }

    private function defaultSystemPrompt(string $type): string
    {
        return match ($type) {
            'student_assistant'     => 'You are a helpful EMC student assistant. Help students with their courses, schedules, assignments, and progress. Be concise and supportive.',
            'trainer_assistant'     => 'You are an EMC trainer assistant. Help trainers manage sessions, assignments, and student progress.',
            'admin_assistant'       => 'You are the EMC admin assistant. Help administrators manage the platform, view metrics, and perform administrative actions.',
            'operations_assistant'  => 'You are the EMC operations assistant. Help teams manage tasks, meetings, and daily operations.',
            'report_assistant'      => 'You are an EMC analytics assistant. Help generate insights and summaries from platform data.',
            'support_assistant'     => 'You are the EMC support assistant. Help resolve support tickets and answer platform questions professionally.',
            default                 => 'You are the EMC intelligent assistant. Help users with their queries about the EMC platform.',
        };
    }
}
