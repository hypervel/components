<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Scheduling;

use DateTimeZone;
use Hypervel\Console\Scheduling\CallbackEvent;
use Hypervel\Console\Scheduling\Event;
use Hypervel\Console\Scheduling\EventMutex;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Application;
use Hypervel\Support\Carbon;
use Hypervel\Support\Str;
use Hypervel\Support\Stringable;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

enum EventTestTimezoneStringEnum: string
{
    case NewYork = 'America/New_York';
    case London = 'Europe/London';
}

enum EventTestTimezoneIntEnum: int
{
    case Zone1 = 1;
    case Zone2 = 2;
}

enum EventTestTimezoneUnitEnum
{
    case UTC;
    case EST;
}

class EventTest extends TestCase
{
    protected ?Container $container = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Application;
        Container::setInstance($this->container);
        $this->container->instance(Filesystem::class, new Filesystem);
    }

    public function testSendOutputToWithIsNotFile()
    {
        $event = new Event(m::mock(EventMutex::class), 'php -v');

        $event->sendOutputTo($output = 'test.log');
        $filesystem = m::mock(Filesystem::class);
        $filesystem->shouldReceive('isFile')
            ->once()
            ->with($output)
            ->andReturn(false);

        $this->container->instance(Filesystem::class, $filesystem);
        $event->writeOutput($this->container);
    }

    public function testSendOutputTo()
    {
        $event = new Event(m::mock(EventMutex::class), 'php -v');

        $event->sendOutputTo($output = 'test.log');

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('output')
            ->once()
            ->andReturn($result = 'PHP 8.3.17 (cli) (built: Feb 11 2025 22:03:03) (NTS)');

        $filesystem = m::mock(Filesystem::class);
        $filesystem->shouldReceive('isFile')
            ->once()
            ->with($output)
            ->andReturn(true);
        $filesystem->shouldReceive('put')
            ->once()
            ->with($output, $result)
            ->andReturn(strlen($result));

        $this->container->instance(KernelContract::class, $kernel);
        $this->container->instance(Filesystem::class, $filesystem);

        $event->writeOutput($this->container);
    }

    public function testSendOutputToWithSystemProcess()
    {
        $event = new Event(m::mock(EventMutex::class), 'php -v');
        $event->isSystem = true;

        $event->sendOutputTo($output = 'test.log');

        $process = m::mock(Process::class);
        $process->shouldReceive('getOutput')
            ->once()
            ->andReturn($result = 'PHP 8.3.17 (cli) (built: Feb 11 2025 22:03:03) (NTS)');
        CoroutineContext::set($key = '__console.scheduling_process.' . spl_object_id($event), $process);

        $filesystem = m::mock(Filesystem::class);
        $filesystem->shouldReceive('put')
            ->once()
            ->with($output, $result)
            ->andReturn(strlen($result));

        $this->container->instance(Filesystem::class, $filesystem);

        $event->writeOutput($this->container);

        CoroutineContext::forget($key);
    }

    public function testDaysOfMonthMethod()
    {
        $event = new Event(m::mock(EventMutex::class), 'php -i');

        $event->daysOfMonth(1, 15);
        $this->assertSame('0 0 1,15 * *', $event->getExpression());

        $event = new Event(m::mock(EventMutex::class), 'php -i');
        $event->daysOfMonth([1, 10, 20, 30]);
        $this->assertSame('0 0 1,10,20,30 * *', $event->getExpression());
    }

    public function testEventDoesNotRunWhenPausedByDefault()
    {
        $event = new Event(m::mock(EventMutex::class), 'php -i');

        $this->assertFalse($event->runsWhenPaused());
    }

    public function testEventRunsWhenMarkedAsEvenWhenPaused()
    {
        $event = new Event(m::mock(EventMutex::class), 'php -i');
        $event->evenWhenPaused();

        $this->assertTrue($event->runsWhenPaused());
    }

    public function testEventMarksSkippedWhenMutexAlreadyExists()
    {
        $eventMutex = m::mock(EventMutex::class);
        $eventMutex->shouldReceive('create')->once()->andReturnFalse();

        $event = new CallbackEvent($eventMutex, function () {
            return 0;
        });
        $event->name('test');
        $event->withoutOverlapping();

        $this->assertNull($event->run($this->container));
        $this->assertTrue($event->skippedBecauseOverlapping);
    }

    public function testEventResetsSkippedBecauseOverlappingWhenItRuns()
    {
        $eventMutex = m::mock(EventMutex::class);
        $eventMutex->shouldReceive('create')->andReturnFalse();

        $event = new CallbackEvent($eventMutex, function () {
            return 0;
        });
        $event->name('test');
        $event->withoutOverlapping();

        $this->assertNull($event->run($this->container));
        $this->assertTrue($event->skippedBecauseOverlapping);

        $eventMutex = m::mock(EventMutex::class);
        $eventMutex->shouldReceive('create')->once()->andReturnTrue();
        $eventMutex->shouldReceive('forget')->once();

        $event = new CallbackEvent($eventMutex, function () {
            return 0;
        });
        $event->name('test');
        $event->withoutOverlapping();
        $event->skippedBecauseOverlapping = true;

        $this->container->instance(Filesystem::class, new Filesystem);

        $this->assertSame(0, $event->run($this->container));
        $this->assertFalse($event->skippedBecauseOverlapping);
    }

    public function testReleaseMutexOnTerminationSignalReleasesOwnedMutex()
    {
        $eventMutex = m::mock(EventMutex::class);
        $eventMutex->shouldReceive('create')->once()->andReturnTrue();
        $eventMutex->shouldReceive('forget')->once();

        $event = new Event($eventMutex, 'php -i');
        $event->withoutOverlapping();

        $this->assertFalse($event->shouldSkipDueToOverlapping());

        $event->releaseMutexOnTerminationSignal();
    }

    public function testReleaseMutexOnTerminationSignalDoesNotReleaseUnownedMutex()
    {
        $eventMutex = m::mock(EventMutex::class);
        $eventMutex->shouldNotReceive('forget');

        $event = new Event($eventMutex, 'php -i');
        $event->withoutOverlapping();

        $event->releaseMutexOnTerminationSignal();
    }

    public function testReleaseMutexOnTerminationSignalHonorsReleaseFlag()
    {
        $eventMutex = m::mock(EventMutex::class);
        $eventMutex->shouldReceive('create')->once()->andReturnTrue();
        $eventMutex->shouldNotReceive('forget');

        $event = new Event($eventMutex, 'php -i');
        $event->withoutOverlapping(releaseOnTerminationSignals: false);

        $this->assertFalse($event->shouldSkipDueToOverlapping());

        $event->releaseMutexOnTerminationSignal();
    }

    public function testStartFailureRemainsPrimaryWhenMutexCleanupFails(): void
    {
        $mutex = m::mock(EventMutex::class);
        $mutex->shouldReceive('create')->once()->andReturnTrue();
        $mutex->shouldReceive('forget')->once()->andThrow(new RuntimeException('mutex cleanup failed'));

        $event = new Event($mutex, 'php -i');
        $event->withoutOverlapping();
        $event->before(fn () => throw new RuntimeException('before callback failed'));

        try {
            $event->run($this->container);
            $this->fail('The before callback exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('before callback failed', $exception->getMessage());
        }

        $event->releaseMutexOnTerminationSignal();
    }

    public function testOutputFailureStillRunsAfterCallbacksAndReleasesMutex(): void
    {
        $afterCalled = false;
        $mutex = m::mock(EventMutex::class);
        $mutex->shouldReceive('create')->once()->andReturnTrue();
        $mutex->shouldReceive('forget')->once();

        $event = new EventTestExecutableEvent($mutex);
        $event->withoutOverlapping();
        $event->sendOutputTo($output = 'test.log');
        $event->after(function () use (&$afterCalled): void {
            $afterCalled = true;
        });

        $filesystem = m::mock(Filesystem::class);
        $filesystem->shouldReceive('isFile')->once()->with($output)->andReturnTrue();
        $filesystem->shouldReceive('put')->once()->with($output, 'output')->andReturnFalse();
        $this->container->instance(Filesystem::class, $filesystem);

        try {
            $event->run($this->container);
            $this->fail('The output exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to write the scheduled event output to [test.log].', $exception->getMessage());
        }

        $this->assertTrue($afterCalled);
        $this->assertSame(0, $event->exitCode());
    }

    public function testAfterCallbackFailureRemainsPrimaryWhenMutexCleanupFails(): void
    {
        $mutex = m::mock(EventMutex::class);
        $mutex->shouldReceive('create')->once()->andReturnTrue();
        $mutex->shouldReceive('forget')->once()->andThrow(new RuntimeException('mutex cleanup failed'));

        $event = new EventTestExecutableEvent($mutex);
        $event->withoutOverlapping();
        $event->after(fn () => throw new RuntimeException('after callback failed'));

        try {
            $event->run($this->container);
            $this->fail('The after callback exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('after callback failed', $exception->getMessage());
        }

        $event->releaseMutexOnTerminationSignal();
    }

    public function testExitPublicationFailureStillRunsCallbacksAndReleasesMutex(): void
    {
        $afterCalled = false;
        $mutex = m::mock(EventMutex::class);
        $mutex->shouldReceive('forget')->once();

        $event = new EventTestFailingExitCodeEvent($mutex);
        $event->withoutOverlapping = true;
        $event->acquireMutexForTest();
        $event->after(function () use (&$afterCalled): void {
            $afterCalled = true;
        });

        try {
            $event->finish($this->container, 0);
            $this->fail('The exit publication exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('exit publication failed', $exception->getMessage());
        }

        $this->assertTrue($afterCalled);
    }

    public function testAppendOutput()
    {
        $event = new Event(m::mock(EventMutex::class), 'php -v');

        $event->appendOutputTo($output = 'test.log');

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('output')
            ->once()
            ->andReturn($result = 'PHP 8.3.17 (cli) (built: Feb 11 2025 22:03:03) (NTS)');

        $filesystem = m::mock(Filesystem::class);
        $filesystem->shouldReceive('isFile')
            ->once()
            ->with($output)
            ->andReturn(true);
        $filesystem->shouldReceive('append')
            ->once()
            ->with($output, $result)
            ->andReturn(strlen($result));

        $this->container->instance(KernelContract::class, $kernel);
        $this->container->instance(Filesystem::class, $filesystem);

        $event->writeOutput($this->container);
    }

    public function testWriteOutputRejectsFailedWrites(): void
    {
        $event = new Event(m::mock(EventMutex::class), 'php -v');
        $event->sendOutputTo($output = 'test.log');

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('output')->once()->andReturn($result = 'output');

        $filesystem = m::mock(Filesystem::class);
        $filesystem->shouldReceive('isFile')->once()->with($output)->andReturnTrue();
        $filesystem->shouldReceive('put')->once()->with($output, $result)->andReturnFalse();

        $this->container->instance(KernelContract::class, $kernel);
        $this->container->instance(Filesystem::class, $filesystem);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to write the scheduled event output to [test.log].');

        $event->writeOutput($this->container);
    }

    public function testWriteOutputRejectsPartialWrites(): void
    {
        $event = new Event(m::mock(EventMutex::class), 'php -v');
        $event->appendOutputTo($output = 'test.log');

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('output')->once()->andReturn($result = 'output');

        $filesystem = m::mock(Filesystem::class);
        $filesystem->shouldReceive('isFile')->once()->with($output)->andReturnTrue();
        $filesystem->shouldReceive('append')->once()->with($output, $result)->andReturn(3);

        $this->container->instance(KernelContract::class, $kernel);
        $this->container->instance(Filesystem::class, $filesystem);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to write the scheduled event output to [test.log].');

        $event->writeOutput($this->container);
    }

    public function testOutputCallbackSurfacesReadFailureAfterFileCheck(): void
    {
        $event = new Event(m::mock(EventMutex::class), 'php -v');
        $event->sendOutputTo($output = 'test.log');
        $event->thenWithOutput(fn (Stringable $output) => $output);

        $filesystem = m::mock(Filesystem::class);
        $filesystem->shouldReceive('isFile')->once()->with($output)->andReturnTrue();
        $filesystem->shouldReceive('get')->once()->with($output)->andThrow(
            new FileNotFoundException("Unable to read file at path {$output}.")
        );

        $this->container->instance(Filesystem::class, $filesystem);

        $this->expectException(FileNotFoundException::class);
        $this->expectExceptionMessage('Unable to read file at path test.log.');

        $event->finish($this->container, 0);
    }

    public function testProcessIsRetainedThroughAfterCallbacksAndReleasedAfterSuccess(): void
    {
        $outputDuringCallback = null;
        $process = m::mock(Process::class);
        $process->shouldReceive('getOutput')->once()->andReturn('process output');

        $event = new EventTestProcessEvent(m::mock(EventMutex::class), $process);
        $event->after(function (Event $event) use (&$outputDuringCallback): void {
            $outputDuringCallback = $event->getOutput($this->container);
        });

        $event->run($this->container);

        $this->assertSame('process output', $outputDuringCallback);
        $this->assertFalse($event->hasRetainedProcess());
    }

    public function testProcessIsReleasedAfterExecutionFailure(): void
    {
        $event = new EventTestProcessEvent(
            m::mock(EventMutex::class),
            m::mock(Process::class),
            new RuntimeException('execution failed')
        );

        try {
            $event->run($this->container);
            $this->fail('The execution exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('execution failed', $exception->getMessage());
        }

        $this->assertFalse($event->hasRetainedProcess());
    }

    public function testProcessIsReleasedAfterOutputFailure(): void
    {
        $afterCalled = false;
        $process = m::mock(Process::class);
        $process->shouldReceive('getOutput')->once()->andReturn('process output');

        $event = new EventTestProcessEvent(m::mock(EventMutex::class), $process);
        $event->sendOutputTo($output = 'test.log');
        $event->after(function () use (&$afterCalled): void {
            $afterCalled = true;
        });

        $filesystem = m::mock(Filesystem::class);
        $filesystem->shouldReceive('put')->once()->with($output, 'process output')->andReturnFalse();
        $this->container->instance(Filesystem::class, $filesystem);

        try {
            $event->run($this->container);
            $this->fail('The output exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to write the scheduled event output to [test.log].', $exception->getMessage());
        }

        $this->assertTrue($afterCalled);
        $this->assertFalse($event->hasRetainedProcess());
    }

    public function testProcessIsReleasedAfterAfterCallbackFailure(): void
    {
        $event = new EventTestProcessEvent(m::mock(EventMutex::class), m::mock(Process::class));
        $event->after(fn () => throw new RuntimeException('after callback failed'));

        try {
            $event->run($this->container);
            $this->fail('The after callback exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('after callback failed', $exception->getMessage());
        }

        $this->assertFalse($event->hasRetainedProcess());
    }

    public function testNextRunDate()
    {
        $event = new Event(m::mock(EventMutex::class), 'php -i');
        $event->dailyAt('10:15');

        $this->assertSame('10:15:00', $event->nextRunDate()->toTimeString());
    }

    public function testCustomMutexName()
    {
        $event = new Event(m::mock(EventMutex::class), 'php -i');
        $event->description('Fancy command description');

        $this->assertSame(
            'framework' . DIRECTORY_SEPARATOR . 'schedule-' . hash('xxh128', $event->getExpression() . Event::normalizeCommand('php -i')),
            $event->mutexName()
        );

        $event->createMutexNameUsing(function (Event $event) {
            return Str::slug($event->description);
        });

        $this->assertSame('fancy-command-description', $event->mutexName());
    }

    public function testBeforeAndAfterCallbacksCanReceiveEvent(): void
    {
        $beforeEvent = null;
        $afterEvent = null;
        $event = new Event(m::mock(EventMutex::class), 'php -i');

        $event->before(function (Event $event) use (&$beforeEvent): void {
            $beforeEvent = $event;
        });
        $event->after(function (Event $event) use (&$afterEvent): void {
            $afterEvent = $event;
        });

        $event->callBeforeCallbacks($this->container);
        $event->callAfterCallbacks($this->container);

        $this->assertSame($event, $beforeEvent);
        $this->assertSame($event, $afterEvent);
    }

    public function testFilterCallbacksCanReceiveEventAndMayBeInvokableObjects(): void
    {
        $filterEvent = null;
        $reject = new EventTestInvokableFilter(false);
        $event = new Event(m::mock(EventMutex::class), 'php -i');

        $event->when(function (Event $scheduledEvent) use (&$filterEvent): bool {
            $filterEvent = $scheduledEvent;

            return true;
        });
        $event->skip($reject);

        $this->assertTrue($event->filtersPass($this->container));
        $this->assertSame($event, $filterEvent);
        $this->assertSame(1, $reject->calls);
    }

    public function testEventCallbackDoesNotReplaceUnrelatedTypedParameters(): void
    {
        $value = new Stringable('injected-string');
        $received = null;
        $event = new Event(m::mock(EventMutex::class), 'php -i');

        $this->container->instance(Stringable::class, $value);
        $event->before(function (Stringable $string) use (&$received): void {
            $received = $string;
        });

        $event->callBeforeCallbacks($this->container);

        $this->assertSame($value, $received);
    }

    public function testSuccessFailureAndOutputCallbacksCanReceiveEvent(): void
    {
        $successEvent = null;
        $failureEvent = null;
        $outputEvent = null;
        $outputValue = null;
        $event = new Event(m::mock(EventMutex::class), 'php -i');
        $event->sendOutputTo($output = 'test.log');

        $filesystem = m::mock(Filesystem::class);
        $filesystem->shouldReceive('isFile')->twice()->with($output)->andReturnTrue();
        $filesystem->shouldReceive('get')->twice()->with($output)->andReturn('captured output');
        $this->container->instance(Filesystem::class, $filesystem);

        $event->onSuccess(function (Event $scheduledEvent) use (&$successEvent): void {
            $successEvent = $scheduledEvent;
        });
        $event->onFailure(function (Event $scheduledEvent) use (&$failureEvent): void {
            $failureEvent = $scheduledEvent;
        });
        $event->thenWithOutput(function (Event $scheduledEvent, Stringable $output) use (&$outputEvent, &$outputValue): void {
            $outputEvent = $scheduledEvent;
            $outputValue = (string) $output;
        });

        $event->finish($this->container, 0);

        $this->assertSame($event, $successEvent);
        $this->assertNull($failureEvent);
        $this->assertSame($event, $outputEvent);
        $this->assertSame('captured output', $outputValue);

        $event->finish($this->container, 1);

        $this->assertSame($event, $failureEvent);
    }

    public function testTimezoneAcceptsStringBackedEnum(): void
    {
        $event = new Event(m::mock(EventMutex::class), 'php -i');

        $event->timezone(EventTestTimezoneStringEnum::NewYork);

        // String-backed enum value should be used
        $this->assertSame('America/New_York', $event->timezone);
    }

    public function testTimezoneAcceptsUnitEnum(): void
    {
        $event = new Event(m::mock(EventMutex::class), 'php -i');

        $event->timezone(EventTestTimezoneUnitEnum::UTC);

        // Unit enum name should be used
        $this->assertSame('UTC', $event->timezone);
    }

    public function testTimezoneAcceptsIntBackedEnum(): void
    {
        $event = new Event(m::mock(EventMutex::class), 'php -i');

        $event->timezone(EventTestTimezoneIntEnum::Zone1);

        $this->assertSame('1', $event->timezone);
    }

    public function testTimezoneAcceptsDateTimeZoneObject(): void
    {
        $event = new Event(m::mock(EventMutex::class), 'php -i');

        $tz = new DateTimeZone('UTC');
        $event->timezone($tz);

        // DateTimeZone object should be preserved
        $this->assertSame($tz, $event->timezone);
    }

    public function testBasicCronCompilation()
    {
        $app = m::mock(ApplicationContract::class);
        $app->shouldReceive('isDownForMaintenance')->andReturn(false);
        $app->shouldReceive('environment')->andReturn('production');
        $app->shouldReceive('call')->andReturnUsing(fn (callable $callback) => $callback());

        $event = new Event(m::mock(EventMutex::class), 'php foo');
        $this->assertSame('* * * * *', $event->getExpression());
        $this->assertTrue($event->isDue($app));
        $this->assertTrue($event->skip(function () {
            return true;
        })->isDue($app));
        $this->assertFalse($event->skip(function () {
            return true;
        })->filtersPass($app));

        $event = new Event(m::mock(EventMutex::class), 'php foo');
        $this->assertSame('* * * * *', $event->getExpression());
        $this->assertFalse($event->environments('local')->isDue($app));

        $event = new Event(m::mock(EventMutex::class), 'php foo');
        $this->assertSame('* * * * *', $event->getExpression());
        $this->assertFalse($event->when(function () {
            return false;
        })->filtersPass($app));

        $event = new Event(m::mock(EventMutex::class), 'php foo');
        $this->assertSame('* * * * *', $event->getExpression());
        $this->assertFalse($event->when(false)->filtersPass($app));

        // chained rules should be commutative
        $eventA = new Event(m::mock(EventMutex::class), 'php foo');
        $eventB = new Event(m::mock(EventMutex::class), 'php foo');
        $this->assertEquals(
            $eventA->daily()->hourly()->getExpression(),
            $eventB->hourly()->daily()->getExpression()
        );

        $eventA = new Event(m::mock(EventMutex::class), 'php foo');
        $eventB = new Event(m::mock(EventMutex::class), 'php foo');
        $this->assertEquals(
            $eventA->weekdays()->hourly()->getExpression(),
            $eventB->hourly()->weekdays()->getExpression()
        );
    }

    public function testEventIsDueCheck()
    {
        $app = m::mock(ApplicationContract::class);
        $app->shouldReceive('isDownForMaintenance')->andReturn(false);
        $app->shouldReceive('environment')->andReturn('production');
        Carbon::setTestNow(Carbon::create(2015, 1, 1, 0, 0, 0));

        $event = new Event(m::mock(EventMutex::class), 'php foo');
        $this->assertSame('* * * * 4', $event->thursdays()->getExpression());
        $this->assertTrue($event->isDue($app));

        $event = new Event(m::mock(EventMutex::class), 'php foo');
        $this->assertSame('0 19 * * 3', $event->wednesdays()->at('19:00')->timezone('EST')->getExpression());
        $this->assertTrue($event->isDue($app));
    }

    public function testEventIsDueAtUsesGivenTime()
    {
        $app = m::mock(ApplicationContract::class);
        $app->shouldReceive('isDownForMaintenance')->andReturn(false);
        $app->shouldReceive('environment')->andReturn('production');

        try {
            Carbon::setTestNow(Carbon::parse('2026-05-29 13:00:00'));

            $event = new Event(m::mock(EventMutex::class), 'php foo');
            $event->dailyAt('13:00');

            $this->assertFalse($event->isDueAt($app, Carbon::parse('2026-05-29 12:59:59')));
            $this->assertTrue($event->isDueAt($app, Carbon::parse('2026-05-29 13:00:00')));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testEventIsDueAtUsesEventTimezone()
    {
        $app = m::mock(ApplicationContract::class);
        $app->shouldReceive('isDownForMaintenance')->andReturn(false);
        $app->shouldReceive('environment')->andReturn('production');

        $event = new Event(m::mock(EventMutex::class), 'php foo');
        $event->dailyAt('09:00')->timezone('America/New_York');

        $this->assertTrue($event->isDueAt($app, Carbon::parse('2026-05-29 13:00:00', 'UTC')));
        $this->assertFalse($event->isDueAt($app, Carbon::parse('2026-05-29 12:59:59', 'UTC')));
    }

    public function testTimeBetweenChecks()
    {
        $app = m::mock(ApplicationContract::class);
        $app->shouldReceive('isDownForMaintenance')->andReturn(false);
        $app->shouldReceive('environment')->andReturn('production');
        $app->shouldReceive('call')->andReturnUsing(fn (callable $callback) => $callback());

        Carbon::setTestNow(Carbon::now()->startOfDay()->addHours(9));

        $event = new Event(m::mock(EventMutex::class), 'php foo', 'UTC');
        $this->assertTrue($event->between('8:00', '10:00')->filtersPass($app));

        $event = new Event(m::mock(EventMutex::class), 'php foo', 'UTC');
        $this->assertTrue($event->between('9:00', '9:00')->filtersPass($app));

        $event = new Event(m::mock(EventMutex::class), 'php foo', 'UTC');
        $this->assertTrue($event->between('23:00', '10:00')->filtersPass($app));

        $event = new Event(m::mock(EventMutex::class), 'php foo', 'UTC');
        $this->assertTrue($event->between('8:00', '6:00')->filtersPass($app));

        $event = new Event(m::mock(EventMutex::class), 'php foo', 'UTC');
        $this->assertFalse($event->between('10:00', '11:00')->filtersPass($app));

        $event = new Event(m::mock(EventMutex::class), 'php foo', 'UTC');
        $this->assertFalse($event->between('10:00', '8:00')->filtersPass($app));
    }

    public function testTimeBetweenIsEvaluatedUsingTheCurrentTime(): void
    {
        $app = m::mock(ApplicationContract::class);
        $app->shouldReceive('isDownForMaintenance')->andReturn(false);
        $app->shouldReceive('environment')->andReturn('production');
        $app->shouldReceive('call')->andReturnUsing(fn (callable $callback) => $callback());

        Carbon::setTestNow('2026-05-29 09:00:00');

        $event = new Event(m::mock(EventMutex::class), 'php foo', 'UTC');
        $event->between('8:00', '10:00');

        $this->assertTrue($event->filtersPass($app));

        Carbon::setTestNow('2026-05-29 11:00:00');

        $this->assertFalse($event->filtersPass($app));
    }

    public function testTimeBetweenUsesTimezoneConfiguredAfterTheConstraint(): void
    {
        $app = m::mock(ApplicationContract::class);
        $app->shouldReceive('isDownForMaintenance')->andReturn(false);
        $app->shouldReceive('environment')->andReturn('production');
        $app->shouldReceive('call')->andReturnUsing(fn (callable $callback) => $callback());

        Carbon::setTestNow('2026-05-29 13:00:00 UTC');

        $event = new Event(m::mock(EventMutex::class), 'php foo');
        $event->between('8:00', '10:00')->timezone('America/New_York');

        $this->assertTrue($event->filtersPass($app));
    }

    public function testTimeUnlessBetweenChecks()
    {
        $app = m::mock(ApplicationContract::class);
        $app->shouldReceive('isDownForMaintenance')->andReturn(false);
        $app->shouldReceive('environment')->andReturn('production');
        $app->shouldReceive('call')->andReturnUsing(fn (callable $callback) => $callback());

        Carbon::setTestNow(Carbon::now()->startOfDay()->addHours(9));

        $event = new Event(m::mock(EventMutex::class), 'php foo', 'UTC');
        $this->assertFalse($event->unlessBetween('8:00', '10:00')->filtersPass($app));

        $event = new Event(m::mock(EventMutex::class), 'php foo', 'UTC');
        $this->assertFalse($event->unlessBetween('9:00', '9:00')->filtersPass($app));

        $event = new Event(m::mock(EventMutex::class), 'php foo', 'UTC');
        $this->assertFalse($event->unlessBetween('23:00', '10:00')->filtersPass($app));

        $event = new Event(m::mock(EventMutex::class), 'php foo', 'UTC');
        $this->assertFalse($event->unlessBetween('8:00', '6:00')->filtersPass($app));

        $event = new Event(m::mock(EventMutex::class), 'php foo', 'UTC');
        $this->assertTrue($event->unlessBetween('10:00', '11:00')->filtersPass($app));

        $event = new Event(m::mock(EventMutex::class), 'php foo', 'UTC');
        $this->assertTrue($event->unlessBetween('10:00', '8:00')->filtersPass($app));
    }
}

class EventTestInvokableFilter
{
    public int $calls = 0;

    public function __construct(protected bool $result)
    {
    }

    public function __invoke(): bool
    {
        ++$this->calls;

        return $this->result;
    }
}

class EventTestExecutableEvent extends Event
{
    public function __construct(EventMutex $mutex)
    {
        parent::__construct($mutex, 'test:command');
    }

    protected function execute(ContainerContract $container): int
    {
        return 0;
    }

    public function getOutput(ContainerContract $container): ?string
    {
        return 'output';
    }
}

class EventTestFailingExitCodeEvent extends EventTestExecutableEvent
{
    public function acquireMutexForTest(): void
    {
        $this->mutexAcquired = true;
    }

    protected function setExitCode(int $exitCode): void
    {
        throw new RuntimeException('exit publication failed');
    }
}

class EventTestProcessEvent extends Event
{
    public function __construct(
        EventMutex $mutex,
        protected Process $process,
        protected ?Throwable $executionException = null
    ) {
        parent::__construct($mutex, 'test:process', isSystem: true);
    }

    public function hasRetainedProcess(): bool
    {
        return CoroutineContext::has($this->processContextKey());
    }

    protected function execute(ContainerContract $container): int
    {
        CoroutineContext::set($this->processContextKey(), $this->process);

        if ($this->executionException !== null) {
            throw $this->executionException;
        }

        return 0;
    }
}
