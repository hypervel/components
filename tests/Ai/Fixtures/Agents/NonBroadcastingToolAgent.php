<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Attributes\WithoutBroadcasting;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\Streaming\Events\ToolCall;
use Hypervel\Ai\Streaming\Events\ToolResult;

#[WithoutBroadcasting(ToolResult::class, ToolCall::class)]
class NonBroadcastingToolAgent implements Agent
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
