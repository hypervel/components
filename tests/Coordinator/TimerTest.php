<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coordinator;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Coordinator\Coordinator;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Coordinator\Timer;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Waiter;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

class TimerTest extends TestCase
{
    public function testAfter(): void
    {
        $this->wait(function () {
            $id = 0;
            $timer = new Timer;
            $identifier = uniqid();
            $timer->after(0.001, function ($isClosing) use (&$id) {
                ++$id;
                $this->assertFalse($isClosing);
            }, $identifier);

            usleep(10000);
            $this->assertSame(1, $id);
        });
    }

    public function testAfterRechecksElapsedTimeAfterAnEarlyCoordinatorWake(): void
    {
        $identifier = uniqid();
        $coordinators = new ReflectionProperty(CoordinatorManager::class, 'container');
        $container = $coordinators->getValue();
        $container[$identifier] = new TimerEarlyWakeCoordinator;
        $coordinators->setValue(null, $container);
        $result = new Channel(1);
        $interval = 0.02;
        $startedAt = hrtime(true);

        try {
            (new Timer)->after($interval, static function (bool $isClosing) use ($result): void {
                $result->push([$isClosing, hrtime(true)]);
            }, $identifier);

            $callback = $result->pop(0.1);

            $this->assertIsArray($callback);
            $this->assertFalse($callback[0]);
            $this->assertGreaterThanOrEqual(
                $interval,
                ($callback[1] - $startedAt) / 1_000_000_000,
            );
        } finally {
            CoordinatorManager::clear($identifier);
        }
    }

    public function testAfterWhenClosing(): void
    {
        $this->wait(function () {
            $id = 0;
            $timer = new Timer;
            $identifier = uniqid();
            $timer->after(10.0, function ($isClosing) use (&$id) {
                ++$id;
                $this->assertTrue($isClosing);
            }, $identifier);

            $this->assertSame(0, $id);
            CoordinatorManager::until($identifier)->resume();
            $this->assertSame(1, $id);
        });
    }

    public function testAfterUsesTheCoordinatorCapturedBeforeTheChildStarts(): void
    {
        $identifier = uniqid();
        $resumed = false;
        $result = new Channel(1);

        Coroutine::afterCreated(function () use ($identifier, &$resumed): void {
            if ($resumed) {
                return;
            }

            $resumed = true;
            CoordinatorManager::until($identifier)->resume();
            CoordinatorManager::clear($identifier);
        });

        try {
            (new Timer)->after(-1.0, static function (bool $isClosing) use ($result): void {
                $result->push($isClosing);
            }, $identifier);

            $this->assertTrue($result->pop(0.1));
        } finally {
            Coroutine::flushState();
            CoordinatorManager::clear($identifier);
        }
    }

    public function testClearAllFromCreationHookDoesNotLeaveTheTimerCoroutineRunning(): void
    {
        $timer = new Timer;
        $callbackCalled = false;

        Coroutine::afterCreated(static function () use ($timer): void {
            $timer->clearAll();
        });

        try {
            $timer->until(static function () use (&$callbackCalled): void {
                $callbackCalled = true;
            }, uniqid());

            usleep(1_000);

            $this->assertFalse($callbackCalled);
            $this->assertSame(['num' => 0, 'round' => 0], Timer::stats());
        } finally {
            Coroutine::flushState();
        }
    }

    public function testCancellationDuringStartupReportingRollsBackThePublishedTimer(): void
    {
        $handler = m::mock(ExceptionHandlerContract::class);
        Container::getInstance()->instance(ExceptionHandlerContract::class, $handler);
        $timer = new Timer;
        $hookFailure = new RuntimeException('The startup hook failed.');
        $reportStarted = new Channel(1);
        $releaseReport = new Channel(1);
        $parentCoroutineId = null;
        $parentCancellation = null;
        $childCoroutineId = null;
        $publishedCoroutineId = null;
        $cancellationRequested = false;
        $callbackCalled = false;

        $handler->shouldReceive('report')
            ->once()
            ->with($hookFailure)
            ->andReturnUsing(static function () use (
                $timer,
                $reportStarted,
                $releaseReport,
                &$childCoroutineId,
                &$publishedCoroutineId,
            ): void {
                $childCoroutineId = EngineCoroutine::id();
                $publishedCoroutineId = array_values(
                    (new ReflectionProperty($timer, 'coroutines'))->getValue($timer),
                )[0] ?? null;
                $reportStarted->push(true);
                $releaseReport->pop();
            });

        Coroutine::afterCreated(static function () use ($hookFailure): void {
            throw $hookFailure;
        });

        $canceller = EngineCoroutine::create(static function () use (
            $reportStarted,
            &$parentCoroutineId,
            &$cancellationRequested,
        ): void {
            $reportStarted->pop();

            if (is_int($parentCoroutineId)) {
                $cancellationRequested = EngineCoroutine::cancelById($parentCoroutineId, throwException: true);
            }
        });

        $parent = EngineCoroutine::create(function () use (
            $timer,
            &$parentCoroutineId,
            &$parentCancellation,
            &$callbackCalled,
        ): void {
            $parentCoroutineId = EngineCoroutine::id();

            try {
                $timer->until(static function () use (&$callbackCalled): void {
                    $callbackCalled = true;
                }, uniqid());
            } catch (CanceledException $exception) {
                $parentCancellation = $exception;
            }
        });

        try {
            $this->assertTrue($cancellationRequested);
            $this->assertInstanceOf(CanceledException::class, $parentCancellation);
            $this->assertIsInt($childCoroutineId);
            $this->assertSame($childCoroutineId, $publishedCoroutineId);
            $this->assertSame([], (new ReflectionProperty($timer, 'coroutines'))->getValue($timer));
            $this->assertFalse($callbackCalled);
        } finally {
            $timer->clearAll();
            $releaseReport->push(true, 0.001);

            if (is_int($childCoroutineId)) {
                Coroutine::join([$childCoroutineId], 1);
            }
        }

        $this->assertFalse($callbackCalled);
        $this->assertFalse(Coroutine::exists($parent->getId()));
        $this->assertFalse(Coroutine::exists($canceller->getId()));
        $this->assertSame(['num' => 0, 'round' => 0], Timer::stats());
    }

