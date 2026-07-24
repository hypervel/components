<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Contracts\Foundation\MaintenanceMode as MaintenanceModeContract;
use Hypervel\Foundation\ArrayMaintenanceMode;
use Hypervel\Foundation\WorkerCachedMaintenanceMode;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use Mockery as m;

class WorkerCachedMaintenanceModeTest extends TestCase
{
    public function testActiveCallsDriverOnlyOnceAndCachesResult()
    {
        $driver = m::mock(MaintenanceModeContract::class);
        $driver->shouldReceive('active')->once()->andReturn(true);
        $driver->shouldReceive('data')->once()->andReturn(['status' => 503]);

        $cached = new WorkerCachedMaintenanceMode($driver);

        $this->assertTrue($cached->active());
        $this->assertTrue($cached->active());
        $this->assertTrue($cached->active());
    }

    public function testDataReturnsCachedPayloadWithoutRereadingDriver()
    {
        $driver = m::mock(MaintenanceModeContract::class);
        $driver->shouldReceive('active')->once()->andReturn(true);
        $driver->shouldReceive('data')->once()->andReturn(['status' => 503, 'retry' => 60]);

        $cached = new WorkerCachedMaintenanceMode($driver);

        $this->assertSame(['status' => 503, 'retry' => 60], $cached->data());
        $this->assertSame(['status' => 503, 'retry' => 60], $cached->data());
    }

    public function testActiveAndDataAreLoadedAtomically()
    {
        $driver = m::mock(MaintenanceModeContract::class);
        $driver->shouldReceive('active')->once()->andReturn(true);
        $driver->shouldReceive('data')->once()->andReturn(['status' => 503]);

        $cached = new WorkerCachedMaintenanceMode($driver);

        $this->assertTrue($cached->active());
        $this->assertSame(['status' => 503], $cached->data());
        $this->assertTrue($cached->active());
        $this->assertSame(['status' => 503], $cached->data());
    }

    public function testSnapshotIsReusedWithinRefreshInterval(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::parse('2026-01-01 00:00:00'));

        $driver = m::mock(MaintenanceModeContract::class);
        $driver->shouldReceive('active')->once()->andReturn(false);
        $driver->shouldNotReceive('data');

        $cached = new WorkerCachedMaintenanceMode($driver, refreshInterval: 5);

        $this->assertFalse($cached->active());

        CarbonImmutable::setTestNow($now->addSeconds(4));

