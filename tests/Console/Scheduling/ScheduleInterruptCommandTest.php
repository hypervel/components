<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Scheduling;

use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Testbench\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class ScheduleInterruptCommandTest extends TestCase
{
    public function testInterruptCommandBroadcastsSignal()
    {
        $cache = m::mock(CacheFactory::class);
        $cache->shouldReceive('put')
            ->once()
            ->with('hypervel:schedule:interrupt', true, m::type(\DateTimeInterface::class));

        $this->app->instance(CacheFactory::class, $cache);

        $this->artisan('schedule:interrupt')
            ->assertSuccessful();
    }

    public function testInterruptCommandRejectsZeroMinutes()
    {
        $this->artisan('schedule:interrupt', ['--minutes' => '0'])
            ->assertFailed();
    }

    public function testInterruptCommandRejectsNegativeMinutes()
    {
        $this->artisan('schedule:interrupt', ['--minutes' => '-1'])
            ->assertFailed();
    }

    public function testInterruptCommandRejectsNonNumericMinutes()
    {
        $this->artisan('schedule:interrupt', ['--minutes' => 'abc'])
            ->assertFailed();
    }

    public function testInterruptCommandAcceptsCustomMinutes()
    {
        $cache = m::mock(CacheFactory::class);
        $cache->shouldReceive('put')
            ->once()
            ->with('hypervel:schedule:interrupt', true, m::type(\DateTimeInterface::class));

        $this->app->instance(CacheFactory::class, $cache);

        $this->artisan('schedule:interrupt', ['--minutes' => '5'])
            ->assertSuccessful();
    }
}
