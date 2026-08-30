<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Closure;
use Exception;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Engine\Exceptions\CoroutineDestroyedException;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\Context\Fixtures\ThrowingReplicableContext;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;
use Throwable;

use function Hypervel\Coroutine\go;

class CoroutineTest extends TestCase
{
    public function testCreateAndForkReturnPositiveCoroutineIds(): void
    {
        $this->assertGreaterThan(0, Coroutine::create(static fn (): null => null));
        $this->assertGreaterThan(0, Coroutine::fork(static fn (): null => null));
    }

    public function testJoinWaitsForCreatedCoroutines(): void
    {
        $completed = false;
        $coroutineId = Coroutine::create(static function () use (&$completed): void {
            usleep(1000);
            $completed = true;
        });

        $this->assertTrue(Coroutine::join([$coroutineId], 1));
        $this->assertTrue($completed);
        $this->assertFalse(Coroutine::exists($coroutineId));
    }

    public function testCoroutineParentId()
    {
        $pid = Coroutine::id();
        Coroutine::create(function () use ($pid) {
            $this->assertSame($pid, Coroutine::parentId());
            $pid = Coroutine::id();
            $id = Coroutine::create(function () use ($pid) {
                $this->assertSame($pid, Coroutine::parentId(Coroutine::id()));
                usleep(1000);
            });
            Coroutine::create(function () use ($pid) {
                $this->assertSame($pid, Coroutine::parentId());
            });
            $this->assertSame($pid, Coroutine::parentId($id));
        });
    }

    public function testCoroutineParentIdHasBeenDestroyed()
    {
        $id = Coroutine::create(function () {
        });

        try {
            Coroutine::parentId($id);
            $this->assertTrue(false);
        } catch (Throwable $exception) {
            $this->assertInstanceOf(CoroutineDestroyedException::class, $exception);
        }
    }

    public function testCoroutineAndDeferWithException()
    {
        $container = new Container;
        $handler = m::mock(ExceptionHandlerContract::class);
        $container->instance(ExceptionHandlerContract::class, $handler);
        Container::setInstance($container);

        $exception = new Exception;
        $handler->shouldReceive('report')->with($exception)->twice();

        $chan = new Channel(1);
        go(static function () use ($chan, $exception) {
            Coroutine::defer(static function () use ($chan, $exception) {
                try {
                    throw $exception;
                } finally {
                    $chan->push(1);
                }
            });

            throw $exception;
        });

        $this->assertTrue(true);
    }

    public function testTerminalCancellationIsNotReported(): void
    {
        $container = new Container;
        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldNotReceive('report');
        $container->instance(ExceptionHandlerContract::class, $handler);
        Container::setInstance($container);
        $blocker = new Channel(1);

        $coroutineId = Coroutine::create(static function () use ($blocker): void {
            $blocker->pop();
        });

        $this->assertTrue(EngineCoroutine::cancelById($coroutineId, throwException: true));
        $this->assertFalse(Coroutine::exists($coroutineId));
    }

    public function testAfterCreatedCallbacksAreExecuted()
    {
        $executed = false;

        Coroutine::afterCreated(function () use (&$executed) {
            $executed = true;
        });

        Coroutine::create(function () {
            // The afterCreated callback should have run before this
        });

        $this->assertTrue($executed);
    }

    public function testAfterCreatedCallbacksExecuteInOrder()
    {
        $order = [];

        Coroutine::afterCreated(function () use (&$order) {
            $order[] = 1;
        });

        Coroutine::afterCreated(function () use (&$order) {
            $order[] = 2;
        });

        Coroutine::create(function () use (&$order) {
            $order[] = 3;
        });

        $this->assertSame([1, 2, 3], $order);
    }

    public function testForkInstallsCopiedContextBeforeAfterCreatedCallbacks(): void
    {
        CoroutineContext::set('request-id', 'parent-request');
        $observed = [];

        Coroutine::afterCreated(static function () use (&$observed): void {
            $observed['callback'] = CoroutineContext::get('request-id');
        });

        Coroutine::fork(static function () use (&$observed): void {
            $observed['callable'] = CoroutineContext::get('request-id');
        });

        $this->assertSame([
            'callback' => 'parent-request',
            'callable' => 'parent-request',
        ], $observed);
    }

    public function testFlushStateClearsAfterCreatedCallbacks()
    {
        $count = 0;

        Coroutine::afterCreated(function () use (&$count) {
            ++$count;
        });

        Coroutine::create(function () {});
        $this->assertSame(1, $count);

        Coroutine::flushState();

        Coroutine::create(function () {});
        $this->assertSame(1, $count); // Should still be 1, callback was flushed
    }

    public function testFlushStateRestoresExceptionReporting()
    {
        try {
            $container = new Container;
            $handler = m::mock(ExceptionHandlerContract::class);
            $container->instance(ExceptionHandlerContract::class, $handler);
            Container::setInstance($container);

            $handler->shouldReceive('report')->once();

            Coroutine::enableReportException(false);
            Coroutine::flushState();

            Coroutine::create(function () {
                throw new Exception('Should be reported after flushState.');
            });
        } finally {
            Coroutine::flushState();
        }
    }

