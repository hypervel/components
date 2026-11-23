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
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;
use Swoole\Coroutine\CanceledException;

class PoolRecyclerTest extends TestCase
{
    use VerifiesDoubles;

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
        $pool = Double::for(ObjectPool::class)->strict();
        $pool->expects('isIdleExpired')->returns(true);
        $manager = Double::for(Factory::class)->strict();
        $manager->expects('getPools')->returns(['idle' => $pool]);
        $manager->expects('purge')->with('idle', Argument::same($pool))->returns(true);

        (new InspectablePoolRecycler($manager))->maintain();
    }

    public function testReplacementPoolSurvivesAStaleEvictionSnapshot(): void
    {
        $manager = $this->poolManager();
        $definition = $this->definition('shared');
        $replacement = $manager->getOrCreate($definition, static fn (): object => new stdClass);
        $stale = Double::for(ObjectPool::class)->strict();
        $stale->expects('isIdleExpired')->returns(true);
        $snapshotManager = Double::for(Factory::class)->strict();
        $snapshotManager->expects('getPools')->returns(['shared' => $stale]);
        $snapshotManager->expects('purge')
            ->with('shared', Argument::same($stale))
            ->resolves(fn (): bool => $manager->purge('shared', $stale));

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
        $pool = Double::for(ObjectPool::class)->strict();
        $pool->expects('isIdleExpired')->returns(false);
        $pool->expects('sweepExpired')->ordered();
        $pool->expects('trimIdle')->ordered();
        $manager = Double::for(Factory::class)->strict();
        $manager->expects('getPools')->returns(['active' => $pool]);

        (new InspectablePoolRecycler($manager))->maintain();
    }

    public function testPoolFailureIsReportedWithoutSkippingLaterPools(): void
    {
        $failure = new RuntimeException('sweep failed');
        $reported = null;
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')
            ->resolves(function (RuntimeException $exception) use (&$reported): void {
                $reported = $exception;
            });
        $container = new Container;
        $container->instance(ExceptionHandler::class, $handler);
        Container::setInstance($container);

        $failingPool = Double::for(ObjectPool::class)->strict();
        $failingPool->expects('isIdleExpired')->returns(false);
        $failingPool->expects('sweepExpired')->throws($failure);
        $failingPool->expects('trimIdle')->never();
        $healthyPool = Double::for(ObjectPool::class)->strict();
        $healthyPool->expects('isIdleExpired')->ordered()->returns(false);
        $healthyPool->expects('sweepExpired')->ordered();
        $healthyPool->expects('trimIdle')->ordered();
        $manager = Double::for(Factory::class)->strict();
        $manager->expects('getPools')->returns([
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
        $timer = Double::for(Timer::class)->strict();
        $timer->expects('tick')
            ->times(2)
            ->with(1.0, Argument::type('Closure'))
            ->returns(99, 100);
        $timer->expects('clear')->with(99);
        $timer->expects('clear')->with(100);
        $recycler = new PoolRecycler(Double::for(Factory::class)->strict(), 1.0, $timer);

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
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->never();
        $container = new Container;
        $container->instance(ExceptionHandler::class, $handler);
        Container::setInstance($container);

        $pool = Double::for(ObjectPool::class)->strict();
        $pool->expects('isIdleExpired')->returns($operation === 'purge');
        $manager = Double::for(Factory::class)->strict();
        $manager->expects('getPools')->returns([
            'canceled' => $pool,
            'later' => Double::for(ObjectPool::class)->strict(),
        ]);

        if ($operation === 'purge') {
            $manager->expects('purge')->with('canceled', Argument::same($pool))->throws($cancellation);
        } else {
            $pool->expects('sweepExpired')->throws($cancellation);
            $pool->expects('trimIdle')->never();
        }

        $callback = null;
        $timer = Double::for(Timer::class)->strict();
        $recycler = new InspectablePoolRecycler($manager, timer: $timer);

        if ($scheduled) {
            $timer->expects('tick')->resolves(
                function (float $interval, callable $scheduled) use (&$callback): int {
                    $callback = $scheduled;

                    return 99;
                },
            );
            $timer->expects('clear')->with(99);
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
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->with(Argument::same($failure));
        $container = new Container;
        $container->instance(ExceptionHandler::class, $handler);
        Container::setInstance($container);

        $manager = Double::for(Factory::class)->strict();
        $manager->expects('getPools')->throws($failure);
        $callback = null;
        $timer = Double::for(Timer::class)->strict();
        $timer->expects('tick')
            ->resolves(function (float $interval, callable $scheduled) use (&$callback): int {
                $callback = $scheduled;

                return 99;
            });
        $timer->expects('clear')->with(99);
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
        $this->expectExceptionMessageIs('The recycler interval must be a finite number greater than 0.');

        new PoolRecycler(Double::for(Factory::class)->strict(), $interval);
    }

    public static function invalidIntervals(): array
    {
        return [[0.0], [-1.0], [NAN], [INF], [-INF]];
    }

    public function testFinitePositiveIntervalCanBeConfiguredAtConstruction(): void
    {
        $recycler = new PoolRecycler(Double::for(Factory::class)->strict(), 2.5);

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
