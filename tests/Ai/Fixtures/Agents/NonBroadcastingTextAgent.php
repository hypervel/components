<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Attributes\WithoutBroadcasting;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\Streaming\Events\TextDelta;

#[WithoutBroadcasting(TextDelta::class)]
class NonBroadcastingTextAgent implements Agent
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
