<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Closure;
use Hypervel\Contracts\Engine\Http\V2\ClientFactoryInterface;
use Hypervel\Contracts\Engine\Http\V2\ClientInterface;
use Hypervel\Contracts\Engine\Http\V2\RequestInterface;
use Hypervel\Contracts\Engine\Http\V2\ResponseInterface;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Engine\Exceptions\HttpClientException;
use Hypervel\Engine\Http\V2\Response;
use Hypervel\Grpc\Client\Connection;
use Hypervel\Grpc\Client\Endpoint;
use Hypervel\Grpc\Client\Request;
use Hypervel\Grpc\Client\StreamState;
use Hypervel\Grpc\Compression;
use Hypervel\Grpc\Exceptions\ConnectionException;
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\Protocol\Deadline;
use Hypervel\Grpc\Protocol\FrameEncoder;
use Hypervel\Grpc\Protocol\Timeout;
use Hypervel\Grpc\StatusCode;
use Hypervel\Tests\TestCase;
use LogicException;
use ReflectionProperty;
use Throwable;

use function Hypervel\Coroutine\parallel;

class ConnectionTest extends TestCase
{
    /** @var list<Connection> */
    private array $connections = [];

    protected function tearDownInCoroutine(): void
    {
        $failure = null;

        foreach ($this->connections as $connection) {
            try {
                $connection->close();
            } catch (Throwable $throwable) {
                $failure ??= $throwable;
            }
        }

        $this->connections = [];

        if ($failure !== null) {
            throw $failure;
        }
    }

    public function testCreatesTheEngineClientLazilyWithSettingsAndReusesIt(): void
    {
        $client = new ConnectionTestClient;
        $factory = new ConnectionTestClientFactory($client);
        $connection = $this->connection($factory, settings: ['connect_timeout' => 2.5]);

        $this->assertSame([], $factory->calls);

        $first = $this->state();
        $this->start($connection, $this->request(), $first);

        $this->assertSame([
            [
                'host' => 'example.test',
                'port' => 8443,
                'ssl' => true,
                'settings' => [
                    'connect_timeout' => 2.5,
                    'write_timeout' => 60.0,
                ],
            ],
        ], $factory->calls);

        $client->respond($this->trailersOnly(1));
        $this->assertSame(StatusCode::Ok, $first->status()->code());
        usleep(1_000);

        $second = $this->state();
        $this->start($connection, $this->request(), $second);
        $client->respond($this->trailersOnly(3));

        $this->assertSame(StatusCode::Ok, $second->status()->code());
        $this->assertCount(1, $factory->calls);
        $this->assertTrue($connection->isAccepting());

        $connection->close();
        $this->assertSame(1, $client->closeCount);
    }

    public function testDoesNotRetainAReceiverThatCompletesDuringSynchronousStartup(): void
    {
        $client = new ConnectionTestClient;
        $client->respond($this->trailersOnly(1));
        $connection = $this->connection(new ConnectionTestClientFactory($client));
        $state = $this->state();

        $this->start($connection, $this->request(), $state);

        $this->assertSame(StatusCode::Ok, $state->status()->code());
        $this->assertFalse(
            (new ReflectionProperty(Connection::class, 'receiving'))->getValue($connection),
        );
        $this->assertNull(
            (new ReflectionProperty(Connection::class, 'receiverCoroutineId'))->getValue($connection),
        );
    }

    public function testStoresAnInitialConnectionFailureInTheCallState(): void
    {
        $transportFailure = new HttpClientException('connection refused', 111);
        $connection = $this->connection(new ConnectionTestClientFactory($transportFailure));
        $state = $this->state();

        $this->start($connection, $this->request(), $state);

        try {
            $state->status();
            $this->fail('Expected the call state to contain the connection failure.');
        } catch (ConnectionException $exception) {
            $this->assertSame('connection refused', $exception->getMessage());
            $this->assertSame(111, $exception->transportCode());
            $this->assertSame('example.test:8443', $exception->target());
            $this->assertSame($transportFailure, $exception->getPrevious());
        }

        $this->assertTrue($connection->isClosed());
    }

