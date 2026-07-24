<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Google\Protobuf\Internal\Message;
use Google\Protobuf\StringValue;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Engine\Exceptions\HttpClientException;
use Hypervel\Engine\Http\V2\Response;
use Hypervel\Grpc\Client\ClientStreamingCall;
use Hypervel\Grpc\Client\Connection;
use Hypervel\Grpc\Client\Endpoint;
use Hypervel\Grpc\Client\Request;
use Hypervel\Grpc\Client\StreamState;
use Hypervel\Grpc\Compression;
use Hypervel\Grpc\Exceptions\ConnectionException;
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\Protocol\Deadline;
use Hypervel\Grpc\Protocol\FrameDecoder;
use Hypervel\Grpc\Protocol\FrameEncoder;
use Hypervel\Grpc\Protocol\MessageSerializer;
use Hypervel\Grpc\StatusCode;
use Hypervel\Tests\Grpc\Fixtures\ClientCallClient;
use Hypervel\Tests\Grpc\Fixtures\ClientCallClientFactory;
use Hypervel\Tests\TestCase;
use LogicException;
use Throwable;

use function Hypervel\Coroutine\parallel;

class ClientStreamingCallTest extends TestCase
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

    public function testWriteSendsFramedMessagesAndWritesDoneHalfClosesOnce(): void
    {
        [$call, $client] = $this->call();

        $call->write((new StringValue)->setValue('first'));
        $call->write((new StringValue)->setValue('second'));
        $call->writesDone();
        $call->writesDone();

        $this->assertSame(['first', 'second'], $this->writtenValues($client));
        $this->assertCount(3, $client->writes);
        $this->assertSame([
            'stream_id' => 1,
            'data' => '',
            'end' => true,
        ], $client->writes[2]);
    }

    public function testConcurrentWritesAreSerialized(): void
    {
        [$call, $client] = $this->call();
        $client->writeDelayMicroseconds = 2_000;

        parallel([
            static fn () => $call->write((new StringValue)->setValue('first')),
            static fn () => $call->write((new StringValue)->setValue('second')),
        ]);

        $this->assertSame(1, $client->maximumConcurrentWrites);
        $this->assertEqualsCanonicalizing(['first', 'second'], $this->writtenValues($client));
    }

    public function testWriteAndHalfCloseRacePreservesOperationOrder(): void
    {
        [$call, $client] = $this->call();
        $writeStarted = new Channel(1);
        $releaseWrite = new Channel(1);
        $blockNextWrite = true;
        $client->writeUsing(static function () use (&$blockNextWrite, $writeStarted, $releaseWrite): void {
            if (! $blockNextWrite) {
                return;
            }

            $blockNextWrite = false;
            $writeStarted->push(true);
            $releaseWrite->pop();
        });

        $coroutineIds = [];

        try {
            $writeCoroutine = Coroutine::create(static function () use ($call): void {
                $call->write((new StringValue)->setValue('message'));
            });
            $coroutineIds[] = $writeCoroutine->getId();
            $writeStarted->pop();

            $halfCloseCoroutine = Coroutine::create(static function () use ($call): void {
                $call->writesDone();
            });
            $coroutineIds[] = $halfCloseCoroutine->getId();
        } finally {
            $releaseWrite->push(true);

            if ($coroutineIds !== []) {
                Coroutine::join($coroutineIds);
            }
        }

        $this->assertSame('message', $this->writtenValues($client)[0]);
        $this->assertTrue($client->writes[1]['end']);
        $this->assertSame('', $client->writes[1]['data']);
    }

    public function testWriteAfterHalfCloseFailsClearly(): void
    {
        [$call] = $this->call();
        $call->writesDone();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('request stream has already been closed');

        $call->write(new StringValue);
    }

    public function testWaitHalfClosesAndCachesOneUnaryResponse(): void
    {
        $deserializations = 0;
        [$call, $client] = $this->call(
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
            'response' => function () use ($client): null {
                while ($client->writes === []) {
                    usleep(100);
                }

                $this->respondSuccessfully($client, 'reply');

                return null;
            },
        ]);

        $this->assertSame('reply', $results['first']->getValue());
        $this->assertSame($results['first'], $results['second']);
        $this->assertSame(1, $deserializations);
        $this->assertCount(1, $client->writes);
        $this->assertTrue($client->writes[0]['end']);
    }

    public function testWaitThrowsTheUnaryResponseStatus(): void
    {
        [$call, $client] = $this->call();
        $client->respond(new Response(
            1,
            200,
            [
                'content-type' => 'application/grpc+proto',
                'grpc-status' => (string) StatusCode::InvalidArgument->value,
            ],
            '',
            false,
        ));

        try {
            $call->wait();
            $this->fail('Expected the client-streaming RPC status to be thrown.');
        } catch (RpcException $exception) {
            $this->assertSame(StatusCode::InvalidArgument, $exception->status()->code());
        }
    }

    public function testWriteFailureTerminatesTheCall(): void
    {
        [$call, $client] = $this->call();
        $client->writeUsing(static function (): void {
            throw new HttpClientException('write failed', 32);
        });

        try {
            $call->write(new StringValue);
            $this->fail('Expected the write failure to reach the caller.');
        } catch (ConnectionException $exception) {
            $this->assertSame('write failed', $exception->getMessage());
        }

        $this->expectException(ConnectionException::class);
        $call->status();
    }

    public function testDeadlineDuringANativeWriteThrowsAMetadataRichRpcException(): void
    {
        $now = 0;
        $deadline = Deadline::usingClock(
            1_000_000_000,
            static function () use (&$now): int {
                return $now;
            },
        );
        [$call, $client] = $this->call(deadline: $deadline);
        $client->writeUsing(static function () use (&$now): void {
            $now = 1_000_000_000;

            throw new HttpClientException('write timed out');
        });

        try {
            $call->write(new StringValue);
            $this->fail('Expected the write deadline to reach the caller.');
        } catch (RpcException $exception) {
            $this->assertSame(StatusCode::DeadlineExceeded, $exception->status()->code());
            $this->assertSame('/testing.Service/ClientStream', $exception->method());
            $this->assertSame('example.test:8443', $exception->target());
        }

        $this->assertSame(StatusCode::DeadlineExceeded, $call->status()->code());
    }

    public function testOversizedRequestMessageTerminatesWithResourceExhausted(): void
    {
        [$call] = $this->call(maxSendMessageSize: 4);

        try {
            $call->write((new StringValue)->setValue('too large'));
            $this->fail('Expected the oversized request message to fail.');
        } catch (RpcException $exception) {
            $this->assertSame(StatusCode::ResourceExhausted, $exception->status()->code());
        }

        $this->assertSame(StatusCode::ResourceExhausted, $call->status()->code());
    }

    /**
     * @param array{class-string<Message>, string}|callable(string): Message $deserialize
     * @return array{ClientStreamingCall, ClientCallClient}
     */
    private function call(
        array|callable $deserialize = [StringValue::class, 'decode'],
        int $maxSendMessageSize = 1024,
        ?Deadline $deadline = null,
    ): array {
        $client = new ClientCallClient;
        $connection = new Connection(
            new ClientCallClientFactory($client),
            Endpoint::parse('https://example.test:8443'),
            3.0,
            60.0,
        );
        $this->connections[] = $connection;
        $deadline ??= Deadline::fromTimeout(null);
        $state = new StreamState($deadline, 1024, 8192, 128, 4096);
        $connection->start(
            static fn (): Request => new Request(
                '/testing.Service/ClientStream',
                'POST',
                '',
                [],
                true,
                true,
            ),
            $state,
            $deadline,
        );

        return [
            new ClientStreamingCall(
                $state,
                '/testing.Service/ClientStream',
                'example.test:8443',
                $deserialize,
                $deadline,
                $connection,
                new FrameEncoder($maxSendMessageSize),
            ),
            $client,
        ];
    }

    /**
     * @return list<string>
     */
    private function writtenValues(ClientCallClient $client): array
    {
        $values = [];

        foreach ($client->writes as $write) {
            if ($write['data'] === '') {
                continue;
            }

            $decoder = new FrameDecoder(Compression::Identity, 1024);

            foreach ($decoder->push($write['data']) as $payload) {
                $message = MessageSerializer::deserialize([StringValue::class, 'decode'], $payload);
                $values[] = $message->getValue();
            }

            $decoder->finish();
        }

        return $values;
    }

    private function respondSuccessfully(ClientCallClient $client, string $value): void
    {
        $client->respond(new Response(
            1,
            200,
            ['content-type' => 'application/grpc+proto'],
            (new FrameEncoder(1024))->encode(
                (new StringValue)->setValue($value)->serializeToString(),
            ),
            true,
        ));
        $client->respond(new Response(
            1,
            0,
            ['grpc-status' => '0'],
            '',
            false,
        ));
    }
}
