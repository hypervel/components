<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Closure;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\StringValue;
use Hypervel\Container\Container;
use Hypervel\Contracts\Engine\Http\V2\ClientFactoryInterface;
use Hypervel\Contracts\Engine\Http\V2\ClientInterface;
use Hypervel\Engine\Http\V2\Response;
use Hypervel\Grpc\Client\BaseClient;
use Hypervel\Grpc\Client\BidiStreamingCall;
use Hypervel\Grpc\Client\ClientStreamingCall;
use Hypervel\Grpc\Client\RetryPolicy;
use Hypervel\Grpc\Client\ServerStreamingCall;
use Hypervel\Grpc\Client\UnaryCall;
use Hypervel\Grpc\Compression;
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\Metadata;
use Hypervel\Grpc\Protocol\FrameDecoder;
use Hypervel\Grpc\Protocol\FrameEncoder;
use Hypervel\Grpc\Protocol\MessageSerializer;
use Hypervel\Grpc\Protocol\Timeout;
use Hypervel\Grpc\StatusCode;
use Hypervel\Tests\Grpc\Fixtures\ClientCallClient;
use Hypervel\Tests\Grpc\Fixtures\ClientCallClientFactory;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

use function Hypervel\Coroutine\parallel;

class BaseClientTest extends TestCase
{
    /** @var list<TestingBaseClient> */
    private array $clients = [];

    protected function tearDownInCoroutine(): void
    {
        $failure = null;

        foreach ($this->clients as $client) {
            try {
                $client->close();
            } catch (Throwable $throwable) {
                $failure ??= $throwable;
            }
        }

        $this->clients = [];

        if ($failure !== null) {
            throw $failure;
        }
    }

    public function testBuildsAnExactUnaryRequestFromGeneratedStyleArguments(): void
    {
        $engineClient = new ClientCallClient;
        $client = $this->client(
            new ClientCallClientFactory($engineClient),
            [
                'timeout' => 5.0,
                'compression' => Compression::Gzip,
                'metadata' => ['x-default' => ['first', 'second']],
            ],
            'https://Example.test:8443',
        );
        $requestMessage = (new StringValue)->setValue('request');

        $call = $client->unary(
            '/testing.Service/Unary',
            $requestMessage,
            [StringValue::class, 'decode'],
            ['x-call' => 'value'],
        );

        $this->assertInstanceOf(UnaryCall::class, $call);
        $this->assertSame('https://Example.test:8443', $client->target());
        $this->assertSame('example.test:8443', $call->peer());
        $this->assertCount(1, $engineClient->sentRequests);
        $request = $engineClient->sentRequests[0];
        $headers = $request->getHeaders();

        $this->assertSame('/testing.Service/Unary', $request->getPath());
        $this->assertSame('POST', $request->getMethod());
        $this->assertFalse($request->isPipeline());
        $this->assertTrue($request->usesPipelineRead());
        $this->assertSame('application/grpc+proto', $headers['content-type']);
        $this->assertSame('trailers', $headers['te']);
        $this->assertSame('identity,gzip', $headers['grpc-accept-encoding']);
        $this->assertSame('gzip', $headers['grpc-encoding']);
        $this->assertSame('example.test:8443', $headers['host']);
        $this->assertSame('first,second', $headers['x-default']);
        $this->assertSame('value', $headers['x-call']);
        $this->assertMatchesRegularExpression('/^[0-9]{1,8}[HMSmun]$/', $headers['grpc-timeout']);
        $this->assertMatchesRegularExpression(
            '#^grpc-php-hypervel/.+ \(PHP/[0-9.]+; Swoole/[0-9.]+\)$#',
            $headers['user-agent'],
        );

        $decoder = new FrameDecoder(Compression::Gzip, 1024);
        $payloads = iterator_to_array($decoder->push($request->getBody()), false);
        $decoder->finish();

        $this->assertCount(1, $payloads);
        $decoded = MessageSerializer::deserialize([StringValue::class, 'decode'], $payloads[0]);
        $this->assertSame('request', $decoded->getValue());
    }

