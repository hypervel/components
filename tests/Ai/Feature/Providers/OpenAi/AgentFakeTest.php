<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenAi;

use Hypervel\Tests\Ai\Fixtures\Agents\OpenAiAgent;
use Hypervel\Tests\Ai\TestCase;

class AgentFakeTest extends TestCase
{
    public function testOpenAiAgentCanBeFaked(): void
    {
        OpenAiAgent::fake(['Test response']);
        $response = (new OpenAiAgent)->prompt('Hello');

        $this->assertSame('Test response', $response->text);
    }

    public function testOpenAiAgentFakeWithClosure(): void
    {
        OpenAiAgent::fake(fn (string $prompt): string => "Echo: {$prompt}");
        $response = (new OpenAiAgent)->prompt('Hello world');

        $this->assertSame('Echo: Hello world', $response->text);
    }

    public function testOpenAiAgentFakeWithNoPredefinedResponses(): void
    {
        OpenAiAgent::fake();
        $response = (new OpenAiAgent)->prompt('Hello');

        $this->assertSame('Fake response for prompt: Hello', $response->text);
    }

    public function testOpenAiAgentFakeRecordsPrompts(): void
    {
        OpenAiAgent::fake();
        (new OpenAiAgent)->prompt('Hello');

        OpenAiAgent::assertPrompted('Hello');
        OpenAiAgent::assertNotPrompted('Goodbye');
    }

    public function testOpenAiAgentStreamCanBeFaked(): void
    {
        OpenAiAgent::fake(['Streamed response']);
        $response = (new OpenAiAgent)->stream('Hello');
        $response->each(fn (): true => true);

        $this->assertSame('Streamed response', $response->text);
    }
}
