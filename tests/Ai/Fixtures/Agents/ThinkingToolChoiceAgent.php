<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasProviderOptions;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\ToolChoice;
use Hypervel\Tests\Ai\Fixtures\Tools\RandomNumberGenerator;

#[ToolChoice(ToolChoice::required)]
class ThinkingToolChoiceAgent implements Agent, HasProviderOptions, HasTools
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
     * Get the agent's tools.
     */
    public function tools(): iterable
    {
        return [
            new RandomNumberGenerator,
        ];
    }

    /**
     * Get the provider options.
     */
    public function providerOptions(Lab|string $provider): array
    {
        return [
            'thinking' => [
                'type' => 'enabled',
                'budget_tokens' => 10_000,
            ],
        ];
    }
}
