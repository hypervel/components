<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Contracts\Cache\Factory;
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Foundation\CacheBasedMaintenanceMode;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

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

    public function testItReturnsEmptyPayloadWhenCacheKeyIsMissing(): void
    {
        $cache = m::mock(Factory::class, Repository::class);
        $cache->shouldReceive('store')->with('store-key')->andReturnSelf();

        $manager = new CacheBasedMaintenanceMode($cache, 'store-key', 'key');

        $cache->shouldReceive('get')->once()->with('key')->andReturnNull();
        $this->assertSame([], $manager->data());
    }

    public function testItStoresPayloadInCache(): void
    {
        $cache = m::mock(Factory::class, Repository::class);
        $cache->shouldReceive('store')->with('store-key')->andReturnSelf();
        $cache->shouldReceive('put')->once()->with('key', ['payload'])->andReturnTrue();

        $manager = new CacheBasedMaintenanceMode($cache, 'store-key', 'key');
        $manager->activate(['payload']);
    }

    public function testItRemovesPayloadFromCache(): void
    {
        $cache = m::mock(Factory::class, Repository::class);
        $cache->shouldReceive('store')->with('store-key')->andReturnSelf();
        $cache->shouldReceive('forget')->once()->with('key')->andReturnTrue();

        $manager = new CacheBasedMaintenanceMode($cache, 'store-key', 'key');
        $manager->deactivate();
    }

    public function testItFailsWhenPayloadCannotBeStored(): void
    {
        $cache = m::mock(Factory::class, Repository::class);
        $cache->shouldReceive('store')->with('store-key')->andReturnSelf();
        $cache->shouldReceive('put')->once()->with('key', ['payload'])->andReturnFalse();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to activate maintenance mode using cache key [key].');

        (new CacheBasedMaintenanceMode($cache, 'store-key', 'key'))->activate(['payload']);
    }

    public function testItFailsWhenPayloadCannotBeRemoved(): void
    {
        $cache = m::mock(Factory::class, Repository::class);
        $cache->shouldReceive('store')->with('store-key')->andReturnSelf();
        $cache->shouldReceive('forget')->once()->with('key')->andReturnFalse();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to deactivate maintenance mode using cache key [key].');

        (new CacheBasedMaintenanceMode($cache, 'store-key', 'key'))->deactivate();
    }
}