    public function testCapsLazyConnectAndSendTimeoutsAndFinalizesTheRequestAfterConnect(): void
    {
        $now = 0;
        $deadline = Deadline::usingClock(
            1_000_000_000,
            static function () use (&$now): int {
                return $now;
            },
        );
        $client = new ConnectionTestClient;
        $factory = new ConnectionTestClientFactory($client);
        $factory->makeCallback = static function () use (&$now): void {
            $now = 250_000_000;
        };
        $connection = $this->connection($factory, settings: [
            'connect_timeout' => 3.0,
            'write_timeout' => 2.0,
        ]);
        $state = $this->state($deadline);

        $connection->start(
            static fn (): Request => new Request(
                '/testing.Service/Unary',
                'POST',
                '',
                ['grpc-timeout' => $deadline->encodedHeader() ?? 'expired'],
                false,
                true,
            ),
            $state,
            $deadline,
        );

        $this->assertSame(1.0, $factory->calls[0]['settings']['connect_timeout']);
        $this->assertSame(1.0, $factory->calls[0]['settings']['write_timeout']);
        $this->assertSame(0.75, $client->sendTimeouts[0]);
        $this->assertSame(
            0.75,
            Timeout::decode($client->sentRequests[0]->getHeaders()['grpc-timeout']),
        );

        $client->respond($this->trailersOnly(1));
        $this->assertSame(StatusCode::Ok, $state->status()->code());
    }

    public function testConnectFailureAfterTheDeadlineReportsDeadlineExceeded(): void
    {
        $now = 0;
        $deadline = Deadline::usingClock(
            1_000_000_000,
            static function () use (&$now): int {
                return $now;
            },
        );
        $factory = new ConnectionTestClientFactory(new HttpClientException('connect timed out'));
        $factory->makeCallback = static function () use (&$now): void {
            $now = 1_000_000_000;
        };
        $connection = $this->connection($factory, settings: [
            'connect_timeout' => 3.0,
            'write_timeout' => 60.0,
        ]);
        $state = $this->state($deadline);

        $connection->start(fn (): Request => $this->request(), $state, $deadline);

        $this->assertSame(StatusCode::DeadlineExceeded, $state->status()->code());
        $this->assertTrue($connection->isClosed());
    }

    public function testDifferentCallDeadlineExpiresWhileWaitingForTheConnectionSemaphore(): void
    {
        $client = new ConnectionTestClient;
        $connection = $this->connection(
            new ConnectionTestClientFactory($client),
            settings: ['connect_timeout' => 3.0, 'write_timeout' => 60.0],
        );
        $active = $this->state();
        $this->start($connection, $this->request(pipeline: true), $active);
        $client->writeUsing(static function (): void {
            usleep(50_000);
        });
        $waitingDeadline = Deadline::fromTimeout(0.01);
        $waiting = $this->state($waitingDeadline);

        parallel([
            'write' => static fn () => $connection->write(
                $active,
                'frame',
                false,
                Deadline::fromTimeout(null),
            ),
            'start' => static fn () => $connection->start(
                static fn (): Request => new Request(
                    '/testing.Service/Unary',
                    'POST',
                    '',
                    [],
                    false,
                    true,
                ),
                $waiting,
                $waitingDeadline,
            ),
        ]);

        $this->assertSame(StatusCode::DeadlineExceeded, $waiting->status()->code());
        $this->assertCount(1, $client->sentRequests);
        $this->assertTrue($connection->isAccepting());
    }

    public function testPassesTheEffectiveDeadlineToEveryNativeSendAndWrite(): void
    {
        $now = 0;
        $deadline = Deadline::usingClock(
            500_000_000,
            static function () use (&$now): int {
                return $now;
            },
        );
        $client = new ConnectionTestClient;
        $connection = $this->connection(
            new ConnectionTestClientFactory($client),
            settings: ['connect_timeout' => 3.0, 'write_timeout' => 2.0],
        );
        $state = $this->state($deadline);

        $this->start($connection, $this->request(pipeline: true), $state, $deadline);
        $now = 100_000_000;
        $connection->write($state, 'frame', false, $deadline);

        $this->assertSame([0.5], $client->sendTimeouts);
        $this->assertSame([0.4], $client->writeTimeouts);
    }

