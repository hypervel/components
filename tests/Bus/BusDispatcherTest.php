<?php

declare(strict_types=1);

namespace Hypervel\Tests\Bus;

use Hypervel\Bus\Dispatcher;
use Hypervel\Bus\Queueable;
use Hypervel\Config\Repository as Config;
use Hypervel\Container\Container;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Queue\Attributes\Connection;
use Hypervel\Queue\Attributes\Delay;
use Hypervel\Queue\Attributes\Queue as QueueAttribute;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\QueueRoutes;
use Hypervel\Support\Testing\Fakes\QueueFake;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use RuntimeException;

class BusDispatcherTest extends TestCase
{
    use VerifiesDoubles;

    public function testCommandsThatShouldQueueIsQueued(): void
    {
        $container = new Container;
        $container->instance('queue.routes', new QueueRoutes);
        Container::setInstance($container);
        $queue = new QueueFake($container);
        $dispatcher = new Dispatcher($container, fn (): Queue => $queue);

        $dispatcher->dispatch(new BusDispatcherQueueable);

        $queue->assertPushedOnce(BusDispatcherQueueable::class);
    }

    public function testCommandsThatShouldQueueIsQueuedUsingCustomHandler(): void
    {
        $container = new Container;
        $container->instance('queue.routes', new QueueRoutes);
        Container::setInstance($container);
        $queue = new QueueFake($container);
        $dispatcher = new Dispatcher($container, fn (): Queue => $queue);

        $dispatcher->dispatch(new BusDispatcherTestCustomQueueCommand);

        $queue->assertPushedOnce(BusDispatcherTestCustomQueueCommand::class);
    }

    public function testCommandsThatShouldQueueIsQueuedUsingCustomQueueAndDelay(): void
    {
        $container = new Container;
        $container->instance('queue.routes', new QueueRoutes);
        Container::setInstance($container);
        $dispatcher = new Dispatcher($container, function (): Queue {
            $mock = Double::for(Queue::class);
            $mock->expects('later')->with(10, Argument::type(BusDispatcherTestSpecificQueueAndDelayCommand::class), '', 'foo');

            return $mock;
        });

        $dispatcher->dispatch(new BusDispatcherTestSpecificQueueAndDelayCommand);
    }

    public function testCommandsAreQueuedUsingAttributesInheritedFromParentClass(): void
    {
        $container = new Container;
        $container->instance('queue.routes', new QueueRoutes);
        Container::setInstance($container);
        $usedConnection = null;
        $dispatcher = new Dispatcher($container, function (?string $connection) use (&$usedConnection): Queue {
            $usedConnection = $connection;

            $mock = Double::for(Queue::class);
            $mock->expects('later')->with(10, Argument::type(BusDispatcherTestQueueableChildCommand::class), '', 'foo');

            return $mock;
        });

        $dispatcher->dispatch(new BusDispatcherTestQueueableChildCommand);

        $this->assertSame('redis', $usedConnection);
    }

    public function testCommandsThatShouldQueueIsQueuedUsingQueueAndDelayAttributes(): void
    {
        $container = new Container;
        $queueRoutes = Double::for(QueueRoutes::class);
        $queueRoutes->expects('getConnection')->returns(null);
        $container->instance('queue.routes', $queueRoutes);
        Container::setInstance($container);
        $dispatcher = new Dispatcher($container, function (): Queue {
            $mock = Double::for(Queue::class);
            $mock->expects('later')->with(10, Argument::type(BusDispatcherTestSpecificQueueAndDelayAttributesCommand::class), '', 'foo');

            return $mock;
        });

        $dispatcher->dispatch(new BusDispatcherTestSpecificQueueAndDelayAttributesCommand);
    }

    public function testCommandDelayPropertyOverridesDelayAttribute(): void
    {
        $container = new Container;
        $queueRoutes = Double::for(QueueRoutes::class);
        $queueRoutes->expects('getConnection')->returns(null);
        $container->instance('queue.routes', $queueRoutes);
        Container::setInstance($container);
        $dispatcher = new Dispatcher($container, function (): Queue {
            $mock = Double::for(Queue::class);
            $mock->expects('later')->with(60, Argument::type(BusDispatcherTestSpecificQueueAndDelayAttributeWithPropertyCommand::class), '', 'foo');

            return $mock;
        });

        $dispatcher->dispatch((new BusDispatcherTestSpecificQueueAndDelayAttributeWithPropertyCommand)->delay(60));
    }

