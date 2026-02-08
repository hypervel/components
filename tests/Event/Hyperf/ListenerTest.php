<?php

declare(strict_types=1);

namespace Hypervel\Tests\Event\Hyperf;

use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Config\Repository;
use Hyperf\Event\Annotation\Listener as ListenerAnnotation;
use Hypervel\Event\EventDispatcher;
use Hypervel\Event\ListenerProvider;
use Hypervel\Event\ListenerProviderFactory;
use Hypervel\Tests\Event\Hyperf\Event\Alpha;
use Hypervel\Tests\Event\Hyperf\Event\Beta;
use Hypervel\Tests\Event\Hyperf\Listener\AlphaListener;
use Hypervel\Tests\Event\Hyperf\Listener\BetaListener;
use Hypervel\Tests\TestCase;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery as m;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * @internal
 * @coversNothing
 */
class ListenerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testInvokeListenerProvider()
    {
        $listenerProvider = new ListenerProvider();
        $this->assertInstanceOf(ListenerProviderInterface::class, $listenerProvider);
        $this->assertTrue(is_array($listenerProvider->listeners));
    }

    public function testInvokeListenerProviderWithListeners(): void
    {
        $listenerProvider = new ListenerProvider();
        $this->assertInstanceOf(ListenerProviderInterface::class, $listenerProvider);

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
        $container = m::mock(ContainerInterface::class);
        $container->shouldReceive('get')->once()->with(Repository::class)->andReturn(new ConfigRepository([]));
        $container->shouldReceive('get')
            ->once()
            ->with(ListenerProviderInterface::class)
            ->andReturn((new ListenerProviderFactory())($container));
        $listenerProvider = $container->get(ListenerProviderInterface::class);
        $this->assertInstanceOf(ListenerProviderInterface::class, $listenerProvider);
    }

    public function testListenerInvokeByFactoryWithConfig()
    {
        $container = m::mock(ContainerInterface::class);
        $container->shouldReceive('get')->once()->with(Repository::class)->andReturn(new ConfigRepository([
            'listeners' => [
                AlphaListener::class,
                BetaListener::class,
            ],
        ]));
        $container->shouldReceive('get')
            ->with(AlphaListener::class)
            ->andReturn($alphaListener = new AlphaListener());
        $container->shouldReceive('get')
            ->with(BetaListener::class)
            ->andReturn($betaListener = new BetaListener());
        $container->shouldReceive('get')
            ->once()
            ->with(ListenerProviderInterface::class)
            ->andReturn((new ListenerProviderFactory())($container));
        $listenerProvider = $container->get(ListenerProviderInterface::class);
        $this->assertInstanceOf(ListenerProviderInterface::class, $listenerProvider);
        $this->assertSame(2, count($listenerProvider->listeners));

        $dispatcher = new EventDispatcher($listenerProvider);
        $this->assertSame(1, $alphaListener->value);
        $dispatcher->dispatch(new Alpha());
        $this->assertSame(2, $alphaListener->value);
        $this->assertSame(1, $betaListener->value);
        $dispatcher->dispatch(new Beta());
        $this->assertSame(2, $betaListener->value);
    }

    public function testListenerInvokeByFactoryWithAnnotationConfig()
    {
        $listenerAnnotation = new ListenerAnnotation();
        $listenerAnnotation->collectClass(AlphaListener::class, ListenerAnnotation::class);
        $listenerAnnotation->collectClass(BetaListener::class, ListenerAnnotation::class);

        $container = m::mock(ContainerInterface::class);
        $container->shouldReceive('get')->once()->with(Repository::class)->andReturn(new ConfigRepository([]));
        $container->shouldReceive('get')
            ->with(AlphaListener::class)
            ->andReturn($alphaListener = new AlphaListener());
        $container->shouldReceive('get')
            ->with(BetaListener::class)
            ->andReturn($betaListener = new BetaListener());
        $container->shouldReceive('get')
            ->once()
            ->with(ListenerProviderInterface::class)
            ->andReturn((new ListenerProviderFactory())($container));

        $listenerProvider = $container->get(ListenerProviderInterface::class);
        $this->assertInstanceOf(ListenerProviderInterface::class, $listenerProvider);
        $this->assertSame(2, count($listenerProvider->listeners));

        $dispatcher = new EventDispatcher($listenerProvider);
        $this->assertSame(1, $alphaListener->value);
        $dispatcher->dispatch(new Alpha());
        $this->assertSame(2, $alphaListener->value);
        $this->assertSame(1, $betaListener->value);
        $dispatcher->dispatch(new Beta());
        $this->assertSame(2, $betaListener->value);
    }

    public function testListenerAnnotationExists(): void
    {
        // Hyperf's Listener annotation still exists (for compatibility)
        // but priority is ignored in Hypervel's Laravel-style event system
        $listenerAnnotation = new ListenerAnnotation();
        $this->assertInstanceOf(ListenerAnnotation::class, $listenerAnnotation);
    }
}