    public function testPassesTheBaselineTimeoutToEveryNativeOperationWithoutADeadline(): void
    {
        $deadline = Deadline::fromTimeout(null);
        $client = new ConnectionTestClient;
        $connection = $this->connection(
            new ConnectionTestClientFactory($client),
            settings: ['connect_timeout' => 3.0, 'write_timeout' => 2.0],
        );
        $state = $this->state($deadline);

        $this->start($connection, $this->request(pipeline: true), $state, $deadline);
        $connection->write($state, 'frame', false, $deadline);

        $this->assertSame([2.0], $client->sendTimeouts);
        $this->assertSame([2.0], $client->writeTimeouts);
    }

    public function testNativeSendFailureAtTheDeadlineReportsDeadlineAndTerminatesTheConnection(): void
    {
        $now = 0;
        $deadline = Deadline::usingClock(
            1_000_000_000,
            static function () use (&$now): int {
                return $now;
            },
        );
        $client = new ConnectionTestClient;
        $client->sendUsing(static function () use (&$now): int {
            $now = 1_000_000_000;

            throw new HttpClientException('send timed out');
        });
        $connection = $this->connection(
            new ConnectionTestClientFactory($client),
            settings: ['connect_timeout' => 3.0, 'write_timeout' => 60.0],
        );
        $state = $this->state($deadline);

        $connection->start(fn (): Request => $this->request(), $state, $deadline);

        $this->assertSame(StatusCode::DeadlineExceeded, $state->status()->code());
        $this->assertTrue($connection->isClosed());
        $this->assertSame(1, $client->closeCount);
    }

    public function testNativeWriteFailureAtTheDeadlineReportsDeadlineAndTerminatesTheConnection(): void
    {
        $now = 0;
        $deadline = Deadline::usingClock(
            1_000_000_000,
            static function () use (&$now): int {
                return $now;
            },
        );
        $client = new ConnectionTestClient;
        $connection = $this->connection(
            new ConnectionTestClientFactory($client),
            settings: ['connect_timeout' => 3.0, 'write_timeout' => 60.0],
        );
        $state = $this->state($deadline);
        $this->start($connection, $this->request(pipeline: true), $state, $deadline);
        $client->writeUsing(static function () use (&$now): void {
            $now = 1_000_000_000;

            throw new HttpClientException('write timed out');
        });

        try {
            $connection->write($state, 'frame', false, $deadline);
            $this->fail('Expected the deadline failure to reach the writer.');
        } catch (RpcException $exception) {
            $this->assertSame(StatusCode::DeadlineExceeded, $exception->status()->code());
        }

        $this->assertSame(StatusCode::DeadlineExceeded, $state->status()->code());
        $this->assertTrue($connection->isClosed());
        $this->assertSame(1, $client->closeCount);
    }

    public function testSerializesWholeSendAndWriteOperations(): void
    {
        $client = new ConnectionTestClient;
        $factory = new ConnectionTestClientFactory($client);
        $connection = $this->connection($factory);
        $first = $this->state();
        $this->start($connection, $this->request(pipeline: true), $first);
        $client->operationDelayMicroseconds = 5_000;

        $second = $this->state();
        parallel([
            'write' => static fn () => $connection->write(
                $first,
                'frame',
                false,
                Deadline::fromTimeout(null),
            ),
            'send' => static fn () => $connection->start(
                static fn (): Request => new Request(
                    '/testing.Service/Unary',
                    'POST',
                    '',
                    [],
                    false,
                    true,
                ),
                $second,
                Deadline::fromTimeout(null),
            ),
        ]);

        $this->assertSame(1, $client->maximumConcurrentOperations);
        $this->assertSame([
            ['stream_id' => 1, 'data' => 'frame', 'end' => false],
        ], $client->writes);

        $client->respond($this->trailersOnly(1));
        $client->respond($this->trailersOnly(3));
        $this->assertSame(StatusCode::Ok, $first->status()->code());
        $this->assertSame(StatusCode::Ok, $second->status()->code());

        $connection->close();
    }

