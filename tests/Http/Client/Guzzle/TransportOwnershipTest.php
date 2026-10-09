<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Client\Guzzle;

use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use Generator;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Promise\CancellationException;
use GuzzleHttp\Promise\Coroutine as PromiseCoroutine;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils as Psr7Utils;
use GuzzleHttp\TransferStats;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Coroutine\Coroutine as FrameworkCoroutine;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\Exceptions\CoroutineOwnershipException;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request as ServerRequest;
use Swoole\Http\Response as ServerResponse;
use WeakReference;

use function Hypervel\Coroutine\parallel;

class TransportOwnershipTest extends TestCase
{
    protected ?Server $server = null;

    protected ?int $serverCoroutine = null;

    /**
     * Close the loopback server in its owning test coroutine.
     */
    protected function tearDownInCoroutine(): void
    {
        $this->server?->shutdown();
        if ($this->serverCoroutine !== null) {
            Coroutine::join([$this->serverCoroutine], 1);
        }
    }

    public function testForeignAdmissionAndDrivingFailWhileOwnerWorkStillCompletes(): void
    {
        $url = $this->startServer();
        $handler = new CurlMultiHandler;
        $promise = $handler(new Request('GET', $url), ['timeout' => 2]);
        $operations = ['__invoke', 'tick', 'execute'];
        if (method_exists($handler, 'close')) {
            $operations[] = 'close';
        }

        $refused = parallel([static function () use ($handler, $url, $operations): array {
            $refused = [];
            foreach ($operations as $operation) {
                try {
                    $operation === '__invoke'
                        ? $handler(new Request('GET', $url), ['timeout' => 2])
                        : $handler->{$operation}();
                } catch (CoroutineOwnershipException) {
                    $refused[] = $operation;
                }
            }

            return $refused;
        }]);

        $this->assertSame($operations, $refused[0]);
        $this->assertSame('ok', (string) $promise->wait()->getBody());
        $this->assertSame(['ok'], parallel([static fn (): string => (string) $handler(new Request('GET', $url), ['timeout' => 2])->wait()->getBody()]));
    }

    public function testSameOwnerCanFinishOverlappingTransfers(): void
    {
        $arrivals = new Channel(2);
        $arrived = 0;
        $url = $this->startServer(static function (ServerRequest $request, ServerResponse $response) use ($arrivals, &$arrived): void {
            if (++$arrived === 2) {
                $arrivals->push(true);
                $arrivals->push(true);
            }
            $response->status($arrivals->pop(2) === true ? 200 : 503);
            $response->end('ok');
        });
        $handler = new CurlMultiHandler;

        try {
            $responses = Utils::all([
                $handler(new Request('GET', $url), ['timeout' => 3]),
                $handler(new Request('GET', $url), ['timeout' => 3]),
            ])->wait();
            $this->assertSame([200, 200], array_map(static fn (ResponseInterface $response): int => $response->getStatusCode(), $responses));
        } finally {
            $arrivals->close();
        }
    }

    public function testPreparationReservesOwnershipBeforeReadingTheBody(): void
    {
        $url = $this->startServer();
        $handler = new CurlMultiHandler;
        $reading = new Channel(1);
        $continue = new Channel(1);
        $body = FnStream::decorate(Psr7Utils::streamFor('upload'), [
            'getSize' => static function () use ($reading, $continue): int {
                $reading->push(true);
                if ($continue->pop(2) !== true) {
                    throw new RuntimeException('The preparation probe was not released.');
                }

                return 6;
            },
        ]);

        try {
            $results = parallel([
                static fn (): string => (string) $handler(new Request('POST', $url, [], $body), ['timeout' => 2])->wait()->getBody(),
                static function () use ($reading, $continue, $handler, $url): bool {
                    if ($reading->pop(2) !== true) {
                        throw new RuntimeException('Request preparation did not read the body.');
                    }
                    try {
                        $handler(new Request('GET', $url), ['timeout' => 2]);
                    } catch (CoroutineOwnershipException) {
                        return true;
                    } finally {
                        $continue->push(true);
                    }

                    return false;
                },
            ]);
            $this->assertSame(['ok', true], $results);
        } finally {
            $reading->close();
            $continue->close();
        }
    }

