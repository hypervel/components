<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use BadMethodCallException;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Events\CacheHit;
use Hypervel\Cache\Events\CacheMissed;
use Hypervel\Cache\Events\RetrievingManyKeys;
use Hypervel\Cache\MemoizedStore;
use Hypervel\Cache\NullSentinel;
use Hypervel\Cache\NullStore;
use Hypervel\Cache\Repository;
use Hypervel\Cache\StackStore;
use Hypervel\Cache\StackStoreProxy;
use Hypervel\Contracts\Cache\CanFlushLocks;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use Mockery as m;
use stdClass;

class CacheMemoizedStoreTest extends TestCase
{
    public function testTouchExtendsTtl(): void
    {
        $store = new MemoizedStore('test', new Repository(new ArrayStore));

        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        $store->put('foo', 'bar', 30);
        $store->touch('foo', 60);

        CarbonImmutable::setTestNow($now->addSeconds(45));

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

    public function testIncompleteClassHandlerRunsOnceAcrossMemoizedRepositories(): void
    {
        $innerRepo = new Repository(new ArrayStore);
        $innerRepo->forever(
            'key',
            unserialize(serialize(new stdClass), ['allowed_classes' => false])
        );

        $handled = [];

        Repository::handleUnserializableClassUsing(function (string $key, ?string $class) use (&$handled): void {
            $handled[] = [$key, $class];
        });

        $outerRepo = new Repository(new MemoizedStore('memoized', $innerRepo));

        $outerRepo->get('key');
        $outerRepo->get('key');

        $this->assertSame([['key', 'stdClass']], $handled);
    }

    public function testPutManyWithEmptyInputReturnsDelegatedRepositoryResult(): void
    {
        $repository = m::mock(Repository::class);
        $repository->shouldReceive('putMany')->once()->with([], 60)->andReturn(false);

        $store = new MemoizedStore('memoized', $repository);

        $this->assertFalse($store->putMany([], 60));
    }

    public function testPutManyInvalidatesMemoizedValues(): void
    {
        $repository = new Repository(new ArrayStore);
        $store = new MemoizedStore('memoized', $repository);

        $store->put('foo', 'old', 60);

        $this->assertSame('old', $store->get('foo'));
        $this->assertTrue($store->putMany(['foo' => 'new'], 60));
        $this->assertSame('new', $store->get('foo'));
    }

    public function testMemoizedStoreCanWrapStackStore(): void
    {
        $stackRepo = $this->createStackRepository();
        $memoizedRepo = new Repository(new MemoizedStore('stack', $stackRepo));

        $invocations = 0;
        $first = $memoizedRepo->remember('permission.roles', 60, function () use (&$invocations) {
            ++$invocations;

            return ['writer' => ['edit articles']];
        });

        $second = $memoizedRepo->remember('permission.roles', 60, function () use (&$invocations) {
            ++$invocations;

            return ['writer' => ['stale value']];
        });

        $this->assertSame(['writer' => ['edit articles']], $first);
        $this->assertSame(['writer' => ['edit articles']], $second);
        $this->assertSame(1, $invocations);

        $freshMemoizedRepo = new Repository(new MemoizedStore('stack', $stackRepo));

        $this->assertSame(['writer' => ['edit articles']], $freshMemoizedRepo->get('permission.roles'));
    }

    public function testMemoizedStorePreservesCachedNullHitsWhenWrappingStackStore(): void
    {
        $stackRepo = $this->createStackRepository();
        $memoizedRepo = new Repository(new MemoizedStore('stack', $stackRepo));

        $invocations = 0;
        $first = $memoizedRepo->rememberNullable('permission.missing', 60, function () use (&$invocations) {
            ++$invocations;

            return null;
        });

        $second = $memoizedRepo->remember('permission.missing', 60, function () use (&$invocations) {
            ++$invocations;

            return 'should-not-run';
        });

        $this->assertNull($first);
        $this->assertNull($second);
        $this->assertSame(['permission.missing' => null], $memoizedRepo->many(['permission.missing']));
        $this->assertSame(1, $invocations);
    }

    public function testManyFiresCacheHitNotCacheMissedForSentinelThroughMemoizedStore(): void
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

    public function testLockFlushCapabilityDelegatesToUnderlyingStore(): void
    {
        $flushable = new MemoizedStore('test', new Repository(new ArrayStore));
        $nonFlushableStore = m::mock(Store::class);
        $nonFlushable = new MemoizedStore('test', new Repository($nonFlushableStore));

        $this->assertTrue($flushable->supportsFlushingLocks());
        $this->assertFalse($nonFlushable->supportsFlushingLocks());
    }

    public function testFlushLocksDelegatesToUnderlyingStore(): void
    {
        $store = m::mock(Store::class, CanFlushLocks::class);
        $store->shouldReceive('supportsFlushingLocks')->once()->andReturnTrue();
        $store->shouldReceive('flushLocks')->once()->andReturnTrue();

        $memoized = new MemoizedStore('test', new Repository($store));

        $this->assertTrue($memoized->flushLocks());
    }

    public function testFlushLocksRejectsUnsupportedUnderlyingStore(): void
    {
        $store = m::mock(Store::class, CanFlushLocks::class);
        $store->shouldReceive('supportsFlushingLocks')->once()->andReturnFalse();
        $store->shouldNotReceive('flushLocks');

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage(sprintf(
            'The memoized cache store\'s underlying store [%s] does not support flushing locks.',
            $store::class
        ));

        (new MemoizedStore('test', new Repository($store)))->flushLocks();
    }

    public function testSeparateLockStoreReportingDelegatesToUnderlyingStore(): void
    {
        $separate = new MemoizedStore('test', new Repository(new ArrayStore));
        $shared = new MemoizedStore('test', new Repository(new NullStore));

        $this->assertTrue($separate->hasSeparateLockStore());
        $this->assertFalse($shared->hasSeparateLockStore());
    }

    protected function createStackRepository(): Repository
    {
        return new Repository(new StackStore([
            new StackStoreProxy(new ArrayStore(serializesValues: true), 3),
            new StackStoreProxy(new ArrayStore(serializesValues: true)),
        ]));
    }
}
