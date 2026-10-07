<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Scheduling;

use DateTimeInterface;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Testbench\TestCase;
use Mockery as m;

class ScheduleInterruptCommandTest extends TestCase
{
    public function testInterruptCommandBroadcastsSignal(): void
    {
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('put')
            ->once()
            ->with('hypervel:schedule:interrupt', true, m::type(DateTimeInterface::class));

        $this->app->instance(Cache::class, $cache);

        $this->artisan('schedule:interrupt')
            ->assertSuccessful();
    }

    public function testInterruptCommandRejectsZeroMinutes(): void
    {
        $this->artisan('schedule:interrupt', ['--minutes' => '0'])
            ->assertFailed();
    }

    public function testInterruptCommandRejectsNegativeMinutes(): void
    {
        $this->artisan('schedule:interrupt', ['--minutes' => '-1'])
            ->assertFailed();
    }

    public function testInterruptCommandRejectsNonNumericMinutes(): void
    {
        $this->artisan('schedule:interrupt', ['--minutes' => 'abc'])
            ->assertFailed();
    }

    public function testInterruptCommandRejectsDecimalMinutes(): void
    {
        $this->artisan('schedule:interrupt', ['--minutes' => '1.5'])
            ->assertFailed();
    }

    public function testInterruptCommandAcceptsCustomMinutes(): void
    {
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('put')
            ->once()
            ->with('hypervel:schedule:interrupt', true, m::type(DateTimeInterface::class));

        $this->app->instance(Cache::class, $cache);

        $this->artisan('schedule:interrupt', ['--minutes' => '5'])
            ->assertSuccessful();
    }
}
