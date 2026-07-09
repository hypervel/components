<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Redis\ConcurrencyLimiterIntegrationTest;

use Error;
use Hypervel\Contracts\Limiters\LimiterTimeoutException;
use Hypervel\Contracts\Limiters\RefreshableLease;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Redis\Limiters\ConcurrencyLease;
use Hypervel\Redis\Limiters\ConcurrencyLimiter;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;
use Throwable;

/**
 * Integration tests for ConcurrencyLimiter with real Redis.
 *
 * Ported from Laravel's tests/Redis/ConcurrentLimiterTest.php.
 */
class ConcurrencyLimiterIntegrationTest extends TestCase
{
    use InteractsWithRedis;

    public function testItLocksTasksWhenNoSlotAvailable(): void
    {
        $store = [];

        foreach (range(1, 2) as $i) {
            (new ConcurrencyLimiterMockThatDoesntRelease($this->redis(), 'key', 2, 5))->block(2, function () use (&$store, $i) {
                $store[] = $i;
            });
        }

        try {
            (new ConcurrencyLimiterMockThatDoesntRelease($this->redis(), 'key', 2, 5))->block(0, function () use (&$store) {
                $store[] = 3;
            });
        } catch (Throwable $e) {
            $this->assertInstanceOf(LimiterTimeoutException::class, $e);
        }

        (new ConcurrencyLimiterMockThatDoesntRelease($this->redis(), 'other_key', 2, 5))->block(2, function () use (&$store) {
            $store[] = 4;
        });

        $this->assertEquals([1, 2, 4], $store);
    }

    public function testItReleasesLockAfterTaskFinishes(): void
    {
        $store = [];

        foreach (range(1, 4) as $i) {
            (new ConcurrencyLimiter($this->redis(), 'key', 2, 5))->block(2, function () use (&$store, $i) {
                $store[] = $i;
            });
        }

        $this->assertEquals([1, 2, 3, 4], $store);
    }

    public function testItReleasesLockIfTaskTookTooLong(): void
    {
        $store = [];

        $lock = new ConcurrencyLimiterMockThatDoesntRelease($this->redis(), 'key', 1, 1);

        $lock->block(2, function () use (&$store) {
            $store[] = 1;
        });

        try {
            $lock->block(0, function () use (&$store) {
                $store[] = 2;
            });
        } catch (Throwable $e) {
            $this->assertInstanceOf(LimiterTimeoutException::class, $e);
        }

        usleep(1_200_000);

        $lock->block(0, function () use (&$store) {
            $store[] = 3;
        });

        $this->assertEquals([1, 3], $store);
    }

    public function testItFailsImmediatelyOrRetriesForAWhileBasedOnAGivenTimeout(): void
    {
        $store = [];

        $lock = new ConcurrencyLimiterMockThatDoesntRelease($this->redis(), 'key', 1, 2);

        $lock->block(2, function () use (&$store) {
            $store[] = 1;
        });

        try {
            $lock->block(0, function () use (&$store) {
                $store[] = 2;
            });
        } catch (Throwable $e) {
            $this->assertInstanceOf(LimiterTimeoutException::class, $e);
        }

        $lock->block(3, function () use (&$store) {
            $store[] = 3;
        });

        $this->assertEquals([1, 3], $store);
    }

    public function testItFailsAfterRetryTimeout(): void
    {
        $store = [];

        $lock = new ConcurrencyLimiterMockThatDoesntRelease($this->redis(), 'key', 1, 10);

        $lock->block(2, function () use (&$store) {
            $store[] = 1;
        });

        try {
            $lock->block(2, function () use (&$store) {
                $store[] = 2;
            });
        } catch (Throwable $e) {
            $this->assertInstanceOf(LimiterTimeoutException::class, $e);
        }

        $this->assertEquals([1], $store);
    }

    public function testItReleasesIfErrorIsThrown(): void
    {
        $store = [];

        $lock = new ConcurrencyLimiter($this->redis(), 'key', 1, 5);

        try {
            $lock->block(1, function () {
                throw new Error;
            });
        } catch (Error) {
        }

        $lock = new ConcurrencyLimiter($this->redis(), 'key', 1, 5);
        $lock->block(1, function () use (&$store) {
            $store[] = 1;
        });

        $this->assertEquals([1], $store);
    }

    public function testAcquireReturnsReleasableLease(): void
    {
        $this->deleteSlots('lease-release', 1);

        $lease = (new ConcurrencyLimiter($this->redis(), 'lease-release', 1, 5))->acquire(0);

        try {
            $this->assertInstanceOf(RefreshableLease::class, $lease);
            $this->assertNotEmpty($lease->owner());
            $this->assertTrue($lease->release());

            $result = (new ConcurrencyLimiter($this->redis(), 'lease-release', 1, 5))->block(0, fn () => 'released');

            $this->assertSame('released', $result);
        } finally {
            $lease->release();
            $this->deleteSlots('lease-release', 1);
        }
    }

