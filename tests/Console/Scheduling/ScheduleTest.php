<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Scheduling;

use DateTimeImmutable;
use Hypervel\Console\Command;
use Hypervel\Console\Scheduling\CacheAware;
use Hypervel\Console\Scheduling\CacheEventMutex;
use Hypervel\Console\Scheduling\CacheSchedulingMutex;
use Hypervel\Console\Scheduling\Event;
use Hypervel\Console\Scheduling\EventMutex;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Console\Scheduling\SchedulingMutex;
use Hypervel\Container\Container;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Application;
use Hypervel\Support\Carbon;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;

enum ScheduleTestQueueStringEnum: string
{
    case High = 'high-priority';
    case Low = 'low-priority';
}

enum ScheduleTestQueueIntEnum: int
{
    case Priority1 = 1;
    case Priority2 = 2;
}

enum ScheduleTestQueueUnitEnum
{
    case default;
    case emails;
}

enum ScheduleTestCacheStoreEnum: string
{
    case Redis = 'redis';
    case File = 'file';
}

enum ScheduleTestCacheStoreIntEnum: int
{
    case Store1 = 1;
    case Store2 = 2;
}

class ScheduleTest extends TestCase
{
    protected Container $container;

    protected EventMutex&MockInterface $eventMutex;

    protected MockInterface&SchedulingMutex $schedulingMutex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Application;
        Container::setInstance($this->container);
        $this->eventMutex = m::mock(EventMutex::class);
        $this->container->instance(EventMutex::class, $this->eventMutex);
        $this->schedulingMutex = m::mock(SchedulingMutex::class);
        $this->container->instance(SchedulingMutex::class, $this->schedulingMutex);
    }

    #[DataProvider('jobHonoursDisplayNameIfMethodExistsProvider')]
    public function testJobHonoursDisplayNameIfMethodExists(object $job, string $jobName): void
    {
        $schedule = new Schedule;
        $scheduledJob = $schedule->job($job);
        self::assertSame($jobName, $scheduledJob->description);
        self::assertFalse($this->container->resolved(JobToTestWithSchedule::class));
    }

    public static function jobHonoursDisplayNameIfMethodExistsProvider(): array
    {
        $job = new class implements ShouldQueue {
            public function displayName(): string
            {
                return 'testJob-123';
            }
        };

        return [
            [new JobToTestWithSchedule, JobToTestWithSchedule::class],
            [$job, 'testJob-123'],
        ];
    }

    public function testJobIsNotInstantiatedIfSuppliedAsClassname(): void
    {
        $schedule = new Schedule;
        $scheduledJob = $schedule->job(JobToTestWithSchedule::class);
        self::assertSame(JobToTestWithSchedule::class, $scheduledJob->description);
        self::assertFalse($this->container->resolved(JobToTestWithSchedule::class));
    }

    public function testItCanFilterEventsByEnvironments(): void
    {
        $schedule = new Schedule;
        $schedule->job(JobToTestWithSchedule::class)->environments('production')->daily();
        $schedule->command('inspire')->environments(['staging', 'production'])->everyMinute();
        $schedule->command('foobar', ['a' => 'b'])->environments(['local', 'uat'])->everyMinute();
        $schedule->command('foobar')->hourly();

        $filteredEvents = $schedule->eventsForEnvironments(['production', 'staging']);

        self::assertCount(3, $filteredEvents);

        self::assertSame(JobToTestWithSchedule::class, $filteredEvents[0]->description);
        self::assertSame(['production'], $filteredEvents[0]->environments);
        self::assertSame('0 0 * * *', $filteredEvents[0]->expression);

        self::assertSame('inspire', $filteredEvents[1]->command);
        self::assertSame(['staging', 'production'], $filteredEvents[1]->environments);
        self::assertSame('* * * * *', $filteredEvents[1]->expression);

        self::assertMatchesRegularExpression('/^foobar\b/', $filteredEvents[2]->command);
        self::assertSame([], $filteredEvents[2]->environments);
        self::assertSame('0 * * * *', $filteredEvents[2]->expression);
    }

    public function testDueEventsAtUsesGivenTime()
    {
        $app = m::mock(ApplicationContract::class);
        $app->shouldReceive('isDownForMaintenance')->andReturn(false);
        $app->shouldReceive('environment')->andReturn('production');

        try {
            Carbon::setTestNow(Carbon::parse('2026-05-29 13:00:00'));

            $schedule = new Schedule;
            $schedule->command('reports:generate')->dailyAt('13:00');

            self::assertCount(0, $schedule->dueEventsAt($app, Carbon::parse('2026-05-29 12:59:59')));
            self::assertCount(1, $schedule->dueEventsAt($app, Carbon::parse('2026-05-29 13:00:00')));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testJobAcceptsStringBackedEnumForQueueAndConnection(): void
    {
        $schedule = new Schedule;

        // Should not throw - enums are accepted
        $scheduledJob = $schedule->job(
            JobToTestWithSchedule::class,
            ScheduleTestQueueStringEnum::High,
            ScheduleTestQueueStringEnum::Low
        );

        self::assertSame(JobToTestWithSchedule::class, $scheduledJob->description);
    }

    public function testJobAcceptsUnitEnumForQueueAndConnection(): void
    {
        $schedule = new Schedule;

        $scheduledJob = $schedule->job(
            JobToTestWithSchedule::class,
            ScheduleTestQueueUnitEnum::default,
            ScheduleTestQueueUnitEnum::emails
        );

        self::assertSame(JobToTestWithSchedule::class, $scheduledJob->description);
    }

    public function testJobAcceptsIntegerBackedEnumForQueueAndConnection(): void
    {
        $schedule = new Schedule;

        $scheduledJob = $schedule->job(
            JobToTestWithSchedule::class,
            ScheduleTestQueueIntEnum::Priority1,
            ScheduleTestQueueIntEnum::Priority2
        );

        self::assertSame(JobToTestWithSchedule::class, $scheduledJob->description);
    }

    public function testUseCacheAcceptsStringBackedEnum(): void
    {
        $eventMutex = m::mock(EventMutex::class, CacheAware::class);
        $eventMutex->shouldReceive('useStore')->once()->with('redis');

        $schedulingMutex = m::mock(SchedulingMutex::class, CacheAware::class);
        $schedulingMutex->shouldReceive('useStore')->once()->with('redis');

        $this->container->instance(EventMutex::class, $eventMutex);
        $this->container->instance(SchedulingMutex::class, $schedulingMutex);

        $schedule = new Schedule;
        $schedule->useCache(ScheduleTestCacheStoreEnum::Redis);
    }

    public function testUseCacheAcceptsIntegerBackedEnum(): void
    {
        $eventMutex = m::mock(EventMutex::class, CacheAware::class);
        $eventMutex->shouldReceive('useStore')->once()->with('1');

        $schedulingMutex = m::mock(SchedulingMutex::class, CacheAware::class);
        $schedulingMutex->shouldReceive('useStore')->once()->with('1');

        $this->container->instance(EventMutex::class, $eventMutex);
        $this->container->instance(SchedulingMutex::class, $schedulingMutex);

        $schedule = new Schedule;
        $schedule->useCache(ScheduleTestCacheStoreIntEnum::Store1);
    }

    public function testMutexCanReceiveCustomStore()
    {
        $eventMutex = m::mock(CacheEventMutex::class);
        $eventMutex->shouldReceive('useStore')->once()->with('test');

        $schedulingMutex = m::mock(CacheSchedulingMutex::class);
        $schedulingMutex->shouldReceive('useStore')->once()->with('test');

        $this->container->instance(EventMutex::class, $eventMutex);
        $this->container->instance(SchedulingMutex::class, $schedulingMutex);

        $schedule = new Schedule;
        $schedule->useCache('test');
    }

    public function testServerShouldRunCachesMutexResultOnlyWithinSameMinute()
    {
        $schedule = new Schedule;
        $event = new Event($this->eventMutex, 'php artisan inspire');
        $firstMinute = new DateTimeImmutable('2024-01-01 12:30:00');
        $sameMinute = new DateTimeImmutable('2024-01-01 12:30:45');
        $nextMinute = new DateTimeImmutable('2024-01-01 12:31:00');
        $sameNextMinute = new DateTimeImmutable('2024-01-01 12:31:30');

        $this->schedulingMutex
            ->shouldReceive('create')
            ->once()
            ->with($event, $firstMinute)
            ->andReturnTrue();

        $this->schedulingMutex
            ->shouldReceive('create')
            ->once()
            ->with($event, $nextMinute)
            ->andReturnFalse();

        $this->assertTrue($schedule->serverShouldRun($event, $firstMinute));
        $this->assertTrue($schedule->serverShouldRun($event, $sameMinute));
        $this->assertFalse($schedule->serverShouldRun($event, $nextMinute));
        $this->assertFalse($schedule->serverShouldRun($event, $sameNextMinute));
    }

    public function testExecCreatesNewCommand()
    {
        $escape = '\\' === DIRECTORY_SEPARATOR ? '"' : '\'';
        $escapeReal = '\\' === DIRECTORY_SEPARATOR ? '\"' : '"';

        $schedule = new Schedule;
        $schedule->exec('path/to/command');
        $schedule->exec('path/to/command -f --foo="bar"');
        $schedule->exec('path/to/command', ['-f']);
        $schedule->exec('path/to/command', ['--foo' => 'bar']);
        $schedule->exec('path/to/command', ['-f', '--foo' => 'bar']);
        $schedule->exec('path/to/command', ['--title' => 'A "real" test']);
        $schedule->exec('path/to/command', [['one', 'two']]);
        $schedule->exec('path/to/command', ['-1 minute']);
        $schedule->exec('path/to/command', ['foo' => ['bar', 'baz']]);
        $schedule->exec('path/to/command', ['--foo' => ['bar', 'baz']]);
        $schedule->exec('path/to/command', ['-F' => ['bar', 'baz']]);

        $events = $schedule->events();
        $this->assertSame('path/to/command', $events[0]->command);
        $this->assertSame('path/to/command -f --foo="bar"', $events[1]->command);
        $this->assertSame('path/to/command -f', $events[2]->command);
        $this->assertSame("path/to/command --foo={$escape}bar{$escape}", $events[3]->command);
        $this->assertSame("path/to/command -f --foo={$escape}bar{$escape}", $events[4]->command);
        $this->assertSame("path/to/command --title={$escape}A {$escapeReal}real{$escapeReal} test{$escape}", $events[5]->command);
        $this->assertSame("path/to/command {$escape}one{$escape} {$escape}two{$escape}", $events[6]->command);
        $this->assertSame("path/to/command {$escape}-1 minute{$escape}", $events[7]->command);
        $this->assertSame("path/to/command {$escape}bar{$escape} {$escape}baz{$escape}", $events[8]->command);
        $this->assertSame("path/to/command --foo={$escape}bar{$escape} --foo={$escape}baz{$escape}", $events[9]->command);
        $this->assertSame("path/to/command -F {$escape}bar{$escape} -F {$escape}baz{$escape}", $events[10]->command);
    }

    public function testExecCreatesNewCommandWithTimezone()
    {
        $schedule = new Schedule('UTC');
        $schedule->exec('path/to/command');
        $events = $schedule->events();
        $this->assertSame('UTC', $events[0]->timezone);

        $schedule = new Schedule('Asia/Tokyo');
        $schedule->exec('path/to/command');
        $events = $schedule->events();
        $this->assertSame('Asia/Tokyo', $events[0]->timezone);
    }

    public function testCommandCreatesNewArtisanCommand()
    {
        // Hypervel runs commands in-process via the Kernel (no shell spawning),
        // so command names are stored without the php/artisan binary prefix.
        $schedule = new Schedule;
        $schedule->command('queue:listen');
        $schedule->command('queue:listen --tries=3');
        $schedule->command('queue:listen', ['--tries' => 3]);

        $events = $schedule->events();
        $this->assertSame('queue:listen', $events[0]->command);
        $this->assertSame('queue:listen --tries=3', $events[1]->command);
        $this->assertSame('queue:listen --tries=3', $events[2]->command);
    }

    public function testCreateNewArtisanCommandUsingCommandClass()
    {
        $schedule = new Schedule;
        $schedule->command(ScheduleTestCommandStub::class, ['--force']);

        $events = $schedule->events();
        $this->assertSame('foo:bar --force', $events[0]->command);
    }

    public function testCreateNewArtisanCommandUsingCommandClassObject()
    {
        $command = new class extends Command {
            protected ?string $signature = 'foo:bar';

            public function handle(): void
            {
            }
        };

        $schedule = new Schedule;
        $schedule->command($command, ['--force']);

        $events = $schedule->events();
        $this->assertSame('foo:bar --force', $events[0]->command);
    }

    public function testItUsesCommandDescriptionAsEventDescription()
    {
        $schedule = new Schedule;
        $event = $schedule->command(ScheduleTestCommandStub::class);
        $this->assertSame('This is a description about the command', $event->description);
    }

    public function testItShouldBePossibleToOverwriteTheDescription()
    {
        $schedule = new Schedule;
        $event = $schedule->command(ScheduleTestCommandStub::class)
            ->description('This is an alternative description');
        $this->assertSame('This is an alternative description', $event->description);
    }

    public function testCallCreatesNewJobWithTimezone()
    {
        $schedule = new Schedule('UTC');
        $schedule->call('path/to/command');
        $events = $schedule->events();
        $this->assertSame('UTC', $events[0]->timezone);

        $schedule = new Schedule('Asia/Tokyo');
        $schedule->call('path/to/command');
        $events = $schedule->events();
        $this->assertSame('Asia/Tokyo', $events[0]->timezone);
    }

    public function testJobSetsNameBeforeGroupAttributesAreMerged()
    {
        $schedule = new Schedule;
        $schedule->withoutOverlapping()->group(function ($schedule) {
            $schedule->job(JobToTestWithSchedule::class);
        });

        $events = $schedule->events();
        $this->assertSame(JobToTestWithSchedule::class, $events[0]->description);
        $this->assertTrue($events[0]->withoutOverlapping);
    }

    public function testItCanAddAttributesToEvents(): void
    {
        $schedule = new Schedule;

        $event = $schedule->command('inspire')
            ->withAttributes(['team' => 'platform'])
            ->withAttributes(['labels' => ['maintenance']]);

        $this->assertSame([
            'team' => 'platform',
            'labels' => ['maintenance'],
        ], $event->attributes);
    }

    public function testItCanAddAttributesToPendingEvents(): void
    {
        $schedule = new Schedule;

        $schedule->withAttributes(['team' => 'platform'])->command('inspire');
        $schedule->command('queue:work');

        $events = $schedule->events();

        $this->assertSame(['team' => 'platform'], $events[0]->attributes);
        $this->assertSame([], $events[1]->attributes);
    }

    public function testWithoutInterruptionPollingDisablesPauseAndInterruptChecks(): void
    {
        Schedule::withoutInterruptionPolling();

        $this->assertFalse(Schedule::$pausable);
        $this->assertFalse(Schedule::$interruptible);
    }

    public function testFlushStateResetsPauseAndInterruptChecks(): void
    {
        Schedule::withoutInterruptionPolling();

        Schedule::flushState();

        $this->assertTrue(Schedule::$pausable);
        $this->assertTrue(Schedule::$interruptible);
    }
}

class ScheduleTestCommandStub extends Command
{
    protected ?string $signature = 'foo:bar';

    protected string $description = 'This is a description about the command';

    public function handle(): void
    {
    }
}

class JobToTestWithSchedule implements ShouldQueue
{
}
