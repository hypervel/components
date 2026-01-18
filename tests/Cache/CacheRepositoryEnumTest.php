<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Contracts\Store;
use Hypervel\Cache\Repository;
use Hypervel\Cache\TaggedCache;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\EventDispatcher\EventDispatcherInterface as Dispatcher;

enum CacheKeyBackedEnum: string
{
    case UserProfile = 'user-profile';
    case Settings = 'settings';
}

enum CacheKeyIntBackedEnum: int
{
    case Counter = 1;
    case Stats = 2;
}

enum CacheKeyUnitEnum
{
    case Dashboard;
    case Analytics;
}

enum CacheTagBackedEnum: string
{
    case Users = 'users';
    case Posts = 'posts';
}

enum CacheTagUnitEnum
{
    case Reports;
    case Exports;
}

/**
 * @internal
 * @coversNothing
 */
class CacheRepositoryEnumTest extends TestCase
{
    public function testGetWithBackedEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('user-profile')->andReturn('cached-value');

        $this->assertSame('cached-value', $repo->get(CacheKeyBackedEnum::UserProfile));
    }

    public function testGetWithUnitEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('Dashboard')->andReturn('dashboard-data');

        $this->assertSame('dashboard-data', $repo->get(CacheKeyUnitEnum::Dashboard));
    }

    public function testGetWithIntBackedEnum(): void
    {
        $repo = $this->getRepository();
        // Int value 1 should be cast to string '1'
        $repo->getStore()->shouldReceive('get')->once()->with('1')->andReturn('counter-value');

        $this->assertSame('counter-value', $repo->get(CacheKeyIntBackedEnum::Counter));
    }

    public function testHasWithBackedEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('user-profile')->andReturn('value');

        $this->assertTrue($repo->has(CacheKeyBackedEnum::UserProfile));
    }

    public function testHasWithUnitEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('Dashboard')->andReturn(null);

        $this->assertFalse($repo->has(CacheKeyUnitEnum::Dashboard));
    }

    public function testMissingWithBackedEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('settings')->andReturn(null);

        $this->assertTrue($repo->missing(CacheKeyBackedEnum::Settings));
    }

    public function testPutWithBackedEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('put')->once()->with('user-profile', 'value', 60)->andReturn(true);

        $this->assertTrue($repo->put(CacheKeyBackedEnum::UserProfile, 'value', 60));
    }

    public function testPutWithUnitEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('put')->once()->with('Dashboard', 'data', 120)->andReturn(true);

        $this->assertTrue($repo->put(CacheKeyUnitEnum::Dashboard, 'data', 120));
    }

    public function testSetWithBackedEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('put')->once()->with('settings', 'config', 300)->andReturn(true);

        $this->assertTrue($repo->set(CacheKeyBackedEnum::Settings, 'config', 300));
    }

    public function testAddWithBackedEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('user-profile')->andReturn(null);
        $repo->getStore()->shouldReceive('put')->once()->with('user-profile', 'new-value', 60)->andReturn(true);

        $this->assertTrue($repo->add(CacheKeyBackedEnum::UserProfile, 'new-value', 60));
    }

    public function testAddWithUnitEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('Analytics')->andReturn(null);
        $repo->getStore()->shouldReceive('put')->once()->with('Analytics', 'data', 60)->andReturn(true);

        $this->assertTrue($repo->add(CacheKeyUnitEnum::Analytics, 'data', 60));
    }

    public function testIncrementWithBackedEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('increment')->once()->with('user-profile', 1)->andReturn(2);

        $this->assertSame(2, $repo->increment(CacheKeyBackedEnum::UserProfile));
    }

    public function testIncrementWithUnitEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('increment')->once()->with('Dashboard', 5)->andReturn(10);

        $this->assertSame(10, $repo->increment(CacheKeyUnitEnum::Dashboard, 5));
    }

    public function testDecrementWithBackedEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('decrement')->once()->with('settings', 1)->andReturn(4);

        $this->assertSame(4, $repo->decrement(CacheKeyBackedEnum::Settings));
    }

    public function testDecrementWithUnitEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('decrement')->once()->with('Analytics', 3)->andReturn(7);

        $this->assertSame(7, $repo->decrement(CacheKeyUnitEnum::Analytics, 3));
    }

    public function testForeverWithBackedEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('forever')->once()->with('user-profile', 'permanent')->andReturn(true);

        $this->assertTrue($repo->forever(CacheKeyBackedEnum::UserProfile, 'permanent'));
    }

    public function testForeverWithUnitEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('forever')->once()->with('Dashboard', 'forever-data')->andReturn(true);

        $this->assertTrue($repo->forever(CacheKeyUnitEnum::Dashboard, 'forever-data'));
    }

    public function testRememberWithBackedEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('settings')->andReturn(null);
        $repo->getStore()->shouldReceive('put')->once()->with('settings', 'computed', 60)->andReturn(true);

        $result = $repo->remember(CacheKeyBackedEnum::Settings, 60, fn () => 'computed');

        $this->assertSame('computed', $result);
    }

    public function testRememberWithUnitEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('Analytics')->andReturn(null);
        $repo->getStore()->shouldReceive('put')->once()->with('Analytics', 'analytics-data', 120)->andReturn(true);

        $result = $repo->remember(CacheKeyUnitEnum::Analytics, 120, fn () => 'analytics-data');

        $this->assertSame('analytics-data', $result);
    }

    public function testRememberForeverWithBackedEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('user-profile')->andReturn(null);
        $repo->getStore()->shouldReceive('forever')->once()->with('user-profile', 'forever-value')->andReturn(true);

        $result = $repo->rememberForever(CacheKeyBackedEnum::UserProfile, fn () => 'forever-value');

        $this->assertSame('forever-value', $result);
    }

    public function testSearWithUnitEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('Dashboard')->andReturn(null);
        $repo->getStore()->shouldReceive('forever')->once()->with('Dashboard', 'seared')->andReturn(true);

        $result = $repo->sear(CacheKeyUnitEnum::Dashboard, fn () => 'seared');

        $this->assertSame('seared', $result);
    }

    public function testForgetWithBackedEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('forget')->once()->with('user-profile')->andReturn(true);

        $this->assertTrue($repo->forget(CacheKeyBackedEnum::UserProfile));
    }

    public function testForgetWithUnitEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('forget')->once()->with('Dashboard')->andReturn(true);

        $this->assertTrue($repo->forget(CacheKeyUnitEnum::Dashboard));
    }

    public function testDeleteWithBackedEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('forget')->once()->with('settings')->andReturn(true);

        $this->assertTrue($repo->delete(CacheKeyBackedEnum::Settings));
    }

    public function testPullWithBackedEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('user-profile')->andReturn('pulled-value');
        $repo->getStore()->shouldReceive('forget')->once()->with('user-profile')->andReturn(true);

        $this->assertSame('pulled-value', $repo->pull(CacheKeyBackedEnum::UserProfile));
    }

    public function testPullWithUnitEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->shouldReceive('get')->once()->with('Analytics')->andReturn('analytics');
        $repo->getStore()->shouldReceive('forget')->once()->with('Analytics')->andReturn(true);

        $this->assertSame('analytics', $repo->pull(CacheKeyUnitEnum::Analytics));
    }

    public function testBackedEnumAndStringInteroperability(): void
    {
        $repo = new Repository(new ArrayStore());

        // Store with enum
        $repo->put(CacheKeyBackedEnum::UserProfile, 'enum-stored', 60);

        // Retrieve with string (the enum value)
        $this->assertSame('enum-stored', $repo->get('user-profile'));

        // Store with string
        $repo->put('settings', 'string-stored', 60);

        // Retrieve with enum
        $this->assertSame('string-stored', $repo->get(CacheKeyBackedEnum::Settings));
    }

    public function testUnitEnumAndStringInteroperability(): void
    {
        $repo = new Repository(new ArrayStore());

        // Store with enum
        $repo->put(CacheKeyUnitEnum::Dashboard, 'enum-stored', 60);

        // Retrieve with string (the enum name)
        $this->assertSame('enum-stored', $repo->get('Dashboard'));

        // Store with string
        $repo->put('Analytics', 'string-stored', 60);

        // Retrieve with enum
        $this->assertSame('string-stored', $repo->get(CacheKeyUnitEnum::Analytics));
    }

    public function testTagsWithBackedEnumArray(): void
    {
        $repo = new Repository(new ArrayStore());

        $tagged = $repo->tags([CacheTagBackedEnum::Users, CacheTagBackedEnum::Posts]);

        $this->assertInstanceOf(TaggedCache::class, $tagged);
        $this->assertEquals(['users', 'posts'], $tagged->getTags()->getNames());
    }

    public function testTagsWithUnitEnumArray(): void
    {
        $repo = new Repository(new ArrayStore());

        $tagged = $repo->tags([CacheTagUnitEnum::Reports, CacheTagUnitEnum::Exports]);

        $this->assertInstanceOf(TaggedCache::class, $tagged);
        $this->assertEquals(['Reports', 'Exports'], $tagged->getTags()->getNames());
    }

    public function testTagsWithMixedEnumsAndStrings(): void
    {
        $repo = new Repository(new ArrayStore());

        $tagged = $repo->tags([CacheTagBackedEnum::Users, 'custom-tag', CacheTagUnitEnum::Reports]);

        $this->assertInstanceOf(TaggedCache::class, $tagged);
        $this->assertEquals(['users', 'custom-tag', 'Reports'], $tagged->getTags()->getNames());
    }

    public function testTagsWithBackedEnumVariadicArgs(): void
    {
        $store = m::mock(ArrayStore::class);
        $repo = new Repository($store);

        $taggedCache = m::mock(TaggedCache::class);
        $taggedCache->shouldReceive('setDefaultCacheTime')->andReturnSelf();
        $store->shouldReceive('tags')->once()->with(['users', 'posts'])->andReturn($taggedCache);

        $repo->tags(CacheTagBackedEnum::Users, CacheTagBackedEnum::Posts);
    }

    public function testTagsWithUnitEnumVariadicArgs(): void
    {
        $store = m::mock(ArrayStore::class);
        $repo = new Repository($store);

        $taggedCache = m::mock(TaggedCache::class);
        $taggedCache->shouldReceive('setDefaultCacheTime')->andReturnSelf();
        $store->shouldReceive('tags')->once()->with(['Reports', 'Exports'])->andReturn($taggedCache);

        $repo->tags(CacheTagUnitEnum::Reports, CacheTagUnitEnum::Exports);
    }

    public function testTaggedCacheOperationsWithEnumKeys(): void
    {
        $repo = new Repository(new ArrayStore());

        $tagged = $repo->tags([CacheTagBackedEnum::Users]);

        // Put with enum key
        $tagged->put(CacheKeyBackedEnum::UserProfile, 'tagged-value', 60);

        // Get with enum key
        $this->assertSame('tagged-value', $tagged->get(CacheKeyBackedEnum::UserProfile));

        // Get with string key (interoperability)
        $this->assertSame('tagged-value', $tagged->get('user-profile'));
    }

    public function testOffsetAccessWithBackedEnum(): void
    {
        $repo = new Repository(new ArrayStore());

        // offsetSet with enum
        $repo[CacheKeyBackedEnum::UserProfile] = 'offset-value';

        // offsetGet with enum
        $this->assertSame('offset-value', $repo[CacheKeyBackedEnum::UserProfile]);

        // offsetExists with enum
        $this->assertTrue(isset($repo[CacheKeyBackedEnum::UserProfile]));

        // offsetUnset with enum
        unset($repo[CacheKeyBackedEnum::UserProfile]);
        $this->assertFalse(isset($repo[CacheKeyBackedEnum::UserProfile]));
    }

    public function testOffsetAccessWithUnitEnum(): void
    {
        $repo = new Repository(new ArrayStore());

        $repo[CacheKeyUnitEnum::Dashboard] = 'dashboard-data';

        $this->assertSame('dashboard-data', $repo[CacheKeyUnitEnum::Dashboard]);
        $this->assertTrue(isset($repo[CacheKeyUnitEnum::Dashboard]));
    }

    protected function getRepository(): Repository
    {
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->with(m::any())->andReturnNull();
        $repository = new Repository(m::mock(Store::class));

        $repository->setEventDispatcher($dispatcher);

        return $repository;
    }
}
