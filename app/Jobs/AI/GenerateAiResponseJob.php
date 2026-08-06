<?php

namespace App\Jobs\AI;

use App\AI\Services\AiConversationService;
use App\AI\Tools\CreateTaskTool;
use App\AI\Tools\GetKpiMetricsTool;
use App\AI\Tools\GetStudentProgressTool;
use App\AI\Tools\GetUpcomingSessionsTool;
use App\AI\Tools\SearchKnowledgeTool;
use App\AI\Tools\ToolRegistry;
use App\Models\AiConversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queued AI response generation for conversation messages.
 *
 * Usage:
 *   GenerateAiResponseJob::dispatch($conversation->id, $userMessage, $userId);
 */
class GenerateAiResponseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 120;

    public function __construct(
        public readonly int $conversationId,
        public readonly string $userMessage,
        public readonly int $userId,
    ) {}

    public function handle(): void
    {
        $conversation = AiConversation::query()->find($this->conversationId);
        if (! $conversation) {
            return;
        }

        $registry = $this->buildRegistry();
        $service  = new AiConversationService(app(\App\AI\AiManager::class), $registry);

        try {
            $service->sendMessage($conversation, $this->userMessage, $this->userId);
        } catch (\Throwable $e) {
            Log::error('GenerateAiResponseJob failed', ['error' => $e->getMessage()]);
            throw $e;
        }
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
