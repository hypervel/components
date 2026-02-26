<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Scheduling;

use Hypervel\Console\Command;
use Hypervel\Console\Scheduling\CacheAware;
use Hypervel\Console\Scheduling\CacheEventMutex;
use Hypervel\Console\Scheduling\CacheSchedulingMutex;
use Hypervel\Console\Scheduling\EventMutex;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Console\Scheduling\SchedulingMutex;
use Hypervel\Container\Container;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Tests\Foundation\Concerns\HasMockedApplication;
use Mockery as m;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeError;

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

/**
 * @internal
 * @coversNothing
 */
class ScheduleTest extends TestCase
{
    use HasMockedApplication;

    protected Container $container;

    protected EventMutex&MockInterface $eventMutex;

    protected MockInterface&SchedulingMutex $schedulingMutex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = $this->getApplication();
        Container::setInstance($this->container);
        $this->eventMutex = m::mock(EventMutex::class);
        $this->container->instance(EventMutex::class, $this->eventMutex);
        $this->schedulingMutex = m::mock(SchedulingMutex::class);
        $this->container->instance(SchedulingMutex::class, $this->schedulingMutex);
    }

    #[DataProvider('jobHonoursDisplayNameIfMethodExistsProvider')]
    public function testJobHonoursDisplayNameIfMethodExists(object $job, string $jobName): void
    {
        $schedule = new Schedule();
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
            [new JobToTestWithSchedule(), JobToTestWithSchedule::class],
            [$job, 'testJob-123'],
        ];
    }

    public function testJobIsNotInstantiatedIfSuppliedAsClassname(): void
    {
        $schedule = new Schedule();
        $scheduledJob = $schedule->job(JobToTestWithSchedule::class);
        self::assertSame(JobToTestWithSchedule::class, $scheduledJob->description);
        self::assertFalse($this->container->resolved(JobToTestWithSchedule::class));
    }

    public function testJobAcceptsStringBackedEnumForQueueAndConnection(): void
    {
        $schedule = new Schedule();

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
        $schedule = new Schedule();

        $scheduledJob = $schedule->job(
            JobToTestWithSchedule::class,
            ScheduleTestQueueUnitEnum::default,
            ScheduleTestQueueUnitEnum::emails
        );

        self::assertSame(JobToTestWithSchedule::class, $scheduledJob->description);
    }

    public function testJobWithIntBackedEnumStoresIntValue(): void
    {
        $schedule = new Schedule();

        // Int-backed enum values are stored as-is (no cast to string)
        // TypeError will occur when the job is dispatched and dispatchToQueue() receives int
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

        $schedule = new Schedule();
        $schedule->useCache(ScheduleTestCacheStoreEnum::Redis);
    }

    public function testUseCacheWithIntBackedEnumThrowsTypeError(): void
    {
        $eventMutex = m::mock(EventMutex::class, CacheAware::class);
        $schedulingMutex = m::mock(SchedulingMutex::class, CacheAware::class);

        $this->container->instance(EventMutex::class, $eventMutex);
        $this->container->instance(SchedulingMutex::class, $schedulingMutex);

        $schedule = new Schedule();

        // TypeError is thrown when useStore() receives int instead of string
        $this->expectException(TypeError::class);
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

        $schedule = new Schedule();
        $schedule->useCache('test');
    }

    public function testExecCreatesNewCommand()
    {
        $escape = '\\' === DIRECTORY_SEPARATOR ? '"' : '\'';
        $escapeReal = '\\' === DIRECTORY_SEPARATOR ? '\"' : '"';

        $schedule = new Schedule();
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
        $schedule = new Schedule();
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
        $schedule = new Schedule();
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

        $schedule = new Schedule();
        $schedule->command($command, ['--force']);

        $events = $schedule->events();
        $this->assertSame('foo:bar --force', $events[0]->command);
    }

    public function testItUsesCommandDescriptionAsEventDescription()
    {
        $schedule = new Schedule();
        $event = $schedule->command(ScheduleTestCommandStub::class);
        $this->assertSame('This is a description about the command', $event->description);
    }

    public function testItShouldBePossibleToOverwriteTheDescription()
    {
        $schedule = new Schedule();
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
