<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console\Scheduling;

use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Sleep;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;

#[WithConfig('cache.default', 'worker-array')]
class ScheduleInterruptCommandTest extends TestCase
{
    public function testInterruptsCommandsStartedBeforeIt(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $startedAt = CarbonImmutable::now();

        CarbonImmutable::setTestNow($startedAt->addSecond());

        $this->artisan('schedule:interrupt')->assertSuccessful();

        $this->assertTrue($schedule->hasBeenInterruptedSince($startedAt));
        $this->assertFalse($schedule->hasBeenInterruptedSince(CarbonImmutable::now()->addSecond()));
    }

    public function testReadsTheTimestampAsStoresSuchAsRedisReturnIt(): void
    {
        Cache::forever('hypervel:schedule:interrupt', (string) CarbonImmutable::now()->getTimestampMs());

        $this->assertTrue($this->app->make(Schedule::class)->hasBeenInterruptedSince(CarbonImmutable::now()->subSecond()));
    }

    public function testDoesNotInterruptWhenInterruptingIsDisabled(): void
    {
        $this->artisan('schedule:interrupt');

        Schedule::$interruptible = false;

        $this->assertFalse($this->app->make(Schedule::class)->hasBeenInterruptedSince(CarbonImmutable::now()->subMinute()));
    }

    public function testLaterScheduleRunsPreserveEarlierInterruptionsAfterMoreThanAMinute(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $earlierStart = CarbonImmutable::now()->startOfMinute();
        CarbonImmutable::setTestNow($earlierStart->addSecond());

        $this->artisan('schedule:interrupt')->assertSuccessful();

        $laterStart = $earlierStart->addMinutes(2)->addSeconds(57);
        CarbonImmutable::setTestNow($laterStart);
        $runs = 0;
        $schedule->call(function () use (&$runs): void {
            ++$runs;
        })->everySecond();
        Sleep::fake(syncWithCarbon: true);

        $this->artisan('schedule:run', ['--once' => true])->assertSuccessful();

        $this->assertSame(3, $runs);
        $this->assertTrue($schedule->hasBeenInterruptedSince($earlierStart));
    }
}
