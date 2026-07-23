<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Scheduling;

use Carbon\CarbonInterface;
use Hypervel\Console\Commands\ScheduleRunCommand;
use Hypervel\Console\Events\ScheduledBackgroundTaskFinished;
use Hypervel\Console\Events\ScheduledTaskFailed;
use Hypervel\Console\Events\ScheduledTaskFinished;
use Hypervel\Console\Events\ScheduledTaskSkipped;
use Hypervel\Console\Events\ScheduledTaskStarting;
use Hypervel\Console\OutputStyle;
use Hypervel\Console\Scheduling\CallbackEvent;
use Hypervel\Console\Scheduling\Event;
use Hypervel\Console\Scheduling\EventMutex;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Coroutine\Concurrent;
use Hypervel\Engine\Channel;
use Hypervel\Support\Carbon;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Facades\Event as EventFacade;
use Hypervel\Support\Sleep;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Swoole\Coroutine;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

use function Hypervel\Coroutine\parallel;

class ScheduleRunCommandTest extends TestCase
{
    protected array $dispatched;

    protected Dispatcher $dispatcher;

    protected ExceptionHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dispatched = [];

        $this->dispatcher = m::mock(Dispatcher::class);
        $this->dispatcher->shouldReceive('hasListeners')
            ->byDefault()
            ->andReturnTrue();
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

    public function testTaskLifecycleEventsAreNotDispatchedWithoutListeners(): void
    {
        $eventMutex = m::mock(EventMutex::class);
        $eventMutex->shouldReceive('create')->andReturnTrue();
        $eventMutex->shouldReceive('forget');

        $callbackEvent = new CallbackEvent($eventMutex, fn (): int => 0);

        $this->dispatcher->shouldReceive('hasListeners')
            ->once()
            ->with(ScheduledTaskStarting::class)
            ->andReturnFalse();
        $this->dispatcher->shouldReceive('hasListeners')
            ->once()
            ->with(ScheduledTaskFinished::class)
            ->andReturnFalse();

        $command = $this->makeCommand();
        $this->invokeRunEvents($command, [$callbackEvent]);

        $this->assertSame([], $this->dispatched);
    }

    public function testTaskLifecycleEventsRemainVisibleToEventFakes(): void
    {
        EventFacade::fake([
            ScheduledTaskStarting::class,
            ScheduledTaskFinished::class,
        ]);

        $eventMutex = m::mock(EventMutex::class);
        $eventMutex->shouldReceive('create')->andReturnTrue();
        $eventMutex->shouldReceive('forget');

        $callbackEvent = new CallbackEvent($eventMutex, fn (): int => 0);

        $command = $this->makeCommand();
        (new ReflectionProperty($command, 'dispatcher'))->setValue($command, EventFacade::getFacadeRoot());

        $this->invokeRunEvents($command, [$callbackEvent]);

        EventFacade::assertDispatched(ScheduledTaskStarting::class);
        EventFacade::assertDispatched(ScheduledTaskFinished::class);
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

        $this->waitForConcurrent($concurrent);

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

        $this->waitForConcurrent($concurrent);

        // On failure: Starting, Failed (not Finished), then BackgroundFinished
        $this->assertCount(3, $this->dispatched);
        $this->assertInstanceOf(ScheduledTaskStarting::class, $this->dispatched[0]);
        $this->assertInstanceOf(ScheduledTaskFailed::class, $this->dispatched[1]);
        $this->assertInstanceOf(ScheduledBackgroundTaskFinished::class, $this->dispatched[2]);
        $this->assertSame($exception, $this->dispatched[1]->exception);
    }

    public function testThrownRunIsRenderedAsFailedWhenTheEventRetainsAZeroExitCode(): void
    {
        $exception = new RuntimeException('Task exploded after an earlier successful run.');
        $event = m::mock(Event::class, [m::mock(EventMutex::class), 'test:stale-exit', null, false])->makePartial();
        $event->finish($this->app, 0);
        $event->shouldReceive('run')->once()->andThrow($exception);

        $this->handler->shouldReceive('report')->once()->with($exception);

        $command = $this->makeCommand();
        $output = $this->captureOutput($command);

        $this->invokeRunEvent($command, $event);

        $rendered = $output->fetch();

        $this->assertStringContainsString('Failed', $rendered);
        $this->assertStringNotContainsString('Finished', $rendered);
    }

