<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache;

use Exception;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\Events\CacheFailedOver;
use Hypervel\Cache\Events\CacheHit;
use Hypervel\Cache\Events\CacheMissed;
use Hypervel\Cache\Events\RetrievingManyKeys;
use Hypervel\Cache\FailoverStore;
use Hypervel\Cache\NullSentinel;
use Hypervel\Cache\Repository;
use Hypervel\Contracts\Cache\RawReadable;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;

#[WithConfig('cache.default', 'failover')]
#[WithConfig('cache.stores.array.serialize', false)]
class FailoverStoreTest extends TestCase
{
    use VerifiesDoubles;

    protected function setUp(): void
    {
        parent::setUp();
        CantSerialize::$throwException = true;
    }

    public function testFailoverCacheDispatchesEventOnlyOnce(): void
    {
        config([
            'cache.stores.failing_array' => array_merge(config('cache.stores.array'), ['serialize' => true]),
        ]);

        config([
            'cache.stores.failover.stores' => ['failing_array', 'array'],
        ]);

        Event::fake();

        Cache::put('irrelevant', new CantSerialize);

        Event::assertDispatched(CacheFailedOver::class, function (CacheFailedOver $event) {
            return $event->storeName === 'failing_array';
        });
        $this->assertInstanceOf(CantSerialize::class, Cache::store('array')->get('irrelevant'));

        Cache::put('irrelevant2', new CantSerialize);
        Event::assertDispatchedTimes(CacheFailedOver::class, 1);
        CantSerialize::$throwException = false;
        Cache::put('irrelevant3', new CantSerialize);
        Event::assertDispatchedTimes(CacheFailedOver::class, 1);
        CantSerialize::$throwException = true;
        Cache::put('irrelevant4', new CantSerialize);
        Event::assertDispatchedTimes(CacheFailedOver::class, 2);
    }

    public function testSeparateFailoverStoresDoNotShareFailureEventsForTheSameFailingStore(): void
    {
        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('hasListeners')
            ->times(2)
            ->with(CacheFailedOver::class)
            ->returns(true);
        $events->expects('dispatch')
            ->times(2)
            ->with(Argument::satisfies(fn (object $event) => $event instanceof CacheFailedOver && $event->storeName === 'failing'));

        $failingRepository = Double::for(CacheRepository::class, RawReadable::class)->strict();
        $failingRepository->expects('getRaw')
            ->times(2)
            ->with(Argument::type('string'))
            ->throws(new Exception('The primary store failed.'));

        $fallbackRepository = Double::for(CacheRepository::class, RawReadable::class)->strict();
        $fallbackRepository->expects('getRaw')
            ->times(2)
            ->with(Argument::type('string'))
            ->returns('fallback-a', 'fallback-b');

        $cacheManager = Double::for(CacheManager::class)->strict();
        $cacheManager->expects('store')
            ->with('failing')
            ->times(2)
            ->returns($failingRepository);
        $cacheManager->expects('store')
            ->with('fallback')
            ->times(2)
            ->returns($fallbackRepository);

        $storeA = new FailoverStore($cacheManager, $events, ['failing', 'fallback']);
        $storeB = new FailoverStore($cacheManager, $events, ['failing', 'fallback']);

        $this->assertSame('fallback-a', $storeA->get('test-a'));
        $this->assertSame('fallback-b', $storeB->get('test-b'));
    }

    public function testFailoverCacheSkipsFailedOverEventWhenThereAreNoListeners(): void
    {
        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('hasListeners')
            ->with(CacheFailedOver::class)
            ->returns(false);
        $events->expects('dispatch')->never();

        $failingRepository = Double::for(CacheRepository::class, RawReadable::class)->strict();
        $failingRepository->expects('getRaw')
            ->with('test')
            ->throws(new Exception('The primary store failed.'));

        $fallbackRepository = Double::for(CacheRepository::class, RawReadable::class)->strict();
        $fallbackRepository->expects('getRaw')
            ->with('test')
            ->returns('fallback');

        $cacheManager = Double::for(CacheManager::class)->strict();
        $cacheManager->expects('store')
            ->with('failing')
            ->returns($failingRepository);
        $cacheManager->expects('store')
            ->with('fallback')
            ->returns($fallbackRepository);

        $store = new FailoverStore($cacheManager, $events, ['failing', 'fallback']);

        $this->assertSame('fallback', $store->get('test'));
    }

