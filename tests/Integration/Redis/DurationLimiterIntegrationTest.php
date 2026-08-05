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

        $acquired1 = $limiter->acquire();
        $this->assertTrue($acquired1);
        $this->assertGreaterThanOrEqual(time(), $limiter->decaysAt);
        $this->assertSame(1, $limiter->remaining);

        $acquired2 = $limiter->acquire();
        $this->assertTrue($acquired2);
        $this->assertSame(0, $limiter->remaining);

        $acquired3 = $limiter->acquire();
        $this->assertFalse($acquired3);
        $this->assertSame(0, $limiter->remaining);
    }

    public function testTooManyAttemptsReportsCorrectly(): void
    {
        $limiter = new DurationLimiter($this->redis(), 'too-many-key', 2, 1);

        // Initially, should not have too many attempts
        $this->assertFalse($limiter->tooManyAttempts());
        $this->assertSame(0, $limiter->decaysAt);
        $this->assertGreaterThan(0, $limiter->remaining);

        // Use up the available slots
        $this->assertTrue($limiter->acquire());
        $this->assertTrue($limiter->acquire());

        // Now, too many attempts within the same window
        $this->assertTrue($limiter->tooManyAttempts());
        $this->assertSame(0, max(0, $limiter->remaining));

        // After decay window, attempts should be allowed again
        sleep(1);
        $this->assertFalse($limiter->tooManyAttempts());
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

    public function testAcquireUsesTheSelectedConnectionPrefix(): void
    {
        $prefixed = Redis::connection($this->createRedisConnectionWithOptions(
            'duration_limiter_prefixed',
            ['prefix' => 'duration-limiter:'],
        ));
        $plain = Redis::connection($this->createRedisConnectionWithOptions(
            'duration_limiter_plain',
            ['prefix' => ''],
        ));

        $plain->del('duration-limiter:selected-connection', 'selected-connection');

        try {
            $this->assertTrue((new DurationLimiter($prefixed, 'selected-connection', 1, 60))->acquire());
            $this->assertSame(1, $plain->exists('duration-limiter:selected-connection'));
            $this->assertSame(0, $plain->exists('selected-connection'));
        } finally {
            $plain->del('duration-limiter:selected-connection', 'selected-connection');
        }
    }

    /**
     * Get the Redis connection for testing.
     */
    private function redis(): RedisProxy
    {
        return Redis::connection();
    }
}
