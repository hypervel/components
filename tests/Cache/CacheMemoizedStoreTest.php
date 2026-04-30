<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Events\CacheHit;
use Hypervel\Cache\Events\CacheMissed;
use Hypervel\Cache\Events\RetrievingManyKeys;
use Hypervel\Cache\MemoizedStore;
use Hypervel\Cache\NullSentinel;
use Hypervel\Cache\Repository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Support\Carbon;
use Hypervel\Tests\TestCase;
use Mockery as m;

class CacheMemoizedStoreTest extends TestCase
{
    public function testTouchExtendsTtl()
    {
        $store = new MemoizedStore('test', new Repository(new ArrayStore));

        Carbon::setTestNow($now = Carbon::now());

        $store->put('foo', 'bar', 30);
        $store->touch('foo', 60);

        Carbon::setTestNow($now->addSeconds(45));

        $this->assertSame('bar', $store->get('foo'));
    }

    public function testNullSentinelRoundTripsThroughMemoizedStore(): void
    {
        $innerRepo = new Repository(new ArrayStore(serializesValues: true));
        $memoized = new MemoizedStore('memoized', $innerRepo);
        $outerRepo = new Repository($memoized);

        $result1 = $outerRepo->rememberNullable('k', 60, fn () => null);
        $this->assertNull($result1);

        $this->assertSame(NullSentinel::VALUE, $memoized->getRaw('k'));

        $invoked = false;
        $result2 = $outerRepo->rememberNullable('k', 60, function () use (&$invoked) {
            $invoked = true;
            return 'should-not-run';
        });
        $this->assertNull($result2);
        $this->assertFalse($invoked, 'Callback must not re-run — proves the RawReadable seam works across the memo layer');
    }

    public function testPlainRememberTreatsCachedSentinelAsHitThroughMemoizedStore(): void
    {
        $innerRepo = new Repository(new ArrayStore(serializesValues: true));
        $outerRepo = new Repository(new MemoizedStore('memoized', $innerRepo));

        $outerRepo->rememberNullable('k', 60, fn () => null);

        $invoked = false;
        $result = $outerRepo->remember('k', 60, function () use (&$invoked) {
            $invoked = true;
            return 'should-not-run';
        });

        $this->assertNull($result);
        $this->assertFalse($invoked);
    }

    public function testPlainFlexibleTreatsCachedSentinelAsHitThroughMemoizedStore(): void
    {
        $innerRepo = new Repository(new ArrayStore(serializesValues: true));
        $outerRepo = new Repository(new MemoizedStore('memoized', $innerRepo));

        $outerRepo->flexibleNullable('k', [60, 120], fn () => null);

        $invoked = false;
        $result = $outerRepo->flexible('k', [60, 120], function () use (&$invoked) {
            $invoked = true;
            return 'should-not-run';
        });

        $this->assertNull($result);
        $this->assertFalse($invoked);
    }

    public function testManyFiresCacheHitNotCacheMissedForSentinelThroughMemoizedStack(): void
    {
        $innerRepo = new Repository(new ArrayStore(serializesValues: true));
        $outerRepo = new Repository(new MemoizedStore('memoized', $innerRepo));

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
}
