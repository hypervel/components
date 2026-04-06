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
use Hypervel\Cache\Events\KeyWritten;
use Hypervel\Cache\Events\RetrievingKey;
use Hypervel\Cache\Events\WritingKey;
use Hypervel\Cache\Repository;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
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

    protected function getRepository($dispatcher)
    {
        $repository = new Repository(new ArrayStore, ['store' => 'array']);
        $repository->put('baz', 'qux', 99);
        $repository->tags('taylor')->put('baz', 'qux', 99);
        $repository->setEventDispatcher($dispatcher);

        return $repository;
    }
}
