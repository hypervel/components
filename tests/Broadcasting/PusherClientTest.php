<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Multiplexing;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use GuzzleHttp\TransportSharing;
use Hypervel\Broadcasting\BroadcastManager;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Psr\Http\Message\RequestInterface;
use Pusher\ApiErrorException;
use Pusher\Pusher;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request as ServerRequest;
use Swoole\Http\Response as ServerResponse;

use function Hypervel\Coroutine\parallel;

class PusherClientTest extends TestCase
{
    protected ?Server $server = null;

    protected ?int $serverCoroutine = null;

    protected array $connections = [];

    /**
     * Close the test-owned server before leaving the coroutine.
     */
    protected function tearDownInCoroutine(): void
    {
        $this->server?->shutdown();

        if ($this->serverCoroutine !== null) {
            Coroutine::join([$this->serverCoroutine], 1);
        }
    }

    #[DataProvider('drivers')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testConcurrentAsyncCallsKeepTransfersAndStatsInTheirCallingCoroutines(string $driver): void
    {
        $this->startServer();
        $owners = $observed = [];
        $client = $this->client($driver, [
            'on_stats' => static function (TransferStats $stats) use (&$observed): void {
                $payload = json_decode((string) $stats->getRequest()->getBody(), true, flags: JSON_THROW_ON_ERROR);
                $name = $payload['name'] ?? $payload['batch'][0]['name'];
                $observed[$name] = Coroutine::getCid();
            },
        ]);

        $results = parallel([
            static function () use ($client, &$owners): object {
                $owners['single'] = Coroutine::getCid();
                $promise = $client->triggerAsync('channel', 'single', ['value' => 1]);
                usleep(1000);

                return $promise->wait();
            },
            static function () use ($client, &$owners): object {
                $owners['batch'] = Coroutine::getCid();

                return $client->triggerBatchAsync([
                    ['channel' => 'channel', 'name' => 'batch', 'data' => ['value' => 2]],
                ])->wait();
            },
            static function () use ($client, &$owners): object {
                $owners['sync'] = Coroutine::getCid();

                return $client->trigger('channel', 'sync', []);
            },
        ]);

        $this->assertCount(3, $results);
        $this->assertSame('accepted', $results[0]->result);
        $this->assertSame('accepted', $results[1]->result);
        $this->assertSame('accepted', $results[2]->result);
        ksort($owners);
        ksort($observed);
        $this->assertSame($owners, $observed);
    }

    #[DataProvider('drivers')]
    public function testSequentialSynchronousCallsReuseTheirConnection(string $driver): void
    {
        $this->startServer();
        $client = $this->client($driver);

        $this->assertSame('accepted', $client->trigger('channel', 'first', [])->result);
        $this->assertSame('accepted', $client->trigger('channel', 'second', [])->result);
        $this->assertCount(2, $this->connections);
        $this->assertSame($this->connections[0], $this->connections[1]);
    }

    /**
     * Provide broadcasting drivers that use the same Pusher client construction.
     */
    public static function drivers(): array
    {
        return ['Pusher' => ['pusher'], 'Reverb' => ['reverb']];
    }

    public function testAsyncRequestsInOneCoroutineMakeProgressTogether(): void
    {
        $arrivals = 0;
        $firstSawBoth = false;
        $gate = new Channel(1);
        $this->startServer(static function (ServerRequest $request, ServerResponse $response) use (&$arrivals, &$firstSawBoth, $gate): void {
            if (++$arrivals === 1) {
                $firstSawBoth = $gate->pop(1) === true;
            } else {
                $gate->push(true);
            }

            $response->end('{"result":"accepted"}');
        });
        $client = $this->client('pusher');

        try {
            $results = Utils::all([
                $client->triggerAsync('channel', 'first', []),
                $client->triggerAsync('channel', 'second', []),
            ])->wait();

            $this->assertTrue($firstSawBoth, 'Both requests must arrive before the first response is sent.');
            $this->assertCount(2, $results);
            $this->assertSame('accepted', $results[0]->result);
            $this->assertSame('accepted', $results[1]->result);
        } finally {
            $gate->close();
        }
    }

    public function testCopiedContextDoesNotLetAChildDriveItsParentsPendingTransfer(): void
    {
        $arrived = [];
        $this->startServer(static function (ServerRequest $request, ServerResponse $response) use (&$arrived): void {
            $payload = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
            $arrived[] = $payload['name'];
            $response->end('{"result":"accepted"}');
        });
        $observed = [];
        $client = $this->client('pusher', [
            'on_stats' => static function (TransferStats $stats) use (&$observed): void {
                $payload = json_decode((string) $stats->getRequest()->getBody(), true, flags: JSON_THROW_ON_ERROR);
                $observed[$payload['name']] = Coroutine::getCid();
            },
        ]);
        $parentOwner = Coroutine::getCid();
        $parent = $client->triggerAsync('channel', 'parent', []);
        $childOwner = null;
        $child = parallel([
            static function () use ($client, &$childOwner): object {
                $childOwner = Coroutine::getCid();

                return $client->triggerAsync('channel', 'child', [])->wait();
            },
        ], copyContext: true);

        $this->assertSame(['child'], $arrived);
        $this->assertSame('accepted', $child[0]->result);
        $this->assertSame('accepted', $parent->wait()->result);
        $this->assertSame(['child', 'parent'], $arrived);
        $this->assertSame($parentOwner, $observed['parent']);
        $this->assertSame($childOwner, $observed['child']);
    }

