<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use BadMethodCallException;
use Hypervel\Cache\Exceptions\NotSupportedException;
use Hypervel\Cache\StackStore;
use Hypervel\Cache\StackStoreProxy;
use Hypervel\Cache\StackTaggedCache;
use Hypervel\Cache\TaggableStore;
use Hypervel\Cache\TaggedCache;
use Hypervel\Cache\TagMode;
use Hypervel\Cache\TagSet;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Tests\TestCase;
use Mockery as m;

class CacheStackStoreTagsTest extends TestCase
{
    public function testValidCompositionsSupportTags(): void
    {
        foreach ([
            [$this->nonTaggableStore(), $this->anyModeTaggableStore()],
            [$this->anyModeTaggableStore()],
            [$this->nonTaggableStore(), $this->anyModeTaggableStore(), $this->anyModeTaggableStore()],
        ] as $layers) {
            $stack = new StackStore($layers);

            $this->assertTrue($stack->supportsTags());
            $this->assertSame(TagMode::Any, $stack->getTagMode());
            $this->assertInstanceOf(StackTaggedCache::class, $stack->tags(['t']));
        }
    }

    public function testInvalidCompositionsDoNotSupportTags(): void
    {
        foreach ([
            [$this->nonTaggableStore()],
            [$this->anyModeTaggableStore(), $this->nonTaggableStore()],
            [$this->nonTaggableStore(), $this->allModeTaggableStore()],
            [$this->allModeTaggableStore(), $this->anyModeTaggableStore()],
        ] as $layers) {
            $stack = new StackStore($layers);

            $this->assertFalse($stack->supportsTags());

            try {
                $stack->tags(['t']);
                $this->fail('Expected NotSupportedException was not thrown.');
            } catch (NotSupportedException) {
                $this->assertTrue(true);
            }
        }
    }

    public function testInvalidNestedStackDoesNotCallGetTagMode(): void
    {
        $taggable = m::mock(TaggableStore::class);
        $taggable->shouldReceive('supportsTags')->once()->andReturnFalse();
        $taggable->shouldNotReceive('getTagMode');

        $stack = new StackStore([$taggable]);

        $this->assertFalse($stack->supportsTags());
    }

    public function testValidationRunsOnce(): void
    {
        $taggable = m::mock(TaggableStore::class);
        $taggable->shouldReceive('supportsTags')->once()->andReturnTrue();
        $taggable->shouldReceive('getTagMode')->once()->andReturn(TagMode::Any);

        $stack = new StackStore([$taggable]);

        $this->assertTrue($stack->supportsTags());
        $this->assertTrue($stack->supportsTags());
        $this->assertSame(TagMode::Any, $stack->getTagMode());
    }

    public function testTaggedPutWritesPlainUpperLayersAndTaggedLowerLayersWithTtlClamp(): void
    {
        $plain = $this->nonTaggableStore();
        $taggable = $this->anyModeTaggableStore();
        $taggedCache = m::mock(TaggedCache::class);

        $plain->shouldReceive('put')
            ->once()
            ->with('key', m::on(fn (array $record): bool => $record['value'] === 'value' && isset($record['expiration'])), 3)
            ->andReturnTrue();
        $taggable->shouldReceive('tags')->once()->with(['tag'])->andReturn($taggedCache);
        $taggedCache->shouldReceive('put')
            ->once()
            ->with('key', m::on(fn (array $record): bool => $record['value'] === 'value' && isset($record['expiration'])), 60)
            ->andReturnTrue();

        $stack = new StackStore([
            new StackStoreProxy($plain, 3),
            new StackStoreProxy($taggable),
        ]);

        $this->assertTrue($stack->tags(['tag'])->put('key', 'value', 60));
    }

    public function testTaggedForeverHonorsLayerTtlClamp(): void
    {
        $plain = $this->nonTaggableStore();
        $taggable = $this->anyModeTaggableStore();
        $taggedCache = m::mock(TaggedCache::class);

        $plain->shouldReceive('put')->once()->with('key', ['value' => 'value'], 3)->andReturnTrue();
        $taggable->shouldReceive('tags')->once()->with(['tag'])->andReturn($taggedCache);
        $taggedCache->shouldReceive('forever')->once()->with('key', ['value' => 'value'])->andReturnTrue();

        $stack = new StackStore([
            new StackStoreProxy($plain, 3),
            new StackStoreProxy($taggable),
        ]);

        $this->assertTrue($stack->tags(['tag'])->forever('key', 'value'));
    }

    public function testTaggedPutClampsTtlOnTaggableLayer(): void
    {
        $taggable = $this->anyModeTaggableStore();
        $taggedCache = m::mock(TaggedCache::class);

        $taggable->shouldReceive('tags')->once()->with(['tag'])->andReturn($taggedCache);
        $taggedCache->shouldReceive('put')
            ->once()
            ->with('key', m::on(fn (array $record): bool => $record['value'] === 'value' && isset($record['expiration'])), 3)
            ->andReturnTrue();

        $stack = new StackStore([
            new StackStoreProxy($taggable, 3),
        ]);

        $this->assertTrue($stack->tags(['tag'])->put('key', 'value', 300));
    }

