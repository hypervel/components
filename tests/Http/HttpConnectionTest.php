<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Multiplexing;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\TransportSharing;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\Client\PendingRequest;
use Hypervel\Http\Client\TransportHandler;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Support\Arr;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;

use function Hypervel\Coroutine\parallel;
use function Hypervel\Coroutine\run;

class HttpConnectionTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testConnectionReusesOneLowLevelHandlerAcrossFreshClientsAndConcurrentRequests(): void
    {
        $factory = new RecordingHttpConnectionFactory;
        $factory->registerConnection('api');

        $firstHandler = $factory->getConnectionHandler('api');
        $secondHandler = $factory->getConnectionHandler('api');

        $this->assertSame($firstHandler, $secondHandler);
        $this->assertCount(1, $factory->createdHandlerOptions);

        $firstClient = $factory->connection('api')->buildClient();
        $secondClient = $factory->connection('api')->buildClient();

        $this->assertNotSame($firstClient, $secondClient);

        $responses = null;
        run(function () use ($factory, &$responses): void {
            $responses = parallel(array_map(
                fn (int $index) => fn () => $factory->connection('api')->get("https://example.com/{$index}")->body(),
                range(1, 10),
            ));
        });

        $this->assertSame(array_fill(0, 10, 'handler-1'), $responses);
        $this->assertCount(1, $factory->createdHandlerOptions);
        $this->assertCount(10, $factory->invocations);
    }

    public function testEachRequestKeepsItsOwnMiddlewareStack(): void
    {
        $factory = new RecordingHttpConnectionFactory;
        $factory->registerConnection('api');

        $factory->connection('api')
            ->withRequestMiddleware(fn (RequestInterface $request) => $request->withHeader('X-Request', 'first'))
            ->get('https://example.com/first');

        $factory->connection('api')
            ->withRequestMiddleware(fn (RequestInterface $request) => $request->withHeader('X-Request', 'second'))
            ->get('https://example.com/second');

        $this->assertSame('first', $factory->invocations[0]['request']->getHeaderLine('X-Request'));
        $this->assertSame('second', $factory->invocations[1]['request']->getHeaderLine('X-Request'));
    }

    public function testRequestHandlerOverridesTheConnectionTransport(): void
    {
        $factory = new RecordingHttpConnectionFactory;
        $factory->registerConnection('api', ['timeout' => 12]);
        $receivedOptions = null;

        $response = $factory->connection('api')
            ->setHandler(function (RequestInterface $request, array $options) use (&$receivedOptions): PromiseInterface {
                $receivedOptions = $options;

                return Create::promiseFor(new Psr7Response(200, [], 'custom'));
            })
            ->get('https://example.com');

        $this->assertSame('custom', $response->body());
        $this->assertSame(12, $receivedOptions['timeout']);
        $this->assertCount(0, $factory->createdHandlerOptions);
    }

    public function testOptionLayersHaveDeterministicPrecedenceRegardlessOfChainingOrder(): void
    {
        $factory = new RecordingHttpConnectionFactory;
        $factory->globalOptions([
            'timeout' => 1,
            'headers' => ['X-Level' => 'global', 'X-Global' => 'yes'],
        ]);
        $factory->registerConnection('api', [
            'timeout' => 2,
            'headers' => ['X-Level' => 'preset', 'X-Preset' => 'yes'],
        ]);

        $factory->timeout(4)->connection('api', [
            'timeout' => 3,
            'headers' => ['X-Level' => 'override', 'X-Override' => 'yes'],
        ])->get('https://example.com/first');

        $factory->connection('api', [
            'timeout' => 3,
            'headers' => ['X-Level' => 'override', 'X-Override' => 'yes'],
        ])->timeout(4)->get('https://example.com/second');

        foreach ($factory->invocations as $invocation) {
            $this->assertSame(4, $invocation['options']['timeout']);
            $this->assertSame('override', $invocation['request']->getHeaderLine('X-Level'));
            $this->assertSame('yes', $invocation['request']->getHeaderLine('X-Global'));
            $this->assertSame('yes', $invocation['request']->getHeaderLine('X-Override'));
            $this->assertSame('', $invocation['request']->getHeaderLine('X-Preset'));
        }
    }

    public function testRegisteredPresetOverridesGlobalsAndEmptyPerCallConfigClearsPreset(): void
    {
        $factory = new RecordingHttpConnectionFactory;
        $factory->globalOptions(['timeout' => 1, 'headers' => ['X-Global' => 'yes']]);
        $factory->registerConnection('api', ['timeout' => 2, 'headers' => ['X-Preset' => 'yes']]);

        $factory->connection('api')->get('https://example.com/preset');
        $factory->connection('api', [])->get('https://example.com/cleared');

        $this->assertSame(2, $factory->invocations[0]['options']['timeout']);
        $this->assertSame('yes', $factory->invocations[0]['request']->getHeaderLine('X-Preset'));
        $this->assertSame(1, $factory->invocations[1]['options']['timeout']);
        $this->assertSame('', $factory->invocations[1]['request']->getHeaderLine('X-Preset'));
        $this->assertSame('yes', $factory->invocations[1]['request']->getHeaderLine('X-Global'));
    }

    public function testSetConnectionConfigRegistersEvenAnEmptyPreset(): void
    {
        $factory = new RecordingHttpConnectionFactory;

        $factory->setConnectionConfig('api', []);

        $this->assertTrue($factory->hasConnection('api'));
        $this->assertSame([], $factory->getConnectionOptions('api'));
        $this->assertInstanceOf(PendingRequest::class, $factory->connection('api'));
    }

    public function testCreateClientRequiresTheRequestOwnedCookieJar(): void
    {
        $parameter = (new ReflectionMethod(Factory::class, 'createClient'))->getParameters()[1];

        $this->assertFalse($parameter->allowsNull());
        $this->assertFalse($parameter->isOptional());
    }

    public function testExplicitClientBypassesConnectionPresetButKeepsGlobalAndFluentOptions(): void
    {
        $factory = new RecordingHttpConnectionFactory;
        $factory->globalOptions(['headers' => ['X-Global' => 'yes']]);
        $factory->registerConnection('api', ['headers' => ['X-Preset' => 'yes']]);
        $receivedRequest = null;
        $client = new Client([
            'handler' => function (RequestInterface $request) use (&$receivedRequest): PromiseInterface {
                $receivedRequest = $request;

                return Create::promiseFor(new Psr7Response(200));
            },
        ]);

        $factory->connection('api')
            ->setClient($client)
            ->withHeader('X-Fluent', 'yes')
            ->get('https://example.com');

        $this->assertSame('yes', $receivedRequest->getHeaderLine('X-Global'));
        $this->assertSame('yes', $receivedRequest->getHeaderLine('X-Fluent'));
        $this->assertSame('', $receivedRequest->getHeaderLine('X-Preset'));
        $this->assertCount(0, $factory->createdHandlerOptions);
    }

    #[DataProvider('registeredReservedOptionsProvider')]
    public function testRegisteredConnectionsRejectReservedOptions(string $option, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The [{$option}] option is not allowed in registered connection configuration.");

        (new Factory)->registerConnection('api', [$option => $value]);
    }

    public static function registeredReservedOptionsProvider(): array
    {
        return [
            'handler' => ['handler', static fn () => null],
            'cookies' => ['cookies', true],
        ];
    }

    public function testRegisteredConnectionsAllowInfrastructureOptions(): void
    {
        $config = [
            'transport' => 'auto',
            'pool' => ['max_objects' => 10],
            'transport_sharing' => TransportSharing::HANDLER_PREFER,
            'multiplex' => Multiplexing::NONE,
        ];

        $factory = new RecordingHttpConnectionFactory;
        $factory->registerConnection('api', $config);
        $factory->getConnectionHandler('api');
        $factory->registerConnection('capped', [
            'max_host_connections' => 5,
            'max_total_connections' => 20,
        ]);
        $factory->getConnectionHandler('capped');

        $this->assertSame($config, $factory->getConnectionConfig('api'));
        $this->assertSame(['multiplex' => Multiplexing::NONE], $factory->getConnectionOptions('api'));
        $this->assertSame(
            [
                [
                    'transport_sharing' => TransportSharing::HANDLER_PREFER,
                    'multiplex' => Multiplexing::NONE,
                ],
                [
                    'max_host_connections' => 5,
                    'max_total_connections' => 20,
                ],
            ],
            $factory->createdHandlerOptions,
        );
    }

    #[DataProvider('contradictoryConnectionPolicyProvider')]
    public function testRegisteredConnectionsRejectContradictoryTransportPolicy(
        array $config,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new Factory)->registerConnection('api', $config);
    }

    public static function contradictoryConnectionPolicyProvider(): array
    {
        return [
            'curl with native pool' => [
                ['transport' => 'curl', 'pool' => []],
                'cannot combine the [curl] transport with native [pool] options',
            ],
            'pool with host cap' => [
                ['pool' => [], 'max_host_connections' => 2],
                'cannot combine [pool] with the Guzzle [max_host_connections] option',
            ],
            'pool with total cap' => [
                ['pool' => [], 'max_total_connections' => 2],
                'cannot combine [pool] with the Guzzle [max_total_connections] option',
            ],
            'pool with required sharing' => [
                ['pool' => [], 'transport_sharing' => TransportSharing::HANDLER_REQUIRE],
                'cannot combine [pool] with required Guzzle [transport_sharing]',
            ],
            'pool has wrong type' => [
                ['pool' => true],
                'connection [pool] option must be an array',
            ],
            'transport has wrong type' => [
                ['transport' => true],
                'connection [transport] option must be a string',
            ],
            'unknown transport' => [
                ['transport' => 'native'],
                'Unsupported HTTP transport [native]',
            ],
        ];
    }

    public function testDefaultTransportAndPoolPolicyAreNormalizedAndValidated(): void
    {
        $factory = new Factory;

        $this->assertSame('curl', $factory->getDefaultTransport());
        $this->assertSame([
            'min_retained_objects' => 0,
            'max_objects' => 10,
            'wait_timeout' => 3.0,
            'max_lifetime' => 60.0,
            'max_idle_time' => 0.0,
            'idle_ttl' => 300.0,
        ], $factory->getDefaultPoolOptions());

        $this->assertSame($factory, $factory->setDefaultTransport('auto'));
        $this->assertSame($factory, $factory->setDefaultPoolOptions([
            'max_objects' => 25,
            'wait_timeout' => 0.5,
        ]));

        $this->assertSame('auto', $factory->getDefaultTransport());
        $this->assertSame([
            'min_retained_objects' => 0,
            'max_objects' => 25,
            'wait_timeout' => 0.5,
            'max_lifetime' => 60.0,
            'max_idle_time' => 0.0,
            'idle_ttl' => 300.0,
        ], $factory->getDefaultPoolOptions());
    }

    public function testPurgeClosesNamedOrAllHandlersAndAllowsFreshResolution(): void
    {
        $factory = new PurgeRecordingHttpConnectionFactory;
        $factory->registerConnection('first');
        $factory->registerConnection('second');

        $default = $factory->getConnectionHandler();
        $first = $factory->getConnectionHandler('first');
        $second = $factory->getConnectionHandler('second');

        $factory->purge('first');

        $this->assertSame(1, $first->closeCalls);
        $this->assertSame(0, $default->closeCalls);
        $this->assertSame(0, $second->closeCalls);
        $this->assertSame($default, $factory->getConnectionHandler());
        $this->assertSame($second, $factory->getConnectionHandler('second'));

        $newFirst = $factory->getConnectionHandler('first');
        $this->assertNotSame($first, $newFirst);

        $factory->purge();

        $this->assertSame(1, $default->closeCalls);
        $this->assertSame(1, $newFirst->closeCalls);
        $this->assertSame(1, $second->closeCalls);
        $this->assertNotSame($default, $factory->getConnectionHandler());
        $this->assertNotSame($newFirst, $factory->getConnectionHandler('first'));
        $this->assertNotSame($second, $factory->getConnectionHandler('second'));

        $factory->purge();
    }

    public function testReconfiguringAConnectionPurgesItsResolvedHandler(): void
    {
        $factory = new PurgeRecordingHttpConnectionFactory;
        $factory->registerConnection('api');
        $oldHandler = $factory->getConnectionHandler('api');

        $factory->setConnectionConfig('api', ['timeout' => 12]);

        $this->assertSame(1, $oldHandler->closeCalls);
        $this->assertNotSame($oldHandler, $factory->getConnectionHandler('api'));

        $factory->purge();
    }

    #[DataProvider('universallyReservedOptionsProvider')]
    public function testGlobalOptionsRejectReservedOptionsFromArraysAndClosures(string $option, mixed $value): void
    {
        foreach ([[$option => $value], fn () => [$option => $value]] as $options) {
            $factory = new Factory;
            $factory->globalOptions($options);

            try {
                $factory->createPendingRequest();
                $this->fail("Global option [{$option}] was not rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString(
                    "The [{$option}] option is not allowed in global HTTP client options.",
                    $exception->getMessage(),
                );
            }
        }
    }

    #[DataProvider('universallyReservedOptionsProvider')]
    public function testPerCallConnectionOverridesRejectReservedOptions(string $option, mixed $value): void
    {
        $factory = new Factory;
        $factory->registerConnection('api');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The [{$option}] option is not allowed in per-call HTTP connection options.");

        $factory->connection('api', [$option => $value]);
    }

    #[DataProvider('universallyReservedOptionsProvider')]
    public function testFluentOptionsRejectReservedOptions(string $option, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The [{$option}] option is not allowed in fluent HTTP request options.");

        (new PendingRequest)->withOptions([$option => $value]);
    }

    #[DataProvider('universallyReservedOptionsProvider')]
    public function testSendOptionsRejectReservedOptions(string $option, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The [{$option}] option is not allowed in request options.");

        (new PendingRequest)->send('GET', 'https://example.com', [$option => $value]);
    }

    public static function universallyReservedOptionsProvider(): array
    {
        return [
            'transport' => ['transport', 'auto'],
            'pool' => ['pool', []],
            'handler' => ['handler', static fn () => null],
            'cookies' => ['cookies', true],
            'transport sharing' => ['transport_sharing', TransportSharing::HANDLER_PREFER],
            'maximum host connections' => ['max_host_connections', 5],
            'maximum total connections' => ['max_total_connections', 20],
        ];
    }

    public function testHandlerConstructionOptionsDoNotLeakIntoRequestOptions(): void
    {
        $factory = new RecordingHttpConnectionFactory;
        $factory->registerConnection('api', [
            'transport_sharing' => TransportSharing::HANDLER_PREFER,
            'max_host_connections' => 5,
            'max_total_connections' => 20,
            'multiplex' => Multiplexing::NONE,
            'timeout' => 12,
        ]);

        $factory->connection('api')->get('https://example.com');

        $this->assertSame(
            [[
                'transport_sharing' => TransportSharing::HANDLER_PREFER,
                'max_host_connections' => 5,
                'max_total_connections' => 20,
                'multiplex' => Multiplexing::NONE,
            ]],
            $factory->createdHandlerOptions,
        );
        $this->assertArrayNotHasKey('transport_sharing', $factory->invocations[0]['options']);
        $this->assertArrayNotHasKey('max_host_connections', $factory->invocations[0]['options']);
        $this->assertArrayNotHasKey('max_total_connections', $factory->invocations[0]['options']);
        $this->assertSame(Multiplexing::NONE, $factory->invocations[0]['options']['multiplex']);
        $this->assertSame(12, $factory->invocations[0]['options']['timeout']);
    }

    public function testReregisteringAConnectionSwapsTheHandlerWithoutBreakingAnInflightRequest(): void
    {
        $factory = new DeferredHttpConnectionFactory;
        $factory->registerConnection('api');

        $oldPromise = $factory->connection('api')->async()->get('https://example.com/old');
        $this->assertCount(1, $factory->pending);

        $factory->registerConnection('api', ['timeout' => 12]);
        $newPromise = $factory->connection('api')->async()->get('https://example.com/new');
        $this->assertCount(2, $factory->pending);

        $factory->pending[1]->resolve(new Psr7Response(200, [], 'new-handler'));
        $factory->pending[0]->resolve(new Psr7Response(200, [], 'old-handler'));

        $this->assertSame('new-handler', $newPromise->wait()->body());
        $this->assertSame('old-handler', $oldPromise->wait()->body());
        $this->assertCount(2, $factory->createdHandlerOptions);
    }

    public function testConcurrentRequestsOwnIsolatedCookieJars(): void
    {
        $factory = new RecordingHttpConnectionFactory;
        $factory->registerConnection('api');

        $responses = null;
        run(function () use ($factory, &$responses): void {
            $responses = parallel([
                fn () => $factory->connection('api')
                    ->withHeader('X-Request', 'first')
                    ->withCookies(['session' => 'first'], 'example.com')
                    ->get('https://example.com/first'),
                fn () => $factory->connection('api')
                    ->withHeader('X-Request', 'second')
                    ->withCookies(['session' => 'second'], 'example.com')
                    ->get('https://example.com/second'),
            ]);
        });

        $this->assertCount(2, $responses);

        $cookies = [];
        foreach ($factory->invocations as $invocation) {
            $cookies[$invocation['request']->getHeaderLine('X-Request')] = $invocation['request']->getHeaderLine('Cookie');
        }

        $this->assertSame('session=first', $cookies['first']);
        $this->assertSame('session=second', $cookies['second']);
    }

    public function testRetryChainRetainsCookiesOnItsRequestOwnedJar(): void
    {
        $factory = new RetryCookieHttpConnectionFactory;
        $factory->registerConnection('api');

        $response = $factory->connection('api')
            ->retry(2, 0, throw: false)
            ->get('https://example.com');

        $this->assertSame(200, $response->status());
        $this->assertSame(['', 'session=retry'], $factory->cookieHeaders);
    }
}

