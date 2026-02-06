<?php

declare(strict_types=1);

namespace Hypervel\Tests\Event\Hyperf;

use Hyperf\Config\Config;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Framework\Logger\StdoutLogger;
use Hypervel\Event\Contracts\ListenerProvider as ListenerProviderContract;
use Hypervel\Event\EventDispatcher;
use Hypervel\Event\EventDispatcherFactory;
use Hypervel\Event\ListenerProvider;
use Hypervel\Tests\Event\Hyperf\Event\Alpha;
use Hypervel\Tests\Event\Hyperf\Event\PriorityEvent;
use Hypervel\Tests\Event\Hyperf\Listener\AlphaListener;
use Hypervel\Tests\Event\Hyperf\Listener\BetaListener;
use Hypervel\Tests\Event\Hyperf\Listener\PriorityListener;
use Mockery as m;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface as PsrListenerProviderInterface;
use ReflectionClass;

/**
 * @internal
 * @coversNothing
 */
class EventDispatcherTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testInvokeDispatcher()
    {
        $listeners = m::mock(ListenerProviderContract::class);
        $this->assertInstanceOf(EventDispatcherInterface::class, new EventDispatcher($listeners));
    }

    public function testInvokeDispatcherWithStdoutLogger()
    {
        $listeners = m::mock(ListenerProviderContract::class);
        $logger = m::mock(StdoutLoggerInterface::class);
        $this->assertInstanceOf(EventDispatcherInterface::class, $instance = new EventDispatcher($listeners, $logger));
        $reflectionClass = new ReflectionClass($instance);
        $loggerProperty = $reflectionClass->getProperty('logger');
        $this->assertInstanceOf(StdoutLoggerInterface::class, $loggerProperty->getValue($instance));
    }

    public function testInvokeDispatcherByFactory()
    {
        $container = m::mock(ContainerInterface::class);
        $container->shouldReceive('get')->with(ConfigInterface::class)->andReturn(new Config([]));
        $config = $container->get(ConfigInterface::class);
        $container->shouldReceive('get')->with(PsrListenerProviderInterface::class)->andReturn(new ListenerProvider());
        $container->shouldReceive('get')->with(StdoutLoggerInterface::class)->andReturn(new StdoutLogger($config));
        $this->assertInstanceOf(EventDispatcherInterface::class, $instance = (new EventDispatcherFactory())($container));
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
