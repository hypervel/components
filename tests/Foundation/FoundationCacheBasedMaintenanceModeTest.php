<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Contracts\Cache\Factory;
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Foundation\CacheBasedMaintenanceMode;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use RuntimeException;

class FoundationCacheBasedMaintenanceModeTest extends TestCase
{
    use VerifiesDoubles;

    public function testItDeterminesWhetherMaintenanceModeIsActive(): void
    {
        $cache = Double::for(Factory::class, Repository::class)->strict();
        $cache->expects('store')->times(2)->with('store-key')->returns($cache);

        $manager = new CacheBasedMaintenanceMode($cache, 'store-key', 'key');

        $cache->expects('has')->with('key')->times(2)->returns(false, true);
        $this->assertFalse($manager->active());

        $this->assertTrue($manager->active());
    }

    public function testItRetrievesPayloadFromCache(): void
    {
        $cache = Double::for(Factory::class, Repository::class)->strict();
        $cache->expects('store')->with('store-key')->returns($cache);

        $manager = new CacheBasedMaintenanceMode($cache, 'store-key', 'key');

        $cache->expects('get')->with('key')->returns(['payload']);
        $this->assertSame(['payload'], $manager->data());
    }

    public function testItReturnsEmptyPayloadWhenCacheKeyIsMissing(): void
    {
        $cache = Double::for(Factory::class, Repository::class)->strict();
        $cache->expects('store')->with('store-key')->returns($cache);

        $manager = new CacheBasedMaintenanceMode($cache, 'store-key', 'key');

        $cache->expects('get')->with('key')->returns(null);
        $this->assertSame([], $manager->data());
    }

    public function testItStoresPayloadInCache(): void
    {
        $cache = Double::for(Factory::class, Repository::class)->strict();
        $cache->expects('store')->with('store-key')->returns($cache);
        $cache->expects('put')->with('key', ['payload'])->returns(true);

        $manager = new CacheBasedMaintenanceMode($cache, 'store-key', 'key');
        $manager->activate(['payload']);
    }

    public function testItRemovesPayloadFromCache(): void
    {
        $cache = Double::for(Factory::class, Repository::class)->strict();
        $cache->expects('store')->with('store-key')->returns($cache);
        $cache->expects('forget')->with('key')->returns(true);

        $manager = new CacheBasedMaintenanceMode($cache, 'store-key', 'key');
        $manager->deactivate();
    }

    public function testItFailsWhenPayloadCannotBeStored(): void
    {
        $cache = Double::for(Factory::class, Repository::class)->strict();
        $cache->expects('store')->with('store-key')->returns($cache);
        $cache->expects('put')->with('key', ['payload'])->returns(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unable to activate maintenance mode using cache key [key].');

        (new CacheBasedMaintenanceMode($cache, 'store-key', 'key'))->activate(['payload']);
    }

    public function testItFailsWhenPayloadCannotBeRemoved(): void
    {
        $cache = Double::for(Factory::class, Repository::class)->strict();
        $cache->expects('store')->with('store-key')->returns($cache);
        $cache->expects('forget')->with('key')->returns(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('Unable to deactivate maintenance mode using cache key [key].');

        (new CacheBasedMaintenanceMode($cache, 'store-key', 'key'))->deactivate();
    }
}
