<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai\Fixtures\Agents;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Promptable;

class SecondaryAssistantAgent implements Agent
{
    use Promptable;

    /**
     * Get the agent instructions.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant that responds extremely concisely to all queries.';
    }
}
