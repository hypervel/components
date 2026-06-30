<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Scheduling;

use Hypervel\Console\Events\SchedulePaused;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use Mockery as m;

class SchedulePauseCommandTest extends TestCase
{
    public function testPauseCommandBroadcastsPauseSignal()
    {
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('forever')
            ->once()
            ->with('hypervel:schedule:paused', true)
            ->andReturnTrue();

        $this->app->instance(Cache::class, $cache);
        Event::fake();

        $this->artisan('schedule:pause')
            ->assertSuccessful();

        Event::assertDispatched(SchedulePaused::class);
    }

    public function testPauseCommandFailsWhenPausePollingIsDisabled()
    {
        Schedule::withoutInterruptionPolling();

        $cache = m::mock(Cache::class);
        $cache->shouldNotReceive('forever');

        $this->app->instance(Cache::class, $cache);
        Event::fake();

        $this->artisan('schedule:pause')
            ->assertFailed();

        Event::assertNotDispatched(SchedulePaused::class);
    }
}
