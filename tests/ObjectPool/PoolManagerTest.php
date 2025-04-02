<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool;

use Hyperf\Context\ApplicationContext;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\ObjectPool\ObjectPool;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\ObjectPool\PoolProxy;
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

        $container = m::mock(ContainerInterface::class);
        $container->shouldReceive('get')
            ->with(PoolManager::class)
            ->andReturn($this->manager = new PoolManager($container));
        ApplicationContext::setContainer($container);

        $this->container = $container;
    }

    public function testGetCreatesNewPoolIfNotExists()
    {
        $this->manager = new PoolManager($this->container);
        $name = 'test-pool';
        $callback = fn () => new Bar();

        $pool = $this->manager->createPool($name, $callback);

        $this->assertInstanceOf(ObjectPool::class, $pool);
        $this->assertTrue($this->manager->hasPool($name));
        $this->assertSame($pool, $this->manager->pools()[$name]);
    }

    public function testCreatePoolThrowsExceptionIfExisted()
    {
        $this->manager = new PoolManager($this->container);
        $name = 'duplicate-test-pool';
        $callback = fn () => new Bar();

        $this->manager->createPool($name, $callback);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("The pool name `{$name}` already exists.");

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

    public function testGetPool()
    {
        $name = 'test-pool';
        $callback = fn () => new Bar();

        $pool = $this->manager->createPool($name, $callback);

        $this->assertSame($pool, $this->manager->getPool($name));
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
