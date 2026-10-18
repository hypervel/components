<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Promptable;
use Hypervel\Tests\Ai\Fixtures\Tools\NamedTool;

class NamedToolAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * Set the tool's declared name.
     */
    public function __construct(public readonly string $toolName = 'custom_named_tool')
    {
    }

    /**
     * Get the instructions for the agent.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    /**
     * Get the tools available to the agent.
     */
    public function tools(): iterable
    {
        return [
            new NamedTool($this->toolName),
        ];
    }
}