    public function testFailedPreparationReleasesTheHandler(): void
    {
        $url = $this->startServer();
        $handler = new CurlMultiHandler;
        $failure = new RuntimeException('body failed');
        $body = FnStream::decorate(Psr7Utils::streamFor('upload'), [
            'getSize' => static fn (): never => throw $failure,
        ]);

        try {
            $handler(new Request('POST', $url, [], $body), ['timeout' => 2])->wait();
            $this->fail('The body exception was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception->getPrevious() ?? $exception);
        }

        $this->assertSame(['ok'], parallel([static fn (): string => (string) $handler(new Request('GET', $url), ['timeout' => 2])->wait()->getBody()]));
    }

    public function testOwnerExitPropagatesCancellationInItsOwnContext(): void
    {
        $url = $this->startServer();
        $callbacks = [];
        [$result] = parallel([static function () use ($url, &$callbacks): array {
            $handler = new CurlMultiHandler;
            $promise = $handler(new Request('GET', $url), ['timeout' => 2]);
            $promise->otherwise(static function () use (&$callbacks): void { $callbacks[] = Coroutine::getCid(); });

            return [$promise, WeakReference::create($handler), Coroutine::getCid()];
        }]);
        [$promise, $handler, $owner] = $result;

        $this->assertSame(PromiseInterface::REJECTED, $promise->getState());
        gc_collect_cycles();
        Utils::queue()->run();
        $this->assertSame([$owner], $callbacks);
        $this->assertNull($handler->get());
    }

    #[DataProvider('ownerExitModes')]
    public function testOwnerExitResetsMemoizedCredentialsForTheNextCaller(bool $cancelOwner): void
    {
        $url = $this->startServer();
        $invocations = 0;
        $provider = CredentialProvider::memoize(static function () use ($url, &$invocations): PromiseInterface {
            ++$invocations;

            return PromiseCoroutine::of(static function () use ($url): Generator {
                yield (new CurlMultiHandler)(new Request('GET', $url), ['timeout' => 2]);
                yield new Credentials('test-key', 'test-secret');
            });
        });

        if ($cancelOwner) {
            $abandoned = null;
            $blocked = new Channel(1);
            $owner = FrameworkCoroutine::create(static function () use ($provider, &$abandoned, $blocked): void {
                $abandoned = $provider();
                $blocked->pop(2);
            });
            try {
                $this->assertTrue(EngineCoroutine::cancelById($owner, true));
                if (Coroutine::exists($owner)) {
                    Coroutine::join([$owner], 2);
                }
            } finally {
                $blocked->close();
            }
        } else {
            [$abandoned] = parallel([static fn (): PromiseInterface => $provider()]);
        }

        try {
            $abandoned->wait();
            $this->fail('The abandoned credential lookup was not canceled.');
        } catch (CancellationException) {
        }

        $this->assertSame(['test-key'], parallel([static fn (): string => $provider()->wait()->getAccessKeyId()]));
        $this->assertSame(2, $invocations);
    }

    /**
     * Provide normal exit and cancellation before waiting on credentials.
     */
    public static function ownerExitModes(): array
    {
        return [
            'normal exit' => [false],
            'canceled owner' => [true],
        ];
    }

    public function testExitCleanupCancelsTransfersCreatedByRetryCallbacks(): void
    {
        $url = $this->startServer();
        $transfers = [];
        $callbacks = [];
        parallel([static function () use ($url, &$transfers, &$callbacks): void {
            $handler = new CurlMultiHandler;
            $first = $transfers[] = $handler(new Request('GET', $url), ['timeout' => 2]);
            $first->otherwise(static function () use ($handler, $url, &$transfers, &$callbacks): PromiseInterface {
                $callbacks[] = 'first';
                $retry = $transfers[] = $handler(new Request('GET', $url), ['timeout' => 2]);
                $retry->otherwise(static function () use (&$callbacks): void {
                    $callbacks[] = 'retry';
                });

                return $retry;
            });
        }]);

        $this->assertSame(['first', 'retry'], $callbacks);
        $this->assertCount(2, $transfers);
        foreach ($transfers as $transfer) {
            $this->assertSame(PromiseInterface::REJECTED, $transfer->getState());
        }
    }

    public function testThrowingExitTaskReportsAndCancelsTransfersWithoutSkippingEarlierDefers(): void
    {
        $url = $this->startServer();
        $failure = new RuntimeException('exit callback failed');
        $reporter = m::mock(ExceptionHandler::class);
        $reporter->shouldReceive('report')->once()->with($failure);
        $this->app->instance(ExceptionHandler::class, $reporter);
        $transfer = null;
        $earlierDeferRan = false;

        parallel([static function () use ($url, $failure, &$transfer, &$earlierDeferRan): void {
            FrameworkCoroutine::defer(static function () use (&$earlierDeferRan): void {
                $earlierDeferRan = true;
            });
            Utils::queue()->add(static function () use ($url, $failure, &$transfer): void {
                $transfer = (new CurlMultiHandler)(new Request('GET', $url), ['timeout' => 2]);

                throw $failure;
            });
        }]);

        $this->assertTrue($earlierDeferRan);
        $this->assertSame(PromiseInterface::REJECTED, $transfer->getState());
    }

    public function testConcurrentSynchronousRequestsShareTheDefaultClientAndKeepCallbackContext(): void
    {
        $url = $this->startServer(static function (ServerRequest $request, ServerResponse $response): void {
            if ($request->server['request_uri'] === '/redirect') {
                $response->status(302);
                $response->header('Location', '/');
            }
            $response->end('ok');
        });
        $client = new Client;
        $callbacks = [];
        $middlewareCallbacks = [];
        $client->getConfig('handler')->push(static function (callable $handler) use (&$middlewareCallbacks): callable {
            return static function (RequestInterface $request, array $options) use ($handler, &$middlewareCallbacks): PromiseInterface {
                $owner = Coroutine::getCid();
                $name = CoroutineContext::get('guzzle-test.request');

                return $handler($request, $options)->then(static function (ResponseInterface $response) use ($owner, $name, &$middlewareCallbacks): ResponseInterface {
                    usleep(1000);
                    $middlewareCallbacks[$name][] = [Coroutine::getCid() === $owner, CoroutineContext::get('guzzle-test.request'), $response->getStatusCode()];

                    return $response;
                });
            };
        });
        $requests = [];
        foreach (['first', 'second'] as $name) {
            $requests[] = static function () use ($client, $url, $name, &$callbacks): string {
                CoroutineContext::set('guzzle-test.request', $name);
                $owner = Coroutine::getCid();

                return (string) $client->get($url . 'redirect', [
                    'timeout' => 2,
                    'on_stats' => static function (TransferStats $stats) use ($name, $owner, &$callbacks): void {
                        usleep(1000);
                        $callbacks[$name][] = [Coroutine::getCid() === $owner, CoroutineContext::get('guzzle-test.request'), $stats->getResponse()->getStatusCode()];
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

    public function testCancellationReleasesTheHandlerForAnotherOwner(): void
    {
        $url = $this->startServer();
        $handler = new CurlMultiHandler;
        $promise = $handler(new Request('GET', $url), ['timeout' => 2]);
        $promise->cancel();

        $this->assertSame(PromiseInterface::REJECTED, $promise->getState());
        $this->assertSame(['ok'], parallel([static fn (): string => (string) $handler(new Request('GET', $url), ['timeout' => 2])->wait()->getBody()]));
    }

    public function testCompletedTransfersDoNotRetainTheirHandlerUntilCoroutineExit(): void
    {
        $url = $this->startServer();
        $handler = new CurlMultiHandler;
        $reference = WeakReference::create($handler);
        $promise = $handler(new Request('GET', $url), ['timeout' => 2]);

        $this->assertSame('ok', (string) $promise->wait()->getBody());
        unset($promise, $handler);
        gc_collect_cycles();

        $this->assertNull($reference->get());
    }

    public function testAStreamingBodyCanBeReadLaterInAnotherCoroutine(): void
    {
        $continue = new Channel(1);
        $url = $this->startServer(static function (ServerRequest $request, ServerResponse $response) use ($continue): void {
            $response->write('first');
            if ($continue->pop(2) !== true) {
                $response->end('timed out');
                return;
            }
            $response->end('second');
        });

        try {
            $response = (new Factory)->withOptions(['stream' => true, 'read_timeout' => 2])->get($url);
            $bodies = parallel([static function () use ($response, $continue): string {
                $continue->push(true);

                return $response->body();
            }]);
            $this->assertSame(['firstsecond'], $bodies);
        } finally {
            $continue->close();
        }
    }

    /**
     * Start a bounded loopback server on an automatically assigned port.
     */
    protected function startServer(?callable $callback = null): string
    {
        $this->server = new Server('127.0.0.1', 0, false, false);
        $this->server->handle('/', $callback ?? static function (ServerRequest $request, ServerResponse $response): void {
            $response->end('ok');
        });
        $this->serverCoroutine = Coroutine::create(fn (): bool => $this->server->start());

        return 'http://127.0.0.1:' . $this->server->port . '/';
    }
}
