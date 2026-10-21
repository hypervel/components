<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\AzureOpenAi;

use Hypervel\Tests\Ai\Fixtures\Agents\AzureAgent;
use Hypervel\Tests\Ai\TestCase;

class AgentFakeTest extends TestCase
{
    public function testAzureAgentCanBeFaked(): void
    {
        AzureAgent::fake(['Test response']);

        $response = (new AzureAgent)->prompt('Hello');

        $this->assertSame('Test response', $response->text);
    }

    public function testAzureAgentFakeWithClosure(): void
    {
        AzureAgent::fake(fn (string $prompt): string => "Echo: {$prompt}");

        $response = (new AzureAgent)->prompt('Hello world');

        $this->assertSame('Echo: Hello world', $response->text);
    }

    public function testAzureAgentFakeWithNoPredefinedResponses(): void
    {
        AzureAgent::fake();

        $response = (new AzureAgent)->prompt('Hello');

        $this->assertSame('Fake response for prompt: Hello', $response->text);
    }

    public function testAzureAgentFakeRecordsPrompts(): void
    {
        AzureAgent::fake();

        (new AzureAgent)->prompt('Hello');

        AzureAgent::assertPrompted('Hello');
        AzureAgent::assertNotPrompted('Goodbye');
    }

    public function testAzureAgentStreamCanBeFaked(): void
    {
        AzureAgent::fake(['Streamed response']);

        $response = (new AzureAgent)->stream('Hello');
        $response->each(fn (): true => true);

        $this->assertSame('Streamed response', $response->text);
    }
}
