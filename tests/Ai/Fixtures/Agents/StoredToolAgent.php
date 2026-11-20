<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasProviderOptions;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Promptable;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;

class StoredToolAgent implements Agent, HasProviderOptions, HasTools
{
    use Promptable;

    /**
     * Create a new agent instance.
     */
    public function __construct(private array $options = [])
    {
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
        return [new FixedNumberGenerator];
    }

    /**
     * Get the maximum number of steps.
     */
    public function maxSteps(): int
    {
        return 5;
    }

    /**
     * Get the provider options.
     */
    public function providerOptions(Lab|string $provider): array
    {
        return ['store' => true, ...$this->options];
    }
}
