<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\ToolChoice;
use Hypervel\Tests\Ai\Fixtures\Tools\NamedTool;
use Hypervel\Tests\Ai\Fixtures\Tools\RandomNumberGenerator;

class ToolChoiceAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * Create an agent with the given tool choice.
     */
    public function __construct(
        private ToolChoice|string|array|null $toolChoice = null,
    ) {
    }

    /**
     * Get the agent instructions.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    /**
     * Get the agent tool choice.
     */
    public function toolChoice(): ToolChoice|string|array|null
    {
        return $this->toolChoice;
    }

    /**
     * Get the agent tools.
     */
    public function tools(): iterable
    {
        return [
            new RandomNumberGenerator,
            new NamedTool('custom_named_tool'),
        ];
    }
}
