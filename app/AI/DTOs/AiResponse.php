<?php

namespace App\AI\DTOs;

/**
 * Immutable value object returned by every AI provider.
 */
final class AiResponse
{
    public function __construct(
        public readonly string $content,
        public readonly string $model,
        public readonly string $provider,
        public readonly int $tokensInput = 0,
        public readonly int $tokensOutput = 0,
        public readonly ?array $toolCall = null,    // if the model invoked a tool
        public readonly bool $success = true,
        public readonly ?string $error = null,
    ) {}

    public static function error(string $error, string $provider = 'unknown', string $model = 'unknown'): self
    {
        return new self(
            content: '',
            model: $model,
            provider: $provider,
            success: false,
            error: $error,
        );
    }

    public function totalTokens(): int
    {
        return $this->tokensInput + $this->tokensOutput;
    }

    public function estimatedCost(): float
    {
        $rateMap = config('ai.cost_per_1k_tokens', []);
        $rate    = $rateMap[$this->model] ?? 0.0;

        return ($this->totalTokens() / 1000) * $rate;
    }
}
