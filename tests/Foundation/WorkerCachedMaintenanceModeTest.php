<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Contracts\Foundation\MaintenanceMode as MaintenanceModeContract;
use Hypervel\Foundation\ArrayMaintenanceMode;
use Hypervel\Foundation\WorkerCachedMaintenanceMode;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\TestWith;

class WorkerCachedMaintenanceModeTest extends TestCase
{
    use VerifiesDoubles;

    public function testActiveCallsDriverOnlyOnceAndCachesResult(): void
    {
        $driver = Double::for(MaintenanceModeContract::class);
        $driver->expects('active')->returns(true);
        $driver->expects('data')->returns(['status' => 503]);

        $cached = new WorkerCachedMaintenanceMode($driver);

        $this->assertTrue($cached->active());
        $this->assertTrue($cached->active());
        $this->assertTrue($cached->active());
    }

    public function testDataReturnsCachedPayloadWithoutRereadingDriver(): void
    {
        $driver = Double::for(MaintenanceModeContract::class);
        $driver->expects('active')->returns(true);
        $driver->expects('data')->returns(['status' => 503, 'retry' => 60]);

        $cached = new WorkerCachedMaintenanceMode($driver);

        $this->assertSame(['status' => 503, 'retry' => 60], $cached->data());
        $this->assertSame(['status' => 503, 'retry' => 60], $cached->data());
    }

    public function testActiveAndDataAreLoadedAtomically(): void
    {
        $driver = Double::for(MaintenanceModeContract::class);
        $driver->expects('active')->returns(true);
        $driver->expects('data')->returns(['status' => 503]);

        $cached = new WorkerCachedMaintenanceMode($driver);

        $this->assertTrue($cached->active());
        $this->assertSame(['status' => 503], $cached->data());
        $this->assertTrue($cached->active());
        $this->assertSame(['status' => 503], $cached->data());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testEmptyPayloadRechecksActivityBeforeCachingTheSnapshot(bool $remainsActive): void
    {
        $driver = Double::for(MaintenanceModeContract::class);
        $driver->expects('active')->times(2)->returns(true, $remainsActive);
        $driver->expects('data')->returns([]);

        $cached = new WorkerCachedMaintenanceMode($driver);

        $this->assertSame($remainsActive, $cached->active());
        $this->assertSame([], $cached->data());
        $this->assertSame($remainsActive, $cached->active());
        $this->assertSame([], $cached->data());
    }

    public function testSnapshotIsReusedWithinRefreshInterval(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::parse('2026-01-01 00:00:00'));

        $driver = Double::for(MaintenanceModeContract::class);
        $driver->expects('active')->returns(false);
        $driver->expects('data')->never();

        $cached = new WorkerCachedMaintenanceMode($driver, refreshInterval: 5);

        $this->assertFalse($cached->active());

        CarbonImmutable::setTestNow($now->addSeconds(4));

        $this->assertFalse($cached->active());
    }

    public function testSnapshotRefreshesAfterInterval(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::parse('2026-01-01 00:00:00'));

        $driver = Double::for(MaintenanceModeContract::class);
        $driver->expects('active')->times(2)->returns(false, true);
        $driver->expects('data')->returns(['status' => 503]);

        $cached = new WorkerCachedMaintenanceMode($driver, refreshInterval: 5);

        $this->assertFalse($cached->active());

        CarbonImmutable::setTestNow($now->addSeconds(6));

