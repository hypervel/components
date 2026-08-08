<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Contracts\Limiters\LimiterTimeoutException;
use Hypervel\Redis\Limiters\DurationLimiter;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * Tests for DurationLimiter.
 *
 * DurationLimiter provides a fixed-window rate limiter using Redis Lua scripts.
 */
class DurationLimiterTest extends TestCase
{
    public function testAcquireSucceedsWhenBelowLimit(): void
    {
        $redis = $this->mockRedis();
        // Lua script returns: [acquired (1=success), decaysAt, remaining]
        $this->expectEvaluation($redis, [1, time() + 60, 4]);

        $limiter = new DurationLimiter($redis, 'test-key', 5, 60);

        $result = $limiter->acquire();

        $this->assertTrue($result);
        $this->assertSame(4, $limiter->remaining);
    }

    public function testAcquireUsesShaCachedEvalSignature(): void
    {
        $redis = $this->mockRedis();
        $this->expectEvaluation(
            $redis,
            [1, time() + 60, 4],
            function (string $script, array $keys, array $arguments): bool {
                $this->assertNotSame('', $script);
                $this->assertSame(['test-key'], $keys);
                $this->assertGreaterThan(0.0, $arguments[0]);
                $this->assertGreaterThan(0, $arguments[1]);
                $this->assertSame(60, $arguments[2]);
                $this->assertSame(5, $arguments[3]);

                return true;
            },
        );

        $limiter = new DurationLimiter($redis, 'test-key', 5, 60);

        $this->assertTrue($limiter->acquire());
    }

    public function testAcquireFailsWhenAtLimit(): void
    {
        $redis = $this->mockRedis();
        // Lua script returns: [acquired (0=failed), decaysAt, remaining]
        $this->expectEvaluation($redis, [0, time() + 30, 0]);

        $limiter = new DurationLimiter($redis, 'test-key', 5, 60);

        $result = $limiter->acquire();

        $this->assertFalse($result);
        $this->assertSame(0, $limiter->remaining);
    }

    public function testRemainingIsNeverNegative(): void
    {
        $redis = $this->mockRedis();
        // Even if script returns negative, remaining should be 0
        $this->expectEvaluation($redis, [0, time() + 60, -2]);

        $limiter = new DurationLimiter($redis, 'test-key', 5, 60);

        $limiter->acquire();

        $this->assertSame(0, $limiter->remaining);
    }

    public function testTooManyAttemptsReturnsTrueWhenNoRemaining(): void
    {
        $redis = $this->mockRedis();
        $this->expectEvaluation($redis, [time() + 60, 0]);

        $limiter = new DurationLimiter($redis, 'test-key', 5, 60);

        $result = $limiter->tooManyAttempts();

        $this->assertTrue($result);
        $this->assertSame(0, $limiter->remaining);
    }

    public function testTooManyAttemptsReturnsFalseWhenHasRemaining(): void
    {
        $redis = $this->mockRedis();
        $this->expectEvaluation($redis, [time() + 60, 3]);

        $limiter = new DurationLimiter($redis, 'test-key', 5, 60);

        $result = $limiter->tooManyAttempts();

        $this->assertFalse($result);
        $this->assertSame(3, $limiter->remaining);
    }

    public function testTooManyAttemptsUsesShaCachedEvalSignature(): void
    {
        $redis = $this->mockRedis();
        $this->expectEvaluation(
            $redis,
            [time() + 60, 2],
            function (string $script, array $keys, array $arguments): bool {
                $this->assertNotSame('', $script);
                $this->assertSame(['test-key'], $keys);
                $this->assertGreaterThan(0.0, $arguments[0]);
                $this->assertGreaterThan(0, $arguments[1]);
                $this->assertSame(60, $arguments[2]);
                $this->assertSame(5, $arguments[3]);

                return true;
            },
        );

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
        $this->expectEvaluation($redis, [1, time() + 60, 4]);

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
        $connection = m::mock(RedisConnection::class);

        $redis->shouldReceive('withConnection')
            ->andReturnUsing(fn (callable $callback): mixed => $callback($connection));

        // Always fail to acquire
        $connection->shouldReceive('evalWithShaCache')
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

    /**
     * Expect a script evaluation on one held Redis connection.
     */
    private function expectEvaluation(
        m\MockInterface|RedisProxy $redis,
        mixed $result,
        ?callable $assertion = null,
    ): void {
        $connection = m::mock(RedisConnection::class);

        $redis->shouldReceive('withConnection')
            ->once()
            ->andReturnUsing(fn (callable $callback): mixed => $callback($connection));

        $expectation = $connection->shouldReceive('evalWithShaCache')->once();

        if ($assertion !== null) {
            $expectation->withArgs($assertion);
        }

        $expectation->andReturn($result);
    }
}
