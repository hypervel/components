<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Carbon\CarbonInterval;
use Closure;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\StringValue;
use Hypervel\Engine\Exceptions\HttpClientException;
use Hypervel\Engine\Http\V2\Response;
use Hypervel\Grpc\Client\RetryPolicy;
use Hypervel\Grpc\Client\StreamState;
use Hypervel\Grpc\Client\UnaryCall;
use Hypervel\Grpc\Exceptions\ConnectionException;
use Hypervel\Grpc\Exceptions\ProtocolException;
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\Protocol\Deadline;
use Hypervel\Grpc\Protocol\FrameEncoder;
use Hypervel\Grpc\Status;
use Hypervel\Grpc\StatusCode;
use Hypervel\Support\Sleep;
use Hypervel\Tests\TestCase;
use RuntimeException;

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
        $call = $this->call($state, $deadline);

        foreach (['wait', 'metadata', 'status', 'trailers'] as $method) {
            try {
                $call->{$method}();
                $this->fail("Expected {$method} to rethrow the transport failure.");
            } catch (ConnectionException $exception) {
                $this->assertSame($failure, $exception);
            }
        }
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
        );

        $response = $call->wait();

        $this->assertInstanceOf(StringValue::class, $response);
        $this->assertSame('retried', $response->getValue());
        $this->assertSame([1], $previousAttempts);
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

        Sleep::fake();
        Sleep::whenFakingSleep(static function (CarbonInterval $duration) use (&$now): void {
            $now += (int) ceil($duration->totalMicroseconds) * 1_000;
        });

        $call = $this->call(
            $first,
            $deadline,
            retryPolicy: new RetryPolicy(maxAttempts: 2),
            attemptFactory: function () use (&$attempts, $deadline): StreamState {
                ++$attempts;
                $state = $this->state($deadline);
                $state->failWithStatus(new Status(
                    StatusCode::DeadlineExceeded,
                    'The gRPC deadline was exceeded.',
                ));
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
        Sleep::assertSleptTimes(1);
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
    ): UnaryCall {
        return new UnaryCall(
            $state,
            '/testing.Service/Unary',
            'example.test:8443',
            $deserialize,
            $deadline,
            $retryPolicy,
            $attemptFactory,
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
}
