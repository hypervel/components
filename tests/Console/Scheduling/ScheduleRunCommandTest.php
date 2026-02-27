<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Scheduling;

use Hypervel\Console\Commands\ScheduleRunCommand;
use Hypervel\Console\Events\ScheduledBackgroundTaskFinished;
use Hypervel\Console\Events\ScheduledTaskFailed;
use Hypervel\Console\Events\ScheduledTaskFinished;
use Hypervel\Console\Events\ScheduledTaskSkipped;
use Hypervel\Console\Events\ScheduledTaskStarting;
use Hypervel\Console\Scheduling\CallbackEvent;
use Hypervel\Console\Scheduling\Event;
use Hypervel\Console\Scheduling\EventMutex;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Support\Carbon;
use Hypervel\Support\Collection;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Swoole\Coroutine;

/**
 * @internal
 * @coversNothing
 */
class ScheduleRunCommandTest extends TestCase
{
    use RunTestsInCoroutine;

    protected array $dispatched;

    protected Dispatcher $dispatcher;

    protected ExceptionHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dispatched = [];

        $this->dispatcher = m::mock(Dispatcher::class);
        $this->dispatcher->shouldReceive('dispatch')
            ->andReturnUsing(function ($event) {
                $this->dispatched[] = $event;
            });

        $this->handler = m::mock(ExceptionHandler::class);
    }

    public function testForegroundCallbackDispatchesStartingAndFinishedEvents()
    {
        $eventMutex = m::mock(EventMutex::class);
        $eventMutex->shouldReceive('create')->andReturn(true);
        $eventMutex->shouldReceive('forget');

        $callbackEvent = new CallbackEvent($eventMutex, function () {
            return 0;
        });

        $command = $this->makeCommand();
        $this->invokeRunEvents($command, [$callbackEvent]);

        $this->assertCount(2, $this->dispatched);
        $this->assertInstanceOf(ScheduledTaskStarting::class, $this->dispatched[0]);
        $this->assertInstanceOf(ScheduledTaskFinished::class, $this->dispatched[1]);
        $this->assertSame($callbackEvent, $this->dispatched[0]->task);
        $this->assertSame($callbackEvent, $this->dispatched[1]->task);
        $this->assertIsFloat($this->dispatched[1]->runtime);
    }

    public function testBackgroundTaskDispatchesAllThreeEvents()
    {
        $eventMutex = m::mock(EventMutex::class);
        $eventMutex->shouldReceive('create')->andReturn(true);
        $eventMutex->shouldReceive('forget');

        // Use a partial mock of Event so run() succeeds without executing a real command
        $event = m::mock(Event::class, [$eventMutex, 'test:background', null, false])->makePartial();
        $event->shouldReceive('run')->once();
        $event->runInBackground();

        $command = $this->makeCommand();
        $concurrent = new \Hypervel\Coroutine\Concurrent(10);
        (new ReflectionProperty($command, 'concurrent'))->setValue($command, $concurrent);

        $this->invokeRunEvents($command, [$event]);

        // Wait for background coroutine to complete
        while (! $concurrent->isEmpty()) {
            Coroutine::sleep(0.01);
        }

        $this->assertCount(3, $this->dispatched);
        $this->assertInstanceOf(ScheduledTaskStarting::class, $this->dispatched[0]);
        $this->assertInstanceOf(ScheduledTaskFinished::class, $this->dispatched[1]);
        $this->assertInstanceOf(ScheduledBackgroundTaskFinished::class, $this->dispatched[2]);
        $this->assertSame($event, $this->dispatched[0]->task);
        $this->assertSame($event, $this->dispatched[1]->task);
        $this->assertSame($event, $this->dispatched[2]->task);
    }

    public function testBackgroundTaskStillDispatchesBackgroundFinishedOnFailure()
    {
        $eventMutex = m::mock(EventMutex::class);
        $eventMutex->shouldReceive('create')->andReturn(true);

        $exception = new RuntimeException('Task exploded');

        $event = m::mock(Event::class, [$eventMutex, 'test:failing', null, false])->makePartial();
        $event->shouldReceive('run')->once()->andThrow($exception);
        $event->runInBackground();

        $this->handler->shouldReceive('report')->once()->with($exception);

        $command = $this->makeCommand();
        $concurrent = new \Hypervel\Coroutine\Concurrent(10);
        (new ReflectionProperty($command, 'concurrent'))->setValue($command, $concurrent);

        $this->invokeRunEvents($command, [$event]);

        while (! $concurrent->isEmpty()) {
            Coroutine::sleep(0.01);
        }

        // On failure: Starting, Failed (not Finished), then BackgroundFinished
        $this->assertCount(3, $this->dispatched);
        $this->assertInstanceOf(ScheduledTaskStarting::class, $this->dispatched[0]);
        $this->assertInstanceOf(ScheduledTaskFailed::class, $this->dispatched[1]);
        $this->assertInstanceOf(ScheduledBackgroundTaskFinished::class, $this->dispatched[2]);
        $this->assertSame($exception, $this->dispatched[1]->exception);
    }

    public function testSkippedTaskDispatchesSkippedEvent()
    {
        $eventMutex = m::mock(EventMutex::class);

        $callbackEvent = new CallbackEvent($eventMutex, function () {
            return 0;
        });
        $callbackEvent->when(false);

        $command = $this->makeCommand();
        $this->invokeRunEvents($command, [$callbackEvent]);

        $this->assertCount(1, $this->dispatched);
        $this->assertInstanceOf(ScheduledTaskSkipped::class, $this->dispatched[0]);
        $this->assertSame($callbackEvent, $this->dispatched[0]->task);
    }

    /**
     * Create a ScheduleRunCommand with mocked dependencies.
     */
    protected function makeCommand(): ScheduleRunCommand
    {
        $command = new ScheduleRunCommand();
        $command->setApp($this->app);

        // Set dependencies that are normally injected via handle().
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
