<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Attributes\MaxSteps;
use Hypervel\Ai\Attributes\MaxTokens;
use Hypervel\Ai\Attributes\Temperature;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Promptable;

#[MaxSteps(10)]
#[MaxTokens(4096)]
#[Temperature(0.7)]
class NullableMethodOptionsAgent implements Agent
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
    public function maxSteps(): ?int
    {
        return null;
    }

    /**
     * Get the maximum number of tokens.
     */
    public function maxTokens(): ?int
    {
        return null;
    }

    /**
     * Get the sampling temperature.
     */
    public function temperature(): ?float
    {
        return null;
    }
}
