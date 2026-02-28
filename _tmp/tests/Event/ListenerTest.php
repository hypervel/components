<?php

declare(strict_types=1);

namespace Hypervel\Tests\Event;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Event\Contracts\ListenerProvider as ListenerProviderContract;
use Hypervel\Event\EventDispatcher;
use Hypervel\Event\ListenerProvider;
use Hypervel\Event\ListenerProviderFactory;
use Hypervel\Tests\Event\Stub\Alpha;
use Hypervel\Tests\Event\Stub\AlphaListener;
use Hypervel\Tests\Event\Stub\Beta;
use Hypervel\Tests\Event\Stub\BetaListener;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class ListenerTest extends TestCase
{
    public function testInvokeListenerProvider()
    {
        $listenerProvider = new ListenerProvider();
        $this->assertInstanceOf(ListenerProviderContract::class, $listenerProvider);
        $this->assertTrue(is_array($listenerProvider->listeners));
    }

    public function testInvokeListenerProviderWithListeners(): void
    {
        $listenerProvider = new ListenerProvider();
        $this->assertInstanceOf(ListenerProviderContract::class, $listenerProvider);

        $listenerProvider->on(Alpha::class, [new AlphaListener(), 'process']);
        $listenerProvider->on(Beta::class, [new BetaListener(), 'process']);
        $this->assertTrue(is_array($listenerProvider->listeners));
        $this->assertSame(2, count($listenerProvider->listeners));
        // getListenersForEvent now returns an array (Laravel-style)
        $this->assertIsArray($listenerProvider->getListenersForEvent(new Alpha()));
    }

    public function testListenerProcess()
    {
        $listenerProvider = new ListenerProvider();
        $listenerProvider->on(Alpha::class, [$listener = new AlphaListener(), 'process']);
        $this->assertSame(1, $listener->value);

        $dispatcher = new EventDispatcher($listenerProvider);
        $dispatcher->dispatch(new Alpha());
        $this->assertSame(2, $listener->value);
    }

    public function testListenerInvokeByFactory()
    {
        $container = m::mock(Container::class);
        $container->shouldReceive('make')->once()->with('config')->andReturn(new Repository([]));
        $container->shouldReceive('make')
            ->once()
            ->with(ListenerProviderContract::class)
            ->andReturn((new ListenerProviderFactory())($container));
        $listenerProvider = $container->make(ListenerProviderContract::class);
        $this->assertInstanceOf(ListenerProviderContract::class, $listenerProvider);
    }

    public function testListenerInvokeByFactoryWithConfig()
    {
        $container = m::mock(Container::class);
        $container->shouldReceive('make')->once()->with('config')->andReturn(new Repository([
            'listeners' => [
                AlphaListener::class,
                BetaListener::class,
            ],
        ]));
        $container->shouldReceive('make')
            ->with(AlphaListener::class)
            ->andReturn($alphaListener = new AlphaListener());
        $container->shouldReceive('make')
            ->with(BetaListener::class)
            ->andReturn($betaListener = new BetaListener());
        $container->shouldReceive('make')
            ->once()
            ->with(ListenerProviderContract::class)
            ->andReturn((new ListenerProviderFactory())($container));
        $listenerProvider = $container->make(ListenerProviderContract::class);
        $this->assertInstanceOf(ListenerProviderContract::class, $listenerProvider);
        $this->assertSame(2, count($listenerProvider->listeners));

        $dispatcher = new EventDispatcher($listenerProvider);
        $this->assertSame(1, $alphaListener->value);
        $dispatcher->dispatch(new Alpha());
        $this->assertSame(2, $alphaListener->value);
        $this->assertSame(1, $betaListener->value);
        $dispatcher->dispatch(new Beta());
        $this->assertSame(2, $betaListener->value);
    }
}