    public function testGetRawNormalizesIntegerBackedEnumKeys(): void
    {
        $events = Double::for(Dispatcher::class)->strict();
        $repository = Double::for(CacheRepository::class, RawReadable::class)->strict();
        $repository->expects('getRaw')->with('0')->returns('zero');

        $cacheManager = Double::for(CacheManager::class)->strict();
        $cacheManager->expects('store')->with('primary')->returns($repository);

        $store = new FailoverStore($cacheManager, $events, ['primary']);

        $this->assertSame('zero', $store->getRaw(FailoverCacheKey::Zero));
    }

    public function testPutManyWithEmptyInputReturnsSelectedStoreResult(): void
    {
        $events = Double::for(Dispatcher::class)->strict();

        $repository = Double::for(CacheRepository::class)->strict();
        $repository->expects('putMany')->with([], 60)->returns(false);

        $cacheManager = Double::for(CacheManager::class)->strict();
        $cacheManager->expects('store')
            ->with('primary')
            ->returns($repository);

        $store = new FailoverStore($cacheManager, $events, ['primary']);

        $this->assertFalse($store->putMany([], 60));
    }

    public function testNullSentinelRoundTripsThroughFailoverStorePrimary(): void
    {
        $primaryRepo = new Repository(new ArrayStore(serializesValues: true));
        $fallbackRepo = new Repository(new ArrayStore(serializesValues: true));

        $outerRepo = $this->buildFailoverRepository($primaryRepo, $fallbackRepo);

        $count = 0;
        $result1 = $outerRepo->rememberNullable('k', 60, function () use (&$count) {
            ++$count;
            return null;
        });
        $result2 = $outerRepo->rememberNullable('k', 60, function () use (&$count) {
            ++$count;
            return null;
        });

        $this->assertNull($result1);
        $this->assertNull($result2);
        $this->assertSame(1, $count, 'Sentinel round-trips through the primary without re-running the callback');

        // Primary's inner store holds the raw sentinel; fallback untouched.
        $this->assertSame(NullSentinel::VALUE, $primaryRepo->getStore()->get('k'));
        $this->assertNull($fallbackRepo->getStore()->get('k'));
    }

    public function testPlainRememberTreatsCachedSentinelAsHitThroughFailoverStack(): void
    {
        $primaryRepo = new Repository(new ArrayStore(serializesValues: true));
        $fallbackRepo = new Repository(new ArrayStore(serializesValues: true));

        $outerRepo = $this->buildFailoverRepository($primaryRepo, $fallbackRepo);

        $outerRepo->rememberNullable('k', 60, fn () => null);

        // Plain remember on the sentinel-stored key. Without RawReadable on FailoverStore,
        // the inner Repository would unwrap the sentinel and remember() would re-run the
        // callback on every call.
        $invoked = false;
        $result = $outerRepo->remember('k', 60, function () use (&$invoked) {
            $invoked = true;
            return 'should-not-run';
        });

        $this->assertNull($result);
        $this->assertFalse($invoked);
    }

    public function testPlainFlexibleTreatsCachedSentinelAsHitThroughFailoverStack(): void
    {
        $primaryRepo = new Repository(new ArrayStore(serializesValues: true));
        $fallbackRepo = new Repository(new ArrayStore(serializesValues: true));

        $outerRepo = $this->buildFailoverRepository($primaryRepo, $fallbackRepo);

        $outerRepo->flexibleNullable('k', [60, 120], fn () => null);

        // Regression test for manyRaw() across FailoverStore. Plain flexible()'s batched
        // read must see the sentinel as a hit via FailoverStore::manyRaw(), not re-run
        // the callback or trigger a background refresh.
        $invoked = false;
        $result = $outerRepo->flexible('k', [60, 120], function () use (&$invoked) {
            $invoked = true;
            return 'should-not-run';
        });

        $this->assertNull($result);
        $this->assertFalse($invoked);
    }

