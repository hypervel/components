<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Client\Guzzle;

use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use Generator;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Promise\CancellationException;
use GuzzleHttp\Promise\Coroutine as PromiseCoroutine;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Request;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Coroutine\Coroutine as FrameworkCoroutine;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Http\Client\Guzzle\Fixtures\HttpServer;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use WeakReference;

use function Hypervel\Coroutine\parallel;

class CoroutineStateTest extends TestCase
{
    protected ?HttpServer $server = null;

    /**
     * Close the loopback server in its owning test coroutine.
     */
    protected function tearDownInCoroutine(): void
    {
        $this->server?->close();
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

    public function testCanceledTransfersReleaseTheirHandlerAfterTheQueueDrains(): void
    {
        $url = $this->startServer();
        $handler = new CurlMultiHandler;
        $reference = WeakReference::create($handler);
        $promise = $handler(new Request('GET', $url), ['timeout' => 2]);
        $promise->cancel();

        $this->assertSame(PromiseInterface::REJECTED, $promise->getState());
        unset($promise, $handler);
        Utils::queue()->run();
        gc_collect_cycles();

        $this->assertNull($reference->get());
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

    /**
     * Start a bounded loopback server on an automatically assigned port.
     */
    protected function startServer(): string
    {
        $this->server = new HttpServer;

        return $this->server->url();
    }
}
