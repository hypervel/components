<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Closure;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\StringValue;
use Hypervel\Engine\Http\V2\Response;
use Hypervel\Grpc\Client\RetryPolicy;
use Hypervel\Grpc\Client\ServerStreamingCall;
use Hypervel\Grpc\Client\StreamState;
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\Protocol\Deadline;
use Hypervel\Grpc\Protocol\FrameEncoder;
use Hypervel\Grpc\StatusCode;
use Hypervel\Tests\TestCase;
use LogicException;

use function Hypervel\Coroutine\parallel;

class ServerStreamingCallTest extends TestCase
{
    public function testReadReturnsMessagesInOrderAndNullAfterCleanCompletion(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $state = $this->state($deadline);
        $this->respond($state, ['first', 'second']);
        $call = $this->call($state, $deadline);

        $this->assertSame('first', $call->read()?->getValue());
        $this->assertSame('second', $call->read()?->getValue());
        $this->assertNull($call->read());
        $this->assertNull($call->read());
    }

    public function testResponsesYieldsEveryMessageAndOwnsTheReaderUntilCompletion(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $state = $this->state($deadline);
        $this->respond($state, ['first', 'second']);
        $call = $this->call($state, $deadline);
        $responses = $call->responses();
        $responses->rewind();

        $this->assertSame('first', $responses->current()->getValue());

        try {
            $call->read();
            $this->fail('Expected the active response iterator to own the reader.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('one active reader', $exception->getMessage());
        }

        $responses->next();
        $this->assertSame('second', $responses->current()->getValue());
        $responses->next();
        $this->assertFalse($responses->valid());
        $this->assertNull($call->read());
    }

    public function testEmptyResponseStreamEndsCleanly(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $state = $this->state($deadline);
        $state->handle($this->trailersOnly(StatusCode::Ok));
        $call = $this->call($state, $deadline);

        $this->assertNull($call->read());
        $this->assertSame([], iterator_to_array($call->responses()));
    }

    public function testReadThrowsTheFinalNonOkStatusAfterDeliveredMessages(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $state = $this->state($deadline);
        $this->respond($state, ['partial'], StatusCode::DataLoss);
        $call = $this->call($state, $deadline);

        $this->assertSame('partial', $call->read()?->getValue());

        try {
            $call->read();
            $this->fail('Expected the final stream status to be thrown.');
        } catch (RpcException $exception) {
            $this->assertSame(StatusCode::DataLoss, $exception->status()->code());
        }
    }

    public function testTrailersOnlyFailureRetriesBeforeCommitment(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $first = $this->state($deadline);
        $first->handle($this->trailersOnly(
            StatusCode::Unavailable,
            ['grpc-retry-pushback-ms' => '0'],
        ));
        $attempts = 0;
        $call = $this->call(
            $first,
            $deadline,
            new RetryPolicy(maxAttempts: 2),
            function () use (&$attempts, $deadline): StreamState {
                ++$attempts;
                $state = $this->state($deadline);
                $this->respond($state, ['retried']);

                return $state;
            },
        );

        $this->assertSame('retried', $call->read()?->getValue());
        $this->assertNull($call->read());
        $this->assertSame(1, $attempts);
    }

    public function testInitialMetadataCommitsTheCallBeforeANonOkEnd(): void
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
            new RetryPolicy(maxAttempts: 2),
            function () use (&$attempts, $deadline): StreamState {
                ++$attempts;

                return $this->state($deadline);
            },
        );

        $this->assertSame('committed', $call->metadata()->first('x-metadata'));

        try {
            $call->read();
            $this->fail('Expected the committed error to be thrown without retry.');
        } catch (RpcException $exception) {
            $this->assertSame(StatusCode::Unavailable, $exception->status()->code());
        }

        $this->assertSame(0, $attempts);
    }

    public function testSecondConcurrentReaderFailsWithoutBlockingTheFirst(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $state = $this->state($deadline);
        $call = $this->call($state, $deadline);

        $results = parallel([
            'first' => static fn (): ?Message => $call->read(),
            'second' => static function () use ($call): LogicException {
                usleep(1_000);

                try {
                    $call->read();
                } catch (LogicException $exception) {
                    return $exception;
                }

                throw new LogicException('Expected the concurrent reader to fail.');
            },
            'response' => function () use ($state): null {
                usleep(2_000);
                $this->respond($state, ['first']);

                return null;
            },
        ]);

        $this->assertSame('first', $results['first']?->getValue());
        $this->assertStringContainsString('one active reader', $results['second']->getMessage());
    }

    public function testMetadataObserverCanWaitAlongsideTheReader(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $state = $this->state($deadline);
        $call = $this->call($state, $deadline);

        $results = parallel([
            'message' => static fn (): ?Message => $call->read(),
            'metadata' => static fn () => $call->metadata(),
            'response' => function () use ($state): null {
                usleep(1_000);
                $this->respond($state, ['message']);

                return null;
            },
        ]);

        $this->assertSame('message', $results['message']?->getValue());
        $this->assertSame('initial', $results['metadata']->first('x-metadata'));
    }

    public function testSlowConsumerLimitBecomesResourceExhausted(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $state = new StreamState($deadline, 1024, 8192, 1, 4096);
        $this->respond($state, ['first', 'second']);
        $call = $this->call($state, $deadline);

        $this->expectException(RpcException::class);
        $this->expectExceptionCode(StatusCode::ResourceExhausted->value);

        $call->read();
    }

    /**
     * @param null|Closure(int): StreamState $attemptFactory
     */
    private function call(
        StreamState $state,
        Deadline $deadline,
        ?RetryPolicy $retryPolicy = null,
        ?Closure $attemptFactory = null,
    ): ServerStreamingCall {
        return new ServerStreamingCall(
            $state,
            '/testing.Service/ServerStream',
            'example.test:8443',
            [StringValue::class, 'decode'],
            $deadline,
            $retryPolicy,
            $attemptFactory,
        );
    }

    private function state(Deadline $deadline): StreamState
    {
        return new StreamState($deadline, 1024, 8192, 128, 4096);
    }

    /**
     * @param list<string> $messages
     */
    private function respond(
        StreamState $state,
        array $messages,
        StatusCode $status = StatusCode::Ok,
    ): void {
        $encoder = new FrameEncoder(1024);
        $body = '';

        foreach ($messages as $message) {
            $body .= $encoder->encode(
                (new StringValue)->setValue($message)->serializeToString(),
            );
        }

        $state->handle(new Response(
            1,
            200,
            [
                'content-type' => 'application/grpc+proto',
                'x-metadata' => 'initial',
            ],
            $body,
            true,
        ));

        if ($state->isComplete()) {
            return;
        }

        $state->handle($this->finalTrailers($status));
    }

    /**
     * @param array<string, string> $headers
     */
    private function trailersOnly(StatusCode $status, array $headers = []): Response
    {
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

    private function finalTrailers(StatusCode $status): Response
    {
        return new Response(
            1,
            0,
            ['grpc-status' => (string) $status->value],
            '',
            false,
        );
    }
}
