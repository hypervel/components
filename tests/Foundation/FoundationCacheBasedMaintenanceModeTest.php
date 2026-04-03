<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Contracts\Cache\Factory;
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Foundation\CacheBasedMaintenanceMode;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class FoundationCacheBasedMaintenanceModeTest extends TestCase
{
    public function testItDeterminesWhetherMaintenanceModeIsActive()
    {
        $cache = m::mock(Factory::class, Repository::class);
        $cache->shouldReceive('store')->with('store-key')->andReturnSelf();

        $manager = new CacheBasedMaintenanceMode($cache, 'store-key', 'key');

        $cache->shouldReceive('has')->once()->with('key')->andReturnFalse();
        $this->assertFalse($manager->active());

        $cache->shouldReceive('has')->once()->with('key')->andReturnTrue();
        $this->assertTrue($manager->active());
    }

    public function testItRetrievesPayloadFromCache()
    {
        $cache = m::mock(Factory::class, Repository::class);
        $cache->shouldReceive('store')->with('store-key')->andReturnSelf();

        $manager = new CacheBasedMaintenanceMode($cache, 'store-key', 'key');

        $cache->shouldReceive('get')->once()->with('key')->andReturn(['payload']);
        $this->assertSame(['payload'], $manager->data());
    }

    public function testItStoresPayloadInCache()
    {
        $cache = m::spy(Factory::class, Repository::class);
        $cache->shouldReceive('store')->with('store-key')->andReturnSelf();

        $manager = new CacheBasedMaintenanceMode($cache, 'store-key', 'key');
        $manager->activate(['payload']);

        $cache->shouldHaveReceived('put')->once()->with('key', ['payload']);
    }

    public function testItRemovesPayloadFromCache()
    {
        $cache = m::spy(Factory::class, Repository::class);
        $cache->shouldReceive('store')->with('store-key')->andReturnSelf();

        $manager = new CacheBasedMaintenanceMode($cache, 'store-key', 'key');
        $manager->deactivate();

        $cache->shouldHaveReceived('forget')->once()->with('key');
    }
}
