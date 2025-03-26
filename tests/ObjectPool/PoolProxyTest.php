<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool;

use Closure;
use Hyperf\Context\ApplicationContext;
use Hypervel\ObjectPool\ObjectPool;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\ObjectPool\PoolProxy;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @internal
 * @coversNothing
 */
class PoolProxyTest extends TestCase
{
    public function testCallPoolProxy()
    {
        $pool = m::mock(ObjectPool::class);
        $pool->shouldReceive('get')
            ->once()
            ->andReturn($object = new Foo());
        $pool->shouldReceive('release')
            ->once();

        $poolManager = m::mock(PoolManager::class);
        $poolManager->shouldReceive('createPool')
            ->with('foo', m::type(Closure::class), ['foo' => 'bar'])
            ->once()
            ->andReturn($pool);

        $container = m::mock(ContainerInterface::class);
        $container->shouldReceive('get')
            ->with(PoolManager::class)
            ->once()
            ->andReturn($poolManager);

        ApplicationContext::setContainer($container);

        $proxy = new PoolProxy(
            'foo',
            fn () => $object,
            ['foo' => 'bar'],
            fn ($object) => $object->state = 'released'
        );

        $this->assertSame('init', $object->state);

        $proxy->handle();

        $this->assertSame('released', $object->state);
    }
}

class Foo
{
    public string $state = 'init';

    public function handle(): void
    {
    }
}