    public function testReceiverCanClaimThePendingStateBeforeSendReturns(): void
    {
        $client = new ConnectionTestClient;
        $connection = $this->connection(new ConnectionTestClientFactory($client));
        $first = $this->state();
        $this->start($connection, $this->request(), $first);
        $sendStarted = new Channel(1);
        $releaseSend = new Channel(1);
        $startFinished = new Channel(1);
        $client->sendUsing(function () use ($client, $sendStarted, $releaseSend): int {
            if (count($client->sentRequests) === 2) {
                $sendStarted->push(true);
                $releaseSend->pop();

                return 3;
            }

            return 1;
        });
        $second = $this->state();

        Coroutine::create(static function () use ($connection, $second, $startFinished): void {
            $connection->start(
                static fn (): Request => new Request(
                    '/testing.Service/Unary',
                    'POST',
                    '',
                    [],
                    false,
                    true,
                ),
                $second,
                Deadline::fromTimeout(null),
            );
            $startFinished->push(true);
        });

        $sendStarted->pop();

        try {
            $client->respond($this->trailersOnly(3));
            $this->assertSame(StatusCode::Ok, $second->status()->code());
        } finally {
            $releaseSend->push(true);
            $startFinished->pop();
        }

        $this->assertSame(3, $second->streamId());
        $this->assertTrue($connection->isAccepting());

        $client->respond($this->trailersOnly(1));
        $this->assertSame(StatusCode::Ok, $first->status()->code());
        $connection->close();
    }

    public function testCompletedPendingResponseIsDiscardedWhenSendReturnsAnotherId(): void
    {
        $client = new ConnectionTestClient;
        $connection = $this->connection(new ConnectionTestClientFactory($client));
        $first = $this->state();
        $this->start($connection, $this->request(), $first);
        $sendStarted = new Channel(1);
        $releaseSend = new Channel(1);
        $startFinished = new Channel(1);
        $client->sendUsing(function () use ($client, $sendStarted, $releaseSend): int {
            if (count($client->sentRequests) === 2) {
                $sendStarted->push(true);
                $releaseSend->pop();

                return 5;
            }

            return 1;
        });
        $second = $this->state();

        Coroutine::create(static function () use ($connection, $second, $startFinished): void {
            $connection->start(
                static fn (): Request => new Request(
                    '/testing.Service/Unary',
                    'POST',
                    '',
                    [],
                    false,
                    true,
                ),
                $second,
                Deadline::fromTimeout(null),
            );
            $startFinished->push(true);
        });

        $sendStarted->pop();

        try {
            $client->respond(new Response(
                3,
                200,
                [
                    'content-type' => 'application/grpc+proto',
                    'x-initial' => 'untrusted',
                ],
                (new FrameEncoder(1024))->encode('wrong-stream'),
                true,
            ));
            $client->respond(new Response(
                3,
                0,
                [
                    'grpc-status' => '0',
                    'x-trailing' => 'untrusted',
                ],
                '',
                false,
            ));
            $this->assertSame(StatusCode::Ok, $second->status()->code());
        } finally {
            $releaseSend->push(true);
            $startFinished->pop();
        }

        foreach (
            [
                'metadata' => static fn () => $second->metadata(),
                'trailers' => static fn () => $second->trailers(),
                'status' => static fn () => $second->status(),
                'message' => static fn () => $second->nextMessage(),
            ] as $observer => $observe
        ) {
            try {
                $observe();
                $this->fail("Expected invalidated {$observer} to fail.");
            } catch (ConnectionException $exception) {
                $this->assertStringContainsString('stream identifier mismatch', $exception->getMessage());
            }
        }

        try {
            $first->status();
            $this->fail('Expected the other incomplete stream to fail with the connection.');
        } catch (ConnectionException $exception) {
            $this->assertStringContainsString('stream identifier mismatch', $exception->getMessage());
        }

        $this->assertSame(0, $second->bufferedMessageCount());
        $this->assertTrue($connection->isClosed());
        $this->assertSame(1, $client->closeCount);
    }

