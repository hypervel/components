<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasProviderOptions;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Promptable;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;

class ProviderOptionsWithToolsAgent implements Agent, HasProviderOptions, HasTools
{
    use Promptable;

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
        return [
            new FixedNumberGenerator,
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
            ],
            Lab::Azure => [
                'frequency_penalty' => 0.5,
            ],
            Lab::Cohere => [
                'k' => 40,
            ],
            Lab::OpenAI => [
                'reasoning' => [
                    'effort' => 'high',
                ],
                'frequency_penalty' => 0.5,
            ],
            Lab::xAI => [
                'frequency_penalty' => 0.5,
            ],
            Lab::Groq => [
                'frequency_penalty' => 0.5,
            ],
            Lab::Mistral => [
                'frequency_penalty' => 0.5,
            ],
            Lab::Ollama => [
                'top_k' => 40,
            ],
            Lab::OpenRouter => [
                'frequency_penalty' => 0.5,
            ],
            Lab::Gemini => [
                'thinking_level' => 'high',
            ],
            Lab::DeepSeek => [
                'frequency_penalty' => 0.5,
            ],
            default => [],
        };
    }
}