    public function testAfterWhenClear(): void
    {
        $this->wait(function () {
            $id = 0;
            $timer = new Timer;
            $identifier = uniqid();
            $ret = $timer->after(10.0, function () use (&$id) {
                ++$id;
            }, $identifier);
            $timer->clear($ret);
            CoordinatorManager::until($identifier)->resume();
            $this->assertSame(0, $id);
        });
    }

    public function testClearReleasesAWaitingTimerCoroutine(): void
    {
        $timer = new Timer;
        $id = $timer->until(static function (): void {
        }, uniqid());

        $this->assertSame(['num' => 1, 'round' => 0], Timer::stats());

        $timer->clear($id);
        usleep(1_000);

        $this->assertSame(['num' => 0, 'round' => 0], Timer::stats());
    }

    public function testClearAllReleasesEveryWaitingTimerCoroutine(): void
    {
        $timer = new Timer;
        $timer->until(static function (): void {
        }, uniqid());
        $timer->until(static function (): void {
        }, uniqid());

        $this->assertSame(['num' => 2, 'round' => 0], Timer::stats());

        $timer->clearAll();
        usleep(1_000);

        $this->assertSame(['num' => 0, 'round' => 0], Timer::stats());
    }

    public function testClearDoesNotInterruptAYieldingCallback(): void
    {
        $timer = new Timer;
        $callbackStarted = new Channel(1);
        $continueCallback = new Channel(1);
        $callbackResult = new Channel(1);

        $id = $timer->after(0.001, static function () use ($callbackStarted, $continueCallback, $callbackResult): void {
            $callbackStarted->push(true);
            $callbackResult->push($continueCallback->pop(0.1));
        }, uniqid());

        $this->assertTrue($callbackStarted->pop(0.1));

        $timer->clear($id);
        $continueCallback->push(true);

        $this->assertTrue($callbackResult->pop(0.1));
    }

    public function testTick(): void
    {
        $this->wait(function () {
            $id = 0;
            $timer = new Timer;
            $identifier = uniqid();
            $timer->tick(0.001, function () use (&$id) {
                ++$id;
            }, $identifier);
            usleep(10000);
            CoordinatorManager::until($identifier)->resume();
            $this->assertGreaterThanOrEqual(1, $id);
        });
    }

    public function testTickRechecksElapsedTimeAfterAnEarlyCoordinatorWake(): void
    {
        $identifier = uniqid();
        $coordinators = new ReflectionProperty(CoordinatorManager::class, 'container');
        $container = $coordinators->getValue();
        $container[$identifier] = new TimerEarlyWakeCoordinator;
        $coordinators->setValue(null, $container);
        $result = new Channel(1);
        $interval = 0.02;
        $startedAt = hrtime(true);

        try {
            (new Timer)->tick($interval, static function (bool $isClosing) use ($result): string {
                $result->push([$isClosing, hrtime(true)]);

                return Timer::STOP;
            }, $identifier);

            $callback = $result->pop(0.1);

            $this->assertIsArray($callback);
            $this->assertFalse($callback[0]);
            $this->assertGreaterThanOrEqual(
                $interval,
                ($callback[1] - $startedAt) / 1_000_000_000,
            );
        } finally {
            CoordinatorManager::clear($identifier);
        }
    }

    public function testTickWhenReturnStop(): void
    {
        $this->wait(function () {
            $id = 0;
            $timer = new Timer;
            $identifier = uniqid();
            $completed = new Channel(1);
            $timer->tick(0.001, function () use (&$id, $completed) {
                ++$id;
                if ($id >= 10) {
                    $completed->push(true);

                    return Timer::STOP;
                }
            }, $identifier);
            $this->assertTrue(
                $completed->pop(1.0),
                'The recurring timer did not stop after its tenth tick within 1 second.',
            );
            $this->assertSame(10, $id);
        });
    }

