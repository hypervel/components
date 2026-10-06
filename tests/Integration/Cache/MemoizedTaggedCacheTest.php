<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache;

use BadMethodCallException;
use Hypervel\Cache\Events\CacheEvent;
use Hypervel\Cache\Events\CacheFlushed;
use Hypervel\Cache\Events\CacheFlushing;
use Hypervel\Cache\Events\CacheHit;
use Hypervel\Cache\Events\CacheMissed;
use Hypervel\Cache\Events\RetrievingKey;
use Hypervel\Cache\TagMode;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Foundation\Testing\Concerns\RequiresHashFieldExpiration;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;

#[WithConfig('cache.default', 'redis')]
class MemoizedTaggedCacheTest extends TestCase
{
    use InteractsWithRedis;
    use RequiresHashFieldExpiration;

    public function testItCanMemoizeWithTagsWhenRetrievingSingleValue(): void
    {
        Cache::tags(['foo', 'bar'])->put('name', 'Tim', 60);

        $live = Cache::tags(['foo', 'bar'])->get('name');
        $memoized = Cache::memo()->tags(['foo', 'bar'])->get('name');
        $this->assertSame('Tim', $live);
        $this->assertSame('Tim', $memoized);

        Cache::tags(['foo', 'bar'])->put('name', 'Taylor', 60);

        $live = Cache::tags(['foo', 'bar'])->get('name');
        $memoized = Cache::memo()->tags(['foo', 'bar'])->get('name');
        $this->assertSame('Taylor', $live);
        $this->assertSame('Tim', $memoized);
    }

    public function testItCanMemoizeWithTagsWhenRetrievingMultipleValues(): void
    {
        Cache::tags(['foo', 'bar'])->put('name.0', 'Tim', 60);
        Cache::tags(['foo', 'bar'])->put('name.1', 'Taylor', 60);

        $live = Cache::tags(['foo', 'bar'])->getMultiple(['name.0', 'name.1']);
        $memoized = Cache::memo()->tags(['foo', 'bar'])->getMultiple(['name.0', 'name.1']);
        $this->assertSame(['name.0' => 'Tim', 'name.1' => 'Taylor'], $live);
        $this->assertSame(['name.0' => 'Tim', 'name.1' => 'Taylor'], $memoized);

        Cache::tags(['foo', 'bar'])->put('name.0', 'MacDonald', 60);
        Cache::tags(['foo', 'bar'])->put('name.1', 'Otwell', 60);

        $live = Cache::tags(['foo', 'bar'])->getMultiple(['name.0', 'name.1']);
        $memoized = Cache::memo()->tags(['foo', 'bar'])->getMultiple(['name.0', 'name.1']);
        $this->assertSame(['name.0' => 'MacDonald', 'name.1' => 'Otwell'], $live);
        $this->assertSame(['name.0' => 'Tim', 'name.1' => 'Taylor'], $memoized);
    }

    public function testItCanFlushMemoizedValuesWithTags(): void
    {
        Cache::tags(['foo', 'bar'])->put('name.0', 'Tim', 60);
        Cache::tags(['foo', 'bar'])->put('name.1', 'Taylor', 60);

        $live = Cache::tags(['foo', 'bar'])->getMultiple(['name.0', 'name.1']);
        $memoized = Cache::memo()->tags(['foo', 'bar'])->getMultiple(['name.0', 'name.1']);
        $this->assertSame(['name.0' => 'Tim', 'name.1' => 'Taylor'], $live);
        $this->assertSame(['name.0' => 'Tim', 'name.1' => 'Taylor'], $memoized);

        Cache::tags(['foo', 'bar'])->flush();

        $live = Cache::tags(['foo', 'bar'])->getMultiple(['name.0', 'name.1']);
        $memoized = Cache::memo()->tags(['foo', 'bar'])->getMultiple(['name.0', 'name.1']);
        $this->assertSame(['name.0' => null, 'name.1' => null], $live);
        $this->assertSame(['name.0' => 'Tim', 'name.1' => 'Taylor'], $memoized);

        Cache::memo()->tags(['foo', 'bar'])->flush();
        $memoized = Cache::memo()->tags(['foo', 'bar'])->getMultiple(['name.0', 'name.1']);
        $this->assertSame(['name.0' => null, 'name.1' => null], $memoized);
    }

