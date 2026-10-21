<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console\Scheduling;

use Hypervel\Console\Events\SchedulePaused;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use Mockery as m;

class SchedulePauseCommandTest extends TestCase
{
    public function testDispatchesEvent(): void
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

    public function testFailsWhenPausingIsDisabled(): void
    {
        $cache = m::mock(Cache::class);
        $cache->shouldNotReceive('forever');

        $this->app->instance(Cache::class, $cache);
        Event::fake();

        Schedule::$pausable = false;

        $this->artisan('schedule:pause')
            ->assertFailed();

        Event::assertNotDispatched(SchedulePaused::class);
    }

    public function testPassiveObserverDoesNotCausePauseSignalToDispatch(): void
    {
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('forever')
            ->once()
            ->with('hypervel:schedule:paused', true)
            ->andReturnTrue();
        $this->app->instance(Cache::class, $cache);

        $observedEvents = [];
        $this->app->make(Dispatcher::class)->observe(
            SchedulePaused::class,
            static function (SchedulePaused $event) use (&$observedEvents): void {
                $observedEvents[] = $event;
            }
        );

        $this->artisan('schedule:pause')->assertSuccessful();

        $this->assertSame([], $observedEvents);
    }
}
