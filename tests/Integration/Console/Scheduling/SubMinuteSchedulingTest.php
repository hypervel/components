<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console\Scheduling\SubMinuteSchedulingTest;

use Hypervel\Cache\Repository;
use Hypervel\Cache\WorkerArrayStore;
use Hypervel\Console\Events\ScheduledTaskSkipped;
use Hypervel\Console\Scheduling\CacheEventMutex;
use Hypervel\Console\Scheduling\CacheSchedulingMutex;
use Hypervel\Console\Scheduling\EventMutex;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Console\Scheduling\SchedulingMutex;
use Hypervel\Container\Container;
use Hypervel\Contracts\Cache\Factory;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Sleep;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use UnitEnum;

class SubMinuteSchedulingTest extends TestCase
{
    protected Schedule $schedule;

    protected function setUp(): void
    {
        $this->beforeApplicationDestroyed(function () {
            @unlink(storage_path('framework/down'));
        });

        parent::setUp();

        $cache = new class implements Factory {
            public Repository $store;

            public function __construct()
            {
                // Use worker-array because scheduling mutexes must survive across scheduler coroutines.
                $this->store = new Repository(new WorkerArrayStore(true));
            }

            public function store(UnitEnum|string|null $name = null): Repository
            {
                return $this->store;
            }
        };

        $container = Container::getInstance();

        $container->instance(EventMutex::class, new CacheEventMutex($cache));
        $container->instance(SchedulingMutex::class, new CacheSchedulingMutex($cache));

        $this->schedule = $this->app->make(Schedule::class);
    }

    public function testItDoesntWaitForSubMinuteEventsWhenNothingIsScheduled(): void
    {
        CarbonImmutable::setTestNow(now()->startOfMinute());
        Sleep::fake();

        $this->artisan('schedule:run', ['--once' => true])
            ->expectsOutputToContain('No scheduled commands are ready to run.');

        Sleep::assertNeverSlept();
    }

    public function testItDoesntWaitForSubMinuteEventsWhenNoneAreScheduled(): void
    {
        $this->schedule
            ->call(fn () => true)
            ->everyMinute();

        CarbonImmutable::setTestNow(now()->startOfMinute());
        Sleep::fake();

        $this->artisan('schedule:run', ['--once' => true])
            ->expectsOutputToContain('Running [Callback]');

        Sleep::assertNeverSlept();
    }

    #[DataProvider('frequencyProvider')]
    public function testItRunsSubMinuteCallbacks(string $frequency, int $expectedRuns): void
    {
        $runs = 0;
        $this->schedule->call(function () use (&$runs) {
            ++$runs;
        })->{$frequency}();

        CarbonImmutable::setTestNow(now()->startOfMinute());
        Sleep::fake();
        Sleep::whenFakingSleep(fn ($duration) => CarbonImmutable::setTestNow(now()->add($duration)));

        $this->artisan('schedule:run', ['--once' => true])
            ->expectsOutputToContain('Running [Callback]');

        Sleep::assertSleptTimes(600);
        $this->assertEquals($expectedRuns, $runs);
    }

    public static function frequencyProvider(): array
    {
        return [
            'everySecond' => ['everySecond', 60],
            'everyTwoSeconds' => ['everyTwoSeconds', 30],
            'everyFiveSeconds' => ['everyFiveSeconds', 12],
            'everyTenSeconds' => ['everyTenSeconds', 6],
            'everyFifteenSeconds' => ['everyFifteenSeconds', 4],
            'everyTwentySeconds' => ['everyTwentySeconds', 3],
            'everyThirtySeconds' => ['everyThirtySeconds', 2],
        ];
    }

    public function testItRunsMultipleSubMinuteCallbacks(): void
    {
        $everySecondRuns = 0;
        $this->schedule->call(function () use (&$everySecondRuns) {
            ++$everySecondRuns;
        })->everySecond();

        $everyThirtySecondsRuns = 0;
        $this->schedule->call(function () use (&$everyThirtySecondsRuns) {
            ++$everyThirtySecondsRuns;
        })->everyThirtySeconds();

        CarbonImmutable::setTestNow(now()->startOfMinute());
        Sleep::fake();
        Sleep::whenFakingSleep(fn ($duration) => CarbonImmutable::setTestNow(now()->add($duration)));

        $this->artisan('schedule:run', ['--once' => true])
            ->expectsOutputToContain('Running [Callback]');

        Sleep::assertSleptTimes(600);
        $this->assertEquals(60, $everySecondRuns);
        $this->assertEquals(2, $everyThirtySecondsRuns);
    }

