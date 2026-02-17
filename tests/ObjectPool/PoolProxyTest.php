<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool;

use Closure;
use Hypervel\Container\Container;
use Hypervel\ObjectPool\Contracts\Factory as PoolFactory;
use Hypervel\ObjectPool\ObjectPool;
use Hypervel\ObjectPool\PoolProxy;
use Mockery as m;
use PHPUnit\Framework\TestCase;

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

        $poolFactory = m::mock(PoolFactory::class);
        $poolFactory->shouldReceive('create')
            ->with('foo', m::type(Closure::class), ['foo' => 'bar'])
            ->once()
            ->andReturn($pool);

        $container = new Container();
        $container->instance(PoolFactory::class, $poolFactory);
        Container::setInstance($container);

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
