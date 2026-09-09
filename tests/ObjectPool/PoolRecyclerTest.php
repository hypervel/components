<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool;

use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\ObjectPool\Factory;
use Hypervel\Contracts\ObjectPool\ObjectPool;
use Hypervel\Coordinator\Timer;
use Hypervel\Coroutine\Coroutine;
use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\ObjectPool\PoolRecycler;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;
use Swoole\Coroutine\CanceledException;

class PoolRecyclerTest extends TestCase
{
    /** @var list<PoolManager> */
    private array $poolManagers = [];

    protected function tearDownInCoroutine(): void
    {
        foreach ($this->poolManagers as $poolManager) {
            $poolManager->purgeAll();
        }
    }

    public function testIdlePoolIsEvictedByIdentityAndExactInstance(): void
    {
        $pool = m::mock(ObjectPool::class);
        $pool->shouldReceive('isIdleExpired')->once()->andReturnTrue();
        $manager = m::mock(Factory::class);
        $manager->shouldReceive('getPools')->once()->andReturn(['idle' => $pool]);
        $manager->shouldReceive('purge')->once()->with('idle', $pool)->andReturnTrue();

        (new InspectablePoolRecycler($manager))->maintain();
    }

    public function testReplacementPoolSurvivesAStaleEvictionSnapshot(): void
    {
        $manager = $this->poolManager();
        $definition = $this->definition('shared');
        $replacement = $manager->getOrCreate($definition, static fn (): object => new stdClass);
        $stale = m::mock(ObjectPool::class);
        $stale->shouldReceive('isIdleExpired')->once()->andReturnTrue();
        $snapshotManager = m::mock(Factory::class);
        $snapshotManager->shouldReceive('getPools')->once()->andReturn(['shared' => $stale]);
        $snapshotManager->shouldReceive('purge')
            ->once()
            ->with('shared', $stale)
            ->andReturnUsing(fn (): bool => $manager->purge('shared', $stale));

        (new InspectablePoolRecycler($snapshotManager))->maintain();

        $this->assertSame($replacement, $manager->get('shared'));
        $this->assertFalse($replacement->isClosed());
    }

    public function testSuspendedFactoryPreventsIdleEviction(): void
    {
        $manager = $this->poolManager();
        $definition = new PoolDefinition(
            'suspended',
            'service',
            'auto:suspended',
            PoolOptions::fromArray(['pool_idle_timeout' => 0.001]),
        );
        $pool = $manager->getOrCreate($definition, function (): object {
            usleep(10_000);

            return new stdClass;
        });
        $borrowed = null;

        Coroutine::create(function () use ($pool, &$borrowed): void {
            $borrowed = $pool->borrow();
        });

        usleep(3_000);
        (new InspectablePoolRecycler($manager))->maintain();

        $this->assertTrue($manager->has('suspended'));
        $this->assertFalse($pool->isClosed());

        usleep(10_000);
        $this->assertInstanceOf(stdClass::class, $borrowed);
        $pool->release($borrowed);
    }

    public function testParkedWaiterAndBorrowedObjectPreventIdleEviction(): void
    {
        $manager = $this->poolManager();
        $definition = new PoolDefinition(
            'waiting',
            'service',
            'auto:waiting',
            PoolOptions::fromArray([
                'max_objects' => 1,
                'wait_timeout' => 0.2,
                'pool_idle_timeout' => 0.001,
            ]),
        );
        $pool = $manager->getOrCreate($definition, static fn (): object => new stdClass);
        $borrowed = $pool->borrow();
        $waiterBorrow = null;

        Coroutine::create(function () use ($pool, &$waiterBorrow): void {
            $waiterBorrow = $pool->borrow();
        });

        usleep(3_000);
        (new InspectablePoolRecycler($manager))->maintain();

        $this->assertTrue($manager->has('waiting'));
        $pool->release($borrowed);
        usleep(3_000);
        $this->assertInstanceOf(stdClass::class, $waiterBorrow);
        $pool->release($waiterBorrow);
    }

    public function testNonIdlePoolsAreSweptAndTrimmed(): void
    {
        $pool = m::mock(ObjectPool::class);
        $pool->shouldReceive('isIdleExpired')->once()->andReturnFalse();
        $pool->shouldReceive('sweepExpired')->once()->ordered();
        $pool->shouldReceive('trimIdle')->once()->ordered();
        $manager = m::mock(Factory::class);
        $manager->shouldReceive('getPools')->once()->andReturn(['active' => $pool]);

        (new InspectablePoolRecycler($manager))->maintain();
    }

    public function testPoolFailureIsReportedWithoutSkippingLaterPools(): void
    {
        $failure = new RuntimeException('sweep failed');
        $reported = null;
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')
            ->once()
            ->andReturnUsing(function (RuntimeException $exception) use (&$reported): void {
                $reported = $exception;
            });
        $container = new Container;
        $container->instance(ExceptionHandler::class, $handler);
        Container::setInstance($container);

        $failingPool = m::mock(ObjectPool::class);
        $failingPool->shouldReceive('isIdleExpired')->once()->andReturnFalse();
        $failingPool->shouldReceive('sweepExpired')->once()->andThrow($failure);
        $failingPool->shouldNotReceive('trimIdle');
        $healthyPool = m::mock(ObjectPool::class);
        $healthyPool->shouldReceive('isIdleExpired')->once()->ordered()->andReturnFalse();
        $healthyPool->shouldReceive('sweepExpired')->once()->ordered();
        $healthyPool->shouldReceive('trimIdle')->once()->ordered();
        $manager = m::mock(Factory::class);
        $manager->shouldReceive('getPools')->once()->andReturn([
            'failing' => $failingPool,
            'healthy' => $healthyPool,
        ]);

        (new InspectablePoolRecycler($manager))->maintain();

        $this->assertInstanceOf(RuntimeException::class, $reported);
        $this->assertSame('Pool maintenance failed for [failing].', $reported->getMessage());
        $this->assertSame($failure, $reported->getPrevious());
    }

