<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool;

use Hypervel\Context\ApplicationContext;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSource;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\ObjectPool\Contracts\Factory as PoolFactory;
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

    public function testCreateNewPoolIfNotExists()
    {
        $this->manager = new PoolManager($this->container);
        $name = 'test-pool';
        $callback = fn () => new Bar();

        $pool = $this->manager->create($name, $callback);

        $this->assertInstanceOf(ObjectPool::class, $pool);
        $this->assertTrue($this->manager->has($name));
        $this->assertSame($pool, $this->manager->pools()[$name]);
    }

    public function testCreateThrowsExceptionIfExisted()
    {
        $this->manager = new PoolManager($this->container);
        $name = 'duplicate-test-pool';
        $callback = fn () => new Bar();

        $this->manager->create($name, $callback);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("The pool name `{$name}` already exists.");

        $this->manager->create($name, $callback);
    }

    public function testHas()
    {
        $this->manager = new PoolManager($this->container);
        $name = 'test-pool';
        $callback = fn () => new Bar();

        $this->assertFalse($this->manager->has($name));

        $this->manager->create($name, $callback);

        $this->assertTrue($this->manager->has($name));
    }

    public function testRemovePool()
    {
        $this->manager = new PoolManager($this->container);
        $name = 'test-pool';
        $callback = fn () => new Bar();

        $this->manager->create($name, $callback);
        $this->assertTrue($this->manager->has($name));

        $this->manager->remove($name);

        $this->assertFalse($this->manager->has($name));
        $this->assertEmpty($this->manager->pools());
    }

    public function testFlush()
    {
        $this->manager = new PoolManager($this->container);
        $this->manager->create('pool1', fn () => new Bar());
        $this->manager->create('pool2', fn () => new Bar());

        $this->assertCount(2, $this->manager->pools());

        $this->manager->flush();

        $this->assertEmpty($this->manager->pools());
    }

    public function testGetPool()
    {
        $name = 'test-pool';
        $callback = fn () => new Bar();

        $pool = $this->manager->create($name, $callback);

        $this->assertSame($pool, $this->manager->get($name));
    }

    public function testPoolProxyIntegration()
    {
        $this->mockContainer();

        $bar = new BarPoolProxy(
            BarPoolProxy::class . ':bar',
            fn () => new Bar()
        );

        $this->assertTrue($bar->handle());

        $poolName = BarPoolProxy::class . ':bar';
        $this->assertTrue($this->manager->has($poolName));

        $pool = $this->manager->pools()[$poolName];
        $this->assertGreaterThan(0, $pool->getCurrentObjectNumber());
    }

    protected function mockContainer(): Container
    {
        $container = new Container(
            new DefinitionSource([
                PoolFactory::class => fn () => $this->manager,
            ])
        );

        ApplicationContext::setContainer($container);

        return $container;
    }
}

class Bar
{
    public function handle(): bool
    {
        return true;
    }
}

class BarPoolProxy extends PoolProxy
{
}
