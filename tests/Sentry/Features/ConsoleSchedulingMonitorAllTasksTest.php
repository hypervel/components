<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use Hypervel\Console\Events\CommandStarting;
use Hypervel\Console\Scheduling\Event;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Sentry\Features\ConsoleSchedulingIntegration;
use Hypervel\Support\Facades\Date;
use Hypervel\Tests\Sentry\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Sentry\CheckIn;
use Swoole\Coroutine\CanceledException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Throwable;

class ConsoleSchedulingMonitorAllTasksTest extends TestCase
{
    protected array $defaultSetupConfig = [
        'sentry.features' => [
            ConsoleSchedulingIntegration::class,
        ],
    ];

    protected array $setupConfig = [
        'sentry.crons.monitor_scheduled_tasks' => true,
        'sentry.crons.exclude_scheduled_tasks' => ['excluded:*', 'scheduled_*-skipped'],
    ];

    public function testScheduledTasksAreMonitoredWhenTheSchedulerRuns(): void
    {
        // Off the hour, so only the every-minute task is due
        Date::setTestNow('2026-01-01 12:30:00');

        $this->getScheduler()->call(function (): void {
        })->name('send-report')->hourly();
        $this->getScheduler()->call(function (): void {
        })->name('every-minute')->everyMinute();

        // Hypervel only dispatches `CommandStarting` outside of unit tests
        $this->startCommand('schedule:run');
        $this->artisan('schedule:run', ['--once' => true]);

        $this->assertSentryCheckInCount(2);

        $checkIn = $this->getLastCheckIn();

        $this->assertEquals('scheduled_every-minute', $checkIn->getMonitorSlug());
        $this->assertEquals('* * * * *', $checkIn->getMonitorConfig()->getSchedule()->getValue());
    }

    public function testScheduledTasksAreNotMonitoredByOtherCommands(): void
    {
        $task = $this->getScheduler()->call(function (): void {
        })->name('send-report');

        $this->startCommand('schedule:list');
        $task->run($this->app);

        $this->assertSentryCheckInCount(0);
    }

    public function testManuallyMonitoredTaskIsNotMonitoredTwice(): void
    {
        $task = $this->getScheduler()->call(function (): void {
        })->name('send-report')->sentryMonitor('custom-slug');

        $this->startCommand('schedule:run');
        $this->startCommand('schedule:run');
        $task->run($this->app);

        $this->assertSentryCheckInCount(2);
        $this->assertEquals('custom-slug', $this->getLastCheckIn()->getMonitorSlug());
    }

    public function testUnnamedClosureIsNotMonitored(): void
    {
        $task = $this->getScheduler()->call(function (): void {
        });

        $this->startCommand('schedule:run');
        $task->run($this->app);

        $this->assertSentryCheckInCount(0);
    }

    public function testExcludedTasksAreNotMonitored(): void
    {
        $tasks = [
            $this->getScheduler()->call(function (): void {
            })->name('excluded:by-name'),
            $this->getScheduler()->call(function (): void {
            })->name('report-skipped'),
            $this->getScheduler()->call(function (): void {
            })->name('report-included'),
        ];

        $this->startCommand('schedule:run');

        foreach ($tasks as $task) {
            $task->run($this->app);
        }

        $this->assertSentryCheckInCount(2);
        $this->assertEquals('scheduled_report-included', $this->getLastCheckIn()->getMonitorSlug());
    }

    public function testTasksSharingASlugAreNotMonitored(): void
    {
        $logs = $this->captureSdkLogs();
        $hourly = $this->getScheduler()->call(function (): void {
        })->name('send-report')->hourly();
        $daily = $this->getScheduler()->call(function (): void {
        })->name('send-report')->daily();

        $this->startCommand('schedule:run');
        $hourly->run($this->app);
        $daily->run($this->app);

        $this->assertSentryCheckInCount(0);
        $this->assertTrue($logs->hasWarningThatContains('Not monitoring scheduled tasks with the slug "scheduled_send-report"'));
    }

    public function testTaskWithAnExplicitSlugIsMonitoredNextToATaskWithTheSameName(): void
    {
        $logs = $this->captureSdkLogs();
        $hourly = $this->getScheduler()->call(function (): void {
        })->name('send-report')->hourly()->sentryMonitor('send-report-hourly');
        $daily = $this->getScheduler()->call(function (): void {
        })->name('send-report')->daily();

        $this->startCommand('schedule:run');
        $hourly->run($this->app);
        $this->assertEquals('send-report-hourly', $this->getLastCheckIn()->getMonitorSlug());
        $daily->run($this->app);
        $this->assertEquals('scheduled_send-report', $this->getLastCheckIn()->getMonitorSlug());

        $this->assertFalse($logs->hasWarningRecords());
    }

