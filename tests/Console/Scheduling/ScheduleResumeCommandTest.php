<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Scheduling;

use Hypervel\Console\Events\ScheduleResumed;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use Mockery as m;

class ScheduleResumeCommandTest extends TestCase
{
    public function testResumeCommandClearsPauseSignal(): void
    {
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('forget')
            ->once()
            ->with('hypervel:schedule:paused')
            ->andReturnTrue();

        $this->app->instance(Cache::class, $cache);
        Event::fake();

        $this->artisan('schedule:resume')
            ->assertSuccessful();

        Event::assertDispatched(ScheduleResumed::class);
    }

    public function testPassiveObserverDoesNotCauseResumeSignalToDispatch(): void
    {
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('forget')
            ->once()
            ->with('hypervel:schedule:paused')
            ->andReturnTrue();
        $this->app->instance(Cache::class, $cache);

        $observedEvents = [];
        $this->app->make(Dispatcher::class)->observe(
            ScheduleResumed::class,
            static function (ScheduleResumed $event) use (&$observedEvents): void {
                $observedEvents[] = $event;
            }
        );

        $this->artisan('schedule:resume')->assertSuccessful();

        $this->assertSame([], $observedEvents);
    }

    public function testContinueAliasClearsPauseSignal(): void
    {
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('forget')
            ->once()
            ->with('hypervel:schedule:paused')
            ->andReturnTrue();

        $this->app->instance(Cache::class, $cache);
        Event::fake();

        $this->artisan('schedule:continue')
            ->assertSuccessful();

        Event::assertDispatched(ScheduleResumed::class);
    }
}
