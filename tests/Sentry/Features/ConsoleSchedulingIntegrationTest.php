<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Features;

use DateTimeZone;
use Hypervel\Bus\Queueable;
use Hypervel\Console\Events\ScheduledTaskStarting;
use Hypervel\Console\Scheduling\Event;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Sentry\Features\ConsoleSchedulingIntegration;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Support\Facades\Context;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Tests\Sentry\TestCase;
use RuntimeException;
use Sentry\CheckIn;
use Sentry\CheckInStatus;
use Sentry\Event as SentryEvent;
use Sentry\EventType;
use Sentry\MonitorSchedule;
use Sentry\Tracing\SpanStatus;

use function Hypervel\Coroutine\parallel;

class ConsoleSchedulingIntegrationTest extends TestCase
{
    protected array $defaultSetupConfig = [
        'sentry.traces_sample_rate' => 1.0,
        'sentry.features' => [
            ConsoleSchedulingIntegration::class,
        ],
    ];

    public function testScheduleMacro(): void
    {
        /** @var Event $scheduledEvent */
        $scheduledEvent = $this->getScheduler()
            ->call(function (): void {
            })
            ->sentryMonitor('test-monitor');

        $scheduledEvent->run($this->app);

        // We expect a total of 2 events to be sent to Sentry:
        // 1. The start check-in event
        // 2. The finish check-in event
        $this->assertSentryCheckInCount(2);

        $finishCheckInEvent = $this->getLastSentryEvent();

        $this->assertNotNull($finishCheckInEvent->getCheckIn());
        $this->assertEquals('test-monitor', $finishCheckInEvent->getCheckIn()->getMonitorSlug());
    }

    /**
     * When a timezone was defined on a command this would fail with:
     * Sentry\MonitorConfig::__construct(): Argument #4 ($timezone) must be of type ?string, DateTimeZone given
     * This test ensures that the timezone is properly converted to a string as expected.
     */
    public function testScheduleMacroWithTimeZone(): void
    {
        $expectedTimezone = 'UTC';

        /** @var Event $scheduledEvent */
        $scheduledEvent = $this->getScheduler()
            ->call(function (): void {
            })
            ->timezone(new DateTimeZone($expectedTimezone))
            ->sentryMonitor('test-timezone-monitor');

        $scheduledEvent->run($this->app);

        // We expect a total of 2 events to be sent to Sentry:
        // 1. The start check-in event
        // 2. The finish check-in event
        $this->assertSentryCheckInCount(2);

        $finishCheckInEvent = $this->getLastSentryEvent();

        $this->assertNotNull($finishCheckInEvent->getCheckIn());
        $this->assertEquals($expectedTimezone, $finishCheckInEvent->getCheckIn()->getMonitorConfig()->getTimezone());
    }

    public function testScheduleMacroWithScheduleOverride(): void
    {
        $expectedSchedule = '*/5 9-17 * * *';

        /** @var Event $scheduledEvent */
        $scheduledEvent = $this->getScheduler()
            ->call(function (): void {
            })
            ->everyFiveMinutes()
            ->between('09:00', '17:59')
            ->sentryMonitor('test-monitor', null, null, true, null, null, $expectedSchedule);

        $this->assertEquals('*/5 * * * *', $scheduledEvent->getExpression());

        $scheduledEvent->run($this->app);

        // We expect a total of 2 events to be sent to Sentry:
        // 1. The start check-in event
        // 2. The finish check-in event
        $this->assertSentryCheckInCount(2);

        $finishCheckInEvent = $this->getLastSentryEvent();

        $this->assertNotNull($finishCheckInEvent->getCheckIn());
        $this->assertNotNull($finishCheckInEvent->getCheckIn()->getMonitorConfig());
        $this->assertEquals(MonitorSchedule::TYPE_CRONTAB, $finishCheckInEvent->getCheckIn()->getMonitorConfig()->getSchedule()->getType());
        $this->assertEquals($expectedSchedule, $finishCheckInEvent->getCheckIn()->getMonitorConfig()->getSchedule()->getValue());
    }

