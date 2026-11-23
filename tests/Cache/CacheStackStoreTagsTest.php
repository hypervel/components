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
use Hypervel\Cache\StackTagSet;
use Hypervel\Cache\TaggableStore;
use Hypervel\Cache\TaggedCache;
use Hypervel\Cache\TagMode;
use Hypervel\Cache\TagSet;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use stdClass;
use Swoole\Coroutine\CanceledException;
use Throwable;

class CacheStackStoreTagsTest extends TestCase
{
    use VerifiesDoubles;

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
            } catch (NotSupportedException) {
                continue;
            }

            $this->fail('Expected NotSupportedException was not thrown.');
        }
    }

    public function testStringKeyedLayersUseZeroBasedIndexesInCompositionErrors(): void
    {
        $stack = new StackStore([
            'memory' => $this->anyModeTaggableStore(),
            'persistent' => $this->nonTaggableStore(),
        ]);

        $this->expectException(NotSupportedException::class);
        $this->expectExceptionMessageIsOrContains('Stack layer 1');

        $stack->tags(['t']);
    }

    public function testInvalidNestedStackDoesNotCallGetTagMode(): void
    {
        $taggable = Double::for(TaggableStore::class)->strict();
        $taggable->expects('supportsTags')->returns(false);
        $taggable->expects('getTagMode')->never();

        $stack = new StackStore([$taggable]);

        $this->assertFalse($stack->supportsTags());
    }

    public function testValidationRunsOnce(): void
    {
        $taggable = Double::for(TaggableStore::class)->strict();
        $taggable->expects('supportsTags')->returns(true);
        $taggable->expects('getTagMode')->returns(TagMode::Any);

        $stack = new StackStore([$taggable]);

        $this->assertTrue($stack->supportsTags());
        $this->assertTrue($stack->supportsTags());
        $this->assertSame(TagMode::Any, $stack->getTagMode());
    }

    public function testTaggedPutWritesPlainUpperLayersAndTaggedLowerLayersWithTtlClamp(): void
    {
        $plain = $this->nonTaggableStore();
        $taggable = $this->anyModeTaggableStore();
        $taggedCache = Double::for(TaggedCache::class)->strict();

        $plain->expects('put')
            ->with('key', Argument::satisfies(fn (array $record): bool => $record['value'] === 'value' && isset($record['expiration'])), 3)
            ->returns(true);
        $taggable->expects('tags')->with(['tag'])->returns($taggedCache);
        $taggedCache->expects('put')
            ->with('key', Argument::satisfies(fn (array $record): bool => $record['value'] === 'value' && isset($record['expiration'])), 60)
            ->returns(true);

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
        $taggedCache = Double::for(TaggedCache::class)->strict();

        $plain->expects('put')->with('key', ['value' => 'value'], 3)->returns(true);
        $taggable->expects('tags')->with(['tag'])->returns($taggedCache);
        $taggedCache->expects('forever')->with('key', ['value' => 'value'])->returns(true);

        $stack = new StackStore([
            new StackStoreProxy($plain, 3),
            new StackStoreProxy($taggable),
        ]);

        $this->assertTrue($stack->tags(['tag'])->forever('key', 'value'));
    }

    public function testTaggedPutClampsTtlOnTaggableLayer(): void
    {
        $taggable = $this->anyModeTaggableStore();
        $taggedCache = Double::for(TaggedCache::class)->strict();

        $taggable->expects('tags')->with(['tag'])->returns($taggedCache);
        $taggedCache->expects('put')
            ->with('key', Argument::satisfies(fn (array $record): bool => $record['value'] === 'value' && isset($record['expiration'])), 3)
            ->returns(true);

        $stack = new StackStore([
            new StackStoreProxy($taggable, 3),
        ]);

        $this->assertTrue($stack->tags(['tag'])->put('key', 'value', 300));
    }

    public function testTaggedForeverClampsToPutOnTaggableLayerWithTtlOverride(): void
    {
        $taggable = $this->anyModeTaggableStore();
        $taggedCache = Double::for(TaggedCache::class)->strict();

        $taggable->expects('tags')->with(['tag'])->returns($taggedCache);
        $taggedCache->expects('put')->with('key', ['value' => 'value'], 3)->returns(true);
        $taggedCache->expects('forever')->never();

        $stack = new StackStore([
            new StackStoreProxy($taggable, 3),
        ]);

        $this->assertTrue($stack->tags(['tag'])->forever('key', 'value'));
    }

    public function testTaggedWriteRollsBackWrittenLayersOnFailure(): void
    {
        $plain = $this->nonTaggableStore();
        $taggable = $this->anyModeTaggableStore();
        $taggedCache = Double::for(TaggedCache::class)->strict();

        $plain->expects('put')->returns(true);
        $plain->expects('forget')->with('key')->returns(true);
        $taggable->expects('tags')->with(['tag'])->returns($taggedCache);
        $taggedCache->expects('put')->returns(false);

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
        $taggedCache = Double::for(TaggedCache::class)->strict();
        $exception = new RuntimeException('write failed');

        $plain->expects('put')->returns(true);
        $taggable->expects('tags')->with(['tag'])->returns($taggedCache);
        $taggedCache->expects('put')->throws($exception);
        $released = [];
        $taggable->expects('forget')->with('key')->resolves(function () use (&$released): bool {
            $released[] = 'taggable';

            return true;
        });
        $plain->expects('forget')->with('key')->resolves(function () use (&$released): bool {
            $released[] = 'plain';

            return true;
        });

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

        $this->assertSame(['taggable', 'plain'], $released);
    }

    public function testTaggedIncrementWritesThroughTaggedPath(): void
    {
        $taggable = $this->anyModeTaggableStore();
        $taggedCache = Double::for(TaggedCache::class)->strict();

        $taggable->expects('get')->with('counter')->returns(['value' => 1]);
        $taggable->expects('tags')->with(['tag'])->returns($taggedCache);
        $taggedCache->expects('forever')->with('counter', ['value' => 3])->returns(true);

        $stack = new StackStore([$taggable]);

        $this->assertSame(3, $stack->tags(['tag'])->increment('counter', 2));
    }

    public function testTaggedIncrementForgetsRecordThatExpiredDuringItsRead(): void
    {
        CarbonImmutable::setTestNow('2000-01-01 00:00:00');

        $taggable = $this->anyModeTaggableStore();
        $record = ['value' => 1, 'expiration' => CarbonImmutable::now()->getTimestamp() + 1];

        $taggable->expects('get')->with('counter')->resolves(function () use ($record): array {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(2));

            return $record;
        });
        $taggable->expects('forget')->with('counter')->returns(true);
        $taggable->expects('tags')->never();

        $stack = new StackStore([$taggable]);

        $this->assertSame(2, $stack->tags(['tag'])->increment('counter'));
    }

    public function testTaggedIncrementDoesNotTreatFailedRepairAsMissingAndPreservesLowerValue(): void
    {
        $plain = $this->nonTaggableStore();
        $taggable = $this->anyModeTaggableStore();
        $taggedCache = Double::for(TaggedCache::class)->strict();
        $record = ['value' => 5];
        $incrementedRecord = ['value' => 6];

        $plain->expects('get')->times(2)->with('counter')->returns(null);
        $taggable->expects('get')->times(2)->with('counter')->returns($record);
        $plain->expects('forever')->times(2)->with('counter', $record)->returns(false);

        $plain->expects('forever')->with('counter', $incrementedRecord)->returns(true);
        $taggable->expects('tags')->with(['tag'])->returns($taggedCache);
        $taggedCache->expects('forever')->with('counter', $incrementedRecord)->returns(false);
        $plain->expects('forget')->with('counter')->returns(true);

        $stack = new StackStore([$plain, $taggable]);

        $this->assertFalse($stack->tags(['tag'])->increment('counter'));
        $this->assertSame(5, $stack->get('counter'));
    }

    public function testTaggedRememberReadsPlainAndWritesTaggedOnMiss(): void
    {
        $taggable = $this->anyModeTaggableStore();
        $taggedCache = Double::for(TaggedCache::class)->strict();

        $taggable->expects('get')->with('0')->returns(null);
        $taggable->expects('tags')->with(['tag'])->returns($taggedCache);
        $taggedCache->expects('put')->with('0', Argument::type('array'), 60)->returns(true);

        $stack = new StackStore([$taggable]);

        $this->assertSame('computed', $stack->tags(['tag'])->remember(StackTaggedCacheKey::Zero, 60, fn () => 'computed'));
    }

    public function testTaggedRememberHitReadsPlainWithoutTaggedWrite(): void
    {
        $taggable = $this->anyModeTaggableStore();

        $taggable->expects('get')->with('key')->returns(['value' => 'cached']);
        $taggable->expects('tags')->never();

        $stack = new StackStore([$taggable]);

        $this->assertSame('cached', $stack->tags(['tag'])->remember('key', 60, fn () => 'computed'));
    }

    public function testTaggedRememberHandlesIncompleteClassBeforeDispatchingHitEvent(): void
    {
        $taggable = $this->anyModeTaggableStore();
        $taggable->expects('get')->with('key')->returns([
            'value' => unserialize(serialize(new stdClass), ['allowed_classes' => false]),
        ]);
        $taggable->expects('tags')->never();

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
        $taggedCache = Double::for(TaggedCache::class)->strict();

        $taggable->expects('get')->with('key')->returns(null);
        $taggable->expects('tags')->with(['tag'])->returns($taggedCache);
        $taggedCache->expects('put')->with('key', Argument::type('array'), 60)->returns(true);

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
        $taggedCache = Double::for(TaggedCache::class)->strict();

        $taggable->expects('tags')->with(['tag'])->returns($taggedCache);
        $taggedCache->expects('put')->with('key', Argument::type('array'), 60)->returns(false);

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

    public function testStackAnyModeTaggedCacheSkipsEventDispatchSeamWithoutListenersAndEntersItWithListeners(): void
    {
        $taggable = $this->anyModeTaggableStore();
        $taggedCache = Double::for(TaggedCache::class)->strict();
        $taggable->expects('get')->times(2)->returns(null);
        $taggable->expects('tags')->times(2)->with(['tag'])->returns($taggedCache);
        $taggedCache->expects('put')->times(2)->with(Argument::type('string'), Argument::type('array'), 60)->returns(true);

        $stack = new StackStore([$taggable]);
        $cache = new class($stack, new StackTagSet($stack, ['tag'])) extends StackTaggedCache {
            public int $eventCalls = 0;

            protected function event(object $event): void
            {
                ++$this->eventCalls;

                parent::event($event);
            }
        };
        $dispatcher = Double::for(Dispatcher::class)->strict();
        $dispatcher->allows('hasListeners')->returns(false);
        $dispatcher->expects('dispatch')->never();
        $cache->setEventDispatcher($dispatcher);

        $this->assertTrue($cache->add('first', 'value', 60));
        $this->assertSame(0, $cache->eventCalls);

        $captured = [];
        $cache->setEventDispatcher($this->capturingDispatcher($captured));

        $this->assertTrue($cache->add('second', 'value', 60));
        $this->assertSame(4, $cache->eventCalls);
        $this->assertSame(
            [RetrievingKey::class, CacheMissed::class, WritingKey::class, KeyWritten::class],
            array_map(get_class(...), $captured),
        );

        foreach ($captured as $event) {
            $this->assertSame(['tag'], $event->tags);
        }
    }

    public function testTaggedRememberDispatchesRepositoryReadFailureEventWhenTheStoreThrows(): void
    {
        $exception = new RuntimeException('read failed');
        $taggable = $this->anyModeTaggableStore();
        $taggable->expects('get')->with('key')->throws($exception);

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

    #[TestWith([false])]
    #[TestWith([true])]
    public function testTaggedRememberDoesNotDispatchFailureEventWhenTheStoreIsCanceled(bool $cleanupThrows): void
    {
        $cancellation = new CanceledException('read canceled');
        if ($cleanupThrows) {
            $cancellation = new RuntimeException('Cleanup failed.', previous: $cancellation);
        }
        $taggable = $this->anyModeTaggableStore();
        $taggable->expects('get')->with('key')->throws($cancellation);

        $captured = [];
        $stack = new StackStore([$taggable]);
        $cache = (new Repository($stack, ['store' => 'stack']))->tags(['tag']);
        $cache->setEventDispatcher($this->capturingDispatcher($captured));

        try {
            $cache->remember('key', 60, fn () => 'computed');
            $this->fail('Expected the tagged cache read cancellation to be rethrown.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame([RetrievingKey::class], array_map(get_class(...), $captured));
    }

    public function testTaggedPutDispatchesRepositoryWriteFailureEventWhenTheStoreThrows(): void
    {
        $exception = new RuntimeException('write failed');
        $taggable = $this->anyModeTaggableStore();
        $taggedCache = Double::for(TaggedCache::class)->strict();
        $taggable->expects('tags')->with(['tag'])->returns($taggedCache);
        $taggedCache->expects('put')->with('key', Argument::type('array'), 60)->throws($exception);
        $taggable->expects('forget')->with('key')->returns(true);

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

    #[TestWith([false])]
    #[TestWith([true])]
    public function testTaggedPutDoesNotDispatchFailureEventWhenTheStoreIsCanceled(bool $cleanupThrows): void
    {
        $cancellation = new CanceledException('write canceled');
        if ($cleanupThrows) {
            $cancellation = new RuntimeException('Cleanup failed.', previous: $cancellation);
        }
        $taggable = $this->anyModeTaggableStore();
        $taggedCache = Double::for(TaggedCache::class)->strict();
        $taggable->expects('tags')->with(['tag'])->returns($taggedCache);
        $taggedCache->expects('put')->with('key', Argument::type('array'), 60)->throws($cancellation);
        $taggable->expects('forget')->with('key')->returns(true);

        $captured = [];
        $stack = new StackStore([$taggable]);
        $cache = (new Repository($stack, ['store' => 'stack']))->tags(['tag']);
        $cache->setEventDispatcher($this->capturingDispatcher($captured));

        try {
            $cache->put('key', 'value', 60);
            $this->fail('Expected the tagged cache write cancellation to be rethrown.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame([WritingKey::class], array_map(get_class(...), $captured));
    }

    public function testTaggedForeverDispatchesRepositoryWriteFailureEventWhenTheStoreThrows(): void
    {
        $exception = new RuntimeException('forever write failed');
        $taggable = $this->anyModeTaggableStore();
        $taggedCache = Double::for(TaggedCache::class)->strict();
        $taggable->expects('tags')->with(['tag'])->returns($taggedCache);
        $taggedCache->expects('forever')->with('key', ['value' => 'value'])->throws($exception);
        $taggable->expects('forget')->with('key')->returns(true);

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

    #[TestWith([false])]
    #[TestWith([true])]
    public function testTaggedForeverDoesNotDispatchFailureEventWhenTheStoreIsCanceled(bool $cleanupThrows): void
    {
        $cancellation = new CanceledException('forever write canceled');
        if ($cleanupThrows) {
            $cancellation = new RuntimeException('Cleanup failed.', previous: $cancellation);
        }
        $taggable = $this->anyModeTaggableStore();
        $taggedCache = Double::for(TaggedCache::class)->strict();
        $taggable->expects('tags')->with(['tag'])->returns($taggedCache);
        $taggedCache->expects('forever')->with('key', ['value' => 'value'])->throws($cancellation);
        $taggable->expects('forget')->with('key')->returns(true);

        $captured = [];
        $stack = new StackStore([$taggable]);
        $cache = (new Repository($stack, ['store' => 'stack']))->tags(['tag']);
        $cache->setEventDispatcher($this->capturingDispatcher($captured));

        try {
            $cache->forever('key', 'value');
            $this->fail('Expected the tagged forever cancellation to be rethrown.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame([WritingKey::class], array_map(get_class(...), $captured));
    }

    public function testTaggedExpiredPutDispatchesRepositoryForgetFailureEventWhenTheStoreThrows(): void
    {
        $exception = new RuntimeException('forget failed');
        $taggable = $this->anyModeTaggableStore();
        $taggable->expects('forget')->with('key')->throws($exception);

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

    #[TestWith([false])]
    #[TestWith([true])]
    public function testTaggedExpiredPutDoesNotDispatchFailureEventWhenTheStoreIsCanceled(bool $cleanupThrows): void
    {
        $cancellation = new CanceledException('forget canceled');
        if ($cleanupThrows) {
            $cancellation = new RuntimeException('Cleanup failed.', previous: $cancellation);
        }
        $taggable = $this->anyModeTaggableStore();
        $taggable->expects('forget')->with('key')->throws($cancellation);

        $captured = [];
        $stack = new StackStore([$taggable]);
        $cache = (new Repository($stack, ['store' => 'stack']))->tags(['tag']);
        $cache->setEventDispatcher($this->capturingDispatcher($captured));

        try {
            $cache->put('key', 'value', 0);
            $this->fail('Expected the tagged forget cancellation to be rethrown.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame([ForgettingKey::class], array_map(get_class(...), $captured));
    }

    public function testTaggedPutWithExpiredTtlDispatchesRepositoryDeleteEvents(): void
    {
        $taggable = $this->anyModeTaggableStore();
        $taggable->expects('forget')->with('key')->returns(true);

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
            fn () => $cache->getAuthoritativeRaw('key'),
            fn () => $cache->getMultiple(['key']),
            fn () => $cache->delete('key'),
            fn () => $cache->deleteMultiple(['key']),
            fn () => $cache->touch('key', 60),
        ] as $operation) {
            try {
                $operation();
            } catch (BadMethodCallException) {
                continue;
            }

            $this->fail('Expected BadMethodCallException was not thrown.');
        }
    }

    public function testClearFlushesTaggableLayersOnly(): void
    {
        $taggable = $this->anyModeTaggableStore();
        $tagSet = Double::for(TagSet::class)->strict();
        $taggedCache = Double::for(TaggedCache::class)->strict();

        $taggable->expects('tags')->with(['tag'])->returns($taggedCache);
        $taggedCache->expects('getTags')->returns($tagSet);
        $tagSet->expects('flush')->returns(true);

        $plain = $this->nonTaggableStore();
        $stack = new StackStore([$plain, $taggable]);

        $this->assertTrue($stack->tags(['tag'])->clear());
        $plain->unused();
    }

    public function testClearFlushesEveryTaggableLayerAndAggregatesFalseResults(): void
    {
        $firstTaggable = $this->anyModeTaggableStore();
        $secondTaggable = $this->anyModeTaggableStore();
        $firstTaggedCache = Double::for(TaggedCache::class)->strict();
        $secondTaggedCache = Double::for(TaggedCache::class)->strict();
        $firstTagSet = Double::for(TagSet::class)->strict();
        $secondTagSet = Double::for(TagSet::class)->strict();

        $firstTaggable->expects('tags')->with(['tag'])->returns($firstTaggedCache);
        $secondTaggable->expects('tags')->with(['tag'])->returns($secondTaggedCache);
        $firstTaggedCache->expects('getTags')->returns($firstTagSet);
        $secondTaggedCache->expects('getTags')->returns($secondTagSet);
        $firstTagSet->expects('flush')->returns(false);
        $secondTagSet->expects('flush')->returns(true);

        $stack = new StackStore([$firstTaggable, $secondTaggable]);

        $this->assertFalse($stack->tags(['tag'])->clear());
    }

    public function testTagSetFlushAttemptsEveryLayerAndPreservesTheFirstException(): void
    {
        $firstException = new RuntimeException('first tag flush failed');
        $firstTaggable = $this->anyModeTaggableStore();
        $secondTaggable = $this->anyModeTaggableStore();
        $firstTaggedCache = Double::for(TaggedCache::class)->strict();
        $secondTaggedCache = Double::for(TaggedCache::class)->strict();
        $firstTagSet = Double::for(TagSet::class)->strict();
        $secondTagSet = Double::for(TagSet::class)->strict();

        $firstTaggable->expects('tags')->with(['tag'])->returns($firstTaggedCache);
        $secondTaggable->expects('tags')->with(['tag'])->returns($secondTaggedCache);
        $firstTaggedCache->expects('getTags')->returns($firstTagSet);
        $secondTaggedCache->expects('getTags')->returns($secondTagSet);
        $firstTagSet->expects('flush')->throws($firstException);
        $secondTagSet->expects('flush')->throws(new RuntimeException('second tag flush failed'));

        try {
            (new StackStore([$firstTaggable, $secondTaggable]))->tags(['tag'])->getTags()->flush();
            $this->fail('Expected the first tag flush exception to be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($firstException, $exception);
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testTagSetFlushStopsImmediatelyOnCancellation(bool $cleanupThrows): void
    {
        $cancellation = new CanceledException;
        if ($cleanupThrows) {
            $cancellation = new RuntimeException('Cleanup failed.', previous: $cancellation);
        }
        $firstTaggable = $this->anyModeTaggableStore();
        $secondTaggable = $this->anyModeTaggableStore();
        $firstTaggedCache = Double::for(TaggedCache::class)->strict();
        $firstTagSet = Double::for(TagSet::class)->strict();

        $firstTaggable->expects('tags')->with(['tag'])->returns($firstTaggedCache);
        $firstTaggedCache->expects('getTags')->returns($firstTagSet);
        $firstTagSet->expects('flush')->throws($cancellation);
        $secondTaggable->expects('tags')->never();

        try {
            (new StackStore([$firstTaggable, $secondTaggable]))->tags(['tag'])->getTags()->flush();
            $this->fail('Expected the tag flush cancellation to be rethrown.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testClearDispatchesFailureEventWithExactTagSetException(): void
    {
        $exception = new RuntimeException('tag flush failed');
        $firstTaggable = $this->anyModeTaggableStore();
        $secondTaggable = $this->anyModeTaggableStore();
        $firstTaggedCache = Double::for(TaggedCache::class)->strict();
        $secondTaggedCache = Double::for(TaggedCache::class)->strict();
        $firstTagSet = Double::for(TagSet::class)->strict();
        $secondTagSet = Double::for(TagSet::class)->strict();
        $firstTaggable->expects('tags')->with(['tag'])->returns($firstTaggedCache);
        $secondTaggable->expects('tags')->with(['tag'])->returns($secondTaggedCache);
        $firstTaggedCache->expects('getTags')->returns($firstTagSet);
        $secondTaggedCache->expects('getTags')->returns($secondTagSet);
        $firstTagSet->expects('flush')->throws($exception);
        $secondTagSet->expects('flush')->throws(new RuntimeException('second tag flush failed'));

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

    #[TestWith([false])]
    #[TestWith([true])]
    public function testClearCancellationSkipsFailureEventAndRemainingTaggableLayers(bool $cleanupThrows): void
    {
        $cancellation = new CanceledException;
        if ($cleanupThrows) {
            $cancellation = new RuntimeException('Cleanup failed.', previous: $cancellation);
        }
        $firstTaggable = $this->anyModeTaggableStore();
        $secondTaggable = $this->anyModeTaggableStore();
        $firstTaggedCache = Double::for(TaggedCache::class)->strict();
        $firstTagSet = Double::for(TagSet::class)->strict();
        $firstTaggable->expects('tags')->with(['tag'])->returns($firstTaggedCache);
        $firstTaggedCache->expects('getTags')->returns($firstTagSet);
        $firstTagSet->expects('flush')->throws($cancellation);
        $secondTaggable->expects('tags')->never();

        $captured = [];
        $cache = (new Repository(new StackStore([$firstTaggable, $secondTaggable]), ['store' => 'stack']))->tags(['tag']);
        $cache->setEventDispatcher($this->capturingDispatcher($captured));

        try {
            $cache->clear();
            $this->fail('Expected the tagged flush cancellation to be rethrown.');
        } catch (Throwable $caught) {
            $this->assertSame($cancellation, $caught);
        }

        $this->assertSame([CacheFlushing::class], array_map(get_class(...), $captured));
    }

    /**
     * Create an any-mode taggable store.
     */
    private function anyModeTaggableStore(): TaggableStore&DoubleInterface
    {
        $store = Double::for(TaggableStore::class)->strict();
        $store->allows('supportsTags')->returns(true);
        $store->allows('getTagMode')->returns(TagMode::Any);

        return $store;
    }

    /**
     * Create an all-mode taggable store.
     */
    private function allModeTaggableStore(): TaggableStore&DoubleInterface
    {
        $store = Double::for(TaggableStore::class)->strict();
        $store->allows('supportsTags')->returns(true);
        $store->allows('getTagMode')->returns(TagMode::All);

        return $store;
    }

    /**
     * Create a store without tag support.
     */
    private function nonTaggableStore(): Store&DoubleInterface
    {
        return Double::for(Store::class)->strict();
    }

    /**
     * Create a dispatcher that records emitted events.
     */
    private function capturingDispatcher(array &$captured): Dispatcher&DoubleInterface
    {
        $events = Double::for(Dispatcher::class)->strict();
        $events->allows('hasListeners')->returns(true);
        $events->allows('dispatch')
            ->resolves(function (object $event) use (&$captured): void {
                $captured[] = $event;
            });

        return $events;
    }
}

enum StackTaggedCacheKey: int
{
    case Zero = 0;
}