    public function testStartIsIdempotentAndStopClearsTheTimer(): void
    {
        $timer = m::mock(Timer::class);
        $timer->shouldReceive('tick')
            ->twice()
            ->with(1.0, m::type('Closure'))
            ->andReturn(99, 100);
        $timer->shouldReceive('clear')->once()->with(99);
        $timer->shouldReceive('clear')->once()->with(100);
        $recycler = new PoolRecycler(m::mock(Factory::class), 1.0, $timer);

        $recycler->start();
        $recycler->start();
        $recycler->stop();
        $recycler->stop();
        $recycler->start();
        $recycler->stop();
    }

    #[DataProvider('maintenanceCancellationPaths')]
    public function testCancellationStopsMaintenanceWithoutReporting(bool $scheduled, string $operation): void
    {
        $cancellation = new CanceledException('maintenance canceled');
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldNotReceive('report');
        $container = new Container;
        $container->instance(ExceptionHandler::class, $handler);
        Container::setInstance($container);

        $pool = m::mock(ObjectPool::class);
        $pool->shouldReceive('isIdleExpired')->once()->andReturn($operation === 'purge');
        $manager = m::mock(Factory::class);
        $manager->shouldReceive('getPools')->once()->andReturn([
            'canceled' => $pool,
            'later' => m::mock(ObjectPool::class),
        ]);

        if ($operation === 'purge') {
            $manager->shouldReceive('purge')->once()->with('canceled', $pool)->andThrow($cancellation);
        } else {
            $pool->shouldReceive('sweepExpired')->once()->andThrow($cancellation);
            $pool->shouldNotReceive('trimIdle');
        }

        $callback = null;
        $timer = m::mock(Timer::class);
        $recycler = new InspectablePoolRecycler($manager, timer: $timer);

        if ($scheduled) {
            $timer->shouldReceive('tick')->once()->andReturnUsing(
                function (float $interval, callable $scheduled) use (&$callback): int {
                    $callback = $scheduled;

                    return 99;
                },
            );
            $timer->shouldReceive('clear')->once()->with(99);
            $recycler->start();
            $this->assertIsCallable($callback);
        }

        $caught = null;

        try {
            $scheduled ? $callback() : $recycler->maintain();
        } catch (CanceledException $exception) {
            $caught = $exception;
        } finally {
            $recycler->stop();
        }

        $this->assertSame($cancellation, $caught);
    }

    public static function maintenanceCancellationPaths(): array
    {
        return [
            'direct eviction' => [false, 'purge'],
            'direct sweep' => [false, 'sweepExpired'],
            'scheduled eviction' => [true, 'purge'],
            'scheduled sweep' => [true, 'sweepExpired'],
        ];
    }

    public function testTimerReportsMaintenanceFailures(): void
    {
        $failure = new RuntimeException('maintenance failed');
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with($failure);
        $container = new Container;
        $container->instance(ExceptionHandler::class, $handler);
        Container::setInstance($container);

        $manager = m::mock(Factory::class);
        $manager->shouldReceive('getPools')->once()->andThrow($failure);
        $callback = null;
        $timer = m::mock(Timer::class);
        $timer->shouldReceive('tick')
            ->once()
            ->andReturnUsing(function (float $interval, callable $scheduled) use (&$callback): int {
                $callback = $scheduled;

                return 99;
            });
        $timer->shouldReceive('clear')->once()->with(99);
        $recycler = new PoolRecycler($manager, 1.0, $timer);
        $recycler->start();

        try {
            $this->assertIsCallable($callback);
            $callback();
        } finally {
            $recycler->stop();
        }
    }

    #[DataProvider('invalidIntervals')]
    public function testConstructorRejectsInvalidIntervals(float $interval): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The recycler interval must be a finite number greater than 0.');

        new PoolRecycler(m::mock(Factory::class), $interval);
    }

    public static function invalidIntervals(): array
    {
        return [[0.0], [-1.0], [NAN], [INF], [-INF]];
    }

    public function testFinitePositiveIntervalCanBeConfiguredAtConstruction(): void
    {
        $recycler = new PoolRecycler(m::mock(Factory::class), 2.5);

        $this->assertSame(2.5, $recycler->getInterval());
    }

    /**
     * Create a tracked pool manager.
     */
    private function poolManager(): PoolManager
    {
        $this->poolManagers[] = $poolManager = new PoolManager;

        return $poolManager;
    }

    private function definition(string $identity): PoolDefinition
    {
        return new PoolDefinition(
            $identity,
            'service',
            'auto:' . $identity,
            PoolOptions::fromArray([]),
        );
    }
}

class InspectablePoolRecycler extends PoolRecycler
{
    public function maintain(): void
    {
        $this->maintainPools();
    }
}