    public function testStartsEveryCallShapeWithIndependentRequestAndResponseFlags(): void
    {
        $engineClient = new ClientCallClient;
        $client = $this->client(new ClientCallClientFactory($engineClient));
        $argument = (new StringValue)->setValue('request');
        $deserialize = [StringValue::class, 'decode'];

        $unary = $client->unary('/testing.Service/Unary', $argument, $deserialize);
        $serverStream = $client->serverStream('/testing.Service/ServerStream', $argument, $deserialize);
        $clientStream = $client->clientStream('/testing.Service/ClientStream', $deserialize);
        $bidi = $client->bidi('/testing.Service/BidiStream', $deserialize);

        $this->assertInstanceOf(UnaryCall::class, $unary);
        $this->assertInstanceOf(ServerStreamingCall::class, $serverStream);
        $this->assertInstanceOf(ClientStreamingCall::class, $clientStream);
        $this->assertInstanceOf(BidiStreamingCall::class, $bidi);
        $this->assertSame(
            [false, false, true, true],
            array_map(
                static fn ($request): bool => $request->isPipeline(),
                $engineClient->sentRequests,
            ),
        );
        $this->assertSame(
            [true, true, true, true],
            array_map(
                static fn ($request): bool => $request->usesPipelineRead(),
                $engineClient->sentRequests,
            ),
        );
        $this->assertNotSame('', $engineClient->sentRequests[0]->getBody());
        $this->assertNotSame('', $engineClient->sentRequests[1]->getBody());
        $this->assertSame('', $engineClient->sentRequests[2]->getBody());
        $this->assertSame('', $engineClient->sentRequests[3]->getBody());
    }

    public function testMetadataPreparationRunsOnceForEveryCallShape(): void
    {
        $engineClient = new ClientCallClient;
        $client = $this->client(
            new ClientCallClientFactory($engineClient),
            ['metadata' => ['x-shared' => 'default']],
        );
        $client->prepareMetadataUsing = static function (
            array|Metadata $metadata,
            Metadata $prepared,
        ): Metadata {
            return $prepared->with('x-prepared', 'yes');
        };
        $argument = new StringValue;
        $deserialize = [StringValue::class, 'decode'];
        $metadata = ['x-shared' => 'call'];

        $client->unary('/testing.Service/Unary', $argument, $deserialize, $metadata);
        $client->serverStream('/testing.Service/ServerStream', $argument, $deserialize, $metadata);
        $client->clientStream('/testing.Service/ClientStream', $deserialize, $metadata);
        $client->bidi('/testing.Service/BidiStream', $deserialize, $metadata);

        $this->assertSame(4, $client->prepareMetadataCalls);

        foreach ($engineClient->sentRequests as $request) {
            $this->assertSame('default,call', $request->getHeaders()['x-shared']);
            $this->assertSame('yes', $request->getHeaders()['x-prepared']);
        }
    }

    public function testMetadataPreparationCanInspectInputAndReturnReplacement(): void
    {
        $engineClient = new ClientCallClient;
        $client = $this->client(
            new ClientCallClientFactory($engineClient),
            ['metadata' => ['x-default' => 'default']],
        );
        $inspectedMetadata = null;
        $client->prepareMetadataUsing = static function (
            array|Metadata $metadata,
            Metadata $prepared,
        ) use (&$inspectedMetadata): Metadata {
            $inspectedMetadata = $metadata;

            return Metadata::make(['x-replacement' => 'replacement']);
        };

        $client->unary(
            '/testing.Service/Unary',
            new StringValue,
            [StringValue::class, 'decode'],
            ['x-call' => 'call'],
        );

        $headers = $engineClient->sentRequests[0]->getHeaders();

        $this->assertSame(['x-call' => 'call'], $inspectedMetadata);
        $this->assertSame('replacement', $headers['x-replacement']);
        $this->assertArrayNotHasKey('x-default', $headers);
        $this->assertArrayNotHasKey('x-call', $headers);
        $this->assertSame(1, $client->prepareMetadataCalls);
    }

