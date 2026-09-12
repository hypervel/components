<?php

declare(strict_types=1);

namespace Hypervel\Tests\Bus;

use Hypervel\Bus\Dispatcher;
use Hypervel\Bus\Queueable;
use Hypervel\Config\Repository as Config;
use Hypervel\Container\Container;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Queue\Attributes\Delay;
use Hypervel\Queue\Attributes\Queue as QueueAttribute;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\QueueRoutes;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

class BusDispatcherTest extends TestCase
{
    public function testCommandsThatShouldQueueIsQueued(): void
    {
        $container = new Container;
        $queueRoutes = m::mock(QueueRoutes::class);
        $queueRoutes->expects('getQueue')->andReturn(null);
        $queueRoutes->expects('getConnection')->andReturn(null);
        $container->instance('queue.routes', $queueRoutes);
        Container::setInstance($container);
        $dispatcher = new Dispatcher($container, function (): Queue {
            $mock = m::mock(Queue::class);
            $mock->expects('push');

            return $mock;
        });

        $dispatcher->dispatch(m::mock(ShouldQueue::class));
    }

    public function testCommandsThatShouldQueueIsQueuedUsingCustomHandler(): void
    {
        $container = new Container;
        $queueRoutes = m::mock(QueueRoutes::class);
        $queueRoutes->expects('getConnection')->andReturn(null);
        $container->instance('queue.routes', $queueRoutes);
        Container::setInstance($container);
        $dispatcher = new Dispatcher($container, function (): Queue {
            $mock = m::mock(Queue::class);
            $mock->expects('push');

            return $mock;
        });

        $dispatcher->dispatch(new BusDispatcherTestCustomQueueCommand);
    }

    public function testCommandsThatShouldQueueIsQueuedUsingCustomQueueAndDelay(): void
    {
        $container = new Container;
        $queueRoutes = m::mock(QueueRoutes::class);
        $queueRoutes->expects('getConnection')->andReturn(null);
        $container->instance('queue.routes', $queueRoutes);
        Container::setInstance($container);
        $dispatcher = new Dispatcher($container, function (): Queue {
            $mock = m::mock(Queue::class);
            $mock->expects('later')->with(10, m::type(BusDispatcherTestSpecificQueueAndDelayCommand::class), '', 'foo');

            return $mock;
        });

        $dispatcher->dispatch(new BusDispatcherTestSpecificQueueAndDelayCommand);
    }

    public function testCommandsThatShouldQueueIsQueuedUsingQueueAndDelayAttributes(): void
    {
        $container = new Container;
        $queueRoutes = m::mock(QueueRoutes::class);
        $queueRoutes->expects('getConnection')->andReturn(null);
        $container->instance('queue.routes', $queueRoutes);
        Container::setInstance($container);
        $dispatcher = new Dispatcher($container, function (): Queue {
            $mock = m::mock(Queue::class);
            $mock->expects('later')->with(10, m::type(BusDispatcherTestSpecificQueueAndDelayAttributesCommand::class), '', 'foo');

            return $mock;
        });

        $dispatcher->dispatch(new BusDispatcherTestSpecificQueueAndDelayAttributesCommand);
    }

    public function testCommandDelayPropertyOverridesDelayAttribute(): void
    {
        $container = new Container;
        $queueRoutes = m::mock(QueueRoutes::class);
        $queueRoutes->expects('getConnection')->andReturn(null);
        $container->instance('queue.routes', $queueRoutes);
        Container::setInstance($container);
        $dispatcher = new Dispatcher($container, function (): Queue {
            $mock = m::mock(Queue::class);
            $mock->expects('later')->with(60, m::type(BusDispatcherTestSpecificQueueAndDelayAttributeWithPropertyCommand::class), '', 'foo');

            return $mock;
        });

        $dispatcher->dispatch((new BusDispatcherTestSpecificQueueAndDelayAttributeWithPropertyCommand)->delay(60));
    }

