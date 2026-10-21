<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasProviderOptions;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Promptable;

class ProviderOptionsAgent implements Agent, HasProviderOptions
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
     * Get the provider options.
     */
    public function providerOptions(Lab|string $provider): array
    {
        $provider = is_string($provider) ? Lab::tryFrom($provider) : $provider;

        return match ($provider) {
            Lab::OpenAI => [
                'reasoning' => [
                    'effort' => 'high',
                ],
                'frequency_penalty' => 0.5,
                'presence_penalty' => 0.3,
            ],
            Lab::Anthropic => [
                'thinking' => [
                    'type' => 'enabled',
                    'budget_tokens' => 10000,
                ],
            ],
            Lab::Azure => [
                'frequency_penalty' => 0.5,
                'presence_penalty' => 0.3,
            ],
            Lab::Cohere => [
                'k' => 40,
                'safety_mode' => 'CONTEXTUAL',
            ],
            Lab::xAI => [
                'frequency_penalty' => 0.5,
                'presence_penalty' => 0.3,
            ],
            Lab::Groq => [
                'frequency_penalty' => 0.5,
                'presence_penalty' => 0.3,
            ],
            Lab::Mistral => [
                'frequency_penalty' => 0.5,
                'presence_penalty' => 0.3,
            ],
            Lab::Ollama => [
                'top_k' => 40,
                'repeat_penalty' => 1.1,
            ],
            Lab::OpenRouter => [
                'frequency_penalty' => 0.5,
                'presence_penalty' => 0.3,
            ],
            Lab::Gemini => [
                'thinking_level' => 'high',
            ],
            Lab::DeepSeek => [
                'frequency_penalty' => 0.5,
                'presence_penalty' => 0.3,
            ],
            Lab::Bedrock => [
                'additionalModelRequestFields' => [
                    'thinking' => [
                        'type' => 'adaptive',
                    ],
                    'output_config' => [
                        'effort' => 'high',
                    ],
                ],
                'guardrailConfig' => [
                    'guardrailIdentifier' => 'gr-1',
                    'guardrailVersion' => '1',
                ],
            ],
            default => [],
        };
    }
}
