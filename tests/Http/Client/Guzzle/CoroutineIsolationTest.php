<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Client\Guzzle;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\TransferStats;
use Hypervel\Context\CoroutineContext;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Http\Client\Guzzle\Fixtures\HttpServer;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Swoole\Coroutine\Channel;
use Swoole\Http\Request as ServerRequest;
use Swoole\Http\Response as ServerResponse;

use function Hypervel\Coroutine\parallel;

class CoroutineIsolationTest extends TestCase
{
    protected ?HttpServer $server = null;

    /**
     * Close the loopback server in its owning test coroutine.
     */
    protected function tearDownInCoroutine(): void
    {
        $this->server?->close();
    }

    /**
     * Configure independent tenant databases with the production connection resolver.
     */
    protected function defineEnvironment(Application $app): void
    {
        foreach (['first', 'second'] as $tenant) {
            $app->make('config')->set("database.connections.{$tenant}", [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'pool' => ['testing_enabled' => true],
            ]);
        }
    }

    public function testConcurrentSynchronousRequestsShareTheDefaultClientAndKeepCallbackContext(): void
    {
        $this->server = new HttpServer(static function (ServerRequest $request, ServerResponse $response): void {
            if ($request->server['request_uri'] === '/redirect') {
                $response->status(302);
                $response->header('Location', '/');
            }
            $response->end('ok');
        });
        $url = $this->server->url();
        $client = new Client;
        $callbacks = [];
        $middlewareCallbacks = [];
        $client->getConfig('handler')->push(static function (callable $handler) use (&$middlewareCallbacks): callable {
            return static function (RequestInterface $request, array $options) use ($handler, &$middlewareCallbacks): PromiseInterface {
                $owner = Coroutine::id();
                $name = CoroutineContext::get('guzzle-test.request');

                return $handler($request, $options)->then(static function (ResponseInterface $response) use ($owner, $name, &$middlewareCallbacks): ResponseInterface {
                    usleep(1000);
                    $middlewareCallbacks[$name][] = [Coroutine::id() === $owner, CoroutineContext::get('guzzle-test.request'), $response->getStatusCode()];

                    return $response;
                });
            };
        });
        $requests = [];
        foreach (['first', 'second'] as $name) {
            $requests[] = static function () use ($client, $url, $name, &$callbacks): string {
                CoroutineContext::set('guzzle-test.request', $name);
                $owner = Coroutine::id();

                return (string) $client->get($url . 'redirect', [
                    'timeout' => 2,
                    'on_stats' => static function (TransferStats $stats) use ($name, $owner, &$callbacks): void {
                        usleep(1000);
                        $callbacks[$name][] = [Coroutine::id() === $owner, CoroutineContext::get('guzzle-test.request'), $stats->getResponse()->getStatusCode()];
                    },
                ])->getBody();
            };
        }

        $this->assertSame(['ok', 'ok'], parallel($requests));
        foreach (['first', 'second'] as $name) {
            $this->assertSame([[true, $name, 302], [true, $name, 200]], $callbacks[$name]);
            $this->assertSame([[true, $name, 302], [true, $name, 200]], $middlewareCallbacks[$name]);
        }
    }

    public function testAStreamingBodyCanBeReadLaterInAnotherCoroutine(): void
    {
        $continue = new Channel(1);
        $this->server = new HttpServer(static function (ServerRequest $request, ServerResponse $response) use ($continue): void {
            $response->write('first');
            if ($continue->pop(2) !== true) {
                $response->end('timed out');
                return;
            }
            $response->end('second');
        });

        try {
            $response = (new Factory)->withOptions(['stream' => true, 'read_timeout' => 2])->get($this->server->url());
            $bodies = parallel([static function () use ($response, $continue): string {
                $continue->push(true);

                return $response->body();
            }]);
            $this->assertSame(['firstsecond'], $bodies);
        } finally {
            $continue->close();
        }
    }

    public function testYieldingCallbacksResolveTheirOwnRequestAndDatabaseTransaction(): void
    {
        $callbacks = $observed = $expected = [];

        foreach (['first', 'second'] as $tenant) {
            $callbacks[] = static function () use ($tenant, &$observed, &$expected): void {
                RequestContext::set(Request::create('/' . $tenant));
                DB::setDefaultConnection($tenant);
                $connection = DB::connection();
                $connection->statement('create table messages (tenant varchar(255))');
                $connection->beginTransaction();
                $connection->table('messages')->insert(['tenant' => $tenant]);
                $expected[$tenant] = [Coroutine::id(), '/' . $tenant, $connection, 1, $tenant];

                try {
                    $promise = Create::promiseFor(null);
                    foreach ([true, false] as $yield) {
                        $promise->then(static function () use ($tenant, $yield, &$observed): void {
                            if ($yield) {
                                usleep(1000);
                            }

                            $resolved = DB::connection();
                            $observed[$tenant][] = [
                                $yield,
                                Coroutine::id(),
                                RequestContext::get()->getPathInfo(),
                                $resolved,
                                $resolved->transactionLevel(),
                                $resolved->table('messages')->value('tenant'),
                            ];
                        });
                    }
                    Utils::queue()->run();
                } finally {
                    $connection->rollBack();
                }
            };
        }

        parallel($callbacks);

        foreach ($expected as $tenant => $snapshot) {
            $this->assertSame([[true, ...$snapshot], [false, ...$snapshot]], $observed[$tenant]);
            $this->assertSame(0, DB::connection($tenant)->table('messages')->count());
        }
    }
}
