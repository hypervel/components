<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai;

use Closure;
use Generator;
use Hypervel\Ai\Responses\StreamableAgentResponse;
use Hypervel\Ai\Streaming\Events\ReasoningDelta;
use Hypervel\Ai\Streaming\Events\StreamEvent;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\TextEnd;
use Hypervel\Broadcasting\AnonymousEvent;
use Hypervel\Broadcasting\Channel as BroadcastChannel;
use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Exceptions\ChildCancellationException;
use Hypervel\Database\Pool\ConnectionLease;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Support\Facades\Context;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Exceptions;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class BroadcastBatchingTest extends TestCase
{
    public function testBatchesPreserveContentBoundariesAndOriginalReplay(): void
    {
        config(['ai.broadcasting.batching.milliseconds' => 60000]);
        Event::fake([AnonymousEvent::class]);
        $events = [
            new TextDelta('text-1', 'message', 'Hello', 1),
            new TextDelta('text-2', 'message', ' world', 2),
            new TextEnd('end', 'message', 3),
            new ReasoningDelta('reason-1', 'reason', 'Think', 4),
            new ReasoningDelta('reason-2', 'reason', ' carefully', 5),
            new ReasoningDelta('summary', 'reason', '', 6, ['text' => 'Summary']),
            new TextDelta('other-message', 'other', 'Other', 7),
            (new TextDelta('other-invocation', 'other', 'Run', 8))->withInvocationId('other-run'),
        ];
        $originals = array_map(static fn (StreamEvent $event): array => $event->toArray(), $events);
        $response = new StreamableAgentResponse('invocation', static fn (): array => $events);

        $response->broadcast(new BroadcastChannel('conversation'));

        $payloads = Event::dispatched(AnonymousEvent::class)->map(static fn (array $arguments): array => $arguments[0]->broadcastWith())->all();
        $this->assertSame(['text-1', 'end', 'reason-1', 'summary', 'other-message', 'other-invocation'], array_column($payloads, 'id'));
        $this->assertSame('Hello world', $payloads[0]['delta']);
        $this->assertSame(1, $payloads[0]['timestamp']);
        $this->assertSame('Think carefully', $payloads[2]['delta']);
        $this->assertSame(['text' => 'Summary'], $payloads[3]['summary']);
        $this->assertSame($events, iterator_to_array($response));
        $this->assertSame($originals, array_map(static fn (StreamEvent $event): array => $event->toArray(), $events));
    }

    public function testEncodedSizeBoundsCombinedDeltasAndDisabledBatchingKeepsIndividualEvents(): void
    {
        Event::fake([AnonymousEvent::class]);
        config(['ai.broadcasting.batching.milliseconds' => 60000, 'ai.broadcasting.batching.max_bytes' => 12]);
        $events = [new TextDelta('1', 'message', 'é', 1), new TextDelta('2', 'message', 'é', 2), new TextDelta('3', 'message', 'ééé', 3)];
        $response = new StreamableAgentResponse('invocation', static fn (): array => $events);
        $response->broadcast(new BroadcastChannel('conversation'));

        $payloads = Event::dispatched(AnonymousEvent::class)->map(static fn (array $arguments): array => $arguments[0]->broadcastWith());
        $this->assertSame(['éé', 'ééé'], $payloads->pluck('delta')->all());

        Event::fake([AnonymousEvent::class]);
        config(['ai.broadcasting.batching.enabled' => false]);
        $response->broadcast(new BroadcastChannel('conversation'));
        $this->assertCount(3, Event::dispatched(AnonymousEvent::class));
    }

    #[DataProvider('batchWindows')]
    public function testQuietStreamsFlushInCapturedContextWithoutLeakingChildChanges(int $milliseconds): void
    {
        config(['broadcasting.default' => 'null', 'ai.broadcasting.batching.milliseconds' => $milliseconds]);
        CoroutineContext::set('broadcast.account', 'consumer');
        Context::add('request_id', 'request');
        $sent = new Channel(1);
        Event::listen(AnonymousEvent::class, function (AnonymousEvent $event) use ($sent): void {
            $this->assertSame('captured', CoroutineContext::get('broadcast.account'));
            $this->assertSame('request', Context::get('request_id'));
            Context::add('request_id', 'sender');
            $sent->push($event->broadcastWith()['delta']);
        });
        $response = new StreamableAgentResponse('invocation', function () use ($sent): Generator {
            yield new TextDelta('text', 'message', 'Hello', 1);
            $this->assertSame('Hello', $sent->pop(1));
        });
        $response->usingContext(static function (Closure $work): mixed {
            $previous = CoroutineContext::get('broadcast.account');
            CoroutineContext::set('broadcast.account', 'captured');
            try {
                return $work();
            } finally {
                CoroutineContext::set('broadcast.account', $previous);
            }
        });

        try {
            $response->broadcast(new BroadcastChannel('conversation'));
            $this->assertSame('Hello', $response->text);
            $this->assertSame('consumer', CoroutineContext::get('broadcast.account'));
            $this->assertSame('request', Context::get('request_id'));
        } finally {
            $sent->close();
        }
    }

    /**
     * Cover immediate coalescing and a timed flush during upstream silence.
     */
    public static function batchWindows(): array
    {
        return ['immediate' => [0], 'timed' => [5]];
    }

    #[DataProvider('senderFailures')]
    public function testProducerFailureDrainsAcceptedDeltasBeforeRethrowing(bool $senderFails): void
    {
        config(['broadcasting.default' => 'null', 'ai.broadcasting.batching.milliseconds' => 60000]);
        Exceptions::fake();
        $sent = [];
        $senderFailure = new RuntimeException('Sender failed');
        Event::listen(AnonymousEvent::class, static function (AnonymousEvent $event) use (&$sent, $senderFails, $senderFailure): void {
            $sent[] = $event->broadcastWith()['delta'];
            if ($senderFails) {
                throw $senderFailure;
            }
        });
        $failure = new RuntimeException('Generation failed');
        $response = new StreamableAgentResponse('invocation', static function () use ($failure): Generator {
            yield new TextDelta('text', 'message', 'Hello', 1);
            throw $failure;
        });

        try {
            $response->broadcast(new BroadcastChannel('conversation'));
            $this->fail('The producer failure must reach the caller.');
        } catch (RuntimeException $caught) {
            $this->assertSame($failure, $caught);
        }

        $this->assertSame(['Hello'], $sent);
        if ($senderFails) {
            Exceptions::assertReported(static fn (RuntimeException $reported): bool => $reported === $senderFailure);
        }
    }

    /**
     * Keep the producer failure even when draining also fails.
     */
    public static function senderFailures(): array
    {
        return ['drained' => [false], 'sender also failed' => [true]];
    }

    #[DataProvider('cancellationModes')]
    public function testCancelingAProducerBlockedOnItsBacklogCancelsTheSender(bool $throwException, bool $cleanupThrows): void
    {
        config(['broadcasting.default' => 'null', 'ai.broadcasting.batching.max_bytes' => 1]);
        $waiting = new Channel(1);
        $active = false;
        $caught = null;
        $cleanupException = new RuntimeException('Producer cleanup failed.');
        Event::listen(AnonymousEvent::class, static function () use ($waiting, &$active): void {
            $active = true;
            try {
                $waiting->pop(1);
            } finally {
                $active = false;
            }
        });
        $parent = EngineCoroutine::create(static function () use (&$caught, $cleanupThrows, $cleanupException): void {
            $response = new StreamableAgentResponse('invocation', static function () use ($cleanupThrows, $cleanupException): Generator {
                try {
                    for ($index = 0; $index < 256; ++$index) {
                        yield new TextDelta((string) $index, 'message', 'x', 1);
                    }
                } finally {
                    if ($cleanupThrows) {
                        throw $cleanupException;
                    }
                }
            });
            try {
                $response->broadcast(new BroadcastChannel('conversation'));
            } catch (Throwable $exception) {
                $caught = $exception;
            }
        });

        try {
            $this->assertTrue($active);
            $this->assertTrue(EngineCoroutine::cancelById($parent->getId(), $throwException));
            Coroutine::join([$parent->getId()], 1);
            if ($cleanupThrows) {
                $this->assertSame($cleanupException, $caught);
                $this->assertInstanceOf(CanceledException::class, $caught->getPrevious());
            } else {
                $this->assertInstanceOf(CanceledException::class, $caught);
            }
            $this->assertFalse($active);
        } finally {
            $waiting->close();
            if (Coroutine::exists($parent->getId())) {
                EngineCoroutine::cancelById($parent->getId(), throwException: true);
                Coroutine::join([$parent->getId()], 1);
            }
        }
    }

    /**
     * Cover both native cancellation modes with normal and throwing cleanup.
     */
    public static function cancellationModes(): array
    {
        return [
            'throw' => [true, false],
            'return false' => [false, false],
            'throw with cleanup failure' => [true, true],
            'return false with cleanup failure' => [false, true],
        ];
    }

    #[TestWith([false, false])]
    #[TestWith([false, true])]
    #[TestWith([true, false])]
    #[TestWith([true, true])]
    public function testCancellationWhileFinishingBroadcastsCancelsTheSender(bool $cleanupThrows, bool $producerFails): void
    {
        config(['broadcasting.default' => 'null', 'ai.broadcasting.batching.max_bytes' => 1]);
        $failure = $cleanupThrows ? new RuntimeException('Release cleanup failed.', previous: new CanceledException) : new CanceledException;
        $lease = m::mock(ConnectionLease::class);
        $lease->shouldReceive('releaseIfIdle')->once()->andThrow($failure);
        $waiting = new Channel(1);
        $senderCanceled = false;
        $senderId = null;
        Event::listen(AnonymousEvent::class, static function () use ($waiting, &$senderCanceled, &$senderId): void {
            $senderId = Coroutine::id();

            try {
                $waiting->pop(1);
            } catch (CanceledException $exception) {
                $senderCanceled = true;
                throw $exception;
            }
        });
        $response = new StreamableAgentResponse('invocation', static function () use ($producerFails): Generator {
            yield new TextDelta('text', 'message', 'Hello', 1);
            if ($producerFails) {
                throw new RuntimeException('Producer failed.');
            }
        });
        $caught = null;
        CoroutineContext::set('__ai.test.connection_lease', $lease);

        try {
            $response->broadcast(new BroadcastChannel('conversation'));
        } catch (Throwable $exception) {
            $caught = $exception;
        } finally {
            CoroutineContext::forget('__ai.test.connection_lease');
            $waiting->close();
            if ($senderId !== null) {
                Coroutine::join([$senderId], 1);
            }
        }

        $this->assertSame($failure, $caught);
        $this->assertTrue($senderCanceled);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testIndependentSenderCancellationIsAChildFailure(bool $cleanupThrows): void
    {
        config(['broadcasting.default' => 'null', 'ai.broadcasting.batching.max_bytes' => 1]);
        $cleanupException = new RuntimeException('Sender cleanup failed.');
        Event::listen(AnonymousEvent::class, static function () use ($cleanupThrows, $cleanupException): void {
            try {
                EngineCoroutine::cancelById(Coroutine::id(), throwException: true);
            } finally {
                if ($cleanupThrows) {
                    Coroutine::sleep(0.001);
                    throw $cleanupException;
                }
            }
        });
        $response = new StreamableAgentResponse('invocation', static fn (): array => [new TextDelta('text', 'message', 'Hello', 1)]);

        try {
            $response->broadcast(new BroadcastChannel('conversation'));
            $this->fail('The sender cancellation must reach the caller.');
        } catch (ChildCancellationException $exception) {
            $this->assertFalse(Coroutine::causedByCancellation($exception));

            if ($cleanupThrows) {
                $this->assertSame($cleanupException, $exception->getPrevious());
                $this->assertInstanceOf(CanceledException::class, $cleanupException->getPrevious());
            } else {
                $this->assertInstanceOf(CanceledException::class, $exception->getPrevious());
            }
        }
    }

    public function testSenderFailureReachesTheCallerAndDisposesTheProducer(): void
    {
        config(['broadcasting.default' => 'null', 'ai.broadcasting.batching.max_bytes' => 1]);
        $failure = new RuntimeException('Broadcast listener failed');
        $disposed = false;
        Event::listen(AnonymousEvent::class, static fn () => throw $failure);
        $response = new StreamableAgentResponse('invocation', static function () use (&$disposed): Generator {
            try {
                yield new TextDelta('first', 'message', 'a', 1);
                yield new TextDelta('second', 'message', 'b', 1);
            } finally {
                $disposed = true;
            }
        });

        try {
            $response->broadcast(new BroadcastChannel('conversation'));
            $this->fail('The sender failure must reach the caller.');
        } catch (RuntimeException $caught) {
            $this->assertSame($failure, $caught);
        }

        $this->assertTrue($disposed);
    }

    public function testFailedDeferredOperationsDoNotBroadcast(): void
    {
        config(['broadcasting.default' => 'null']);
        $sent = [];
        Event::listen(AnonymousEvent::class, static function (AnonymousEvent $event) use (&$sent): void {
            $sent[] = $event;
        });
        $failure = new RuntimeException('Discard deferred events');

        try {
            Event::defer(static function () use ($failure): void {
                (new StreamableAgentResponse('invocation', static fn (): array => [new TextDelta('text', 'message', 'Hello', 1)]))
                    ->broadcast(new BroadcastChannel('conversation'));
                throw $failure;
            });
        } catch (RuntimeException $caught) {
            $this->assertSame($failure, $caught);
        }

        $this->assertSame([], $sent);
    }
}