    public function testTaskSharingASlugWithAManuallyMonitoredTaskIsNotMonitored(): void
    {
        $logs = $this->captureSdkLogs();
        $this->getScheduler()->call(function (): void {
        })->name('send-report')->daily()->sentryMonitor();
        $hourly = $this->getScheduler()->call(function (): void {
        })->name('other-report')->hourly();
        $this->getScheduler()->call(function (): void {
        })->name('send-report')->hourly()->sentryMonitor('scheduled_other-report');

        $this->startCommand('schedule:run');
        $hourly->run($this->app);

        $this->assertSentryCheckInCount(0);
        $this->assertTrue($logs->hasWarningThatContains('"scheduled_other-report"'));
    }

    public function testLongSlugIsHashed(): void
    {
        $task = $this->getScheduler()->call(function (): void {
        })->name(str_repeat('send-report-', 10))->hourly();

        $this->startCommand('schedule:run');
        $task->run($this->app);

        $slug = $this->getLastCheckIn()->getMonitorSlug();

        $this->assertSame(50, strlen($slug));
        $this->assertSame(1, preg_match('/_[0-9a-f]{8}$/', $slug));

        // The slug Sentry shows can be copied into the exclusions
        $this->resetApplicationWithConfig([
            ...$this->setupConfig,
            'sentry.crons.exclude_scheduled_tasks' => [$slug],
        ]);
        $task = $this->getScheduler()->call(function (): void {
        })->name(str_repeat('send-report-', 10))->hourly();

        $this->startCommand('schedule:run');
        $task->run($this->app);

        $this->assertSentryCheckInCount(0);
    }

    public function testLongUniqueSlugsStayDistinct(): void
    {
        $prefix = str_repeat('send-report-', 5);
        $first = $this->getScheduler()->call(function (): void {
        })->name($prefix . 'first')->hourly();
        $second = $this->getScheduler()->call(function (): void {
        })->name($prefix . 'second')->hourly();

        $this->startCommand('schedule:run');
        $first->run($this->app);
        $firstSlug = $this->getLastCheckIn()->getMonitorSlug();
        $second->run($this->app);
        $secondSlug = $this->getLastCheckIn()->getMonitorSlug();

        $this->assertSame(50, strlen($firstSlug));
        $this->assertSame(50, strlen($secondSlug));
        $this->assertNotEquals($firstSlug, $secondSlug);
    }

    #[DataProvider('monitorConfigDataProvider')]
    public function testMonitorConfig(callable $configure, ?string $expectedCrontab): void
    {
        $task = $configure($this->getScheduler()->call(function (): void {
        })->name('send-report')->timezone('UTC'));

        $this->startCommand('schedule:run');
        $task->run($this->app);

        if ($expectedCrontab === null) {
            $this->assertSentryCheckInCount(0);

            return;
        }

        $this->assertEquals('scheduled_send-report', $this->getLastCheckIn()->getMonitorSlug());
        $this->assertEquals($expectedCrontab, $this->getLastCheckIn()->getMonitorConfig()->getSchedule()->getValue());
    }

    /**
     * Provide task schedules with the crontab Sentry should expect, or null when the task is not monitored.
     */
    public static function monitorConfigDataProvider(): iterable
    {
        yield 'crontab' => [static fn (Event $task): Event => $task->weekdays()->at('9:30'), '30 9 * * 1-5'];
        yield 'without overlapping' => [static fn (Event $task): Event => $task->hourly()->withoutOverlapping(), '0 * * * *'];
        yield 'last day of month' => [static fn (Event $task): Event => $task->cron('0 0 L * *'), '0 0 L * *'];
        yield 'question mark' => [static fn (Event $task): Event => $task->cron('0 0 ? * MON'), '0 0 * * MON'];
        yield 'named timezone' => [static fn (Event $task): Event => $task->daily()->timezone('Europe/Vienna'), '0 0 * * *'];
        yield 'nearest weekday' => [static fn (Event $task): Event => $task->cron('0 0 15W * *'), null];
        yield 'day step and weekday' => [static fn (Event $task): Event => $task->cron('0 0 */2 * 1'), null];
        yield 'day and weekday' => [static fn (Event $task): Event => $task->cron('0 0 1 * 1'), null];
        yield 'step from a number' => [static fn (Event $task): Event => $task->cron('5/15 * * * *'), null];
        yield 'step over a range' => [static fn (Event $task): Event => $task->cron('0 9-17/2 * * *'), '0 9-17/2 * * *'];
        yield 'when' => [static fn (Event $task): Event => $task->daily()->when(fn (): bool => true), null];
        yield 'skip' => [static fn (Event $task): Event => $task->daily()->skip(fn (): bool => false), null];
        yield 'between' => [static fn (Event $task): Event => $task->hourly()->between('8:00', '17:00'), null];
        yield 'offset timezone' => [static fn (Event $task): Event => $task->daily()->timezone('+05:00'), null];
    }

