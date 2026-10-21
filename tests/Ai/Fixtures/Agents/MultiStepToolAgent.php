<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Attributes\MaxSteps;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Promptable;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;

#[MaxSteps(10)]
class MultiStepToolAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant that generates numbers.';
    }

    /**
     * Get the tools available to the agent.
     */
    public function tools(): iterable
    {
        return [new FixedNumberGenerator];
    }
}