    public function testCommandsAreDispatchedWithQueueRoute(): void
    {
        Container::setInstance($container = new Container);
        $queueRoutes = m::mock(QueueRoutes::class);
        $queueRoutes->expects('getQueue')->andReturn('high-priority');
        $queueRoutes->expects('getConnection')->andReturn(null);
        $container->instance('queue.routes', $queueRoutes);

        $mock = m::mock(Queue::class);
        $mock->expects('push')->with(BusDispatcherQueueable::class, '', 'high-priority');

        $dispatcher = new Dispatcher($container, function () use ($mock): Queue {
            return $mock;
        });

        $dispatcher->dispatch(new BusDispatcherQueueable);
    }

    public function testCommandsAreForwardedToConnectionByQueueName(): void
    {
        Container::setInstance($container = new Container);
        $queueRoutes = new QueueRoutes;
        $queueRoutes->forward('reports', 'processing', 'cloud');
        $container->instance('queue.routes', $queueRoutes);

        $mock = m::mock(Queue::class);
        $mock->expects('push')->with(m::type(BusDispatcherQueueable::class), '', 'reports');

        $usedConnection = false;

        $dispatcher = new Dispatcher($container, function (?string $connection) use ($mock, &$usedConnection): Queue {
            $usedConnection = $connection;

            return $mock;
        });

        $dispatcher->dispatch((new BusDispatcherQueueable)->onQueue('reports'));

        $this->assertSame('cloud', $usedConnection);
    }

    public function testExplicitConnectionWinsOverForwardedQueue(): void
    {
        Container::setInstance($container = new Container);
        $queueRoutes = new QueueRoutes;
        $queueRoutes->forward('reports', 'processing', 'cloud');
        $container->instance('queue.routes', $queueRoutes);

        $mock = m::mock(Queue::class);
        $mock->expects('push')->with(m::type(BusDispatcherQueueable::class), '', 'reports');

        $usedConnection = false;

        $dispatcher = new Dispatcher($container, function (?string $connection) use ($mock, &$usedConnection): Queue {
            $usedConnection = $connection;

            return $mock;
        });

        $dispatcher->dispatch((new BusDispatcherQueueable)->onConnection('redis')->onQueue('reports'));

        $this->assertSame('redis', $usedConnection);
    }

    public function testDispatchNowShouldNeverQueue(): void
    {
        $container = new Container;
        $mock = m::mock(Queue::class);
        $mock->shouldReceive('push')->never();
        $dispatcher = new Dispatcher($container, function () use ($mock): Queue {
            return $mock;
        });

        $dispatcher->dispatch(new BusDispatcherBasicCommand);
    }

    public function testDispatcherCanDispatchStandAloneHandler(): void
    {
        $container = new Container;
        $mock = m::mock(Queue::class);
        $dispatcher = new Dispatcher($container, function () use ($mock): Queue {
            return $mock;
        });

        $dispatcher->map([StandAloneCommand::class => StandAloneHandler::class]);

        $response = $dispatcher->dispatch(new StandAloneCommand);

        $this->assertInstanceOf(StandAloneCommand::class, $response);
    }

    public function testDisabledDispatchAfterResponseUsesExplicitHandler(): void
    {
        $dispatcher = new Dispatcher(new Container);
        $dispatcher->withoutDispatchingAfterResponses();

        $command = new BusDispatcherImmediateCommand;
        $handledCommand = null;

        $dispatcher->dispatchAfterResponse($command, static function (BusDispatcherImmediateCommand $receivedCommand) use (&$handledCommand): void {
            $handledCommand = $receivedCommand;
        });

        $this->assertSame($command, $handledCommand);
        $this->assertFalse($command->handled);
    }

    public function testOnConnectionOnJobWhenDispatching(): void
    {
        Container::setInstance($container = new Container);
        $container->singleton('config', function (): Config {
            return new Config([
                'queue' => [
                    'default' => 'null',
                    'connections' => [
                        'null' => ['driver' => 'null'],
                    ],
                ],
            ]);
        });
        $queueRoutes = m::mock(QueueRoutes::class);
        $queueRoutes->expects('getQueue')->andReturn(null);
        $container->instance('queue.routes', $queueRoutes);

        $dispatcher = new Dispatcher($container, function (): Queue {
            $mock = m::mock(Queue::class);
            $mock->expects('push');

            return $mock;
        });

        $job = (new ShouldNotBeDispatched)->onConnection('null');

        $dispatcher->dispatch($job);
    }