    public function testFlushingTagsForgetsValuesMemoizedByOverlappingTagSets(): void
    {
        Cache::tags(['foo'])->put('name', 'Tim', 60);
        Cache::tags(['foo', 'bar'])->put('name', 'Taylor', 60);

        $foo = Cache::memo()->tags(['foo']);
        $fooAndBar = Cache::memo()->tags(['foo', 'bar']);

        $this->assertSame('Tim', $foo->get('name'));
        $this->assertSame('Taylor', $fooAndBar->get('name'));

        $foo->flush();

        $this->assertNull($fooAndBar->get('name'));
    }

    public function testItCanForgetMemoizedValuesWithTags(): void
    {
        Cache::tags(['foo', 'bar'])->put('name', 'Tim', 60);

        $live = Cache::tags(['foo', 'bar'])->get('name');
        $memoized = Cache::memo()->tags(['foo', 'bar'])->get('name');
        $this->assertSame('Tim', $live);
        $this->assertSame('Tim', $memoized);

        Cache::memo()->tags(['foo', 'bar'])->forget('name');

        $live = Cache::tags(['foo', 'bar'])->get('name');
        $memoized = Cache::memo()->tags(['foo', 'bar'])->get('name');
        $this->assertNull($live);
        $this->assertNull($memoized);
    }

    public function testItCanForgetMultipleMemoizedValuesWithTags(): void
    {
        Cache::tags(['foo'])->putMany(['first' => 'Tim', 'last' => 'MacDonald'], 60);
        Cache::memo()->tags(['foo'])->get(['first', 'last']);

        $this->assertTrue(Cache::memo()->tags(['foo'])->forget(['first', 'last']));

        $this->assertSame(['first' => null, 'last' => null], Cache::memo()->tags(['foo'])->get(['first', 'last']));
        $this->assertSame(['first' => null, 'last' => null], Cache::tags(['foo'])->get(['first', 'last']));
    }

    public function testItCanIncrementAndDecrementMemoizedValuesWithTags(): void
    {
        Cache::tags(['foo', 'bar'])->put('count', 1, 60);

        $live = Cache::tags(['foo', 'bar'])->get('count');
        $memoized = Cache::memo()->tags(['foo', 'bar'])->get('count');
        $this->assertSame('1', $live);
        $this->assertSame('1', $memoized);

        Cache::memo()->tags(['foo', 'bar'])->increment('count');

        $live = Cache::tags(['foo', 'bar'])->get('count');
        $memoized = Cache::memo()->tags(['foo', 'bar'])->get('count');
        $this->assertSame('2', $live);
        $this->assertSame('2', $memoized);

        Cache::memo()->tags(['foo', 'bar'])->decrement('count');

        $live = Cache::tags(['foo', 'bar'])->get('count');
        $memoized = Cache::memo()->tags(['foo', 'bar'])->get('count');
        $this->assertSame('1', $live);
        $this->assertSame('1', $memoized);
    }

