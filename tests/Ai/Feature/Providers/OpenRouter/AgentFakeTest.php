<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenRouter;

use Hypervel\Tests\Ai\Fixtures\Agents\OpenRouterAgent;
use Hypervel\Tests\Ai\TestCase;

class AgentFakeTest extends TestCase
{
    public function testOpenrouterAgentCanBeFaked(): void
    {
        OpenRouterAgent::fake(['Test response']);

        $response = (new OpenRouterAgent)->prompt('Hello');

        $this->assertSame('Test response', $response->text);
    }

    public function testOpenrouterAgentFakeWithClosure(): void
    {
        OpenRouterAgent::fake(fn (string $prompt): string => "Echo: {$prompt}");

        $response = (new OpenRouterAgent)->prompt('Hello world');

        $this->assertSame('Echo: Hello world', $response->text);
    }

    public function testOpenrouterAgentFakeWithNoPredefinedResponses(): void
    {
        OpenRouterAgent::fake();

        $response = (new OpenRouterAgent)->prompt('Hello');

        $this->assertSame('Fake response for prompt: Hello', $response->text);
    }

    public function testOpenrouterAgentFakeRecordsPrompts(): void
    {
        OpenRouterAgent::fake();

        (new OpenRouterAgent)->prompt('Hello');

        OpenRouterAgent::assertPrompted('Hello');
        OpenRouterAgent::assertNotPrompted('Goodbye');
    }

    public function testOpenrouterAgentStreamCanBeFaked(): void
    {
        OpenRouterAgent::fake(['Streamed response']);

        $response = (new OpenRouterAgent)->stream('Hello');
        $response->each(fn (): true => true);

        $this->assertSame('Streamed response', $response->text);
    }
}
