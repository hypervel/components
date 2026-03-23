<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache;

use Exception;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\Events\CacheFailedOver;
use Hypervel\Cache\FailoverStore;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
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

        Cache::put('irrelevant', new CantSerialize());

        Event::assertDispatched(CacheFailedOver::class, function (CacheFailedOver $event) {
            return $event->storeName === 'failing_array';
        });
        $this->assertInstanceOf(CantSerialize::class, Cache::store('array')->get('irrelevant'));

        Cache::put('irrelevant2', new CantSerialize());
        Event::assertDispatchedTimes(CacheFailedOver::class, 1);
        CantSerialize::$throwException = false;
        Cache::put('irrelevant3', new CantSerialize());
        Event::assertDispatchedTimes(CacheFailedOver::class, 1);
        CantSerialize::$throwException = true;
        Cache::put('irrelevant4', new CantSerialize());
        Event::assertDispatchedTimes(CacheFailedOver::class, 2);
    }

    public function testSeparateFailoverStoresDoNotShareFailureEventsForTheSameFailingStore()
    {
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')
            ->twice()
            ->with(m::on(fn (object $event) => $event instanceof CacheFailedOver && $event->storeName === 'failing'));

        $failingRepository = m::mock(CacheRepository::class);
        $failingRepository->shouldReceive('get')
            ->twice()
            ->with(m::type('string'))
            ->andThrow(new Exception('The primary store failed.'));

        $fallbackRepository = m::mock(CacheRepository::class);
        $fallbackRepository->shouldReceive('get')
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