    public function testTickStopsSilentlyWhenItsCallbackIsCancelled(): void
    {
        $logs = 0;
        $logger = m::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->zeroOrMoreTimes()->andReturnUsing(
            static function () use (&$logs): void {
                ++$logs;
            },
        );
        $timer = new Timer($logger);
        $calls = 0;
        $started = new Channel(1);

        $timer->tick(0.001, static function () use (&$calls, $started): string {
            ++$calls;
            $started->push(true);

            throw new CanceledException;
        }, uniqid());

        $this->assertTrue($started->pop(1.0));
        usleep(10_000);

        $this->assertSame(1, $calls);
        $this->assertSame(0, $logs);
        $this->assertSame(['num' => 0, 'round' => 0], Timer::stats());
    }

    public function testTickClearedFromItsCallbackDoesNotWaitAnotherInterval(): void
    {
        $timer = new Timer;
        $called = new Channel(1);
        $id = null;

        $id = $timer->tick(0.1, function () use ($timer, &$id, $called): void {
            $timer->clear($id);
            $called->push(true);
        }, uniqid());

        $this->assertTrue($called->pop(1.0));
        usleep(10_000);
        $this->assertSame(['num' => 0, 'round' => 0], Timer::stats());
    }

    public function testTickReportsThroughTheConfiguredLoggerAndContinues(): void
    {
        $this->wait(function (): void {
            $exception = new RuntimeException('recurring timer failed');
            $logger = m::mock(LoggerInterface::class);
            $logger->shouldReceive('error')->once()->with((string) $exception);
            $timer = new Timer($logger);
            $calls = 0;
            $completed = new Channel(1);

            $timer->tick(0.001, function () use (&$calls, $completed, $exception): ?string {
                if (++$calls === 1) {
                    throw $exception;
                }

                $completed->push(true);

                return Timer::STOP;
            }, uniqid());

            $this->assertTrue(
                $completed->pop(1.0),
                'The recurring timer did not reach its second tick within 1 second.',
            );
            $this->assertSame(2, $calls);
        });
    }

    public function testTickFallsBackToThePhpErrorLogAndContinues(): void
    {
        $directory = ParallelTesting::tempDir('TimerTest');
        (new Filesystem)->deleteDirectory($directory);
        mkdir($directory, 0777, true);
        $errorLog = $directory . '/php-error.log';
        $previousErrorLog = ini_set('error_log', $errorLog);
        $previousLogErrors = ini_set('log_errors', '1');

        try {
            $this->wait(function (): void {
                $timer = new Timer;
                $calls = 0;
                $completed = new Channel(1);

                $timer->tick(0.001, function () use (&$calls, $completed): ?string {
                    if (++$calls === 1) {
                        throw new RuntimeException('recurring timer fallback failed');
                    }

                    $completed->push(true);

                    return Timer::STOP;
                }, uniqid());

                $this->assertTrue(
                    $completed->pop(1.0),
                    'The recurring timer did not reach its second tick within 1 second.',
                );
                $this->assertSame(2, $calls);
            });

            $contents = file_get_contents($errorLog);
            $this->assertIsString($contents);
            $this->assertStringContainsString('recurring timer fallback failed', $contents);
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

    public function testClearDontExistsClosure(): void
    {
        $timer = new Timer;

        $timer->clear(999);

        $this->assertTrue(true);
    }

    public function testUntil(): void
    {
        $this->wait(function () {
            $id = 0;
            $timer = new Timer;
            $identifier = uniqid();
            $timer->until(function () use (&$id) {
                ++$id;
            }, $identifier);

            $this->assertSame(0, $id);
            CoordinatorManager::until($identifier)->resume();
            $this->assertSame(1, $id);
        });
    }

    public function testUntilWhenClear(): void
    {
        $this->wait(function () {
            $id = 0;
            $timer = new Timer;
            $identifier = uniqid();
            $ret = $timer->until(function () use (&$id) {
                ++$id;
            }, $identifier);
            $timer->clear($ret);
            $this->assertSame(0, $id);
            CoordinatorManager::until($identifier)->resume();
            $this->assertSame(0, $id);
        });
    }

    public function testFlushStateRestoresTimerStats(): void
    {
        (new ReflectionProperty(Timer::class, 'count'))->setValue(null, 3);
        (new ReflectionProperty(Timer::class, 'round'))->setValue(null, 7);

        $this->assertSame(['num' => 3, 'round' => 7], Timer::stats());

        Timer::flushState();

        $this->assertSame(['num' => 0, 'round' => 0], Timer::stats());
    }

    private function wait(Closure $closure): void
    {
        $waiter = new Waiter;
        $waiter->wait($closure);
    }
}

class TimerEarlyWakeCoordinator extends Coordinator
{
    private bool $returnedEarly = false;

    public function yield(float|int $timeout = -1): bool
    {
        if (! $this->returnedEarly) {
            $this->returnedEarly = true;

            return false;
        }

        return parent::yield($timeout);
    }
}