    public function testUnaryRetryReusesPreparedMetadataSnapshot(): void
    {
        $engineClient = new ClientCallClient;
        $client = $this->client(new ClientCallClientFactory($engineClient), [
            'retry' => new RetryPolicy(maxAttempts: 2),
        ]);
        $client->ambientMetadata = 'first';
        $call = $client->unary(
            '/testing.Service/Unary',
            new StringValue,
            [StringValue::class, 'decode'],
        );
        $client->ambientMetadata = 'second';

        $results = parallel([
            'wait' => static fn (): Message => $call->wait(),
            'responses' => function () use ($engineClient): null {
                $engineClient->respond($this->trailersOnly(
                    1,
                    StatusCode::Unavailable,
                    ['grpc-retry-pushback-ms' => '0'],
                ));

                while (count($engineClient->sentRequests) < 2) {
                    usleep(100);
                }

                $this->respondSuccessfully($engineClient, 3, 'retried');

                return null;
            },
        ]);

        $this->assertSame('retried', $results['wait']->getValue());
        $this->assertSame(1, $client->prepareMetadataCalls);
        $this->assertSame(
            ['first', 'first'],
            array_map(
                static fn ($request): string => $request->getHeaders()['x-ambient'],
                $engineClient->sentRequests,
            ),
        );
    }

    public function testServerStreamingRetryReusesPreparedMetadataSnapshot(): void
    {
        $engineClient = new ClientCallClient;
        $client = $this->client(new ClientCallClientFactory($engineClient), [
            'retry' => new RetryPolicy(maxAttempts: 2),
        ]);
        $client->ambientMetadata = 'first';
        $call = $client->serverStream(
            '/testing.Service/ServerStream',
            new StringValue,
            [StringValue::class, 'decode'],
        );
        $client->ambientMetadata = 'second';

        $results = parallel([
            'read' => static fn (): ?Message => $call->read(),
            'responses' => function () use ($engineClient): null {
                $engineClient->respond($this->trailersOnly(
                    1,
                    StatusCode::Unavailable,
                    ['grpc-retry-pushback-ms' => '0'],
                ));

                while (count($engineClient->sentRequests) < 2) {
                    usleep(100);
                }

                $this->respondSuccessfully($engineClient, 3, 'retried');

                return null;
            },
        ]);

        $this->assertSame('retried', $results['read']?->getValue());
        $this->assertSame(1, $client->prepareMetadataCalls);
        $this->assertSame(
            ['first', 'first'],
            array_map(
                static fn ($request): string => $request->getHeaders()['x-ambient'],
                $engineClient->sentRequests,
            ),
        );
    }

    public function testTranslatesTlsOptionsBeforeTheLazyEngineConnection(): void
    {
        $engineClient = new ClientCallClient;
        $factory = new ClientCallClientFactory($engineClient);
        $client = $this->client($factory, [
            'connect_timeout' => 2.5,
            'tls' => [
                'verify_peer' => false,
                'ca_file' => __FILE__,
                'certificate' => __FILE__,
                'private_key' => __FILE__,
                'passphrase' => 'secret',
                'server_name' => 'peer.example.test',
            ],
            'swoole' => [
                'write_timeout' => 9.0,
                'socket_buffer_size' => 4096,
            ],
        ], 'https://example.test:8443');

        $this->assertSame([], $factory->calls);

        $client->unary(
            '/testing.Service/Unary',
            new StringValue,
            [StringValue::class, 'decode'],
        );

        $this->assertSame([[
            'host' => 'example.test',
            'port' => 8443,
            'ssl' => true,
            'settings' => [
                'connect_timeout' => 2.5,
                'write_timeout' => 9.0,
                'ssl_verify_peer' => false,
                'ssl_cafile' => __FILE__,
                'ssl_cert_file' => __FILE__,
                'ssl_key_file' => __FILE__,
                'ssl_passphrase' => 'secret',
                'ssl_host_name' => 'peer.example.test',
                'socket_buffer_size' => 4096,
            ],
        ]], $factory->calls);
    }

