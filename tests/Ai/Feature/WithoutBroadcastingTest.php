<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Attributes\WithoutBroadcasting;
use Hypervel\Ai\Jobs\BroadcastAgent;
use Hypervel\Ai\Responses\StreamedAgentResponse;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\ToolCall;
use Hypervel\Ai\Streaming\Events\ToolResult;
use Hypervel\Broadcasting\AnonymousEvent;
use Hypervel\Broadcasting\Channel;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\NonBroadcastingTextAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\NonBroadcastingToolAgent;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;

class WithoutBroadcastingTest extends TestCase
{
    public function testItRejectsEventClassesThatAreNotStreamEvents(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WithoutBroadcasting('App\Events\Typo');
    }

    public function testNoEventsAreWithheldWhenTheAttributeIsAbsent(): void
    {
        $this->assertSame([], WithoutBroadcasting::eventsFor(new AssistantAgent));
    }

    public function testListedEventsAreWithheldWhenTheAttributeIsPresent(): void
    {
        $events = WithoutBroadcasting::eventsFor(new NonBroadcastingToolAgent);

        $this->assertContains(ToolResult::class, $events);
        $this->assertContains(ToolCall::class, $events);
    }

    public function testEventsNotListedInTheAttributeAreNotWithheld(): void
    {
        $this->assertNotContains(TextDelta::class, WithoutBroadcasting::eventsFor(new NonBroadcastingToolAgent));
    }

    public function testANullTargetWithholdsNothing(): void
    {
        $this->assertSame([], WithoutBroadcasting::eventsFor(null));
    }

    public function testBroadcastWithholdsListedEventsFromTheChannelButAssemblesTheFullResponse(): void
    {
        Event::fake();
        NonBroadcastingTextAgent::fake(['Hello world']);

        $received = null;

        (new NonBroadcastingTextAgent)
            ->broadcastNow('Say hello', new Channel('test-channel'))
            ->then(function (StreamedAgentResponse $response) use (&$received): void {
                $received = $response;
            });

        Event::assertNotDispatched(AnonymousEvent::class, fn (AnonymousEvent $event): bool => $event->broadcastAs() === 'text_delta');
        Event::assertDispatched(AnonymousEvent::class, fn (AnonymousEvent $event): bool => $event->broadcastAs() === 'stream_start');

        $this->assertNotNull($received, 'then() callback was never invoked');
        $this->assertSame('Hello world', $received->text);
    }

    public function testBroadcastAgentWithholdsListedEventsFromTheChannelButResolvesTheFullResponse(): void
    {
        Event::fake();
        NonBroadcastingTextAgent::fake(['Hello world']);

        $received = null;

        $job = new BroadcastAgent(
            agent: new NonBroadcastingTextAgent,
            prompt: 'Say hello',
            channels: new Channel('test-channel'),
        );

        $job->then(function (StreamedAgentResponse $response) use (&$received): void {
            $received = $response;
        });

        $job->handle();

        Event::assertNotDispatched(AnonymousEvent::class, fn (AnonymousEvent $event): bool => $event->broadcastAs() === 'text_delta');
        Event::assertDispatched(AnonymousEvent::class, fn (AnonymousEvent $event): bool => $event->broadcastAs() === 'stream_start');

        $this->assertNotNull($received, 'then() callback was never invoked');
        $this->assertSame('Hello world', $received->text);
    }
}
