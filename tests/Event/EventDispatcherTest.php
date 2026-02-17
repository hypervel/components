<?php

declare(strict_types=1);

namespace Hypervel\Tests\Event;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Event\Contracts\ListenerProvider as ListenerProviderContract;
use Hypervel\Event\EventDispatcher;
use Hypervel\Event\EventDispatcherFactory;
use Hypervel\Event\ListenerProvider;
use Hypervel\Framework\Logger\StdoutLogger;
use Hypervel\Tests\Event\Stub\Alpha;
use Hypervel\Tests\Event\Stub\AlphaListener;
use Hypervel\Tests\Event\Stub\BetaListener;
use Hypervel\Tests\Event\Stub\PriorityEvent;
use Hypervel\Tests\Event\Stub\PriorityListener;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionClass;

/**
 * @internal
 * @coversNothing
 */
class EventDispatcherTest extends TestCase
{
    public function testInvokeDispatcher()
    {
        $listeners = m::mock(ListenerProviderContract::class);
        $this->assertInstanceOf(Dispatcher::class, new EventDispatcher($listeners));
    }

    public function testInvokeDispatcherWithStdoutLogger()
    {
        $listeners = m::mock(ListenerProviderContract::class);
        $logger = m::mock(StdoutLoggerInterface::class);
        $this->assertInstanceOf(Dispatcher::class, $instance = new EventDispatcher($listeners, $logger));
        $reflectionClass = new ReflectionClass($instance);
        $loggerProperty = $reflectionClass->getProperty('logger');
        $this->assertInstanceOf(StdoutLoggerInterface::class, $loggerProperty->getValue($instance));
    }

    public function testInvokeDispatcherByFactory()
    {
        $container = m::mock(Container::class);
        $container->shouldReceive('make')->with('config')->andReturn(new Repository([]));
        $config = $container->make('config');
        $container->shouldReceive('make')->with(ListenerProviderContract::class)->andReturn(new ListenerProvider());
        $container->shouldReceive('make')->with(StdoutLoggerInterface::class)->andReturn(new StdoutLogger($config));
        $this->assertInstanceOf(Dispatcher::class, $instance = (new EventDispatcherFactory())($container));
        $reflectionClass = new ReflectionClass($instance);
        $loggerProperty = $reflectionClass->getProperty('logger');
        $this->assertInstanceOf(StdoutLoggerInterface::class, $loggerProperty->getValue($instance));
    }

    public function testStoppable()
    {
        $listeners = new ListenerProvider();
        $listeners->on(Alpha::class, [$alphaListener = new AlphaListener(), 'process']);
        $listeners->on(Alpha::class, [$betaListener = new BetaListener(), 'process']);
        $dispatcher = new EventDispatcher($listeners);
        $dispatcher->dispatch((new Alpha())->setPropagation(true));
        $this->assertSame(2, $alphaListener->value);
        $this->assertSame(1, $betaListener->value);
    }

    public function testLoggerDump()
    {
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('debug')->once();
        $listenerProvider = new ListenerProvider();
        $listenerProvider->on(Alpha::class, [new AlphaListener(), 'process']);
        $dispatcher = new EventDispatcher($listenerProvider, $logger);
        $dispatcher->dispatch(new Alpha());
    }

    public function testListenersCalledInRegistrationOrder(): void
    {
        // Listeners are called in registration order (Laravel-style, no priority)
        PriorityEvent::$result = [];
        $listenerProvider = new ListenerProvider();
        $listenerProvider->on(PriorityEvent::class, [new PriorityListener(1), 'process']);
        $listenerProvider->on(PriorityEvent::class, [new PriorityListener(2), 'process']);
        $listenerProvider->on(PriorityEvent::class, [new PriorityListener(3), 'process']);
        $listenerProvider->on(PriorityEvent::class, [new PriorityListener(4), 'process']);
        $listenerProvider->on(PriorityEvent::class, [new PriorityListener(5), 'process']);
        $listenerProvider->on(PriorityEvent::class, [new PriorityListener(6), 'process']);

        $dispatcher = new EventDispatcher($listenerProvider);
        $dispatcher->dispatch(new PriorityEvent());

        $this->assertSame([1, 2, 3, 4, 5, 6], PriorityEvent::$result);
    }
}
