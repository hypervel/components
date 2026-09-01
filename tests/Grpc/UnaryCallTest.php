<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Closure;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\StringValue;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Engine\Exceptions\HttpClientException;
use Hypervel\Engine\Http\V2\Response;
use Hypervel\Grpc\Client\RetryBackoff;
use Hypervel\Grpc\Client\RetryPolicy;
use Hypervel\Grpc\Client\StreamState;
use Hypervel\Grpc\Client\UnaryCall;
use Hypervel\Grpc\Contracts\GrpcOperationObserver;
use Hypervel\Grpc\Exceptions\ConnectionException;
use Hypervel\Grpc\Exceptions\ProtocolException;
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\GrpcOperation;
use Hypervel\Grpc\GrpcOperationHandle;
use Hypervel\Grpc\GrpcOperationResult;
use Hypervel\Grpc\Protocol\Deadline;
use Hypervel\Grpc\Protocol\FrameEncoder;
use Hypervel\Grpc\Protocol\ServiceMethod;
use Hypervel\Grpc\StatusCode;
use Hypervel\Tests\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

use function Hypervel\Coroutine\parallel;

class UnaryCallTest extends TestCase
{
    public function testWaitReturnsAndCachesOneDeserializedMessageForConcurrentWaiters(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $state = $this->state($deadline);
        $deserializations = 0;
        $call = $this->call(
            $state,
            $deadline,
            static function (string $payload) use (&$deserializations): Message {
                ++$deserializations;
                $message = new StringValue;
                $message->mergeFromString($payload);

                return $message;
            },
        );

        $results = parallel([
            'first' => static fn (): Message => $call->wait(),
            'second' => static fn (): Message => $call->wait(),
            'response' => function () use ($state): null {
                usleep(1_000);
                $this->respondSuccessfully($state, 'hello');

                return null;
            },
        ]);

        $this->assertInstanceOf(StringValue::class, $results['first']);
        $this->assertSame('hello', $results['first']->getValue());
        $this->assertSame($results['first'], $results['second']);
        $this->assertSame(1, $deserializations);
        $this->assertSame($results['first'], $call->wait());
    }

