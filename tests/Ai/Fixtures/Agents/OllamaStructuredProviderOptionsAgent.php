<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Attributes\Strict;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasProviderOptions;
use Hypervel\Ai\Contracts\HasStructuredOutput;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Promptable;
use Hypervel\Contracts\JsonSchema\JsonSchema;

#[Strict]
class OllamaStructuredProviderOptionsAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant that uses structured output and knows about periodic table element symbols.';
    }

    /**
     * Get the structured output's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'symbol' => $schema->string()->required(),
        ];
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
            ],
            default => [],
        };
    }
}
