<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console\Scheduling\SubMinuteSchedulingTest;

use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Repository;
use Hypervel\Console\Scheduling\CacheEventMutex;
use Hypervel\Console\Scheduling\CacheSchedulingMutex;
use Hypervel\Console\Scheduling\EventMutex;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Console\Scheduling\SchedulingMutex;
use Hypervel\Container\Container;
use Hypervel\Contracts\Cache\Factory;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Support\Carbon;
use Hypervel\Support\Sleep;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 * @coversNothing
 */
class SubMinuteSchedulingTest extends TestCase
{
    use RunTestsInCoroutine;

    protected Schedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schedule = $this->app->make(Schedule::class);

        $cache = new class implements Factory {
            public Repository $store;

            public function __construct()
            {
                $this->store = new Repository(new ArrayStore(true));
            }

            public function store(?string $name = null): Repository
            {
                return $this->store;
            }
        };

        $container = Container::getInstance();

        $container->instance(EventMutex::class, new CacheEventMutex($cache));
        $container->instance(SchedulingMutex::class, new CacheSchedulingMutex($cache));
    }

    public function testItDoesntWaitForSubMinuteEventsWhenNothingIsScheduled()
    {
        Carbon::setTestNow(now()->startOfMinute());
        Sleep::fake();

        $this->artisan('schedule:run', ['--once' => true])
            ->expectsOutputToContain('No scheduled commands are ready to run.');

        Sleep::assertNeverSlept();
    }

    public function testItDoesntWaitForSubMinuteEventsWhenNoneAreScheduled()
    {
        $this->schedule
            ->call(fn () => true)
            ->everyMinute();

        Carbon::setTestNow(now()->startOfMinute());
        Sleep::fake();

        $this->artisan('schedule:run', ['--once' => true])
            ->expectsOutputToContain('Running [Callback]');

        Sleep::assertNeverSlept();
    }

    #[DataProvider('frequencyProvider')]
    public function testItRunsSubMinuteCallbacks(string $frequency, int $expectedRuns)
    {
        $runs = 0;
        $this->schedule->call(function () use (&$runs) {
            ++$runs;
        })->{$frequency}();

        Carbon::setTestNow(now()->startOfMinute());
        Sleep::fake();
        Sleep::whenFakingSleep(fn ($duration) => Carbon::setTestNow(now()->add($duration)));

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

    public function testItRunsMultipleSubMinuteCallbacks()
    {
        $everySecondRuns = 0;
        $this->schedule->call(function () use (&$everySecondRuns) {
            ++$everySecondRuns;
        })->everySecond();

        $everyThirtySecondsRuns = 0;
        $this->schedule->call(function () use (&$everyThirtySecondsRuns) {
            ++$everyThirtySecondsRuns;
        })->everyThirtySeconds();

        Carbon::setTestNow(now()->startOfMinute());
        Sleep::fake();
        Sleep::whenFakingSleep(fn ($duration) => Carbon::setTestNow(now()->add($duration)));

        $this->artisan('schedule:run', ['--once' => true])
            ->expectsOutputToContain('Running [Callback]');

        Sleep::assertSleptTimes(600);
        $this->assertEquals(60, $everySecondRuns);
        $this->assertEquals(2, $everyThirtySecondsRuns);
    }

    public function testSubMinuteSchedulingCanBeInterrupted()
    {
        $runs = 0;
        $this->schedule->call(function () use (&$runs) {
            ++$runs;
        })->everySecond();

        Carbon::setTestNow(now()->startOfMinute());
        $startedAt = now();
        Sleep::fake();
        Sleep::whenFakingSleep(function ($duration) use ($startedAt) {
            Carbon::setTestNow(now()->add($duration));

            if ($startedAt->diffInSeconds() >= 30) {
                $this->artisan('schedule:stop')
                    ->expectsOutputToContain('Broadcasting schedule stop signal.');
            }
        });

        $this->artisan('schedule:run', ['--once' => true])
            ->expectsOutputToContain('Running [Callback]');

        Sleep::assertSleptTimes(300);
        $this->assertEquals(30, $runs);
        $this->assertEquals(30, $startedAt->diffInSeconds(now()));
    }

    // @TODO Port once maintenance mode is implemented (Application::isDownForMaintenance() currently stubbed to false)
    // public function testSubMinuteEventsStopForTheRestOfTheMinuteOnceMaintenanceModeIsEnabled()

    // @TODO Port once maintenance mode is implemented (Application::isDownForMaintenance() currently stubbed to false)
    // public function testSubMinuteEventsCanBeRunInMaintenanceMode()

    public function testSubMinuteSchedulingRespectsFilters()
    {
        $runs = 0;
        $this->schedule->call(function () use (&$runs) {
            ++$runs;
        })->everySecond()->when(fn () => now()->second % 2 === 0);

        Carbon::setTestNow(now()->startOfMinute());
        Sleep::fake();
        Sleep::whenFakingSleep(fn ($duration) => Carbon::setTestNow(now()->add($duration)));

        $this->artisan('schedule:run', ['--once' => true])
            ->expectsOutputToContain('Running [Callback]');

        Sleep::assertSleptTimes(600);
        $this->assertEquals(30, $runs);
    }

    public function testSubMinuteSchedulingCanRunOnOneServer()
    {
        $runs = 0;
        $this->schedule->call(function () use (&$runs) {
            ++$runs;
        })->everySecond()->name('test')->onOneServer();

        $startedAt = now()->startOfMinute();
        Carbon::setTestNow($startedAt);
        Sleep::fake();
        Sleep::whenFakingSleep(fn ($duration) => Carbon::setTestNow(now()->add($duration)));

        $this->app->instance(Schedule::class, clone $this->schedule);
        $this->artisan('schedule:run', ['--once' => true])
            ->expectsOutputToContain('Running [test]');

        Sleep::assertSleptTimes(600);
        $this->assertEquals(60, $runs);

        // Fake a second server running at the same minute.
        Carbon::setTestNow($startedAt);

        $this->app->instance(Schedule::class, clone $this->schedule);
        $this->artisan('schedule:run', ['--once' => true])
            ->expectsOutputToContain('Skipping [test]');

        Sleep::assertSleptTimes(1200);
        $this->assertEquals(60, $runs);
    }
}
