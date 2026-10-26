<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Events\CacheFlushed;
use Hypervel\Cache\Events\CacheFlushFailed;
use Hypervel\Cache\Events\CacheFlushing;
use Hypervel\Cache\Events\CacheHit;
use Hypervel\Cache\Events\CacheLocksFlushed;
use Hypervel\Cache\Events\CacheLocksFlushFailed;
use Hypervel\Cache\Events\CacheLocksFlushing;
use Hypervel\Cache\Events\CacheMissed;
use Hypervel\Cache\Events\ForgettingKey;
use Hypervel\Cache\Events\KeyForgetFailed;
use Hypervel\Cache\Events\KeyForgotten;
use Hypervel\Cache\Events\KeyRetrievalFailed;
use Hypervel\Cache\Events\KeyWriteFailed;
use Hypervel\Cache\Events\KeyWritten;
use Hypervel\Cache\Events\ManyKeysRetrievalFailed;
use Hypervel\Cache\Events\RetrievingKey;
use Hypervel\Cache\Events\RetrievingManyKeys;
use Hypervel\Cache\Events\WritingKey;
use Hypervel\Cache\Events\WritingManyKeys;
use Hypervel\Cache\NamespacedTaggedCache;
use Hypervel\Cache\Repository;
use Hypervel\Cache\VersionedTagSet;
use Hypervel\Contracts\Cache\CanFlushLocks;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Contracts\Events\Dispatcher as DispatcherContract;
use Hypervel\Events\Dispatcher;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

