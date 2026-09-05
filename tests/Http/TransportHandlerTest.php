<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\TransferStats;
use Hypervel\Contracts\Engine\Http\ClientInterface as EngineClientInterface;
use Hypervel\Contracts\Engine\Http\RawResponseInterface;
use Hypervel\Engine\Exceptions\HttpClientBusyException;
use Hypervel\Engine\Exceptions\HttpClientException;
use Hypervel\Engine\Exceptions\SocketClosedException;
use Hypervel\Engine\Exceptions\SocketConnectException;
use Hypervel\Engine\Exceptions\SocketTimeoutException;
use Hypervel\Http\Client\SwooleHandler;
use Hypervel\Http\Client\SwooleRequest;
use Hypervel\Http\Client\TransportHandler;
use Hypervel\Http\Client\UnsupportedTransportException;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\Support\Sleep;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

class TransportHandlerTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    /** @var TransportHandler[] */
    private array $handlers = [];

    protected function tearDown(): void
    {
        try {
            foreach ($this->handlers as $handler) {
                $handler->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testCurlModeUsesOnlyTheUntouchedFallback(): void
    {
        $request = new Request('GET', 'http://example.com');
        $response = new Response(200, [], 'fallback');
        $receivedOptions = null;
        $swooleHandler = m::mock(SwooleHandler::class);
        $swooleHandler->shouldNotReceive('prepare');
        $handler = $this->newHandler(
            new PoolManager,
            $swooleHandler,
            transport: 'curl',
            fallbackHandler: function (RequestInterface $receivedRequest, array $options) use (
                $request,
                $response,
                &$receivedOptions,
            ) {
                $this->assertSame($request, $receivedRequest);
                $receivedOptions = $options;

                return Create::promiseFor($response);
            },
        );

        $result = $handler($request, ['synchronous' => true])->wait();

        $this->assertSame($response, $result);
        $this->assertSame(['synchronous' => true], $receivedOptions);
    }

    public function testAutoFallsBackWhenTheRequestIsNotNativeCompatible(): void
    {
        $request = new Request('GET', 'http://example.com');
        $response = new Response(200, [], 'fallback');
        $swooleHandler = m::mock(SwooleHandler::class);
        $swooleHandler->shouldReceive('prepare')->once()->andReturn(
            'asynchronous requests require the Guzzle transport',
        );
        $swooleHandler->shouldNotReceive('send');
        $handler = $this->newHandler(
            new PoolManager,
            $swooleHandler,
            fallbackHandler: static fn () => Create::promiseFor($response),
        );

        $this->assertSame($response, $handler($request, ['synchronous' => false])->wait());
    }

    public function testStrictSwooleRejectsInsteadOfFallingBack(): void
    {
        $request = new Request('GET', 'http://example.com');
        $swooleHandler = m::mock(SwooleHandler::class);
        $swooleHandler->shouldReceive('prepare')->once()->andReturn(
            'asynchronous requests require the Guzzle transport',
        );
        $handler = $this->newHandler(
            new PoolManager,
            $swooleHandler,
            transport: 'swoole',
            fallbackHandler: function () {
                $this->fail('The fallback must not run in strict Swoole mode.');
            },
        );

        try {
            $handler($request, ['synchronous' => false])->wait();
            $this->fail('Expected strict Swoole mode to reject the request.');
        } catch (UnsupportedTransportException $exception) {
            $this->assertSame(
                'The Swoole HTTP transport cannot handle this request because '
                . 'asynchronous requests require the Guzzle transport.',
                $exception->getMessage(),
            );
        }
    }

    public function testExplicitModeOverrideDoesNotCreateAnotherOwningHandler(): void
    {
        $request = new Request('GET', 'http://example.com');
        $response = new Response(200);
        $swooleHandler = m::mock(SwooleHandler::class);
        $swooleHandler->shouldNotReceive('prepare');
        $handler = $this->newHandler(
            new PoolManager,
            $swooleHandler,
            transport: 'swoole',
            fallbackHandler: static fn () => Create::promiseFor($response),
        );

        $this->assertSame(
            $response,
            $handler->handleUsing('curl', $request, ['synchronous' => true])->wait(),
        );
    }

    public function testNativeDelayRunsBeforePoolLookupAndStatsIncludeTheWholeOperation(): void
    {
        Sleep::fake();

        $manager = new PoolManager;
        $request = new Request('GET', 'http://example.com');
        $prepared = $this->prepared(delayMicroseconds: 250500);
        $response = new Response(200);
        $stats = null;
        $swooleHandler = m::mock(SwooleHandler::class);
        $swooleHandler->shouldReceive('prepare')->once()->andReturn($prepared);
        $swooleHandler->shouldReceive('send')->once()->andReturnUsing(
            function (TransportHandlerTestClient $client) use ($response): Response {
                $client->connected = true;

                return $response;
            },
        );
        $handler = $this->newHandler($manager, $swooleHandler);

        Sleep::whenFakingSleep(function () use ($manager): void {
            $this->assertSame([], $manager->pools());
        });

        $result = $handler($request, [
            'synchronous' => true,
            'on_stats' => function (TransferStats $receivedStats) use (&$stats): void {
                $stats = $receivedStats;
            },
        ])->wait();

        $this->assertSame($response, $result);
        Sleep::assertSequence([Sleep::for(250500)->microseconds()]);
        $this->assertInstanceOf(TransferStats::class, $stats);
        $this->assertSame($request, $stats->getRequest());
        $this->assertSame($response, $stats->getResponse());
        $this->assertSame('swoole', $stats->getHandlerStat('transport'));
        $this->assertIsFloat($stats->getTransferTime());
        $this->assertGreaterThanOrEqual(0.0, $stats->getTransferTime());

        $pool = array_values($manager->pools())[0];
        $this->assertSame([
            'total' => 1,
            'idle' => 1,
            'borrowed' => 0,
            'closed' => false,
        ], $pool->getStats());
    }

    public function testFallbackStatsPreserveEveryFieldAndAddTheTransport(): void
    {
        $request = new Request('GET', 'http://example.com');
        $response = new Response(200);
        $errorData = new RuntimeException('handler data');
        $receivedStats = null;
        $swooleHandler = m::mock(SwooleHandler::class);
        $handler = $this->newHandler(
            new PoolManager,
            $swooleHandler,
            transport: 'curl',
            fallbackHandler: static function (RequestInterface $request, array $options) use (
                $response,
                $errorData,
            ) {
                $options['on_stats'](new TransferStats(
                    $request,
                    $response,
                    1.25,
                    $errorData,
                    ['existing' => 'yes'],
                ));

                return Create::promiseFor($response);
            },
        );

        $handler($request, [
            'on_stats' => function (TransferStats $stats) use (&$receivedStats): void {
                $receivedStats = $stats;
            },
        ])->wait();

        $this->assertInstanceOf(TransferStats::class, $receivedStats);
        $this->assertSame($request, $receivedStats->getRequest());
        $this->assertSame($response, $receivedStats->getResponse());
        $this->assertSame(1.25, $receivedStats->getTransferTime());
        $this->assertSame($errorData, $receivedStats->getHandlerErrorData());
        $this->assertSame([
            'existing' => 'yes',
            'transport' => 'guzzle',
        ], $receivedStats->getHandlerStats());
    }

    public function testPhysicalIdentityReusesRequestOnlyChangesAndSplitsConnectionChanges(): void
    {
        $manager = new PoolManager;
        $request = new Request('GET', 'http://example.com');
        $preparedRequests = [
            $this->prepared(
                headers: ['X-Request' => ['one']],
                transferSettings: ['timeout' => 1.0],
            ),
            $this->prepared(
                headers: ['X-Request' => ['two']],
                transferSettings: ['timeout' => 2.0],
            ),
            $this->prepared(host: 'other.example.com'),
            $this->prepared(constructionSettings: ['ssl_verify_peer' => false]),
        ];
        $swooleHandler = m::mock(SwooleHandler::class);
        $swooleHandler->shouldReceive('prepare')->times(4)->andReturn(...$preparedRequests);
        $swooleHandler->shouldReceive('send')->times(4)->andReturnUsing(
            static function (TransportHandlerTestClient $client): Response {
                $client->connected = true;

                return new Response(200);
            },
        );
        $handler = $this->newHandler($manager, $swooleHandler);

        for ($index = 0; $index < 4; ++$index) {
            $handler($request, ['synchronous' => true])->wait();
        }

        $this->assertCount(3, $manager->pools());
        $this->assertCount(3, $handler->createdClients);
    }

    public function testClientConstructionReceivesOnlyPhysicalConnectionInputs(): void
    {
        $request = new Request('GET', 'https://example.com');
        $constructionSettings = [
            'ssl_verify_peer' => true,
            'ssl_host_name' => 'example.com',
        ];
        $prepared = $this->prepared(
            host: 'example.com',
            port: 8443,
            ssl: true,
            constructionSettings: $constructionSettings,
            transferSettings: ['timeout' => 2.0],
            headers: ['X-Request' => ['not retained by construction']],
        );
        $handler = $this->newHandler(
            new PoolManager,
            $this->successfulSwooleHandler($prepared),
        );

        $handler($request, ['synchronous' => true])->wait();

        $this->assertSame([[
            'host' => 'example.com',
            'port' => 8443,
            'ssl' => true,
            'construction_settings' => $constructionSettings,
        ]], $handler->createdClientArguments);
    }

    public function testLogicalIdentityKeepsBudgetsSeparate(): void
    {
        $manager = new PoolManager;
        $request = new Request('GET', 'http://example.com');
        $prepared = $this->prepared();
        $firstSwooleHandler = $this->successfulSwooleHandler($prepared);
        $secondSwooleHandler = $this->successfulSwooleHandler($prepared);
        $first = $this->newHandler($manager, $firstSwooleHandler, logicalIdentity: 'first');
        $second = $this->newHandler($manager, $secondSwooleHandler, logicalIdentity: 'second');

        $first($request, ['synchronous' => true])->wait();
        $second($request, ['synchronous' => true])->wait();

        $this->assertCount(2, $manager->pools());
    }

    public function testEngineRuntimeFailureAlwaysDiscardsEvenWhenTheFakeRemainsConnected(): void
    {
        $manager = new PoolManager;
        $request = new Request('GET', 'http://example.com');
        $prepared = $this->prepared();
        $engineException = new HttpClientBusyException('still busy');
        $stats = null;
        $swooleHandler = m::mock(SwooleHandler::class);
        $swooleHandler->shouldReceive('prepare')->once()->andReturn($prepared);
        $swooleHandler->shouldReceive('send')->once()->andThrow($engineException);
        $handler = $this->newHandler($manager, $swooleHandler);
        $handler->newClientsConnected = true;

        try {
            $handler($request, [
                'synchronous' => true,
                'on_stats' => function (TransferStats $receivedStats) use (&$stats): void {
                    $stats = $receivedStats;
                },
            ])->wait();
            $this->fail('Expected the busy failure to reject the request.');
        } catch (HttpClientBusyException $exception) {
            $this->assertSame($engineException, $exception);
        }

        $pool = array_values($manager->pools())[0];
        $this->assertSame(0, $pool->getCurrentObjectNumber());
        $this->assertInstanceOf(TransferStats::class, $stats);
        $this->assertSame($engineException, $stats->getHandlerErrorData());
        $this->assertSame('swoole', $stats->getHandlerStat('transport'));
    }

    public function testConnectedPreIoFailureReleasesAndReusesTheClient(): void
    {
        $manager = new PoolManager;
        $request = new Request('GET', 'http://example.com');
        $prepared = $this->prepared();
        $failure = new UnsupportedTransportException('invalid factory');
        $swooleHandler = m::mock(SwooleHandler::class);
        $swooleHandler->shouldReceive('prepare')->times(3)->andReturn($prepared);
        $attempt = 0;
        $clients = [];
        $swooleHandler->shouldReceive('send')->times(3)->andReturnUsing(
            function (TransportHandlerTestClient $client) use (&$attempt, &$clients, $failure): Response {
                $clients[] = $client;
                ++$attempt;

                if ($attempt === 2) {
                    throw $failure;
                }

                $client->connected = true;

                return new Response(200);
            },
        );
        $handler = $this->newHandler($manager, $swooleHandler);

        $handler($request, ['synchronous' => true])->wait();

        try {
            $handler($request, ['synchronous' => true])->wait();
            $this->fail('Expected the pre-I/O failure.');
        } catch (UnsupportedTransportException $exception) {
            $this->assertSame($failure, $exception);
        }

        $handler($request, ['synchronous' => true])->wait();

        $this->assertSame($clients[0], $clients[1]);
        $this->assertSame($clients[0], $clients[2]);
        $this->assertCount(1, $handler->createdClients);
    }

    public function testDisconnectedNonEngineFailureDiscardsTheClient(): void
    {
        $manager = new PoolManager;
        $request = new Request('GET', 'http://example.com');
        $prepared = $this->prepared();
        $swooleHandler = m::mock(SwooleHandler::class);
        $swooleHandler->shouldReceive('prepare')->once()->andReturn($prepared);
        $swooleHandler->shouldReceive('send')->once()->andThrow(
            new InvalidArgumentException('conversion failed'),
        );
        $handler = $this->newHandler($manager, $swooleHandler);

        try {
            $handler($request, ['synchronous' => true])->wait();
            $this->fail('Expected response conversion to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('conversion failed', $exception->getMessage());
        }

        $pool = array_values($manager->pools())[0];
        $this->assertSame(0, $pool->getCurrentObjectNumber());
    }

    #[DataProvider('engineFailureProvider')]
    public function testMapsEngineFailuresForTheInstalledGuzzleMajor(
        Throwable $engineException,
        string $guzzleSevenClass,
        string $guzzleEightClass,
    ): void {
        $manager = new PoolManager;
        $request = new Request('GET', 'http://example.com');
        $swooleHandler = m::mock(SwooleHandler::class);
        $swooleHandler->shouldReceive('prepare')->once()->andReturn($this->prepared());
        $swooleHandler->shouldReceive('send')->once()->andThrow($engineException);
        $handler = $this->newHandler($manager, $swooleHandler);

        try {
            $handler($request, ['synchronous' => true])->wait();
            $this->fail('Expected the Engine failure to be mapped.');
        } catch (Throwable $exception) {
            // @TODO: Remove Guzzle 7 compatibility when Hypervel requires Guzzle 8;
            // assert only the Guzzle 8 exception class and simplify this provider.
            $expected = GuzzleClientInterface::MAJOR_VERSION >= 8
                ? $guzzleEightClass
                : $guzzleSevenClass;

            $this->assertInstanceOf($expected, $exception);
            $this->assertSame($engineException, $exception->getPrevious());
            $this->assertSame($request, $exception->getRequest());
        }
    }

    public static function engineFailureProvider(): array
    {
        return [
            'connect timeout' => [
                new SocketConnectException('connect timed out', SOCKET_ETIMEDOUT),
                ConnectException::class,
                ConnectTimeoutException::class,
            ],
            'connect error' => [
                new SocketConnectException('connection refused', SOCKET_ECONNREFUSED),
                ConnectException::class,
                ConnectException::class,
            ],
            'request timeout' => [
                new SocketTimeoutException('request timed out', SOCKET_ETIMEDOUT),
                ConnectException::class,
                NetworkTimeoutException::class,
            ],
            'closed connection' => [
                new SocketClosedException('connection closed', SOCKET_ECONNRESET),
                GuzzleRequestException::class,
                NetworkException::class,
            ],
            'unclassified client failure' => [
                new HttpClientException('client failed', SOCKET_EIO),
                GuzzleRequestException::class,
                GuzzleRequestException::class,
            ],
        ];
    }

    public function testPoolExhaustionMapsOnlyToAConnectException(): void
    {
        $manager = new PoolManager;
        $request = new Request('GET', 'http://example.com');
        $prepared = $this->prepared();
        $swooleHandler = $this->successfulSwooleHandler($prepared);
        $handler = $this->newHandler(
            $manager,
            $swooleHandler,
            poolOptions: [
                'max_objects' => 1,
                'wait_timeout' => 0.001,
            ],
        );

        $handler($request, ['synchronous' => true])->wait();
        $pool = array_values($manager->pools())[0];
        $borrowed = $pool->get();

        try {
            $handler($request, ['synchronous' => true])->wait();
            $this->fail('Expected pool exhaustion.');
        } catch (ConnectException $exception) {
            $this->assertStringContainsString('Object pool exhausted', $exception->getMessage());
            $this->assertSame($request, $exception->getRequest());
        } finally {
            $pool->release($borrowed);
        }
    }

    public function testOnStatsFailureRejectsOnceAfterTheClientIsSettled(): void
    {
        $manager = new PoolManager;
        $request = new Request('GET', 'http://example.com');
        $swooleHandler = $this->successfulSwooleHandler($this->prepared());
        $handler = $this->newHandler($manager, $swooleHandler);
        $invocations = 0;

        try {
            $handler($request, [
                'synchronous' => true,
                'on_stats' => function () use (&$invocations): never {
                    ++$invocations;

                    throw new RuntimeException('stats failed');
                },
            ])->wait();
            $this->fail('Expected the stats callback to reject the request.');
        } catch (RuntimeException $exception) {
            $this->assertSame('stats failed', $exception->getMessage());
        }

        $this->assertSame(1, $invocations);
        $pool = array_values($manager->pools())[0];
        $this->assertSame(0, $pool->getBorrowedObjectNumber());
        $this->assertSame(1, $pool->getObjectNumberInPool());
    }

    public function testCloseRemovesOnlyPoolsStillOwnedByThisHandler(): void
    {
        $manager = new PoolManager;
        $request = new Request('GET', 'http://example.com');
        $prepared = $this->prepared();
        $old = $this->newHandler($manager, $this->successfulSwooleHandler($prepared));
        $old($request, ['synchronous' => true])->wait();
        $identity = array_key_first($manager->pools());
        $oldPool = $manager->get($identity);

        $this->assertTrue($manager->remove($identity, $oldPool));

        $replacement = $this->newHandler($manager, $this->successfulSwooleHandler($prepared));
        $replacement($request, ['synchronous' => true])->wait();
        $replacementPool = $manager->get($identity);

        $old->close();

        $this->assertSame($replacementPool, $manager->get($identity));

        $replacement->close();
        $this->assertFalse($manager->has($identity));
    }

    public function testClosedHandlerRejectsAndCloseIsIdempotent(): void
    {
        $handler = $this->newHandler(new PoolManager, m::mock(SwooleHandler::class));
        $handler->close();
        $handler->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The HTTP transport handler is closed.');

        $handler(new Request('GET', 'http://example.com'), [])->wait();
    }

    #[DataProvider('invalidTransportProvider')]
    public function testRejectsInvalidTransportNames(string $transport): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported HTTP transport [{$transport}]");

        $this->newHandler(
            new PoolManager,
            m::mock(SwooleHandler::class),
            transport: $transport,
        );
    }

    public static function invalidTransportProvider(): array
    {
        return [
            'empty' => [''],
            'case sensitive' => ['Auto'],
            'unknown' => ['native'],
        ];
    }

    /**
     * Create a transport handler for a test.
     */
    private function newHandler(
        PoolManager $manager,
        SwooleHandler $swooleHandler,
        string $transport = 'auto',
        ?callable $fallbackHandler = null,
        string $logicalIdentity = 'default',
        array $poolOptions = [],
    ): TransportHandlerTestHandler {
        $handler = new TransportHandlerTestHandler(
            poolFactory: $manager,
            logicalIdentity: $logicalIdentity,
            poolOptions: PoolOptions::fromArray(array_replace([
                'min_retained_objects' => 0,
                'max_objects' => 2,
                'wait_timeout' => 0.01,
                'max_lifetime' => 0,
            ], $poolOptions)),
            transport: $transport,
            swooleHandler: $swooleHandler,
            fallbackHandler: $fallbackHandler ?? static fn () => Create::promiseFor(new Response(200)),
        );

        $this->handlers[] = $handler;

        return $handler;
    }

    /**
     * Create a prepared native request.
     */
    private function prepared(
        string $host = 'example.com',
        int $port = 80,
        bool $ssl = false,
        array $constructionSettings = [],
        array $transferSettings = [],
        array $headers = [],
        int $delayMicroseconds = 0,
    ): SwooleRequest {
        return new SwooleRequest(
            host: $host,
            port: $port,
            ssl: $ssl,
            constructionSettings: $constructionSettings,
            transferSettings: array_replace([
                'connect_timeout' => 0.0,
                'timeout' => 0.0,
                'read_timeout' => 0.0,
                'body_decompression' => true,
            ], $transferSettings),
            method: 'GET',
            path: '/',
            headers: $headers,
            body: Utils::streamFor(''),
            version: '1.1',
            decodeContent: true,
            delayMicroseconds: $delayMicroseconds,
        );
    }

    /**
     * Create a Swoole handler that completes successfully.
     */
    private function successfulSwooleHandler(SwooleRequest $prepared): SwooleHandler
    {
        $swooleHandler = m::mock(SwooleHandler::class);
        $swooleHandler->shouldReceive('prepare')->andReturn($prepared);
        $swooleHandler->shouldReceive('send')->andReturnUsing(
            static function (TransportHandlerTestClient $client): Response {
                $client->connected = true;

                return new Response(200);
            },
        );

        return $swooleHandler;
    }
}

class TransportHandlerTestHandler extends TransportHandler
{
    /** @var TransportHandlerTestClient[] */
    public array $createdClients = [];

    /** @var array<int, array{host: string, port: int, ssl: bool, construction_settings: array<string, mixed>}> */
    public array $createdClientArguments = [];

    public bool $newClientsConnected = false;

    /**
     * Create a fresh test Engine client.
     */
    protected function createClient(
        string $host,
        int $port,
        bool $ssl,
        array $constructionSettings,
    ): EngineClientInterface
    {
        $this->createdClientArguments[] = [
            'host' => $host,
            'port' => $port,
            'ssl' => $ssl,
            'construction_settings' => $constructionSettings,
        ];

        return $this->createdClients[] = new TransportHandlerTestClient(
            $this->newClientsConnected,
        );
    }
}

class TransportHandlerTestClient implements EngineClientInterface
{
    public function __construct(public bool $connected = false)
    {
    }

    public function set(array $settings): void
    {
    }

    public function request(
        string $method = 'GET',
        string $path = '/',
        array $headers = [],
        string $contents = '',
        string $version = '1.1',
    ): RawResponseInterface {
        throw new RuntimeException('The mocked Swoole handler owns request execution.');
    }

    public function send(
        string $method = 'GET',
        string $path = '/',
        array $headers = [],
        string $contents = '',
        string $version = '1.1',
    ): void {
    }

    public function recv(float $timeout = 0): RawResponseInterface
    {
        throw new RuntimeException('The mocked Swoole handler owns response execution.');
    }

    public function close(): void
    {
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }
}