    public function testFinishedListenerFailureIsRenderedAsFailed(): void
    {
        $exception = new RuntimeException('Finished listener failed.');
        $failedEvent = null;
        $dispatcher = $this->app->make(Dispatcher::class);
        $dispatcher->listen(ScheduledTaskFinished::class, fn () => throw $exception);
        $dispatcher->listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event) use (&$failedEvent): void {
            $failedEvent = $event;
        });
        $this->dispatcher = $dispatcher;

        $this->handler->shouldReceive('report')->once()->with($exception);

        $event = new ScheduleRunExitCodeEvent(m::mock(EventMutex::class), 0, 'test:listener-failure');
        $command = $this->makeCommand();
        $output = $this->captureOutput($command);

        $this->invokeRunEvent($command, $event);

        $rendered = $output->fetch();

        $this->assertInstanceOf(ScheduledTaskFailed::class, $failedEvent);
        $this->assertSame($exception, $failedEvent->exception);
        $this->assertStringContainsString('Failed', $rendered);
        $this->assertStringNotContainsString('Finished', $rendered);
    }

    public function testNonZeroExitDispatchesAndReportsFailure(): void
    {
        $this->handler->shouldReceive('report')
            ->once()
            ->with(m::on(fn (Throwable $throwable): bool => $throwable instanceof RuntimeException
                && $throwable->getMessage() === 'Scheduled command [test:non-zero] failed with exit code [1].'));

        $event = new ScheduleRunExitCodeEvent(m::mock(EventMutex::class), 1, 'test:non-zero');
        $command = $this->makeCommand();
        $output = $this->captureOutput($command);

        $this->invokeRunEvent($command, $event);

        $this->assertCount(3, $this->dispatched);
        $this->assertInstanceOf(ScheduledTaskStarting::class, $this->dispatched[0]);
        $this->assertInstanceOf(ScheduledTaskFinished::class, $this->dispatched[1]);
        $this->assertInstanceOf(ScheduledTaskFailed::class, $this->dispatched[2]);
        $this->assertSame(
            'Scheduled command [test:non-zero] failed with exit code [1].',
            $this->dispatched[2]->exception->getMessage()
        );
        $this->assertStringContainsString('Failed', $output->fetch());
    }

    public function testBackgroundNonZeroExitDispatchesAndReportsFailureBeforeBackgroundCompletion(): void
    {
        $this->handler->shouldReceive('report')
            ->once()
            ->with(m::on(fn (Throwable $throwable): bool => $throwable instanceof RuntimeException
                && $throwable->getMessage() === 'Scheduled command [test:background-non-zero] failed with exit code [1].'));

        $event = new ScheduleRunExitCodeEvent(m::mock(EventMutex::class), 1, 'test:background-non-zero');
        $event->runInBackground();

        $command = $this->makeCommand();
        $output = $this->captureOutput($command);
        $concurrent = new Concurrent(10);
        (new ReflectionProperty($command, 'concurrent'))->setValue($command, $concurrent);

        $this->invokeRunEvents($command, [$event]);
        $this->waitForConcurrent($concurrent);

        $this->assertCount(4, $this->dispatched);
        $this->assertInstanceOf(ScheduledTaskStarting::class, $this->dispatched[0]);
        $this->assertInstanceOf(ScheduledTaskFinished::class, $this->dispatched[1]);
        $this->assertInstanceOf(ScheduledTaskFailed::class, $this->dispatched[2]);
        $this->assertInstanceOf(ScheduledBackgroundTaskFinished::class, $this->dispatched[3]);
        $this->assertStringContainsString('Failed', $output->fetch());
    }

    public function testOverlapSkipDoesNotReportThePreviousNonZeroExitAgain(): void
    {
        $mutex = m::mock(EventMutex::class);
        $mutex->shouldReceive('create')->once()->andReturnFalse();

        $event = new ScheduleRunExitCodeEvent($mutex, 1, 'test:overlap-skip');
        $event->finish($this->app, 1);
        $event->withoutOverlapping();

        $this->handler->shouldNotReceive('report');

        $command = $this->makeCommand();
        $output = $this->captureOutput($command);
        $this->invokeRunEvent($command, $event);

        $failedEvents = array_filter(
            $this->dispatched,
            static fn (object $event): bool => $event instanceof ScheduledTaskFailed
        );

        $this->assertSame([], array_values($failedEvents));
        $this->assertTrue($event->wasSkippedDueToOverlapping());

        $rendered = $output->fetch();

        $this->assertStringContainsString('Skipped', $rendered);
        $this->assertStringNotContainsString('Finished', $rendered);
        $this->assertStringNotContainsString('Failed', $rendered);
    }

    public function testConcurrentOverlapSkipDoesNotHideTheRunningEventsNonZeroFailure(): void
    {
        $entered = new Channel(1);
        $release = new Channel(1);
        $skipped = new Channel(1);
        $event = new ScheduleRunConcurrentOverlapEvent(
            new ScheduleRunTestEventMutex,
            $entered,
            $release,
            $skipped,
        );
        $event->withoutOverlapping()->everySecond()->runInBackground();

        $this->handler->shouldReceive('report')
            ->once()
            ->with(m::on(fn (Throwable $throwable): bool => $throwable instanceof RuntimeException
                && $throwable->getMessage() === 'Scheduled command [test:concurrent-overlap] failed with exit code [1].'));

        $command = $this->makeCommand();
        $concurrent = new Concurrent(2);
        $concurrent->fork(fn () => $this->invokeRunEvent($command, $event));

        $enteredRun = $entered->pop(5.0);
        $skippedRun = false;

        try {
            if ($enteredRun === true) {
                $concurrent->fork(fn () => $this->invokeRunEvent($command, $event));

                $skippedRun = $skipped->pop(5.0);
            }
        } finally {
            $release->push(true, 5.0);
            $this->waitForConcurrent($concurrent);
        }

        $this->assertTrue($enteredRun);
        $this->assertTrue($skippedRun);
        $this->assertTrue($event->skippedBecauseOverlapping);

        $failedEvents = array_values(array_filter(
            $this->dispatched,
            static fn (object $event): bool => $event instanceof ScheduledTaskFailed
        ));

        $this->assertCount(1, $failedEvents);
        $this->assertSame($event, $failedEvents[0]->task);
    }

    public function testBackgroundFinishedEventIsNotDispatchedWithoutListeners(): void
    {
        $eventMutex = m::mock(EventMutex::class);

        $event = m::mock(Event::class, [$eventMutex, 'test:background', null, false])->makePartial();
        $event->shouldReceive('run')->once();
        $event->runInBackground();

        $this->dispatcher->shouldReceive('hasListeners')
            ->once()
            ->with(ScheduledTaskStarting::class)
            ->andReturnFalse();
        $this->dispatcher->shouldReceive('hasListeners')
            ->once()
            ->with(ScheduledTaskFinished::class)
            ->andReturnFalse();
        $this->dispatcher->shouldReceive('hasListeners')
            ->once()
            ->with(ScheduledBackgroundTaskFinished::class)
            ->andReturnFalse();

        $command = $this->makeCommand();
        $concurrent = new \Hypervel\Coroutine\Concurrent(10);
        (new ReflectionProperty($command, 'concurrent'))->setValue($command, $concurrent);

        $this->invokeRunEvents($command, [$event]);

        $this->waitForConcurrent($concurrent);

        $this->assertSame([], $this->dispatched);
    }

    public function testSkippedNonRepeatableTaskIsOnlyEvaluatedOncePerMinute(): void
    {
        $eventMutex = m::mock(EventMutex::class);

        $callbackEvent = new CallbackEvent($eventMutex, function () {
            return 0;
        });
        $callbackEvent->when(false);

        $command = $this->makeCommand();
        $startedAt = CarbonImmutable::parse('2026-05-28 12:34:00');

        $this->invokeRunEvents($command, [$callbackEvent], $startedAt);
        $this->invokeRunEvents($command, [$callbackEvent], $startedAt->addSeconds(30));

        $this->assertCount(1, $this->dispatched);
        $this->assertInstanceOf(ScheduledTaskSkipped::class, $this->dispatched[0]);
        $this->assertSame($callbackEvent, $this->dispatched[0]->task);
    }

    public function testSkippedTaskEventIsGuardedByRegisteredListeners()
    {
        $eventMutex = m::mock(EventMutex::class);

        $callbackEvent = new CallbackEvent($eventMutex, function () {
            return 0;
        });
        $callbackEvent->when(false);

        $this->dispatcher->shouldReceive('hasListeners')
            ->once()
            ->with(ScheduledTaskSkipped::class)
            ->andReturnFalse();

        $command = $this->makeCommand();
        $this->invokeRunEvents($command, [$callbackEvent]);

        $this->assertSame([], $this->dispatched);
    }

    public function testPausedTaskIsSkippedWithoutRunningFilters()
    {
        $eventMutex = m::mock(EventMutex::class);

        $callbackEvent = m::mock(CallbackEvent::class, [$eventMutex, function () {
            return 0;
        }])->makePartial();
        $callbackEvent->shouldReceive('filtersPass')->never();

        $cache = m::mock(Cache::class);
        $cache->shouldReceive('get')
            ->once()
            ->with('hypervel:schedule:paused', false)
            ->andReturnTrue();

        $command = $this->makeCommand($cache);
        $this->invokeRunEvents($command, [$callbackEvent]);

        $this->assertCount(1, $this->dispatched);
        $this->assertInstanceOf(ScheduledTaskSkipped::class, $this->dispatched[0]);
        $this->assertSame($callbackEvent, $this->dispatched[0]->task);
    }

    public function testTaskMarkedEvenWhenPausedRunsWhileSchedulerIsPaused()
    {
        $runCount = 0;

        $eventMutex = m::mock(EventMutex::class);
        $eventMutex->shouldReceive('create')->andReturn(true);
        $eventMutex->shouldReceive('forget');

        $callbackEvent = new CallbackEvent($eventMutex, function () use (&$runCount) {
            ++$runCount;

            return 0;
        });
        $callbackEvent->evenWhenPaused();

        $cache = m::mock(Cache::class);
        $cache->shouldReceive('get')
            ->once()
            ->with('hypervel:schedule:paused', false)
            ->andReturnTrue();

        $command = $this->makeCommand($cache);
        $this->invokeRunEvents($command, [$callbackEvent]);

        $this->assertSame(1, $runCount);
        $this->assertCount(2, $this->dispatched);
        $this->assertInstanceOf(ScheduledTaskStarting::class, $this->dispatched[0]);
        $this->assertInstanceOf(ScheduledTaskFinished::class, $this->dispatched[1]);
    }

    public function testPausedNonRepeatableTaskIsOnlyEvaluatedOncePerMinute(): void
    {
        $eventMutex = m::mock(EventMutex::class);

        $callbackEvent = new CallbackEvent($eventMutex, function () {
            return 0;
        });

        $cache = m::mock(Cache::class);
        $cache->shouldReceive('get')
            ->once()
            ->with('hypervel:schedule:paused', false)
            ->andReturnTrue();

        $command = $this->makeCommand($cache);
        $startedAt = CarbonImmutable::parse('2026-05-28 12:34:00');

        $this->invokeRunEvents($command, [$callbackEvent], $startedAt);
        $this->invokeRunEvents($command, [$callbackEvent], $startedAt->addSeconds(30));

        $this->assertCount(1, $this->dispatched);
        $this->assertInstanceOf(ScheduledTaskSkipped::class, $this->dispatched[0]);
        $this->assertSame($callbackEvent, $this->dispatched[0]->task);
    }

    public function testNonRepeatableEventOnlyRunsOncePerMinute(): void
    {
        $runCount = 0;

        $eventMutex = m::mock(EventMutex::class);
        $eventMutex->shouldReceive('create')->andReturn(true);
        $eventMutex->shouldReceive('forget');

        $callbackEvent = new CallbackEvent($eventMutex, function () use (&$runCount) {
            ++$runCount;
            return 0;
        });

        $command = $this->makeCommand();
        $startedAt = CarbonImmutable::parse('2026-05-28 12:34:00');

        $this->invokeRunEvents($command, [$callbackEvent], $startedAt);
        $this->assertSame(1, $runCount);
        $this->assertNotNull($callbackEvent->lastChecked);

        $this->invokeRunEvents($command, [$callbackEvent], $startedAt->addSeconds(30));
        $this->assertSame(1, $runCount);

        $this->invokeRunEvents($command, [$callbackEvent], $startedAt->addMinute());
        $this->assertSame(2, $runCount);
    }

    public function testRepeatableEventIsThrottledByLastChecked()
    {
        $runCount = 0;

        $eventMutex = m::mock(EventMutex::class);
        $eventMutex->shouldReceive('create')->andReturn(true);
        $eventMutex->shouldReceive('forget');

        $callbackEvent = new CallbackEvent($eventMutex, function () use (&$runCount) {
            ++$runCount;
            return 0;
        });
        // Make it repeatable (every 10 seconds).
        $callbackEvent->repeatSeconds = 10;

        $command = $this->makeCommand();

        // First call — event runs and sets lastChecked.
        $this->invokeRunEvents($command, [$callbackEvent]);
        $this->assertSame(1, $runCount);

        // Immediately after — shouldRepeatNow() returns false because
        // less than 10 seconds have passed. Event should be skipped.
        $this->invokeRunEvents($command, [$callbackEvent]);
        $this->assertSame(1, $runCount);
    }

    #[DataProvider('dateClassProvider')]
    public function testRepeatEventsPreservesOriginalStartForSingleServerMutex(string $dateClass): void
    {
        Date::use($dateClass);
        Date::setTestNow('2026-05-28 12:34:00');
        Sleep::fake();

        $startedAt = Date::now()->startOfMinute();
        $expectedStartedAt = $startedAt->format('Y-m-d H:i:s');
        $eventMutex = m::mock(EventMutex::class);
        $callbackEvent = new CallbackEvent($eventMutex, function () use ($startedAt): int {
            Date::setTestNow($startedAt->copy()->addMinute());

            return 0;
        });
        $callbackEvent->name('single-server-repeat')->onOneServer();
        $callbackEvent->repeatSeconds = 1;
        $callbackEvent->lastChecked = $startedAt->copy()->subSecond();

        $schedule = m::mock(Schedule::class);
        $schedule->shouldReceive('serverShouldRun')
            ->once()
            ->withArgs(function (Event $event, CarbonInterface $time) use ($callbackEvent, $expectedStartedAt): bool {
                $this->assertSame($callbackEvent, $event);
                $this->assertSame($expectedStartedAt, $time->format('Y-m-d H:i:s'));

                return true;
            })
            ->andReturnTrue();

        $command = $this->makeCommand();
        (new ReflectionProperty($command, 'schedule'))->setValue($command, $schedule);
        (new ReflectionProperty($command, 'startedAt'))->setValue($command, $startedAt);

        $this->invokeRepeatEvents($command, [$callbackEvent]);

        $this->assertSame($dateClass, $startedAt::class);
        $this->assertSame($expectedStartedAt, $startedAt->format('Y-m-d H:i:s'));
        Sleep::assertSleptTimes(1);
    }

    /**
     * Provide mutable and immutable Date factory classes.
     */
    public static function dateClassProvider(): array
    {
        return [
            'immutable default' => [CarbonImmutable::class],
            'mutable opt-out' => [Carbon::class],
        ];
    }

    public function testConcurrentFinishesUseRunLocalExitCodeForSuccessAndFailureCallbacks()
    {
        $eventMutex = m::mock(EventMutex::class);
        $event = new Event($eventMutex, 'test:overlap');
        $channel = new Channel(2);

        $event->then(function () {
            usleep(5000);
        });
        $event->onSuccess(function () use ($channel) {
            $channel->push(CoroutineContext::get('__test.schedule_run') . ':success');
        });
        $event->onFailure(function () use ($channel) {
            $channel->push(CoroutineContext::get('__test.schedule_run') . ':failure');
        });

        parallel([
            function () use ($event) {
                CoroutineContext::set('__test.schedule_run', 'alpha');
                $event->finish($this->app, 0);
            },
            function () use ($event) {
                usleep(2500);
                CoroutineContext::set('__test.schedule_run', 'bravo');
                $event->finish($this->app, 1);
            },
        ]);

        $firstResult = $channel->pop(5.0);
        $secondResult = $channel->pop(5.0);

        if (! is_string($firstResult) || ! is_string($secondResult)) {
            $this->fail('Scheduled completion callbacks did not finish within five seconds.');
        }

        $results = [$firstResult, $secondResult];

        $this->assertContains('alpha:success', $results);
        $this->assertContains('bravo:failure', $results);
    }

    public function testSignalCleanupReleasesMutexesForRunningOwnedEvents()
    {
        $eventMutex = m::mock(EventMutex::class);
        $eventMutex->shouldReceive('create')->once()->andReturnTrue();
        $eventMutex->shouldReceive('forget')->once();

        $event = new Event($eventMutex, 'test:overlap');
        $event->withoutOverlapping();

        $this->assertFalse($event->shouldSkipDueToOverlapping());

        $command = $this->makeCommand();

        $register = new ReflectionMethod($command, 'registerRunningEvent');
        $register->invoke($command, $event);

        $release = new ReflectionMethod($command, 'releaseRunningEventMutexes');
        $release->invoke($command);
    }

    public function testSignalCleanupDoesNotReleaseMutexesForEventsWithoutOwnedMutexes()
    {
        $eventMutex = m::mock(EventMutex::class);
        $eventMutex->shouldNotReceive('forget');

        $event = new Event($eventMutex, 'test:overlap');
        $event->withoutOverlapping();

        $command = $this->makeCommand();

        $register = new ReflectionMethod($command, 'registerRunningEvent');
        $register->invoke($command, $event);

        $release = new ReflectionMethod($command, 'releaseRunningEventMutexes');
        $release->invoke($command);
    }

    public function testSignalCleanupHonorsReleaseOnTerminationSignalsFlag()
    {
        $eventMutex = m::mock(EventMutex::class);
        $eventMutex->shouldReceive('create')->once()->andReturnTrue();
        $eventMutex->shouldNotReceive('forget');

        $event = new Event($eventMutex, 'test:overlap');
        $event->withoutOverlapping(releaseOnTerminationSignals: false);

        $this->assertFalse($event->shouldSkipDueToOverlapping());

        $command = $this->makeCommand();

        $register = new ReflectionMethod($command, 'registerRunningEvent');
        $register->invoke($command, $event);

        $release = new ReflectionMethod($command, 'releaseRunningEventMutexes');
        $release->invoke($command);
    }

    public function testRunningEventIsRetainedUntilEveryOverlappingInvocationFinishes(): void
    {
        $eventMutex = m::mock(EventMutex::class);
        $eventMutex->shouldReceive('create')->once()->andReturnTrue();
        $eventMutex->shouldReceive('forget')->once();

        $event = new Event($eventMutex, 'test:overlap');
        $event->withoutOverlapping();

        $this->assertFalse($event->shouldSkipDueToOverlapping());

        $command = $this->makeCommand();
        $register = new ReflectionMethod($command, 'registerRunningEvent');
        $forget = new ReflectionMethod($command, 'forgetRunningEvent');
        $release = new ReflectionMethod($command, 'releaseRunningEventMutexes');

        $register->invoke($command, $event);
        $register->invoke($command, $event);
        $forget->invoke($command, $event);

        $runningEvents = (new ReflectionProperty($command, 'runningEvents'))->getValue($command);

        $this->assertSame(1, $runningEvents[spl_object_id($event)]['count']);

        $release->invoke($command);
        $forget->invoke($command, $event);

        $this->assertSame([], (new ReflectionProperty($command, 'runningEvents'))->getValue($command));
    }

    public function testRunningEventIsForgottenWhenEventThrows()
    {
        $eventMutex = m::mock(EventMutex::class);
        $eventMutex->shouldReceive('create')->andReturn(true);

        $exception = new RuntimeException('Task exploded');

        $event = m::mock(Event::class, [$eventMutex, 'test:failing', null, false])->makePartial();
        $event->shouldReceive('run')->once()->andThrow($exception);

        $this->handler->shouldReceive('report')->once()->with($exception);

        $command = $this->makeCommand();
        $this->invokeRunEvent($command, $event);

        $runningEvents = (new ReflectionProperty($command, 'runningEvents'))->getValue($command);

        $this->assertSame([], $runningEvents);
    }

    /**
     * Create a ScheduleRunCommand with mocked dependencies.
     */
    protected function makeCommand(?Cache $cache = null): ScheduleRunCommand
    {
        $command = new ScheduleRunCommand;
        $command->setHypervel($this->app);

        $cache ??= m::mock(Cache::class);
        $cache->shouldReceive('get')
            ->byDefault()
            ->with('hypervel:schedule:paused', false)
            ->andReturnFalse();

        // Set dependencies that are normally injected via handle().
        (new ReflectionProperty($command, 'schedule'))->setValue($command, m::mock(Schedule::class));
        (new ReflectionProperty($command, 'dispatcher'))->setValue($command, $this->dispatcher);
        (new ReflectionProperty($command, 'cache'))->setValue($command, $cache);
        (new ReflectionProperty($command, 'handler'))->setValue($command, $this->handler);

        return $command;
    }

    /**
     * Capture command output in memory.
     */
    protected function captureOutput(ScheduleRunCommand $command): BufferedOutput
    {
        $output = new BufferedOutput;

        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

        return $output;
    }

    /**
     * Wait for all owned background coroutines to finish.
     */
    protected function waitForConcurrent(Concurrent $concurrent): void
    {
        $deadline = microtime(true) + 5.0;

        while (! $concurrent->isEmpty()) {
            if (microtime(true) >= $deadline) {
                $this->fail('Background scheduled events did not finish within five seconds.');
            }

            Coroutine::sleep(0.001);
        }
    }

    /**
     * Invoke the protected runEvents method.
     */
    protected function invokeRunEvents(ScheduleRunCommand $command, array $events, ?CarbonInterface $startedAt = null): void
    {
        $method = new ReflectionMethod($command, 'runEvents');
        $method->invoke($command, new Collection($events), $startedAt ?? Date::now());
    }

    /**
     * Invoke the protected repeatEvents method.
     */
    protected function invokeRepeatEvents(ScheduleRunCommand $command, array $events): void
    {
        $method = new ReflectionMethod($command, 'repeatEvents');
        $method->invoke($command, new Collection($events));
    }

    /**
     * Invoke the protected runEvent method.
     */
    protected function invokeRunEvent(ScheduleRunCommand $command, Event $event): void
    {
        $method = new ReflectionMethod($command, 'runEvent');
        $method->invoke($command, $event);
    }
}

