<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai\Fixtures\Agents;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\Providers\Tools\ToolSearch;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\Fixtures\Tools\SecretCodeGenerator;

class ToolSearchAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * Get the agent's instructions.
     */
    public function instructions(): string
    {
        return 'You are an assistant with access to tools. Some tools are not loaded '
            . 'upfront and must be discovered using your tool search capability before '
            . 'they can be called. Always use the appropriate tool to answer; never guess. '
            . 'When asked for the secret authorization code, find and call the tool that returns it.';
    }

    /**
     * Get the tools available to the agent.
     */
    public function tools(): iterable
    {
        return [
            new FixedNumberGenerator,
            new ToolSearch(tools: [new SecretCodeGenerator]),
        ];
    }
}
