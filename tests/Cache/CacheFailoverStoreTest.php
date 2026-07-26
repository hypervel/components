<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use BadMethodCallException;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\FailoverStore;
use Hypervel\Cache\Repository;
use Hypervel\Contracts\Cache\CanFlushLocks;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use stdClass;

class CacheFailoverStoreTest extends TestCase
{
    public function testIncompleteClassHandlerRunsOnceAcrossFailoverRepositories(): void
    {
        $backingStore = new ArrayStore;
        $backingStore->forever(
            'key',
            unserialize(serialize(new stdClass), ['allowed_classes' => false])
        );

        $handled = [];

        Repository::handleUnserializableClassUsing(function (string $key, ?string $class) use (&$handled): void {
            $handled[] = [$key, $class];
        });

        (new Repository($this->makeFailoverStore(['cache' => $backingStore])))->get('key');

        $this->assertSame([['key', 'stdClass']], $handled);
    }

    public function testLockFlushCapabilityRequiresAtLeastOneLockProvider(): void
    {
        $store = $this->makeFailoverStore([
            'plain' => m::mock(Store::class),
        ]);

        $this->assertFalse($store->supportsFlushingLocks());
        $this->assertFalse($store->hasSeparateLockStore());

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('This failover cache store has no lock-providing stores to flush.');

        $store->flushLocks();
    }

    public function testLockFlushCapabilityRequiresEveryLockProviderToSupportFlushing(): void
    {
        $flushable = $this->lockStore();
        $flushable->shouldReceive('supportsFlushingLocks')->once()->andReturnTrue();

        $unsupported = m::mock(implode(',', [Store::class, LockProvider::class]));

        $store = $this->makeFailoverStore([
            'plain' => m::mock(Store::class),
            'flushable' => $flushable,
            'unsupported' => $unsupported,
        ]);

        $this->assertFalse($store->supportsFlushingLocks());
    }

    public function testLockFlushCapabilityRequiresEveryFlushableStoreToBeCurrentlySupported(): void
    {
        $supported = $this->lockStore();
        $supported->shouldReceive('supportsFlushingLocks')->once()->andReturnTrue();

        $unsupported = $this->lockStore();
        $unsupported->shouldReceive('supportsFlushingLocks')->once()->andReturnFalse();

        $store = $this->makeFailoverStore([
            'supported' => $supported,
            'unsupported' => $unsupported,
        ]);

        $this->assertFalse($store->supportsFlushingLocks());
    }

    public function testLockFlushCapabilityIsTrueWhenEveryLockProviderSupportsIt(): void
    {
        $first = $this->lockStore();
        $first->shouldReceive('supportsFlushingLocks')->once()->andReturnTrue();

        $second = $this->lockStore();
        $second->shouldReceive('supportsFlushingLocks')->once()->andReturnTrue();

        $store = $this->makeFailoverStore([
            'plain' => m::mock(Store::class),
            'first' => $first,
            'second' => $second,
        ]);

        $this->assertTrue($store->supportsFlushingLocks());
    }

    public function testFlushLocksPreflightsEveryLockProviderBeforeChangingState(): void
    {
        $first = $this->lockStore();
        $first->shouldReceive('supportsFlushingLocks')->once()->andReturnTrue();
        $first->shouldNotReceive('flushLocks');

        $second = $this->lockStore();
        $second->shouldReceive('supportsFlushingLocks')->once()->andReturnFalse();
        $second->shouldNotReceive('flushLocks');

        $store = $this->makeFailoverStore([
            'first' => $first,
            'second' => $second,
        ]);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage(sprintf(
            'The failover cache store [%s] does not support flushing locks.',
            $second::class
        ));

        $store->flushLocks();
    }

    public function testFlushLocksAttemptsEveryStoreAndAggregatesFalseResults(): void
    {
        $first = $this->lockStore();
        $first->shouldReceive('supportsFlushingLocks')->once()->andReturnTrue();
        $first->shouldReceive('flushLocks')->once()->andReturnFalse();

        $second = $this->lockStore();
        $second->shouldReceive('supportsFlushingLocks')->once()->andReturnTrue();
        $second->shouldReceive('flushLocks')->once()->andReturnTrue();

        $store = $this->makeFailoverStore([
            'first' => $first,
            'second' => $second,
        ]);

        $this->assertFalse($store->flushLocks());
    }

    public function testFlushLocksAttemptsEveryStoreAndPreservesTheEarliestFailure(): void
    {
        $first = $this->lockStore();
        $first->shouldReceive('supportsFlushingLocks')->once()->andReturnTrue();
        $first->shouldReceive('flushLocks')->once()->andThrow(new RuntimeException('first failure'));

        $second = $this->lockStore();
        $second->shouldReceive('supportsFlushingLocks')->once()->andReturnTrue();
        $second->shouldReceive('flushLocks')->once()->andThrow(new RuntimeException('second failure'));

        $store = $this->makeFailoverStore([
            'first' => $first,
            'second' => $second,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('first failure');

        $store->flushLocks();
    }

    public function testSeparateLockStoreReportingRequiresEveryLockProviderToBeSeparate(): void
    {
        $separate = $this->lockStore();
        $separate->shouldReceive('hasSeparateLockStore')->once()->andReturnTrue();

        $shared = $this->lockStore();
        $shared->shouldReceive('hasSeparateLockStore')->once()->andReturnFalse();

        $store = $this->makeFailoverStore([
            'plain' => m::mock(Store::class),
            'separate' => $separate,
            'shared' => $shared,
        ]);

        $this->assertFalse($store->hasSeparateLockStore());
    }

    public function testSeparateLockStoreReportingIsTrueWhenEveryLockProviderIsSeparate(): void
    {
        $first = $this->lockStore();
        $first->shouldReceive('hasSeparateLockStore')->once()->andReturnTrue();

        $second = $this->lockStore();
        $second->shouldReceive('hasSeparateLockStore')->once()->andReturnTrue();

        $store = $this->makeFailoverStore([
            'plain' => m::mock(Store::class),
            'first' => $first,
            'second' => $second,
        ]);

        $this->assertTrue($store->hasSeparateLockStore());
    }

    /**
     * Create a failover store with the given named backing stores.
     *
     * @param array<string, Store> $stores
     */
    protected function makeFailoverStore(array $stores): FailoverStore
    {
        $cache = m::mock(CacheManager::class);

        foreach ($stores as $name => $store) {
            $cache->shouldReceive('store')
                ->with($name)
                ->andReturn(new Repository($store));
        }

        return new FailoverStore($cache, m::mock(Dispatcher::class), array_keys($stores));
    }

    /**
     * Create a lock-capable store with flush support.
     */
    protected function lockStore(): Store&LockProvider&CanFlushLocks
    {
        return m::mock(implode(',', [Store::class, LockProvider::class, CanFlushLocks::class]));
    }
}