    public function testSubMinuteSchedulingCanBeInterrupted(): void
    {
        $runs = 0;
        $this->schedule->call(function () use (&$runs) {
            ++$runs;
        })->everySecond();

        CarbonImmutable::setTestNow(now()->startOfMinute());
        $startedAt = now();
        Sleep::fake();
        Sleep::whenFakingSleep(function ($duration) use ($startedAt) {
            CarbonImmutable::setTestNow(now()->add($duration));

            if ($startedAt->diffInSeconds() >= 30) {
                $this->artisan('schedule:interrupt')
                    ->expectsOutputToContain('Broadcasting schedule interrupt signal.');
            }
        });

        $this->artisan('schedule:run', ['--once' => true])
            ->expectsOutputToContain('Running [Callback]');

        Sleep::assertSleptTimes(300);
        $this->assertEquals(30, $runs);
        $this->assertEquals(30, $startedAt->diffInSeconds(now()));
    }

    public function testSubMinuteEventsStopForTheRestOfTheMinuteOnceMaintenanceModeIsEnabled(): void
    {
        $runs = 0;
        $this->schedule->call(function () use (&$runs) {
            ++$runs;
        })->everySecond();

        config(['app.maintenance.driver' => 'cache']);
        config(['app.maintenance.store' => 'array']);
        CarbonImmutable::setTestNow(now()->startOfMinute());
        $startedAt = now();
        Sleep::fake();
        Sleep::whenFakingSleep(function ($duration) use ($startedAt) {
            CarbonImmutable::setTestNow(now()->add($duration));

            if ($startedAt->diffInSeconds() >= 30 && ! $this->app->isDownForMaintenance()) {
                $this->artisan('down');
            }

            if ($startedAt->diffInSeconds() >= 40 && $this->app->isDownForMaintenance()) {
                $this->artisan('up');
            }
        });

        $this->artisan('schedule:run', ['--once' => true])
            ->expectsOutputToContain('Running [Callback]');

        Sleep::assertSleptTimes(600);
        $this->assertEquals(30, $runs);
    }

    public function testSubMinuteEventsCanBeRunInMaintenanceMode(): void
    {
        $runs = 0;
        $this->schedule->call(function () use (&$runs) {
            ++$runs;
        })->everySecond()->evenInMaintenanceMode();

        config(['app.maintenance.driver' => 'cache']);
        config(['app.maintenance.store' => 'array']);
        CarbonImmutable::setTestNow(now()->startOfMinute());
        $startedAt = now();
        Sleep::fake();
        Sleep::whenFakingSleep(function ($duration) use ($startedAt) {
            CarbonImmutable::setTestNow(now()->add($duration));

            if (now()->diffInSeconds($startedAt) >= 30 && ! $this->app->isDownForMaintenance()) {
                $this->artisan('down');
            }
        });

        $this->artisan('schedule:run', ['--once' => true])
            ->expectsOutputToContain('Running [Callback]');

        Sleep::assertSleptTimes(600);
        $this->assertEquals(60, $runs);
    }

    public function testSubMinuteEventsCanBeRunWhenScheduleIsPaused(): void
    {
        $runs = 0;
        $this->schedule->call(function () use (&$runs) {
            ++$runs;
        })->everySecond()->evenWhenPaused();

        CarbonImmutable::setTestNow(now()->startOfMinute());
        $startedAt = now();
        $cache = $this->app->make(CacheRepository::class);
        Sleep::fake();
        Sleep::whenFakingSleep(function ($duration) use ($startedAt, $cache) {
            CarbonImmutable::setTestNow(now()->add($duration));

            if ($startedAt->diffInSeconds() >= 30 && ! $cache->get('hypervel:schedule:paused', false)) {
                $this->artisan('schedule:pause')
                    ->expectsOutputToContain('Scheduled task processing has been paused.');
            }
        });

        $this->artisan('schedule:run', ['--once' => true])
            ->expectsOutputToContain('Running [Callback]');

        Sleep::assertSleptTimes(600);
        $this->assertEquals(60, $runs);
    }

