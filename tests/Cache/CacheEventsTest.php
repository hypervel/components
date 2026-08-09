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
use Hypervel\Cache\Repository;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

class CacheEventsTest extends TestCase
{
    public function testHasTriggersEvents()
    {
        $dispatcher = $this->getDispatcher();
        $repository = $this->getRepository($dispatcher);

        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(RetrievingKey::class, ['key' => 'foo']));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(CacheMissed::class, ['key' => 'foo']));
        $this->assertFalse($repository->has('foo'));

        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(RetrievingKey::class, ['key' => 'baz']));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(CacheHit::class, ['key' => 'baz', 'value' => 'qux']));
        $this->assertTrue($repository->has('baz'));

        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(RetrievingKey::class, ['key' => 'foo', 'tags' => ['taylor']]));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(CacheMissed::class, ['key' => 'foo', 'tags' => ['taylor']]));
        $this->assertFalse($repository->tags('taylor')->has('foo'));

        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(RetrievingKey::class, ['key' => 'baz', 'tags' => ['taylor']]));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(CacheHit::class, ['key' => 'baz', 'value' => 'qux', 'tags' => ['taylor']]));
        $this->assertTrue($repository->tags('taylor')->has('baz'));
    }

    public function testGetTriggersEvents()
    {
        $dispatcher = $this->getDispatcher();
        $repository = $this->getRepository($dispatcher);

        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(RetrievingKey::class, ['key' => 'foo']));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(CacheMissed::class, ['key' => 'foo']));
        $this->assertNull($repository->get('foo'));

        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(RetrievingKey::class, ['key' => 'baz']));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(CacheHit::class, ['key' => 'baz', 'value' => 'qux']));
        $this->assertSame('qux', $repository->get('baz'));

        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(RetrievingKey::class, ['key' => 'foo', 'tags' => ['taylor']]));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(CacheMissed::class, ['key' => 'foo', 'tags' => ['taylor']]));
        $this->assertNull($repository->tags('taylor')->get('foo'));

        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(RetrievingKey::class, ['key' => 'baz', 'tags' => ['taylor']]));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(CacheHit::class, ['key' => 'baz', 'value' => 'qux', 'tags' => ['taylor']]));
        $this->assertSame('qux', $repository->tags('taylor')->get('baz'));
    }

    public function testTaggedManyTriggersManyEvents(): void
    {
        $dispatcher = $this->getDispatcher();
        $repository = $this->getRepository($dispatcher);

        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(RetrievingManyKeys::class, [
            'keys' => ['baz', 'foo'],
            'tags' => ['taylor'],
        ]));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(CacheHit::class, [
            'key' => 'baz',
            'value' => 'qux',
            'tags' => ['taylor'],
        ]));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(CacheMissed::class, [
            'key' => 'foo',
            'tags' => ['taylor'],
        ]));

        $this->assertSame([
            'baz' => 'qux',
            'foo' => null,
        ], $repository->tags('taylor')->many(['baz', 'foo']));
    }

    public function testGetDispatchesFailureEventWhenTheStoreThrows(): void
    {
        $exception = new RuntimeException('The cache read failed.');
        $store = m::mock(Store::class);
        $store->shouldReceive('get')->once()->with('foo')->andThrow($exception);
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
        $store->shouldReceive('many')->once()->with(['foo', 'bar'])->andThrow($exception);
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
        $store->shouldReceive('put')->once()->with('foo', 'bar', 60)->andThrow($exception);
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
    }

    public function testPutManyDispatchesFailureEventsWhenTheStoreThrows(): void
    {
        $exception = new RuntimeException('The cache batch write failed.');
        $values = ['foo' => 'bar', 'baz' => 'qux'];
        $store = m::mock(Store::class);
        $store->shouldReceive('putMany')->once()->with($values, 60)->andThrow($exception);
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
    }

    public function testForeverDispatchesFailureEventWhenTheStoreThrows(): void
    {
        $exception = new RuntimeException('The cache forever write failed.');
        $store = m::mock(Store::class);
        $store->shouldReceive('forever')->once()->with('foo', 'bar')->andThrow($exception);
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
    }

    public function testForgetDispatchesFailureEventWhenTheStoreThrows(): void
    {
        $exception = new RuntimeException('The cache forget failed.');
        $store = m::mock(Store::class);
        $store->shouldReceive('forget')->once()->with('foo')->andThrow($exception);
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
    }

    public function testPullTriggersEvents()
    {
        $dispatcher = $this->getDispatcher();
        $repository = $this->getRepository($dispatcher);

        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(RetrievingKey::class, ['key' => 'baz']));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(CacheHit::class, ['key' => 'baz', 'value' => 'qux']));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(ForgettingKey::class, ['key' => 'baz']));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(KeyForgotten::class, ['key' => 'baz']));
        $this->assertSame('qux', $repository->pull('baz'));
    }

    public function testPullTriggersEventsUsingTags()
    {
        $dispatcher = $this->getDispatcher();
        $repository = $this->getRepository($dispatcher);

        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(RetrievingKey::class, ['key' => 'baz', 'tags' => ['taylor']]));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(CacheHit::class, ['key' => 'baz', 'value' => 'qux', 'tags' => ['taylor']]));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(ForgettingKey::class, ['key' => 'baz', 'tags' => ['taylor']]));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(KeyForgotten::class, ['key' => 'baz', 'tags' => ['taylor']]));
        $this->assertSame('qux', $repository->tags('taylor')->pull('baz'));
    }

    public function testPutTriggersEvents()
    {
        $dispatcher = $this->getDispatcher();
        $repository = $this->getRepository($dispatcher);

        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(WritingKey::class, ['key' => 'foo', 'value' => 'bar', 'seconds' => 99]));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(KeyWritten::class, ['key' => 'foo', 'value' => 'bar', 'seconds' => 99]));
        $repository->put('foo', 'bar', 99);

        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(WritingKey::class, ['key' => 'foo', 'value' => 'bar', 'seconds' => 99, 'tags' => ['taylor']]));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(KeyWritten::class, ['key' => 'foo', 'value' => 'bar', 'seconds' => 99, 'tags' => ['taylor']]));
        $repository->tags('taylor')->put('foo', 'bar', 99);

        $this->assertTrue(true);
    }

    public function testAddTriggersEvents()
    {
        $dispatcher = $this->getDispatcher();
        $repository = $this->getRepository($dispatcher);

        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(RetrievingKey::class, ['key' => 'foo']));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(CacheMissed::class, ['key' => 'foo']));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(WritingKey::class, ['key' => 'foo', 'value' => 'bar', 'seconds' => 99]));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(KeyWritten::class, ['key' => 'foo', 'value' => 'bar', 'seconds' => 99]));
        $this->assertTrue($repository->add('foo', 'bar', 99));

        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(RetrievingKey::class, ['key' => 'foo', 'tags' => ['taylor']]));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(CacheMissed::class, ['key' => 'foo', 'tags' => ['taylor']]));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(WritingKey::class, ['key' => 'foo', 'value' => 'bar', 'seconds' => 99, 'tags' => ['taylor']]));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(KeyWritten::class, ['key' => 'foo', 'value' => 'bar', 'seconds' => 99, 'tags' => ['taylor']]));
        $this->assertTrue($repository->tags('taylor')->add('foo', 'bar', 99));
    }

    public function testForeverTriggersEvents()
    {
        $dispatcher = $this->getDispatcher();
        $repository = $this->getRepository($dispatcher);

        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(WritingKey::class, ['key' => 'foo', 'value' => 'bar', 'seconds' => null]));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(KeyWritten::class, ['key' => 'foo', 'value' => 'bar', 'seconds' => null]));
        $repository->forever('foo', 'bar');

        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(WritingKey::class, ['key' => 'foo', 'value' => 'bar', 'seconds' => null, 'tags' => ['taylor']]));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(KeyWritten::class, ['key' => 'foo', 'value' => 'bar', 'seconds' => null, 'tags' => ['taylor']]));
        $repository->tags('taylor')->forever('foo', 'bar');

        $this->assertTrue(true);
    }

    public function testRememberTriggersEvents()
    {
        $dispatcher = $this->getDispatcher();
        $repository = $this->getRepository($dispatcher);

        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(RetrievingKey::class, ['key' => 'foo']));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(CacheMissed::class, ['key' => 'foo']));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(WritingKey::class, ['key' => 'foo', 'value' => 'bar', 'seconds' => 99]));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(KeyWritten::class, ['key' => 'foo', 'value' => 'bar', 'seconds' => 99]));
        $this->assertSame('bar', $repository->remember('foo', 99, function () {
            return 'bar';
        }));

        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(RetrievingKey::class, ['key' => 'foo', 'tags' => ['taylor']]));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(CacheMissed::class, ['key' => 'foo', 'tags' => ['taylor']]));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(WritingKey::class, ['key' => 'foo', 'value' => 'bar', 'seconds' => 99, 'tags' => ['taylor']]));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(KeyWritten::class, ['key' => 'foo', 'value' => 'bar', 'seconds' => 99, 'tags' => ['taylor']]));
        $this->assertSame('bar', $repository->tags('taylor')->remember('foo', 99, function () {
            return 'bar';
        }));
    }

    public function testRememberForeverTriggersEvents()
    {
        $dispatcher = $this->getDispatcher();
        $repository = $this->getRepository($dispatcher);

        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(RetrievingKey::class, ['key' => 'foo']));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(CacheMissed::class, ['key' => 'foo']));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(WritingKey::class, ['key' => 'foo', 'value' => 'bar', 'seconds' => null]));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(KeyWritten::class, ['key' => 'foo', 'value' => 'bar', 'seconds' => null]));
        $this->assertSame('bar', $repository->rememberForever('foo', function () {
            return 'bar';
        }));

        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(RetrievingKey::class, ['key' => 'foo', 'tags' => ['taylor']]));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(CacheMissed::class, ['key' => 'foo', 'tags' => ['taylor']]));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(WritingKey::class, ['key' => 'foo', 'value' => 'bar', 'seconds' => null, 'tags' => ['taylor']]));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(KeyWritten::class, ['key' => 'foo', 'value' => 'bar', 'seconds' => null, 'tags' => ['taylor']]));
        $this->assertSame('bar', $repository->tags('taylor')->rememberForever('foo', function () {
            return 'bar';
        }));
    }

    public function testForgetTriggersEvents()
    {
        $dispatcher = $this->getDispatcher();
        $repository = $this->getRepository($dispatcher);

        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(ForgettingKey::class, ['key' => 'baz']));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(KeyForgotten::class, ['key' => 'baz']));
        $this->assertTrue($repository->forget('baz'));

        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(ForgettingKey::class, ['key' => 'baz', 'tags' => ['taylor']]));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(KeyForgotten::class, ['key' => 'baz', 'tags' => ['taylor']]));
        $this->assertTrue($repository->tags('taylor')->forget('baz'));
    }

    public function testForgetDoesTriggerFailedEventOnFailure()
    {
        $dispatcher = $this->getDispatcher();
        $store = m::mock(Store::class);
        $store->shouldReceive('forget')->andReturn(false);
        $repository = new Repository($store);
        $repository->setEventDispatcher($dispatcher);

        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(ForgettingKey::class, ['key' => 'baz']));
        $dispatcher->shouldReceive('dispatch')->once()->with($this->assertEventMatches(KeyForgetFailed::class, ['key' => 'baz']));
        $dispatcher->shouldReceive('dispatch')->never()->with($this->assertEventMatches(KeyForgotten::class, ['key' => 'baz']));
        $this->assertFalse($repository->forget('baz'));
    }

    public function testFlushTriggersEvents()
    {
        $dispatcher = $this->getDispatcher();
        $repository = $this->getRepository($dispatcher);

        $dispatcher->shouldReceive('dispatch')->once()->with(
            $this->assertEventMatches(CacheFlushing::class, [
                'storeName' => 'array',
            ])
        );

        $dispatcher->shouldReceive('dispatch')->once()->with(
            $this->assertEventMatches(CacheFlushed::class, [
                'storeName' => 'array',
            ])
        );
        $this->assertTrue($repository->clear());
    }

    public function testFlushLocksTriggersEvents()
    {
        $dispatcher = $this->getDispatcher();
        $repository = $this->getRepository($dispatcher);

        $dispatcher->shouldReceive('dispatch')->once()->with(
            $this->assertEventMatches(CacheLocksFlushing::class, [
                'storeName' => 'array',
            ])
        );

        $dispatcher->shouldReceive('dispatch')->once()->with(
            $this->assertEventMatches(CacheLocksFlushed::class, [
                'storeName' => 'array',
            ])
        );
        $this->assertTrue($repository->flushLocks());
    }

    public function testFlushFailureDoesDispatchEvent()
    {
        $dispatcher = $this->getDispatcher();

        // Create a store that fails to flush
        $failingStore = m::mock(Store::class);
        $failingStore->shouldReceive('flush')->andReturn(false);

        $repository = new Repository($failingStore, ['store' => 'array']);
        $repository->setEventDispatcher($dispatcher);

        $dispatcher->shouldReceive('dispatch')->once()->with(
            $this->assertEventMatches(CacheFlushing::class, [
                'storeName' => 'array',
            ])
        );

        $dispatcher->shouldReceive('dispatch')->once()->with(
            $this->assertEventMatches(CacheFlushFailed::class, [
                'storeName' => 'array',
            ])
        );
        $this->assertFalse($repository->clear());
    }

    public function testFlushLocksFailureDoesDispatchEvent()
    {
        $dispatcher = $this->getDispatcher();

        // Create a store that fails to flush locks
        $failingStore = m::mock(ArrayStore::class);
        $failingStore->shouldReceive('supportsFlushingLocks')->andReturn(true);
        $failingStore->shouldReceive('flushLocks')->andReturn(false);

        $repository = new Repository($failingStore, ['store' => 'array']);
        $repository->setEventDispatcher($dispatcher);

        $dispatcher->shouldReceive('dispatch')->once()->with(
            $this->assertEventMatches(CacheLocksFlushing::class, [
                'storeName' => 'array',
            ])
        );

        $dispatcher->shouldReceive('dispatch')->once()->with(
            $this->assertEventMatches(CacheLocksFlushFailed::class, [
                'storeName' => 'array',
            ])
        );
        $this->assertFalse($repository->flushLocks());
    }

    protected function assertEventMatches($eventClass, $properties = [])
    {
        return m::on(function ($event) use ($eventClass, $properties) {
            if (! $event instanceof $eventClass) {
                return false;
            }

            foreach ($properties as $name => $value) {
                if ($value != $event->{$name}) {
                    return false;
                }
            }

            return true;
        });
    }

    protected function getDispatcher()
    {
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('hasListeners')->withAnyArgs()->andReturn(true);

        return $dispatcher;
    }

    protected function getCapturingDispatcher(array &$events): Dispatcher
    {
        $dispatcher = $this->getDispatcher();
        $dispatcher->shouldReceive('dispatch')->andReturnUsing(function (object $event) use (&$events): void {
            $events[] = $event;
        });

        return $dispatcher;
    }

    protected function getRepository($dispatcher)
    {
        $repository = new Repository(new ArrayStore, ['store' => 'array']);
        $repository->put('baz', 'qux', 99);
        $repository->tags('taylor')->put('baz', 'qux', 99);
        $repository->setEventDispatcher($dispatcher);

        return $repository;
    }
}
