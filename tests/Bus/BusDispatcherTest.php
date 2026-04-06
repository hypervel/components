<?php

declare(strict_types=1);

namespace Hypervel\Tests\Bus;

use Hypervel\Bus\Dispatcher;
use Hypervel\Bus\Queueable;
use Hypervel\Config\Repository as Config;
use Hypervel\Container\Container;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\QueueRoutes;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class BusDispatcherTest extends TestCase
{
    public function testCommandsThatShouldQueueIsQueued()
    {
        $container = new Container;
        $container->instance('queue.routes', $queueRoutes = m::mock(QueueRoutes::class));
        $queueRoutes->shouldReceive('getQueue')->andReturn(null);
        $queueRoutes->shouldReceive('getConnection')->andReturn(null);
        Container::setInstance($container);
        $dispatcher = new Dispatcher($container, function () {
            $mock = m::mock(Queue::class);
            $mock->shouldReceive('push')->once();

            return $mock;
        });

        $dispatcher->dispatch(m::mock(ShouldQueue::class));

        Container::setInstance(null);
    }

    public function testCommandsThatShouldQueueIsQueuedUsingCustomHandler()
    {
        $container = new Container;
        $container->instance('queue.routes', $queueRoutes = m::mock(QueueRoutes::class));
        $queueRoutes->shouldReceive('getQueue')->andReturn(null);
        $queueRoutes->shouldReceive('getConnection')->andReturn(null);
        Container::setInstance($container);
        $dispatcher = new Dispatcher($container, function () {
            $mock = m::mock(Queue::class);
            $mock->shouldReceive('push')->once();

            return $mock;
        });

        $dispatcher->dispatch(new BusDispatcherTestCustomQueueCommand);

        Container::setInstance(null);
    }

    public function testCommandsThatShouldQueueIsQueuedUsingCustomQueueAndDelay()
    {
        $container = new Container;
        $container->instance('queue.routes', $queueRoutes = m::mock(QueueRoutes::class));
        $queueRoutes->shouldReceive('getQueue')->andReturn(null);
        $queueRoutes->shouldReceive('getConnection')->andReturn(null);
        Container::setInstance($container);
        $dispatcher = new Dispatcher($container, function () {
            $mock = m::mock(Queue::class);
            $mock->shouldReceive('later')->once()->with(10, m::type(BusDispatcherTestSpecificQueueAndDelayCommand::class), '', 'foo');

            return $mock;
        });

        $dispatcher->dispatch(new BusDispatcherTestSpecificQueueAndDelayCommand);

        Container::setInstance(null);
    }

    public function testCommandsAreDispatchedWithQueueRoute()
    {
        Container::setInstance($container = new Container);
        $container->instance('queue.routes', $queueRoutes = m::mock(QueueRoutes::class));
        $queueRoutes->shouldReceive('getQueue')->andReturn('high-priority');
        $queueRoutes->shouldReceive('getConnection')->andReturn(null);

        $mock = m::mock(Queue::class);
        $mock->shouldReceive('push')->once()->with(BusDispatcherQueueable::class, '', 'high-priority');

        $dispatcher = new Dispatcher($container, function () use ($mock) {
            return $mock;
        });

        $dispatcher->dispatch(new BusDispatcherQueueable);

        Container::setInstance(null);
    }

    public function testDispatchNowShouldNeverQueue()
    {
        $container = new Container;
        $mock = m::mock(Queue::class);
        $mock->shouldReceive('push')->never();
        $dispatcher = new Dispatcher($container, function () use ($mock) {
            return $mock;
        });

        $dispatcher->dispatch(new BusDispatcherBasicCommand);
    }

    public function testDispatcherCanDispatchStandAloneHandler()
    {
        $container = new Container;
        $mock = m::mock(Queue::class);
        $dispatcher = new Dispatcher($container, function () use ($mock) {
            return $mock;
        });

        $dispatcher->map([StandAloneCommand::class => StandAloneHandler::class]);

        $response = $dispatcher->dispatch(new StandAloneCommand);

        $this->assertInstanceOf(StandAloneCommand::class, $response);
    }

    public function testOnConnectionOnJobWhenDispatching()
    {
        Container::setInstance($container = new Container);
        $container->singleton('config', function () {
            return new Config([
                'queue' => [
                    'default' => 'null',
                    'connections' => [
                        'null' => ['driver' => 'null'],
                    ],
                ],
            ]);
        });
        $container->instance('queue.routes', $queueRoutes = m::mock(QueueRoutes::class));
        $queueRoutes->shouldReceive('getQueue')->andReturn(null);
        $queueRoutes->shouldReceive('getConnection')->andReturn(null);
        Container::setInstance($container);

        $dispatcher = new Dispatcher($container, function () {
            $mock = m::mock(Queue::class);
            $mock->shouldReceive('push')->once();

            return $mock;
        });

        $job = (new ShouldNotBeDispatched)->onConnection('null');

        $dispatcher->dispatch($job);

        Container::setInstance(null);
    }
}

class BusInjectionStub
{
}

class BusDispatcherBasicCommand
{
    public $name;

    public function __construct($name = null)
    {
        $this->name = $name;
    }

    public function handle(BusInjectionStub $stub)
    {
    }
}

class BusDispatcherTestCustomQueueCommand implements ShouldQueue
{
    public function queue($queue, $command)
    {
        $queue->push($command);
    }
}

class BusDispatcherTestSpecificQueueAndDelayCommand implements ShouldQueue
{
    public $queue = 'foo';

    public $delay = 10;
}

class BusDispatcherQueueable implements ShouldQueue
{
    use Queueable;
}

class StandAloneCommand
{
}

class StandAloneHandler
{
    public function handle(StandAloneCommand $command)
    {
        return $command;
    }
}

class ShouldNotBeDispatched implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function handle()
    {
        throw new RuntimeException('This should not be run');
    }
}
