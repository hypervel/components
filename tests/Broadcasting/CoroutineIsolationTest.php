<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Closure;
use Hypervel\Broadcasting\BroadcastException;
use Hypervel\Broadcasting\BroadcastManager;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Http\Request;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\Testbench\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\CanceledException;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request as ServerRequest;
use Swoole\Http\Response;
use Throwable;

use function Hypervel\Coroutine\parallel;

class CoroutineIsolationTest extends TestCase
{
    protected ?Server $server = null;

    protected ?int $serverCoroutine = null;

    /**
     * Configure a bounded publishing pool with request-relative URLs.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('broadcasting.connections.mercure', [
            'driver' => 'mercure',
            'url' => '/.well-known/mercure',
            'secret' => 'hypervel-mercure-integration-signing-key',
            'claims' => ['iss' => 'http://hypervel.test'],
            'cookie_name' => 'mercureAuthorization',
            'client_options' => ['max_duration' => 3],
            'pool' => ['max_objects' => 2],
        ]);
    }

    /**
     * Close all clients and the test-owned listener before leaving the coroutine.
     */
    protected function tearDownInCoroutine(): void
    {
        try {
            $this->app->make(PoolManager::class)->purgeAll();
        } finally {
            $this->server?->shutdown();

            if ($this->serverCoroutine !== null) {
                Coroutine::join([$this->serverCoroutine], 1);
            }
        }
    }

    public function testConcurrentPublishesYieldReuseClientsAndKeepRequestAudiencesSeparate(): void
    {
        $active = $peak = $completed = $ticks = 0;
        $received = $connections = [];
        $address = $this->startServer(function (ServerRequest $request, Response $response) use (&$active, &$peak, &$received, &$connections): void {
            $peak = max($peak, ++$active);
            usleep(50000);
            parse_str($request->getContent(), $body);
            $data = json_decode($body['data'], true, flags: JSON_THROW_ON_ERROR);
            $token = explode('.', substr($request->header['authorization'], 7));
            $claims = json_decode(base64_decode(strtr($token[1], '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
            $received[$data['payload']['id']] = [$request->header['host'], $claims['aud']];
            $connections[] = $request->server['remote_port'];
            $response->end('published');
            --$active;
        });
        $broadcaster = $this->app->make(BroadcastManager::class)->connection('mercure');
        $origins = ['http://' . $address, 'http://localhost:' . $this->server->port];
        $calls = [];

        for ($index = 0; $index < 4; ++$index) {
            $calls[] = static function () use ($broadcaster, $origins, $index, &$completed): void {
                RequestContext::set(Request::create($origins[$index % 2]));
                usleep(1000);
                $broadcaster->broadcast(['orders'], 'shipped', ['id' => $index]);
                ++$completed;
            };
        }

        $waiting = 0;
        $calls[] = function () use ($broadcaster, &$completed, &$ticks, &$waiting): void {
            $pools = $this->app->make(PoolManager::class);
            $deadline = microtime(true) + 5;

            while ($completed < 4 && microtime(true) < $deadline) {
                usleep(5000);
                ++$ticks;

                if ($pools->has($broadcaster->getHub()->getPoolName())) {
                    $waiting = max($waiting, $pools->get($broadcaster->getHub()->getPoolName())->getWaitingCount());
                }
            }
        };

        parallel($calls);

        $this->assertSame(2, $peak);
        $this->assertGreaterThan(0, $waiting);
        $this->assertGreaterThan(1, $ticks);

        foreach (range(0, 3) as $index) {
            $origin = $origins[$index % 2];
            $this->assertSame([substr($origin, 7), $origin . '/.well-known/mercure'], $received[$index]);
        }

        RequestContext::set(Request::create($origins[0]));
        $broadcaster->broadcast(['orders'], 'shipped', ['id' => 4]);
        $pool = $this->app->make(PoolManager::class)->get($broadcaster->getHub()->getPoolName());
        $this->assertSame(0, $pool->getBorrowedCount());
        $this->assertSame(2, $pool->getManagedCount());
        $this->assertLessThan(5, count(array_unique($connections)));
    }

    public function testFailedPublishDiscardsTheClientAndTheNextPublishSucceeds(): void
    {
        $requests = 0;
        $address = $this->startServer(static function (ServerRequest $request, Response $response) use (&$requests): void {
            $response->status(++$requests === 1 ? 500 : 200);
            $response->end($requests === 1 ? 'unavailable' : 'published');
        });
        RequestContext::set(Request::create('http://' . $address));
        $broadcaster = $this->app->make(BroadcastManager::class)->connection('mercure');

        try {
            $broadcaster->broadcast(['orders'], 'shipped');
            $this->fail('The failed publish was not reported.');
        } catch (BroadcastException $exception) {
            $this->assertNotNull($exception->getPrevious());
        }

        $pool = $this->app->make(PoolManager::class)->get($broadcaster->getHub()->getPoolName());
        $this->assertSame(0, $pool->getManagedCount());
        $broadcaster->broadcast(['orders'], 'shipped');
        $this->assertSame(2, $requests);
        $this->assertSame(1, $pool->getIdleCount());
    }

    public function testCanceledPublishPreservesCancellationAndDiscardsTheClient(): void
    {
        $received = new Channel(1);
        $release = new Channel(1);
        $finished = new Channel(1);
        $address = $this->startServer(static function (ServerRequest $request, Response $response) use ($received, $release, $finished): void {
            $received->push(true);
            $release->pop(2);
            $response->end('published');
            $finished->push(true);
        });
        $broadcaster = $this->app->make(BroadcastManager::class)->connection('mercure');
        $failure = null;
        $publisher = Coroutine::create(static function () use ($broadcaster, $address, &$failure): void {
            RequestContext::set(Request::create('http://' . $address));

            try {
                $broadcaster->broadcast(['orders'], 'shipped');
            } catch (Throwable $exception) {
                $failure = $exception;
            }
        });

        try {
            $this->assertTrue($received->pop(2));
            $this->assertTrue(EngineCoroutine::cancelById($publisher, throwException: true));
            Coroutine::join([$publisher], 1);
            $this->assertInstanceOf(CanceledException::class, $failure);
            $pool = $this->app->make(PoolManager::class)->get($broadcaster->getHub()->getPoolName());
            $this->assertSame(0, $pool->getManagedCount());
        } finally {
            $release->push(true);
            $serverFinished = $finished->pop(3);
            Coroutine::join([$publisher], 1);
            $received->close();
            $release->close();
            $finished->close();
        }

        $this->assertTrue($serverFinished);
    }

    /**
     * Start a self-contained HTTP endpoint on an automatically assigned port.
     *
     * @param Closure(ServerRequest, Response): void $handler
     */
    protected function startServer(Closure $handler): string
    {
        $this->server = new Server('127.0.0.1', 0, false, false);
        $this->server->handle('/', $handler);
        $this->serverCoroutine = Coroutine::create(fn (): bool => $this->server->start());

        return '127.0.0.1:' . $this->server->port;
    }
}
