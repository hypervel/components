<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasProviderOptions;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Promptable;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;

class PromptCacheAgent implements Agent, HasProviderOptions, HasTools
{
    use Promptable;

    /**
     * Create an agent with optional tools and provider options.
     */
    public function __construct(
        protected bool $withTools = true,
        protected array $options = [],
    ) {
    }

    /**
     * Get the agent's instructions.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant that generates numbers.';
    }

    /**
     * Get the agent's tools.
     */
    public function tools(): iterable
    {
        return $this->withTools ? [new FixedNumberGenerator] : [];
    }

    /**
     * Get the provider options.
     */
    public function providerOptions(Lab|string $provider): array
    {
        return $this->options;
    }
}
