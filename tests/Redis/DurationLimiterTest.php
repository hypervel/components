<?php

declare(strict_types=1);

namespace Hypervel\Redis\Tests;

use Hyperf\Redis\RedisFactory;
use Hyperf\Redis\RedisProxy;
use Hypervel\Redis\Limiters\DurationLimiter;
use Hypervel\Redis\Limiters\LimiterTimeoutException;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * Tests for DurationLimiter.
 *
 * DurationLimiter provides a sliding window rate limiter using Redis Lua scripts.
 *
 * @internal
 * @coversNothing
 */
class DurationLimiterTest extends TestCase
{
    /**
     * @test
     */
    public function testAcquireSucceedsWhenBelowLimit(): void
    {
        $redis = $this->mockRedis();
        // Lua script returns: [acquired (1=success), decaysAt, remaining]
        $redis->shouldReceive('eval')
            ->once()
            ->andReturn([1, time() + 60, 4]);

        $factory = $this->createFactory($redis);
        $limiter = new DurationLimiter($factory, 'default', 'test-key', 5, 60);

        $result = $limiter->acquire();

        $this->assertTrue($result);
        $this->assertSame(4, $limiter->remaining);
    }

    /**
     * @test
     */
    public function testAcquireFailsWhenAtLimit(): void
    {
        $redis = $this->mockRedis();
        // Lua script returns: [acquired (0=failed), decaysAt, remaining]
        $redis->shouldReceive('eval')
            ->once()
            ->andReturn([0, time() + 30, 0]);

        $factory = $this->createFactory($redis);
        $limiter = new DurationLimiter($factory, 'default', 'test-key', 5, 60);

        $result = $limiter->acquire();

        $this->assertFalse($result);
        $this->assertSame(0, $limiter->remaining);
    }

    /**
     * @test
     */
    public function testRemainingIsNeverNegative(): void
    {
        $redis = $this->mockRedis();
        // Even if script returns negative, remaining should be 0
        $redis->shouldReceive('eval')
            ->once()
            ->andReturn([0, time() + 60, -2]);

        $factory = $this->createFactory($redis);
        $limiter = new DurationLimiter($factory, 'default', 'test-key', 5, 60);

        $limiter->acquire();

        $this->assertSame(0, $limiter->remaining);
    }

    /**
     * @test
     */
    public function testTooManyAttemptsReturnsTrueWhenNoRemaining(): void
    {
        $redis = $this->mockRedis();
        $redis->shouldReceive('eval')
            ->once()
            ->andReturn([time() + 60, 0]);

        $factory = $this->createFactory($redis);
        $limiter = new DurationLimiter($factory, 'default', 'test-key', 5, 60);

        $result = $limiter->tooManyAttempts();

        $this->assertTrue($result);
        $this->assertSame(0, $limiter->remaining);
    }

    /**
     * @test
     */
    public function testTooManyAttemptsReturnsFalseWhenHasRemaining(): void
    {
        $redis = $this->mockRedis();
        $redis->shouldReceive('eval')
            ->once()
            ->andReturn([time() + 60, 3]);

        $factory = $this->createFactory($redis);
        $limiter = new DurationLimiter($factory, 'default', 'test-key', 5, 60);

        $result = $limiter->tooManyAttempts();

        $this->assertFalse($result);
        $this->assertSame(3, $limiter->remaining);
    }

    /**
     * @test
     */
    public function testClearDeletesKey(): void
    {
        $redis = $this->mockRedis();
        $redis->shouldReceive('del')
            ->once()
            ->with('test-key')
            ->andReturn(1);

        $factory = $this->createFactory($redis);
        $limiter = new DurationLimiter($factory, 'default', 'test-key', 5, 60);

        $limiter->clear();

        // Mockery verifies del() was called
    }

    /**
     * @test
     */
    public function testBlockExecutesCallbackOnSuccess(): void
    {
        $redis = $this->mockRedis();
        $redis->shouldReceive('eval')
            ->once()
            ->andReturn([1, time() + 60, 4]);

        $factory = $this->createFactory($redis);
        $limiter = new DurationLimiter($factory, 'default', 'test-key', 5, 60);

        $callbackExecuted = false;
        $result = $limiter->block(5, function () use (&$callbackExecuted) {
            $callbackExecuted = true;
            return 'callback-result';
        });

        $this->assertTrue($callbackExecuted);
        $this->assertSame('callback-result', $result);
    }

    /**
     * @test
     */
    public function testBlockThrowsExceptionAfterTimeout(): void
    {
        $redis = $this->mockRedis();
        // Always fail to acquire
        $redis->shouldReceive('eval')
            ->andReturn([0, time() + 60, 0]);

        $factory = $this->createFactory($redis);
        $limiter = new DurationLimiter($factory, 'default', 'test-key', 5, 60);

        $this->expectException(LimiterTimeoutException::class);

        // Timeout of 0 means it should fail immediately on first retry
        $limiter->block(0, null, 1); // 1ms sleep between retries
    }

    /**
     * @test
     */
    public function testUsesSpecifiedConnectionName(): void
    {
        $cacheRedis = $this->mockRedis();
        $cacheRedis->shouldReceive('eval')
            ->once()
            ->andReturn([1, time() + 60, 4]);

        $factory = m::mock(RedisFactory::class);
        // Expect 'cache' connection, not 'default'
        $factory->shouldReceive('get')->with('cache')->andReturn($cacheRedis);

        $limiter = new DurationLimiter($factory, 'cache', 'test-key', 5, 60);

        $limiter->acquire();

        // Mockery verifies get('cache') was called
    }

    /**
     * Create a mock RedisProxy.
     */
    private function mockRedis(): m\MockInterface|RedisProxy
    {
        return m::mock(RedisProxy::class);
    }

    /**
     * Create a RedisFactory that returns the given RedisProxy.
     */
    private function createFactory(m\MockInterface|RedisProxy $redis): RedisFactory
    {
        $factory = m::mock(RedisFactory::class);
        $factory->shouldReceive('get')->with('default')->andReturn($redis);

        return $factory;
    }
}
