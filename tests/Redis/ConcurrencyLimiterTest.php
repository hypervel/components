<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Contracts\Redis\LimiterTimeoutException;
use Hypervel\Redis\Limiters\ConcurrencyLimiter;
use Hypervel\Redis\RedisProxy;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

/**
 * Tests for ConcurrencyLimiter.
 *
 * ConcurrencyLimiter provides a slot-based concurrency limiter using Redis Lua scripts.
 * It acquires one of N named slots, holds it during callback execution, and releases it afterward.
 *
 * @internal
 * @coversNothing
 */
class ConcurrencyLimiterTest extends TestCase
{
    public function testBlockExecutesCallbackOnSuccessfulAcquisition()
    {
        $redis = $this->mockRedis();

        // acquire() calls eval with the lock script — return a slot name to indicate success
        $redis->shouldReceive('eval')
            ->once()
            ->andReturn('test-lock1');

        // release() calls eval with the release script
        $redis->shouldReceive('eval')
            ->once()
            ->withArgs(function (string $script, int $numKeys, string $key, string $id): bool {
                $this->assertSame(1, $numKeys);
                $this->assertSame('test-lock1', $key);
                $this->assertNotEmpty($id);

                return true;
            })
            ->andReturn(1);

        $limiter = new ConcurrencyLimiter($redis, 'test-lock', 3, 60);

        $result = $limiter->block(5, function () {
            return 'callback-result';
        });

        $this->assertSame('callback-result', $result);
    }

    public function testBlockReturnsTrueWithoutCallback()
    {
        $redis = $this->mockRedis();

        // acquire() succeeds
        $redis->shouldReceive('eval')
            ->once()
            ->andReturn('test-lock1');

        $limiter = new ConcurrencyLimiter($redis, 'test-lock', 3, 60);

        $result = $limiter->block(5);

        $this->assertTrue($result);
    }

    public function testBlockReleasesLockWhenCallbackThrows()
    {
        $redis = $this->mockRedis();

        // acquire() succeeds
        $redis->shouldReceive('eval')
            ->once()
            ->andReturn('test-lock1');

        // release() should still be called
        $redis->shouldReceive('eval')
            ->once()
            ->withArgs(function (string $script, int $numKeys, string $key, string $id): bool {
                $this->assertSame(1, $numKeys);
                $this->assertSame('test-lock1', $key);

                return true;
            })
            ->andReturn(1);

        $limiter = new ConcurrencyLimiter($redis, 'test-lock', 3, 60);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('test error');

        $limiter->block(5, function () {
            throw new RuntimeException('test error');
        });
    }

    public function testBlockThrowsTimeoutExceptionWhenCannotAcquire()
    {
        $redis = $this->mockRedis();

        // acquire() always fails (returns falsy)
        $redis->shouldReceive('eval')
            ->andReturn(false);

        $limiter = new ConcurrencyLimiter($redis, 'test-lock', 3, 60);

        $this->expectException(LimiterTimeoutException::class);

        // Timeout of 0 means it should fail immediately on first retry
        $limiter->block(0, null, 1); // 1ms sleep between retries
    }

    public function testAcquirePassesCorrectKeysToLuaScript()
    {
        $redis = $this->mockRedis();

        $redis->shouldReceive('eval')
            ->once()
            ->withArgs(function (string $script, int $numKeys, ...$args): bool {
                // With maxLocks=3, we should have 3 keys
                $this->assertSame(3, $numKeys);

                // First 3 args are the slot keys
                $this->assertSame('test-lock1', $args[0]);
                $this->assertSame('test-lock2', $args[1]);
                $this->assertSame('test-lock3', $args[2]);

                // Then ARGV: name, releaseAfter, id
                $this->assertSame('test-lock', $args[3]);
                $this->assertSame(60, $args[4]);
                $this->assertNotEmpty($args[5]); // random id

                return true;
            })
            ->andReturn('test-lock1');

        $limiter = new ConcurrencyLimiter($redis, 'test-lock', 3, 60);

        $limiter->block(5);
    }

    /**
     * Create a mock RedisProxy.
     */
    private function mockRedis(): m\MockInterface|RedisProxy
    {
        return m::mock(RedisProxy::class);
    }
}
