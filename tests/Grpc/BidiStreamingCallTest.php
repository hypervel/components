<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Google\Protobuf\Internal\Message;
use Google\Protobuf\StringValue;
use Hypervel\Engine\Http\V2\Response;
use Hypervel\Grpc\Client\BidiStreamingCall;
use Hypervel\Grpc\Client\Connection;
use Hypervel\Grpc\Client\Endpoint;
use Hypervel\Grpc\Client\Request;
use Hypervel\Grpc\Client\StreamState;
use Hypervel\Grpc\Compression;
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

class BidiStreamingCallTest extends TestCase
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

    public function testOneReaderAndOneWriterOperateIndependently(): void
    {
        [$call, $client] = $this->call();

        $results = parallel([
            'write' => static function () use ($call): string {
                $call->write((new StringValue)->setValue('request'));

                return 'written';
            },
            'read' => static fn (): ?Message => $call->read(),
            'response' => function () use ($client): null {
                while ($client->writes === []) {
                    usleep(100);
                }

                $this->respond($client, ['response']);

                return null;
            },
        ]);

        $this->assertSame('written', $results['write']);
        $this->assertSame('response', $results['read']?->getValue());
        $this->assertSame(['request'], $this->writtenValues($client));
        $this->assertNull($call->read());
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

    public function testSecondConcurrentReaderFails(): void
    {
        [$call, $client] = $this->call();

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
            'response' => function () use ($client): null {
                usleep(2_000);
                $this->respond($client, ['first']);

                return null;
            },
        ]);

        $this->assertSame('first', $results['first']?->getValue());
        $this->assertStringContainsString('one active reader', $results['second']->getMessage());
    }

    public function testHalfCloseDoesNotPreventLaterResponseReads(): void
    {
        [$call, $client] = $this->call();

        $call->write((new StringValue)->setValue('request'));
        $call->writesDone();
        $this->respond($client, ['first', 'second']);

        $this->assertSame('first', $call->read()?->getValue());
        $this->assertSame('second', $call->read()?->getValue());
        $this->assertNull($call->read());
        $this->assertTrue($client->writes[1]['end']);
    }

    public function testFinalNonOkStatusIsThrownAfterPartialResponses(): void
    {
        [$call, $client] = $this->call();
        $this->respond($client, ['partial'], StatusCode::Aborted);

        $this->assertSame('partial', $call->read()?->getValue());

        try {
            $call->read();
            $this->fail('Expected the bidirectional stream status to be thrown.');
        } catch (RpcException $exception) {
            $this->assertSame(StatusCode::Aborted, $exception->status()->code());
        }
    }

    public function testPeerCompletionPreventsFurtherWrites(): void
    {
        [$call, $client] = $this->call();
        $this->respond($client, [], StatusCode::Unavailable);
        $this->assertSame(StatusCode::Unavailable, $call->status()->code());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('request stream has already been closed');

        $call->write(new StringValue);
    }

    /**
     * @return array{BidiStreamingCall, ClientCallClient}
     */
    private function call(): array
    {
        $client = new ClientCallClient;
        $connection = new Connection(
            new ClientCallClientFactory($client),
            Endpoint::parse('https://example.test:8443'),
            3.0,
            60.0,
        );
        $this->connections[] = $connection;
        $deadline = Deadline::fromTimeout(null);
        $state = new StreamState($deadline, 1024, 8192, 128, 4096);
        $connection->start(
            static fn (): Request => new Request(
                '/testing.Service/BidiStream',
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
            new BidiStreamingCall(
                $state,
                '/testing.Service/BidiStream',
                'example.test:8443',
                [StringValue::class, 'decode'],
                $deadline,
                $connection,
                new FrameEncoder(1024),
            ),
            $client,
        ];
    }

    /**
     * @param list<string> $messages
     */
    private function respond(
        ClientCallClient $client,
        array $messages,
        StatusCode $status = StatusCode::Ok,
    ): void {
        $body = '';
        $encoder = new FrameEncoder(1024);

        foreach ($messages as $message) {
            $body .= $encoder->encode(
                (new StringValue)->setValue($message)->serializeToString(),
            );
        }

        $client->respond(new Response(
            1,
            200,
            ['content-type' => 'application/grpc+proto'],
            $body,
            true,
        ));
        $client->respond(new Response(
            1,
            0,
            ['grpc-status' => (string) $status->value],
            '',
            false,
        ));
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
}
