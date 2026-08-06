<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Redis;

use Hypervel\Contracts\Limiters\LimiterTimeoutException;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Redis\Limiters\DurationLimiter;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;
use Throwable;

/**
 * Integration tests for DurationLimiter with real Redis.
 *
 * Ported from Laravel's tests/Redis/DurationLimiterTest.php.
 */
class DurationLimiterIntegrationTest extends TestCase
{
    use InteractsWithRedis;

    public function testItLocksTasksWhenNoSlotAvailable(): void
    {
        $store = [];

        (new DurationLimiter($this->redis(), 'key', 2, 2))->block(0, function () use (&$store) {
            $store[] = 1;
        });

        (new DurationLimiter($this->redis(), 'key', 2, 2))->block(0, function () use (&$store) {
            $store[] = 2;
        });

        try {
            (new DurationLimiter($this->redis(), 'key', 2, 2))->block(0, function () use (&$store) {
                $store[] = 3;
            });
        } catch (Throwable $e) {
            $this->assertInstanceOf(LimiterTimeoutException::class, $e);
        }

        $this->assertEquals([1, 2], $store);

        sleep(2);

        (new DurationLimiter($this->redis(), 'key', 2, 2))->block(0, function () use (&$store) {
            $store[] = 3;
        });

        $this->assertEquals([1, 2, 3], $store);
    }

    public function testItFailsImmediatelyOrRetriesForAWhileBasedOnAGivenTimeout(): void
    {
        $store = [];

        (new DurationLimiter($this->redis(), 'key', 1, 1))->block(2, function () use (&$store) {
            $store[] = 1;
        });

        try {
            (new DurationLimiter($this->redis(), 'key', 1, 1))->block(0, function () use (&$store) {
                $store[] = 2;
            });
        } catch (Throwable $e) {
            $this->assertInstanceOf(LimiterTimeoutException::class, $e);
        }

        (new DurationLimiter($this->redis(), 'key', 1, 1))->block(2, function () use (&$store) {
            $store[] = 3;
        });

        $this->assertEquals([1, 3], $store);
    }

    public function testItReturnsTheCallbackResult(): void
    {
        $limiter = new DurationLimiter($this->redis(), 'key', 1, 1);

        $result = $limiter->block(1, function () {
            return 'foo';
        });

        $this->assertSame('foo', $result);
    }

    public function testAcquireSetsDecaysAtAndRemaining(): void
    {
        $limiter = new DurationLimiter($this->redis(), 'acquire-key', 2, 2);
        $before = time();

        $acquired1 = $limiter->acquire();
        $this->assertTrue($acquired1);
        $this->assertGreaterThanOrEqual($before + 2, $limiter->decaysAt);
        $this->assertLessThanOrEqual(time() + 2, $limiter->decaysAt);
        $this->assertSame(1, $limiter->remaining);

        $acquired2 = $limiter->acquire();
        $this->assertTrue($acquired2);
        $this->assertSame(0, $limiter->remaining);

        $acquired3 = $limiter->acquire();
        $this->assertFalse($acquired3);
        $this->assertSame(0, $limiter->remaining);
    }

    public function testTooManyAttemptsReportsFreshWindowMetadataWithoutCreatingAKey(): void
    {
        $redis = $this->redis();
        $limiter = new DurationLimiter($redis, 'fresh-key', 2, 60);
        $before = time();

        $this->assertFalse($limiter->tooManyAttempts());
        $this->assertGreaterThanOrEqual($before + 60, $limiter->decaysAt);
        $this->assertLessThanOrEqual(time() + 60, $limiter->decaysAt);
        $this->assertSame(2, $limiter->remaining);
        $this->assertSame(0, $redis->exists('fresh-key'));
    }

    public function testTooManyAttemptsReportsOccupiedWindowMetadata(): void
    {
        $redis = $this->redis();
        $limiter = new DurationLimiter($redis, 'occupied-key', 2, 60);

        $this->assertTrue($limiter->acquire());
        $this->assertTrue($limiter->acquire());

        $this->assertTrue($limiter->tooManyAttempts());
        $this->assertGreaterThan(time(), $limiter->decaysAt);
        $this->assertSame(0, $limiter->remaining);
        $this->assertSame('2', $redis->hget('occupied-key', 'count'));
    }

    public function testTooManyAttemptsClampsOverLimitRemainingCount(): void
    {
        $limiter = new DurationLimiter($this->redis(), 'over-limit-key', 2, 60);

        $this->assertTrue($limiter->acquire());
        $this->assertTrue($limiter->acquire());
        $this->assertFalse($limiter->acquire());
        $this->assertFalse($limiter->acquire());

        $this->assertTrue($limiter->tooManyAttempts());
        $this->assertSame(0, $limiter->remaining);
    }

    public function testTooManyAttemptsReportsExpiredWindowMetadataWithoutResettingTheKey(): void
    {
        $redis = $this->redis();
        $now = time();
        $expiredAt = $now - 60;

        $redis->hmset('expired-key', [
            'start' => $now - 120,
            'end' => $expiredAt,
            'count' => 2,
        ]);

        $limiter = new DurationLimiter($redis, 'expired-key', 2, 60);
        $before = time();

        $tooManyAttempts = $limiter->tooManyAttempts();

        $this->assertGreaterThanOrEqual($before + 60, $limiter->decaysAt);
        $this->assertLessThanOrEqual(time() + 60, $limiter->decaysAt);
        $this->assertSame(2, $limiter->remaining);
        $this->assertFalse($tooManyAttempts);
        $this->assertSame((string) $expiredAt, $redis->hget('expired-key', 'end'));
    }

    public function testClearResetsLimiter(): void
    {
        $limiter = new DurationLimiter($this->redis(), 'clear-key', 1, 2);

        $this->assertTrue($limiter->acquire());
        $this->assertFalse($limiter->acquire());

        // Clear and try again
        $limiter->clear();
        $this->assertTrue($limiter->acquire());
    }

    public function testBlockReturnsTrueWithoutCallback(): void
    {
        $limiter = new DurationLimiter($this->redis(), 'no-callback-key', 1, 1);

        $this->assertTrue($limiter->block(1));
    }

    public function testAcquireResetsAfterDecay(): void
    {
        $limiter = new DurationLimiter($this->redis(), 'reset-after-decay-key', 1, 1);

        $this->assertTrue($limiter->acquire());
        $this->assertFalse($limiter->acquire());

        sleep(1);

        $this->assertTrue($limiter->acquire());
        $this->assertSame(0, $limiter->remaining);
    }

    /**
     * Get the Redis connection for testing.
     */
    private function redis(): RedisProxy
    {
        return Redis::connection();
    }
}
