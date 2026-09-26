<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\Tools\Tools\AccountCreateTool;
use App\Services\Ai\Tools\Tools\AccountsGetTool;
use App\Services\Ai\Tools\Tools\AccountsSearchTool;
use App\Services\Ai\Tools\Tools\JournalCreateTool;
use App\Services\Ai\Tools\Tools\JournalsSearchTool;
use App\Services\Ai\Tools\Tools\OverviewGetTool;
use App\Services\Ai\Tools\Tools\TagCreateTool;
use InvalidArgumentException;

/**
 * The assistant's entire capability surface.
 *
 * This is a security boundary, not a convenience registry: a tool that is not
 * listed here cannot be invoked no matter what the model emits. Tools are
 * listed explicitly rather than auto-discovered so that adding one is always a
 * conscious decision.
 */
class AiToolRegistry
{
    /**
     * @var class-string<AiTool>
     */
    private const TOOLS = [
        // Reads — run immediately so the model has facts to reason about.
        AccountsSearchTool::class,
        AccountsGetTool::class,
        JournalsSearchTool::class,
        OverviewGetTool::class,

        // Writes — proposed as drafts, never run on the model's say-so.
        JournalCreateTool::class,
        AccountCreateTool::class,
        TagCreateTool::class,
    ];

    /**
     * @var array<string, AiTool>|null
     */
    private ?array $resolved = null;

    /**
     * @return array<string, AiTool>
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $tools = [];

        foreach (self::TOOLS as $class) {
            $tool = app($class);

            if (isset($tools[$tool->name()])) {
                throw new InvalidArgumentException("Duplicate AI tool name [{$tool->name()}].");
            }

            $tools[$tool->name()] = $tool;
        }

        return $this->resolved = $tools;
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    public function get(string $name): AiTool
    {
        return $this->all()[$name] ?? throw new InvalidArgumentException("Unknown AI tool [{$name}].");
    }

    /**
     * @return array<int, AiTool>
     */
    public function ofKind(AiToolKind $kind): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (AiTool $tool): bool => $tool->kind() === $kind,
        ));
    }

    /**
     * Tool definitions in the normalized shape handed to providers.
     *
     * @return array<int, array{name: string, description: string, parameters: array<string, mixed>}>
     */
    public function definitions(): array
    {
        return array_values(array_map(
            static fn (AiTool $tool): array => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => $tool->parameters(),
            ],
            $this->all(),
        ));
    }
}
