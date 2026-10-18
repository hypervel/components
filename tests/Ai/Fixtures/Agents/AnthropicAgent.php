<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Attributes\Provider;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Promptable;

#[Provider('anthropic')]
class AnthropicAgent implements Agent
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