    public function testAfterCreatedCallbackExceptionDoesNotStopOthers()
    {
        $container = new Container;
        $handler = m::mock(ExceptionHandlerContract::class);
        $container->instance(ExceptionHandlerContract::class, $handler);
        Container::setInstance($container);
        $handler->shouldReceive('report')->once();

        $secondCallbackRan = false;
        $mainCallableRan = false;

        Coroutine::afterCreated(function () {
            throw new Exception('First callback fails');
        });

        Coroutine::afterCreated(function () use (&$secondCallbackRan) {
            $secondCallbackRan = true;
        });

        Coroutine::create(function () use (&$mainCallableRan) {
            $mainCallableRan = true;
        });

        $this->assertTrue($secondCallbackRan);
        $this->assertTrue($mainCallableRan);
    }

    public function testOwnedCoroutineTransfersOwnershipBeforeStartupFailureReportingCanSuspend(): void
    {
        $container = new Container;
        $handler = m::mock(ExceptionHandlerContract::class);
        $container->instance(ExceptionHandlerContract::class, $handler);
        Container::setInstance($container);
        CoroutineContext::set('request-id', 'child-request');
        $exception = new RuntimeException('The startup hook failed.');
        $reportStarted = new Channel(1);
        $releaseReport = new Channel(1);
        $reportedContext = null;
        $started = false;
        $finalized = 0;
        $secondCallbackRan = false;
        $mainCallableRan = false;

        $handler->shouldReceive('report')
            ->once()
            ->with($exception)
            ->andReturnUsing(static function () use ($reportStarted, $releaseReport, &$reportedContext): void {
                $reportedContext = CoroutineContext::get('request-id');
                $reportStarted->push(true);
                $releaseReport->pop();
            });

        Coroutine::afterCreated(static function () use ($exception): void {
            throw $exception;
        });
        Coroutine::afterCreated(static function () use (&$secondCallbackRan): void {
            $secondCallbackRan = true;
        });

        $coroutineId = null;

        try {
            $coroutineId = Coroutine::forkOwned(
                static function () use (&$mainCallableRan): void {
                    $mainCallableRan = true;
                },
                static function (Closure $run) use (&$started, &$finalized): void {
                    try {
                        $started = true;
                        $run();
                    } finally {
                        ++$finalized;
                    }
                },
            );

            $this->assertTrue($reportStarted->pop(1));
            $this->assertTrue($started);
            $this->assertSame('child-request', $reportedContext);
            $this->assertFalse($secondCallbackRan);
            $this->assertFalse($mainCallableRan);

            $this->assertTrue(EngineCoroutine::cancelById($coroutineId, throwException: true));
            $this->assertFalse(Coroutine::exists($coroutineId));
            $this->assertSame(1, $finalized);
        } finally {
            $releaseReport->push(true, 0.001);

            if ($coroutineId !== null && Coroutine::exists($coroutineId)) {
                EngineCoroutine::cancelById($coroutineId, throwException: true);
                Coroutine::join([$coroutineId], 1);
            }
        }
    }

    #[RunInSeparateProcess]
    public function testExceptionHandlerFailureFallsBackToThePhpErrorLog(): void
    {
        $directory = ParallelTesting::tempDir('CoroutineTest');
        (new Filesystem)->deleteDirectory($directory);
        mkdir($directory, 0777, true);
        $errorLog = $directory . '/php-error.log';
        $previousErrorLog = ini_set('error_log', $errorLog);
        $previousLogErrors = ini_set('log_errors', '1');

        try {
            $container = new Container;
            $handler = m::mock(ExceptionHandlerContract::class);
            $handler->shouldReceive('report')
                ->once()
                ->andThrow(new RuntimeException('The exception reporter failed.'));
            $container->instance(ExceptionHandlerContract::class, $handler);
            Container::setInstance($container);

            Coroutine::create(static function (): void {
                throw new RuntimeException('The coroutine failed.');
            });

            $contents = file_get_contents($errorLog);
            $this->assertIsString($contents);
            $this->assertStringContainsString('The coroutine failed.', $contents);
        } finally {
            if ($previousErrorLog !== false) {
                ini_set('error_log', $previousErrorLog);
            }

            if ($previousLogErrors !== false) {
                ini_set('log_errors', $previousLogErrors);
            }

            (new Filesystem)->deleteDirectory($directory);
        }
    }

    public function testForkSurfacesContextReplicationFailureBeforeCreatingTheChild(): void
    {
        CoroutineContext::set('throwing', new ThrowingReplicableContext);
        $executed = false;

        try {
            Coroutine::fork(static function () use (&$executed): void {
                $executed = true;
            });
            $this->fail('Expected context replication to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to replicate context.', $exception->getMessage());
        }

        $this->assertFalse($executed);
    }
}
