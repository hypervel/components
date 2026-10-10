<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\AttributeAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\IncompatibleMethodsAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\MethodOptionsAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\MethodOverridesAttributeAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\NullableMethodOptionsAgent;
use Hypervel\Tests\Ai\TestCase;

class AgentAttributeTest extends TestCase
{
    public function testTextGenerationOptionsCanBeCreatedFromAgentAttributes(): void
    {
        $options = TextGenerationOptions::forAgent(new AttributeAgent);

        $this->assertSame(10, $options->maxSteps);
        $this->assertSame(4096, $options->maxTokens);
        $this->assertSame(0.7, $options->temperature);
        $this->assertSame(0.8, $options->topP);
    }

    public function testTextGenerationOptionsAreNullWhenAgentHasNoAttributes(): void
    {
        $options = TextGenerationOptions::forAgent(new AssistantAgent);

        $this->assertNull($options->maxSteps);
        $this->assertNull($options->maxTokens);
        $this->assertNull($options->temperature);
        $this->assertNull($options->topP);
    }

    public function testTextGenerationOptionsCanBeResolvedFromAgentMethods(): void
    {
        $options = TextGenerationOptions::forAgent(new MethodOptionsAgent);

        $this->assertSame(3, $options->maxSteps);
        $this->assertSame(2048, $options->maxTokens);
        $this->assertSame(0.5, $options->temperature);
    }

    public function testAgentMethodsTakePriorityOverAttributes(): void
    {
        $options = TextGenerationOptions::forAgent(new MethodOverridesAttributeAgent);

        $this->assertSame(1, $options->maxSteps);
        $this->assertSame(512, $options->maxTokens);
        $this->assertSame(0.2, $options->temperature);
    }

    public function testNullReturnFromAgentMethodFallsBackToAttributes(): void
    {
        $options = TextGenerationOptions::forAgent(new NullableMethodOptionsAgent);

        $this->assertSame(10, $options->maxSteps);
        $this->assertSame(4096, $options->maxTokens);
        $this->assertSame(0.7, $options->temperature);
    }

    public function testNonPublicOrParameterizedOptionMethodsAreIgnored(): void
    {
        $options = TextGenerationOptions::forAgent(new IncompatibleMethodsAgent);

        $this->assertSame(8, $options->maxSteps);
        $this->assertSame(1024, $options->maxTokens);
        $this->assertSame(0.9, $options->temperature);
    }

    public function testProviderAttributeIsUsedWhenPrompting(): void
    {
        AttributeAgent::fake();

        (new AttributeAgent)->prompt('Hello');

        AttributeAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->provider->name() === Lab::Anthropic->value);
    }
}
