<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use BadMethodCallException;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\Events\CacheFailedOver;
use Hypervel\Cache\FailoverStore;
use Hypervel\Cache\MemoizedStore;
use Hypervel\Cache\NullSentinel;
use Hypervel\Cache\Repository;
use Hypervel\Cache\StackStore;
use Hypervel\Contracts\Cache\AuthoritativeRawReadable;
use Hypervel\Contracts\Cache\CanFlushLocks;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\RawReadable;
use Hypervel\Contracts\Cache\Repository as RepositoryContract;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use stdClass;
use Swoole\Coroutine\CanceledException;

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

    public function testAuthoritativeReadDelegatesToCapableRepository(): void
    {
        $backingStore = m::mock(Store::class, AuthoritativeRawReadable::class);
        $backingStore->shouldReceive('getAuthoritativeRaw')->once()->with('key')->andReturn(NullSentinel::VALUE);

        $store = $this->makeFailoverStore(['cache' => $backingStore]);

        $this->assertSame(NullSentinel::VALUE, $store->getAuthoritativeRaw('key'));
    }

    public function testAuthoritativeReadRecursesThroughStackBottom(): void
    {
        $top = new ArrayStore;
        $bottom = new ArrayStore;
        $stack = new StackStore([$top, $bottom]);

        $stack->put('key', 'fresh', 60);
        $top->put('key', ['value' => 'stale'], 60);

        $store = $this->makeFailoverStore(['stack' => $stack]);

        $this->assertSame('fresh', $store->getAuthoritativeRaw('key'));
        $this->assertSame(['value' => 'stale'], $top->get('key'));
    }

    public function testAuthoritativeReadRecursesThroughMemoizedFailoverAndStack(): void
    {
        $top = new ArrayStore;
        $bottom = new ArrayStore;
        $stack = new StackStore([$top, $bottom]);
        $stack->put('key', 'fresh', 60);
        $top->put('key', ['value' => 'stale'], 60);

        $failover = $this->makeFailoverStore(['stack' => $stack]);
        $memoized = new MemoizedStore('memoized', new Repository($failover));

        $this->assertSame('stale', $memoized->getRaw('key'));
        $this->assertSame('fresh', $memoized->getAuthoritativeRaw('key'));
        $this->assertSame('stale', $memoized->getRaw('key'));
    }

    public function testAuthoritativeReadFailsOverAfterStoreFailure(): void
    {
        $failure = new RuntimeException('primary unavailable');
        $primary = m::mock(Store::class, AuthoritativeRawReadable::class);
        $primary->shouldReceive('getAuthoritativeRaw')->once()->with('key')->andThrow($failure);
        $secondary = m::mock(Store::class, AuthoritativeRawReadable::class);
        $secondary->shouldReceive('getAuthoritativeRaw')->once()->with('key')->andReturn('value');
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->once()->with(CacheFailedOver::class)->andReturnFalse();

        $store = $this->makeFailoverStore(
            ['primary' => $primary, 'secondary' => $secondary],
            $events,
        );

        $this->assertSame('value', $store->getAuthoritativeRaw('key'));
    }

    public function testRawReadsFallBackToContractRepositoryWithLossyNullSemantics(): void
    {
        $repository = m::mock(RepositoryContract::class);
        $repository->shouldReceive('get')->once()->with('key')->andReturn('value');
        $repository->shouldReceive('get')->once()->with(['cached-null', 'miss'])->andReturn([
            'cached-null' => null,
            'miss' => null,
        ]);
        $repository->shouldReceive('get')->once()->with('null')->andReturnNull();

        $store = $this->makeFailoverStore(['custom' => $repository]);

        $this->assertSame('value', $store->getRaw('key'));
        $this->assertSame([
            'cached-null' => null,
            'miss' => null,
        ], $store->manyRaw(['cached-null', 'miss']));
        $this->assertNull($store->getAuthoritativeRaw('null'));
        $this->assertNotInstanceOf(RawReadable::class, $repository);
        $this->assertNotInstanceOf(AuthoritativeRawReadable::class, $repository);
    }

    public function testCountersPreserveEveryContractValidResult(): void
    {
        $backingStore = m::mock(Store::class);
        $backingStore->expects('increment')->with('increment', 2)->andReturnTrue();
        $backingStore->expects('decrement')->with('decrement', 3)->andReturnFalse();
        $store = $this->makeFailoverStore(['cache' => $backingStore]);

        $this->assertTrue($store->increment('increment', 2));
        $this->assertFalse($store->decrement('decrement', 3));
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

    public function testFlushLocksStopsAtCancellation(): void
    {
        $cancellation = new CanceledException('flush canceled');
        $first = $this->lockStore();
        $first->shouldReceive('supportsFlushingLocks')->once()->andReturnTrue();
        $first->shouldReceive('flushLocks')->once()->andThrow($cancellation);
        $second = $this->lockStore();
        $second->shouldReceive('supportsFlushingLocks')->once()->andReturnTrue();
        $second->shouldNotReceive('flushLocks');
        $store = $this->makeFailoverStore([
            'first' => $first,
            'second' => $second,
        ]);

        try {
            $store->flushLocks();
            $this->fail('Flushing the lock stores was expected to be canceled.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testFirstSuccessOperationDoesNotFailOverAfterCancellation(): void
    {
        $cancellation = new CanceledException('read canceled');
        $first = m::mock(Store::class);
        $first->shouldReceive('get')->once()->with('key')->andThrow(new RuntimeException('first failure'));
        $second = m::mock(Store::class);
        $second->shouldReceive('get')->once()->with('key')->andThrow($cancellation);
        $third = m::mock(Store::class);
        $third->shouldNotReceive('get');
        $failedStores = [];
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->once()->with(CacheFailedOver::class)->andReturnTrue();
        $events->shouldReceive('dispatch')->once()->andReturnUsing(
            function (CacheFailedOver $event) use (&$failedStores): void {
                $failedStores[] = $event->storeName;
            }
        );
        $store = $this->makeFailoverStore(
            ['first' => $first, 'second' => $second, 'third' => $third],
            $events,
        );

        try {
            $store->get('key');
            $this->fail('Reading from the failover store was expected to be canceled.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame(['first'], $failedStores);
    }

    public function testEveryStoreOperationDoesNotContinueAfterCancellation(): void
    {
        $cancellation = new CanceledException('forget canceled');
        $first = m::mock(Store::class);
        $first->shouldReceive('forget')->once()->with('key')->andReturnTrue();
        $second = m::mock(Store::class);
        $second->shouldReceive('forget')->once()->with('key')->andThrow($cancellation);
        $third = m::mock(Store::class);
        $third->shouldNotReceive('forget');
        $store = $this->makeFailoverStore([
            'first' => $first,
            'second' => $second,
            'third' => $third,
        ]);

        try {
            $store->forget('key');
            $this->fail('Forgetting from every failover store was expected to be canceled.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testFirstSuccessListenerFailurePreservesObservedAndUnattemptedFailures(): void
    {
        $listenerFailure = new RuntimeException('listener failure');
        $first = m::mock(Store::class);
        $first->shouldReceive('forget')->once()->with('key')->andReturnTrue();
        $first->shouldReceive('get')->twice()->with('key')->andThrow(new RuntimeException('first failure'));
        $second = m::mock(Store::class);
        $second->shouldReceive('forget')->once()->with('key')->andThrow(new RuntimeException('second failure'));
        $second->shouldReceive('get')->once()->with('key')->andThrow(new RuntimeException('second failure'));
        $third = m::mock(Store::class);
        $third->shouldReceive('forget')->once()->with('key')->andReturnTrue();
        $third->shouldReceive('get')->once()->with('key')->andReturn('value');
        $failedStores = [];
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->with(CacheFailedOver::class)->andReturnTrue();
        $events->shouldReceive('dispatch')->andReturnUsing(
            function (CacheFailedOver $event) use (&$failedStores, $listenerFailure): void {
                $failedStores[] = $event->storeName;

                if ($event->storeName === 'first') {
                    throw $listenerFailure;
                }
            }
        );
        $store = $this->makeFailoverStore(
            ['first' => $first, 'second' => $second, 'third' => $third],
            $events,
        );

        $this->assertFalse($store->forget('key'));

        try {
            $store->get('key');
            $this->fail('Expected the failover listener failure to be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($listenerFailure, $exception);
        }

        $this->assertSame('value', $store->get('key'));
        $this->assertSame(['second', 'first'], $failedStores);
    }

    public function testEveryStoreListenerFailurePreservesObservedAndUnattemptedFailures(): void
    {
        $listenerFailure = new RuntimeException('listener failure');
        $firstCall = 0;
        $first = m::mock(Store::class);
        $first->shouldReceive('forget')->times(3)->with('key')->andReturnUsing(
            function () use (&$firstCall): bool {
                ++$firstCall;

                return $firstCall === 1
                    ? true
                    : throw new RuntimeException('first failure');
            }
        );
        $second = m::mock(Store::class);
        $second->shouldReceive('forget')->twice()->with('key')->andThrow(new RuntimeException('second failure'));
        $third = m::mock(Store::class);
        $third->shouldReceive('forget')->twice()->with('key')->andReturnTrue();
        $failedStores = [];
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->with(CacheFailedOver::class)->andReturnTrue();
        $events->shouldReceive('dispatch')->andReturnUsing(
            function (CacheFailedOver $event) use (&$failedStores, $listenerFailure): void {
                $failedStores[] = $event->storeName;

                if ($event->storeName === 'first') {
                    throw $listenerFailure;
                }
            }
        );
        $store = $this->makeFailoverStore(
            ['first' => $first, 'second' => $second, 'third' => $third],
            $events,
        );

        $this->assertFalse($store->forget('key'));

        try {
            $store->forget('key');
            $this->fail('Expected the failover listener failure to be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($listenerFailure, $exception);
        }

        $this->assertFalse($store->forget('key'));
        $this->assertSame(['second', 'first'], $failedStores);
    }

    public function testListenerInterruptionPreservesUnattemptedFailureHistoryWithSparseStoreKeys(): void
    {
        $listenerFailure = new RuntimeException('listener failure');
        $firstFailure = new RuntimeException('first failure');
        $secondFailure = new RuntimeException('second failure');
        $thirdFailure = new RuntimeException('third failure');
        $firstCall = 0;
        $secondCall = 0;
        $first = m::mock(Store::class);
        $first->shouldReceive('forget')->times(3)->with('key')->andReturnUsing(
            function () use (&$firstCall, $firstFailure): bool {
                ++$firstCall;

                return $firstCall === 1 ? true : throw $firstFailure;
            }
        );
        $second = m::mock(Store::class);
        $second->shouldReceive('forget')->times(3)->with('key')->andReturnUsing(
            function () use (&$secondCall, $secondFailure): bool {
                ++$secondCall;

                return $secondCall === 1 ? true : throw $secondFailure;
            }
        );
        $third = m::mock(Store::class);
        $third->shouldReceive('forget')->twice()->with('key')->andThrow($thirdFailure);
        $failedStores = [];
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->with(CacheFailedOver::class)->andReturnTrue();
        $events->shouldReceive('dispatch')->andReturnUsing(
            function (CacheFailedOver $event) use (&$failedStores, $listenerFailure): void {
                $failedStores[] = $event->storeName;

                if ($event->storeName === 'second') {
                    throw $listenerFailure;
                }
            }
        );
        $store = $this->makeFailoverStore(
            ['first' => $first, 'second' => $second, 'third' => $third],
            $events,
            [0 => 'first', 2 => 'second', 5 => 'third'],
        );

        $this->assertFalse($store->forget('key'));

        try {
            $store->forget('key');
            $this->fail('Expected the failover listener failure to be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($listenerFailure, $exception);
        }

        try {
            $store->forget('key');
            $this->fail('Expected every backing store to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($thirdFailure, $exception);
        }

        $this->assertSame(['third', 'first', 'second'], $failedStores);
    }

    public function testFailoverEventCarriesLogicalAndFailedBackingStoreNames(): void
    {
        $exception = new RuntimeException('primary unavailable');
        $first = m::mock(Store::class);
        $first->shouldReceive('get')->once()->with('key')->andThrow($exception);
        $second = m::mock(Store::class);
        $second->shouldReceive('get')->once()->with('key')->andReturn('value');
        $captured = null;
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->once()->with(CacheFailedOver::class)->andReturnTrue();
        $events->shouldReceive('dispatch')->once()->andReturnUsing(function (CacheFailedOver $event) use (&$captured): void {
            $captured = $event;
        });

        $result = $this->makeFailoverStore(
            ['primary' => $first, 'secondary' => $second],
            $events,
            failoverStoreName: 'resilient',
        )->get('key');

        $this->assertSame('value', $result);
        $this->assertInstanceOf(CacheFailedOver::class, $captured);
        $this->assertSame('primary', $captured->storeName);
        $this->assertSame('resilient', $captured->failoverStoreName);
        $this->assertSame($exception, $captured->exception);
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
     * @param array<string, RepositoryContract|Store> $stores
     * @param null|array<int, string> $storeNames
     */
    protected function makeFailoverStore(
        array $stores,
        ?Dispatcher $events = null,
        ?array $storeNames = null,
        ?string $failoverStoreName = null,
    ): FailoverStore {
        $cache = m::mock(CacheManager::class);

        foreach ($stores as $name => $store) {
            $cache->shouldReceive('store')
                ->with($name)
                ->andReturn($store instanceof RepositoryContract ? $store : new Repository($store));
        }

        return new FailoverStore(
            $cache,
            $events ?? m::mock(Dispatcher::class),
            $storeNames ?? array_keys($stores),
            $failoverStoreName,
        );
    }

    /**
     * Create a lock-capable store with flush support.
     */
    protected function lockStore(): Store&LockProvider&CanFlushLocks
    {
        return m::mock(implode(',', [Store::class, LockProvider::class, CanFlushLocks::class]));
    }
}
