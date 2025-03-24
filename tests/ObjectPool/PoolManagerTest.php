<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool;

use Closure;
use Hyperf\Context\ApplicationContext;
use Hyperf\Coordinator\Timer;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\ObjectPool\ObjectPool;
use Hypervel\ObjectPool\ObjectPoolOption;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\ObjectPool\PoolProxy;
use Hypervel\ObjectPool\RecycleStrategies\TimeRecycleStrategy;
use Hypervel\ObjectPool\SimpleObjectPool;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class PoolManagerTest extends TestCase
{
    use RunTestsInCoroutine;

    protected ContainerInterface $container;

    protected PoolManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = m::mock(ContainerInterface::class);
        $this->manager = new PoolManager($this->container, [
            'recycle_interval' => 1,
        ]);
        $this->container->shouldReceive('get')
            ->with(PoolManager::class)
            ->andReturn($this->manager);
        ApplicationContext::setContainer($this->container);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    public function testGetCreatesNewPoolIfNotExists()
    {
        $this->manager = new PoolManager($this->container);
        $name = 'test-pool';
        $callback = fn () => new Bar();
        $options = ['recycle_time' => 10];

        $pool = $this->manager->createPool($name, $callback, $options);

        $this->assertInstanceOf(ObjectPool::class, $pool);
        $this->assertTrue($this->manager->hasPool($name));
        $this->assertSame($pool, $this->manager->pools()[$name]);
    }

    public function testCreatePoolThrowsExceptionForInvalidRecycleTime()
    {
        $this->manager = new PoolManager($this->container, [
            'recycle_interval' => 10,
        ]);
        $name = 'test-pool-invalid';
        $callback = fn () => new Bar();
        $options = [
            'recycle_strategy' => new TimeRecycleStrategy(9),
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The recycle time in the strategy must be greater than the recycle interval.');

        $this->manager->createPool($name, $callback, $options);
    }

    public function testCreatePoolThrowsExceptionForDuplicatePoolName()
    {
        $this->manager = new PoolManager($this->container);
        $name = 'duplicate-test-pool';
        $callback = fn () => new Bar();

        $this->manager->createPool($name, $callback);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("The pool {$name} is already exists.");

        $this->manager->createPool($name, $callback);
    }

    public function testHasPool()
    {
        $this->manager = new PoolManager($this->container);
        $name = 'test-pool';
        $callback = fn () => new Bar();

        $this->assertFalse($this->manager->hasPool($name));

        $this->manager->createPool($name, $callback);

        $this->assertTrue($this->manager->hasPool($name));
    }

    public function testRemovePool()
    {
        $this->manager = new PoolManager($this->container);
        $name = 'test-pool';
        $callback = fn () => new Bar();

        $this->manager->createPool($name, $callback);
        $this->assertTrue($this->manager->hasPool($name));

        $this->manager->removePool($name);

        $this->assertFalse($this->manager->hasPool($name));
        $this->assertEmpty($this->manager->pools());
    }

    public function testFlush()
    {
        $this->manager = new PoolManager($this->container);
        $this->manager->createPool('pool1', fn () => new Bar());
        $this->manager->createPool('pool2', fn () => new Bar());

        $this->assertCount(2, $this->manager->pools());

        $this->manager->flush();

        $this->assertEmpty($this->manager->pools());
    }

    public function testStartAndStopTick()
    {
        // Mock the Timer class
        $this->manager = new PoolManager($this->container, [
            'recycle_interval' => $interval = 1,
        ]);
        $timerMock = m::mock(Timer::class);

        // Mock the timer to expect tick method call
        $timerMock->shouldReceive('tick')
            ->once()
            ->with(
                $interval,
                m::type(Closure::class)
            )
            ->andReturn($timerId = 123); // Return a fake timer ID
        $this->manager->setTimer($timerMock);

        // Call startTick
        $this->manager->startRecycle();

        // Now test stopTick
        $timerMock->shouldReceive('clear')
            ->once()
            ->with($timerId);

        // Call stopTick
        $this->manager->stopRecycle();

        $this->assertNull($this->manager->getTimerId());
    }

    public function testTickRecycle()
    {
        $this->manager = new PoolManager($this->container, [
            'recycle_interval' => 1,
        ]);
        $name = 'test-pool';

        $poolMock = m::mock(SimpleObjectPool::class);
        $poolOptionMock = m::mock(ObjectPoolOption::class);
        $strategyMock = m::mock(TimeRecycleStrategy::class);

        $strategyMock->expects('shouldRecycle')
            ->andReturn(true);
        $strategyMock->expects('recycle')
            ->andReturn(true);
        $poolMock->shouldReceive('getOption')
            ->andReturn($poolOptionMock);
        $poolOptionMock->shouldReceive('getRecycleTime')
            ->andReturn(1);
        $poolOptionMock->shouldReceive('getRecycleStrategy')
            ->andReturn($strategyMock);

        $this->manager->setPools([
            $name => $poolMock,
        ]);

        $this->manager->startRecycle();
    }

    public function testGetPool()
    {
        $name = 'test-pool';
        $callback = fn () => new Bar();

        $pool = $this->manager->createPool($name, $callback);

        $this->assertSame($pool, $this->manager->getPool($name));
    }

    public function testGetTimer()
    {
        $timer = $this->manager->getTimer();
        $this->assertInstanceOf(Timer::class, $timer);

        $this->assertSame($timer, $this->manager->getTimer());
    }

    public function testSetTimer()
    {
        $timer = new Timer();

        $this->manager->setTimer($timer);
        $this->assertSame($timer, $this->manager->getTimer());
    }

    public function testGetTimerId()
    {
        $this->assertNull($this->manager->getTimerId());

        // 設置一個模擬的 Timer
        $timerMock = m::mock(Timer::class);
        $timerMock->shouldReceive('tick')
            ->once()
            ->andReturn(123);
        $this->manager->setTimer($timerMock);

        // 啟動 tick 後，timerId 應該被設置
        $this->manager->startRecycle();
        $this->assertEquals(123, $this->manager->getTimerId());
    }

    public function testGetLastTickedTimestamps()
    {
        $name = 'test-pool';
        $callback = fn () => new Bar();

        $this->manager->createPool($name, $callback);
        $timestamps = $this->manager->getLastRecycledTimestamps();

        $this->assertArrayNotHasKey($name, $timestamps);
    }

    public function testPoolProxyIntegration()
    {
        $bar = new BarPoolProxy(
            BarPoolProxy::class . ':bar',
            fn () => new Bar(),
            [
                'recycle_time' => 10,
            ]
        );

        $this->assertEquals(1, $bar->tick());

        $poolName = BarPoolProxy::class . ':bar';
        $this->assertTrue($this->manager->hasPool($poolName));

        $pool = $this->manager->pools()[$poolName];
        $this->assertGreaterThan(0, $pool->getCurrentObjectNumber());
    }
}

class Bar
{
    public function tick()
    {
        return 1;
    }
}

class BarPoolProxy extends PoolProxy
{
}