    public function testCommandsAreDispatchedWithQueueRoute(): void
    {
        Container::setInstance($container = new Container);
        $queueRoutes = new QueueRoutes;
        $queueRoutes->set(BusDispatcherQueueable::class, 'high-priority');
        $container->instance('queue.routes', $queueRoutes);

        $queue = new QueueFake($container);
        $dispatcher = new Dispatcher($container, fn (): Queue => $queue);

        $dispatcher->dispatch(new BusDispatcherQueueable);

        $queue->assertPushedOnce(BusDispatcherQueueable::class);
        $queue->assertPushedOn('high-priority', BusDispatcherQueueable::class);
    }

    public function testCommandsAreForwardedToConnectionByQueueName(): void
    {
        Container::setInstance($container = new Container);
        $queueRoutes = new QueueRoutes;
        $queueRoutes->forward('reports', 'processing', 'cloud');
        $container->instance('queue.routes', $queueRoutes);

        $queue = new QueueFake($container);
        $usedConnection = false;

        $dispatcher = new Dispatcher($container, function (?string $connection) use ($queue, &$usedConnection): Queue {
            $usedConnection = $connection;

            return $queue;
        });

        $dispatcher->dispatch((new BusDispatcherQueueable)->onQueue('reports'));

        $this->assertSame('cloud', $usedConnection);
        $queue->assertPushedOnce(BusDispatcherQueueable::class);
        $queue->assertPushedOn('reports', BusDispatcherQueueable::class);
    }

    public function testExplicitConnectionWinsOverForwardedQueue(): void
    {
        Container::setInstance($container = new Container);
        $queueRoutes = new QueueRoutes;
        $queueRoutes->forward('reports', 'processing', 'cloud');
        $container->instance('queue.routes', $queueRoutes);

        $mock = Double::for(Queue::class);
        $mock->expects('push')->with(Argument::type(BusDispatcherQueueable::class), '', 'reports');

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
        $queue = new QueueFake($container);
        $dispatcher = new Dispatcher($container, fn (): Queue => $queue);

        $dispatcher->dispatch(new BusDispatcherBasicCommand);

        $queue->assertNothingPushed();
    }

    public function testDispatcherCanDispatchStandAloneHandler(): void
    {
        $container = new Container;
        $queue = new QueueFake($container);
        $dispatcher = new Dispatcher($container, fn (): Queue => $queue);

        $dispatcher->map([StandAloneCommand::class => StandAloneHandler::class]);

        $response = $dispatcher->dispatch(new StandAloneCommand);

        $this->assertInstanceOf(StandAloneCommand::class, $response);
        $queue->assertNothingPushed();
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
        $container->instance('queue.routes', new QueueRoutes);

        $queue = new QueueFake($container);
        $dispatcher = new Dispatcher($container, fn (): Queue => $queue);

        $job = (new ShouldNotBeDispatched)->onConnection('null');

        $dispatcher->dispatch($job);

        $queue->assertPushedOnce(ShouldNotBeDispatched::class);
    }

    public function testDispatchBulk(): void
    {
        $container = new Container;
        $container->instance('queue.routes', new QueueRoutes);
        Container::setInstance($container);

        $defaultQueue = Double::for(Queue::class);
        $defaultQueue->expects('bulk')->with(Argument::satisfies(fn (array $jobs): bool => count($jobs) === 2), '', null);
        $defaultQueue->expects('bulk')->with(Argument::satisfies(fn (array $jobs): bool => count($jobs) === 1), '', 'high');

        $priorityQueue = Double::for(Queue::class);
        $priorityQueue->expects('bulk')->with(Argument::satisfies(fn (array $jobs): bool => count($jobs) === 1), '', 'high');

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

        $firstQueue = Double::for(Queue::class);
        $firstQueue->expects('bulk')->with(Argument::same([$firstJob]), '', 'c');

        $secondQueue = Double::for(Queue::class);
        $secondQueue->expects('bulk')->with(Argument::same([$secondJob]), '', 'b:c');

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

#[Connection('redis')]
#[QueueAttribute('foo')]
#[Delay(10)]
abstract class BusDispatcherTestParentCommandWithAttributes implements ShouldQueue
{
}

class BusDispatcherTestQueueableChildCommand extends BusDispatcherTestParentCommandWithAttributes
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
