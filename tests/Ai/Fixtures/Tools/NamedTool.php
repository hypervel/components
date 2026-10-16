<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Tools;

use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;

class NamedTool implements Tool
{
    /**
     * Create a tool with the given name.
     */
    public function __construct(public readonly string $toolName = 'custom_named_tool')
    {
    }

    /**
     * Get the tool name.
     */
    public function name(): string
    {
        return $this->toolName;
    }

    /**
     * Get the tool description.
     */
    public function description(): string
    {
        return 'A tool that declares its own name.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        return 'ok';
    }

    /**
     * Get the tool schema.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
