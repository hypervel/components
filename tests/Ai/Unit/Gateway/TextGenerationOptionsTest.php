<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Gateway\TextGenerationOptionsTest;

use Error;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasProviderOptions;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\ToolChoice;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class TextGenerationOptionsTest extends TestCase
{
    public function testItNormalizesAStringDriverNameToALabEnumSoTheDocumentedMatchIdiomWorks(): void
    {
        $options = TextGenerationOptions::forAgent(new ProviderOptionsAgent);

        $this->assertSame(['reasoning' => ['effort' => 'medium']], $options->providerOptions('openrouter'));
    }

    public function testItAcceptsALabEnumDirectlyWithoutReNormalizing(): void
    {
        $options = TextGenerationOptions::forAgent(new ProviderOptionsAgent);

        $this->assertSame(['thinking' => ['type' => 'enabled']], $options->providerOptions(Lab::Anthropic));
    }

    public function testItPassesTheRawStringThroughWhenItDoesNotMatchAnyLabCase(): void
    {
        $agent = new class implements Agent, HasProviderOptions {
            use Promptable;

            /**
             * Get the agent instructions.
             */
            public function instructions(): string
            {
                return 'test';
            }

            /**
             * Get the provider options.
             */
            public function providerOptions(Lab|string $provider): array
            {
                return ['received' => $provider];
            }
        };

        $options = TextGenerationOptions::forAgent($agent);

        $this->assertSame(['received' => 'unknown-driver'], $options->providerOptions('unknown-driver'));
    }

    public function testItReturnsNullWhenTheAgentDoesNotImplementHasProviderOptions(): void
    {
        $options = TextGenerationOptions::forAgent(new NoProviderOptionsAgent);

        $this->assertNull($options->providerOptions('openai'));
    }

    public function testToolChoiceMethodsRequiringArgumentsFallBackToTheAttribute(): void
    {
        $options = TextGenerationOptions::forAgent(new ParameterizedToolChoiceAgent);

        $this->assertSame(ToolChoice::required, $options->toolChoice->mode);
    }

    #[DataProvider('optionMethods')]
    public function testErrorsFromEligibleMethodsPropagate(string $method): void
    {
        $failure = new Error('Option body failed.');
        $agent = new FailingOptionsAgent($method, $failure);

        try {
            TextGenerationOptions::forAgent($agent);
            $this->fail('The option method error was swallowed.');
        } catch (Error $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    /**
     * Provide the two option resolution paths.
     */
    public static function optionMethods(): array
    {
        return [['maxSteps'], ['toolChoice']];
    }

    public function testReflectionCachingDoesNotCacheRuntimeOptionValues(): void
    {
        $agent = new DynamicOptionsAgent;
        $this->assertSame(2, TextGenerationOptions::forAgent($agent)->maxSteps);

        $agent->steps = 5;
        $this->assertSame(5, TextGenerationOptions::forAgent($agent)->maxSteps);
    }
}

class ProviderOptionsAgent implements Agent, HasProviderOptions
{
    use Promptable;

    /**
     * Get the agent instructions.
     */
    public function instructions(): string
    {
        return 'test';
    }

    /**
     * Get the provider options.
     */
    public function providerOptions(Lab|string $provider): array
    {
        return match ($provider) {
            Lab::OpenRouter => ['reasoning' => ['effort' => 'medium']],
            Lab::Anthropic => ['thinking' => ['type' => 'enabled']],
            default => [],
        };
    }
}

class NoProviderOptionsAgent implements Agent
{
    use Promptable;

    /**
     * Get the agent instructions.
     */
    public function instructions(): string
    {
        return 'test';
    }
}

#[ToolChoice(ToolChoice::required)]
class ParameterizedToolChoiceAgent extends NoProviderOptionsAgent
{
    /**
     * Return a tool choice requiring an argument.
     */
    public function toolChoice(string $name): ToolChoice
    {
        return ToolChoice::tool($name);
    }
}

class FailingOptionsAgent extends NoProviderOptionsAgent
{
    /**
     * Create an agent with a failing option method.
     */
    public function __construct(private string $method, private Error $failure)
    {
    }

    /**
     * Get the maximum steps.
     */
    public function maxSteps(): ?int
    {
        if ($this->method === 'maxSteps') {
            throw $this->failure;
        }

        return null;
    }

    /**
     * Get the tool choice.
     */
    public function toolChoice(): ToolChoice
    {
        throw $this->failure;
    }
}

class DynamicOptionsAgent extends NoProviderOptionsAgent
{
    public int $steps = 2;

    /**
     * Get the current maximum steps.
     */
    public function maxSteps(): int
    {
        return $this->steps;
    }
}