    public function testCanceledUnaryDeserializationCanBeResumedByAnotherWaiter(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $state = $this->state($deadline);
        $this->respondSuccessfully($state, 'hello');
        $cancellation = new CanceledException;
        $deserializations = 0;
        $call = $this->call(
            $state,
            $deadline,
            static function (string $payload) use (&$deserializations, $cancellation): Message {
                ++$deserializations;

                if ($deserializations === 1) {
                    throw $cancellation;
                }

                $message = new StringValue;
                $message->mergeFromString($payload);

                return $message;
            },
        );

        try {
            $call->wait();
            $this->fail('Expected cancellation to propagate.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame('hello', $call->wait()->getValue());
        $this->assertSame(2, $deserializations);
    }

    public function testCancellingOneMetadataWaiterDoesNotFailTheCall(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $state = $this->state($deadline);
        $call = $this->call($state, $deadline);
        $cancellation = null;
        $waiter = EngineCoroutine::create(function () use ($call, &$cancellation): void {
            try {
                $call->metadata();
            } catch (CanceledException $exception) {
                $cancellation = $exception;
            }
        });

        try {
            $this->assertTrue(EngineCoroutine::cancelById($waiter->getId(), throwException: true));
            $this->assertInstanceOf(CanceledException::class, $cancellation);
            $this->assertFalse($state->isComplete());
        } finally {
            if (EngineCoroutine::exists($waiter->getId())) {
                EngineCoroutine::cancelById($waiter->getId(), throwException: true);
            }
        }

        $this->respondSuccessfully($state, 'hello');

        $this->assertSame('hello', $call->wait()->getValue());
    }

    public function testCancelPublishesOneStableCanceledOutcome(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $state = $this->state($deadline);
        $abandonments = 0;
        $state->onAbandon(static function () use (&$abandonments): void {
            ++$abandonments;
        });
        $call = $this->call($state, $deadline);

        $call->cancel();
        $call->cancel();

        $this->assertSame(StatusCode::Cancelled, $call->status()->code());
        $this->assertSame(1, $abandonments);

        try {
            $call->wait();
            $this->fail('Expected the canceled call to fail.');
        } catch (RpcException $exception) {
            $this->assertSame(StatusCode::Cancelled, $exception->status()->code());
        }
    }

    public function testCancelMapsARetryableCompletedAttemptAcrossEveryObserver(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $state = $this->state($deadline);
        $state->handle($this->trailersOnly(
            status: StatusCode::Unavailable,
            headers: [
                'grpc-retry-pushback-ms' => '0',
                'x-trailer' => 'trailing',
            ],
        ));
        $attempts = 0;
        $call = $this->call(
            $state,
            $deadline,
            retryPolicy: new RetryPolicy(maxAttempts: 2),
            attemptFactory: function () use (&$attempts, $deadline): StreamState {
                ++$attempts;

                return $this->state($deadline);
            },
        );

        $call->cancel();

        $this->assertNull($call->metadata()->first('x-trailer'));
        $this->assertSame('trailing', $call->trailers()->first('x-trailer'));
        $this->assertSame(StatusCode::Cancelled, $call->status()->code());
        $this->assertSame(0, $attempts);

        try {
            $call->wait();
            $this->fail('Expected the canceled call to fail.');
        } catch (RpcException $exception) {
            $this->assertSame(StatusCode::Cancelled, $exception->status()->code());
        }
    }

    public function testDroppingAnUnfinishedCallReleasesResourcesWithoutFinishingObservation(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $state = $this->state($deadline);
        $abandonments = 0;
        $state->onAbandon(static function () use (&$abandonments): void {
            ++$abandonments;
        });
        $observer = new UnaryCallGrpcOperationObserverStub;
        $operationHandle = new GrpcOperationHandle(
            new UnaryCallGrpcOperationStub,
            [[$observer, null]],
        );
        $call = $this->call($state, $deadline, operationHandle: $operationHandle);

        unset($call);

        $this->assertSame(1, $abandonments);
        $this->assertTrue($state->isAbandoned());
        $this->assertSame([], $observer->results);
        $this->assertFalse($operationHandle->isFinished());
    }

    public function testWaitRejectsAResponseWithoutAMessage(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $state = $this->state($deadline);
        $state->handle($this->trailersOnly(status: StatusCode::Ok));
        $call = $this->call($state, $deadline);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('must contain exactly one message');

        $call->wait();
    }

    public function testWaitRejectsMultipleResponseMessagesAndStoresTheProtocolFailure(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $state = $this->state($deadline);
        $encoder = new FrameEncoder(1024);
        $state->handle(new Response(
            1,
            200,
            ['content-type' => 'application/grpc+proto'],
            $encoder->encode($this->serialized('first'))
                . $encoder->encode($this->serialized('second')),
            true,
        ));
        $state->handle($this->finalTrailers(StatusCode::Ok));
        $call = $this->call($state, $deadline);

        try {
            $call->wait();
            $this->fail('Expected multiple unary response messages to fail.');
        } catch (ProtocolException $exception) {
            $this->assertStringContainsString('cannot contain multiple messages', $exception->getMessage());
        }

        $this->expectException(ProtocolException::class);
        $call->status();
    }

    public function testWaitThrowsMetadataRichRpcExceptionForANonOkStatus(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $state = $this->state($deadline);
        $state->handle(new Response(
            1,
            200,
            [
                'content-type' => 'application/grpc+proto',
                'grpc-status' => (string) StatusCode::NotFound->value,
                'grpc-message' => 'missing',
                'x-trailing' => 'value',
            ],
            '',
            false,
        ));
        $call = $this->call($state, $deadline);

        try {
            $call->wait();
            $this->fail('Expected the peer RPC error to be thrown.');
        } catch (RpcException $exception) {
            $this->assertSame(StatusCode::NotFound, $exception->status()->code());
            $this->assertSame('missing', $exception->getMessage());
            $this->assertSame('value', $exception->trailers()->first('x-trailing'));
            $this->assertSame('/testing.Service/Unary', $exception->method());
            $this->assertSame('example.test:8443', $exception->target());
        }
    }

    public function testMetadataStatusTrailersAndPeerRemainObservable(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $state = $this->state($deadline);
        $this->respondSuccessfully($state, 'hello');
        $call = $this->call($state, $deadline);

        $this->assertSame('initial', $call->metadata()->first('x-metadata'));
        $this->assertSame('trailing', $call->trailers()->first('x-trailer'));
        $this->assertSame(StatusCode::Ok, $call->status()->code());
        $this->assertSame('example.test:8443', $call->peer());
    }

    public function testTransportFailuresAreStoredForEveryObserver(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $state = $this->state($deadline);
        $failure = new ConnectionException(
            'example.test:8443',
            'socket lost',
            104,
            new HttpClientException('socket lost', 104),
        );
        $state->fail($failure);
        $observer = new UnaryCallGrpcOperationObserverStub;
        $operationHandle = new GrpcOperationHandle(
            new UnaryCallGrpcOperationStub,
            [[$observer, null]],
        );
        $call = $this->call($state, $deadline, operationHandle: $operationHandle);

        foreach (['wait', 'metadata', 'status', 'trailers'] as $method) {
            try {
                $call->{$method}();
                $this->fail("Expected {$method} to rethrow the transport failure.");
            } catch (ConnectionException $exception) {
                $this->assertSame($failure, $exception);
            }
        }

        $call->cancel();

        $this->assertCount(1, $observer->results);
        $this->assertSame($failure, $observer->results[0]->exception);
    }

    public function testTrailersOnlyRetryTransitionsToASecondAttempt(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $first = $this->state($deadline);
        $first->handle($this->trailersOnly(
            status: StatusCode::Unavailable,
            headers: ['grpc-retry-pushback-ms' => '0'],
        ));
        $previousAttempts = [];
        $observer = new UnaryCallGrpcOperationObserverStub;
        $operationHandle = new GrpcOperationHandle(
            new UnaryCallGrpcOperationStub,
            [[$observer, null]],
        );
        $call = $this->call(
            $first,
            $deadline,
            retryPolicy: new RetryPolicy(maxAttempts: 2),
            attemptFactory: function (int $attempts) use (&$previousAttempts, $deadline): StreamState {
                $previousAttempts[] = $attempts;
                $state = $this->state($deadline);
                $this->respondSuccessfully($state, 'retried');

                return $state;
            },
            operationHandle: $operationHandle,
        );

        $response = $call->wait();

        $this->assertInstanceOf(StringValue::class, $response);
        $this->assertSame('retried', $response->getValue());
        $this->assertSame([1], $previousAttempts);
        $this->assertTrue($operationHandle->isFinished());
        $this->assertCount(1, $observer->results);
        $this->assertSame(StatusCode::Ok, $observer->results[0]->status?->code());
        $this->assertNull($observer->results[0]->exception);
        $this->assertSame(2, $observer->results[0]->attemptCount);
    }

    public function testWaiterCancellationDuringRetryBackoffRestoresTheBackoffSequence(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $first = $this->state($deadline);
        $first->handle($this->trailersOnly(status: StatusCode::Unavailable));
        $policy = new RetryPolicy(
            maxAttempts: 2,
            initialBackoff: 60,
            maxBackoff: 60,
        );
        $backoff = new RetryBackoff($policy, new Randomizer(new Mt19937(1234)));
        $waiterCompleted = new Channel(1);
        $cancellation = null;
        $failure = null;
        $attempts = 0;
        $call = $this->call(
            $first,
            $deadline,
            retryPolicy: $policy,
            attemptFactory: function () use (&$attempts, $deadline): StreamState {
                ++$attempts;
                $state = $this->state($deadline);
                $this->respondSuccessfully($state, 'retried');

                return $state;
            },
            retryBackoff: $backoff,
        );

        $waiter = EngineCoroutine::create(static function () use (
            $call,
            $waiterCompleted,
            &$cancellation,
            &$failure,
        ): void {
            try {
                $call->status();
            } catch (CanceledException $exception) {
                $cancellation = $exception;
            } catch (Throwable $throwable) {
                $failure = $throwable;
            } finally {
                $waiterCompleted->push(true);
            }
        });

        try {
            $this->assertTrue(EngineCoroutine::cancelById($waiter->getId(), throwException: true));
            $this->assertTrue($waiterCompleted->pop(1));
            $this->assertInstanceOf(CanceledException::class, $cancellation);
            $this->assertNull($failure);
            $this->assertSame(0, $backoff->checkpoint());
            $this->assertSame(0, $attempts);
        } finally {
            if (EngineCoroutine::exists($waiter->getId())) {
                EngineCoroutine::cancelById($waiter->getId(), throwException: true);
            }
        }
    }

    public function testCanceledRetryDoesNotPreventALaterRetry(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $first = $this->state($deadline);
        $first->handle($this->trailersOnly(
            status: StatusCode::Unavailable,
            headers: ['grpc-retry-pushback-ms' => '0'],
        ));
        $factoryStarted = new Channel(2);
        $releaseFactory = new Channel(1);
        $waiterCompleted = new Channel(1);
        $cancellation = null;
        $failure = null;
        $attempts = 0;
        $call = $this->call(
            $first,
            $deadline,
            retryPolicy: new RetryPolicy(maxAttempts: 2),
            attemptFactory: function () use (
                $deadline,
                $factoryStarted,
                $releaseFactory,
                &$attempts,
            ): StreamState {
                $factoryStarted->push(true);
                $releaseFactory->pop();
                ++$attempts;
                $state = $this->state($deadline);
                $this->respondSuccessfully($state, 'retried');

                return $state;
            },
        );
        $waiter = EngineCoroutine::create(static function () use (
            $call,
            $waiterCompleted,
            &$cancellation,
            &$failure,
        ): void {
            try {
                $call->status();
            } catch (CanceledException $exception) {
                $cancellation = $exception;
            } catch (Throwable $throwable) {
                $failure = $throwable;
            } finally {
                $waiterCompleted->push(true);
            }
        });

        try {
            $this->assertTrue($factoryStarted->pop(1));
            $this->assertTrue(EngineCoroutine::cancelById($waiter->getId(), throwException: true));
            $this->assertTrue($waiterCompleted->pop(1));
            $this->assertInstanceOf(CanceledException::class, $cancellation);
            $this->assertNull($failure);
            $this->assertSame(0, $attempts);

            $this->assertTrue($releaseFactory->push(true));
            $this->assertSame(StatusCode::Ok, $call->status()->code());
            $this->assertTrue($factoryStarted->pop(1));
            $this->assertSame(1, $attempts);
        } finally {
            $releaseFactory->push(true, 0.001);

            if (EngineCoroutine::exists($waiter->getId())) {
                EngineCoroutine::cancelById($waiter->getId(), throwException: true);
            }
        }
    }

    public function testCancelWakesARetryBackoffWithoutStartingAnotherAttempt(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $first = $this->state($deadline);
        $first->handle($this->trailersOnly(status: StatusCode::Unavailable));
        $attempts = 0;
        $status = null;
        $failure = null;
        $waiterCompleted = new Channel(1);
        $call = $this->call(
            $first,
            $deadline,
            retryPolicy: new RetryPolicy(
                maxAttempts: 2,
                initialBackoff: 60,
                maxBackoff: 60,
            ),
            attemptFactory: function () use (&$attempts, $deadline): StreamState {
                ++$attempts;

                return $this->state($deadline);
            },
        );
        $waiter = EngineCoroutine::create(static function () use (
            $call,
            $waiterCompleted,
            &$status,
            &$failure,
        ): void {
            try {
                $status = $call->status();
            } catch (Throwable $throwable) {
                $failure = $throwable;
            } finally {
                $waiterCompleted->push(true);
            }
        });

        try {
            $this->assertCancellationReturnsPromptly($call);
            $this->assertTrue($waiterCompleted->pop(1));

            $this->assertNull($failure);
            $this->assertSame(StatusCode::Cancelled, $status?->code());
            $this->assertSame(0, $attempts);
            $this->assertFalse(EngineCoroutine::exists($waiter->getId()));
        } finally {
            if (EngineCoroutine::exists($waiter->getId())) {
                EngineCoroutine::cancelById($waiter->getId(), throwException: true);
            }
        }
    }

    public function testCancelDuringAttemptCreationAbandonsTheUnpublishedAttempt(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $first = $this->state($deadline);
        $first->handle($this->trailersOnly(
            status: StatusCode::Unavailable,
            headers: ['grpc-retry-pushback-ms' => '0'],
        ));
        $factoryStarted = new Channel(1);
        $releaseFactory = new Channel(1);
        $waiterCompleted = new Channel(1);
        $replacement = null;
        $status = null;
        $failure = null;
        $observer = new UnaryCallGrpcOperationObserverStub;
        $operationHandle = new GrpcOperationHandle(
            new UnaryCallGrpcOperationStub,
            [[$observer, null]],
        );
        $call = $this->call(
            $first,
            $deadline,
            retryPolicy: new RetryPolicy(maxAttempts: 2),
            attemptFactory: function () use (
                $deadline,
                $factoryStarted,
                $releaseFactory,
                &$replacement,
            ): StreamState {
                $factoryStarted->push(true);
                $releaseFactory->pop();
                $replacement = $this->state($deadline);

                return $replacement;
            },
            operationHandle: $operationHandle,
        );
        $waiter = EngineCoroutine::create(static function () use (
            $call,
            $waiterCompleted,
            &$status,
            &$failure,
        ): void {
            try {
                $status = $call->status();
            } catch (Throwable $throwable) {
                $failure = $throwable;
            } finally {
                $waiterCompleted->push(true);
            }
        });

        try {
            $this->assertTrue($factoryStarted->pop(1));
            $this->assertCancellationReturnsPromptly($call);
            $this->assertTrue($releaseFactory->push(true));
            $this->assertTrue($waiterCompleted->pop(1));
        } finally {
            $releaseFactory->push(true, 0.001);

            if (EngineCoroutine::exists($waiter->getId())) {
                EngineCoroutine::cancelById($waiter->getId(), throwException: true);
            }
        }

        $this->assertNull($failure);
        $this->assertSame(StatusCode::Cancelled, $status?->code());
        $this->assertInstanceOf(StreamState::class, $replacement);
        $this->assertTrue($replacement->isAbandoned());
        $this->assertCount(1, $observer->results);
        $this->assertSame(StatusCode::Cancelled, $observer->results[0]->status?->code());
        $this->assertSame(1, $observer->results[0]->attemptCount);
    }

    public function testLogicalCancellationSuppressesAnOrdinaryFailureFromTheUnpublishedAttemptFactory(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $first = $this->state($deadline);
        $first->handle($this->trailersOnly(
            status: StatusCode::Unavailable,
            headers: [
                'grpc-retry-pushback-ms' => '0',
                'x-trailer' => 'trailing',
            ],
        ));
        $factoryStarted = new Channel(1);
        $releaseFactory = new Channel(1);
        $waiterCompleted = new Channel(1);
        $factoryFailure = new RuntimeException('Attempt creation failed.');
        $status = null;
        $waiterFailure = null;
        $observer = new UnaryCallGrpcOperationObserverStub;
        $operationHandle = new GrpcOperationHandle(
            new UnaryCallGrpcOperationStub,
            [[$observer, null]],
        );
        $call = $this->call(
            $first,
            $deadline,
            retryPolicy: new RetryPolicy(maxAttempts: 2),
            // First-party factories do not currently throw after yielding; this pins the internal seam's cancellation invariant.
            attemptFactory: static function () use (
                $factoryStarted,
                $releaseFactory,
                $factoryFailure,
            ): never {
                $factoryStarted->push(true);
                $releaseFactory->pop();

                throw $factoryFailure;
            },
            operationHandle: $operationHandle,
        );
        $waiter = EngineCoroutine::create(static function () use (
            $call,
            $waiterCompleted,
            &$status,
            &$waiterFailure,
        ): void {
            try {
                $status = $call->status();
            } catch (Throwable $throwable) {
                $waiterFailure = $throwable;
            } finally {
                $waiterCompleted->push(true);
            }
        });

        try {
            $this->assertTrue($factoryStarted->pop(1));
            $this->assertCancellationReturnsPromptly($call);
            $this->assertTrue($releaseFactory->push(true));
            $this->assertTrue($waiterCompleted->pop(1));
        } finally {
            $releaseFactory->push(true, 0.001);

            if (EngineCoroutine::exists($waiter->getId())) {
                EngineCoroutine::cancelById($waiter->getId(), throwException: true);
            }
        }

        $this->assertNull($waiterFailure);
        $this->assertSame(StatusCode::Cancelled, $status?->code());
        $this->assertNull($call->metadata()->first('x-trailer'));
        $this->assertSame('trailing', $call->trailers()->first('x-trailer'));
        $this->assertSame(StatusCode::Cancelled, $call->status()->code());

        try {
            $call->wait();
            $this->fail('Expected the canceled call to fail.');
        } catch (RpcException $exception) {
            $this->assertSame(StatusCode::Cancelled, $exception->status()->code());
        }

        $this->assertCount(1, $observer->results);
        $this->assertSame(StatusCode::Cancelled, $observer->results[0]->status?->code());
        $this->assertSame(1, $observer->results[0]->attemptCount);
    }

    public function testConcurrentObserversCreateOnlyOneRetryAttempt(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $first = $this->state($deadline);
        $first->handle($this->trailersOnly(
            status: StatusCode::Unavailable,
            headers: ['grpc-retry-pushback-ms' => '0'],
        ));
        $attempts = 0;
        $call = $this->call(
            $first,
            $deadline,
            retryPolicy: new RetryPolicy(maxAttempts: 2),
            attemptFactory: function () use (&$attempts, $deadline): StreamState {
                ++$attempts;
                usleep(1_000);
                $state = $this->state($deadline);
                $this->respondSuccessfully($state, 'retried');

                return $state;
            },
        );

        $results = parallel([
            'status' => static fn () => $call->status(),
            'wait' => static fn () => $call->wait(),
        ]);

        $this->assertSame(StatusCode::Ok, $results['status']->code());
        $this->assertSame('retried', $results['wait']->getValue());
        $this->assertSame(1, $attempts);
    }

    public function testRetryFactoryFailureWakesEveryConcurrentObserver(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $first = $this->state($deadline);
        $first->handle($this->trailersOnly(
            status: StatusCode::Unavailable,
            headers: ['grpc-retry-pushback-ms' => '0'],
        ));
        $failure = new RuntimeException('attempt factory failed');
        $attempts = 0;
        $call = $this->call(
            $first,
            $deadline,
            retryPolicy: new RetryPolicy(maxAttempts: 2),
            attemptFactory: static function () use (&$attempts, $failure): StreamState {
                ++$attempts;
                usleep(1_000);

                throw $failure;
            },
        );

        $results = parallel([
            'status' => static function () use ($call): RuntimeException {
                try {
                    $call->status();
                } catch (RuntimeException $exception) {
                    return $exception;
                }

                throw new RuntimeException('Expected status to observe the factory failure.');
            },
            'wait' => static function () use ($call): RuntimeException {
                try {
                    $call->wait();
                } catch (RuntimeException $exception) {
                    return $exception;
                }

                throw new RuntimeException('Expected wait to observe the factory failure.');
            },
        ]);

        $this->assertSame($failure, $results['status']);
        $this->assertSame($failure, $results['wait']);
        $this->assertSame(1, $attempts);
    }

    public function testDeadlineExpiryDuringBackoffPublishesOneTerminalReplacement(): void
    {
        $now = 1_000_000_000;
        $deadline = Deadline::usingClock(
            1_010_000_000,
            static function () use (&$now): int {
                return $now;
            },
        );
        $first = $this->state($deadline);
        $first->handle($this->trailersOnly(status: StatusCode::Unavailable));
        $attempts = 0;

        $call = $this->call(
            $first,
            $deadline,
            retryPolicy: new RetryPolicy(maxAttempts: 2),
            attemptFactory: function () use (&$attempts, &$now, $deadline): StreamState {
                ++$attempts;
                $now = 1_010_000_000;
                $state = $this->state($deadline);
                $this->assertTrue($state->expireIfNeeded());
                usleep(1_000);

                return $state;
            },
        );

        $results = parallel([
            'status' => static fn (): StatusCode => $call->status()->code(),
            'wait' => static function () use ($call): StatusCode {
                try {
                    $call->wait();
                } catch (RpcException $exception) {
                    return $exception->status()->code();
                }

                throw new RuntimeException('Expected wait to observe the expired deadline.');
            },
        ]);

        $this->assertSame(StatusCode::DeadlineExceeded, $results['status']);
        $this->assertSame(StatusCode::DeadlineExceeded, $results['wait']);
        $this->assertSame(1, $attempts);
    }

    public function testCommittedFailureIsNeverRetried(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $state = $this->state($deadline);
        $state->handle(new Response(
            1,
            200,
            [
                'content-type' => 'application/grpc+proto',
                'x-metadata' => 'committed',
            ],
            '',
            true,
        ));
        $state->handle($this->finalTrailers(StatusCode::Unavailable));
        $attempts = 0;
        $call = $this->call(
            $state,
            $deadline,
            retryPolicy: new RetryPolicy(maxAttempts: 2),
            attemptFactory: function () use (&$attempts, $deadline): StreamState {
                ++$attempts;

                return $this->state($deadline);
            },
        );

        $this->assertSame(StatusCode::Unavailable, $call->status()->code());
        $this->assertSame(0, $attempts);
    }

    public function testMalformedRetryPushbackStopsTheRetry(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $state = $this->state($deadline);
        $state->handle($this->trailersOnly(
            status: StatusCode::Unavailable,
            headers: ['grpc-retry-pushback-ms' => 'invalid'],
        ));
        $attempts = 0;
        $call = $this->call(
            $state,
            $deadline,
            retryPolicy: new RetryPolicy(maxAttempts: 2),
            attemptFactory: function () use (&$attempts, $deadline): StreamState {
                ++$attempts;

                return $this->state($deadline);
            },
        );

        $this->assertSame(StatusCode::Unavailable, $call->status()->code());
        $this->assertSame(0, $attempts);
    }

    public function testDeserializerFailureBecomesTheCallProtocolFailure(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $state = $this->state($deadline);
        $this->respondSuccessfully($state, 'hello');
        $failure = new RuntimeException('decoder failed');
        $call = $this->call(
            $state,
            $deadline,
            static function () use ($failure): Message {
                throw $failure;
            },
        );

        try {
            $call->wait();
            $this->fail('Expected the response deserializer to fail.');
        } catch (ProtocolException $exception) {
            $this->assertSame($failure, $exception->getPrevious());
        }

        $this->expectException(ProtocolException::class);
        $call->status();
    }

    /**
     * @param array{class-string<Message>, string}|callable(string): Message $deserialize
     * @param null|Closure(int): StreamState $attemptFactory
     */
    private function call(
        StreamState $state,
        Deadline $deadline,
        array|callable $deserialize = [StringValue::class, 'decode'],
        ?RetryPolicy $retryPolicy = null,
        ?Closure $attemptFactory = null,
        ?RetryBackoff $retryBackoff = null,
        ?GrpcOperationHandle $operationHandle = null,
    ): UnaryCall {
        return new UnaryCall(
            $state,
            '/testing.Service/Unary',
            'example.test:8443',
            $deserialize,
            $deadline,
            $retryPolicy,
            $attemptFactory,
            $retryBackoff,
            operationHandle: $operationHandle,
        );
    }

    private function state(Deadline $deadline): StreamState
    {
        return new StreamState($deadline, 1024, 8192, 128, 4096);
    }

    private function respondSuccessfully(StreamState $state, string $value): void
    {
        $state->handle(new Response(
            1,
            200,
            [
                'content-type' => 'application/grpc+proto',
                'x-metadata' => 'initial',
            ],
            (new FrameEncoder(1024))->encode($this->serialized($value)),
            true,
        ));
        $state->handle($this->finalTrailers(
            StatusCode::Ok,
            ['x-trailer' => 'trailing'],
        ));
    }

    /**
     * @param array<string, string> $headers
     */
    private function trailersOnly(
        StatusCode $status,
        array $headers = [],
    ): Response {
        return new Response(
            1,
            200,
            [
                'content-type' => 'application/grpc+proto',
                'grpc-status' => (string) $status->value,
                ...$headers,
            ],
            '',
            false,
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function finalTrailers(
        StatusCode $status,
        array $headers = [],
    ): Response {
        return new Response(
            1,
            0,
            [
                'grpc-status' => (string) $status->value,
                ...$headers,
            ],
            '',
            false,
        );
    }

    private function serialized(string $value): string
    {
        return (new StringValue)->setValue($value)->serializeToString();
    }

    private function assertCancellationReturnsPromptly(UnaryCall $call): void
    {
        $completed = new Channel(1);
        $failure = null;
        $coroutine = EngineCoroutine::create(static function () use ($call, $completed, &$failure): void {
            try {
                $call->cancel();
            } catch (Throwable $throwable) {
                $failure = $throwable;
            } finally {
                $completed->push(true);
            }
        });

        try {
            $this->assertTrue($completed->pop(1));
            $this->assertNull($failure);
        } finally {
            if (EngineCoroutine::exists($coroutine->getId())) {
                EngineCoroutine::cancelById($coroutine->getId(), throwException: true);
            }
        }
    }
}

class UnaryCallGrpcOperationStub implements GrpcOperation
{
    public function serviceMethod(): ?ServiceMethod
    {
        return null;
    }
}

class UnaryCallGrpcOperationObserverStub implements GrpcOperationObserver
{
    /** @var list<GrpcOperationResult> */
    public array $results = [];

    public function starting(GrpcOperation $operation): null
    {
        return null;
    }

    public function finished(
        GrpcOperation $operation,
        mixed $token,
        GrpcOperationResult $result,
    ): void {
        $this->results[] = $result;
    }
}