    public function testPausedSubMinuteEventsAreSkippedAtTheirNaturalCadenceFromStart(): void
    {
        $runs = 0;
        $skips = 0;
        $this->schedule->call(function () use (&$runs) {
            ++$runs;
        })->everySecond();

        $this->app->make(Dispatcher::class)->listen(
            ScheduledTaskSkipped::class,
            function () use (&$skips): void {
                ++$skips;
            },
        );

        config(['cache.default' => 'worker-array']);
        CarbonImmutable::setTestNow(now()->startOfMinute());
        Sleep::fake();
        Sleep::whenFakingSleep(fn ($duration) => CarbonImmutable::setTestNow(now()->add($duration)));

        $this->artisan('schedule:pause')
            ->expectsOutputToContain('Scheduled task processing has been paused.');

        $this->artisan('schedule:run', ['--once' => true])
            ->assertSuccessful();

        Sleep::assertSleptTimes(600);
        $this->assertSame(0, $runs);
        $this->assertSame(60, $skips);
    }

    public function testSubMinuteEventsStopForTheRestOfTheMinuteOnceScheduleIsPaused(): void
    {
        $runs = 0;
        $skips = 0;
        $this->schedule->call(function () use (&$runs) {
            ++$runs;
        })->everySecond();

        $this->app->make(Dispatcher::class)->listen(
            ScheduledTaskSkipped::class,
            function () use (&$skips): void {
                ++$skips;
            },
        );

        CarbonImmutable::setTestNow(now()->startOfMinute());
        $startedAt = now();
        $cache = $this->app->make(CacheRepository::class);
        Sleep::fake();
        Sleep::whenFakingSleep(function ($duration) use ($startedAt, $cache) {
            CarbonImmutable::setTestNow(now()->add($duration));

            if ($startedAt->diffInSeconds() >= 30 && ! $cache->get('hypervel:schedule:paused', false)) {
                $this->artisan('schedule:pause')
                    ->expectsOutputToContain('Scheduled task processing has been paused.');
            }
        });

        $this->artisan('schedule:run', ['--once' => true])
            ->expectsOutputToContain('Running [Callback]');

        Sleep::assertSleptTimes(600);
        $this->assertEquals(30, $runs);
        $this->assertSame(30, $skips);
    }

    public function testSubMinuteSchedulingRespectsFilters(): void
    {
        $runs = 0;
        $this->schedule->call(function () use (&$runs) {
            ++$runs;
        })->everySecond()->when(fn () => now()->second % 2 === 0);

        CarbonImmutable::setTestNow(now()->startOfMinute());
        Sleep::fake();
        Sleep::whenFakingSleep(fn ($duration) => CarbonImmutable::setTestNow(now()->add($duration)));

        $this->artisan('schedule:run', ['--once' => true])
            ->expectsOutputToContain('Running [Callback]');

        Sleep::assertSleptTimes(600);
        $this->assertEquals(30, $runs);
    }

    public function testSubMinuteSchedulingCanRunOnOneServer(): void
    {
        $runs = 0;
        $this->schedule->call(function () use (&$runs) {
            ++$runs;
        })->everySecond()->name('test')->onOneServer();

        $startedAt = now()->startOfMinute();
        CarbonImmutable::setTestNow($startedAt);
        Sleep::fake();
        Sleep::whenFakingSleep(fn ($duration) => CarbonImmutable::setTestNow(now()->add($duration)));

        $this->app->instance(Schedule::class, clone $this->schedule);
        $this->artisan('schedule:run', ['--once' => true])
            ->expectsOutputToContain('Running [test]');

        Sleep::assertSleptTimes(600);
        $this->assertEquals(60, $runs);

        // Fake a second server running at the same minute.
        CarbonImmutable::setTestNow($startedAt);

        $this->app->instance(Schedule::class, clone $this->schedule);
        $this->artisan('schedule:run', ['--once' => true])
            ->expectsOutputToContain('Skipping [test]');

        Sleep::assertSleptTimes(1200);
        $this->assertEquals(60, $runs);
    }
}
