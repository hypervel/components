<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Contracts\Redis\LimiterTimeoutException;
use Hypervel\Redis\Limiters\DurationLimiter;
use Hypervel\Redis\RedisProxy;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * Tests for DurationLimiter.
 *
 * DurationLimiter provides a sliding window rate limiter using Redis Lua scripts.
 */
class DurationLimiterTest extends TestCase
{
    public function testAcquireSucceedsWhenBelowLimit(): void
    {
        $redis = $this->mockRedis();
        // Lua script returns: [acquired (1=success), decaysAt, remaining]
        $redis->shouldReceive('eval')
            ->once()
            ->andReturn([1, time() + 60, 4]);

        $limiter = new DurationLimiter($redis, 'test-key', 5, 60);

        $result = $limiter->acquire();

        $this->assertTrue($result);
        $this->assertSame(4, $limiter->remaining);
    }

    public function testAcquireUsesTransformedEvalSignature(): void
    {
        $redis = $this->mockRedis();
        $redis->shouldReceive('eval')
            ->once()
            ->withArgs(function (string $script, int $numberOfKeys, string $name, float $microtime, int $timestamp, int $decay, int $maxLocks): bool {
                $this->assertNotSame('', $script);
                $this->assertSame(1, $numberOfKeys);
                $this->assertSame('test-key', $name);
                $this->assertGreaterThan(0.0, $microtime);
                $this->assertGreaterThan(0, $timestamp);
                $this->assertSame(60, $decay);
                $this->assertSame(5, $maxLocks);

                return true;
            })
            ->andReturn([1, time() + 60, 4]);

        $limiter = new DurationLimiter($redis, 'test-key', 5, 60);

        $this->assertTrue($limiter->acquire());
    }

    public function testAcquireFailsWhenAtLimit(): void
    {
        $redis = $this->mockRedis();
        // Lua script returns: [acquired (0=failed), decaysAt, remaining]
        $redis->shouldReceive('eval')
            ->once()
            ->andReturn([0, time() + 30, 0]);

        $limiter = new DurationLimiter($redis, 'test-key', 5, 60);

        $result = $limiter->acquire();

        $this->assertFalse($result);
        $this->assertSame(0, $limiter->remaining);
    }

    public function testRemainingIsNeverNegative(): void
    {
        $redis = $this->mockRedis();
        // Even if script returns negative, remaining should be 0
        $redis->shouldReceive('eval')
            ->once()
            ->andReturn([0, time() + 60, -2]);

        $limiter = new DurationLimiter($redis, 'test-key', 5, 60);

        $limiter->acquire();

        $this->assertSame(0, $limiter->remaining);
    }

    public function testTooManyAttemptsReturnsTrueWhenNoRemaining(): void
    {
        $redis = $this->mockRedis();
        $redis->shouldReceive('eval')
            ->once()
            ->andReturn([time() + 60, 0]);

        $limiter = new DurationLimiter($redis, 'test-key', 5, 60);

        $result = $limiter->tooManyAttempts();

        $this->assertTrue($result);
        $this->assertSame(0, $limiter->remaining);
    }

    public function testTooManyAttemptsReturnsFalseWhenHasRemaining(): void
    {
        $redis = $this->mockRedis();
        $redis->shouldReceive('eval')
            ->once()
            ->andReturn([time() + 60, 3]);

        $limiter = new DurationLimiter($redis, 'test-key', 5, 60);

        $result = $limiter->tooManyAttempts();

        $this->assertFalse($result);
        $this->assertSame(3, $limiter->remaining);
    }

    public function testTooManyAttemptsUsesTransformedEvalSignature(): void
    {
        $redis = $this->mockRedis();
        $redis->shouldReceive('eval')
            ->once()
            ->withArgs(function (string $script, int $numberOfKeys, string $name, float $microtime, int $timestamp, int $decay, int $maxLocks): bool {
                $this->assertNotSame('', $script);
                $this->assertSame(1, $numberOfKeys);
                $this->assertSame('test-key', $name);
                $this->assertGreaterThan(0.0, $microtime);
                $this->assertGreaterThan(0, $timestamp);
                $this->assertSame(60, $decay);
                $this->assertSame(5, $maxLocks);

                return true;
            })
            ->andReturn([time() + 60, 2]);

        $limiter = new DurationLimiter($redis, 'test-key', 5, 60);

        $this->assertFalse($limiter->tooManyAttempts());
        $this->assertSame(2, $limiter->remaining);
    }

    public function testClearDeletesKey(): void
    {
        $redis = $this->mockRedis();
        $redis->shouldReceive('del')
            ->once()
            ->with('test-key')
            ->andReturn(1);

        $limiter = new DurationLimiter($redis, 'test-key', 5, 60);

        $limiter->clear();

        // Mockery verifies del() was called
    }

    public function testBlockExecutesCallbackOnSuccess(): void
    {
        $redis = $this->mockRedis();
        $redis->shouldReceive('eval')
            ->once()
            ->andReturn([1, time() + 60, 4]);

        $limiter = new DurationLimiter($redis, 'test-key', 5, 60);

        $callbackExecuted = false;
        $result = $limiter->block(5, function () use (&$callbackExecuted) {
            $callbackExecuted = true;
            return 'callback-result';
        });

        $this->assertTrue($callbackExecuted);
        $this->assertSame('callback-result', $result);
    }

    public function testBlockThrowsExceptionAfterTimeout(): void
    {
        $redis = $this->mockRedis();
        // Always fail to acquire
        $redis->shouldReceive('eval')
            ->andReturn([0, time() + 60, 0]);

        $limiter = new DurationLimiter($redis, 'test-key', 5, 60);

        $this->expectException(LimiterTimeoutException::class);

        // Timeout of 0 means it should fail immediately on first retry
        $limiter->block(0, null, 1); // 1ms sleep between retries
    }

    // REMOVED: testUsesSpecifiedConnectionName - Connection is now resolved before creating the limiter,
    // so DurationLimiter no longer has a connection name parameter.

    /**
     * Create a mock RedisProxy.
     */
    private function mockRedis(): m\MockInterface|RedisProxy
    {
        return m::mock(RedisProxy::class);
    }
}