    public function testScheduleMacroAutomaticSlugForCommand(): void
    {
        $this->defineInspireCommand();

        /** @var Event $scheduledEvent */
        $scheduledEvent = $this->getScheduler()->command('inspire')->sentryMonitor();

        $scheduledEvent->run($this->app);

        // We expect a total of 2 events to be sent to Sentry:
        // 1. The start check-in event
        // 2. The finish check-in event
        $this->assertSentryCheckInCount(2);

        $finishCheckInEvent = $this->getLastSentryEvent();

        $this->assertNotNull($finishCheckInEvent->getCheckIn());
        // Artisan tasks run in-process, so the command string has no PHP or Artisan binary
        $this->assertEquals('scheduled_inspire', $finishCheckInEvent->getCheckIn()->getMonitorSlug());
    }

    public function testScheduleMacroAutomaticSlugForJob(): void
    {
        /** @var Event $scheduledEvent */
        $scheduledEvent = $this->getScheduler()->job(ScheduledQueuedJob::class)->sentryMonitor();

        $scheduledEvent->run($this->app);

        // We expect a total of 2 events to be sent to Sentry:
        // 1. The start check-in event
        // 2. The finish check-in event
        $this->assertSentryCheckInCount(2);

        $finishCheckInEvent = $this->getLastSentryEvent();

        $this->assertNotNull($finishCheckInEvent->getCheckIn());
        // Scheduled is duplicated here because of the class name of the queued job, this is not a bug just unfortunate naming for the test class
        $this->assertEquals(
            'scheduled_scheduledqueuedjob-features-sentry-tests-hypervel',
            $finishCheckInEvent->getCheckIn()->getMonitorSlug()
        );
    }

    public function testScheduleMacroWithoutSlugCommandOrDescriptionOrName(): void
    {
        $this->expectException(RuntimeException::class);

        $this->getScheduler()->call(function (): void {
        })->sentryMonitor();
    }

    #[DefineEnvironment('envWithoutDsnSet')]
    public function testScheduleMacroWithoutDsnSet(): void
    {
        /** @var Event $scheduledEvent */
        $scheduledEvent = $this->getScheduler()->call(function (): void {
        })->sentryMonitor('test-monitor');

        $scheduledEvent->run($this->app);

        $this->assertSentryCheckInCount(0);
    }

    public function testScheduleMacroIsRegistered(): void
    {
        Event::flushMacros();

        $this->refreshApplication();

        $this->assertTrue(Event::hasMacro('sentryMonitor'));
    }

