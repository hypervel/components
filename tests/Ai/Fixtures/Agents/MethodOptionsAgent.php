<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Promptable;

class MethodOptionsAgent implements Agent
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
     * Get the maximum number of steps.
     */
    public function maxSteps(): int
    {
        return 3;
    }

    /**
     * Get the maximum number of tokens.
     */
    public function maxTokens(): int
    {
        return 2048;
    }

    /**
     * Get the sampling temperature.
     */
    public function temperature(): float
    {
        return 0.5;
    }
}
