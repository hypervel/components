<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Approvals\Decision;
use Hypervel\Ai\Approvals\Decisions;
use Hypervel\Ai\Jobs\BroadcastAgent;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Responses\StreamedAgentResponse;
use Hypervel\Broadcasting\AnonymousEvent;
use Hypervel\Broadcasting\BroadcastException;
use Hypervel\Broadcasting\Channel;
use Hypervel\Support\Facades\Broadcast;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ConversationalAgent;
use Hypervel\Tests\Ai\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

class BroadcastAgentTest extends TestCase
{
    public function testThenCallbackReceivesStreamedAgentResponse(): void
    {
        Event::fake();
        AssistantAgent::fake(['Hello world']);

        $received = null;

        $job = new BroadcastAgent(
            agent: new AssistantAgent,
            prompt: 'Say hello',
            channels: new Channel('test-channel'),
        );

        $job->then(function (StreamedAgentResponse $response) use (&$received): void {
            $received = $response;
        });

        $job->handle();

        $this->assertInstanceOf(StreamedAgentResponse::class, $received);
        $this->assertSame('Hello world', $received->text);
    }

    public function testMultipleThenCallbacksAllReceiveStreamedAgentResponse(): void
    {
        Event::fake();
        AssistantAgent::fake(['Hello world']);

        $receivedA = null;
        $receivedB = null;

        $job = new BroadcastAgent(
            agent: new AssistantAgent,
            prompt: 'Say hello',
            channels: new Channel('test-channel'),
        );

        $job->then(function (StreamedAgentResponse $response) use (&$receivedA): void {
            $receivedA = $response;
        });

        $job->then(function (StreamedAgentResponse $response) use (&$receivedB): void {
            $receivedB = $response;
        });

        $job->handle();

        $this->assertInstanceOf(StreamedAgentResponse::class, $receivedA);
        $this->assertInstanceOf(StreamedAgentResponse::class, $receivedB);
    }

    public function testAResumeStreamsTheDecisionMapInsteadOfThePrompt(): void
    {
        Event::fake();
        ConversationalAgent::fake();

        $job = new BroadcastAgent(
            agent: new ConversationalAgent,
            channels: new Channel('test-channel'),
            prompt: Decisions::from(['call-1' => Decision::approve()]),
        );

        $job->handle();

        ConversationalAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            return $prompt->approvalDecisions?->get('call-1')?->isApproved() === true;
        });
    }

    public function testFailedBroadcastsAStreamFailedEventWithRecoverableFalseOnTheConfiguredChannel(): void
    {
        Event::fake();

        $channel = new Channel('test-channel');

        $job = new BroadcastAgent(
            agent: new AssistantAgent,
            prompt: 'Say hello',
            channels: $channel,
        );

        $invocationId = $job->invocationId;
        $job = unserialize(serialize($job));

        $job->failed(new RuntimeException('Something went wrong'));

        Event::assertDispatched(AnonymousEvent::class, function (AnonymousEvent $event) use ($channel, $invocationId): bool {
            $payload = $event->broadcastWith();

            return $event->broadcastAs() === 'stream_failed'
                && $payload['invocation_id'] === $invocationId
                && $payload['recoverable'] === false
                && $payload['message'] === 'The stream failed.'
                && $event->broadcastOn() == [$channel];
        });
    }

    public function testFailedBroadcastsOnEveryChannelWhenGivenAnArray(): void
    {
        Event::fake();

        $channels = [new Channel('a'), new Channel('b')];

        $job = new BroadcastAgent(
            agent: new AssistantAgent,
            prompt: 'Say hello',
            channels: $channels,
        );

        $job->failed(new RuntimeException('boom'));

        Event::assertDispatched(AnonymousEvent::class, fn (AnonymousEvent $event): bool => $event->broadcastAs() === 'stream_failed'
            && $event->broadcastOn() === $channels);
    }

    public function testFailedEventSharesTheInvocationIdWithBroadcastsFromHandle(): void
    {
        Event::fake();
        AssistantAgent::fake(['Hello world']);

        $job = new BroadcastAgent(
            agent: new AssistantAgent,
            prompt: 'Say hello',
            channels: new Channel('test-channel'),
        );

        $job->handle();

        $invocationId = $job->invocationId;

        $broadcastIds = [];

        Event::assertDispatched(AnonymousEvent::class, function (AnonymousEvent $event) use (&$broadcastIds): true {
            $broadcastIds[] = $event->broadcastWith()['invocation_id'] ?? null;

            return true;
        });

        $this->assertSame([$invocationId], array_values(array_unique($broadcastIds)));
    }

    public function testAnOversizedBroadcastFrameDoesNotAbortTheStreamAndThenStillResolves(): void
    {
        AssistantAgent::fake(['Hello world']);

        $pending = m::mock(AnonymousEvent::class);
        $pending->shouldReceive('as')->andReturnSelf();
        $pending->shouldReceive('with')->andReturnSelf();
        $pending->shouldReceive('sendNow')->atLeast()->once()->andThrow(new BroadcastException('Payload too large'));

        Broadcast::shouldReceive('on')->andReturn($pending);

        $received = null;

        $job = new BroadcastAgent(
            agent: new AssistantAgent,
            prompt: 'Say hello',
            channels: new Channel('test-channel'),
        );

        $job->then(function (StreamedAgentResponse $response) use (&$received): void {
            $received = $response;
        });

        $job->handle();

        $this->assertInstanceOf(StreamedAgentResponse::class, $received);
        $this->assertSame('Hello world', $received->text);
    }

    public function testStreamedResponsePassedToThenIsFullyResolved(): void
    {
        Event::fake();
        AssistantAgent::fake(['Hello world']);

        $received = null;

        $job = new BroadcastAgent(
            agent: new AssistantAgent,
            prompt: 'Say hello',
            channels: new Channel('test-channel'),
        );

        $job->then(function (StreamedAgentResponse $response) use (&$received): void {
            $received = $response;
        });

        $job->handle();

        $this->assertInstanceOf(StreamedAgentResponse::class, $received);
        $this->assertNotEmpty($received->events);
        $this->assertSame('Hello world', $received->text);
    }

    #[DataProvider('failureExceptions')]
    public function testFailedInvokesCatchCallbacksAfterBroadcasting(?Throwable $exception): void
    {
        Event::fake();

        $job = new BroadcastAgent(new AssistantAgent, 'Say hello', new Channel('test-channel'));
        $invocationId = $job->invocationId;
        $failures = [];
        $job->catch(function (?Throwable $exception) use ($invocationId, &$failures): void {
            Event::assertDispatched(AnonymousEvent::class, fn (AnonymousEvent $event): bool => $event->broadcastAs() === 'stream_failed'
                && $event->broadcastWith()['invocation_id'] === $invocationId);

            $failures[] = $exception;
        });

        $job->failed($exception);

        $this->assertSame([$exception], $failures);
    }

    /**
     * Provide exception and manual failures.
     */
    public static function failureExceptions(): array
    {
        return [
            'exception' => [new RuntimeException('Something went wrong')],
            'manual failure' => [null],
        ];
    }
}