    public function testUnsolicitedStreamFailsEveryActiveCall(): void
    {
        $client = new ConnectionTestClient;
        $connection = $this->connection(new ConnectionTestClientFactory($client));
        $first = $this->state();
        $second = $this->state();
        $this->start($connection, $this->request(), $first);
        $this->start($connection, $this->request(), $second);

        $client->respond($this->trailersOnly(99));

        foreach ([$first, $second] as $state) {
            try {
                $state->status();
                $this->fail('Expected the unsolicited stream to fail the connection.');
            } catch (ConnectionException $exception) {
                $this->assertStringContainsString('unsolicited HTTP/2 stream [99]', $exception->getMessage());
            }
        }

        $this->assertTrue($connection->isClosed());
        $this->assertSame(1, $client->closeCount);
    }

    public function testMissingNativeStreamFailsOnlyThatCall(): void
    {
        $client = new ConnectionTestClient;
        $connection = $this->connection(new ConnectionTestClientFactory($client));
        $first = $this->state();
        $second = $this->state();
        $this->start($connection, $this->request(), $first);
        $this->start($connection, $this->request(), $second);
        $client->streams[1] = false;
        $client->poll();

        try {
            $first->status();
            $this->fail('Expected the reset stream to fail.');
        } catch (ConnectionException $exception) {
            $this->assertStringContainsString('closed HTTP/2 stream [1]', $exception->getMessage());
            $this->assertNull($exception->transportCode());
        }

        $this->assertTrue($connection->isAccepting());
        $this->assertTrue($client->isConnected());

        $client->respond($this->trailersOnly(3));
        $this->assertSame(StatusCode::Ok, $second->status()->code());
        $connection->close();
    }

    public function testReceiverExpiresAnUnobservedDeadlineAndRetiresTheConnection(): void
    {
        $client = new ConnectionTestClient;
        $retired = 0;
        $connection = $this->connection(
            new ConnectionTestClientFactory($client),
            static function () use (&$retired): void {
                ++$retired;
            },
        );
        $now = 0;
        $deadline = Deadline::usingClock(
            1_000_000_000,
            static function () use (&$now): int {
                return $now;
            },
        );
        $state = $this->state($deadline);

        $this->start($connection, $this->request(), $state, $deadline);
        $receiverCoroutineId = (new ReflectionProperty(Connection::class, 'receiverCoroutineId'))
            ->getValue($connection);
        $now = 2_000_000_000;
        $client->poll();

        $this->assertIsInt($receiverCoroutineId);
        $this->assertFalse(Coroutine::exists($receiverCoroutineId));
        $this->assertNull(
            (new ReflectionProperty(Connection::class, 'receiverCoroutineId'))->getValue($connection),
        );
        $this->assertSame(StatusCode::DeadlineExceeded, $state->status()->code());
        $this->assertTrue($state->isAbandoned());
        $this->assertTrue($connection->isClosed());
        $this->assertSame(1, $client->closeCount);
        $this->assertSame(1, $retired);
    }

