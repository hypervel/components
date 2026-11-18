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
use Hypervel\Events\Dispatcher as EventDispatcher;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use stdClass;
use Swoole\Coroutine\CanceledException;
use Throwable;

class CacheFailoverStoreTest extends TestCase
{
    use VerifiesDoubles;

    public function testImplementsCanFlushLocks(): void
    {
        $store = $this->makeFailoverStore([]);

        $this->assertInstanceOf(CanFlushLocks::class, $store);
    }

    public function testFlushLocksCallsFlushLocksOnAllBackingStores(): void
    {
        $storeA = new ArrayStore;
        $storeB = new ArrayStore;

        $this->assertTrue($storeA->lock('lock-a', 60)->get());
        $this->assertTrue($storeB->lock('lock-b', 60)->get());

        $cache = Double::for(CacheManager::class)->strict();
        $cache->expects('store')->with('store-a')->returns(new Repository($storeA));
        $cache->expects('store')->with('store-b')->returns(new Repository($storeB));

        $failover = new FailoverStore($cache, new EventDispatcher, ['store-a', 'store-b']);

        $result = $failover->flushLocks();

        $this->assertTrue($result);
        $this->assertNull($storeA->getLockRecord('lock-a'));
        $this->assertNull($storeB->getLockRecord('lock-b'));
    }

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
        $backingStore = Double::for(Store::class, AuthoritativeRawReadable::class)->strict();
        $backingStore->expects('getAuthoritativeRaw')->with('key')->returns(NullSentinel::VALUE);

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
        $primary = Double::for(Store::class, AuthoritativeRawReadable::class)->strict();
        $primary->expects('getAuthoritativeRaw')->with('key')->throws($failure);
        $secondary = Double::for(Store::class, AuthoritativeRawReadable::class)->strict();
        $secondary->expects('getAuthoritativeRaw')->with('key')->returns('value');
        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('hasListeners')->with(CacheFailedOver::class)->returns(false);
        $events->expects('dispatch')->never();

        $store = $this->makeFailoverStore(
            ['primary' => $primary, 'secondary' => $secondary],
            $events,
        );

