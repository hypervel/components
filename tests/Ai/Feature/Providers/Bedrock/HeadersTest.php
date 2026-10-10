<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Bedrock;

use Aws\MockHandler;
use Aws\Result;
use Hypervel\Ai\Gateway\StepContext;
use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Tests\Ai\Fixtures\BedrockHelpers;
use Hypervel\Tests\Ai\TestCase;

class HeadersTest extends TestCase
{
    use BedrockHelpers;

    /**
     * Generate one step and capture its request.
     */
    protected function generateBedrockStep(Provider $provider): MockHandler
    {
        $mock = new MockHandler([new Result([
            'output' => ['message' => ['content' => [['text' => 'Hello']]]],
            'usage' => ['inputTokens' => 7, 'outputTokens' => 5],
            'stopReason' => 'end_turn',
        ])]);

        $this->gatewayWithHandler($mock)->generateTextStep(
            $provider,
            'anthropic.claude-opus-4-7-v1:0',
            null,
            [new UserMessage('Hi')],
            [],
            null,
            new TextGenerationOptions,
            null,
            new StepContext,
        );

        return $mock;
    }

    public function testCustomHeadersAreIncludedInBedrockRequests(): void
    {
        $mock = $this->generateBedrockStep($this->bedrockProvider()->withHeaders(['X-Custom-Header' => 'bedrock-value']));

        $this->assertSame('bedrock-value', $mock->getLastRequest()->getHeaderLine('X-Custom-Header'));
    }

    public function testBedrockRequestsDoNotIncludeCustomHeadersWhenNoneAreSet(): void
    {
        $mock = $this->generateBedrockStep($this->bedrockProvider());

        $this->assertFalse($mock->getLastRequest()->hasHeader('X-Custom-Header'));
    }
}