    public function testPutManyForTaggedMemoDriver(): void
    {
        Cache::memo()->tags(['foo', 'bar'])->putMany(['name.0' => 'Tim', 'name.1' => 'Taylor'], 60);

        $live = Cache::tags(['foo', 'bar'])->getMultiple(['name.0', 'name.1']);
        $memoized = Cache::memo()->tags(['foo', 'bar'])->getMultiple(['name.0', 'name.1']);
        $this->assertSame(['name.0' => 'Tim', 'name.1' => 'Taylor'], $live);
        $this->assertSame(['name.0' => 'Tim', 'name.1' => 'Taylor'], $memoized);

        Cache::memo()->tags(['foo', 'bar'])->putMany(['name.0' => 'MacDonald', 'name.1' => 'Otwell'], 60);

        $live = Cache::tags(['foo', 'bar'])->getMultiple(['name.0', 'name.1']);
        $memoized = Cache::memo()->tags(['foo', 'bar'])->getMultiple(['name.0', 'name.1']);
        $this->assertSame(['name.0' => 'MacDonald', 'name.1' => 'Otwell'], $live);
        $this->assertSame(['name.0' => 'MacDonald', 'name.1' => 'Otwell'], $memoized);
    }

    public function testPutForgetsMemoizedValue(): void
    {
        Cache::tags(['foo'])->put('name', 'Tim', 60);
        Cache::memo()->tags(['foo'])->get('name');

        Cache::memo()->tags(['foo'])->put('name', 'Taylor', 60);

        $this->assertSame('Taylor', Cache::memo()->tags(['foo'])->get('name'));
    }

    public function testPutSupportsAnArrayOfValues(): void
    {
        Cache::memo()->tags(['foo'])->put(['first' => 'Tim', 'last' => 'MacDonald'], 60);

        $this->assertSame(
            ['first' => 'Tim', 'last' => 'MacDonald'],
            Cache::memo()->tags(['foo'])->get(['first', 'last']),
        );
    }

    public function testTaggedMemoizedCacheUsesPrefixes(): void
    {
        Cache::tags(['foo', 'bar'])->setPrefix('prefix1_');

        $this->assertSame('prefix1_', Cache::tags(['foo', 'bar'])->getPrefix());
        $this->assertSame('prefix1_', Cache::memo()->tags(['foo', 'bar'])->getPrefix());
    }

    public function testMemoizedKeysArePrefixedWithTags(): void
    {
        $redis = Cache::store('redis')->tags(['foo', 'bar']);

        $redis->setPrefix('aaaa');
        $redis->put('name', 'Tim', 60);
        $redis->setPrefix('zzzz');
        $redis->put('name', 'Taylor', 60);

        $redis->setPrefix('aaaa');
        $value = Cache::memo('redis')->tags(['foo', 'bar'])->get('name');
        $this->assertSame('Tim', $value);

        $redis->setPrefix('zzzz');
        $value = Cache::memo('redis')->tags(['foo', 'bar'])->get('name');
        $this->assertSame('Taylor', $value);
    }

    public function testErrorThrownWhenTagsNotSupported(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessageIs('This cache store does not support tagging.');

        Cache::memo('file')->tags(['foo', 'bar'])->put('name', 'Tim', 60);
    }

    public function testItForwardsMethodsSupportedByTheTaggedCache(): void
    {
        $this->assertTrue(Cache::memo()->tags(['foo'])->flushStale());
    }

    public function testItKeepsMemoizedValuesIsolatedBetweenTagSets(): void
    {
        Cache::tags(['foo'])->put('name', 'Foo', 60);
        Cache::tags(['bar'])->put('name', 'Bar', 60);

        $this->assertSame('Foo', Cache::memo()->tags(['foo'])->get('name'));
        $this->assertSame('Bar', Cache::memo()->tags(['bar'])->get('name'));
    }

    public function testScalarAndArrayTagNamesShareTheSameMemoizedCache(): void
    {
        Cache::tags(['foo'])->put('name', 'Foo', 60);

        $this->assertSame('Foo', Cache::memo()->tags('foo')->get('name'));

        Cache::tags(['foo'])->put('name', 'Bar', 60);

        $this->assertSame('Foo', Cache::memo()->tags(['foo'])->get('name'));
    }

