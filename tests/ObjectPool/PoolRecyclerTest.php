<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool;

use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Coordinator\Timer;
use Hypervel\Coroutine\Coroutine;
use Hypervel\ObjectPool\Contracts\Factory;
use Hypervel\ObjectPool\Contracts\ObjectPool;
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

class PoolRecyclerTest extends TestCase
{
    public function testIdlePoolIsEvictedByIdentityAndExactInstance(): void
    {
        $pool = m::mock(ObjectPool::class);
        $pool->shouldReceive('isIdle')->once()->andReturnTrue();
        $manager = m::mock(Factory::class);
        $manager->shouldReceive('pools')->once()->andReturn(['idle' => $pool]);
        $manager->shouldReceive('remove')->once()->with('idle', $pool)->andReturnTrue();

        (new InspectablePoolRecycler($manager))->maintain();
    }

    public function testReplacementPoolSurvivesAStaleEvictionSnapshot(): void
    {
        $manager = new PoolManager;
        $definition = $this->definition('shared');
        $replacement = $manager->getOrCreate($definition, static fn (): object => new stdClass);
        $stale = m::mock(ObjectPool::class);
        $stale->shouldReceive('isIdle')->once()->andReturnTrue();
        $snapshotManager = m::mock(Factory::class);
        $snapshotManager->shouldReceive('pools')->once()->andReturn(['shared' => $stale]);
        $snapshotManager->shouldReceive('remove')
            ->once()
            ->with('shared', $stale)
            ->andReturnUsing(fn (): bool => $manager->remove('shared', $stale));

        (new InspectablePoolRecycler($snapshotManager))->maintain();

        $this->assertSame($replacement, $manager->get('shared'));
        $this->assertFalse($replacement->isClosed());
        $manager->flush();
    }

    public function testSuspendedFactoryPreventsIdleEviction(): void
    {
        $manager = new PoolManager;
        $definition = new PoolDefinition(
            'suspended',
            'service',
            'auto:suspended',
            PoolOptions::fromArray(['idle_ttl' => 0.001]),
        );
        $pool = $manager->getOrCreate($definition, function (): object {
            usleep(10_000);

            return new stdClass;
        });
        $borrowed = null;

        Coroutine::create(function () use ($pool, &$borrowed): void {
            $borrowed = $pool->get();
        });

        usleep(3_000);
        (new InspectablePoolRecycler($manager))->maintain();

        $this->assertTrue($manager->has('suspended'));
        $this->assertFalse($pool->isClosed());

        usleep(10_000);
        $this->assertInstanceOf(stdClass::class, $borrowed);
        $pool->release($borrowed);
        $manager->flush();
    }

    public function testParkedWaiterAndBorrowedObjectPreventIdleEviction(): void
    {
        $manager = new PoolManager;
        $definition = new PoolDefinition(
            'waiting',
            'service',
            'auto:waiting',
            PoolOptions::fromArray([
                'max_objects' => 1,
                'wait_timeout' => 0.2,
                'idle_ttl' => 0.001,
            ]),
        );
        $pool = $manager->getOrCreate($definition, static fn (): object => new stdClass);
        $borrowed = $pool->get();
        $waiterBorrow = null;

        Coroutine::create(function () use ($pool, &$waiterBorrow): void {
            $waiterBorrow = $pool->get();
        });

        usleep(3_000);
        (new InspectablePoolRecycler($manager))->maintain();

        $this->assertTrue($manager->has('waiting'));
        $pool->release($borrowed);
        usleep(3_000);
        $this->assertInstanceOf(stdClass::class, $waiterBorrow);
        $pool->release($waiterBorrow);
        $manager->flush();
    }

    public function testNonIdlePoolsAreSweptAndTrimmed(): void
    {
        $pool = m::mock(ObjectPool::class);
        $pool->shouldReceive('isIdle')->once()->andReturnFalse();
        $pool->shouldReceive('sweepExpired')->once()->ordered();
        $pool->shouldReceive('trimIdle')->once()->ordered();
        $manager = m::mock(Factory::class);
        $manager->shouldReceive('pools')->once()->andReturn(['active' => $pool]);

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
        $failingPool->shouldReceive('isIdle')->once()->andReturnFalse();
        $failingPool->shouldReceive('sweepExpired')->once()->andThrow($failure);
        $failingPool->shouldNotReceive('trimIdle');
        $healthyPool = m::mock(ObjectPool::class);
        $healthyPool->shouldReceive('isIdle')->once()->ordered()->andReturnFalse();
        $healthyPool->shouldReceive('sweepExpired')->once()->ordered();
        $healthyPool->shouldReceive('trimIdle')->once()->ordered();
        $manager = m::mock(Factory::class);
        $manager->shouldReceive('pools')->once()->andReturn([
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
            ->once()
            ->with(1.0, m::type('Closure'))
            ->andReturn(99);
        $timer->shouldReceive('clear')->once()->with(99);
        $recycler = new PoolRecycler(m::mock(Factory::class), 1.0);
        $recycler->setTimer($timer);

        $recycler->start();
        $recycler->start();
        $this->assertSame(99, $recycler->getTimerId());

        $recycler->stop();
        $this->assertNull($recycler->getTimerId());
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
        $manager->shouldReceive('pools')->once()->andThrow($failure);
        $callback = null;
        $timer = m::mock(Timer::class);
        $timer->shouldReceive('tick')
            ->once()
            ->andReturnUsing(function (float $interval, callable $scheduled) use (&$callback): int {
                $callback = $scheduled;

                return 99;
            });
        $recycler = new PoolRecycler($manager, 1.0);
        $recycler->setTimer($timer);
        $recycler->start();

        $this->assertIsCallable($callback);
        $callback();
    }

    #[DataProvider('invalidIntervals')]
    public function testConstructorRejectsInvalidIntervals(float $interval): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The recycler interval must be a finite number greater than 0.');

        new PoolRecycler(m::mock(Factory::class), $interval);
    }

    #[DataProvider('invalidIntervals')]
    public function testSetterRejectsInvalidIntervals(float $interval): void
    {
        $recycler = new PoolRecycler(m::mock(Factory::class));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The recycler interval must be a finite number greater than 0.');

        $recycler->setInterval($interval);
    }

    public static function invalidIntervals(): array
    {
        return [[0.0], [-1.0], [NAN], [INF], [-INF]];
    }

    public function testFinitePositiveIntervalCanBeChanged(): void
    {
        $recycler = new PoolRecycler(m::mock(Factory::class), 1.0);

        $recycler->setInterval(2.5);

        $this->assertSame(2.5, $recycler->getInterval());
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
