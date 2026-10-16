<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasProviderOptions;
use Hypervel\Ai\Contracts\HasStructuredOutput;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Promptable;
use Hypervel\Contracts\JsonSchema\JsonSchema;

class StructuredWithThinkingAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    /**
     * Get the agent's instructions.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant that uses structured output.';
    }

    /**
     * Get the response schema.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required(),
            'age' => $schema->integer()->required(),
        ];
    }

    /**
     * Get the provider options.
     */
    public function providerOptions(Lab|string $provider): array
    {
        $provider = is_string($provider) ? Lab::tryFrom($provider) : $provider;

        return match ($provider) {
            Lab::Anthropic => [
                'thinking' => [
                    'type' => 'enabled',
                    'budget_tokens' => 10000,
                ],
                'output_config' => [
                    'effort' => 'low',
                ],
            ],
            Lab::OpenAI => [
                'reasoning' => [
                    'effort' => 'low',
                ],
                'text' => [
                    'verbosity' => 'low',
                ],
            ],
            default => [],
        };
    }
}
