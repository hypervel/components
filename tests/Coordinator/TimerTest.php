<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coordinator;

use Closure;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Coordinator\Timer;
use Hypervel\Coroutine\Waiter;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;

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

            $this->assertSame(0, $id);
            usleep(10000);
            $this->assertSame(1, $id);
        });
    }

    public function testAfterWhenClosing(): void
    {
        $this->wait(function () {
            $id = 0;
            $timer = new Timer;
            $identifier = uniqid();
            $timer->after(0.001, function ($isClosing) use (&$id) {
                ++$id;
                $this->assertTrue($isClosing);
            }, $identifier);

            $this->assertSame(0, $id);
            CoordinatorManager::until($identifier)->resume();
            $this->assertSame(1, $id);
        });
    }

    public function testAfterWhenClear(): void
    {
        $this->wait(function () {
            $id = 0;
            $timer = new Timer;
            $identifier = uniqid();
            $ret = $timer->after(0.001, function () use (&$id) {
                ++$id;
            }, $identifier);
            $timer->clear($ret);
            CoordinatorManager::until($identifier)->resume();
            $this->assertSame(0, $id);
        });
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

    public function testTickWhenReturnStop(): void
    {
        $this->wait(function () {
            $id = 0;
            $timer = new Timer;
            $identifier = uniqid();
            $timer->tick(0.001, function () use (&$id) {
                ++$id;
                if ($id >= 10) {
                    return Timer::STOP;
                }
            }, $identifier);
            usleep(20000);
            $this->assertSame(10, $id);
        });
    }

    public function testTickReportsThroughTheConfiguredLoggerAndContinues(): void
    {
        $this->wait(function (): void {
            $exception = new RuntimeException('recurring timer failed');
            $logger = m::mock(LoggerInterface::class);
            $logger->shouldReceive('error')->once()->with((string) $exception);
            $timer = new Timer($logger);
            $calls = 0;

            $timer->tick(0.001, function () use (&$calls, $exception): ?string {
                if (++$calls === 1) {
                    throw $exception;
                }

                return Timer::STOP;
            }, uniqid());

            usleep(10_000);

            $this->assertSame(2, $calls);
        });
    }

    public function testTickFallsBackToThePhpErrorLogAndContinues(): void
    {
        $directory = ParallelTesting::tempDir('TimerTest');
        mkdir($directory, 0777, true);
        $errorLog = $directory . '/php-error.log';
        $previousErrorLog = ini_set('error_log', $errorLog);
        $previousLogErrors = ini_set('log_errors', '1');

        try {
            $this->wait(function (): void {
                $timer = new Timer;
                $calls = 0;

                $timer->tick(0.001, function () use (&$calls): ?string {
                    if (++$calls === 1) {
                        throw new RuntimeException('recurring timer fallback failed');
                    }

                    return Timer::STOP;
                }, uniqid());

                usleep(10_000);

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
