<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Gemini;

use Hypervel\Tests\Ai\Fixtures\Agents\GeminiAgent;
use Hypervel\Tests\Ai\TestCase;

class AgentFakeTest extends TestCase
{
    public function testGeminiAgentCanBeFaked(): void
    {
        GeminiAgent::fake(['Test response']);

        $response = (new GeminiAgent)->prompt('Hello');

        $this->assertSame('Test response', $response->text);
    }

    public function testGeminiAgentFakeWithClosure(): void
    {
        GeminiAgent::fake(fn (string $prompt): string => "Echo: {$prompt}");

        $response = (new GeminiAgent)->prompt('Hello world');

        $this->assertSame('Echo: Hello world', $response->text);
    }

    public function testGeminiAgentFakeWithNoPredefinedResponses(): void
    {
        GeminiAgent::fake();

        $response = (new GeminiAgent)->prompt('Hello');

        $this->assertSame('Fake response for prompt: Hello', $response->text);
    }

    public function testGeminiAgentFakeRecordsPrompts(): void
    {
        GeminiAgent::fake();

        (new GeminiAgent)->prompt('Hello');

        GeminiAgent::assertPrompted('Hello');
        GeminiAgent::assertNotPrompted('Goodbye');
    }

    public function testGeminiAgentStreamCanBeFaked(): void
    {
        GeminiAgent::fake(['Streamed response']);

        $response = (new GeminiAgent)->stream('Hello');
        $response->each(fn (): true => true);

        $this->assertSame('Streamed response', $response->text);
    }
}