    public function testTaggedForeverClampsToPutOnTaggableLayerWithTtlOverride(): void
    {
        $taggable = $this->anyModeTaggableStore();
        $taggedCache = m::mock(TaggedCache::class);

        $taggable->shouldReceive('tags')->once()->with(['tag'])->andReturn($taggedCache);
        $taggedCache->shouldReceive('put')->once()->with('key', ['value' => 'value'], 3)->andReturnTrue();
        $taggedCache->shouldNotReceive('forever');

        $stack = new StackStore([
            new StackStoreProxy($taggable, 3),
        ]);

        $this->assertTrue($stack->tags(['tag'])->forever('key', 'value'));
    }

    public function testTaggedWriteRollsBackWrittenLayersOnFailure(): void
    {
        $plain = $this->nonTaggableStore();
        $taggable = $this->anyModeTaggableStore();
        $taggedCache = m::mock(TaggedCache::class);

        $plain->shouldReceive('put')->once()->andReturnTrue();
        $plain->shouldReceive('forget')->once()->with('key')->andReturnTrue();
        $taggable->shouldReceive('tags')->once()->with(['tag'])->andReturn($taggedCache);
        $taggedCache->shouldReceive('put')->once()->andReturnFalse();

        $stack = new StackStore([
            new StackStoreProxy($plain),
            new StackStoreProxy($taggable),
        ]);

        $this->assertFalse($stack->tags(['tag'])->put('key', 'value', 60));
    }

    public function testTaggedIncrementWritesThroughTaggedPath(): void
    {
        $taggable = $this->anyModeTaggableStore();
        $taggedCache = m::mock(TaggedCache::class);

        $taggable->shouldReceive('get')->once()->with('counter')->andReturn(['value' => 1]);
        $taggable->shouldReceive('tags')->once()->with(['tag'])->andReturn($taggedCache);
        $taggedCache->shouldReceive('forever')->once()->with('counter', ['value' => 3])->andReturnTrue();

        $stack = new StackStore([$taggable]);

        $this->assertSame(3, $stack->tags(['tag'])->increment('counter', 2));
    }

    public function testTaggedRememberReadsPlainAndWritesTaggedOnMiss(): void
    {
        $taggable = $this->anyModeTaggableStore();
        $taggedCache = m::mock(TaggedCache::class);

        $taggable->shouldReceive('get')->once()->with('key')->andReturnNull();
        $taggable->shouldReceive('tags')->once()->with(['tag'])->andReturn($taggedCache);
        $taggedCache->shouldReceive('put')->once()->andReturnTrue();

        $stack = new StackStore([$taggable]);

        $this->assertSame('computed', $stack->tags(['tag'])->remember('key', 60, fn () => 'computed'));
    }

    public function testTaggedRememberHitReadsPlainWithoutTaggedWrite(): void
    {
        $taggable = $this->anyModeTaggableStore();

        $taggable->shouldReceive('get')->once()->with('key')->andReturn(['value' => 'cached']);
        $taggable->shouldNotReceive('tags');

        $stack = new StackStore([$taggable]);

        $this->assertSame('cached', $stack->tags(['tag'])->remember('key', 60, fn () => 'computed'));
    }

    public function testTaggedReadAndDeleteOperationsThrow(): void
    {
        $cache = (new StackStore([$this->anyModeTaggableStore()]))->tags(['tag']);

        foreach ([
            fn () => $cache->get('key'),
            fn () => $cache->getMultiple(['key']),
            fn () => $cache->delete('key'),
            fn () => $cache->deleteMultiple(['key']),
            fn () => $cache->touch('key', 60),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Expected BadMethodCallException was not thrown.');
            } catch (BadMethodCallException) {
                $this->assertTrue(true);
            }
        }
    }

    public function testClearFlushesTaggableLayersOnly(): void
    {
        $taggable = $this->anyModeTaggableStore();
        $tagSet = m::mock(TagSet::class);
        $taggedCache = m::mock(TaggedCache::class);

        $taggable->shouldReceive('tags')->once()->with(['tag'])->andReturn($taggedCache);
        $taggedCache->shouldReceive('getTags')->once()->andReturn($tagSet);
        $tagSet->shouldReceive('flush')->once();

        $stack = new StackStore([$this->nonTaggableStore(), $taggable]);

        $this->assertTrue($stack->tags(['tag'])->clear());
    }

    private function anyModeTaggableStore(): TaggableStore|m\MockInterface
    {
        $store = m::mock(TaggableStore::class);
        $store->shouldReceive('supportsTags')->byDefault()->andReturnTrue();
        $store->shouldReceive('getTagMode')->byDefault()->andReturn(TagMode::Any);

        return $store;
    }

    private function allModeTaggableStore(): TaggableStore|m\MockInterface
    {
        $store = m::mock(TaggableStore::class);
        $store->shouldReceive('supportsTags')->byDefault()->andReturnTrue();
        $store->shouldReceive('getTagMode')->byDefault()->andReturn(TagMode::All);

        return $store;
    }

    private function nonTaggableStore(): Store|m\MockInterface
    {
        return m::mock(Store::class);
    }
}
