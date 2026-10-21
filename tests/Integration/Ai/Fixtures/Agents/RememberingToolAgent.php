<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai\Fixtures\Agents;

use Hypervel\Ai\Concerns\RemembersConversations;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasProviderOptions;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Promptable;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;

class RememberingToolAgent implements Agent, HasProviderOptions, HasTools
{
    use Promptable;
    use RemembersConversations;

    /**
     * Create the agent with provider options.
     */
    public function __construct(public array $extraProviderOptions = [])
    {
    }

    /**
     * Get the agent instructions.
     */
    public function instructions(): string
    {
        return 'Call the FixedNumberGenerator tool before answering. Answer with one short sentence.';
    }

    /**
     * Get the available tools.
     */
    public function tools(): iterable
    {
        return [new FixedNumberGenerator];
    }

    /**
     * Get the provider options.
     */
    public function providerOptions(Lab|string $provider): array
    {
        return $this->extraProviderOptions;
    }
}
