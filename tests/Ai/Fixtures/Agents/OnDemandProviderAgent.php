<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Ai;
use Hypervel\Ai\Providers\Provider;

class OnDemandProviderAgent extends AssistantAgent
{
    /**
     * Create an agent with its own credentials.
     */
    public function __construct(public string $key)
    {
    }

    /**
     * Build the agent's provider.
     */
    public function provider(): Provider
    {
        return Ai::build(['driver' => 'anthropic', 'key' => $this->key]);
    }
}