    #[DataProvider('invalidClientOptions')]
    public function testRejectsInvalidClientOptions(array $options, string $message): void
    {
        $this->bindFactory(new ClientCallClientFactory(new ClientCallClient));

        try {
            new TestingBaseClient('example.test:50051', $options);
            $this->fail('Expected invalid gRPC client options to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    /**
     * Return invalid client options and their diagnostic fragments.
     *
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidClientOptions(): iterable
    {
        yield 'unknown' => [['unknown' => true], 'unknown'];
        yield 'connections' => [['connections' => 0], 'positive integer'];
        yield 'connect timeout' => [['connect_timeout' => INF], 'positive finite'];
        yield 'timeout' => [['timeout' => 0], 'positive finite'];
        yield 'receive size' => [['max_receive_message_size' => 0], 'positive integer'];
        yield 'send frame range' => [['max_send_message_size' => 0x100000000], 'unsigned 32-bit'];
        yield 'metadata size' => [['max_metadata_size' => '8192'], 'positive integer'];
        yield 'buffer messages' => [['max_buffered_messages' => 0], 'positive integer'];
        yield 'buffer bytes' => [[
            'max_receive_message_size' => 1024,
            'max_buffered_bytes' => 512,
        ], 'cannot be smaller'];
        yield 'compression' => [['compression' => 'deflate'], 'identity, gzip'];
        yield 'retry' => [['retry' => true], 'RetryPolicy'];
        yield 'metadata' => [['metadata' => null], 'array or Metadata'];
        yield 'tls shape' => [['tls' => null], 'must be an array'];
        yield 'tls unknown' => [['tls' => ['unknown' => true]], 'unknown'];
        yield 'tls enabled' => [['tls' => ['enabled' => 1]], 'boolean or null'];
        yield 'tls verify' => [['tls' => ['verify_peer' => null]], 'must be a boolean'];
        yield 'tls pair' => [['tls' => ['certificate' => __FILE__]], 'supplied together'];
        yield 'tls passphrase without key' => [[
            'tls' => ['passphrase' => 'secret'],
        ], 'passphrase requires a certificate and private key'];
        yield 'tls file' => [['tls' => ['ca_file' => '/missing/grpc-ca.pem']], 'not readable'];
        yield 'tls server name' => [['tls' => ['server_name' => '']], 'cannot be empty'];
        yield 'swoole shape' => [['swoole' => null], 'must be an array'];
        yield 'swoole key' => [['swoole' => [0 => true]], 'keys must be strings'];
        yield 'duplicate native connect timeout' => [[
            'swoole' => ['connect_timeout' => 1.0],
        ], 'connect_timeout setting is owned'];
        yield 'zero native write timeout' => [[
            'swoole' => ['write_timeout' => 0],
        ], 'write_timeout setting must be a positive finite'];
        yield 'infinite native write timeout' => [[
            'swoole' => ['write_timeout' => INF],
        ], 'write_timeout setting must be a positive finite'];
        yield 'invalid native timeout fallback' => [[
            'swoole' => ['timeout' => -1],
        ], 'timeout setting must be a positive finite'];
    }

    public function testNativeTimeoutSuppliesTheBaselineWriteTimeout(): void
    {
        $factory = new ClientCallClientFactory(new ClientCallClient);
        $client = $this->client($factory, [
            'swoole' => ['timeout' => 7.5],
        ]);

        $client->unary(
            '/testing.Service/Unary',
            new StringValue,
            [StringValue::class, 'decode'],
        );

        $this->assertSame(7.5, $factory->calls[0]['settings']['write_timeout']);
        $this->assertSame(7.5, $factory->calls[0]['settings']['timeout']);
    }

    public function testSpecificNativeWriteTimeoutTakesPrecedenceOverTheGeneralTimeout(): void
    {
        $factory = new ClientCallClientFactory(new ClientCallClient);
        $client = $this->client($factory, [
            'swoole' => [
                'timeout' => 7.5,
                'write_timeout' => 2.5,
            ],
        ]);

        $client->unary(
            '/testing.Service/Unary',
            new StringValue,
            [StringValue::class, 'decode'],
        );

        $this->assertSame(2.5, $factory->calls[0]['settings']['write_timeout']);
        $this->assertSame(7.5, $factory->calls[0]['settings']['timeout']);
    }

    public function testDeadlineCapsLazyConnectionAndRefreshesTheTimeoutBeforeSend(): void
    {
        $engineClient = new ClientCallClient;
        $factory = new DelayedClientCallClientFactory(50_000, $engineClient);
        $client = $this->client($factory, [
            'connect_timeout' => 2.5,
            'swoole' => ['write_timeout' => 0.75],
        ]);

        $client->unary(
            '/testing.Service/Unary',
            new StringValue,
            [StringValue::class, 'decode'],
            options: ['timeout' => 0.2],
        );

        $settings = $factory->calls[0]['settings'];
        $sendTimeout = $engineClient->sendTimeouts[0];
        $headerTimeout = Timeout::decode(
            $engineClient->sentRequests[0]->getHeaders()['grpc-timeout'],
        );

        $this->assertGreaterThan(0, $settings['connect_timeout']);
        $this->assertLessThanOrEqual(0.2, $settings['connect_timeout']);
        $this->assertGreaterThan(0, $settings['write_timeout']);
        $this->assertLessThanOrEqual(0.2, $settings['write_timeout']);
        $this->assertNotNull($sendTimeout);
        $this->assertGreaterThan(0, $sendTimeout);
        $this->assertLessThan($settings['connect_timeout'], $sendTimeout);
        $this->assertGreaterThanOrEqual($sendTimeout, $headerTimeout);
        $this->assertLessThanOrEqual($sendTimeout + 0.001, $headerTimeout);
    }

    public function testDeadlineExpiringAfterAHealthyConnectKeepsTheConnectionReusable(): void
    {
        $engineClient = new ClientCallClient;
        $factory = new DelayedClientCallClientFactory(30_000, $engineClient);
        $client = $this->client($factory);
        $expired = $client->unary(
            '/testing.Service/Unary',
            new StringValue,
            [StringValue::class, 'decode'],
            options: ['timeout' => 0.01],
        );

        $this->assertSame(StatusCode::DeadlineExceeded, $expired->status()->code());
        $this->assertSame([], $engineClient->sentRequests);
        $this->assertSame(0, $engineClient->closeCount);

        $client->unary(
            '/testing.Service/Unary',
            new StringValue,
            [StringValue::class, 'decode'],
        );

        $this->assertCount(1, $factory->calls);
        $this->assertCount(1, $engineClient->sentRequests);
    }

    public function testRejectsExplicitTlsOnlyOptionsForAPlaintextTarget(): void
    {
        $this->bindFactory(new ClientCallClientFactory(new ClientCallClient));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('plaintext target: verify_peer');

        new TestingBaseClient('http://example.test:50051', [
            'tls' => ['verify_peer' => false],
        ]);
    }

    public function testExplicitNullCallOptionsDisableEveryNullableClientDefault(): void
    {
        $engineClient = new ClientCallClient;
        $client = $this->client(new ClientCallClientFactory($engineClient), [
            'timeout' => 5.0,
            'compression' => Compression::Gzip,
            'retry' => new RetryPolicy(maxAttempts: 2),
        ]);
        $call = $client->unary(
            '/testing.Service/Unary',
            new StringValue,
            [StringValue::class, 'decode'],
            options: [
                'timeout' => null,
                'compression' => null,
                'retry' => null,
            ],
        );
        $headers = $engineClient->sentRequests[0]->getHeaders();

        $this->assertArrayNotHasKey('grpc-timeout', $headers);
        $this->assertArrayNotHasKey('grpc-encoding', $headers);

        $engineClient->respond($this->trailersOnly(1, StatusCode::Unavailable));

        $this->assertSame(StatusCode::Unavailable, $call->status()->code());
        $this->assertCount(1, $engineClient->sentRequests);
    }

    public function testConstructorRetryIsInheritedByUnaryCallsAndReportsPreviousAttempts(): void
    {
        $engineClient = new ClientCallClient;
        $client = $this->client(new ClientCallClientFactory($engineClient), [
            'retry' => new RetryPolicy(maxAttempts: 2),
        ]);
        $call = $client->unary(
            '/testing.Service/Unary',
            new StringValue,
            [StringValue::class, 'decode'],
        );

        $results = parallel([
            'wait' => static fn (): Message => $call->wait(),
            'responses' => function () use ($engineClient): null {
                $engineClient->respond($this->trailersOnly(
                    1,
                    StatusCode::Unavailable,
                    ['grpc-retry-pushback-ms' => '0'],
                ));

                while (count($engineClient->sentRequests) < 2) {
                    usleep(100);
                }

                $this->respondSuccessfully($engineClient, 3, 'retried');

                return null;
            },
        ]);

        $this->assertSame('retried', $results['wait']->getValue());
        $this->assertArrayNotHasKey(
            'grpc-previous-rpc-attempts',
            $engineClient->sentRequests[0]->getHeaders(),
        );
        $this->assertSame(
            '1',
            $engineClient->sentRequests[1]->getHeaders()['grpc-previous-rpc-attempts'],
        );
    }

    public function testStreamingRequestShapesIgnoreTheConstructorRetryAndRejectCallRetryKeys(): void
    {
        $engineClient = new ClientCallClient;
        $client = $this->client(new ClientCallClientFactory($engineClient), [
            'retry' => new RetryPolicy(maxAttempts: 2),
        ]);
        $clientStreaming = $client->clientStream(
            '/testing.Service/ClientStream',
            [StringValue::class, 'decode'],
        );
        $engineClient->respond($this->trailersOnly(1, StatusCode::Unavailable));

        $this->assertSame(StatusCode::Unavailable, $clientStreaming->status()->code());
        $this->assertCount(1, $engineClient->sentRequests);

        foreach (['client', 'bidi'] as $shape) {
            try {
                $shape === 'client'
                    ? $client->clientStream(
                        '/testing.Service/ClientStream',
                        [StringValue::class, 'decode'],
                        options: ['retry' => null],
                    )
                    : $client->bidi(
                        '/testing.Service/BidiStream',
                        [StringValue::class, 'decode'],
                        options: ['retry' => null],
                    );
                $this->fail("Expected {$shape} retry options to fail.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('do not support retries', $exception->getMessage());
            }
        }

        $this->assertCount(1, $engineClient->sentRequests);
    }

    public function testConnectionRoundRobinStateBelongsToEachClientInstance(): void
    {
        $engineClients = [
            new ClientCallClient,
            new ClientCallClient,
            new ClientCallClient,
            new ClientCallClient,
        ];
        $this->bindFactory(new ClientCallClientFactory(...$engineClients));
        $first = $this->newClient(options: ['connections' => 2]);
        $second = $this->newClient(options: ['connections' => 2]);

        for ($index = 0; $index < 3; ++$index) {
            $first->unary(
                '/testing.Service/Unary',
                new StringValue,
                [StringValue::class, 'decode'],
            );
            $second->unary(
                '/testing.Service/Unary',
                new StringValue,
                [StringValue::class, 'decode'],
            );
        }

        $this->assertSame(
            [2, 2, 1, 1],
            array_map(static fn (ClientCallClient $client): int => count($client->sentRequests), $engineClients),
        );
    }

    public function testRetiringConnectionIsReplacedWithoutAbandoningHealthyAcceptedCalls(): void
    {
        $retiringClient = new ClientCallClient;
        $replacementClient = new ClientCallClient;
        $client = $this->client(
            new ClientCallClientFactory($retiringClient, $replacementClient),
            [
                'max_receive_message_size' => 64,
                'max_buffered_bytes' => 1024,
            ],
        );
        $streaming = $client->serverStream(
            '/testing.Service/ServerStream',
            new StringValue,
            [StringValue::class, 'decode'],
        );
        $healthy = $client->unary(
            '/testing.Service/Unary',
            new StringValue,
            [StringValue::class, 'decode'],
        );
        $encoder = new FrameEncoder(2048);
        $retiringClient->respond(new Response(
            1,
            200,
            [
                'content-type' => 'application/grpc+proto',
                'grpc-encoding' => 'gzip',
            ],
            $encoder->encode(
                $this->serialized(str_repeat('x', 1_000)),
                Compression::Gzip,
            ),
            true,
        ));

        $this->assertSame(StatusCode::ResourceExhausted, $streaming->status()->code());

        $replacement = $client->unary(
            '/testing.Service/Unary',
            new StringValue,
            [StringValue::class, 'decode'],
        );

        $this->assertCount(2, $retiringClient->sentRequests);
        $this->assertCount(1, $replacementClient->sentRequests);

        $this->respondSuccessfully($retiringClient, 3, 'healthy');
        $this->respondSuccessfully($replacementClient, 1, 'replacement');

        $this->assertSame('healthy', $healthy->wait()->getValue());
        $this->assertSame('replacement', $replacement->wait()->getValue());

        $client->close();

        $this->assertSame(1, $retiringClient->closeCount);
        $this->assertSame(1, $replacementClient->closeCount);
    }

    public function testLocalSendAndMetadataLimitFailuresAreSynchronous(): void
    {
        $sendFactory = new ClientCallClientFactory(new ClientCallClient);
        $sendClient = $this->client($sendFactory, ['max_send_message_size' => 1]);

        try {
            $sendClient->unary(
                '/testing.Service/Unary',
                (new StringValue)->setValue('large'),
                [StringValue::class, 'decode'],
            );
            $this->fail('Expected the outbound message limit to fail synchronously.');
        } catch (RpcException $exception) {
            $this->assertSame(StatusCode::ResourceExhausted, $exception->status()->code());
        }

        $this->assertSame([], $sendFactory->calls);

        $metadataFactory = new ClientCallClientFactory(new ClientCallClient);
        $metadataClient = $this->client($metadataFactory, ['max_metadata_size' => 1]);

        try {
            $metadataClient->unary(
                '/testing.Service/Unary',
                new StringValue,
                [StringValue::class, 'decode'],
            );
            $this->fail('Expected the outbound metadata limit to fail synchronously.');
        } catch (RpcException $exception) {
            $this->assertSame(StatusCode::ResourceExhausted, $exception->status()->code());
        }

        $this->assertSame([], $metadataFactory->calls);
    }

    public function testCloseIsIdempotentTerminalAndRequiresNoDestructor(): void
    {
        $engineClient = new ClientCallClient;
        $client = $this->client(new ClientCallClientFactory($engineClient));
        $client->unary(
            '/testing.Service/Unary',
            new StringValue,
            [StringValue::class, 'decode'],
        );

        $client->close();
        $client->close();

        $this->assertSame(1, $engineClient->closeCount);
        $this->assertFalse((new ReflectionClass(BaseClient::class))->hasMethod('__destruct'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('client is closed');

        $client->unary(
            '/testing.Service/Unary',
            new StringValue,
            [StringValue::class, 'decode'],
        );
    }

    public function testGeneratedRequestMethodsRetainTheirProtectedNamesAndArgumentOrder(): void
    {
        foreach ([
            '_simpleRequest' => ['method', 'argument', 'deserialize', 'metadata', 'options'],
            '_clientStreamRequest' => ['method', 'deserialize', 'metadata', 'options'],
            '_serverStreamRequest' => ['method', 'argument', 'deserialize', 'metadata', 'options'],
            '_bidiRequest' => ['method', 'deserialize', 'metadata', 'options'],
        ] as $method => $parameters) {
            $reflection = new ReflectionMethod(BaseClient::class, $method);

            $this->assertTrue($reflection->isProtected());
            $this->assertSame(
                $parameters,
                array_map(static fn ($parameter): string => $parameter->getName(), $reflection->getParameters()),
            );
        }
    }

    /**
     * Bind the engine factory and create a tracked client.
     *
     * @param array<string, mixed> $options
     */
    private function client(
        ClientCallClientFactory $factory,
        array $options = [],
        string $target = 'example.test:50051',
    ): TestingBaseClient {
        $this->bindFactory($factory);

        return $this->newClient($target, $options);
    }

    /**
     * Bind an engine factory in an isolated container.
     */
    private function bindFactory(ClientCallClientFactory $factory): void
    {
        $container = Container::setInstance(new Container);
        $container->instance(ClientFactoryInterface::class, $factory);
    }

    /**
     * Create a tracked client from the currently bound engine factory.
     *
     * @param array<string, mixed> $options
     */
    private function newClient(
        string $target = 'example.test:50051',
        array $options = [],
    ): TestingBaseClient {
        $client = new TestingBaseClient($target, $options);
        $this->clients[] = $client;

        return $client;
    }

    /**
     * Deliver one successful unary response.
     */
    private function respondSuccessfully(
        ClientCallClient $client,
        int $streamId,
        string $value,
    ): void {
        $encoder = new FrameEncoder(1024);
        $client->respond(new Response(
            $streamId,
            200,
            ['content-type' => 'application/grpc+proto'],
            $encoder->encode($this->serialized($value)),
            true,
        ));
        $client->respond($this->trailersOnly($streamId, StatusCode::Ok));
    }

    /**
     * Build a trailers-only response event.
     *
     * @param array<string, string> $headers
     */
    private function trailersOnly(
        int $streamId,
        StatusCode $status,
        array $headers = [],
    ): Response {
        return new Response(
            $streamId,
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
     * Serialize a string wrapper fixture.
     */
    private function serialized(string $value): string
    {
        return (new StringValue)->setValue($value)->serializeToString();
    }
}

class TestingBaseClient extends BaseClient
{
    public int $prepareMetadataCalls = 0;

    public ?string $ambientMetadata = null;

    /** @var null|Closure(array<string, list<string>|string>|Metadata, Metadata): Metadata */
    public ?Closure $prepareMetadataUsing = null;

    /**
     * Start a unary fixture call.
     *
     * @param array{class-string<Message>, string}|callable(string): Message $deserialize
     * @param array<string, list<string>|string>|Metadata $metadata
     * @param array<string, mixed> $options
     */
    public function unary(
        string $method,
        Message $argument,
        array|callable $deserialize,
        array|Metadata $metadata = [],
        array $options = [],
    ): UnaryCall {
        return $this->_simpleRequest($method, $argument, $deserialize, $metadata, $options);
    }

    /**
     * Start a client-streaming fixture call.
     *
     * @param array{class-string<Message>, string}|callable(string): Message $deserialize
     * @param array<string, list<string>|string>|Metadata $metadata
     * @param array<string, mixed> $options
     */
    public function clientStream(
        string $method,
        array|callable $deserialize,
        array|Metadata $metadata = [],
        array $options = [],
    ): ClientStreamingCall {
        return $this->_clientStreamRequest($method, $deserialize, $metadata, $options);
    }

    /**
     * Start a server-streaming fixture call.
     *
     * @param array{class-string<Message>, string}|callable(string): Message $deserialize
     * @param array<string, list<string>|string>|Metadata $metadata
     * @param array<string, mixed> $options
     */
    public function serverStream(
        string $method,
        Message $argument,
        array|callable $deserialize,
        array|Metadata $metadata = [],
        array $options = [],
    ): ServerStreamingCall {
        return $this->_serverStreamRequest($method, $argument, $deserialize, $metadata, $options);
    }

    /**
     * Start a bidirectional-streaming fixture call.
     *
     * @param array{class-string<Message>, string}|callable(string): Message $deserialize
     * @param array<string, list<string>|string>|Metadata $metadata
     * @param array<string, mixed> $options
     */
    public function bidi(
        string $method,
        array|callable $deserialize,
        array|Metadata $metadata = [],
        array $options = [],
    ): BidiStreamingCall {
        return $this->_bidiRequest($method, $deserialize, $metadata, $options);
    }

    /**
     * Prepare metadata for a fixture RPC.
     *
     * @param array<string, list<string>|string>|Metadata $metadata
     */
    protected function prepareMetadata(array|Metadata $metadata): Metadata
    {
        ++$this->prepareMetadataCalls;
        $prepared = parent::prepareMetadata($metadata);

        if ($this->prepareMetadataUsing !== null) {
            return ($this->prepareMetadataUsing)($metadata, $prepared);
        }

        return $this->ambientMetadata === null
            ? $prepared
            : $prepared->with('x-ambient', $this->ambientMetadata);
    }
}

class DelayedClientCallClientFactory extends ClientCallClientFactory
{
    private bool $delayed = false;

    public function __construct(
        private readonly int $delayMicroseconds,
        ClientCallClient ...$clients,
    ) {
        parent::__construct(...$clients);
    }

    public function make(
        string $host,
        int $port = 80,
        bool $ssl = false,
        array $settings = [],
    ): ClientInterface {
        if (! $this->delayed) {
            $this->delayed = true;
            usleep($this->delayMicroseconds);
        }

        return parent::make($host, $port, $ssl, $settings);
    }
}