    public function testCloseCancelsTheReceiverBeforeClosingItsNativeClient(): void
    {
        $client = new ConnectionCloseWaitTestClient;
        $connection = $this->connection(new ConnectionTestClientFactory($client));
        $state = $this->state();
        $this->start($connection, $this->request(), $state);
        $receiverCoroutineId = (new ReflectionProperty(Connection::class, 'receiverCoroutineId'))
            ->getValue($connection);

        $this->assertIsInt($receiverCoroutineId);
        $this->assertTrue($client->waiting);

        $connection->close();

        $this->assertFalse($client->waiting);
        $this->assertFalse(Coroutine::exists($receiverCoroutineId));
        $this->assertNull(
            (new ReflectionProperty(Connection::class, 'receiverCoroutineId'))->getValue($connection),
        );
        $this->assertFalse(
            (new ReflectionProperty(Connection::class, 'receiving'))->getValue($connection),
        );
        $this->assertSame(1, $client->closeCount);

        try {
            $state->status();
            $this->fail('Expected close to fail the active call.');
        } catch (ConnectionException $exception) {
            $this->assertSame('The gRPC connection was closed.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public function testRetiringConnectionPreservesHealthyCallsUntilTheyFinish(): void
    {
        $client = new ConnectionTestClient;
        $retired = 0;
        $connection = $this->connection(
            new ConnectionTestClientFactory($client),
            static function () use (&$retired): void {
                ++$retired;
            },
        );
        $slow = $this->state(maxBufferedMessages: 1);
        $healthy = $this->state();
        $this->start($connection, $this->request(), $slow);
        $this->start($connection, $this->request(), $healthy);
        $frames = (new FrameEncoder(1024))->encode('one')
            . (new FrameEncoder(1024))->encode('two');

        $client->respond(new Response(
            1,
            200,
            ['content-type' => 'application/grpc+proto'],
            $frames,
            true,
        ));

        $this->assertSame(StatusCode::ResourceExhausted, $slow->status()->code());
        $this->assertFalse($connection->isAccepting());
        $this->assertTrue($client->isConnected());

        $client->respond($this->trailersOnly(3));

        $this->assertSame(StatusCode::Ok, $healthy->status()->code());
        $this->assertTrue($connection->isClosed());
        $this->assertSame(1, $client->closeCount);
        $this->assertSame(1, $retired);
    }

    public function testDecoderReceiveLimitRetiresOnlyTheAffectedStream(): void
    {
        $client = new ConnectionTestClient;
        $retired = 0;
        $connection = $this->connection(
            new ConnectionTestClientFactory($client),
            static function () use (&$retired): void {
                ++$retired;
            },
        );
        $limited = $this->state(maxReceiveMessageSize: 64);
        $healthy = $this->state();
        $this->start($connection, $this->request(), $limited);
        $this->start($connection, $this->request(), $healthy);
        $oversizedFrame = (new FrameEncoder(1024))->encode(
            str_repeat('x', 1_000),
            Compression::Gzip,
        );

        $client->respond(new Response(
            1,
            200,
            [
                'content-type' => 'application/grpc+proto',
                'grpc-encoding' => 'gzip',
            ],
            $oversizedFrame,
            true,
        ));

        $this->assertSame(StatusCode::ResourceExhausted, $limited->status()->code());
        $this->assertFalse($connection->isAccepting());
        $this->assertTrue($client->isConnected());

        $client->respond($this->trailersOnly(3));

        $this->assertSame(StatusCode::Ok, $healthy->status()->code());
        $this->assertTrue($connection->isClosed());
        $this->assertSame(1, $client->closeCount);
        $this->assertSame(1, $retired);
    }

    public function testReceiveFailureFansOutWithoutImplicitReplay(): void
    {
        $client = new ConnectionTestClient;
        $factory = new ConnectionTestClientFactory($client);
        $connection = $this->connection($factory);
        $first = $this->state();
        $second = $this->state();
        $this->start($connection, $this->request(), $first);
        $this->start($connection, $this->request(), $second);

        $client->failReceive(new HttpClientException('socket lost', 104));

        foreach ([$first, $second] as $state) {
            try {
                $state->status();
                $this->fail('Expected the receive failure to fan out.');
            } catch (ConnectionException $exception) {
                $this->assertSame('socket lost', $exception->getMessage());
                $this->assertSame(104, $exception->transportCode());
            }
        }

        $this->assertCount(1, $factory->calls);
        $this->assertTrue($connection->isClosed());
    }

    public function testWriteFailureFailsEveryStreamAndIsReportedToTheWriter(): void
    {
        $client = new ConnectionTestClient;
        $connection = $this->connection(new ConnectionTestClientFactory($client));
        $first = $this->state();
        $second = $this->state();
        $this->start($connection, $this->request(pipeline: true), $first);
        $this->start($connection, $this->request(), $second);
        $client->writeUsing(static function (): void {
            throw new HttpClientException('write failed', 32);
        });

        try {
            $connection->write($first, 'frame', false, Deadline::fromTimeout(null));
            $this->fail('Expected the write failure to reach the writer.');
        } catch (ConnectionException $exception) {
            $this->assertSame('write failed', $exception->getMessage());
            $this->assertSame(32, $exception->transportCode());
        }

        foreach ([$first, $second] as $state) {
            try {
                $state->status();
                $this->fail('Expected the write failure to fail every stream.');
            } catch (ConnectionException $exception) {
                $this->assertSame('write failed', $exception->getMessage());
            }
        }

        $this->assertTrue($connection->isClosed());
    }

    public function testCloseIsTerminalIdempotentAndRejectsLaterWork(): void
    {
        $client = new ConnectionTestClient;
        $connection = $this->connection(new ConnectionTestClientFactory($client));
        $active = $this->state();
        $this->start($connection, $this->request(), $active);

        $connection->close();
        $connection->close();

        try {
            $active->status();
            $this->fail('Expected close to fail the active call.');
        } catch (ConnectionException $exception) {
            $this->assertSame('The gRPC connection was closed.', $exception->getMessage());
        }

        $later = $this->state();
        $this->start($connection, $this->request(), $later);

        try {
            $later->status();
            $this->fail('Expected a closed connection to reject later work.');
        } catch (ConnectionException $exception) {
            $this->assertStringContainsString('closed and cannot accept', $exception->getMessage());
        }

        $this->expectException(ConnectionException::class);
        $connection->write($active, 'frame', false, Deadline::fromTimeout(null));
    }

    private function start(
        Connection $connection,
        Request $request,
        StreamState $state,
        ?Deadline $deadline = null,
    ): void {
        $connection->start(
            static fn (): Request => $request,
            $state,
            $deadline ?? Deadline::fromTimeout(null),
        );
    }

    private function connection(
        ClientFactoryInterface $factory,
        ?Closure $onRetired = null,
        array $settings = [],
    ): Connection {
        $connectTimeout = $settings['connect_timeout'] ?? 3.0;
        $writeTimeout = $settings['write_timeout'] ?? 60.0;
        unset($settings['connect_timeout'], $settings['write_timeout']);
        $connection = new Connection(
            $factory,
            Endpoint::parse('https://example.test:8443'),
            $connectTimeout,
            $writeTimeout,
            $settings,
            $onRetired,
        );
        $this->connections[] = $connection;

        return $connection;
    }

    private function state(
        ?Deadline $deadline = null,
        int $maxBufferedMessages = 128,
        int $maxReceiveMessageSize = 1024,
    ): StreamState {
        return new StreamState(
            $deadline ?? Deadline::fromTimeout(null),
            $maxReceiveMessageSize,
            8192,
            $maxBufferedMessages,
            4096,
        );
    }

    private function request(bool $pipeline = false): Request
    {
        return new Request(
            '/testing.Service/Unary',
            'POST',
            '',
            [],
            $pipeline,
            true,
        );
    }

    private function trailersOnly(int $streamId, int $status = 0): Response
    {
        return new Response(
            $streamId,
            200,
            [
                'content-type' => 'application/grpc+proto',
                'grpc-status' => (string) $status,
            ],
            '',
            false,
        );
    }
}

class ConnectionTestClientFactory implements ClientFactoryInterface
{
    /** @var list<array{host: string, port: int, ssl: bool, settings: array<string, mixed>}> */
    public array $calls = [];

    public int $delayMicroseconds = 0;

    public ?Closure $makeCallback = null;

    /** @var list<ClientInterface|Throwable> */
    private array $results;

    public function __construct(ClientInterface|Throwable ...$results)
    {
        $this->results = $results;
    }

    public function make(
        string $host,
        int $port = 80,
        bool $ssl = false,
        array $settings = [],
    ): ClientInterface {
        $this->calls[] = compact('host', 'port', 'ssl', 'settings');

        if ($this->delayMicroseconds > 0) {
            usleep($this->delayMicroseconds);
        }

        ($this->makeCallback ?? static function (): void {
        })();

        $result = array_shift($this->results)
            ?? throw new LogicException('No fake HTTP/2 client remains.');

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }
}

class ConnectionTestClient implements ClientInterface
{
    public bool $connected = true;

    public int $closeCount = 0;

    public int $operationDelayMicroseconds = 0;

    public int $maximumConcurrentOperations = 0;

    /** @var array<int, bool> */
    public array $streams = [];

    /** @var list<RequestInterface> */
    public array $sentRequests = [];

    /** @var list<?float> */
    public array $sendTimeouts = [];

    /** @var list<array{stream_id: int, data: string, end: bool}> */
    public array $writes = [];

    /** @var list<?float> */
    public array $writeTimeouts = [];

    private int $nextStreamId = 1;

    private int $concurrentOperations = 0;

    private Channel $events;

    /** @var null|Closure(): int */
    private ?Closure $sendCallback = null;

    /** @var null|Closure(): void */
    private ?Closure $writeCallback = null;

    public function __construct()
    {
        $this->events = new Channel(128);
    }

    public function sendUsing(Closure $callback): void
    {
        $this->sendCallback = $callback;
    }

    public function writeUsing(Closure $callback): void
    {
        $this->writeCallback = $callback;
    }

    public function respond(ResponseInterface $response): void
    {
        if ($response->isEndStream()) {
            unset($this->streams[$response->getStreamId()]);
        } else {
            $this->streams[$response->getStreamId()] = true;
        }

        $this->events->push($response);
    }

    public function poll(): void
    {
        $this->events->push('poll');
    }

    public function failReceive(Throwable $throwable): void
    {
        $this->events->push($throwable);
    }

    public function send(RequestInterface $request, ?float $timeout = null): int
    {
        $this->sentRequests[] = $request;
        $this->sendTimeouts[] = $timeout;
        $this->beginOperation();

        try {
            if ($this->operationDelayMicroseconds > 0) {
                usleep($this->operationDelayMicroseconds);
            }

            $streamId = $this->sendCallback === null
                ? $this->nextStreamId
                : ($this->sendCallback)();
            $this->nextStreamId = max($this->nextStreamId + 2, $streamId + 2);
            $this->streams[$streamId] = true;

            return $streamId;
        } finally {
            $this->finishOperation();
        }
    }

    public function recv(float $timeout = 0): ?ResponseInterface
    {
        $event = $this->events->pop($timeout);

        if ($event === false || $event === 'poll') {
            return null;
        }

        if ($event instanceof Throwable) {
            throw $event;
        }

        return $event;
    }

    public function write(
        int $streamId,
        string $data,
        bool $end = false,
        ?float $timeout = null,
    ): void {
        $this->beginOperation();

        try {
            if ($this->operationDelayMicroseconds > 0) {
                usleep($this->operationDelayMicroseconds);
            }

            ($this->writeCallback ?? static function (): void {
            })();
            $this->writes[] = [
                'stream_id' => $streamId,
                'data' => $data,
                'end' => $end,
            ];
            $this->writeTimeouts[] = $timeout;

            if ($end) {
                $this->streams[$streamId] = false;
            }
        } finally {
            $this->finishOperation();
        }
    }

    public function close(): void
    {
        ++$this->closeCount;
        $this->connected = false;

        foreach (array_keys($this->streams) as $streamId) {
            $this->streams[$streamId] = false;
        }

        if (! $this->events->isClosing()) {
            $this->events->close();
        }
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function isStreamOpen(int $streamId): bool
    {
        return $this->streams[$streamId] ?? false;
    }

    private function beginOperation(): void
    {
        ++$this->concurrentOperations;
        $this->maximumConcurrentOperations = max(
            $this->maximumConcurrentOperations,
            $this->concurrentOperations,
        );
    }

    private function finishOperation(): void
    {
        --$this->concurrentOperations;
    }
}

class ConnectionCloseWaitTestClient extends ConnectionTestClient
{
    public bool $waiting = false;

    public function recv(float $timeout = 0): ?ResponseInterface
    {
        $this->waiting = true;

        try {
            return parent::recv($timeout);
        } finally {
            $this->waiting = false;
        }
    }

    public function close(): void
    {
        if ($this->waiting) {
            throw new HttpClientException('Coroutine socket close wait');
        }

        parent::close();
    }
}
