<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Scheduling;

use Hypervel\Console\Contracts\CacheAware;
use Hypervel\Console\Contracts\EventMutex;
use Hypervel\Console\Contracts\SchedulingMutex;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Container\Container;
use Hypervel\Queue\Contracts\ShouldQueue;
use Hypervel\Tests\Foundation\Concerns\HasMockedApplication;
use Mockery as m;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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

    public function testJobAcceptsIntBackedEnumForQueueAndConnection(): void
    {
        $schedule = new Schedule();

        // Int-backed enums should be cast to string
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

    public function testUseCacheAcceptsIntBackedEnum(): void
    {
        $eventMutex = m::mock(EventMutex::class, CacheAware::class);
        // Int value 1 should be cast to string '1'
        $eventMutex->shouldReceive('useStore')->once()->with('1');

        $schedulingMutex = m::mock(SchedulingMutex::class, CacheAware::class);
        $schedulingMutex->shouldReceive('useStore')->once()->with('1');

        $this->container->instance(EventMutex::class, $eventMutex);
        $this->container->instance(SchedulingMutex::class, $schedulingMutex);

        $schedule = new Schedule();
        $schedule->useCache(ScheduleTestCacheStoreIntEnum::Store1);
    }
}

class JobToTestWithSchedule implements ShouldQueue
{
}
