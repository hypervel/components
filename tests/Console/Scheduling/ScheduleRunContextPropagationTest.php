<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Scheduling;

use Hypervel\Console\Commands\ScheduleRunCommand;
use Hypervel\Console\Scheduling\CallbackEvent;
use Hypervel\Console\Scheduling\Event;
use Hypervel\Console\Scheduling\EventMutex;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Coroutine\Concurrent;
use Hypervel\Engine\Channel;
use Hypervel\Log\Context\Repository as ContextRepository;
use Hypervel\Support\Carbon;
use Hypervel\Support\Collection;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use ReflectionMethod;
use ReflectionProperty;
use Swoole\Coroutine;

/**
 * @internal
 * @coversNothing
 */
class ScheduleRunContextPropagationTest extends TestCase
{
    protected Dispatcher $dispatcher;

    protected ExceptionHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dispatcher = m::mock(Dispatcher::class);
        $this->dispatcher->shouldReceive('dispatch');

        $this->handler = m::mock(ExceptionHandler::class);
    }

    public function testBackgroundTaskReceivesParentContext()
    {
        ContextRepository::getInstance()->add('trace_id', 'parent-trace-123');

        $channel = new Channel(1);

        $event = $this->makeBackgroundEvent(function () use ($channel) {
            $channel->push(ContextRepository::getInstance()->get('trace_id'));
        });

        $this->runBackgroundEvents([$event]);

        $this->assertSame('parent-trace-123', $channel->pop(1.0));
    }

    public function testBackgroundTaskReceivesHiddenContext()
    {
        ContextRepository::getInstance()->addHidden('checkin_id', 'secret-id-456');

        $channel = new Channel(1);

        $event = $this->makeBackgroundEvent(function () use ($channel) {
            $channel->push(ContextRepository::getInstance()->getHidden('checkin_id'));
        });

        $this->runBackgroundEvents([$event]);

        $this->assertSame('secret-id-456', $channel->pop(1.0));
    }

    public function testBackgroundTaskContextDoesNotLeakBackToParent()
    {
        ContextRepository::getInstance()->add('parent_key', 'parent_value');

        $channel = new Channel(1);

        $event = $this->makeBackgroundEvent(function () use ($channel) {
            ContextRepository::getInstance()->add('parent_key', 'modified_by_child');
            ContextRepository::getInstance()->add('child_only', 'child_data');
            $channel->push(true);
        });

        $this->runBackgroundEvents([$event]);

        $channel->pop(1.0);

        $this->assertSame('parent_value', ContextRepository::getInstance()->get('parent_key'));
        $this->assertNull(ContextRepository::getInstance()->get('child_only'));
    }

    public function testBackgroundTaskDoesNotReceiveNonContextCoroutineState()
    {
        CoroutineContext::set('__request_specific', 'should-not-propagate');
        ContextRepository::getInstance()->add('should_propagate', 'yes');

        $channel = new Channel(2);

        $event = $this->makeBackgroundEvent(function () use ($channel) {
            $channel->push(CoroutineContext::get('__request_specific'));
            $channel->push(ContextRepository::getInstance()->get('should_propagate'));
        });

        $this->runBackgroundEvents([$event]);

        $this->assertNull($channel->pop(1.0));
        $this->assertSame('yes', $channel->pop(1.0));
    }

    public function testForegroundTaskSharesParentContext()
    {
        ContextRepository::getInstance()->add('parent_key', 'original');

        $eventMutex = m::mock(EventMutex::class);
        $eventMutex->shouldReceive('create')->andReturn(true);
        $eventMutex->shouldReceive('forget');

        $event = new CallbackEvent($eventMutex, function () {
            ContextRepository::getInstance()->add('parent_key', 'modified');
            return 0;
        });

        $command = $this->makeCommand();
        $this->invokeRunEvents($command, [$event]);

        // Foreground tasks run in the same coroutine — mutations are visible
        $this->assertSame('modified', ContextRepository::getInstance()->get('parent_key'));
    }

    /**
     * Create a background Event mock that executes a callback inside run().
     */
    protected function makeBackgroundEvent(callable $callback): Event
    {
        $eventMutex = m::mock(EventMutex::class);
        $eventMutex->shouldReceive('create')->andReturn(true);
        $eventMutex->shouldReceive('forget');

        $event = m::mock(Event::class, [$eventMutex, 'test:context', null, false])->makePartial();
        $event->shouldReceive('run')->once()->andReturnUsing(function () use ($callback) {
            $callback();
        });
        $event->runInBackground();

        return $event;
    }

    /**
     * Run background events and wait for completion.
     */
    protected function runBackgroundEvents(array $events): void
    {
        $command = $this->makeCommand();
        $concurrent = new Concurrent(10);
        (new ReflectionProperty($command, 'concurrent'))->setValue($command, $concurrent);

        $this->invokeRunEvents($command, $events);

        while (! $concurrent->isEmpty()) {
            Coroutine::sleep(0.01);
        }
    }

    /**
     * Create a ScheduleRunCommand with mocked dependencies.
     */
    protected function makeCommand(): ScheduleRunCommand
    {
        $command = new ScheduleRunCommand();
        $command->setHypervel($this->app);

        (new ReflectionProperty($command, 'schedule'))->setValue($command, m::mock(Schedule::class));
        (new ReflectionProperty($command, 'dispatcher'))->setValue($command, $this->dispatcher);
        (new ReflectionProperty($command, 'cache'))->setValue($command, m::mock(CacheFactory::class));
        (new ReflectionProperty($command, 'handler'))->setValue($command, $this->handler);

        return $command;
    }

    /**
     * Invoke the protected runEvents method.
     */
    protected function invokeRunEvents(ScheduleRunCommand $command, array $events): void
    {
        $method = new ReflectionMethod($command, 'runEvents');
        $method->invoke($command, new Collection($events), Carbon::now());
    }
}