        $this->assertTrue($cached->active());
    }

    public function testSnapshotRefreshesAtExactIntervalBoundary(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::parse('2026-01-01 00:00:00'));

        $driver = Double::for(MaintenanceModeContract::class);
        $driver->expects('active')->times(2)->returns(false, true);
        $driver->expects('data')->returns(['status' => 503]);

        $cached = new WorkerCachedMaintenanceMode($driver, refreshInterval: 5);

        $this->assertFalse($cached->active());

        CarbonImmutable::setTestNow($now->addSeconds(5));

        $this->assertTrue($cached->active());
    }

    public function testSnapshotDoesNotRefreshBeforeIntervalAcrossFractionalSeconds(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::parse('2026-01-01 00:00:00.900000'));

        $driver = Double::for(MaintenanceModeContract::class);
        $driver->expects('active')->returns(false);

        $cached = new WorkerCachedMaintenanceMode($driver, refreshInterval: 5);

        $this->assertFalse($cached->active());

        // 4.2 seconds have passed, not 5: the whole-second parts differ by 5,
        // so a truncated timestamp would report the interval as elapsed.
        CarbonImmutable::setTestNow($now->addSeconds(4.2));

        $this->assertFalse($cached->active());
    }

    public function testActivePayloadRefreshesAfterInterval(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::parse('2026-01-01 00:00:00'));

        $driver = Double::for(MaintenanceModeContract::class);
        $driver->expects('active')->times(2)->returns(true, true);
        $driver->expects('data')->times(2)->returns(['status' => 503], ['status' => 418]);

        $cached = new WorkerCachedMaintenanceMode($driver, refreshInterval: 5);

        $this->assertSame(['status' => 503], $cached->data());

        CarbonImmutable::setTestNow($now->addSeconds(5));

        $this->assertSame(['status' => 418], $cached->data());
    }

    public function testZeroRefreshIntervalDisablesTimeRefresh(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::parse('2026-01-01 00:00:00'));

        $driver = Double::for(MaintenanceModeContract::class);
        $driver->expects('active')->returns(false);
        $driver->expects('data')->never();

        $cached = new WorkerCachedMaintenanceMode($driver, refreshInterval: 0);

        $this->assertFalse($cached->active());

        CarbonImmutable::setTestNow($now->addYear());

        $this->assertFalse($cached->active());
    }

    public function testNegativeRefreshIntervalDisablesTimeRefresh(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::parse('2026-01-01 00:00:00'));

        $driver = Double::for(MaintenanceModeContract::class);
        $driver->expects('active')->returns(false);
        $driver->expects('data')->never();

        $cached = new WorkerCachedMaintenanceMode($driver, refreshInterval: -1);

        $this->assertFalse($cached->active());

        CarbonImmutable::setTestNow($now->addYear());

        $this->assertFalse($cached->active());
    }

    public function testFlushCacheResetsSnapshot(): void
    {
        $driver = Double::for(MaintenanceModeContract::class);
        $driver->expects('active')->times(2)->returns(true, false);
        $driver->expects('data')->returns(['status' => 503]);

        $cached = new WorkerCachedMaintenanceMode($driver);

        $this->assertTrue($cached->active());

        WorkerCachedMaintenanceMode::flushCache();

        $this->assertFalse($cached->active());
    }

    public function testFlushCacheForcesRefreshWithinInterval(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::parse('2026-01-01 00:00:00'));

        $driver = Double::for(MaintenanceModeContract::class);
        $driver->expects('active')->times(2)->returns(false, true);
        $driver->expects('data')->returns(['status' => 503]);

        $cached = new WorkerCachedMaintenanceMode($driver, refreshInterval: 60);

        $this->assertFalse($cached->active());

        CarbonImmutable::setTestNow($now->addSecond());
        WorkerCachedMaintenanceMode::flushCache();

        $this->assertTrue($cached->active());
    }

    public function testActivateDelegatesToDriverAndFlushesCache(): void
    {
        $driver = Double::for(MaintenanceModeContract::class);
        $driver->expects('active')->times(2)->returns(false, true);
        $driver->expects('data')->returns(['status' => 503]);
        $driver->expects('activate')->with(['status' => 503]);

        $cached = new WorkerCachedMaintenanceMode($driver);

        $this->assertFalse($cached->active());

        $cached->activate(['status' => 503]);

        $this->assertTrue($cached->active());
    }

    public function testDeactivateDelegatesToDriverAndFlushesCache(): void
    {
        $driver = Double::for(MaintenanceModeContract::class);
        $driver->expects('active')->times(2)->returns(true, false);
        $driver->expects('data')->returns(['status' => 503]);
        $driver->expects('deactivate');

        $cached = new WorkerCachedMaintenanceMode($driver);

        $this->assertTrue($cached->active());

        $cached->deactivate();

        $this->assertFalse($cached->active());
    }

    public function testWhenNotActiveDataReturnsEmptyArrayWithoutCallingDriverData(): void
    {
        $driver = Double::for(MaintenanceModeContract::class);
        $driver->expects('active')->returns(false);
        $driver->expects('data')->never();

        $cached = new WorkerCachedMaintenanceMode($driver);

        $this->assertSame([], $cached->data());
    }

    public function testAfterFlushAndRereadDecoratorReflectsUpdatedState(): void
    {
        $driver = Double::for(MaintenanceModeContract::class);
        $driver->expects('active')->times(2)->returns(true, false);
        $driver->expects('data')->returns(['status' => 503]);

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
