<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Container\Container;
use Hypervel\Contracts\Database\ConcurrencyErrorDetector as ConcurrencyErrorDetectorContract;
use Hypervel\Contracts\Database\LostConnectionDetector as LostConnectionDetectorContract;
use Hypervel\Contracts\Queue\EntityResolver;
use Hypervel\Core\Events\BeforeServerFork;
use Hypervel\Core\Events\BeforeWorkerStart;
use Hypervel\Core\Events\TaskTerminated;
use Hypervel\Database\ConcurrencyErrorDetector;
use Hypervel\Database\DatabaseServiceProvider;
use Hypervel\Database\DetectsConcurrencyErrors;
use Hypervel\Database\DetectsLostConnections;
use Hypervel\Database\Eloquent\QueueEntityResolver;
use Hypervel\Database\LostConnectionDetector;
use Hypervel\Events\Dispatcher;
use Hypervel\Testbench\TestCase;
use PDOException;
use RuntimeException;
use Swoole\Constant;
use Throwable;

class DatabaseServiceProviderTest extends TestCase
{
    public function testConcurrencyErrorDetectorIsRegistered(): void
    {
        $this->assertInstanceOf(
            ConcurrencyErrorDetector::class,
            $this->app->make(ConcurrencyErrorDetectorContract::class),
        );
    }

    public function testConcurrencyErrorDetectorCanBeOverridden(): void
    {
        $detector = new class implements ConcurrencyErrorDetectorContract {
            public function causedByConcurrencyError(Throwable $e): bool
            {
                return $e->getMessage() === 'testing override';
            }
        };

        $this->app->instance(ConcurrencyErrorDetectorContract::class, $detector);

        // The provider must not overwrite an existing application binding.
        (new DatabaseServiceProvider($this->app))->register();

        $this->assertSame($detector, $this->app->make(ConcurrencyErrorDetectorContract::class));

        $subject = new class {
            use DetectsConcurrencyErrors;

            public function detects(Throwable $exception): bool
            {
                return $this->causedByConcurrencyError($exception);
            }
        };

        $this->assertTrue($subject->detects(new RuntimeException('testing override')));
    }

    public function testLostConnectionDetectorIsRegistered(): void
    {
        $this->assertInstanceOf(
            LostConnectionDetector::class,
            $this->app->make(LostConnectionDetectorContract::class),
        );
    }

    public function testLostConnectionDetectorCanBeOverridden(): void
    {
        $detector = new class implements LostConnectionDetectorContract {
            public int $calls = 0;

            public function causedByLostConnection(Throwable $e): bool
            {
                ++$this->calls;

                return $e->getMessage() === 'testing override';
            }
        };

        $this->app->instance(LostConnectionDetectorContract::class, $detector);

        // The provider must not overwrite an existing application binding.
        (new DatabaseServiceProvider($this->app))->register();

        $this->assertSame($detector, $this->app->make(LostConnectionDetectorContract::class));

        $subject = new class {
            use DetectsLostConnections;

            public function detects(Throwable $exception): bool
            {
                return $this->causedByLostConnection($exception);
            }
        };

        $this->assertTrue($subject->detects(new RuntimeException('testing override')));
        $this->assertSame(1, $detector->calls);
    }

    public function testDetectorTraitsRetainTheirBareContainerFallbacks(): void
    {
        $originalContainer = Container::getInstance();
        Container::setInstance(new Container);

        try {
            $concurrencySubject = new class {
                use DetectsConcurrencyErrors;

                public function detects(Throwable $exception): bool
                {
                    return $this->causedByConcurrencyError($exception);
                }
            };
            $lostConnectionSubject = new class {
                use DetectsLostConnections;

                public function detects(Throwable $exception): bool
                {
                    return $this->causedByLostConnection($exception);
                }
            };

            $this->assertTrue($concurrencySubject->detects(new PDOException('database is locked')));
            $this->assertTrue($lostConnectionSubject->detects(new RuntimeException('server has gone away')));
        } finally {
            Container::setInstance($originalContainer);
        }
    }

    public function testQueueEntityResolverIsRegistered(): void
    {
        $this->assertInstanceOf(
            QueueEntityResolver::class,
            $this->app->make(EntityResolver::class),
        );
    }

    public function testNonCoroutineTaskLifecycleListenersAreRegistered(): void
    {
        $events = $this->bootProviderWithTaskCoroutines(false);

        $this->assertTrue($events->hasListeners(TaskTerminated::class));
        $this->assertTrue($events->hasListeners(BeforeServerFork::class));
        $this->assertTrue($events->hasListeners(BeforeWorkerStart::class));
    }

    public function testCoroutineTasksDoNotRegisterTerminalTaskCleanup(): void
    {
        $events = $this->bootProviderWithTaskCoroutines(true);

        $this->assertFalse($events->hasListeners(TaskTerminated::class));
        $this->assertTrue($events->hasListeners(BeforeServerFork::class));
        $this->assertTrue($events->hasListeners(BeforeWorkerStart::class));
    }

    /**
     * Boot a fresh provider against an isolated event dispatcher.
     */
    private function bootProviderWithTaskCoroutines(bool $enabled): Dispatcher
    {
        $this->app->make('config')->set(
            'server.settings.' . Constant::OPTION_TASK_ENABLE_COROUTINE,
            $enabled,
        );

        $events = new Dispatcher($this->app);
        $this->app->instance('events', $events);

        (new DatabaseServiceProvider($this->app))->boot();

        return $events;
    }
}
