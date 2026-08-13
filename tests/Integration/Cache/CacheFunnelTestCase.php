<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache;

use Exception;
use Hypervel\Cache\Limiters\ConcurrencyLease as CacheConcurrencyLease;
use Hypervel\Cache\Limiters\RefreshableConcurrencyLease as CacheRefreshableConcurrencyLease;
use Hypervel\Cache\RedisStore;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\RefreshableLock;
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Contracts\Limiters\LimiterTimeoutException;
use Hypervel\Contracts\Limiters\RefreshableLease;
use Hypervel\Redis\RedisConnection;
use Hypervel\Testbench\TestCase;
use Throwable;

abstract class CacheFunnelTestCase extends TestCase
{
    abstract protected function cache(): Repository;

    protected function setUpInCoroutine(): void
    {
        try {
            $this->releaseFunnelLocks();
        } catch (Throwable) {
        }
    }

    public function testFunnelBasicHappyPath(): void
    {
        $result = $this->cache()->funnel('test')
            ->limit(2)
            ->releaseAfter(60)
            ->block(0)
            ->then(fn () => 'hello');

        $this->assertSame('hello', $result);
    }

    public function testFunnelReleasesLockAfterCallback(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $result = $this->cache()->funnel('test')
                ->limit(1)
                ->releaseAfter(60)
                ->block(0)
                ->then(fn () => 'ok');

            $this->assertSame('ok', $result);
        }
    }

    public function testFunnelLockReleasedOnException(): void
    {
        $expectedException = new Exception('fail');
        $caughtException = null;

        try {
            $this->cache()->funnel('test')
                ->limit(1)
                ->releaseAfter(60)
                ->block(0)
                ->then(function () use ($expectedException): never {
                    throw $expectedException;
                });
        } catch (Exception $exception) {
            $caughtException = $exception;
        }

        $this->assertSame($expectedException, $caughtException);

        $result = $this->cache()->funnel('test')
            ->limit(1)
            ->releaseAfter(60)
            ->block(0)
            ->then(fn () => 'recovered');

        $this->assertSame('recovered', $result);
    }

    public function testFunnelTimeoutExceptionWithoutFailureCallback(): void
    {
        $funnel = $this->cache()->funnel('test')
            ->limit(2)
            ->releaseAfter(60)
            ->block(0);
        $first = $funnel->acquire();

        try {
            $second = $funnel->acquire();

            try {
                $this->expectException(LimiterTimeoutException::class);

                $funnel->then(fn () => 'should not run');
            } finally {
                $second->release();
            }
        } finally {
            $first->release();
        }
    }

    public function testFunnelFailureCallbackReceivesException(): void
    {
        $funnel = $this->cache()->funnel('test')
            ->limit(2)
            ->releaseAfter(60)
            ->block(0);
        $first = $funnel->acquire();

        try {
            $second = $funnel->acquire();

            try {
                $result = $funnel->then(
                    fn () => 'should not run',
                    function ($e) {
                        $this->assertInstanceOf(LimiterTimeoutException::class, $e);

                        return 'failed';
                    }
                );
            } finally {
                $second->release();
            }
        } finally {
            $first->release();
        }

        $this->assertSame('failed', $result);
    }

    public function testFunnelAcquireReturnsLease(): void
    {
        $lease = $this->cache()->funnel('lease-test')
            ->limit(1)
            ->releaseAfter(60)
            ->block(0)
            ->acquire();

        try {
            $this->assertNotEmpty($lease->owner());

            if ($lease instanceof RefreshableLease) {
                $this->assertTrue($lease->refresh());
            }
        } finally {
            $lease->release();
        }
    }

    public function testFunnelLeaseReleaseFreesSlot(): void
    {
        $lease = $this->cache()->funnel('lease-release-test')
            ->limit(1)
            ->releaseAfter(60)
            ->block(0)
            ->acquire();

        $this->assertTrue($lease->release());

        $result = $this->cache()->funnel('lease-release-test')
            ->limit(1)
            ->releaseAfter(60)
            ->block(0)
            ->then(fn () => 'released');

        $this->assertSame('released', $result);
    }

    public function testFunnelIndependentKeys(): void
    {
        $lease = $this->cache()->funnel('key-a')
            ->limit(1)
            ->releaseAfter(60)
            ->block(0)
            ->acquire();

        try {
            $result = $this->cache()->funnel('key-b')
                ->limit(1)
                ->releaseAfter(60)
                ->block(0)
                ->then(fn () => 'key-b-ok');
        } finally {
            $lease->release();
        }

        $this->assertSame('key-b-ok', $result);
    }

    public function testLeakedFunnelLeaseIsReclaimedAfterReleaseAfter(): void
    {
        $this->cache()->funnel('lease-reclaim')
            ->limit(1)
            ->releaseAfter(1)
            ->block(0)
            ->acquire();

        $this->expectException(LimiterTimeoutException::class);

        try {
            $this->cache()->funnel('lease-reclaim')
                ->limit(1)
                ->releaseAfter(1)
                ->block(0)
                ->acquire();
        } finally {
            usleep(1_200_000);

            $lease = $this->cache()->funnel('lease-reclaim')
                ->limit(1)
                ->releaseAfter(1)
                ->block(0)
                ->acquire();

            $this->assertTrue($lease->release());
        }
    }

    public function testFunnelLeaseRefreshExtendsLifetime(): void
    {
        $lease = $this->cache()->funnel('lease-refresh')
            ->limit(1)
            ->releaseAfter(3)
            ->block(0)
            ->acquire();

        try {
            if (! $lease instanceof RefreshableLease) {
                $this->markTestSkipped('This cache store does not return refreshable funnel leases.');
            }

            usleep(1_100_000);

            $decayedLifetime = $lease->getRemainingLifetime();
            $this->assertNotNull($decayedLifetime);

            $this->assertTrue($lease->refresh());

            $refreshedLifetime = $lease->getRemainingLifetime();
            $this->assertNotNull($refreshedLifetime);
            $this->assertGreaterThan($decayedLifetime, $refreshedLifetime);
        } finally {
            $lease->release();
        }
    }

    public function testTwoFunnelLeasesDoNotInterfereWithEachOther(): void
    {
        $funnel = $this->cache()->funnel('lease-pair')
            ->limit(2)
            ->releaseAfter(60)
            ->block(0);

        $first = $funnel->acquire();

        try {
            $second = $funnel->acquire();

            try {
                $this->expectException(LimiterTimeoutException::class);

                try {
                    $this->cache()->funnel('lease-pair')
                        ->limit(2)
                        ->releaseAfter(60)
                        ->block(0)
                        ->acquire();
                } finally {
                    $this->assertTrue($first->release());

                    $third = $this->cache()->funnel('lease-pair')
                        ->limit(2)
                        ->releaseAfter(60)
                        ->block(0)
                        ->acquire();
                    $this->assertTrue($third->release());

                    if ($second instanceof RefreshableLease) {
                        $this->assertTrue($second->refresh());
                    }
                }
            } finally {
                $second->release();
            }
        } finally {
            $first->release();
        }
    }

    public function testWrongOwnerCannotReleaseOrRefreshHeldFunnelSlot(): void
    {
        $lease = $this->cache()->funnel('{lease-owner}')
            ->limit(1)
            ->releaseAfter(60)
            ->block(0)
            ->acquire();

        try {
            $store = $this->cache()->getStore();

            if (! $store instanceof LockProvider) {
                $this->markTestSkipped('This cache store does not support restoring locks.');
            }

            $wrongLock = $store->restoreLock('{lease-owner}1', 'wrong-owner');
            $wrongLease = $wrongLock instanceof RefreshableLock
                ? new CacheRefreshableConcurrencyLease($wrongLock)
                : new CacheConcurrencyLease($wrongLock);

            $this->assertFalse($wrongLease->release());

            if ($wrongLease instanceof RefreshableLease) {
                $this->assertFalse($wrongLease->refresh());
            }

            $this->expectException(LimiterTimeoutException::class);

            $this->cache()->funnel('{lease-owner}')
                ->limit(1)
                ->releaseAfter(60)
                ->block(0)
                ->acquire();
        } finally {
            $lease->release();
        }
    }

    protected function releaseFunnelLocks(): void
    {
        $cache = $this->cache();
        $store = $cache->getStore();
        $cluster = $store instanceof RedisStore && $store->lockConnection()->isCluster();
        $slots = [
            ['test', 1],
            ['test', 2],
            ['key-a', 1],
            ['key-b', 1],
            ['lease-test', 1],
            ['lease-release-test', 1],
            ['lease-reclaim', 1],
            ['lease-refresh', 1],
            ['lease-pair', 1],
            ['lease-pair', 2],
            ['{lease-owner}', 1],
        ];

        foreach ($slots as [$name, $slot]) {
            $tagged = $cluster && ! RedisConnection::hasHashTag($name)
                ? '{' . $name . '}'
                : $name;

            $cache->lock($tagged . $slot)->forceRelease();
        }
    }

    protected function tearDownInCoroutine(): void
    {
        try {
            $this->releaseFunnelLocks();
        } catch (Throwable) {
        }
    }
}
