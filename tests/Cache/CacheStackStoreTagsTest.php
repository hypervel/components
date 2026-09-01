<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use __PHP_Incomplete_Class;
use BadMethodCallException;
use Hypervel\Cache\Events\CacheFlushFailed;
use Hypervel\Cache\Events\CacheFlushing;
use Hypervel\Cache\Events\CacheHit;
use Hypervel\Cache\Events\CacheMissed;
use Hypervel\Cache\Events\ForgettingKey;
use Hypervel\Cache\Events\KeyForgetFailed;
use Hypervel\Cache\Events\KeyForgotten;
use Hypervel\Cache\Events\KeyRetrievalFailed;
use Hypervel\Cache\Events\KeyWriteFailed;
use Hypervel\Cache\Events\KeyWritten;
use Hypervel\Cache\Events\RetrievingKey;
use Hypervel\Cache\Events\WritingKey;
use Hypervel\Cache\Exceptions\NotSupportedException;
use Hypervel\Cache\Repository;
use Hypervel\Cache\StackStore;
use Hypervel\Cache\StackStoreProxy;
use Hypervel\Cache\StackTaggedCache;
use Hypervel\Cache\TaggableStore;
use Hypervel\Cache\TaggedCache;
use Hypervel\Cache\TagMode;
use Hypervel\Cache\TagSet;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use stdClass;
use Swoole\Coroutine\CanceledException;

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

    public function testStringKeyedLayersUseZeroBasedIndexesInCompositionErrors(): void
    {
        $stack = new StackStore([
            'memory' => $this->anyModeTaggableStore(),
            'persistent' => $this->nonTaggableStore(),
        ]);

        $this->expectException(NotSupportedException::class);
        $this->expectExceptionMessage('Stack layer 1');

        $stack->tags(['t']);
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

    public function testTaggedWriteRollsBackWrittenLayersWhenLowerLayerThrows(): void
    {
        $plain = $this->nonTaggableStore();
        $taggable = $this->anyModeTaggableStore();
        $taggedCache = m::mock(TaggedCache::class);
        $exception = new RuntimeException('write failed');

        $plain->shouldReceive('put')->once()->andReturnTrue();
        $taggable->shouldReceive('tags')->once()->with(['tag'])->andReturn($taggedCache);
        $taggedCache->shouldReceive('put')->once()->andThrow($exception);
        $taggable->shouldReceive('forget')->once()->with('key')->ordered()->andReturnTrue();
        $plain->shouldReceive('forget')->once()->with('key')->ordered()->andReturnTrue();

        $stack = new StackStore([
            new StackStoreProxy($plain),
            new StackStoreProxy($taggable),
        ]);

        try {
            $stack->tags(['tag'])->put('key', 'value', 60);
            $this->fail('Expected the lower-layer exception to be thrown.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }
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

    public function testTaggedIncrementDoesNotTreatFailedRepairAsMissingAndPreservesLowerValue(): void
    {
        $plain = $this->nonTaggableStore();
        $taggable = $this->anyModeTaggableStore();
        $taggedCache = m::mock(TaggedCache::class);
        $record = ['value' => 5];
        $incrementedRecord = ['value' => 6];

        $plain->shouldReceive('get')->twice()->with('counter')->andReturnNull();
        $taggable->shouldReceive('get')->twice()->with('counter')->andReturn($record);
        $plain->shouldReceive('forever')->twice()->with('counter', $record)->andReturnFalse();

        $plain->shouldReceive('forever')->once()->with('counter', $incrementedRecord)->andReturnTrue();
        $taggable->shouldReceive('tags')->once()->with(['tag'])->andReturn($taggedCache);
        $taggedCache->shouldReceive('forever')->once()->with('counter', $incrementedRecord)->andReturnFalse();
        $plain->shouldReceive('forget')->once()->with('counter')->andReturnTrue();

        $stack = new StackStore([$plain, $taggable]);

        $this->assertFalse($stack->tags(['tag'])->increment('counter'));
        $this->assertSame(5, $stack->get('counter'));
    }

    public function testTaggedRememberReadsPlainAndWritesTaggedOnMiss(): void
    {
        $taggable = $this->anyModeTaggableStore();
        $taggedCache = m::mock(TaggedCache::class);

        $taggable->shouldReceive('get')->once()->with('0')->andReturnNull();
        $taggable->shouldReceive('tags')->once()->with(['tag'])->andReturn($taggedCache);
        $taggedCache->shouldReceive('put')->once()->with('0', m::type('array'), 60)->andReturnTrue();

        $stack = new StackStore([$taggable]);

        $this->assertSame('computed', $stack->tags(['tag'])->remember(StackTaggedCacheKey::Zero, 60, fn () => 'computed'));
    }

    public function testTaggedRememberHitReadsPlainWithoutTaggedWrite(): void
    {
        $taggable = $this->anyModeTaggableStore();

        $taggable->shouldReceive('get')->once()->with('key')->andReturn(['value' => 'cached']);
        $taggable->shouldNotReceive('tags');

        $stack = new StackStore([$taggable]);

        $this->assertSame('cached', $stack->tags(['tag'])->remember('key', 60, fn () => 'computed'));
    }

    public function testTaggedRememberHandlesIncompleteClassBeforeDispatchingHitEvent(): void
    {
        $taggable = $this->anyModeTaggableStore();
        $taggable->shouldReceive('get')->once()->with('key')->andReturn([
            'value' => unserialize(serialize(new stdClass), ['allowed_classes' => false]),
        ]);
        $taggable->shouldNotReceive('tags');

        $sequence = [];

        Repository::handleUnserializableClassUsing(function (string $key, ?string $class) use (&$sequence): void {
            $sequence[] = ['handler', $key, $class];
        });

        $cache = (new Repository(new StackStore([$taggable]), ['store' => 'stack']))->tags(['tag']);
        $cache->setEventDispatcher($this->capturingDispatcher($sequence));

        $result = $cache->remember('key', 60, function (): never {
            $this->fail('The cache callback should not be called.');
        });

        $this->assertInstanceOf(__PHP_Incomplete_Class::class, $result);
        $this->assertInstanceOf(RetrievingKey::class, $sequence[0]);
        $this->assertSame(['handler', 'key', 'stdClass'], $sequence[1]);
        $this->assertInstanceOf(CacheHit::class, $sequence[2]);
    }

    public function testTaggedRememberMissDispatchesRepositoryReadAndWriteEvents(): void
    {
        $taggable = $this->anyModeTaggableStore();
        $taggedCache = m::mock(TaggedCache::class);

        $taggable->shouldReceive('get')->once()->with('key')->andReturnNull();
        $taggable->shouldReceive('tags')->once()->with(['tag'])->andReturn($taggedCache);
        $taggedCache->shouldReceive('put')->once()->with('key', m::type('array'), 60)->andReturnTrue();

        $captured = [];
        $stack = new StackStore([$taggable]);
        $cache = (new Repository($stack, ['store' => 'stack']))->tags(['tag']);
        $cache->setEventDispatcher($this->capturingDispatcher($captured));

        $this->assertSame('computed', $cache->remember('key', 60, fn () => 'computed'));
        $this->assertSame(
            [RetrievingKey::class, CacheMissed::class, WritingKey::class, KeyWritten::class],
            array_map(get_class(...), $captured)
        );

        foreach ($captured as $event) {
            $this->assertSame('stack', $event->storeName);
            $this->assertSame('key', $event->key);
            $this->assertSame(['tag'], $event->tags);
        }
    }

    public function testTaggedPutDispatchesRepositoryWriteFailureEvents(): void
    {
        $taggable = $this->anyModeTaggableStore();
        $taggedCache = m::mock(TaggedCache::class);

        $taggable->shouldReceive('tags')->once()->with(['tag'])->andReturn($taggedCache);
        $taggedCache->shouldReceive('put')->once()->with('key', m::type('array'), 60)->andReturnFalse();

        $captured = [];
        $stack = new StackStore([$taggable]);
        $cache = (new Repository($stack, ['store' => 'stack']))->tags(['tag']);
        $cache->setEventDispatcher($this->capturingDispatcher($captured));

        $this->assertFalse($cache->put('key', 'value', 60));
        $this->assertSame([WritingKey::class, KeyWriteFailed::class], array_map(get_class(...), $captured));

        foreach ($captured as $event) {
            $this->assertSame('stack', $event->storeName);
            $this->assertSame('key', $event->key);
            $this->assertSame(['tag'], $event->tags);
        }
    }

    public function testTaggedRememberDispatchesRepositoryReadFailureEventWhenTheStoreThrows(): void
    {
        $exception = new RuntimeException('read failed');
        $taggable = $this->anyModeTaggableStore();
        $taggable->shouldReceive('get')->once()->with('key')->andThrow($exception);

        $captured = [];
        $stack = new StackStore([$taggable]);
        $cache = (new Repository($stack, ['store' => 'stack']))->tags(['tag']);
        $cache->setEventDispatcher($this->capturingDispatcher($captured));

        try {
            $cache->remember('key', 60, fn () => 'computed');
            $this->fail('Expected the tagged cache read exception to be rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame(
            [RetrievingKey::class, KeyRetrievalFailed::class],
            array_map(get_class(...), $captured),
        );
        $this->assertSame(['tag'], $captured[1]->tags);
        $this->assertSame($exception, $captured[1]->exception);
    }

    public function testTaggedRememberDoesNotDispatchFailureEventWhenTheStoreIsCanceled(): void
    {
        $cancellation = new CanceledException('read canceled');
        $taggable = $this->anyModeTaggableStore();
        $taggable->shouldReceive('get')->once()->with('key')->andThrow($cancellation);

        $captured = [];
        $stack = new StackStore([$taggable]);
        $cache = (new Repository($stack, ['store' => 'stack']))->tags(['tag']);
        $cache->setEventDispatcher($this->capturingDispatcher($captured));

        try {
            $cache->remember('key', 60, fn () => 'computed');
            $this->fail('Expected the tagged cache read cancellation to be rethrown.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame([RetrievingKey::class], array_map(get_class(...), $captured));
    }

    public function testTaggedPutDispatchesRepositoryWriteFailureEventWhenTheStoreThrows(): void
    {
        $exception = new RuntimeException('write failed');
        $taggable = $this->anyModeTaggableStore();
        $taggedCache = m::mock(TaggedCache::class);
        $taggable->shouldReceive('tags')->once()->with(['tag'])->andReturn($taggedCache);
        $taggedCache->shouldReceive('put')->once()->with('key', m::type('array'), 60)->andThrow($exception);
        $taggable->shouldReceive('forget')->once()->with('key')->andReturnTrue();

        $captured = [];
        $stack = new StackStore([$taggable]);
        $cache = (new Repository($stack, ['store' => 'stack']))->tags(['tag']);
        $cache->setEventDispatcher($this->capturingDispatcher($captured));

        try {
            $cache->put('key', 'value', 60);
            $this->fail('Expected the tagged cache write exception to be rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame([WritingKey::class, KeyWriteFailed::class], array_map(get_class(...), $captured));
        $this->assertSame(['tag'], $captured[1]->tags);
        $this->assertSame($exception, $captured[1]->exception);
    }

    public function testTaggedPutDoesNotDispatchFailureEventWhenTheStoreIsCanceled(): void
    {
        $cancellation = new CanceledException('write canceled');
        $taggable = $this->anyModeTaggableStore();
        $taggedCache = m::mock(TaggedCache::class);
        $taggable->shouldReceive('tags')->once()->with(['tag'])->andReturn($taggedCache);
        $taggedCache->shouldReceive('put')->once()->with('key', m::type('array'), 60)->andThrow($cancellation);
        $taggable->shouldReceive('forget')->once()->with('key')->andReturnTrue();

        $captured = [];
        $stack = new StackStore([$taggable]);
        $cache = (new Repository($stack, ['store' => 'stack']))->tags(['tag']);
        $cache->setEventDispatcher($this->capturingDispatcher($captured));

        try {
            $cache->put('key', 'value', 60);
            $this->fail('Expected the tagged cache write cancellation to be rethrown.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame([WritingKey::class], array_map(get_class(...), $captured));
    }

    public function testTaggedForeverDispatchesRepositoryWriteFailureEventWhenTheStoreThrows(): void
    {
        $exception = new RuntimeException('forever write failed');
        $taggable = $this->anyModeTaggableStore();
        $taggedCache = m::mock(TaggedCache::class);
        $taggable->shouldReceive('tags')->once()->with(['tag'])->andReturn($taggedCache);
        $taggedCache->shouldReceive('forever')->once()->with('key', ['value' => 'value'])->andThrow($exception);
        $taggable->shouldReceive('forget')->once()->with('key')->andReturnTrue();

        $captured = [];
        $stack = new StackStore([$taggable]);
        $cache = (new Repository($stack, ['store' => 'stack']))->tags(['tag']);
        $cache->setEventDispatcher($this->capturingDispatcher($captured));

        try {
            $cache->forever('key', 'value');
            $this->fail('Expected the tagged forever exception to be rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame([WritingKey::class, KeyWriteFailed::class], array_map(get_class(...), $captured));
        $this->assertSame(['tag'], $captured[1]->tags);
        $this->assertNull($captured[1]->seconds);
        $this->assertSame($exception, $captured[1]->exception);
    }

    public function testTaggedForeverDoesNotDispatchFailureEventWhenTheStoreIsCanceled(): void
    {
        $cancellation = new CanceledException('forever write canceled');
        $taggable = $this->anyModeTaggableStore();
        $taggedCache = m::mock(TaggedCache::class);
        $taggable->shouldReceive('tags')->once()->with(['tag'])->andReturn($taggedCache);
        $taggedCache->shouldReceive('forever')->once()->with('key', ['value' => 'value'])->andThrow($cancellation);
        $taggable->shouldReceive('forget')->once()->with('key')->andReturnTrue();

        $captured = [];
        $stack = new StackStore([$taggable]);
        $cache = (new Repository($stack, ['store' => 'stack']))->tags(['tag']);
        $cache->setEventDispatcher($this->capturingDispatcher($captured));

        try {
            $cache->forever('key', 'value');
            $this->fail('Expected the tagged forever cancellation to be rethrown.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame([WritingKey::class], array_map(get_class(...), $captured));
    }

    public function testTaggedExpiredPutDispatchesRepositoryForgetFailureEventWhenTheStoreThrows(): void
    {
        $exception = new RuntimeException('forget failed');
        $taggable = $this->anyModeTaggableStore();
        $taggable->shouldReceive('forget')->once()->with('key')->andThrow($exception);

        $captured = [];
        $stack = new StackStore([$taggable]);
        $cache = (new Repository($stack, ['store' => 'stack']))->tags(['tag']);
        $cache->setEventDispatcher($this->capturingDispatcher($captured));

        try {
            $cache->put('key', 'value', 0);
            $this->fail('Expected the tagged forget exception to be rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame([ForgettingKey::class, KeyForgetFailed::class], array_map(get_class(...), $captured));
        $this->assertSame(['tag'], $captured[1]->tags);
        $this->assertSame($exception, $captured[1]->exception);
    }

    public function testTaggedExpiredPutDoesNotDispatchFailureEventWhenTheStoreIsCanceled(): void
    {
        $cancellation = new CanceledException('forget canceled');
        $taggable = $this->anyModeTaggableStore();
        $taggable->shouldReceive('forget')->once()->with('key')->andThrow($cancellation);

        $captured = [];
        $stack = new StackStore([$taggable]);
        $cache = (new Repository($stack, ['store' => 'stack']))->tags(['tag']);
        $cache->setEventDispatcher($this->capturingDispatcher($captured));

        try {
            $cache->put('key', 'value', 0);
            $this->fail('Expected the tagged forget cancellation to be rethrown.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame([ForgettingKey::class], array_map(get_class(...), $captured));
    }

    public function testTaggedPutWithExpiredTtlDispatchesRepositoryDeleteEvents(): void
    {
        $taggable = $this->anyModeTaggableStore();
        $taggable->shouldReceive('forget')->once()->with('key')->andReturnTrue();

        $captured = [];
        $stack = new StackStore([$taggable]);
        $cache = (new Repository($stack, ['store' => 'stack']))->tags(['tag']);
        $cache->setEventDispatcher($this->capturingDispatcher($captured));

        $this->assertTrue($cache->put('key', 'value', 0));
        $this->assertSame([ForgettingKey::class, KeyForgotten::class], array_map(get_class(...), $captured));

        foreach ($captured as $event) {
            $this->assertSame('stack', $event->storeName);
            $this->assertSame('key', $event->key);
            $this->assertSame(['tag'], $event->tags);
        }
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
        $tagSet->shouldReceive('flush')->once()->andReturnTrue();

        $stack = new StackStore([$this->nonTaggableStore(), $taggable]);

        $this->assertTrue($stack->tags(['tag'])->clear());
    }

    public function testClearFlushesEveryTaggableLayerAndAggregatesFalseResults(): void
    {
        $firstTaggable = $this->anyModeTaggableStore();
        $secondTaggable = $this->anyModeTaggableStore();
        $firstTaggedCache = m::mock(TaggedCache::class);
        $secondTaggedCache = m::mock(TaggedCache::class);
        $firstTagSet = m::mock(TagSet::class);
        $secondTagSet = m::mock(TagSet::class);

        $firstTaggable->shouldReceive('tags')->once()->with(['tag'])->andReturn($firstTaggedCache);
        $secondTaggable->shouldReceive('tags')->once()->with(['tag'])->andReturn($secondTaggedCache);
        $firstTaggedCache->shouldReceive('getTags')->once()->andReturn($firstTagSet);
        $secondTaggedCache->shouldReceive('getTags')->once()->andReturn($secondTagSet);
        $firstTagSet->shouldReceive('flush')->once()->andReturnFalse();
        $secondTagSet->shouldReceive('flush')->once()->andReturnTrue();

        $stack = new StackStore([$firstTaggable, $secondTaggable]);

        $this->assertFalse($stack->tags(['tag'])->clear());
    }

    public function testTagSetFlushAttemptsEveryLayerAndPreservesTheFirstException(): void
    {
        $firstException = new RuntimeException('first tag flush failed');
        $firstTaggable = $this->anyModeTaggableStore();
        $secondTaggable = $this->anyModeTaggableStore();
        $firstTaggedCache = m::mock(TaggedCache::class);
        $secondTaggedCache = m::mock(TaggedCache::class);
        $firstTagSet = m::mock(TagSet::class);
        $secondTagSet = m::mock(TagSet::class);

        $firstTaggable->shouldReceive('tags')->once()->with(['tag'])->andReturn($firstTaggedCache);
        $secondTaggable->shouldReceive('tags')->once()->with(['tag'])->andReturn($secondTaggedCache);
        $firstTaggedCache->shouldReceive('getTags')->once()->andReturn($firstTagSet);
        $secondTaggedCache->shouldReceive('getTags')->once()->andReturn($secondTagSet);
        $firstTagSet->shouldReceive('flush')->once()->andThrow($firstException);
        $secondTagSet->shouldReceive('flush')->once()->andThrow(new RuntimeException('second tag flush failed'));

        try {
            (new StackStore([$firstTaggable, $secondTaggable]))->tags(['tag'])->getTags()->flush();
            $this->fail('Expected the first tag flush exception to be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($firstException, $exception);
        }
    }

    public function testTagSetFlushStopsImmediatelyOnCancellation(): void
    {
        $cancellation = new CanceledException;
        $firstTaggable = $this->anyModeTaggableStore();
        $secondTaggable = $this->anyModeTaggableStore();
        $firstTaggedCache = m::mock(TaggedCache::class);
        $firstTagSet = m::mock(TagSet::class);

        $firstTaggable->shouldReceive('tags')->once()->with(['tag'])->andReturn($firstTaggedCache);
        $firstTaggedCache->shouldReceive('getTags')->once()->andReturn($firstTagSet);
        $firstTagSet->shouldReceive('flush')->once()->andThrow($cancellation);
        $secondTaggable->shouldNotReceive('tags');

        try {
            (new StackStore([$firstTaggable, $secondTaggable]))->tags(['tag'])->getTags()->flush();
            $this->fail('Expected the tag flush cancellation to be rethrown.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testClearDispatchesFailureEventWithExactTagSetException(): void
    {
        $exception = new RuntimeException('tag flush failed');
        $firstTaggable = $this->anyModeTaggableStore();
        $secondTaggable = $this->anyModeTaggableStore();
        $firstTaggedCache = m::mock(TaggedCache::class);
        $secondTaggedCache = m::mock(TaggedCache::class);
        $firstTagSet = m::mock(TagSet::class);
        $secondTagSet = m::mock(TagSet::class);
        $firstTaggable->shouldReceive('tags')->once()->with(['tag'])->andReturn($firstTaggedCache);
        $secondTaggable->shouldReceive('tags')->once()->with(['tag'])->andReturn($secondTaggedCache);
        $firstTaggedCache->shouldReceive('getTags')->once()->andReturn($firstTagSet);
        $secondTaggedCache->shouldReceive('getTags')->once()->andReturn($secondTagSet);
        $firstTagSet->shouldReceive('flush')->once()->andThrow($exception);
        $secondTagSet->shouldReceive('flush')->once()->andThrow(new RuntimeException('second tag flush failed'));

        $captured = [];
        $cache = (new Repository(new StackStore([$firstTaggable, $secondTaggable]), ['store' => 'stack']))->tags(['tag']);
        $cache->setEventDispatcher($this->capturingDispatcher($captured));

        try {
            $cache->clear();
            $this->fail('Expected the tagged flush exception to be rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame([CacheFlushing::class, CacheFlushFailed::class], array_map(get_class(...), $captured));
        $this->assertSame($exception, $captured[1]->exception);
    }

    public function testClearCancellationSkipsFailureEventAndRemainingTaggableLayers(): void
    {
        $cancellation = new CanceledException;
        $firstTaggable = $this->anyModeTaggableStore();
        $secondTaggable = $this->anyModeTaggableStore();
        $firstTaggedCache = m::mock(TaggedCache::class);
        $firstTagSet = m::mock(TagSet::class);
        $firstTaggable->shouldReceive('tags')->once()->with(['tag'])->andReturn($firstTaggedCache);
        $firstTaggedCache->shouldReceive('getTags')->once()->andReturn($firstTagSet);
        $firstTagSet->shouldReceive('flush')->once()->andThrow($cancellation);
        $secondTaggable->shouldNotReceive('tags');

        $captured = [];
        $cache = (new Repository(new StackStore([$firstTaggable, $secondTaggable]), ['store' => 'stack']))->tags(['tag']);
        $cache->setEventDispatcher($this->capturingDispatcher($captured));

        try {
            $cache->clear();
            $this->fail('Expected the tagged flush cancellation to be rethrown.');
        } catch (CanceledException $caught) {
            $this->assertSame($cancellation, $caught);
        }

        $this->assertSame([CacheFlushing::class], array_map(get_class(...), $captured));
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

    private function capturingDispatcher(array &$captured): Dispatcher|m\MockInterface
    {
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->withAnyArgs()->andReturnTrue();
        $events->shouldReceive('dispatch')
            ->andReturnUsing(function (object $event) use (&$captured): void {
                $captured[] = $event;
            });

        return $events;
    }
}

enum StackTaggedCacheKey: int
{
    case Zero = 0;
}
