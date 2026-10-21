<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Tools;

use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Tests\Ai\Fixtures\Agents\ResearchAgent;

class AgentCallingTool implements Tool
{
    /**
     * Get the tool description.
     */
    public function description(): string
    {
        return 'Delegates to a research agent from within the tool handler.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): string
    {
        return ResearchAgent::make()->prompt('Research Hypervel')->text;
    }

    /**
     * Get the tool's input schema.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
