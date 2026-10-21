<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ProviderOptionsAgent;
use Hypervel\Tests\Ai\TestCase;

class AgentProviderOptionsTest extends TestCase
{
    public function testTextGenerationOptionsCanExtractProviderOptionsForOpenai(): void
    {
        $options = TextGenerationOptions::forAgent(new ProviderOptionsAgent);

        $providerOptions = $options->providerOptions(Lab::OpenAI);

        $this->assertNotNull($providerOptions);
        $this->assertIsArray($providerOptions);
        $this->assertSame([
            'reasoning' => ['effort' => 'high'],
            'frequency_penalty' => 0.5,
            'presence_penalty' => 0.3,
        ], $providerOptions);
    }

    public function testTextGenerationOptionsCanExtractProviderOptionsForAnthropic(): void
    {
        $options = TextGenerationOptions::forAgent(new ProviderOptionsAgent);

        $providerOptions = $options->providerOptions(Lab::Anthropic);

        $this->assertNotNull($providerOptions);
        $this->assertSame([
            'thinking' => [
                'type' => 'enabled',
                'budget_tokens' => 10000,
            ],
        ], $providerOptions);
    }

    public function testTextGenerationOptionsAcceptStringProvider(): void
    {
        $options = TextGenerationOptions::forAgent(new ProviderOptionsAgent);

        $providerOptions = $options->providerOptions('openai');

        $this->assertNotNull($providerOptions);
        $this->assertSame([
            'reasoning' => ['effort' => 'high'],
            'frequency_penalty' => 0.5,
            'presence_penalty' => 0.3,
        ], $providerOptions);
    }

    public function testTextGenerationOptionsReturnEmptyArrayForUnknownProvider(): void
    {
        $options = TextGenerationOptions::forAgent(new ProviderOptionsAgent);

        $providerOptions = $options->providerOptions(Lab::Jina);

        $this->assertSame([], $providerOptions);
    }

    public function testTextGenerationOptionsHaveNullProviderOptionsWhenAgentDoesNotImplementInterface(): void
    {
        $options = TextGenerationOptions::forAgent(new AssistantAgent);

        $this->assertNull($options->providerOptions(Lab::OpenAI));
    }

    public function testProviderOptionsArePassedThroughWhenPrompting(): void
    {
        ProviderOptionsAgent::fake();

        (new ProviderOptionsAgent)->prompt('Hello');

        ProviderOptionsAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $options = TextGenerationOptions::forAgent($prompt->agent);

            return $options->providerOptions(Lab::OpenAI) === [
                'reasoning' => [
                    'effort' => 'high',
                ],
                'frequency_penalty' => 0.5,
                'presence_penalty' => 0.3,
            ];
        });
    }

    public function testProviderOptionsDefaultToNullWhenNotProvided(): void
    {
        AssistantAgent::fake();

        (new AssistantAgent)->prompt('Hello');

        AssistantAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $options = TextGenerationOptions::forAgent($prompt->agent);

            return $options->providerOptions(Lab::OpenAI) === null;
        });
    }
}
