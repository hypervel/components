<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasProviderOptions;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Promptable;

class OllamaTopLevelOptionsAgent implements Agent, HasProviderOptions
{
    use Promptable;

    /**
     * Get the agent instructions.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    /**
     * Get the provider options.
     */
    public function providerOptions(Lab|string $provider): array
    {
        $provider = is_string($provider) ? Lab::tryFrom($provider) : $provider;

        return match ($provider) {
            Lab::Ollama => [
                'format' => 'json',
                'keep_alive' => '10m',
                'logprobs' => true,
                'num_ctx' => 8192,
            ],
            default => [],
        };
    }
}
