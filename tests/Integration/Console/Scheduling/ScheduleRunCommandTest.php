<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console\Scheduling;

use Hypervel\Console\Commands\ScheduleRunCommand;
use Hypervel\Console\Events\ScheduledTaskFailed;
use Hypervel\Console\Events\ScheduledTaskFinished;
use Hypervel\Console\Events\ScheduledTaskStarting;
use Hypervel\Console\Scheduling\Event as ScheduledEvent;
use Hypervel\Console\Scheduling\EventMutex;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Contracts\Container\BindingResolutionException;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Support\Carbon;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Console\Fixtures\FakeEventMutex;
use ReflectionMethod;
use ReflectionProperty;

class ScheduleRunCommandTest extends TestCase
{
    /**
     * @throws BindingResolutionException
     */
    public function testFailingCommandInForegroundTriggersEvent(): void
    {
        Event::fake([
            ScheduledTaskStarting::class,
            ScheduledTaskFinished::class,
            ScheduledTaskFailed::class,
        ]);

        // Create a schedule and add the command
        $schedule = $this->app->make(Schedule::class);
        $task = $schedule->exec('exit 1')
            ->everyMinute();

        // Allow the task through its filters.
        $task->when(function (): bool {
            return true;
        });

        // Execute the scheduler
        $this->runSchedule($task);

        // Verify the event sequence
        Event::assertDispatched(ScheduledTaskStarting::class);
        Event::assertDispatched(ScheduledTaskFinished::class);
        Event::assertDispatched(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event) use ($task): bool {
            return $event->task === $task
                   && $event->exception->getMessage() === 'Scheduled command [exit 1] failed with exit code [1].';
        });
    }

    /**
     * @throws BindingResolutionException
     */
    public function testFailingCommandInBackgroundTriggersEvent(): void
    {
        Event::fake([
            ScheduledTaskStarting::class,
            ScheduledTaskFinished::class,
            ScheduledTaskFailed::class,
        ]);

        // Create a schedule and add the command
        $schedule = $this->app->make(Schedule::class);
        $task = $schedule->exec('exit 1')
            ->everyMinute()
            ->runInBackground();

        // Allow the task through its filters.
        $task->when(function (): bool {
            return true;
        });

        // Execute the scheduler
        $this->runSchedule($task);

        // Verify the event sequence
        Event::assertDispatched(ScheduledTaskStarting::class);
        Event::assertDispatched(ScheduledTaskFinished::class);
        // Background coroutines remain observable, including the process exit status.
        Event::assertDispatched(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event) use ($task): bool {
            return $event->task === $task
                   && $event->exception->getMessage() === 'Scheduled command [exit 1] failed with exit code [1].';
        });
    }

    /**
     * @throws BindingResolutionException
     */
    public function testSuccessfulCommandDoesNotTriggerEvent(): void
    {
        Event::fake([
            ScheduledTaskStarting::class,
            ScheduledTaskFinished::class,
            ScheduledTaskFailed::class,
        ]);

        // Create a schedule and add the command
        $schedule = $this->app->make(Schedule::class);
        $task = $schedule->exec('exit 0')
            ->everyMinute();

        // Allow the task through its filters.
        $task->when(function (): bool {
            return true;
        });

        // Execute the scheduler
        $this->runSchedule($task);

        // Verify the event sequence
        Event::assertDispatched(ScheduledTaskStarting::class);
        Event::assertDispatched(ScheduledTaskFinished::class);
        Event::assertNotDispatched(ScheduledTaskFailed::class);
    }

    /**
     * @throws BindingResolutionException
     */
    public function testCommandWithNoExplicitReturnDoesNotTriggerEvent(): void
    {
        Event::fake([
            ScheduledTaskStarting::class,
            ScheduledTaskFinished::class,
            ScheduledTaskFailed::class,
        ]);

        // Create a schedule and add the command that just performs an action without explicit exit
        $schedule = $this->app->make(Schedule::class);
        $command = PHP_OS_FAMILY === 'Windows' ? 'cmd /c exit 0' : 'true';
        $task = $schedule->exec($command)
            ->everyMinute();

        // Allow the task through its filters.
        $task->when(function (): bool {
            return true;
        });

        // Execute the scheduler
        $this->runSchedule($task);

        // Verify the event sequence
        Event::assertDispatched(ScheduledTaskStarting::class);
        Event::assertDispatched(ScheduledTaskFinished::class);
        Event::assertNotDispatched(ScheduledTaskFailed::class);
    }

    /**
     * @throws BindingResolutionException
     */
    public function testSuccessfulCommandInBackgroundDoesNotTriggerEvent(): void
    {
        Event::fake([
            ScheduledTaskStarting::class,
            ScheduledTaskFinished::class,
            ScheduledTaskFailed::class,
        ]);

        // Create a schedule and add the command
        $schedule = $this->app->make(Schedule::class);
        $task = $schedule->exec('exit 0')
            ->everyMinute()
            ->runInBackground();

        // Allow the task through its filters.
        $task->when(function (): bool {
            return true;
        });

        // Execute the scheduler
        $this->runSchedule($task);

        // Verify the event sequence
        Event::assertDispatched(ScheduledTaskStarting::class);
        Event::assertDispatched(ScheduledTaskFinished::class);
        Event::assertNotDispatched(ScheduledTaskFailed::class);
    }

    public function testOverlappingTaskFinishedEventIndicatesSkipped(): void
    {
        Event::fake([
            ScheduledTaskStarting::class,
            ScheduledTaskFinished::class,
            ScheduledTaskFailed::class,
        ]);

        $this->app->instance(EventMutex::class, new FakeEventMutex);

        $ran = false;
        $schedule = $this->app->make(Schedule::class);
        $task = $schedule->call(function () use (&$ran): void {
            $ran = true;
        })->name('test')->withoutOverlapping()->everyMinute();

        $this->runSchedule($task);

        Event::assertDispatched(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event) use ($task): bool {
            return $event->task === $task;
        });
        Event::assertDispatched(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event) use ($task): bool {
            return $event->task === $task
                   && $event->task->skippedBecauseOverlapping === true;
        });
        Event::assertNotDispatched(ScheduledTaskFailed::class);
        $this->assertFalse($ran);
    }

    /**
     * @throws BindingResolutionException
     */
    public function testCommandWithNoExplicitReturnInBackgroundDoesNotTriggerEvent(): void
    {
        Event::fake([
            ScheduledTaskStarting::class,
            ScheduledTaskFinished::class,
            ScheduledTaskFailed::class,
        ]);

        // Create a schedule and add the command that just performs an action without explicit exit
        $schedule = $this->app->make(Schedule::class);
        $command = PHP_OS_FAMILY === 'Windows' ? 'cmd /c exit 0' : 'true';
        $task = $schedule->exec($command)
            ->everyMinute()
            ->runInBackground();

        // Allow the task through its filters.
        $task->when(function (): bool {
            return true;
        });

        // Execute the scheduler
        $this->runSchedule($task);

        // Verify the event sequence
        Event::assertDispatched(ScheduledTaskStarting::class);
        Event::assertDispatched(ScheduledTaskFinished::class);
        Event::assertNotDispatched(ScheduledTaskFailed::class);
    }

    public function testRepeatEventsDoesNotMutateStartedAt(): void
    {
        CarbonImmutable::setTestNow('2026-03-25 12:00:30');

        $command = new ScheduleRunCommand;
        $this->app->instance(ScheduleRunCommand::class, $command);

        $reflection = new ReflectionProperty($command, 'startedAt');
        // runOnce() uses Date::now(); a mutable date exercises the copy() guard.
        $reflection->setValue($command, Carbon::now());
        $startedAt = $reflection->getValue($command);

        $originalTimestamp = $startedAt->getTimestamp();
        $originalMicro = $startedAt->micro;

        // Call repeatEvents with an empty collection so it exits immediately
        $reflection = new ReflectionMethod($command, 'repeatEvents');
        $command->setHypervel($this->app);

        // Set test time past the minute boundary so the while loop exits immediately
        CarbonImmutable::setTestNow('2026-03-25 12:01:01');
        $reflection->invoke($command, collect());

        // startedAt should not have been mutated to end of minute
        $startedAtAfter = (new ReflectionProperty($command, 'startedAt'))->getValue($command);
        $this->assertEquals($originalTimestamp, $startedAtAfter->getTimestamp());
        $this->assertEquals($originalMicro, $startedAtAfter->micro);
    }

    /**
     * Run one scheduler iteration and join its task coroutine before returning.
     */
    private function runSchedule(ScheduledEvent $task): void
    {
        $coroutineId = null;
        $task->before(function () use (&$coroutineId): void {
            $coroutineId = Coroutine::id();
        });

        try {
            $this->artisan('schedule:run', ['--once' => true])->assertSuccessful();
        } finally {
            if ($coroutineId !== null) {
                Coroutine::join([$coroutineId], 5);
                $this->assertFalse(Coroutine::exists($coroutineId), 'The scheduled task did not finish within five seconds.');
            }
        }
    }
}