    public function testManyFiresCacheHitNotCacheMissedForSentinelThroughFailoverStack(): void
    {
        $primaryRepo = new Repository(new ArrayStore(serializesValues: true));
        $fallbackRepo = new Repository(new ArrayStore(serializesValues: true));

        $outerRepo = $this->buildFailoverRepository($primaryRepo, $fallbackRepo);

        $outerRepo->rememberNullable('k', 60, fn () => null);

        // Capture only the many() read-path events by attaching the dispatcher
        // after the write.
        $captured = [];
        $events = Double::for(Dispatcher::class)->strict();
        $events->allows('hasListeners')->returns(true);
        $events->allows('dispatch')
            ->resolves(function ($event) use (&$captured) {
                $captured[] = $event;
            });
        $outerRepo->setEventDispatcher($events);

        $result = $outerRepo->many(['k']);

        $this->assertSame(['k' => null], $result);
        $this->assertCount(2, $captured);
        $this->assertInstanceOf(RetrievingManyKeys::class, $captured[0]);
        $this->assertInstanceOf(CacheHit::class, $captured[1]);
        // Null, not the sentinel value.
        $this->assertNull($captured[1]->value);
        $this->assertEmpty(array_filter($captured, fn ($event) => $event instanceof CacheMissed));
    }

    public function testForgetAndFlushReachEveryStore(): void
    {
        $primary = Double::for(CacheRepository::class)->strict();
        $primary->expects('forget')->with('key')->returns(false);
        $primary->expects('flush')->returns(true);
        $fallback = Double::for(CacheRepository::class)->strict();
        $fallback->expects('forget')->with('key')->returns(true);
        $fallback->expects('flush')->returns(true);
        $store = $this->failoverStore([
            'primary' => $primary,
            'fallback' => $fallback,
        ]);

        $this->assertTrue($store->forget('key'));
        $this->assertTrue($store->flush());
    }

    public function testPartialInvalidationFailuresReturnFalseAfterTryingEveryStore(): void
    {
        $primary = Double::for(CacheRepository::class)->strict();
        $primary->expects('forget')
            ->with('key')
            ->throws(new Exception('Primary forget failed.'));
        $primary->expects('flush')
            ->throws(new Exception('Primary flush failed.'));
        $fallback = Double::for(CacheRepository::class)->strict();
        $fallback->expects('forget')->with('key')->returns(false);
        $fallback->expects('flush')->returns(true);
        $store = $this->failoverStore([
            'primary' => $primary,
            'fallback' => $fallback,
        ]);

        $this->assertFalse($store->forget('key'));
        $this->assertFalse($store->flush());
    }

    public function testFlushReturnsFalseWhenAnyCompletedStoreReturnsFalse(): void
    {
        $primary = Double::for(CacheRepository::class)->strict();
        $primary->expects('flush')->returns(false);
        $fallback = Double::for(CacheRepository::class)->strict();
        $fallback->expects('flush')->returns(true);
        $store = $this->failoverStore([
            'primary' => $primary,
            'fallback' => $fallback,
        ]);

        $this->assertFalse($store->flush());
    }

