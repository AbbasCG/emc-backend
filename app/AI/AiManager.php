<?php

namespace App\AI;

use App\AI\Contracts\AiProviderInterface;
use App\AI\DTOs\AiRequest;
use App\AI\DTOs\AiResponse;
use App\AI\Providers\MockAiProvider;
use App\AI\Providers\OpenAiProvider;
use App\Models\AiUsageLog;

/**
 * Central AI gateway.
 *
 * Resolves the configured provider, proxies requests,
 * and writes every call to ai_usage_logs.
 *
 * Usage:
 *   $manager = app(AiManager::class);
 *   $response = $manager->chat(AiRequest::fromConversation($messages, $systemPrompt));
 *   $vector   = $manager->embed("some text");
 */
class AiManager
{
    private AiProviderInterface $provider;

    public function __construct()
    {
        $this->provider = $this->resolveProvider();
    }

    public function chat(AiRequest $request, ?int $userId = null, string $action = 'chat'): AiResponse
    {
        $response = $this->provider->chat($request);

        $this->log($response, $userId, $action);

        return $response;
    }

    public function embed(string $text): array
    {
        return $this->provider->embed($text);
    }

    public function provider(): AiProviderInterface
    {
        return $this->provider;
    }

    // -----------------------------------------------------------------------

    private function resolveProvider(): AiProviderInterface
    {
        return match (config('ai.provider', 'mock')) {
            'openai'    => new OpenAiProvider(),
            default     => new MockAiProvider(),
        };
    }

    private function log(AiResponse $response, ?int $userId, string $action): void
    {
        try {
            AiUsageLog::create([
                'user_id'        => $userId,
                'provider'       => $response->provider,
                'model'          => $response->model,
                'action'         => $action,
                'tokens_input'   => $response->tokensInput,
                'tokens_output'  => $response->tokensOutput,
                'estimated_cost' => $response->estimatedCost(),
                'status'         => $response->success ? 'success' : 'failed',
            ]);
        } catch (\Throwable) {
            // Logging must never crash the application
        }
    }
}
