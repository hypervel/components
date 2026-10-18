<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Xai;

use Hypervel\Tests\Ai\Fixtures\Agents\XaiAgent;
use Hypervel\Tests\Ai\TestCase;

class AgentFakeTest extends TestCase
{
    public function testXaiAgentCanBeFaked(): void
    {
        XaiAgent::fake(['Test response']);

        $response = (new XaiAgent)->prompt('Hello');

        $this->assertSame('Test response', $response->text);
    }

    public function testXaiAgentFakeWithClosure(): void
    {
        XaiAgent::fake(fn (string $prompt): string => "Echo: {$prompt}");

        $response = (new XaiAgent)->prompt('Hello world');

        $this->assertSame('Echo: Hello world', $response->text);
    }

    public function testXaiAgentFakeWithNoPredefinedResponses(): void
    {
        XaiAgent::fake();

        $response = (new XaiAgent)->prompt('Hello');

        $this->assertSame('Fake response for prompt: Hello', $response->text);
    }

    public function testXaiAgentFakeRecordsPrompts(): void
    {
        XaiAgent::fake();

        (new XaiAgent)->prompt('Hello');

        XaiAgent::assertPrompted('Hello');
        XaiAgent::assertNotPrompted('Goodbye');
    }

    public function testXaiAgentStreamCanBeFaked(): void
    {
        XaiAgent::fake(['Streamed response']);

        $response = (new XaiAgent)->stream('Hello');
        $response->each(fn (): true => true);

        $this->assertSame('Streamed response', $response->text);
    }
}
