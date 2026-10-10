<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Anthropic;

use Hypervel\Tests\Ai\Fixtures\Agents\AnthropicAgent;
use Hypervel\Tests\Ai\TestCase;

class AgentFakeTest extends TestCase
{
    public function testAnthropicAgentCanBeFaked(): void
    {
        AnthropicAgent::fake(['Test response']);

        $response = (new AnthropicAgent)->prompt('Hello');

        $this->assertSame('Test response', $response->text);
    }

    public function testAnthropicAgentFakeWithClosure(): void
    {
        AnthropicAgent::fake(fn (string $prompt): string => "Echo: {$prompt}");

        $response = (new AnthropicAgent)->prompt('Hello world');

        $this->assertSame('Echo: Hello world', $response->text);
    }

    public function testAnthropicAgentFakeWithNoPredefinedResponses(): void
    {
        AnthropicAgent::fake();

        $response = (new AnthropicAgent)->prompt('Hello');

        $this->assertSame('Fake response for prompt: Hello', $response->text);
    }

    public function testAnthropicAgentFakeRecordsPrompts(): void
    {
        AnthropicAgent::fake();

        (new AnthropicAgent)->prompt('Hello');

        AnthropicAgent::assertPrompted('Hello');
        AnthropicAgent::assertNotPrompted('Goodbye');
    }

    public function testAnthropicAgentStreamCanBeFaked(): void
    {
        AnthropicAgent::fake(['Streamed response']);

        $response = (new AnthropicAgent)->stream('Hello');
        $response->each(fn (): true => true);

        $this->assertSame('Streamed response', $response->text);
    }
}