    public function testAllFailingInvalidationRethrowsTheLastExceptionAfterTryingEveryStore(): void
    {
        $primary = Double::for(CacheRepository::class)->strict();
        $primary->expects('forget')
            ->with('key')
            ->throws(new Exception('Primary failed.'));
        $fallback = Double::for(CacheRepository::class)->strict();
        $fallback->expects('forget')
            ->with('key')
            ->throws(new Exception('Fallback failed.'));
        $store = $this->failoverStore([
            'primary' => $primary,
            'fallback' => $fallback,
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageIs('Fallback failed.');

        $store->forget('key');
    }

    public function testAllFailingFirstSuccessOperationStillRethrowsTheLastException(): void
    {
        $primary = Double::for(CacheRepository::class, RawReadable::class)->strict();
        $primary->expects('getRaw')
            ->with('key')
            ->throws(new Exception('Primary failed.'));
        $fallback = Double::for(CacheRepository::class, RawReadable::class)->strict();
        $fallback->expects('getRaw')
            ->with('key')
            ->throws(new Exception('Fallback failed.'));
        $store = $this->failoverStore([
            'primary' => $primary,
            'fallback' => $fallback,
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageIs('Fallback failed.');

        $store->getRaw('key');
    }

    public function testFailedInvalidationUpdatesFailureContextForLaterFirstSuccessWrites(): void
    {
        $events = Double::for(Dispatcher::class)->strict();
        $events->expects('hasListeners')
            ->with(CacheFailedOver::class)
            ->returns(true);
        $events->expects('dispatch')
            ->with(Argument::satisfies(
                fn (object $event): bool => $event instanceof CacheFailedOver
                    && $event->storeName === 'primary'
            ));
        $primary = Double::for(CacheRepository::class)->strict();
        $primary->expects('forget')
            ->with('key')
            ->throws(new Exception('Primary failed.'));
        $primary->expects('put')
            ->with('key', 'value', 60)
            ->throws(new Exception('Primary still failed.'));
        $fallback = Double::for(CacheRepository::class)->strict();
        $fallback->expects('forget')->with('key')->returns(true);
        $fallback->expects('put')->with('key', 'value', 60)->returns(true);
        $store = $this->failoverStore([
            'primary' => $primary,
            'fallback' => $fallback,
        ], $events);

        $this->assertFalse($store->forget('key'));
        $this->assertTrue($store->put('key', 'value', 60));
    }

    public function testSuccessfulReadsAndWritesRemainFirstSuccessOperations(): void
    {
        $primary = Double::for(CacheRepository::class, RawReadable::class)->strict();
        $primary->expects('getRaw')->with('key')->returns('value');
        $primary->expects('put')->with('key', 'updated', 60)->returns(true);
        $fallback = Double::for(CacheRepository::class, RawReadable::class)->strict();
        $fallback->expects('getRaw')->never();
        $fallback->expects('put')->never();
        $store = $this->failoverStore([
            'primary' => $primary,
            'fallback' => $fallback,
        ]);

        $this->assertSame('value', $store->getRaw('key'));
        $this->assertTrue($store->put('key', 'updated', 60));
    }

    /**
     * Build a repository with primary and fallback cache stores.
     */
    private function buildFailoverRepository(Repository $primary, Repository $fallback): Repository
    {
        $cacheManager = Double::for(CacheManager::class)->strict();
        $cacheManager->allows('store')->with('primary')->returns($primary);
        $cacheManager->allows('store')->with('fallback')->returns($fallback);

        $events = Double::for(Dispatcher::class)->strict();
        $events->allows('dispatch');

        return new Repository(new FailoverStore($cacheManager, $events, ['primary', 'fallback']));
    }

    /**
     * Build a failover store from named repository doubles.
     *
     * @param array<string, CacheRepository> $repositories
     */
    private function failoverStore(
        array $repositories,
        ?Dispatcher $events = null,
    ): FailoverStore {
        $cacheManager = Double::for(CacheManager::class)->strict();
        $cacheManager->allows('store')
            ->resolves(
                fn (string $store): CacheRepository => $repositories[$store],
            );

        if ($events === null) {
            $events = Double::for(Dispatcher::class)->strict();
            $events->allows('hasListeners')->returns(false);
        }

        return new FailoverStore(
            $cacheManager,
            $events,
            array_keys($repositories),
        );
    }
}

class CantSerialize
{
    public static bool $throwException = true;

    public function __serialize(): array
    {
        if (self::$throwException) {
            throw new Exception('You cannot serialize this.');
        }

        return [];
    }
}

enum FailoverCacheKey: int
{
    case Zero = 0;
}
