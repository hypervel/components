<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\CanActAsTool;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Promptable;

class MiddleManagerAgent implements Agent, CanActAsTool, HasTools
{
    use Promptable;

    /**
     * Get the tool name.
     */
    public function name(): string
    {
        return 'middle_manager';
    }

    /**
     * Get the tool description.
     */
    public function description(): string
    {
        return 'Delegate specialized research tasks to the research agent.';
    }

    /**
     * Get the agent's instructions.
     */
    public function instructions(): string
    {
        return 'You are a middle manager that breaks down tasks and delegates to the research_agent sub-agent.';
    }

    /**
     * Get the agent's tools.
     */
    public function tools(): iterable
    {
        return [
            new ResearchAgent,
        ];
    }
}
