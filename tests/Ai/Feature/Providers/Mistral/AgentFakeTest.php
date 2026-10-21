<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Mistral;

use Hypervel\Tests\Ai\Fixtures\Agents\MistralAgent;
use Hypervel\Tests\Ai\TestCase;

class AgentFakeTest extends TestCase
{
    public function testMistralAgentCanBeFaked(): void
    {
        MistralAgent::fake(['Test response']);

        $response = (new MistralAgent)->prompt('Hello');

        $this->assertSame('Test response', $response->text);
    }

    public function testMistralAgentFakeWithClosure(): void
    {
        MistralAgent::fake(fn (string $prompt): string => "Echo: {$prompt}");

        $response = (new MistralAgent)->prompt('Hello world');

        $this->assertSame('Echo: Hello world', $response->text);
    }

    public function testMistralAgentFakeWithNoPredefinedResponses(): void
    {
        MistralAgent::fake();

        $response = (new MistralAgent)->prompt('Hello');

        $this->assertSame('Fake response for prompt: Hello', $response->text);
    }

    public function testMistralAgentFakeRecordsPrompts(): void
    {
        MistralAgent::fake();

        (new MistralAgent)->prompt('Hello');

        MistralAgent::assertPrompted('Hello');
        MistralAgent::assertNotPrompted('Goodbye');
    }

    public function testMistralAgentStreamCanBeFaked(): void
    {
        MistralAgent::fake(['Streamed response']);

        $response = (new MistralAgent)->stream('Hello');
        $response->each(fn (): true => true);

        $this->assertSame('Streamed response', $response->text);
    }
}
