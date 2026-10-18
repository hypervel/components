<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\DeepSeek;

use Hypervel\Tests\Ai\Fixtures\Agents\DeepSeekAgent;
use Hypervel\Tests\Ai\TestCase;

class AgentFakeTest extends TestCase
{
    public function testDeepSeekAgentCanBeFaked(): void
    {
        DeepSeekAgent::fake(['Test response']);

        $response = (new DeepSeekAgent)->prompt('Hello');

        $this->assertSame('Test response', $response->text);
    }

    public function testDeepSeekAgentFakeWithClosure(): void
    {
        DeepSeekAgent::fake(fn (string $prompt): string => "Echo: {$prompt}");

        $response = (new DeepSeekAgent)->prompt('Hello world');

        $this->assertSame('Echo: Hello world', $response->text);
    }

    public function testDeepSeekAgentFakeWithNoPredefinedResponses(): void
    {
        DeepSeekAgent::fake();

        $response = (new DeepSeekAgent)->prompt('Hello');

        $this->assertSame('Fake response for prompt: Hello', $response->text);
    }

    public function testDeepSeekAgentFakeRecordsPrompts(): void
    {
        DeepSeekAgent::fake();

        (new DeepSeekAgent)->prompt('Hello');

        DeepSeekAgent::assertPrompted('Hello');
        DeepSeekAgent::assertNotPrompted('Goodbye');
    }

    public function testDeepSeekAgentStreamCanBeFaked(): void
    {
        DeepSeekAgent::fake(['Streamed response']);

        $response = (new DeepSeekAgent)->stream('Hello');
        $response->each(fn (): true => true);

        $this->assertSame('Streamed response', $response->text);
    }
}
