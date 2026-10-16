<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Attributes\MaxSteps;
use Hypervel\Ai\Attributes\MaxTokens;
use Hypervel\Ai\Attributes\Temperature;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Promptable;

#[MaxSteps(8)]
#[MaxTokens(1024)]
#[Temperature(0.9)]
class IncompatibleMethodsAgent implements Agent
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
     * Get the scaled step limit.
     */
    public function maxSteps(int $scale): int
    {
        return $scale * 2;
    }

    /**
     * Get the internal token limit.
     */
    protected function maxTokens(): int
    {
        return 256;
    }
}