    // Upstream doesn't monitor `lastDayOfMonth()`, because Laravel writes this month's last day number.
    // Hypervel writes "L", so the task is monitored with a crontab that holds for every month.
    public function testLastDayOfMonthIsMonitored(): void
    {
        Date::setTestNow('2026-02-10 12:00:00');

        $task = $this->getScheduler()->call(function (): void {
        })->name('send-report')->timezone('UTC')->lastDayOfMonth();

        $this->startCommand('schedule:run');
        $task->run($this->app);

        $this->assertEquals('0 0 L * *', $this->getLastCheckIn()->getMonitorConfig()->getSchedule()->getValue());
    }

    public function testTaskWithAnUnsupportedScheduleIsNotMonitored(): void
    {
        $logs = $this->captureSdkLogs();
        $task = $this->getScheduler()->call(function (): void {
        })->name('send-report')->timezone('UTC')->daily()->when(fn (): bool => true);

        $this->startCommand('schedule:run');
        $task->run($this->app);

        $this->assertSentryCheckInCount(0);
        $this->assertTrue($logs->hasDebugThatContains('Not monitoring the scheduled task "scheduled_send-report"'));
    }

    public function testManuallyMonitoredTaskWithAnUnsupportedScheduleChecksInWithoutAConfig(): void
    {
        $task = $this->getScheduler()->call(function (): void {
        })->name('send-report')->daily()->when(fn (): bool => true)->sentryMonitor('custom-slug', null, null, false);

        $this->startCommand('schedule:run');
        $task->run($this->app);

        $this->assertSentryCheckInCount(2);
        $this->assertNull($this->getLastCheckIn()->getMonitorConfig());
    }

    // February 28 is February's last day, so this also checks that an ordinary day number isn't treated as `lastDayOfMonth()`.
    public function testMonthlyOnHasMonitorConfig(): void
    {
        Date::setTestNow('2026-02-10 12:00:00');

        $task = $this->getScheduler()->call(function (): void {
        })->name('send-report')->timezone('UTC')->monthlyOn(28);

        $this->startCommand('schedule:run');
        $task->run($this->app);

        $this->assertEquals('0 0 28 * *', $this->getLastCheckIn()->getMonitorConfig()->getSchedule()->getValue());
    }

    public function testSubMinuteTaskIsNotMonitored(): void
    {
        $task = $this->getScheduler()->call(function (): void {
        })->name('send-report')->everyTenSeconds();

        $this->startCommand('schedule:run');
        $task->run($this->app);

        $this->assertSentryCheckInCount(0);
    }

    public function testScheduleSetupFailureIsLoggedWithoutStoppingTheCommand(): void
    {
        $logs = $this->captureSdkLogs();
        $this->app->singleton(Schedule::class, static fn (): never => throw new RuntimeException('Broken schedule.'));

        $this->startCommand('schedule:run');

        $this->assertTrue($logs->hasWarningThatContains('Failed to set up cron monitoring for scheduled tasks.'));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testScheduleSetupCancellationIsRethrown(bool $wrapped): void
    {
        $cancellation = $wrapped ? new RuntimeException('Cleanup failed.', previous: new CanceledException) : new CanceledException;
        $logs = $this->captureSdkLogs();
        $this->app->singleton(Schedule::class, static fn (): never => throw $cancellation);

        $caught = null;

        try {
            $this->startCommand('schedule:run');
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertSame($cancellation, $caught);
        $this->assertFalse($logs->hasWarningRecords());
    }

    public function testScheduledTasksAreNotMonitoredByDefault(): void
    {
        $this->resetApplicationWithConfig([]);

        $this->getScheduler()->call(function (): void {
        })->name('every-minute')->everyMinute();

        $this->startCommand('schedule:run');
        $this->artisan('schedule:run', ['--once' => true]);

        $this->assertSentryCheckInCount(0);
    }

    /**
     * Dispatch the event that starts the given command.
     */
    private function startCommand(string $command): void
    {
        $this->app->make('events')->dispatch(new CommandStarting($command, new ArrayInput([]), new NullOutput));
    }

    /**
     * Capture the Sentry SDK's log records.
     */
    private function captureSdkLogs(): TestHandler
    {
        $handler = new TestHandler;

        $this->getSentryClientFromContainer()->getOptions()->setLogger(new Logger('sentry', [$handler]));

        return $handler;
    }

    /**
     * Get the last check-in sent to Sentry.
     */
    private function getLastCheckIn(): CheckIn
    {
        $checkIn = $this->getLastSentryEvent()->getCheckIn();

        $this->assertNotNull($checkIn);

        return $checkIn;
    }

    /**
     * Get the scheduler.
     */
    private function getScheduler(): Schedule
    {
        return $this->app->make(Schedule::class);
    }
}
