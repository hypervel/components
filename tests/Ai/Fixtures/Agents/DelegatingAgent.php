<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Promptable;

class DelegatingAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * Get the agent's instructions.
     */
    public function instructions(): string
    {
        return 'You are a project manager that delegates research tasks to your research_agent sub-agent.';
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
