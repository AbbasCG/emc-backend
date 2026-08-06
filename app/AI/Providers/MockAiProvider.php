<?php

namespace App\AI\Providers;

use App\AI\Contracts\AiProviderInterface;
use App\AI\DTOs\AiRequest;
use App\AI\DTOs\AiResponse;

/**
 * Mock provider — safe for local dev, CI, and testing.
 *
 * Returns deterministic responses based on the last user message.
 * Never makes real HTTP calls.
 * Configured automatically when AI_PROVIDER=mock (default).
 */
class MockAiProvider implements AiProviderInterface
{
    public function chat(AiRequest $request): AiResponse
    {
        $last   = last($request->messages);
        $prompt = $last['content'] ?? '';

        $reply = $this->mockReply($prompt, $request->metadata['type'] ?? 'general');

        return new AiResponse(
            content: $reply,
            model: $this->defaultModel(),
            provider: $this->name(),
            tokensInput: str_word_count($prompt),
            tokensOutput: str_word_count($reply),
            success: true,
        );
    }

    public function embed(string $text): array
    {
        // Return a deterministic 4-dimensional mock vector
        $hash = crc32($text);
        return [
            sin($hash) * 0.5,
            cos($hash) * 0.5,
            sin($hash + 1) * 0.5,
            cos($hash + 1) * 0.5,
        ];
    }

    public function name(): string
    {
        return 'mock';
    }

    public function defaultModel(): string
    {
        return 'mock-model-1.0';
    }

    private function mockReply(string $prompt, string $type): string
    {
        return match ($type) {
            'course_outline'   => "## Mock Course Outline\n\n1. Introduction\n2. Core Concepts\n3. Practical Application\n4. Assessment\n\n*Configure AI_PROVIDER=openai to generate real content.*",
            'meeting_summary'  => "**Meeting Summary (Mock)**\n\nKey decisions were made regarding the agenda items. Follow-up tasks have been identified.\n\n*Configure AI_PROVIDER=openai for real summarization.*",
            'quiz_questions'   => json_encode([['question' => 'Mock question?', 'options' => ['A', 'B', 'C', 'D'], 'answer' => 'A']]),
            'insights'         => json_encode(['risk_level' => 'low', 'summary' => 'No anomalies detected in mock mode.']),
            'recommendations'  => json_encode([['title' => 'Mock recommendation', 'priority' => 'medium']]),
            default            => "I'm the EMC AI assistant. Your message was: \"{$prompt}\"\n\n*Configure AI_PROVIDER=openai in .env to activate real responses.*",
        };
    }
}
