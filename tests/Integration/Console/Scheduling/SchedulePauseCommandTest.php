<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console\Scheduling;

use Hypervel\Console\Events\SchedulePaused;
use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class SchedulePauseCommandTest extends TestCase
{
    use VerifiesDoubles;

    public function testDispatchesEvent(): void
    {
        $cache = Double::for(Cache::class)->strict();
        $cache->expects('forever')
            ->with('hypervel:schedule:paused', true)
            ->returns(true);

        $this->app->instance(Cache::class, $cache);
        Event::fake();

        $this->artisan('schedule:pause')
            ->assertSuccessful();

        Event::assertDispatched(SchedulePaused::class);
    }

    public function testFailsWhenPausingIsDisabled(): void
    {
        $cache = Double::for(Cache::class)->strict();
        $cache->expects('forever')->never();

        $this->app->instance(Cache::class, $cache);
        Event::fake();

        Schedule::$pausable = false;

        $this->artisan('schedule:pause')
            ->assertFailed();

        Event::assertNotDispatched(SchedulePaused::class);
    }

    public function testPassiveObserverDoesNotCausePauseSignalToDispatch(): void
    {
        $cache = Double::for(Cache::class)->strict();
        $cache->expects('forever')
            ->with('hypervel:schedule:paused', true)
            ->returns(true);
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
