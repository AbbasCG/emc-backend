<?php

namespace App\AI\Providers;

use App\AI\Contracts\AiProviderInterface;
use App\AI\DTOs\AiRequest;
use App\AI\DTOs\AiResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI provider (Chat Completions + Embeddings).
 *
 * Required env:
 *   AI_PROVIDER=openai
 *   OPENAI_API_KEY=sk-...
 *   OPENAI_MODEL=gpt-4.1-mini
 */
class OpenAiProvider implements AiProviderInterface
{
    private string $apiKey;

    private string $baseUrl;

    private int $timeout;

    public function __construct()
    {
        $this->apiKey  = config('ai.openai.api_key', '');
        $this->baseUrl = config('ai.openai.base_url', 'https://api.openai.com/v1');
        $this->timeout = config('ai.openai.timeout', 60);
    }

    public function chat(AiRequest $request): AiResponse
    {
        $model = $request->model ?? $this->defaultModel();

        $messages = [];
        if ($request->systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $request->systemPrompt];
        }
        $messages = array_merge($messages, $request->messages);

        $body = array_filter([
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => $request->maxTokens,
            'temperature' => $request->temperature,
            'tools'       => $request->tools ?: null,
        ]);

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout($this->timeout)
                ->post("{$this->baseUrl}/chat/completions", $body);

            if ($response->failed()) {
                $error = $response->json('error.message') ?? 'OpenAI API error';
                Log::error('[AI:openai] chat failed', ['status' => $response->status(), 'error' => $error]);

                return AiResponse::error($error, $this->name(), $model);
            }

            $data    = $response->json();
            $choice  = $data['choices'][0] ?? [];
            $message = $choice['message'] ?? [];
            $usage   = $data['usage'] ?? [];

            $toolCall = null;
            if (! empty($message['tool_calls'])) {
                $tc       = $message['tool_calls'][0];
                $toolCall = [
                    'name'      => $tc['function']['name'] ?? null,
                    'arguments' => json_decode($tc['function']['arguments'] ?? '{}', true),
                ];
            }

            return new AiResponse(
                content: $message['content'] ?? '',
                model: $data['model'] ?? $model,
                provider: $this->name(),
                tokensInput: $usage['prompt_tokens'] ?? 0,
                tokensOutput: $usage['completion_tokens'] ?? 0,
                toolCall: $toolCall,
                success: true,
            );
        } catch (\Throwable $e) {
            Log::error('[AI:openai] exception', ['error' => $e->getMessage()]);

            return AiResponse::error($e->getMessage(), $this->name(), $model);
        }
    }

    public function embed(string $text): array
    {
        $model = config('ai.openai.embedding_model', 'text-embedding-3-small');

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(30)
                ->post("{$this->baseUrl}/embeddings", [
                    'model' => $model,
                    'input' => $text,
                ]);

            if ($response->failed()) {
                Log::error('[AI:openai] embed failed', ['status' => $response->status()]);

                return [];
            }

            return $response->json('data.0.embedding') ?? [];
        } catch (\Throwable $e) {
            Log::error('[AI:openai] embed exception', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function name(): string
    {
        return 'openai';
    }

    public function defaultModel(): string
    {
        return config('ai.openai.model', 'gpt-4.1-mini');
    }
}