class ScheduleRunExitCodeEvent extends Event
{
    public function __construct(EventMutex $mutex, protected int $result, string $command)
    {
        parent::__construct($mutex, $command);
    }

    /**
     * Execute the scheduled event.
     */
    protected function execute(ContainerContract $container): int
    {
        return $this->result;
    }
}

class ScheduleRunConcurrentOverlapEvent extends Event
{
    public function __construct(
        EventMutex $mutex,
        protected Channel $entered,
        protected Channel $release,
        protected Channel $skipped,
    ) {
        parent::__construct($mutex, 'test:concurrent-overlap');
    }

    /**
     * Run the scheduled event.
     */
    public function run(ContainerContract $container): mixed
    {
        $result = parent::run($container);

        if ($this->wasSkippedDueToOverlapping()) {
            if (! $this->skipped->push(true, 5.0)) {
                throw new RuntimeException('Unable to publish the overlap skip signal.');
            }
        }

        return $result;
    }

    /**
     * Execute the scheduled event.
     */
    protected function execute(ContainerContract $container): int
    {
        if (! $this->entered->push(true, 5.0)) {
            throw new RuntimeException('Unable to publish the running event signal.');
        }

        if ($this->release->pop(5.0) !== true) {
            throw new RuntimeException('The running event was not released.');
        }

        return 1;
    }
}

class ScheduleRunTestEventMutex implements EventMutex
{
    protected bool $locked = false;

    /**
     * Attempt to obtain an event mutex for the given event.
     */
    public function create(Event $event): bool
    {
        if ($this->locked) {
            return false;
        }

        return $this->locked = true;
    }

    /**
     * Determine if an event mutex exists for the given event.
     */
    public function exists(Event $event): bool
    {
        return $this->locked;
    }

    /**
     * Clear the event mutex for the given event.
     */
    public function forget(Event $event): void
    {
        $this->locked = false;
    }
}
