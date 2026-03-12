<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Contracts\Foundation\MaintenanceMode as MaintenanceModeContract;
use Hypervel\Foundation\WorkerCachedMaintenanceMode;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class WorkerCachedMaintenanceModeTest extends TestCase
{
    protected function tearDown(): void
    {
        WorkerCachedMaintenanceMode::flushCache();

        parent::tearDown();
    }

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
}
