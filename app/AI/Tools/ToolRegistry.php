<?php

namespace App\AI\Tools;

use Illuminate\Support\Facades\Log;

/**
 * Central registry of all available AI tools.
 *
 * Usage:
 *   $registry = app(ToolRegistry::class);
 *   $toolDefs  = $registry->openAiDefinitions('student'); // for function-calling
 *   $result    = $registry->execute('get_student_progress', $args, $userId);
 */
class ToolRegistry
{
    /** @var AiToolInterface[] */
    private array $tools = [];

    public function register(AiToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function get(string $name): ?AiToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /** @return AiToolInterface[] */
    public function allForRole(string $role): array
    {
        return array_values(array_filter(
            $this->tools,
            fn (AiToolInterface $t) => $t->isAllowedForRole($role),
        ));
    }

    /**
     * Return tool definitions in OpenAI function-calling format.
     */
    public function openAiDefinitions(string $role): array
    {
        return array_map(fn (AiToolInterface $t) => [
            'type'     => 'function',
            'function' => [
                'name'        => $t->name(),
                'description' => $t->description(),
                'parameters'  => $t->parameters(),
            ],
        ], $this->allForRole($role));
    }

    public function execute(string $name, array $args, ?int $userId = null): mixed
    {
        $tool = $this->get($name);
        if (! $tool) {
            Log::warning("AI ToolRegistry: unknown tool [{$name}]");

            return ['error' => "Tool [{$name}] not found."];
        }

        try {
            return $tool->execute($args, $userId);
        } catch (\Throwable $e) {
            Log::error("AI Tool [{$name}] failed", ['error' => $e->getMessage()]);

            return ['error' => $e->getMessage()];
        }
    }
}