class CacheEventsTest extends TestCase
{
    public function testRepositorySkipsEventDispatchSeamWithoutListenersAndEntersItWithListeners(): void
    {
        $repository = new class(new ArrayStore, ['store' => 'array']) extends Repository {
            public int $eventCalls = 0;

            /**
             * Count event dispatches through the repository.
             */
            protected function event(object $event): void
            {
                ++$this->eventCalls;

                parent::event($event);
            }
        };
        $dispatcher = m::mock(DispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->withAnyArgs()->andReturnFalse();
        $dispatcher->shouldNotReceive('dispatch');
        $repository->setEventDispatcher($dispatcher);

        $this->assertNull($repository->get('missing'));
        $this->assertTrue($repository->put('key', 'value', 60));
        $this->assertTrue($repository->putMany(['one' => 1, 'two' => 2], 60));
        $this->assertTrue($repository->clear());
        $this->assertSame(0, $repository->eventCalls);

        $repository->setEventDispatcher($this->getDispatcher());

        $this->assertTrue($repository->put('key', 'value', 60));
        $this->assertSame(2, $repository->eventCalls);
        $this->assertEventDispatched(WritingKey::class, ['key' => 'key']);
        $this->assertEventDispatched(KeyWritten::class, ['key' => 'key']);

        $this->assertSame([], $this->dispatchedEvents);
    }

    public function testNamespacedTaggedCacheSkipsEventDispatchSeamWithoutListenersAndEntersItWithListeners(): void
    {
        $store = new ArrayStore;
        $repository = new class($store, new VersionedTagSet($store, ['tag'])) extends NamespacedTaggedCache {
            public int $eventCalls = 0;

            /**
             * Count event dispatches through the tagged repository.
             */
            protected function event(object $event): void
            {
                ++$this->eventCalls;

                parent::event($event);
            }
        };
        $dispatcher = m::mock(DispatcherContract::class);
        $dispatcher->shouldReceive('hasListeners')->withAnyArgs()->andReturnFalse();
        $dispatcher->shouldNotReceive('dispatch');
        $repository->setEventDispatcher($dispatcher);

        $this->assertNull($repository->get('missing'));
        $this->assertTrue($repository->put('key', 'value', 60));
        $this->assertTrue($repository->putMany(['one' => 1, 'two' => 2], 60));
        $this->assertTrue($repository->flush());
        $this->assertSame(0, $repository->eventCalls);

        $repository->setEventDispatcher($this->getDispatcher());

        $this->assertTrue($repository->put('key', 'value', 60));
        $this->assertSame(2, $repository->eventCalls);
        $this->assertEventDispatched(WritingKey::class, ['key' => 'key', 'tags' => ['tag']]);
        $this->assertEventDispatched(KeyWritten::class, ['key' => 'key', 'tags' => ['tag']]);

        $this->assertSame([], $this->dispatchedEvents);
    }

    public function testHasTriggersEvents(): void
    {
        $dispatcher = $this->getDispatcher();
        $repository = $this->getRepository($dispatcher);

        $this->assertFalse($repository->has('foo'));
        $this->assertEventDispatched(RetrievingKey::class, ['storeName' => 'array', 'key' => 'foo']);
        $this->assertEventDispatched(CacheMissed::class, ['storeName' => 'array', 'key' => 'foo']);

        $this->assertTrue($repository->has('baz'));
        $this->assertEventDispatched(RetrievingKey::class, ['storeName' => 'array', 'key' => 'baz']);
        $this->assertEventDispatched(CacheHit::class, ['storeName' => 'array', 'key' => 'baz', 'value' => 'qux']);

        $this->assertFalse($repository->tags('taylor')->has('foo'));
        $this->assertEventDispatched(RetrievingKey::class, ['storeName' => 'array', 'key' => 'foo', 'tags' => ['taylor']]);
        $this->assertEventDispatched(CacheMissed::class, ['storeName' => 'array', 'key' => 'foo', 'tags' => ['taylor']]);

        $this->assertTrue($repository->tags('taylor')->has('baz'));
        $this->assertEventDispatched(RetrievingKey::class, ['storeName' => 'array', 'key' => 'baz', 'tags' => ['taylor']]);
        $this->assertEventDispatched(CacheHit::class, ['storeName' => 'array', 'key' => 'baz', 'value' => 'qux', 'tags' => ['taylor']]);

        $this->assertSame([], $this->dispatchedEvents);
    }

    public function testGetTriggersEvents(): void
    {
        $dispatcher = $this->getDispatcher();
        $repository = $this->getRepository($dispatcher);

        $this->assertNull($repository->get('foo'));
        $this->assertEventDispatched(RetrievingKey::class, ['storeName' => 'array', 'key' => 'foo']);
        $this->assertEventDispatched(CacheMissed::class, ['storeName' => 'array', 'key' => 'foo']);

        $this->assertSame(['foo' => null, 'bar' => null], $repository->get(['foo', 'bar']));
        $this->assertEventDispatched(RetrievingManyKeys::class, ['storeName' => 'array', 'keys' => ['foo', 'bar']]);
        $this->assertEventDispatched(CacheMissed::class, ['storeName' => 'array', 'key' => 'foo']);
        $this->assertEventDispatched(CacheMissed::class, ['storeName' => 'array', 'key' => 'bar']);

        $this->assertSame('qux', $repository->get('baz'));
        $this->assertEventDispatched(RetrievingKey::class, ['storeName' => 'array', 'key' => 'baz']);
        $this->assertEventDispatched(CacheHit::class, ['storeName' => 'array', 'key' => 'baz', 'value' => 'qux']);

        $this->assertNull($repository->tags('taylor')->get('foo'));
        $this->assertEventDispatched(RetrievingKey::class, ['storeName' => 'array', 'key' => 'foo', 'tags' => ['taylor']]);
        $this->assertEventDispatched(CacheMissed::class, ['storeName' => 'array', 'key' => 'foo', 'tags' => ['taylor']]);

        $this->assertSame('qux', $repository->tags('taylor')->get('baz'));
        $this->assertEventDispatched(RetrievingKey::class, ['storeName' => 'array', 'key' => 'baz', 'tags' => ['taylor']]);
        $this->assertEventDispatched(CacheHit::class, ['storeName' => 'array', 'key' => 'baz', 'value' => 'qux', 'tags' => ['taylor']]);

        $this->assertSame([], $this->dispatchedEvents);
    }

    public function testTaggedManyTriggersManyEvents(): void
    {
        $dispatcher = $this->getDispatcher();
        $repository = $this->getRepository($dispatcher);

        $this->assertSame([
            'baz' => 'qux',
            'foo' => null,
        ], $repository->tags('taylor')->many(['baz', 'foo']));
        $this->assertEventDispatched(RetrievingManyKeys::class, ['keys' => ['baz', 'foo'], 'tags' => ['taylor']]);
        $this->assertEventDispatched(CacheHit::class, ['key' => 'baz', 'value' => 'qux', 'tags' => ['taylor']]);
        $this->assertEventDispatched(CacheMissed::class, ['key' => 'foo', 'tags' => ['taylor']]);

        $this->assertSame([], $this->dispatchedEvents);
    }

    public function testGetDispatchesFailureEventWhenTheStoreThrows(): void
    {
        $exception = new RuntimeException('The cache read failed.');
        $store = m::mock(Store::class);
        $store->expects('get')->with('foo')->andThrow($exception);
        $events = [];
        $repository = new Repository($store, ['store' => 'array']);
        $repository->setEventDispatcher($this->getCapturingDispatcher($events));

        try {
            $repository->get('foo');
            $this->fail('Expected the cache read exception to be rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame(
            [RetrievingKey::class, KeyRetrievalFailed::class],
            array_map(get_class(...), $events),
        );
        $this->assertSame('array', $events[1]->storeName);
        $this->assertSame('foo', $events[1]->key);
        $this->assertSame($exception, $events[1]->exception);
    }

    public function testManyDispatchesFailureEventWhenTheStoreThrows(): void
    {
        $exception = new RuntimeException('The cache batch read failed.');
        $store = m::mock(Store::class);
        $store->expects('many')->with(['foo', 'bar'])->andThrow($exception);
        $events = [];
        $repository = new Repository($store, ['store' => 'array']);
        $repository->setEventDispatcher($this->getCapturingDispatcher($events));

        try {
            $repository->many(['foo', 'bar']);
            $this->fail('Expected the cache batch read exception to be rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame(
            [RetrievingManyKeys::class, ManyKeysRetrievalFailed::class],
            array_map(get_class(...), $events),
        );
        $this->assertSame('array', $events[1]->storeName);
        $this->assertSame(['foo', 'bar'], $events[1]->keys);
        $this->assertSame($exception, $events[1]->exception);
    }

    public function testPutDispatchesFailureEventWhenTheStoreThrows(): void
    {
        $exception = new RuntimeException('The cache write failed.');
        $store = m::mock(Store::class);
        $store->expects('put')->with('foo', 'bar', 60)->andThrow($exception);
        $events = [];
        $repository = new Repository($store, ['store' => 'array']);
        $repository->setEventDispatcher($this->getCapturingDispatcher($events));

        try {
            $repository->put('foo', 'bar', 60);
            $this->fail('Expected the cache write exception to be rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame(
            [WritingKey::class, KeyWriteFailed::class],
            array_map(get_class(...), $events),
        );
        $this->assertSame('foo', $events[1]->key);
        $this->assertSame('bar', $events[1]->value);
        $this->assertSame(60, $events[1]->seconds);
        $this->assertSame($exception, $events[1]->exception);
    }

    #[DataProvider('falseWriteOperations')]
    public function testFalseWriteResultDispatchesFailureEventsWithoutException(
        string $method,
        array $arguments,
        array $eventClasses,
        array $keys,
    ): void {
        $store = m::mock(Store::class);
        $store->expects($method)->with(...$arguments)->andReturnFalse();
        $events = [];
        $repository = new Repository($store, ['store' => 'array']);
        $repository->setEventDispatcher($this->getCapturingDispatcher($events));

        $this->assertFalse($repository->{$method}(...$arguments));
        $this->assertSame($eventClasses, array_map(get_class(...), $events));

        $failures = array_slice($events, 1);
        $this->assertSame($keys, array_map(static fn (KeyWriteFailed $event): string => $event->key, $failures));

        foreach ($failures as $failure) {
            $this->assertNull($failure->exception);
        }
    }

    /**
     * Provide cache writes that report failure without throwing.
     */
    public static function falseWriteOperations(): array
    {
        return [
            'put' => ['put', ['foo', 'bar', 60], [WritingKey::class, KeyWriteFailed::class], ['foo']],
            'put many' => ['putMany', [['foo' => 'bar', 'baz' => 'qux'], 60], [WritingManyKeys::class, KeyWriteFailed::class, KeyWriteFailed::class], ['foo', 'baz']],
            'forever' => ['forever', ['foo', 'bar'], [WritingKey::class, KeyWriteFailed::class], ['foo']],
        ];
    }

    public function testPutManyDispatchesFailureEventsWhenTheStoreThrows(): void
    {
        $exception = new RuntimeException('The cache batch write failed.');
        $values = ['foo' => 'bar', 'baz' => 'qux'];
        $store = m::mock(Store::class);
        $store->expects('putMany')->with($values, 60)->andThrow($exception);
        $events = [];
        $repository = new Repository($store, ['store' => 'array']);
        $repository->setEventDispatcher($this->getCapturingDispatcher($events));

        try {
            $repository->putMany($values, 60);
            $this->fail('Expected the cache batch write exception to be rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame(
            [WritingManyKeys::class, KeyWriteFailed::class, KeyWriteFailed::class],
            array_map(get_class(...), $events),
        );
        $this->assertSame(['foo', 'baz'], array_map(static fn (KeyWriteFailed $event): string => $event->key, array_slice($events, 1)));
        $this->assertSame($exception, $events[1]->exception);
        $this->assertSame($exception, $events[2]->exception);
    }

    public function testForeverDispatchesFailureEventWhenTheStoreThrows(): void
    {
        $exception = new RuntimeException('The cache forever write failed.');
        $store = m::mock(Store::class);
        $store->expects('forever')->with('foo', 'bar')->andThrow($exception);
        $events = [];
        $repository = new Repository($store, ['store' => 'array']);
        $repository->setEventDispatcher($this->getCapturingDispatcher($events));

        try {
            $repository->forever('foo', 'bar');
            $this->fail('Expected the cache forever write exception to be rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame(
            [WritingKey::class, KeyWriteFailed::class],
            array_map(get_class(...), $events),
        );
        $this->assertSame('foo', $events[1]->key);
        $this->assertNull($events[1]->seconds);
        $this->assertSame($exception, $events[1]->exception);
    }

    public function testForgetDispatchesFailureEventWhenTheStoreThrows(): void
    {
        $exception = new RuntimeException('The cache forget failed.');
        $store = m::mock(Store::class);
        $store->expects('forget')->with('foo')->andThrow($exception);
        $events = [];
        $repository = new Repository($store, ['store' => 'array']);
        $repository->setEventDispatcher($this->getCapturingDispatcher($events));

        try {
            $repository->forget('foo');
            $this->fail('Expected the cache forget exception to be rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame(
            [ForgettingKey::class, KeyForgetFailed::class],
            array_map(get_class(...), $events),
        );
        $this->assertSame('foo', $events[1]->key);
        $this->assertSame($exception, $events[1]->exception);
    }

    #[DataProvider('repositoryCancellationOperations')]
    public function testCancellationDoesNotDispatchFailureEvents(
        string $storeMethod,
        array $storeArguments,
        string $repositoryMethod,
        array $repositoryArguments,
        string $startedEvent,
    ): void {
        $cancellation = new CanceledException('cache operation canceled');
        $store = m::mock(Store::class, CanFlushLocks::class);
        $store->shouldReceive('supportsFlushingLocks')->andReturnTrue()->byDefault();
        $store->expects($storeMethod)
            ->with(...$storeArguments)
            ->andThrow($cancellation);
        $events = [];
        $repository = new Repository($store, ['store' => 'array']);
        $repository->setEventDispatcher($this->getCapturingDispatcher($events));

        try {
            $repository->{$repositoryMethod}(...$repositoryArguments);
            $this->fail('The cache operation was expected to be canceled.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame([$startedEvent], array_map(get_class(...), $events));
    }

    /**
     * Provide cache operations that propagate coroutine cancellation.
     */
    public static function repositoryCancellationOperations(): array
    {
        return [
            'get' => ['get', ['foo'], 'get', ['foo'], RetrievingKey::class],
            'many' => ['many', [['foo', 'bar']], 'many', [['foo', 'bar']], RetrievingManyKeys::class],
            'put' => ['put', ['foo', 'bar', 60], 'put', ['foo', 'bar', 60], WritingKey::class],
            'put many' => ['putMany', [['foo' => 'bar'], 60], 'putMany', [['foo' => 'bar'], 60], WritingManyKeys::class],
            'forever' => ['forever', ['foo', 'bar'], 'forever', ['foo', 'bar'], WritingKey::class],
            'forget' => ['forget', ['foo'], 'forget', ['foo'], ForgettingKey::class],
            'clear' => ['flush', [], 'clear', [], CacheFlushing::class],
            'flush locks' => ['flushLocks', [], 'flushLocks', [], CacheLocksFlushing::class],
        ];
    }

    public function testPullTriggersEvents(): void
    {
        $dispatcher = $this->getDispatcher();
        $repository = $this->getRepository($dispatcher);

        $this->assertSame('qux', $repository->pull('baz'));
        $this->assertEventDispatched(RetrievingKey::class, ['storeName' => 'array', 'key' => 'baz']);
        $this->assertEventDispatched(CacheHit::class, ['storeName' => 'array', 'key' => 'baz', 'value' => 'qux']);
        $this->assertEventDispatched(ForgettingKey::class, ['storeName' => 'array', 'key' => 'baz']);
        $this->assertEventDispatched(KeyForgotten::class, ['storeName' => 'array', 'key' => 'baz']);

        $this->assertSame([], $this->dispatchedEvents);
    }

    public function testPullTriggersEventsUsingTags(): void
    {
        $dispatcher = $this->getDispatcher();
        $repository = $this->getRepository($dispatcher);

        $this->assertSame('qux', $repository->tags('taylor')->pull('baz'));
        $this->assertEventDispatched(RetrievingKey::class, ['storeName' => 'array', 'key' => 'baz', 'tags' => ['taylor']]);
        $this->assertEventDispatched(CacheHit::class, ['storeName' => 'array', 'key' => 'baz', 'value' => 'qux', 'tags' => ['taylor']]);
        $this->assertEventDispatched(ForgettingKey::class, ['storeName' => 'array', 'key' => 'baz', 'tags' => ['taylor']]);
        $this->assertEventDispatched(KeyForgotten::class, ['storeName' => 'array', 'key' => 'baz', 'tags' => ['taylor']]);

        $this->assertSame([], $this->dispatchedEvents);
    }

    public function testPutTriggersEvents(): void
    {
        $dispatcher = $this->getDispatcher();
        $repository = $this->getRepository($dispatcher);

        $repository->put('foo', 'bar', 99);
        $this->assertEventDispatched(WritingKey::class, ['storeName' => 'array', 'key' => 'foo', 'value' => 'bar', 'seconds' => 99]);
        $this->assertEventDispatched(KeyWritten::class, ['storeName' => 'array', 'key' => 'foo', 'value' => 'bar', 'seconds' => 99]);

        $repository->putMany(['foo' => 'bar', 'baz' => 'qux'], 99);
        $this->assertEventDispatched(WritingManyKeys::class, ['storeName' => 'array', 'keys' => ['foo', 'baz'], 'values' => ['bar', 'qux'], 'seconds' => 99]);
        $this->assertEventDispatched(KeyWritten::class, ['storeName' => 'array', 'key' => 'foo', 'value' => 'bar', 'seconds' => 99]);
        $this->assertEventDispatched(KeyWritten::class, ['storeName' => 'array', 'key' => 'baz', 'value' => 'qux', 'seconds' => 99]);

        $repository->tags('taylor')->put('foo', 'bar', 99);
        $this->assertEventDispatched(WritingKey::class, ['storeName' => 'array', 'key' => 'foo', 'value' => 'bar', 'seconds' => 99, 'tags' => ['taylor']]);
        $this->assertEventDispatched(KeyWritten::class, ['storeName' => 'array', 'key' => 'foo', 'value' => 'bar', 'seconds' => 99, 'tags' => ['taylor']]);

        $this->assertSame([], $this->dispatchedEvents);
    }

    public function testAddTriggersEvents(): void
    {
        $dispatcher = $this->getDispatcher();
        $repository = $this->getRepository($dispatcher);

        $this->assertTrue($repository->add('foo', 'bar', 99));
        $this->assertEventDispatched(RetrievingKey::class, ['storeName' => 'array', 'key' => 'foo']);
        $this->assertEventDispatched(CacheMissed::class, ['storeName' => 'array', 'key' => 'foo']);
        $this->assertEventDispatched(WritingKey::class, ['storeName' => 'array', 'key' => 'foo', 'value' => 'bar', 'seconds' => 99]);
        $this->assertEventDispatched(KeyWritten::class, ['storeName' => 'array', 'key' => 'foo', 'value' => 'bar', 'seconds' => 99]);

        $this->assertTrue($repository->tags('taylor')->add('foo', 'bar', 99));
        $this->assertEventDispatched(RetrievingKey::class, ['storeName' => 'array', 'key' => 'foo', 'tags' => ['taylor']]);
        $this->assertEventDispatched(CacheMissed::class, ['storeName' => 'array', 'key' => 'foo', 'tags' => ['taylor']]);
        $this->assertEventDispatched(WritingKey::class, ['storeName' => 'array', 'key' => 'foo', 'value' => 'bar', 'seconds' => 99, 'tags' => ['taylor']]);
        $this->assertEventDispatched(KeyWritten::class, ['storeName' => 'array', 'key' => 'foo', 'value' => 'bar', 'seconds' => 99, 'tags' => ['taylor']]);

        $this->assertSame([], $this->dispatchedEvents);
    }

    public function testForeverTriggersEvents(): void
    {
        $dispatcher = $this->getDispatcher();
        $repository = $this->getRepository($dispatcher);

        $repository->forever('foo', 'bar');
        $this->assertEventDispatched(WritingKey::class, ['storeName' => 'array', 'key' => 'foo', 'value' => 'bar', 'seconds' => null]);
        $this->assertEventDispatched(KeyWritten::class, ['storeName' => 'array', 'key' => 'foo', 'value' => 'bar', 'seconds' => null]);

        $repository->tags('taylor')->forever('foo', 'bar');
        $this->assertEventDispatched(WritingKey::class, ['storeName' => 'array', 'key' => 'foo', 'value' => 'bar', 'seconds' => null, 'tags' => ['taylor']]);
        $this->assertEventDispatched(KeyWritten::class, ['storeName' => 'array', 'key' => 'foo', 'value' => 'bar', 'seconds' => null, 'tags' => ['taylor']]);

        $this->assertSame([], $this->dispatchedEvents);
    }

    public function testRememberTriggersEvents(): void
    {
        $dispatcher = $this->getDispatcher();
        $repository = $this->getRepository($dispatcher);

        $this->assertSame('bar', $repository->remember('foo', 99, function (): string {
            return 'bar';
        }));
        $this->assertEventDispatched(RetrievingKey::class, ['storeName' => 'array', 'key' => 'foo']);
        $this->assertEventDispatched(CacheMissed::class, ['storeName' => 'array', 'key' => 'foo']);
        $this->assertEventDispatched(WritingKey::class, ['storeName' => 'array', 'key' => 'foo', 'value' => 'bar', 'seconds' => 99]);
        $this->assertEventDispatched(KeyWritten::class, ['storeName' => 'array', 'key' => 'foo', 'value' => 'bar', 'seconds' => 99]);

        $this->assertSame('bar', $repository->tags('taylor')->remember('foo', 99, function (): string {
            return 'bar';
        }));
        $this->assertEventDispatched(RetrievingKey::class, ['storeName' => 'array', 'key' => 'foo', 'tags' => ['taylor']]);
        $this->assertEventDispatched(CacheMissed::class, ['storeName' => 'array', 'key' => 'foo', 'tags' => ['taylor']]);
        $this->assertEventDispatched(WritingKey::class, ['storeName' => 'array', 'key' => 'foo', 'value' => 'bar', 'seconds' => 99, 'tags' => ['taylor']]);
        $this->assertEventDispatched(KeyWritten::class, ['storeName' => 'array', 'key' => 'foo', 'value' => 'bar', 'seconds' => 99, 'tags' => ['taylor']]);

        $this->assertSame([], $this->dispatchedEvents);
    }

    public function testRememberForeverTriggersEvents(): void
    {
        $dispatcher = $this->getDispatcher();
        $repository = $this->getRepository($dispatcher);

        $this->assertSame('bar', $repository->rememberForever('foo', function (): string {
            return 'bar';
        }));
        $this->assertEventDispatched(RetrievingKey::class, ['storeName' => 'array', 'key' => 'foo']);
        $this->assertEventDispatched(CacheMissed::class, ['storeName' => 'array', 'key' => 'foo']);
        $this->assertEventDispatched(WritingKey::class, ['storeName' => 'array', 'key' => 'foo', 'value' => 'bar', 'seconds' => null]);
        $this->assertEventDispatched(KeyWritten::class, ['storeName' => 'array', 'key' => 'foo', 'value' => 'bar', 'seconds' => null]);

        $this->assertSame('bar', $repository->tags('taylor')->rememberForever('foo', function (): string {
            return 'bar';
        }));
        $this->assertEventDispatched(RetrievingKey::class, ['storeName' => 'array', 'key' => 'foo', 'tags' => ['taylor']]);
        $this->assertEventDispatched(CacheMissed::class, ['storeName' => 'array', 'key' => 'foo', 'tags' => ['taylor']]);
        $this->assertEventDispatched(WritingKey::class, ['storeName' => 'array', 'key' => 'foo', 'value' => 'bar', 'seconds' => null, 'tags' => ['taylor']]);
        $this->assertEventDispatched(KeyWritten::class, ['storeName' => 'array', 'key' => 'foo', 'value' => 'bar', 'seconds' => null, 'tags' => ['taylor']]);

        $this->assertSame([], $this->dispatchedEvents);
    }

    public function testForgetTriggersEvents(): void
    {
        $dispatcher = $this->getDispatcher();
        $repository = $this->getRepository($dispatcher);

        $this->assertTrue($repository->forget('baz'));
        $this->assertEventDispatched(ForgettingKey::class, ['storeName' => 'array', 'key' => 'baz']);
        $this->assertEventDispatched(KeyForgotten::class, ['storeName' => 'array', 'key' => 'baz']);

        $this->assertTrue($repository->tags('taylor')->forget('baz'));
        $this->assertEventDispatched(ForgettingKey::class, ['storeName' => 'array', 'key' => 'baz', 'tags' => ['taylor']]);
        $this->assertEventDispatched(KeyForgotten::class, ['storeName' => 'array', 'key' => 'baz', 'tags' => ['taylor']]);

        $this->assertSame([], $this->dispatchedEvents);
    }

    public function testForgetDoesTriggerFailedEventOnFailure(): void
    {
        $dispatcher = $this->getDispatcher();
        $store = m::mock(Store::class);
        $store->expects('forget')->andReturn(false);
        $repository = new Repository($store);
        $repository->setEventDispatcher($dispatcher);

        $this->assertFalse($repository->forget('baz'));
        $this->assertEventDispatched(ForgettingKey::class, ['key' => 'baz']);
        $this->assertEventDispatched(KeyForgetFailed::class, ['key' => 'baz', 'exception' => null]);

        $this->assertSame([], $this->dispatchedEvents);
    }

    public function testFlushTriggersEvents(): void
    {
        $dispatcher = $this->getDispatcher();
        $repository = $this->getRepository($dispatcher);

        $this->assertTrue($repository->clear());
        $this->assertEventDispatched(CacheFlushing::class, ['storeName' => 'array']);
        $this->assertEventDispatched(CacheFlushed::class, ['storeName' => 'array']);

        $this->assertSame([], $this->dispatchedEvents);
    }

    public function testEmptyTaggedFlushIsIdempotentAndDispatchesSuccessEvents(): void
    {
        $events = [];
        $repository = new Repository(new ArrayStore, ['store' => 'array']);
        $repository->setEventDispatcher($this->getCapturingDispatcher($events));

        $this->assertTrue($repository->tags('missing')->flush());
        $this->assertTrue($repository->tags('missing')->flush());
        $this->assertSame([
            CacheFlushing::class,
            CacheFlushed::class,
            CacheFlushing::class,
            CacheFlushed::class,
        ], array_map(get_class(...), $events));
    }

    public function testFlushDispatchesFailureEventWithExactException(): void
    {
        $exception = new RuntimeException('The cache flush failed.');
        $store = m::mock(Store::class);
        $store->expects('flush')->andThrow($exception);
        $events = [];
        $repository = new Repository($store, ['store' => 'array']);
        $repository->setEventDispatcher($this->getCapturingDispatcher($events));

        try {
            $repository->clear();
            $this->fail('Expected the cache flush exception to be rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame([CacheFlushing::class, CacheFlushFailed::class], array_map(get_class(...), $events));
        $this->assertSame($exception, $events[1]->exception);
    }

    public function testFlushLocksTriggersEvents(): void
    {
        $dispatcher = $this->getDispatcher();
        $repository = $this->getRepository($dispatcher);

        $this->assertTrue($repository->flushLocks());
        $this->assertEventDispatched(CacheLocksFlushing::class, ['storeName' => 'array']);
        $this->assertEventDispatched(CacheLocksFlushed::class, ['storeName' => 'array']);

        $this->assertSame([], $this->dispatchedEvents);
    }

    public function testFlushLocksDispatchesFailureEventWithExactException(): void
    {
        $exception = new RuntimeException('The cache lock flush failed.');
        $store = m::mock(Store::class, CanFlushLocks::class);
        $store->expects('supportsFlushingLocks')->andReturnTrue();
        $store->expects('flushLocks')->andThrow($exception);
        $events = [];
        $repository = new Repository($store, ['store' => 'array']);
        $repository->setEventDispatcher($this->getCapturingDispatcher($events));

        try {
            $repository->flushLocks();
            $this->fail('Expected the cache lock flush exception to be rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame(
            [CacheLocksFlushing::class, CacheLocksFlushFailed::class],
            array_map(get_class(...), $events),
        );
        $this->assertSame($exception, $events[1]->exception);
    }

    public function testFlushFailureDoesDispatchEvent(): void
    {
        $dispatcher = $this->getDispatcher();

        // Create a store that fails to flush
        $failingStore = m::mock(Store::class);
        $failingStore->expects('flush')->andReturn(false);

        $repository = new Repository($failingStore, ['store' => 'array']);
        $repository->setEventDispatcher($dispatcher);

        $this->assertFalse($repository->clear());
        $this->assertEventDispatched(CacheFlushing::class, ['storeName' => 'array']);
        $this->assertEventDispatched(CacheFlushFailed::class, ['storeName' => 'array', 'exception' => null]);

        $this->assertSame([], $this->dispatchedEvents);
    }

    public function testFlushLocksFailureDoesDispatchEvent(): void
    {
        $dispatcher = $this->getDispatcher();

        // Create a store that fails to flush locks
        $failingStore = m::mock(ArrayStore::class);
        $failingStore->shouldReceive('supportsFlushingLocks')->andReturn(true);
        $failingStore->expects('flushLocks')->andReturn(false);

        $repository = new Repository($failingStore, ['store' => 'array']);
        $repository->setEventDispatcher($dispatcher);

        $this->assertFalse($repository->flushLocks());
        $this->assertEventDispatched(CacheLocksFlushing::class, ['storeName' => 'array']);
        $this->assertEventDispatched(CacheLocksFlushFailed::class, ['storeName' => 'array', 'exception' => null]);

        $this->assertSame([], $this->dispatchedEvents);
    }

    protected array $dispatchedEvents = [];

    /**
     * Assert that the next recorded event has the given class and property values.
     */
    protected function assertEventDispatched(string $eventClass, array $properties = []): void
    {
        $event = array_shift($this->dispatchedEvents);

        $this->assertInstanceOf($eventClass, $event);

        foreach ($properties as $name => $value) {
            $this->assertSame($value, $event->{$name}, "Event property [{$name}] does not match.");
        }
    }

    /**
     * Create a dispatcher that records cache events in dispatchedEvents.
     */
    protected function getDispatcher(): Dispatcher
    {
        $this->dispatchedEvents = [];

        return $this->getCapturingDispatcher($this->dispatchedEvents);
    }

    /**
     * Create a dispatcher that records each emitted cache event.
     *
     * The listener targets the cache event namespace because Hypervel treats a
     * bare '*' listener as a passive observer, which hasListeners() guards ignore.
     */
    protected function getCapturingDispatcher(array &$events): Dispatcher
    {
        $dispatcher = new Dispatcher;
        $dispatcher->listen('Hypervel\Cache\Events\*', function (string $event, array $payload) use (&$events): void {
            $events[] = $payload[0];
        });

        return $dispatcher;
    }

    /**
     * Create a repository with ordinary and tagged cache entries.
     */
    protected function getRepository(Dispatcher $dispatcher): Repository
    {
        $repository = new Repository(new ArrayStore, ['store' => 'array']);
        $repository->put('baz', 'qux', 99);
        $repository->tags('taylor')->put('baz', 'qux', 99);
        $repository->setEventDispatcher($dispatcher);

        return $repository;
    }
}
