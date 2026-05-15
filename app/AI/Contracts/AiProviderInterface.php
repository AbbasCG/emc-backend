<?php

namespace App\AI\Contracts;

use App\AI\DTOs\AiRequest;
use App\AI\DTOs\AiResponse;

interface AiProviderInterface
{
    /**
     * Send a chat completion request to the AI provider.
     */
    public function chat(AiRequest $request): AiResponse;

    /**
     * Generate a text embedding vector (for semantic search / RAG).
     *
     * @return float[]  Embedding vector, or empty array if unsupported.
     */
    public function embed(string $text): array;

    /**
     * Return the provider slug (e.g. 'openai', 'anthropic', 'mock').
     */
    public function name(): string;

    /**
     * Return the default model identifier for this provider.
     */
    public function defaultModel(): string;
}