    public function testDispatchBulk(): void
    {
        $container = new Container;
        $queueRoutes = m::mock(QueueRoutes::class);
        $queueRoutes->expects('getQueue')->times(2)->andReturn(null);
        $queueRoutes->expects('getConnection')->times(3)->andReturn(null);
        $container->instance('queue.routes', $queueRoutes);
        Container::setInstance($container);

        $defaultQueue = m::mock(Queue::class);
        $defaultQueue->expects('bulk')->with(m::on(fn (array $jobs): bool => count($jobs) === 2), '', null);
        $defaultQueue->expects('bulk')->with(m::on(fn (array $jobs): bool => count($jobs) === 1), '', 'high');

        $priorityQueue = m::mock(Queue::class);
        $priorityQueue->expects('bulk')->with(m::on(fn (array $jobs): bool => count($jobs) === 1), '', 'high');

        $dispatcher = new Dispatcher(
            $container,
            fn (?string $connection): Queue => $connection === 'priority' ? $priorityQueue : $defaultQueue
        );

        $immediate = new BusDispatcherImmediateCommand;

        $dispatcher->bulk([
            new BusDispatcherQueueable,
            new BusDispatcherQueueable,
            new BusDispatcherTestSpecificQueueCommand,
            new BusDispatcherTestSpecificConnectionAndQueueCommand,
            $immediate,
        ]);

        $this->assertTrue($immediate->handled);
    }

    public function testDispatchBulkKeepsColonBearingRoutesSeparate(): void
    {
        $firstJob = (new BusDispatcherQueueable)->onConnection('a:b')->onQueue('c');
        $secondJob = (new BusDispatcherQueueable)->onConnection('a')->onQueue('b:c');

        $firstQueue = m::mock(Queue::class);
        $firstQueue->expects('bulk')->with([$firstJob], '', 'c');

        $secondQueue = m::mock(Queue::class);
        $secondQueue->expects('bulk')->with([$secondJob], '', 'b:c');

        $dispatcher = new Dispatcher(
            new Container,
            fn (?string $connection): Queue => match ($connection) {
                'a:b' => $firstQueue,
                'a' => $secondQueue,
                default => throw new RuntimeException("Unexpected connection [{$connection}]."),
            }
        );

        $dispatcher->bulk([$firstJob, $secondJob]);
    }
}

class BusInjectionStub
{
}

class BusDispatcherBasicCommand
{
    public mixed $name;

    /**
     * Create a command with the given name.
     */
    public function __construct(mixed $name = null)
    {
        $this->name = $name;
    }

    /**
     * Handle the command.
     */
    public function handle(BusInjectionStub $stub): void
    {
    }
}

class BusDispatcherTestCustomQueueCommand implements ShouldQueue
{
    /**
     * Queue the command using a custom handler.
     */
    public function queue(Queue $queue, object $command): void
    {
        $queue->push($command);
    }
}

class BusDispatcherTestSpecificQueueAndDelayCommand implements ShouldQueue
{
    public string $queue = 'foo';

    public int $delay = 10;
}

class BusDispatcherTestSpecificQueueCommand implements ShouldQueue
{
    public string $queue = 'high';
}

class BusDispatcherTestSpecificConnectionAndQueueCommand implements ShouldQueue
{
    public string $connection = 'priority';

    public string $queue = 'high';
}

class BusDispatcherImmediateCommand
{
    public bool $handled = false;

    /**
     * Handle the command.
     */
    public function handle(): void
    {
        $this->handled = true;
    }
}

#[QueueAttribute('foo')]
#[Delay(10)]
class BusDispatcherTestSpecificQueueAndDelayAttributesCommand implements ShouldQueue
{
}

#[QueueAttribute('foo')]
#[Delay(10)]
class BusDispatcherTestSpecificQueueAndDelayAttributeWithPropertyCommand implements ShouldQueue
{
    use Queueable;
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
    /**
     * Handle the standalone command.
     */
    public function handle(StandAloneCommand $command): StandAloneCommand
    {
        return $command;
    }
}

class ShouldNotBeDispatched implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /**
     * Reject unexpected inline dispatch.
     *
     * @throws RuntimeException
     */
    public function handle(): never
    {
        throw new RuntimeException('This should not be run');
    }
}
