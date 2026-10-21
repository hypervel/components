<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Attributes\Model;
use Hypervel\Ai\Attributes\Provider;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\Providers\Tools\ToolSearch;
use Hypervel\Tests\Ai\Fixtures\Tools\DeferredTool;
use Hypervel\Tests\Ai\Fixtures\Tools\NonStrictTool;

#[Provider('anthropic')]
#[Model('claude-opus-4-8')]
class AnthropicToolSearchAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * Get the agent's instructions.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    /**
     * Get the agent's tools.
     */
    public function tools(): iterable
    {
        return [new NonStrictTool, new ToolSearch(tools: [new DeferredTool])];
    }
}
