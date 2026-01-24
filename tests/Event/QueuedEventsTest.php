<?php

declare(strict_types=1);

namespace Hypervel\Tests\Event;

use Hyperf\Config\Config;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Di\Definition\DefinitionSource;
use Hypervel\Contracts\Bus\Dispatcher;
use Hypervel\Container\Container;
use Hypervel\Event\EventDispatcher;
use Hypervel\Event\ListenerProvider;
use Hypervel\Contracts\Queue\Factory as QueueFactoryContract;
use Hypervel\Contracts\Queue\Queue as QueueContract;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Support\Testing\Fakes\QueueFake;
use Hypervel\Tests\TestCase;
use Illuminate\Events\CallQueuedListener;
use Mockery as m;
use Mockery\MockInterface;
use Psr\Container\ContainerInterface;

use function Hypervel\Event\queueable;

/**
 * @internal
 * @coversNothing
 */
class QueuedEventsTest extends TestCase
{
    /**
     * @var ContainerInterface|MockInterface
     */
    private ContainerInterface $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = m::mock(ContainerInterface::class);
    }

    public function testQueuedEventHandlersAreQueued()
    {
        $this->container
            ->shouldReceive('get')
            ->once()
            ->with(TestDispatcherQueuedHandler::class)
            ->andReturn(new TestDispatcherQueuedHandler());

        $d = $this->getEventDispatcher();

        $queue = m::mock(QueueFactoryContract::class);
        $connection = m::mock(QueueContract::class);
        $connection->shouldReceive('pushOn')->with(null, m::type(CallQueuedListener::class))->once();
        $queue->shouldReceive('connection')->with(null)->once()->andReturn($connection);

        $d->setQueueResolver(fn () => $queue);

        $d->listen('some.event', TestDispatcherQueuedHandler::class . '@someMethod');
        $d->dispatch('some.event', ['foo', 'bar']);
    }

    public function testCallableHandlersAreQueued()
    {
        $this->container
            ->shouldReceive('get')
            ->once()
            ->with(TestDispatcherQueuedHandler::class)
            ->andReturn(new TestDispatcherQueuedHandler());

        $d = $this->getEventDispatcher();

        $queue = m::mock(QueueFactoryContract::class);
        $connection = m::mock(QueueContract::class);
        $connection->shouldReceive('pushOn')->with(null, m::type(CallQueuedListener::class))->twice();
        $queue->shouldReceive('connection')->with(null)->twice()->andReturn($connection);

        $d->setQueueResolver(fn () => $queue);

        $d->listen(TestDispatcherQueuedHandlerEvent::class, [new TestDispatcherQueuedHandler(), 'handle']);
        $d->listen(TestDispatcherQueuedHandlerEvent::class, [TestDispatcherQueuedHandler::class, 'handle']);
        $d->dispatch(new TestDispatcherQueuedHandlerEvent());
    }

    public function testQueueIsSetByGetConnection()
    {
        $this->container
            ->shouldReceive('get')
            ->once()
            ->with(TestDispatcherGetConnection::class)
            ->andReturn(new TestDispatcherGetConnection());

        $d = $this->getEventDispatcher();

        $queue = m::mock(QueueFactoryContract::class);
        $connection = m::mock(QueueContract::class);
        $connection->shouldReceive('pushOn')->with(null, m::type(CallQueuedListener::class))->once();
        $queue->shouldReceive('connection')->with('some_other_connection')->once()->andReturn($connection);

        $d->setQueueResolver(fn () => $queue);

        $d->listen('some.event', TestDispatcherGetConnection::class . '@handle');
        $d->dispatch('some.event', ['foo', 'bar']);
    }

    public function testDelayIsSetByWithDelay()
    {
        $this->container
            ->shouldReceive('get')
            ->once()
            ->with(TestDispatcherGetDelay::class)
            ->andReturn(new TestDispatcherGetConnection());

        $d = $this->getEventDispatcher();

        $queue = m::mock(QueueFactoryContract::class);
        $connection = m::mock(QueueContract::class);
        $connection->shouldReceive('laterOn')->with(null, 20, m::type(CallQueuedListener::class))->once();
        $queue->shouldReceive('connection')->with(null)->once()->andReturn($connection);

        $d->setQueueResolver(fn () => $queue);

        $d->listen('some.event', TestDispatcherGetDelay::class . '@handle');
        $d->dispatch('some.event', ['foo', 'bar']);
    }

    public function testQueueIsSetByGetConnectionDynamically()
    {
        $this->container
            ->shouldReceive('get')
            ->once()
            ->with(TestDispatcherGetConnectionDynamically::class)
            ->andReturn(new TestDispatcherGetConnectionDynamically());

        $d = $this->getEventDispatcher();

        $queue = m::mock(QueueFactoryContract::class);
        $connection = m::mock(QueueContract::class);
        $connection->shouldReceive('pushOn')->with(null, m::type(CallQueuedListener::class))->once();
        $queue->shouldReceive('connection')->with('redis')->once()->andReturn($connection);

        $d->setQueueResolver(fn () => $queue);

        $d->listen('some.event', TestDispatcherGetConnectionDynamically::class . '@handle');
        $d->dispatch('some.event', [
            ['shouldUseRedisConnection' => true],
            'bar',
        ]);
    }

    public function testDelayIsSetByWithDelayDynamically()
    {
        $this->container
            ->shouldReceive('get')
            ->once()
            ->with(TestDispatcherGetDelayDynamically::class)
            ->andReturn(new TestDispatcherGetDelayDynamically());

        $d = $this->getEventDispatcher();

        $queue = m::mock(QueueFactoryContract::class);
        $connection = m::mock(QueueContract::class);
        $connection->shouldReceive('laterOn')->with(null, 60, m::type(CallQueuedListener::class))->once();
        $queue->shouldReceive('connection')->with(null)->once()->andReturn($connection);

        $d->setQueueResolver(fn () => $queue);

        $d->listen('some.event', TestDispatcherGetDelayDynamically::class . '@handle');
        $d->dispatch('some.event', [['useHighDelay' => true], 'bar']);
    }

    public function testQueuePropagateRetryUntilAndMaxExceptions()
    {
        $this->container = $this->getContainer();

        $d = $this->getEventDispatcher();

        $fakeQueue = new QueueFake($this->container);

        $d->setQueueResolver(function () use ($fakeQueue) {
            return $fakeQueue;
        });

        $d->listen('some.event', TestDispatcherOptions::class . '@handle');
        $d->dispatch('some.event', ['foo', 'bar']);

        $fakeQueue->assertPushed(CallQueuedListener::class, function ($job) {
            return $job->maxExceptions === 1 && $job->retryUntil !== null;
        });
    }

    public function testQueuedClosureEventHandlersAreQueued()
    {
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch');

        $this->container = $this->getContainer();
        $this->container->set(Dispatcher::class, $dispatcher);

        $d = $this->getEventDispatcher();

        $queue = m::mock(QueueFactoryContract::class);

        $d->setQueueResolver(fn () => $queue);

        $d->listen('some.event', queueable(function () {}));
        $d->dispatch('some.event', ['foo', 'bar']);
    }

    public function testQueuePropagateMiddleware()
    {
        $this->container = $this->getContainer();

        $d = $this->getEventDispatcher();

        $fakeQueue = new QueueFake($this->container);

        $d->setQueueResolver(function () use ($fakeQueue) {
            return $fakeQueue;
        });

        $d->listen('some.event', TestDispatcherMiddleware::class . '@handle');
        $d->dispatch('some.event', ['foo', 'bar']);

        $fakeQueue->assertPushed(CallQueuedListener::class, function ($job) {
            return count($job->middleware) === 1
                && $job->middleware[0] instanceof TestMiddleware
                && $job->middleware[0]->a === 'foo'
                && $job->middleware[0]->b === 'bar';
        });
    }

    private function getContainer(): Container
    {
        $container = new Container(
            new DefinitionSource([
                ConfigInterface::class => fn () => new Config([]),
            ])
        );

        ApplicationContext::setContainer($container);

        return $container;
    }

    private function getEventDispatcher(?StdoutLoggerInterface $logger = null): EventDispatcher
    {
        return new EventDispatcher(new ListenerProvider(), $logger, $this->container);
    }
}

