<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache;

use Hypervel\Contracts\Cache\Repository;
use Hypervel\Coroutine\Parallel;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Support\Facades\Cache;

class RedisCacheFunnelTest extends CacheFunnelTestCase
{
    use InteractsWithRedis;

    protected function cache(): Repository
    {
        return Cache::store('redis');
    }

    public function testCoroutineConcurrencyAllSlotsHeldAllFail()
    {
        $cache = $this->cache();

        $lock1 = $cache->lock('test1', 60);
        $lock1->get();
        $lock2 = $cache->lock('test2', 60);
        $lock2->get();

        $parallel = new Parallel(5);
        for ($i = 0; $i < 10; ++$i) {
            $parallel->add(
                static fn () => $cache->funnel('test')
                    ->limit(2)->releaseAfter(60)->block(0)
                    ->then(fn () => 'success', fn () => 'failed')
            );
        }

        $this->assertSame(array_fill(0, 10, 'failed'), $parallel->wait());

        $lock1->forceRelease();
        $lock2->forceRelease();
    }

    public function testCoroutineConcurrencyLimitMatchesCount()
    {
        $cache = $this->cache();

        $parallel = new Parallel(5);
        for ($i = 0; $i < 5; ++$i) {
            $parallel->add(
                static fn () => $cache->funnel('test')
                    ->limit(5)->releaseAfter(60)->block(2)
                    ->then(fn () => 'ok')
            );
        }

        $results = $parallel->wait();
        $this->assertCount(5, $results);
        $this->assertNotContains(null, $results);
        foreach ($results as $result) {
            $this->assertSame('ok', $result);
        }
    }

    public function testFunnelWithZeroReleaseAfterAcquiresAndReleasesPermanentSlot()
    {
        // releaseAfter(0) means no TTL — RedisLock semantic for "permanent".
        // The Lua acquire path must SET without EX in that case (EX 0 errors).
        // Slot still releases via the explicit RedisLock release after the callback.
        $cache = $this->cache();
        $cache->lock('perm1')->forceRelease();

        $first = $cache->funnel('perm')
            ->limit(1)
            ->releaseAfter(0)
            ->block(0)
            ->then(fn () => 'first');
        $this->assertSame('first', $first);

        $second = $cache->funnel('perm')
            ->limit(1)
            ->releaseAfter(0)
            ->block(0)
            ->then(fn () => 'second');
        $this->assertSame('second', $second);

        $cache->lock('perm1')->forceRelease();
    }

    public function testFunnelWithZeroLimitOnRedisDoesNotRunCallback()
    {
        // limit(0) results in zero precomputed slots — the limiter must short-circuit
        // before calling Lua eval, otherwise unpack({}) → redis.call('mget') errors.
        $called = false;

        $result = $this->cache()->funnel('zero')
            ->limit(0)
            ->releaseAfter(5)
            ->block(0)
            ->then(
                function () use (&$called) {
                    $called = true;

                    return 'should-not-run';
                },
                fn () => 'failed',
            );

        $this->assertFalse($called);
        $this->assertSame('failed', $result);
    }
}
