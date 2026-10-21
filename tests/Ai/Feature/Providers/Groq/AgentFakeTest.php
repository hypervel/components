<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Groq;

use Hypervel\Tests\Ai\Fixtures\Agents\GroqAgent;
use Hypervel\Tests\Ai\TestCase;

class AgentFakeTest extends TestCase
{
    public function testGroqAgentCanBeFaked(): void
    {
        GroqAgent::fake(['Test response']);

        $response = (new GroqAgent)->prompt('Hello');

        $this->assertSame('Test response', $response->text);
    }

    public function testGroqAgentFakeWithClosure(): void
    {
        GroqAgent::fake(fn (string $prompt): string => "Echo: {$prompt}");

        $response = (new GroqAgent)->prompt('Hello world');

        $this->assertSame('Echo: Hello world', $response->text);
    }

    public function testGroqAgentFakeWithNoPredefinedResponses(): void
    {
        GroqAgent::fake();

        $response = (new GroqAgent)->prompt('Hello');

        $this->assertSame('Fake response for prompt: Hello', $response->text);
    }

    public function testGroqAgentFakeRecordsPrompts(): void
    {
        GroqAgent::fake();

        (new GroqAgent)->prompt('Hello');

        GroqAgent::assertPrompted('Hello');
        GroqAgent::assertNotPrompted('Goodbye');
    }

    public function testGroqAgentStreamCanBeFaked(): void
    {
        GroqAgent::fake(['Streamed response']);

        $response = (new GroqAgent)->stream('Hello');
        $response->each(fn (): true => true);

        $this->assertSame('Streamed response', $response->text);
    }
}