class TestDispatcherQueuedHandler implements ShouldQueue
{
    public function handle()
    {
    }
}

class TestDispatcherQueuedHandlerEvent
{
}

class TestDispatcherGetConnection implements ShouldQueue
{
    public $connection = 'my_connection';

    public function handle()
    {
    }

    public function viaConnection()
    {
        return 'some_other_connection';
    }
}

class TestDispatcherGetDelay implements ShouldQueue
{
    public $delay = 10;

    public function handle()
    {
    }

    public function withDelay()
    {
        return 20;
    }
}

class TestDispatcherOptions implements ShouldQueue
{
    public $maxExceptions = 1;

    public function retryUntil()
    {
        return now()->addHour(1);
    }

    public function handle()
    {
    }
}

class TestDispatcherMiddleware implements ShouldQueue
{
    public function middleware($a, $b)
    {
        return [new TestMiddleware($a, $b)];
    }

    public function handle($a, $b)
    {
    }
}

class TestMiddleware
{
    public $a;

    public $b;

    public function __construct($a, $b)
    {
        $this->a = $a;
        $this->b = $b;
    }

    public function handle($job, $next)
    {
        $next($job);
    }
}

class TestDispatcherGetConnectionDynamically implements ShouldQueue
{
    public function handle()
    {
    }

    public function viaConnection($event)
    {
        if ($event['shouldUseRedisConnection']) {
            return 'redis';
        }

        return 'sqs';
    }
}

class TestDispatcherGetDelayDynamically implements ShouldQueue
{
    public $delay = 10;

    public function handle()
    {
    }

    public function withDelay($event)
    {
        if ($event['useHighDelay']) {
            return 60;
        }

        return 20;
    }
}

class TestDispatcherAnonymousQueuedClosureEvent
{
}
