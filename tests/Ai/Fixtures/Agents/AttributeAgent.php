<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Attributes\MaxSteps;
use Hypervel\Ai\Attributes\MaxTokens;
use Hypervel\Ai\Attributes\Provider;
use Hypervel\Ai\Attributes\Temperature;
use Hypervel\Ai\Attributes\TopP;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Promptable;

#[MaxSteps(10)]
#[MaxTokens(4096)]
#[Temperature(0.7)]
#[TopP(0.8)]
#[Provider('anthropic')]
class AttributeAgent implements Agent
{
    use Promptable;

    /**
     * Get the agent's instructions.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }
}