    public function testLeakedLeaseIsReclaimedAfterReleaseAfter(): void
    {
        $this->deleteSlots('lease-reclaim', 1);

        (new ConcurrencyLimiter($this->redis(), 'lease-reclaim', 1, 1))->acquire(0);

        $this->expectException(LimiterTimeoutException::class);

        try {
            (new ConcurrencyLimiter($this->redis(), 'lease-reclaim', 1, 1))->acquire(0);
        } finally {
            usleep(1_200_000);

            $lease = (new ConcurrencyLimiter($this->redis(), 'lease-reclaim', 1, 1))->acquire(0);
            $this->assertTrue($lease->release());
            $this->deleteSlots('lease-reclaim', 1);
        }
    }

    public function testLeaseRefreshExtendsLifetime(): void
    {
        $this->deleteSlots('lease-refresh', 1);

        $lease = (new ConcurrencyLimiter($this->redis(), 'lease-refresh', 1, 3))->acquire(0);

        try {
            usleep(1_100_000);

            $decayedLifetime = $lease->getRemainingLifetime();
            $this->assertNotNull($decayedLifetime);

            $this->assertTrue($lease->refresh());

            $refreshedLifetime = $lease->getRemainingLifetime();
            $this->assertNotNull($refreshedLifetime);
            $this->assertGreaterThan($decayedLifetime, $refreshedLifetime);
        } finally {
            $lease->release();
            $this->deleteSlots('lease-refresh', 1);
        }
    }

    public function testPermanentLeaseRefreshChecksOwnershipAndHasNoLifetime(): void
    {
        $this->deleteSlots('lease-permanent', 1);

        $lease = (new ConcurrencyLimiter($this->redis(), 'lease-permanent', 1, 0))->acquire(0);

        try {
            $this->assertTrue($lease->refresh());
            $this->assertNull($lease->getRemainingLifetime());

            $this->redis()->del('lease-permanent1');

            $this->assertFalse($lease->refresh());
        } finally {
            $lease->release();
            $this->deleteSlots('lease-permanent', 1);
        }
    }

    public function testTwoLeasesDoNotInterfereWithEachOther(): void
    {
        $this->deleteSlots('lease-pair', 2);

        $limiter = new ConcurrencyLimiter($this->redis(), 'lease-pair', 2, 5);
        $first = $limiter->acquire(0);
        $second = $limiter->acquire(0);

        try {
            $this->expectException(LimiterTimeoutException::class);

            try {
                (new ConcurrencyLimiter($this->redis(), 'lease-pair', 2, 5))->acquire(0);
            } finally {
                $this->assertTrue($first->release());

                $third = (new ConcurrencyLimiter($this->redis(), 'lease-pair', 2, 5))->acquire(0);
                $this->assertTrue($third->release());

                $this->assertTrue($second->refresh());
            }
        } finally {
            $first->release();
            $second->release();
            $this->deleteSlots('lease-pair', 2);
        }
    }

    public function testWrongOwnerCannotReleaseOrRefreshHeldSlot(): void
    {
        $this->deleteSlots('lease-owner', 1);

        $lease = (new ConcurrencyLimiter($this->redis(), 'lease-owner', 1, 5))->acquire(0);
        $wrongOwner = new ConcurrencyLease($this->redis(), 'lease-owner1', 'wrong-owner', 5);

        try {
            $this->assertFalse($wrongOwner->release());
            $this->assertFalse($wrongOwner->refresh());

            $this->expectException(LimiterTimeoutException::class);

            (new ConcurrencyLimiter($this->redis(), 'lease-owner', 1, 5))->acquire(0);
        } finally {
            $lease->release();
            $this->deleteSlots('lease-owner', 1);
        }
    }

    /**
     * Get the Redis connection for testing.
     */
    private function redis(): RedisProxy
    {
        return Redis::connection();
    }

    /**
     * Delete limiter slot keys for the given limiter name.
     */
    private function deleteSlots(string $name, int $slots): void
    {
        foreach (range(1, $slots) as $slot) {
            $this->redis()->del($name . $slot);
        }
    }
}

/**
 * Mock that prevents lock release, used to test slot exhaustion.
 */
class ConcurrencyLimiterMockThatDoesntRelease extends ConcurrencyLimiter
{
    public function block(int $timeout, ?callable $callback = null, int $sleep = 250): mixed
    {
        $this->acquire($timeout, $sleep);

        if (is_callable($callback)) {
            return $callback();
        }

        return true;
    }
}
