<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Cache;

use Exception;
use Hypervel\Cache\Limiters\ConcurrencyLease as CacheConcurrencyLease;
use Hypervel\Cache\Limiters\RefreshableConcurrencyLease as CacheRefreshableConcurrencyLease;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\RefreshableLock;
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Contracts\Limiters\Lease;
use Hypervel\Contracts\Limiters\LimiterTimeoutException;
use Hypervel\Contracts\Limiters\RefreshableLease;
use Hypervel\Testbench\TestCase;
use Throwable;

abstract class CacheFunnelTestCase extends TestCase
{
    abstract protected function cache(): Repository;

    protected function setUp(): void
    {
        parent::setUp();

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
        try {
            $this->cache()->funnel('test')
                ->limit(1)
                ->releaseAfter(60)
                ->block(0)
                ->then(function () {
                    throw new Exception('fail');
                });
        } catch (Exception) {
        }

        $result = $this->cache()->funnel('test')
            ->limit(1)
            ->releaseAfter(60)
            ->block(0)
            ->then(fn () => 'recovered');

        $this->assertSame('recovered', $result);
    }

    public function testFunnelTimeoutExceptionWithoutFailureCallback(): void
    {
        $this->cache()->lock('test1', 60)->get();
        $this->cache()->lock('test2', 60)->get();

        $this->expectException(LimiterTimeoutException::class);

        $this->cache()->funnel('test')
            ->limit(2)
            ->releaseAfter(60)
            ->block(0)
            ->then(fn () => 'should not run');
    }

    public function testFunnelFailureCallbackReceivesException(): void
    {
        $this->cache()->lock('test1', 60)->get();
        $this->cache()->lock('test2', 60)->get();

        $result = $this->cache()->funnel('test')
            ->limit(2)
            ->releaseAfter(60)
            ->block(0)
            ->then(
                fn () => 'should not run',
                function ($e) {
                    $this->assertInstanceOf(LimiterTimeoutException::class, $e);

                    return 'failed';
                }
            );

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
            $this->assertInstanceOf(Lease::class, $lease);
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
        $this->cache()->lock('key-a1', 60)->get();

        $result = $this->cache()->funnel('key-b')
            ->limit(1)
            ->releaseAfter(60)
            ->block(0)
            ->then(fn () => 'key-b-ok');

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
            $first->release();
            $second->release();
        }
    }

    public function testWrongOwnerCannotReleaseOrRefreshHeldFunnelSlot(): void
    {
        $lease = $this->cache()->funnel('lease-owner')
            ->limit(1)
            ->releaseAfter(60)
            ->block(0)
            ->acquire();

        try {
            $store = $this->cache()->getStore();

            if (! $store instanceof LockProvider) {
                $this->markTestSkipped('This cache store does not support restoring locks.');
            }

            $wrongLock = $store->restoreLock('lease-owner1', 'wrong-owner');
            $wrongLease = $wrongLock instanceof RefreshableLock
                ? new CacheRefreshableConcurrencyLease($wrongLock)
                : new CacheConcurrencyLease($wrongLock);

            $this->assertFalse($wrongLease->release());

            if ($wrongLease instanceof RefreshableLease) {
                $this->assertFalse($wrongLease->refresh());
            }

            $this->expectException(LimiterTimeoutException::class);

            $this->cache()->funnel('lease-owner')
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
        $this->cache()->lock('test1')->forceRelease();
        $this->cache()->lock('test2')->forceRelease();
        $this->cache()->lock('key-a1')->forceRelease();
        $this->cache()->lock('key-b1')->forceRelease();
        $this->cache()->lock('lease-test1')->forceRelease();
        $this->cache()->lock('lease-release-test1')->forceRelease();
        $this->cache()->lock('lease-reclaim1')->forceRelease();
        $this->cache()->lock('lease-refresh1')->forceRelease();
        $this->cache()->lock('lease-pair1')->forceRelease();
        $this->cache()->lock('lease-pair2')->forceRelease();
        $this->cache()->lock('lease-owner1')->forceRelease();
    }

    protected function tearDown(): void
    {
        try {
            $this->releaseFunnelLocks();
        } catch (Throwable) {
        }

        parent::tearDown();
    }
}
