<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console\Scheduling;

use Hypervel\Console\Events\ScheduleResumed;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class ScheduleResumeCommandTest extends TestCase
{
    use VerifiesDoubles;

    public function testDispatchesEvent(): void
    {
        $cache = Double::for(Cache::class)->strict();
        $cache->expects('forget')
            ->with('hypervel:schedule:paused')
            ->returns(true);

        $this->app->instance(Cache::class, $cache);
        Event::fake();

        $this->artisan('schedule:resume')
            ->assertSuccessful();

        Event::assertDispatched(ScheduleResumed::class);
    }

    public function testPassiveObserverDoesNotCauseResumeSignalToDispatch(): void
    {
        $cache = Double::for(Cache::class)->strict();
        $cache->expects('forget')
            ->with('hypervel:schedule:paused')
            ->returns(true);
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
        $cache = Double::for(Cache::class)->strict();
        $cache->expects('forget')
            ->with('hypervel:schedule:paused')
            ->returns(true);

        $this->app->instance(Cache::class, $cache);
        Event::fake();

        $this->artisan('schedule:continue')
            ->assertSuccessful();

        Event::assertDispatched(ScheduleResumed::class);
    }
}
