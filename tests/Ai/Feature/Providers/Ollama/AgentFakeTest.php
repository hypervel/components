<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Ollama;

use Hypervel\Tests\Ai\Fixtures\Agents\OllamaAgent;
use Hypervel\Tests\Ai\TestCase;

class AgentFakeTest extends TestCase
{
    public function testOllamaAgentCanBeFaked(): void
    {
        OllamaAgent::fake(['Test response']);

        $response = (new OllamaAgent)->prompt('Hello');

        $this->assertSame('Test response', $response->text);
    }

    public function testOllamaAgentFakeWithClosure(): void
    {
        OllamaAgent::fake(fn (string $prompt): string => "Echo: {$prompt}");

        $response = (new OllamaAgent)->prompt('Hello world');

        $this->assertSame('Echo: Hello world', $response->text);
    }

    public function testOllamaAgentFakeWithNoPredefinedResponses(): void
    {
        OllamaAgent::fake();

        $response = (new OllamaAgent)->prompt('Hello');

        $this->assertSame('Fake response for prompt: Hello', $response->text);
    }

    public function testOllamaAgentFakeRecordsPrompts(): void
    {
        OllamaAgent::fake();

        (new OllamaAgent)->prompt('Hello');

        OllamaAgent::assertPrompted('Hello');
        OllamaAgent::assertNotPrompted('Goodbye');
    }

    public function testOllamaAgentStreamCanBeFaked(): void
    {
        OllamaAgent::fake(['Streamed response']);

        $response = (new OllamaAgent)->stream('Hello');
        $response->each(fn (): true => true);

        $this->assertSame('Streamed response', $response->text);
    }
}