        $this->assertFalse($cached->active());
    }

    public function testSnapshotRefreshesAfterInterval(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::parse('2026-01-01 00:00:00'));

        $driver = m::mock(MaintenanceModeContract::class);
        $driver->shouldReceive('active')->twice()->andReturn(false, true);
        $driver->shouldReceive('data')->once()->andReturn(['status' => 503]);

        $cached = new WorkerCachedMaintenanceMode($driver, refreshInterval: 5);

        $this->assertFalse($cached->active());

        CarbonImmutable::setTestNow($now->addSeconds(6));

        $this->assertTrue($cached->active());
    }

    public function testSnapshotRefreshesAtExactIntervalBoundary(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::parse('2026-01-01 00:00:00'));

        $driver = m::mock(MaintenanceModeContract::class);
        $driver->shouldReceive('active')->twice()->andReturn(false, true);
        $driver->shouldReceive('data')->once()->andReturn(['status' => 503]);

        $cached = new WorkerCachedMaintenanceMode($driver, refreshInterval: 5);

        $this->assertFalse($cached->active());

        CarbonImmutable::setTestNow($now->addSeconds(5));

        $this->assertTrue($cached->active());
    }

    public function testActivePayloadRefreshesAfterInterval(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::parse('2026-01-01 00:00:00'));

        $driver = m::mock(MaintenanceModeContract::class);
        $driver->shouldReceive('active')->twice()->andReturn(true, true);
        $driver->shouldReceive('data')->twice()->andReturn(['status' => 503], ['status' => 418]);

        $cached = new WorkerCachedMaintenanceMode($driver, refreshInterval: 5);

        $this->assertSame(['status' => 503], $cached->data());

        CarbonImmutable::setTestNow($now->addSeconds(5));

        $this->assertSame(['status' => 418], $cached->data());
    }

    public function testZeroRefreshIntervalDisablesTimeRefresh(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::parse('2026-01-01 00:00:00'));

        $driver = m::mock(MaintenanceModeContract::class);
        $driver->shouldReceive('active')->once()->andReturn(false);
        $driver->shouldNotReceive('data');

        $cached = new WorkerCachedMaintenanceMode($driver, refreshInterval: 0);

        $this->assertFalse($cached->active());

        CarbonImmutable::setTestNow($now->addYear());

        $this->assertFalse($cached->active());
    }

    public function testNegativeRefreshIntervalDisablesTimeRefresh(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::parse('2026-01-01 00:00:00'));

        $driver = m::mock(MaintenanceModeContract::class);
        $driver->shouldReceive('active')->once()->andReturn(false);
        $driver->shouldNotReceive('data');

        $cached = new WorkerCachedMaintenanceMode($driver, refreshInterval: -1);

        $this->assertFalse($cached->active());

        CarbonImmutable::setTestNow($now->addYear());

        $this->assertFalse($cached->active());
    }

    public function testFlushCacheResetsSnapshot()
    {
        $driver = m::mock(MaintenanceModeContract::class);
        $driver->shouldReceive('active')->twice()->andReturn(true, false);
        $driver->shouldReceive('data')->once()->andReturn(['status' => 503]);

        $cached = new WorkerCachedMaintenanceMode($driver);

        $this->assertTrue($cached->active());

        WorkerCachedMaintenanceMode::flushCache();

        $this->assertFalse($cached->active());
    }

    public function testFlushCacheForcesRefreshWithinInterval(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::parse('2026-01-01 00:00:00'));

        $driver = m::mock(MaintenanceModeContract::class);
        $driver->shouldReceive('active')->twice()->andReturn(false, true);
        $driver->shouldReceive('data')->once()->andReturn(['status' => 503]);

        $cached = new WorkerCachedMaintenanceMode($driver, refreshInterval: 60);

        $this->assertFalse($cached->active());

        CarbonImmutable::setTestNow($now->addSecond());
        WorkerCachedMaintenanceMode::flushCache();

        $this->assertTrue($cached->active());
    }

    public function testActivateDelegatesToDriverAndFlushesCache()
    {
        $driver = m::mock(MaintenanceModeContract::class);
        $driver->shouldReceive('active')->twice()->andReturn(false, true);
        $driver->shouldReceive('data')->once()->andReturn(['status' => 503]);
        $driver->shouldReceive('activate')->once()->with(['status' => 503]);

        $cached = new WorkerCachedMaintenanceMode($driver);

        $this->assertFalse($cached->active());

        $cached->activate(['status' => 503]);

        $this->assertTrue($cached->active());
    }

    public function testDeactivateDelegatesToDriverAndFlushesCache()
    {
        $driver = m::mock(MaintenanceModeContract::class);
        $driver->shouldReceive('active')->twice()->andReturn(true, false);
        $driver->shouldReceive('data')->once()->andReturn(['status' => 503]);
        $driver->shouldReceive('deactivate')->once();

        $cached = new WorkerCachedMaintenanceMode($driver);

        $this->assertTrue($cached->active());

        $cached->deactivate();

        $this->assertFalse($cached->active());
    }

    public function testWhenNotActiveDataReturnsEmptyArrayWithoutCallingDriverData()
    {
        $driver = m::mock(MaintenanceModeContract::class);
        $driver->shouldReceive('active')->once()->andReturn(false);
        $driver->shouldNotReceive('data');

        $cached = new WorkerCachedMaintenanceMode($driver);

        $this->assertSame([], $cached->data());
    }

    public function testAfterFlushAndRereadDecoratorReflectsUpdatedState()
    {
        $driver = m::mock(MaintenanceModeContract::class);
        $driver->shouldReceive('active')->twice()->andReturn(true, false);
        $driver->shouldReceive('data')->once()->andReturn(['status' => 503]);

        $cached = new WorkerCachedMaintenanceMode($driver);

        $this->assertTrue($cached->active());
        $this->assertSame(['status' => 503], $cached->data());

        WorkerCachedMaintenanceMode::flushCache();

        $this->assertFalse($cached->active());
        $this->assertSame([], $cached->data());
    }

    public function testArrayDriverChangesFlushTheSameProcessSnapshot(): void
    {
        $cached = new WorkerCachedMaintenanceMode(new ArrayMaintenanceMode);

        $this->assertFalse($cached->active());

        $cached->activate(['status' => 503]);

        $this->assertTrue($cached->active());
        $this->assertSame(['status' => 503], $cached->data());

        $cached->deactivate();

        $this->assertFalse($cached->active());
        $this->assertSame([], $cached->data());
    }
}
