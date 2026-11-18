<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Events\CacheHit;
use Hypervel\Cache\Repository;
use Hypervel\Cache\TaggedCache;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use stdClass;

enum CacheRepositoryEnumTestKeyBackedEnum: string
{
    case UserProfile = 'user-profile';
    case Settings = 'settings';
}

enum CacheRepositoryEnumTestKeyIntBackedEnum: int
{
    case Zero = 0;
    case Counter = 1;
    case Stats = 2;
}

enum CacheRepositoryEnumTestKeyUnitEnum
{
    case Dashboard;
    case Analytics;
}

enum CacheRepositoryEnumTestTagBackedEnum: string
{
    case Users = 'users';
    case Posts = 'posts';
}

enum CacheRepositoryEnumTestTagUnitEnum
{
    case Reports;
    case Exports;
}

enum CacheRepositoryEnumTestTagIntBackedEnum: int
{
    case Zero = 0;
    case Reports = 1;
}

class CacheRepositoryEnumTest extends TestCase
{
    use VerifiesDoubles;

    public function testGetWithBackedEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->expects('get')->with('user-profile')->returns('cached-value');

        $this->assertSame('cached-value', $repo->get(CacheRepositoryEnumTestKeyBackedEnum::UserProfile));
    }

    public function testGetWithUnitEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->expects('get')->with('Dashboard')->returns('dashboard-data');

        $this->assertSame('dashboard-data', $repo->get(CacheRepositoryEnumTestKeyUnitEnum::Dashboard));
    }

    public function testGetWithIntBackedEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->expects('get')->with('1')->returns('counter-value');

        $this->assertSame('counter-value', $repo->get(CacheRepositoryEnumTestKeyIntBackedEnum::Counter));
    }

    public function testZeroBackedEnumAndStringInteroperability(): void
    {
        $repo = new Repository(new ArrayStore);

        $repo->put(CacheRepositoryEnumTestKeyIntBackedEnum::Zero, 'zero-value', 60);

        $this->assertSame('zero-value', $repo->get('0'));
        $this->assertSame('zero-value', $repo->get(CacheRepositoryEnumTestKeyIntBackedEnum::Zero));
    }

    public function testIntegerBackedEnumIdentifiersWorkAcrossRepositoryOperations(): void
    {
        $repo = new Repository(new ArrayStore);

        $this->assertTrue($repo->add(CacheRepositoryEnumTestKeyIntBackedEnum::Counter, 1, 60));
        $this->assertSame(2, $repo->increment(CacheRepositoryEnumTestKeyIntBackedEnum::Counter));
        $this->assertSame(1, $repo->decrement(CacheRepositoryEnumTestKeyIntBackedEnum::Counter));
        $this->assertTrue($repo->touch(CacheRepositoryEnumTestKeyIntBackedEnum::Counter, 60));
        $this->assertSame(1, $repo->pull(CacheRepositoryEnumTestKeyIntBackedEnum::Counter));

        $this->assertSame('remembered', $repo->remember(
            CacheRepositoryEnumTestKeyIntBackedEnum::Stats,
            60,
            fn () => 'remembered'
        ));

        $this->assertSame('flexible', $repo->flexible(
            CacheRepositoryEnumTestKeyIntBackedEnum::Zero,
            [60, 120],
            fn () => 'flexible'
        ));

        $this->assertSame('locked', $repo->withoutOverlapping(
            CacheRepositoryEnumTestKeyIntBackedEnum::Zero,
            fn () => 'locked'
        ));
    }

    public function testIntegerBackedEnumTypedGetterThrowsTheIntendedDiagnostic(): void
    {
        $repo = new Repository(new ArrayStore);
        $repo->put(CacheRepositoryEnumTestKeyIntBackedEnum::Counter, new stdClass, 60);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Cache value for key [1] must be a string, object given.');

        $repo->string(CacheRepositoryEnumTestKeyIntBackedEnum::Counter);
    }

    public function testGetMultiplePreservesNumericEnumIdentifiersAndDefaults(): void
    {
        $repo = new Repository(new ArrayStore);
        $repo->put('1', 'stored', 60);

        $this->assertSame([
            1 => 'stored',
            0 => 'fallback',
        ], $repo->getMultiple([
            CacheRepositoryEnumTestKeyIntBackedEnum::Counter,
            CacheRepositoryEnumTestKeyIntBackedEnum::Zero,
        ], 'fallback'));
    }

    public function testArrayStoreManyReadsNumericStringKeys(): void
    {
        $store = new ArrayStore;
        $store->put('0', 'zero', 60);
        $store->put('1', 'one', 60);

        $this->assertSame([
            0 => 'zero',
            1 => 'one',
        ], $store->many(['0', '1']));
    }

    public function testCacheEventsNormalizeIntegerBackedEnumKeys(): void
    {
        $event = new CacheHit('array', CacheRepositoryEnumTestKeyIntBackedEnum::Zero, 'value');

        $this->assertSame('0', $event->key);
    }

    public function testHasWithBackedEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->expects('get')->with('user-profile')->returns('value');

        $this->assertTrue($repo->has(CacheRepositoryEnumTestKeyBackedEnum::UserProfile));
    }

    public function testHasWithUnitEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->expects('get')->with('Dashboard')->returns(null);

        $this->assertFalse($repo->has(CacheRepositoryEnumTestKeyUnitEnum::Dashboard));
    }

    public function testMissingWithBackedEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->expects('get')->with('settings')->returns(null);

        $this->assertTrue($repo->missing(CacheRepositoryEnumTestKeyBackedEnum::Settings));
    }

    public function testPutWithBackedEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->expects('put')->with('user-profile', 'value', 60)->returns(true);

        $this->assertTrue($repo->put(CacheRepositoryEnumTestKeyBackedEnum::UserProfile, 'value', 60));
    }

    public function testPutWithUnitEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->expects('put')->with('Dashboard', 'data', 120)->returns(true);

        $this->assertTrue($repo->put(CacheRepositoryEnumTestKeyUnitEnum::Dashboard, 'data', 120));
    }

    public function testSetWithBackedEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->expects('put')->with('settings', 'config', 300)->returns(true);

        $this->assertTrue($repo->set(CacheRepositoryEnumTestKeyBackedEnum::Settings, 'config', 300));
    }

    public function testAddWithBackedEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->expects('get')->with('user-profile')->returns(null);
        $repo->getStore()->expects('put')->with('user-profile', 'new-value', 60)->returns(true);

        $this->assertTrue($repo->add(CacheRepositoryEnumTestKeyBackedEnum::UserProfile, 'new-value', 60));
    }

    public function testAddWithUnitEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->expects('get')->with('Analytics')->returns(null);
        $repo->getStore()->expects('put')->with('Analytics', 'data', 60)->returns(true);

        $this->assertTrue($repo->add(CacheRepositoryEnumTestKeyUnitEnum::Analytics, 'data', 60));
    }

    public function testIncrementWithBackedEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->expects('increment')->with('user-profile', 1)->returns(2);

        $this->assertSame(2, $repo->increment(CacheRepositoryEnumTestKeyBackedEnum::UserProfile));
    }

    public function testIncrementWithUnitEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->expects('increment')->with('Dashboard', 5)->returns(10);

        $this->assertSame(10, $repo->increment(CacheRepositoryEnumTestKeyUnitEnum::Dashboard, 5));
    }

    public function testDecrementWithBackedEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->expects('decrement')->with('settings', 1)->returns(4);

        $this->assertSame(4, $repo->decrement(CacheRepositoryEnumTestKeyBackedEnum::Settings));
    }

    public function testDecrementWithUnitEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->expects('decrement')->with('Analytics', 3)->returns(7);

        $this->assertSame(7, $repo->decrement(CacheRepositoryEnumTestKeyUnitEnum::Analytics, 3));
    }

    public function testForeverWithBackedEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->expects('forever')->with('user-profile', 'permanent')->returns(true);

        $this->assertTrue($repo->forever(CacheRepositoryEnumTestKeyBackedEnum::UserProfile, 'permanent'));
    }

    public function testForeverWithUnitEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->expects('forever')->with('Dashboard', 'forever-data')->returns(true);

        $this->assertTrue($repo->forever(CacheRepositoryEnumTestKeyUnitEnum::Dashboard, 'forever-data'));
    }

    public function testRememberWithBackedEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->expects('get')->with('settings')->returns(null);
        $repo->getStore()->expects('put')->with('settings', 'computed', 60)->returns(true);

        $result = $repo->remember(CacheRepositoryEnumTestKeyBackedEnum::Settings, 60, fn () => 'computed');

        $this->assertSame('computed', $result);
    }

    public function testRememberWithUnitEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->expects('get')->with('Analytics')->returns(null);
        $repo->getStore()->expects('put')->with('Analytics', 'analytics-data', 120)->returns(true);

        $result = $repo->remember(CacheRepositoryEnumTestKeyUnitEnum::Analytics, 120, fn () => 'analytics-data');

        $this->assertSame('analytics-data', $result);
    }

    public function testRememberForeverWithBackedEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->expects('get')->with('user-profile')->returns(null);
        $repo->getStore()->expects('forever')->with('user-profile', 'forever-value')->returns(true);

        $result = $repo->rememberForever(CacheRepositoryEnumTestKeyBackedEnum::UserProfile, fn () => 'forever-value');

        $this->assertSame('forever-value', $result);
    }

    public function testSearWithUnitEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->expects('get')->with('Dashboard')->returns(null);
        $repo->getStore()->expects('forever')->with('Dashboard', 'seared')->returns(true);

        $result = $repo->sear(CacheRepositoryEnumTestKeyUnitEnum::Dashboard, fn () => 'seared');

        $this->assertSame('seared', $result);
    }

    public function testForgetWithBackedEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->expects('forget')->with('user-profile')->returns(true);

        $this->assertTrue($repo->forget(CacheRepositoryEnumTestKeyBackedEnum::UserProfile));
    }

    public function testForgetWithUnitEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->expects('forget')->with('Dashboard')->returns(true);

        $this->assertTrue($repo->forget(CacheRepositoryEnumTestKeyUnitEnum::Dashboard));
    }

    public function testDeleteWithBackedEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->expects('forget')->with('settings')->returns(true);

        $this->assertTrue($repo->delete(CacheRepositoryEnumTestKeyBackedEnum::Settings));
    }

    public function testPullWithBackedEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->expects('get')->with('user-profile')->returns('pulled-value');
        $repo->getStore()->expects('forget')->with('user-profile')->returns(true);

        $this->assertSame('pulled-value', $repo->pull(CacheRepositoryEnumTestKeyBackedEnum::UserProfile));
    }

    public function testPullWithUnitEnum(): void
    {
        $repo = $this->getRepository();
        $repo->getStore()->expects('get')->with('Analytics')->returns('analytics');
        $repo->getStore()->expects('forget')->with('Analytics')->returns(true);

        $this->assertSame('analytics', $repo->pull(CacheRepositoryEnumTestKeyUnitEnum::Analytics));
    }

    public function testBackedEnumAndStringInteroperability(): void
    {
        $repo = new Repository(new ArrayStore);

        // Store with enum
        $repo->put(CacheRepositoryEnumTestKeyBackedEnum::UserProfile, 'enum-stored', 60);

        // Retrieve with string (the enum value)
        $this->assertSame('enum-stored', $repo->get('user-profile'));

        // Store with string
        $repo->put('settings', 'string-stored', 60);

        // Retrieve with enum
        $this->assertSame('string-stored', $repo->get(CacheRepositoryEnumTestKeyBackedEnum::Settings));
    }

    public function testUnitEnumAndStringInteroperability(): void
    {
        $repo = new Repository(new ArrayStore);

        // Store with enum
        $repo->put(CacheRepositoryEnumTestKeyUnitEnum::Dashboard, 'enum-stored', 60);

        // Retrieve with string (the enum name)
        $this->assertSame('enum-stored', $repo->get('Dashboard'));

        // Store with string
        $repo->put('Analytics', 'string-stored', 60);

        // Retrieve with enum
        $this->assertSame('string-stored', $repo->get(CacheRepositoryEnumTestKeyUnitEnum::Analytics));
    }

    public function testTagsWithBackedEnumArray(): void
    {
        $repo = new Repository(new ArrayStore);

        $tagged = $repo->tags([CacheRepositoryEnumTestTagBackedEnum::Users, CacheRepositoryEnumTestTagBackedEnum::Posts]);

        $this->assertInstanceOf(TaggedCache::class, $tagged);
        $this->assertEquals(['users', 'posts'], $tagged->getTags()->getNames());
    }

    public function testTagsWithUnitEnumArray(): void
    {
        $repo = new Repository(new ArrayStore);

        $tagged = $repo->tags([CacheRepositoryEnumTestTagUnitEnum::Reports, CacheRepositoryEnumTestTagUnitEnum::Exports]);

        $this->assertInstanceOf(TaggedCache::class, $tagged);
        $this->assertEquals(['Reports', 'Exports'], $tagged->getTags()->getNames());
    }

    public function testTagsWithIntegerBackedEnumArray(): void
    {
        $repo = new Repository(new ArrayStore);

        $tagged = $repo->tags([
            CacheRepositoryEnumTestTagIntBackedEnum::Zero,
            CacheRepositoryEnumTestTagIntBackedEnum::Reports,
        ]);

        $this->assertSame(['0', '1'], $tagged->getTags()->getNames());
    }

    public function testTagsWithMixedEnumsAndStrings(): void
    {
        $repo = new Repository(new ArrayStore);

        $tagged = $repo->tags([CacheRepositoryEnumTestTagBackedEnum::Users, 'custom-tag', CacheRepositoryEnumTestTagUnitEnum::Reports]);

        $this->assertInstanceOf(TaggedCache::class, $tagged);
        $this->assertEquals(['users', 'custom-tag', 'Reports'], $tagged->getTags()->getNames());
    }

    public function testTagsWithBackedEnumVariadicArgs(): void
    {
        $store = Double::for(ArrayStore::class)->strict();
        $repo = new Repository($store);

        $taggedCache = Double::for(TaggedCache::class)->strict();
        $taggedCache->allows('setDefaultCacheTime');
        $store->expects('tags')->with(['users', 'posts'])->returns($taggedCache);

        $repo->tags(CacheRepositoryEnumTestTagBackedEnum::Users, CacheRepositoryEnumTestTagBackedEnum::Posts);
    }

    public function testTagsWithUnitEnumVariadicArgs(): void
    {
        $store = Double::for(ArrayStore::class)->strict();
        $repo = new Repository($store);

        $taggedCache = Double::for(TaggedCache::class)->strict();
        $taggedCache->allows('setDefaultCacheTime');
        $store->expects('tags')->with(['Reports', 'Exports'])->returns($taggedCache);

        $repo->tags(CacheRepositoryEnumTestTagUnitEnum::Reports, CacheRepositoryEnumTestTagUnitEnum::Exports);
    }

    public function testTaggedCacheOperationsWithEnumKeys(): void
    {
        $repo = new Repository(new ArrayStore);

        $tagged = $repo->tags([CacheRepositoryEnumTestTagBackedEnum::Users]);

        // Put with enum key
        $tagged->put(CacheRepositoryEnumTestKeyBackedEnum::UserProfile, 'tagged-value', 60);

        // Get with enum key
        $this->assertSame('tagged-value', $tagged->get(CacheRepositoryEnumTestKeyBackedEnum::UserProfile));

        // Get with string key (interoperability)
        $this->assertSame('tagged-value', $tagged->get('user-profile'));
    }

    public function testOffsetAccessWithBackedEnum(): void
    {
        $repo = new Repository(new ArrayStore);

        // offsetSet with enum
        $repo[CacheRepositoryEnumTestKeyBackedEnum::UserProfile] = 'offset-value';

        // offsetGet with enum
        $this->assertSame('offset-value', $repo[CacheRepositoryEnumTestKeyBackedEnum::UserProfile]);

        // offsetExists with enum
        $this->assertTrue(isset($repo[CacheRepositoryEnumTestKeyBackedEnum::UserProfile]));

        // offsetUnset with enum
        unset($repo[CacheRepositoryEnumTestKeyBackedEnum::UserProfile]);
        $this->assertFalse(isset($repo[CacheRepositoryEnumTestKeyBackedEnum::UserProfile]));
    }

    public function testOffsetAccessWithUnitEnum(): void
    {
        $repo = new Repository(new ArrayStore);

        $repo[CacheRepositoryEnumTestKeyUnitEnum::Dashboard] = 'dashboard-data';

        $this->assertSame('dashboard-data', $repo[CacheRepositoryEnumTestKeyUnitEnum::Dashboard]);
        $this->assertTrue(isset($repo[CacheRepositoryEnumTestKeyUnitEnum::Dashboard]));
    }

    protected function getRepository(): Repository
    {
        $dispatcher = Double::for(Dispatcher::class)->strict();
        $dispatcher->allows('hasListeners')->returns(true);
        $dispatcher->allows('dispatch')->with(Argument::any())->returns(null);
        $repository = new Repository(Double::for(Store::class)->strict());

        $repository->setEventDispatcher($dispatcher);

        return $repository;
    }
}
