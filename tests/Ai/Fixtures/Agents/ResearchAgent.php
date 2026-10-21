<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\CanActAsTool;
use Hypervel\Ai\Promptable;

class ResearchAgent implements Agent, CanActAsTool
{
    use Promptable;

    /**
     * Get the tool name.
     */
    public function name(): string
    {
        return 'research_agent';
    }

    /**
     * Get the tool description.
     */
    public function description(): string
    {
        return 'Research a topic in depth and return a summary.';
    }

    /**
     * Get the agent's instructions.
     */
    public function instructions(): string
    {
        return 'You are a research agent. Summarize your findings concisely.';
    }
}