    public function testItFlushesTaggedMemoizedValuesWhenTheStoreIsFlushed(): void
    {
        Cache::tags(['foo'])->put('name', 'Foo', 60);
        Cache::memo()->tags(['foo'])->get('name');

        Cache::memo()->flush();

        $this->assertNull(Cache::memo()->tags(['foo'])->get('name'));
    }

    public function testItResolvesDefaultsWhenRetrievingMultipleTaggedValues(): void
    {
        $this->assertSame(
            ['missing' => 'fallback'],
            Cache::memo()->tags(['foo'])->getMultiple(['missing'], fn (): string => 'fallback'),
        );

        $this->assertSame(
            ['missing' => null],
            Cache::memo()->tags(['foo'])->get(['missing']),
        );
    }

    public function testItDoesNotMemoizeDefaultValues(): void
    {
        $cache = Cache::memo()->tags(['foo']);

        $this->assertSame('first', $cache->get('missing', 'first'));
        $this->assertSame('second', $cache->get('missing', 'second'));
    }

    public function testNullableRememberMemoizesCachedNullWithTags(): void
    {
        $invocations = 0;
        $callback = function () use (&$invocations): null {
            ++$invocations;

            return null;
        };

        $this->assertNull(Cache::memo()->tags(['foo'])->rememberNullable('name', 60, $callback));
        $this->assertNull(Cache::memo()->tags(['foo'])->rememberNullable('name', 60, $callback));

        Cache::tags(['foo'])->forget('name');

        $this->assertNull(Cache::memo()->tags(['foo'])->rememberNullable('name', 60, $callback));
        $this->assertSame(1, $invocations);
    }

    public function testItSupportsEnumKeys(): void
    {
        Cache::tags(['foo'])->put(MemoizedTaggedCacheTestKey::Name, 'Tim', 60);

        $this->assertSame('Tim', Cache::memo()->tags(['foo'])->get(MemoizedTaggedCacheTestKey::Name));
    }

    public function testMutationsForgetMemoizedValues(): void
    {
        Cache::tags(['foo'])->put('name', 'Tim', 60);
        Cache::memo()->tags(['foo'])->get('name');

        Cache::memo()->tags(['foo'])->forever('name', 'Taylor');
        $this->assertSame('Taylor', Cache::memo()->tags(['foo'])->get('name'));

        Cache::tags(['foo'])->put('name', 'Abigail', 60);
        Cache::memo()->tags(['foo'])->touch('name', 60);
        $this->assertSame('Abigail', Cache::memo()->tags(['foo'])->get('name'));

        Cache::tags(['foo'])->put('name', 'Nuno', 60);
        Cache::memo()->tags(['foo'])->get('name');
        Cache::tags(['foo'])->put('name', 'Jess', 60);
        $this->assertFalse(Cache::memo()->tags(['foo'])->add('name', 'Adam', 60));
        $this->assertSame('Jess', Cache::memo()->tags(['foo'])->get('name'));
    }

    public function testClearFlushesTheTagSetAndForgetsTaggedMemoizedValues(): void
    {
        Cache::put('untagged', 'Tim', 60);
        Cache::tags(['foo'])->put('foo', 'Taylor', 60);
        Cache::tags(['bar'])->put('bar', 'Jess', 60);

        Cache::memo()->get('untagged');
        Cache::memo()->tags(['foo'])->get('foo');
        Cache::memo()->tags(['bar'])->get('bar');

        Cache::put('untagged', 'Abigail', 60);
        Cache::tags(['bar'])->put('bar', 'Nuno', 60);

        $this->assertTrue(Cache::memo()->tags(['foo'])->clear());

        $this->assertSame('Tim', Cache::memo()->get('untagged'));
        $this->assertSame('Abigail', Cache::get('untagged'));
        $this->assertNull(Cache::memo()->tags(['foo'])->get('foo'));
        $this->assertSame('Nuno', Cache::memo()->tags(['bar'])->get('bar'));
    }