        $this->assertSame('value', $store->getAuthoritativeRaw('key'));
    }

    public function testRawReadsFallBackToContractRepositoryWithLossyNullSemantics(): void
    {
        $repository = Double::for(RepositoryContract::class)->strict();
        $repository->expects('get')->with('key')->returns('value');
        $repository->expects('get')->with(['cached-null', 'miss'])->returns([
            'cached-null' => null,
            'miss' => null,
        ]);
        $repository->expects('get')->with('null')->returns(null);

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
        $backingStore = Double::for(Store::class)->strict();
        $backingStore->expects('increment')->with('increment', 2)->returns(true);
        $backingStore->expects('decrement')->with('decrement', 3)->returns(false);
        $store = $this->makeFailoverStore(['cache' => $backingStore]);

        $this->assertTrue($store->increment('increment', 2));
        $this->assertFalse($store->decrement('decrement', 3));
    }

    #[DataProvider('storesWithoutLockProviders')]
    public function testLockFlushCapabilityRequiresAtLeastOneLockProvider(bool $hasStore): void
    {
        $store = $this->makeFailoverStore($hasStore ? [
            'plain' => Double::for(Store::class)->strict(),
        ] : []);

        $this->assertFalse($store->supportsFlushingLocks());
        $this->assertFalse($store->hasSeparateLockStore());

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessageIs('This failover cache store has no lock-providing stores to flush.');

        $store->flushLocks();
    }

    /**
     * Provide configurations without a lock-capable backing store.
     */
    public static function storesWithoutLockProviders(): array
    {
        return [
            'no stores' => [false],
            'non-locking store' => [true],
        ];
    }

    public function testLockFlushCapabilityRequiresEveryLockProviderToSupportFlushing(): void
    {
        $flushable = $this->lockStore();
        $flushable->expects('supportsFlushingLocks')->returns(true);

        $unsupported = Double::for(Store::class, LockProvider::class)->strict();

        $store = $this->makeFailoverStore([
            'plain' => Double::for(Store::class)->strict(),
            'flushable' => $flushable,
            'unsupported' => $unsupported,
        ]);

        $this->assertFalse($store->supportsFlushingLocks());
    }

    public function testLockFlushCapabilityRequiresEveryFlushableStoreToBeCurrentlySupported(): void
    {
        $supported = $this->lockStore();
        $supported->expects('supportsFlushingLocks')->returns(true);

        $unsupported = $this->lockStore();
        $unsupported->expects('supportsFlushingLocks')->returns(false);

        $store = $this->makeFailoverStore([
            'supported' => $supported,
            'unsupported' => $unsupported,
        ]);

        $this->assertFalse($store->supportsFlushingLocks());
    }

    public function testLockFlushCapabilityIsTrueWhenEveryLockProviderSupportsIt(): void
    {
        $first = $this->lockStore();
        $first->expects('supportsFlushingLocks')->returns(true);

        $second = $this->lockStore();
        $second->expects('supportsFlushingLocks')->returns(true);

        $store = $this->makeFailoverStore([
            'plain' => Double::for(Store::class)->strict(),
            'first' => $first,
            'second' => $second,
        ]);

        $this->assertTrue($store->supportsFlushingLocks());
    }

    public function testFlushLocksPreflightsEveryLockProviderBeforeChangingState(): void
    {
        $first = $this->lockStore();
        $first->expects('supportsFlushingLocks')->returns(true);
        $first->expects('flushLocks')->never();

        $second = $this->lockStore();
        $second->expects('supportsFlushingLocks')->returns(false);
        $second->expects('flushLocks')->never();

        $store = $this->makeFailoverStore([
            'first' => $first,
            'second' => $second,
        ]);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessageIs(sprintf(
            'The failover cache store [%s] does not support flushing locks.',
            $second::class
        ));

        $store->flushLocks();
    }

    public function testFlushLocksAttemptsEveryStoreAndAggregatesFalseResults(): void
    {
        $first = $this->lockStore();
        $first->expects('supportsFlushingLocks')->returns(true);
        $first->expects('flushLocks')->returns(false);

        $second = $this->lockStore();
        $second->expects('supportsFlushingLocks')->returns(true);
        $second->expects('flushLocks')->returns(true);

        $store = $this->makeFailoverStore([
            'first' => $first,
            'second' => $second,
        ]);

        $this->assertFalse($store->flushLocks());
    }

    public function testFlushLocksAttemptsEveryStoreAndPreservesTheEarliestFailure(): void
    {
        $first = $this->lockStore();
        $first->expects('supportsFlushingLocks')->returns(true);
        $first->expects('flushLocks')->throws(new RuntimeException('first failure'));

        $second = $this->lockStore();
        $second->expects('supportsFlushingLocks')->returns(true);
        $second->expects('flushLocks')->throws(new RuntimeException('second failure'));

        $store = $this->makeFailoverStore([
            'first' => $first,
            'second' => $second,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('first failure');

        $store->flushLocks();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testFlushLocksStopsAtCancellation(bool $cleanupThrows): void
    {
        $cancellation = new CanceledException('flush canceled');
        if ($cleanupThrows) {
            $cancellation = new RuntimeException('Lock cleanup failed.', previous: $cancellation);
        }
        $first = $this->lockStore();
        $first->expects('supportsFlushingLocks')->returns(true);
        $first->expects('flushLocks')->throws($cancellation);
        $second = $this->lockStore();
        $second->expects('supportsFlushingLocks')->returns(true);
        $second->expects('flushLocks')->never();
        $store = $this->makeFailoverStore([
            'first' => $first,
            'second' => $second,
        ]);

        try {
            $store->flushLocks();
            $this->fail('Flushing the lock stores was expected to be canceled.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testFirstSuccessOperationDoesNotFailOverAfterCancellation(bool $cleanupThrows): void
    {
        $cancellation = new CanceledException('read canceled');
        if ($cleanupThrows) {
            $cancellation = new RuntimeException('Read cleanup failed.', previous: $cancellation);
        }
        $first = Double::for(Store::class)->strict();
        $first->expects('get')->with('key')->throws(new RuntimeException('first failure'));
        $second = Double::for(Store::class)->strict();
        $second->expects('get')->with('key')->throws($cancellation);
        $third = Double::for(Store::class)->strict();
        $third->expects('get')->never();
        $failedStores = [];
        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('hasListeners')->with(CacheFailedOver::class)->returns(true);
        $events->expects('dispatch')->resolves(
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
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame(['first'], $failedStores);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testEveryStoreOperationDoesNotContinueAfterCancellation(bool $cleanupThrows): void
    {
        $cancellation = new CanceledException('forget canceled');
        if ($cleanupThrows) {
            $cancellation = new RuntimeException('Forget cleanup failed.', previous: $cancellation);
        }
        $first = Double::for(Store::class)->strict();
        $first->expects('forget')->with('key')->returns(true);
        $second = Double::for(Store::class)->strict();
        $second->expects('forget')->with('key')->throws($cancellation);
        $third = Double::for(Store::class)->strict();
        $third->expects('forget')->never();
        $store = $this->makeFailoverStore([
            'first' => $first,
            'second' => $second,
            'third' => $third,
        ]);

        try {
            $store->forget('key');
            $this->fail('Forgetting from every failover store was expected to be canceled.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testFirstSuccessListenerFailurePreservesObservedAndUnattemptedFailures(): void
    {
        $listenerFailure = new RuntimeException('listener failure');
        $first = Double::for(Store::class)->strict();
        $first->expects('forget')->with('key')->returns(true);
        $first->expects('get')->times(2)->with('key')->throws(new RuntimeException('first failure'));
        $second = Double::for(Store::class)->strict();
        $second->expects('forget')->with('key')->throws(new RuntimeException('second failure'));
        $second->expects('get')->with('key')->throws(new RuntimeException('second failure'));
        $third = Double::for(Store::class)->strict();
        $third->expects('forget')->with('key')->returns(true);
        $third->expects('get')->with('key')->returns('value');
        $failedStores = [];
        $events = Double::for(Dispatcher::class)->strict();
        $events->allows('hasListeners')->with(CacheFailedOver::class)->returns(true);
        $events->allows('dispatch')->resolves(
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
        $first = Double::for(Store::class)->strict();
        $first->expects('forget')->times(3)->with('key')->resolves(
            function () use (&$firstCall): bool {
                ++$firstCall;

                return $firstCall === 1
                    ? true
                    : throw new RuntimeException('first failure');
            }
        );
        $second = Double::for(Store::class)->strict();
        $second->expects('forget')->times(2)->with('key')->throws(new RuntimeException('second failure'));
        $third = Double::for(Store::class)->strict();
        $third->expects('forget')->times(2)->with('key')->returns(true);
        $failedStores = [];
        $events = Double::for(Dispatcher::class)->strict();
        $events->allows('hasListeners')->with(CacheFailedOver::class)->returns(true);
        $events->allows('dispatch')->resolves(
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
        $first = Double::for(Store::class)->strict();
        $first->expects('forget')->times(3)->with('key')->resolves(
            function () use (&$firstCall, $firstFailure): bool {
                ++$firstCall;

                return $firstCall === 1 ? true : throw $firstFailure;
            }
        );
        $second = Double::for(Store::class)->strict();
        $second->expects('forget')->times(3)->with('key')->resolves(
            function () use (&$secondCall, $secondFailure): bool {
                ++$secondCall;

                return $secondCall === 1 ? true : throw $secondFailure;
            }
        );
        $third = Double::for(Store::class)->strict();
        $third->expects('forget')->times(2)->with('key')->throws($thirdFailure);
        $failedStores = [];
        $events = Double::for(Dispatcher::class)->strict();
        $events->allows('hasListeners')->with(CacheFailedOver::class)->returns(true);
        $events->allows('dispatch')->resolves(
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

    public function testReplacementStoreReportsItsOwnFirstFailure(): void
    {
        $cache = Double::for(CacheManager::class)->strict();
        $events = Double::for(Dispatcher::class)->strict();
        $primary = Double::for(RepositoryContract::class)->strict();
        $secondary = Double::for(RepositoryContract::class)->strict();
        $primary->allows('get')->throws(new RuntimeException('Primary unavailable.'));
        $secondary->allows('get')->returns('value');
        $cache->allows('store')->with('primary')->returns($primary);
        $cache->allows('store')->with('secondary')->returns($secondary);
        $events->allows('hasListeners')->with(CacheFailedOver::class)->returns(true);
        $events->expects('dispatch')->times(2)->with(Argument::type(CacheFailedOver::class));

        $store = new FailoverStore($cache, $events, ['primary', 'secondary']);
        $this->assertSame('value', $store->get('key'));
        unset($store);

        $replacement = new FailoverStore($cache, $events, ['primary', 'secondary']);
        $this->assertSame('value', $replacement->get('key'));
    }

    public function testFailoverEventCarriesLogicalAndFailedBackingStoreNames(): void
    {
        $exception = new RuntimeException('primary unavailable');
        $first = Double::for(Store::class)->strict();
        $first->expects('get')->with('key')->throws($exception);
        $second = Double::for(Store::class)->strict();
        $second->expects('get')->with('key')->returns('value');
        $captured = null;
        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('hasListeners')->with(CacheFailedOver::class)->returns(true);
        $events->expects('dispatch')->resolves(function (CacheFailedOver $event) use (&$captured): void {
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
        $separate->expects('hasSeparateLockStore')->returns(true);

        $shared = $this->lockStore();
        $shared->expects('hasSeparateLockStore')->returns(false);

        $store = $this->makeFailoverStore([
            'plain' => Double::for(Store::class)->strict(),
            'separate' => $separate,
            'shared' => $shared,
        ]);

        $this->assertFalse($store->hasSeparateLockStore());
    }

    public function testSeparateLockStoreReportingIsTrueWhenEveryLockProviderIsSeparate(): void
    {
        $first = $this->lockStore();
        $first->expects('hasSeparateLockStore')->returns(true);

        $second = $this->lockStore();
        $second->expects('hasSeparateLockStore')->returns(true);

        $store = $this->makeFailoverStore([
            'plain' => Double::for(Store::class)->strict(),
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
        $cache = Double::for(CacheManager::class)->strict();

        foreach ($stores as $name => $store) {
            $cache->allows('store')
                ->with($name)
                ->returns($store instanceof RepositoryContract ? $store : new Repository($store));
        }

        return new FailoverStore(
            $cache,
            $events ?? new EventDispatcher,
            $storeNames ?? array_keys($stores),
            $failoverStoreName,
        );
    }

    /**
     * Create a lock-capable store with flush support.
     */
    protected function lockStore(): Store&LockProvider&CanFlushLocks
    {
        return Double::for(Store::class, LockProvider::class, CanFlushLocks::class)->strict();
    }
}