class RecordingHttpConnectionFactory extends Factory
{
    public array $createdHandlerOptions = [];

    public array $invocations = [];

    protected function createConnectionHandler(string $identity, array $config): callable
    {
        $options = Arr::only($config, self::CONNECTION_HANDLER_OPTIONS);
        $this->createdHandlerOptions[] = $options;
        $number = count($this->createdHandlerOptions);

        return function (RequestInterface $request, array $requestOptions) use ($number): PromiseInterface {
            $this->invocations[] = [
                'handler' => $number,
                'request' => $request,
                'options' => $requestOptions,
            ];

            return Create::promiseFor(new Psr7Response(200, [], "handler-{$number}"));
        };
    }
}

class DeferredHttpConnectionFactory extends Factory
{
    /** @var Promise[] */
    public array $pending = [];

    public array $createdHandlerOptions = [];

    protected function createConnectionHandler(string $identity, array $config): callable
    {
        $options = Arr::only($config, self::CONNECTION_HANDLER_OPTIONS);
        $this->createdHandlerOptions[] = $options;

        return function (): PromiseInterface {
            $this->pending[] = $promise = new Promise;

            return $promise;
        };
    }
}

class RetryCookieHttpConnectionFactory extends Factory
{
    public array $cookieHeaders = [];

    protected function createConnectionHandler(string $identity, array $config): callable
    {
        return function (RequestInterface $request): PromiseInterface {
            $this->cookieHeaders[] = $request->getHeaderLine('Cookie');

            return count($this->cookieHeaders) === 1
                ? Create::promiseFor(new Psr7Response(500, ['Set-Cookie' => 'session=retry; Path=/']))
                : Create::promiseFor(new Psr7Response(200));
        };
    }
}

class PurgeRecordingHttpConnectionFactory extends Factory
{
    /** @var PurgeRecordingTransportHandler[] */
    public array $createdHandlers = [];

    protected function createConnectionHandler(string $identity, array $config): callable
    {
        return $this->createdHandlers[] = new PurgeRecordingTransportHandler($identity);
    }
}

class PurgeRecordingTransportHandler extends TransportHandler
{
    public int $closeCalls = 0;

    public function __construct(string $identity)
    {
        parent::__construct(
            poolFactory: new PoolManager,
            logicalIdentity: $identity,
            poolOptions: PoolOptions::fromArray(['min_retained_objects' => 0]),
            fallbackHandler: static fn () => Create::promiseFor(new Psr7Response(200)),
        );
    }

    public function close(): void
    {
        ++$this->closeCalls;

        parent::close();
    }
}
