<?php

namespace App\AI\DTOs;

/**
 * Immutable value object representing a request to the AI provider.
 */
final class AiRequest
{
    public function __construct(
        public readonly array $messages,          // [['role' => 'user', 'content' => '...']]
        public readonly ?string $systemPrompt = null,
        public readonly ?string $model = null,
        public readonly int $maxTokens = 1024,
        public readonly float $temperature = 0.7,
        public readonly array $tools = [],        // OpenAI function definitions
        public readonly array $metadata = [],     // passed through for logging
    ) {}

    public static function fromConversation(
        array $messages,
        string $systemPrompt,
        array $options = [],
    ): self {
        return new self(
            messages: $messages,
            systemPrompt: $systemPrompt,
            model: $options['model'] ?? null,
            maxTokens: $options['max_tokens'] ?? 1024,
            temperature: $options['temperature'] ?? 0.7,
            tools: $options['tools'] ?? [],
            metadata: $options['metadata'] ?? [],
        );
    }
}
