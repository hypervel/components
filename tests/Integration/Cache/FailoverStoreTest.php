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
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Mockery as m;

#[WithConfig('cache.default', 'failover')]
#[WithConfig('cache.stores.array.serialize', false)]
class FailoverStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CantSerialize::$throwException = true;
    }

    public function testFailoverCacheDispatchesEventOnlyOnce()
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

    public function testSeparateFailoverStoresDoNotShareFailureEventsForTheSameFailingStore()
    {
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')
            ->twice()
            ->with(m::on(fn (object $event) => $event instanceof CacheFailedOver && $event->storeName === 'failing'));

        // FailoverStore::get() now delegates to getRaw() internally (for sentinel-aware
        // reads via RawReadable). The mocks target getRaw() accordingly — the contract
        // is the same, just routed through the raw-read path.
        $failingRepository = m::mock(CacheRepository::class);
        $failingRepository->shouldReceive('getRaw')
            ->twice()
            ->with(m::type('string'))
            ->andThrow(new Exception('The primary store failed.'));

        $fallbackRepository = m::mock(CacheRepository::class);
        $fallbackRepository->shouldReceive('getRaw')
            ->twice()
            ->with(m::type('string'))
            ->andReturn('fallback-a', 'fallback-b');

        $cacheManager = m::mock(CacheManager::class);
        $cacheManager->shouldReceive('store')
            ->with('failing')
            ->twice()
            ->andReturn($failingRepository);
        $cacheManager->shouldReceive('store')
            ->with('fallback')
            ->twice()
            ->andReturn($fallbackRepository);

        $storeA = new FailoverStore($cacheManager, $events, ['failing', 'fallback']);
        $storeB = new FailoverStore($cacheManager, $events, ['failing', 'fallback']);

        $this->assertSame('fallback-a', $storeA->get('test-a'));
        $this->assertSame('fallback-b', $storeB->get('test-b'));
    }

    public function testNullSentinelRoundTripsThroughFailoverStorePrimary()
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

    public function testPlainRememberTreatsCachedSentinelAsHitThroughFailoverStack()
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

    public function testPlainFlexibleTreatsCachedSentinelAsHitThroughFailoverStack()
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

    public function testManyFiresCacheHitNotCacheMissedForSentinelThroughFailoverStack()
    {
        $primaryRepo = new Repository(new ArrayStore(serializesValues: true));
        $fallbackRepo = new Repository(new ArrayStore(serializesValues: true));

        $outerRepo = $this->buildFailoverRepository($primaryRepo, $fallbackRepo);

        $outerRepo->rememberNullable('k', 60, fn () => null);

        // Capture only the many() read-path events by attaching the dispatcher
        // after the write.
        $captured = [];
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->withAnyArgs()->andReturn(true);
        $events->shouldReceive('dispatch')
            ->andReturnUsing(function ($event) use (&$captured) {
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
        $this->assertEmpty(array_filter($captured, fn ($e) => $e instanceof CacheMissed));
    }

    private function buildFailoverRepository(Repository $primary, Repository $fallback): Repository
    {
        $cacheManager = m::mock(CacheManager::class);
        $cacheManager->shouldReceive('store')->with('primary')->andReturn($primary);
        $cacheManager->shouldReceive('store')->with('fallback')->andReturn($fallback);

        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->withAnyArgs()->andReturnNull();

        return new Repository(new FailoverStore($cacheManager, $events, ['primary', 'fallback']));
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