    public function testAsyncFailureDoesNotPreventTheNextRequest(): void
    {
        $this->startServer();
        $client = $this->client('pusher');

        try {
            $client->triggerAsync('channel', 'fail', [])->wait();
            $this->fail('The failed publish was not reported.');
        } catch (ApiErrorException $exception) {
            $this->assertSame(503, $exception->getCode());
        }

        $this->assertSame('accepted', $client->triggerAsync('channel', 'recovered', [])->wait()->result);
        $this->assertCount(2, $this->connections);
        $this->assertSame($this->connections[0], $this->connections[1]);
    }

    public function testCustomHandlerReceivesConfiguredOptionsAndBothRequestModes(): void
    {
        $requests = [];
        $client = $this->client('pusher', [
            'connect_timeout' => 2,
            'multiplex' => Multiplexing::EAGER,
            'headers' => ['X-Custom' => 'preserved'],
            'handler' => static function (RequestInterface $request, array $options) use (&$requests): PromiseInterface {
                $requests[] = [$request, $options];

                return Create::promiseFor(new Response(200, [], '{"result":"accepted"}'));
            },
        ]);

        $client->trigger('channel', 'sync', []);
        $client->triggerAsync('channel', 'async', [])->wait();

        $this->assertCount(2, $requests);
        $this->assertTrue($requests[0][1]['synchronous']);
        $this->assertFalse($requests[1][1]['synchronous'] ?? false);

        foreach ($requests as [$request, $options]) {
            $this->assertSame('preserved', $request->getHeaderLine('X-Custom'));
            $this->assertSame(2, $options['connect_timeout']);
            $this->assertSame(3, $options['timeout']);
            $this->assertSame(Multiplexing::EAGER, $options['multiplex']);
        }
    }

    #[DataProvider('sharingModes')]
    public function testDefaultHandlersPreserveTransportOptions(string $sharing): void
    {
        $options = [
            'transport_sharing' => $sharing,
            'multiplex' => Multiplexing::NONE,
            'version' => '2.0',
        ];

        try {
            new Client($options);
        } catch (RuntimeException $exception) {
            // Required sharing depends on the installed libcurl capabilities.
            $this->expectException($exception::class);
            $this->expectExceptionMessageIs($exception->getMessage());
            $this->app->make(BroadcastManager::class)->pusher($this->configuration('pusher', $options));

            return;
        }

        $this->startServer();
        $client = $this->client('pusher', $options);

        $this->assertSame('accepted', $client->trigger('channel', 'sync', [])->result);
        $this->assertSame('accepted', $client->triggerAsync('channel', 'async', [])->wait()->result);
    }

    /**
     * Provide optional and mandatory sharing modes for default handlers.
     */
    public static function sharingModes(): array
    {
        return [[TransportSharing::HANDLER_PREFER], [TransportSharing::HANDLER_REQUIRE]];
    }

    #[DataProvider('customHandlerOptions')]
    public function testCustomHandlerOptionsRetainGuzzlesValidation(array $options, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->app->make(BroadcastManager::class)->pusher($this->configuration('pusher', $options + [
            'handler' => static fn (RequestInterface $request, array $options): PromiseInterface => Create::promiseFor(new Response),
        ]));
    }

    /**
     * Provide options Guzzle validates when a custom handler is supplied.
     */
    public static function customHandlerOptions(): array
    {
        return [
            'required sharing' => [
                ['transport_sharing' => TransportSharing::HANDLER_REQUIRE],
                'can only require sharing when Guzzle creates the default handler',
            ],
            'connection cap' => [
                ['max_host_connections' => 2],
                'client options require Guzzle to create the default handler',
            ],
        ];
    }

    #[DataProvider('connectionCaps')]
    public function testConnectionCapsRequireBoundingBroadcastConcurrencyInstead(string $option): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Bound coroutine concurrency or use rate limiting');

        $this->app->make(BroadcastManager::class)->pusher($this->configuration('pusher', [$option => 2]));
    }

    /**
     * Provide connection limits that require an unsafe shared multi-handler.
     */
    public static function connectionCaps(): array
    {
        return [['max_host_connections'], ['max_total_connections']];
    }

    /**
     * Resolve the public Pusher client through the configured broadcast driver.
     */
    protected function client(string $driver, array $options = []): Pusher
    {
        config(['broadcasting.connections.ownership' => $this->configuration($driver, $options)]);

        return $this->app->make(BroadcastManager::class)->connection('ownership')->getPusher();
    }

    /**
     * Configure a local broadcasting endpoint and bounded request timeouts.
     */
    protected function configuration(string $driver, array $options): array
    {
        return [
            'driver' => $driver,
            'key' => 'key',
            'secret' => 'secret',
            'app_id' => 'app',
            'options' => [
                'host' => '127.0.0.1',
                'port' => $this->server?->port ?? 80,
                'scheme' => 'http',
                'useTLS' => false,
                'timeout' => 3,
            ],
            'client_options' => $options,
        ];
    }

    /**
     * Start a local Pusher-compatible endpoint with keep-alive support.
     */
    protected function startServer(?Closure $handler = null): void
    {
        $handler ??= static function (ServerRequest $request, ServerResponse $response): void {
            $payload = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
            usleep(10000);
            $response->status(($payload['name'] ?? null) === 'fail' ? 503 : 200);
            $response->header('Content-Type', 'application/json');
            $response->end('{"result":"accepted"}');
        };
        $this->server = new Server('127.0.0.1', 0, false, false);
        $this->server->handle('/', function (ServerRequest $request, ServerResponse $response) use ($handler): void {
            $this->connections[] = $request->server['remote_port'];
            $handler($request, $response);
        });
        $this->serverCoroutine = Coroutine::create(fn (): bool => $this->server->start());
    }
}