    public function testMonitorCheckInsRemainWithoutScheduledTracingListeners(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.traces_sample_rate' => null,
            'sentry.features' => [
                ConsoleSchedulingIntegration::class,
            ],
        ]);
        $listeners = $this->app->make('events')->getRawListeners()[ScheduledTaskStarting::class] ?? [];

        foreach ($listeners as $listener) {
            $this->assertFalse(
                is_array($listener) && ($listener[0] ?? null) instanceof ConsoleSchedulingIntegration,
            );
        }

        /** @var Event $scheduledEvent */
        $scheduledEvent = $this->getScheduler()
            ->call(static function (): void {
            })
            ->sentryMonitor('check-ins-without-tracing');

        $scheduledEvent->run($this->app);

        $this->assertSentryCheckInCount(2);
    }

    #[DefineEnvironment('envWithoutDsnSet')]
    public function testScheduleMacroIsRegisteredWithoutDsnSet(): void
    {
        Event::flushMacros();

        $this->refreshApplication();

        $this->assertTrue(Event::hasMacro('sentryMonitor'));
    }

    public function testScheduledClosureCreatesTransaction(): void
    {
        $this->getScheduler()->call(function (): void {
        })->everyMinute();

        $this->artisan('schedule:run --once');

        $this->assertSentryTransactionCount(1);

        $transaction = $this->getLastSentryEvent();

        $this->assertEquals('Closure', $transaction->getTransaction());
        $this->assertSame((string) SpanStatus::ok(), $transaction->getContexts()['trace']['status']);
    }

    public function testScheduledClosureWithNonZeroExitCreatesOneFailedTransaction(): void
    {
        $this->getScheduler()->call(static fn (): false => false)->everyMinute();

        $this->artisan('schedule:run --once');

        $this->assertSentryTransactionCount(1);

        $transaction = $this->getCapturedTransactions()[0];

        $this->assertSame(
            (string) SpanStatus::internalError(),
            $transaction->getContexts()['trace']['status'],
        );
    }

    public function testScheduledClosureThatThrowsBeforeFinishedCreatesOneFailedTransaction(): void
    {
        $this->getScheduler()->call(static function (): never {
            throw new RuntimeException('Scheduled closure failed.');
        })->everyMinute();

        $this->artisan('schedule:run --once');

        $this->assertSentryTransactionCount(1);

        $transaction = $this->getCapturedTransactions()[0];

        $this->assertSame(
            (string) SpanStatus::internalError(),
            $transaction->getContexts()['trace']['status'],
        );
    }

    public function testScheduledJobCreatesTransaction(): void
    {
        $this->getScheduler()->job(ScheduledQueuedJob::class)->everyMinute();

        $this->artisan('schedule:run --once');

        $this->assertSentryTransactionCount(1);

        $transaction = $this->getLastSentryEvent();

        $this->assertEquals(ScheduledQueuedJob::class, $transaction->getTransaction());
    }

    public function testBackgroundScheduledTaskUsesContextForCheckInId(): void
    {
        $this->defineInspireCommand();

        $this->getScheduler()
            ->command('inspire')
            ->everyMinute()
            ->runInBackground()
            ->sentryMonitor('test-background-monitor');

        // Background tasks run in a coroutine forked by the scheduler, which starts and finishes the check-in
        $this->artisan('schedule:run --once');

        $this->assertSentryCheckInCount(2);

        [$startCheckIn, $finishCheckIn] = $this->getCheckIns();

        $this->assertSame($startCheckIn->getId(), $finishCheckIn->getId());
        $this->assertEquals(CheckInStatus::ok(), $finishCheckIn->getStatus());
    }

    public function testBackgroundScheduledTaskOverlappingExecutionsHaveDistinctCheckInIds(): void
    {
        /** @var Event $scheduledEvent */
        $scheduledEvent = $this->getScheduler()
            ->call(static function (): void {
                usleep(5000);
            })
            ->sentryMonitor('test-overlapping-monitor');

        parallel([
            fn (): mixed => $scheduledEvent->run($this->app),
            fn (): mixed => $scheduledEvent->run($this->app),
        ]);

        $checkIns = $this->getCheckIns();

        $this->assertCount(4, $checkIns);
        // Both executions start before either finishes
        $this->assertEquals(CheckInStatus::inProgress(), $checkIns[0]->getStatus());
        $this->assertEquals(CheckInStatus::inProgress(), $checkIns[1]->getStatus());
        $this->assertNotSame($checkIns[0]->getId(), $checkIns[1]->getId());
        $this->assertEqualsCanonicalizing(
            [$checkIns[0]->getId(), $checkIns[1]->getId()],
            [$checkIns[2]->getId(), $checkIns[3]->getId()],
        );
    }

    public function testCheckInIsKeptOutOfTheApplicationContextAndRemovedOnCompletion(): void
    {
        $hiddenContext = null;

        /** @var Event $scheduledEvent */
        $scheduledEvent = $this->getScheduler()
            ->call(static function () use (&$hiddenContext): void {
                $hiddenContext = Context::allHidden();
            })
            ->sentryMonitor('test-context-monitor');

        $scheduledEvent->run($this->app);

        [$startCheckIn, $finishCheckIn] = $this->getCheckIns();

        $this->assertSame([], $hiddenContext);
        $this->assertSame($startCheckIn->getId(), $finishCheckIn->getId());
        $this->assertSame([], CoroutineContext::get(ConsoleSchedulingIntegration::CHECK_INS_CONTEXT_KEY, []));
    }

    /**
     * Get the captured Sentry transactions.
     *
     * @return list<SentryEvent>
     */
    protected function getCapturedTransactions(): array
    {
        return array_map(
            static fn (array $captured): SentryEvent => $captured[0],
            $this->getCapturedSentryEventsOfType(EventType::transaction()),
        );
    }

    /**
     * Get the captured Sentry check-ins.
     *
     * @return list<CheckIn>
     */
    private function getCheckIns(): array
    {
        return array_map(
            static fn (array $captured): CheckIn => $captured[0]->getCheckIn(),
            $this->getCapturedSentryEventsOfType(EventType::checkIn()),
        );
    }

    /**
     * Get the scheduler.
     */
    protected function getScheduler(): Schedule
    {
        return $this->app->make(Schedule::class);
    }

    /**
     * Define the `inspire` command that upstream's tests get from the Laravel application skeleton.
     */
    private function defineInspireCommand(): void
    {
        Artisan::command('inspire', function (): void {
        });
    }
}

class ScheduledQueuedJob implements ShouldQueue
{
    use Queueable;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
    }
}
