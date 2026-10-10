<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\CanActAsTool;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Promptable;

class OrchestratorAgent implements Agent, CanActAsTool, HasTools
{
    use Promptable;

    /**
     * Get the tool name.
     */
    public function name(): string
    {
        return 'orchestrator';
    }

    /**
     * Get the tool description.
     */
    public function description(): string
    {
        return 'Orchestrate complex tasks by delegating to specialized sub-agents.';
    }

    /**
     * Get the agent's instructions.
     */
    public function instructions(): string
    {
        return 'You are an orchestrator that breaks down complex tasks and delegates to the middle_manager sub-agent.';
    }

    /**
     * Get the agent's tools.
     */
    public function tools(): iterable
    {
        return [
            new MiddleManagerAgent,
        ];
    }
}