    public function testStoreFlushClearsExistingTaggedMemoizedCacheInstancesWithoutDispatchingTagFlushEvents(): void
    {
        $events = [];

        Event::listen([CacheFlushing::class, CacheFlushed::class], function (CacheFlushing|CacheFlushed $event) use (&$events): void {
            $events[] = $event;
        });

        Cache::tags(['foo'])->put('name', 'Tim', 60);

        $cache = Cache::memo()->tags(['foo']);
        $cache->get('name');

        Cache::memo()->flush();

        $this->assertNull($cache->get('name'));
        $this->assertSame([], $events);
    }

    public function testItDispatchesDecoratedDriverEventsOnly(): void
    {
        $events = [];

        Event::listen([RetrievingKey::class, CacheMissed::class], function (CacheEvent $event) use (&$events): void {
            $events[] = $event;
        });

        Cache::memo()->tags(['foo'])->get('name');
        Cache::memo()->tags(['foo'])->get('name');

        $this->assertCount(2, $events);
        $this->assertInstanceOf(RetrievingKey::class, $events[0]);
        $this->assertSame('redis', $events[0]->storeName);
        $this->assertSame(['foo'], $events[0]->tags);
        $this->assertInstanceOf(CacheMissed::class, $events[1]);
        $this->assertSame('redis', $events[1]->storeName);
        $this->assertSame(['foo'], $events[1]->tags);
    }

    public function testFlexibleRefreshesEachTagSetForTheSameKey(): void
    {
        $this->freezeTime();
        Cache::tags(['foo'])->flexible('name', [10, 20], 'foo-1');
        Cache::tags(['bar'])->flexible('name', [10, 20], 'bar-1');

        $this->travel(11)->seconds();
        $this->assertSame('foo-1', Cache::memo()->tags(['foo'])->flexible('name', [10, 20], 'foo-2'));
        $this->assertSame('bar-1', Cache::memo()->tags(['bar'])->flexible('name', [10, 20], 'bar-2'));
        defer()->invoke();

        $this->assertSame('foo-2', Cache::tags(['foo'])->get('name'));
        $this->assertSame('bar-2', Cache::tags(['bar'])->get('name'));
    }

    public function testAnyModeTagsShareTheMemoizedPlainKeys(): void
    {
        $this->skipIfHashFieldExpirationUnsupported();
        Cache::store('redis')->getStore()->setTagMode('any');

        $events = [];

        Event::listen([RetrievingKey::class, CacheHit::class], function (CacheEvent $event) use (&$events): void {
            $events[] = $event;
        });

        Cache::tags(['people'])->put('name', 'Tim', 60);
        Cache::put('other', 'Taylor', 60);

        $this->assertSame(TagMode::Any, Cache::memo()->getTagMode());
        $this->assertSame('Tim', Cache::memo()->tags(['people'])->remember('name', 60, fn (): string => 'unused'));
        $this->assertCount(2, $events);
        $this->assertSame(['people'], $events[0]->tags);
        $this->assertSame(['people'], $events[1]->tags);

        Cache::put('name', 'Abigail', 60);

        $this->assertSame('Tim', Cache::memo()->get('name'));
        $this->assertSame('Tim', Cache::memo()->tags(['people'])->remember('name', 60, fn (): string => 'unused'));
        $this->assertCount(2, $events);

        Cache::memo()->tags(['people'])->put('name', 'Jess', 60);

        $this->assertSame('Jess', Cache::memo()->get('name'));

        Cache::memo()->get('other');
        Cache::put('other', 'Nuno', 60);
        Cache::memo()->tags(['people'])->flush();

        $this->assertNull(Cache::memo()->get('name'));
        $this->assertSame('Nuno', Cache::memo()->get('other'));

        $this->expectException(BadMethodCallException::class);

        Cache::memo()->tags(['people'])->get('name');
    }
}

enum MemoizedTaggedCacheTestKey
{
    case Name;
}
