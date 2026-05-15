<?php

namespace App\AI\Tools;

/**
 * Contract for every AI tool callable by the engine.
 */
interface AiToolInterface
{
    /** Unique name used in tool definitions (snake_case). */
    public function name(): string;

    /** Human-readable description sent to the AI provider. */
    public function description(): string;

    /**
     * JSON Schema parameters definition (OpenAI function-calling format).
     * @return array{type: 'object', properties: array, required: array}
     */
    public function parameters(): array;

    /**
     * Execute the tool, validate permissions, return result.
     *
     * @param  array   $args      Validated arguments from the AI call.
     * @param  int|null $userId   Authenticated user — for permission checks.
     * @return mixed               Result returned to the AI as tool output.
     */
    public function execute(array $args, ?int $userId = null): mixed;

    /**
     * Whether this tool is available to the given user role.
     */
    public function isAllowedForRole(string $role): bool;
}
