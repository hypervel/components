<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Contracts\Limiters\LimiterTimeoutException;
use Hypervel\Redis\Limiters\ConcurrencyLease;
use Hypervel\Redis\Limiters\ConcurrencyLimiter;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

/**
 * Tests for ConcurrencyLimiter.
 *
 * ConcurrencyLimiter provides a slot-based concurrency limiter using Redis Lua scripts.
 * It acquires one of N named slots, holds it during callback execution, and releases it afterward.
 */
class ConcurrencyLimiterTest extends TestCase
{
    public function testBlockExecutesCallbackOnSuccessfulAcquisition(): void
    {
        $redis = $this->mockRedis();

        // acquire() returns a slot name to indicate success
        $this->expectSlotClaim($redis, 'test-lock1');

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

    public function testBlockReturnsTrueWithoutCallback(): void
    {
        $redis = $this->mockRedis();

        // acquire() succeeds
        $this->expectSlotClaim($redis, 'test-lock1');

        $limiter = new ConcurrencyLimiter($redis, 'test-lock', 3, 60);

        $result = $limiter->block(5);

        $this->assertTrue($result);
    }

    public function testBlockReleasesLockWhenCallbackThrows(): void
    {
        $redis = $this->mockRedis();

        // acquire() succeeds
        $this->expectSlotClaim($redis, 'test-lock1');

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

    public function testBlockPropagatesReleaseFailureAfterSuccessfulCallback(): void
    {
        $redis = $this->mockRedis();
        $releaseException = new RuntimeException('release failed');

        $this->expectSlotClaim($redis, 'test-lock1');
        $redis->shouldReceive('eval')
            ->once()
            ->andThrow($releaseException);

        $limiter = new ConcurrencyLimiter($redis, 'test-lock', 3, 60);

        try {
            $limiter->block(5, fn (): string => 'callback-result');

            $this->fail('Expected the release failure to be thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($releaseException, $exception);
        }
    }

    public function testBlockPreservesCallbackFailureWhenReleaseAlsoFails(): void
    {
        $redis = $this->mockRedis();
        $callbackException = new RuntimeException('callback failed');

        $this->expectSlotClaim($redis, 'test-lock1');
        $redis->shouldReceive('eval')
            ->once()
            ->andThrow(new RuntimeException('release failed'));

        $limiter = new ConcurrencyLimiter($redis, 'test-lock', 3, 60);

        try {
            $limiter->block(5, function () use ($callbackException): never {
                throw $callbackException;
            });

            $this->fail('Expected the callback failure to be thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($callbackException, $exception);
        }
    }

    public function testBlockThrowsTimeoutExceptionWhenCannotAcquire(): void
    {
        $redis = $this->mockRedis();
        $connection = m::mock(RedisConnection::class);

        // acquire() always fails (returns falsy)
        $redis->shouldReceive('withConnection')
            ->andReturnUsing(fn (callable $callback): mixed => $callback($connection));
        $connection->shouldReceive('evalWithShaCache')
            ->andReturn(false);

        $limiter = new ConcurrencyLimiter($redis, 'test-lock', 3, 60);

        $this->expectException(LimiterTimeoutException::class);

        // Timeout of 0 means it should fail immediately on first retry
        $limiter->block(0, null, 1); // 1ms sleep between retries
    }

    public function testAcquirePassesCorrectKeysToLuaScript(): void
    {
        $redis = $this->mockRedis();

        $this->expectSlotClaim(
            $redis,
            'test-lock1',
            function (string $script, array $keys, array $arguments): bool {
                $this->assertNotSame('', $script);
                $this->assertSame(['test-lock1', 'test-lock2', 'test-lock3'], $keys);
                $this->assertSame('test-lock', $arguments[0]);
                $this->assertSame(60, $arguments[1]);
                $this->assertNotEmpty($arguments[2]);

                return true;
            },
        );

        $limiter = new ConcurrencyLimiter($redis, 'test-lock', 3, 60);

        $limiter->block(5);
    }

    public function testBlockWithZeroLimitDoesNotCallEvalAndTimesOut(): void
    {
        $redis = $this->mockRedis();

        // limit(0) means no slots — acquire must short-circuit before evaluating Lua,
        // otherwise Lua hits redis.call('mget') with no args and errors.
        $redis->shouldNotReceive('withConnection');

        $this->expectException(LimiterTimeoutException::class);

        (new ConcurrencyLimiter($redis, 'zero', 0, 5))->block(0);
    }

    public function testBlockWithNegativeLimitDoesNotCallEvalAndTimesOut(): void
    {
        $redis = $this->mockRedis();

        $redis->shouldNotReceive('withConnection');

        $this->expectException(LimiterTimeoutException::class);

        (new ConcurrencyLimiter($redis, 'neg', -1, 5))->block(0);
    }

    public function testAcquireReturnsLease(): void
    {
        $redis = $this->mockRedis();

        $this->expectSlotClaim($redis, 'test-lock1');

        $limiter = new ConcurrencyLimiter($redis, 'test-lock', 3, 60);

        $lease = $limiter->acquire(5);

        $this->assertInstanceOf(ConcurrencyLease::class, $lease);
        $this->assertNotEmpty($lease->owner());
    }

    public function testLeaseCanReleaseSlot(): void
    {
        $redis = $this->mockRedis();

        $this->expectSlotClaim($redis, 'test-lock1');
        $redis->shouldReceive('eval')
            ->once()
            ->withArgs(function (string $script, int $numKeys, string $key, string $id): bool {
                $this->assertSame(1, $numKeys);
                $this->assertSame('test-lock1', $key);
                $this->assertNotEmpty($id);

                return true;
            })
            ->andReturn(1);

        $lease = (new ConcurrencyLimiter($redis, 'test-lock', 3, 60))->acquire(5);

        $this->assertTrue($lease->release());
    }

    public function testLeaseCanRefreshSlot(): void
    {
        $redis = $this->mockRedis();

        $this->expectSlotClaim($redis, 'test-lock1');
        $redis->shouldReceive('eval')
            ->once()
            ->withArgs(function (string $script, int $numKeys, string $key, string $id, int $seconds): bool {
                $this->assertSame(1, $numKeys);
                $this->assertSame('test-lock1', $key);
                $this->assertNotEmpty($id);
                $this->assertSame(60, $seconds);

                return true;
            })
            ->andReturn(1);

        $lease = (new ConcurrencyLimiter($redis, 'test-lock', 3, 60))->acquire(5);

        $this->assertTrue($lease->refresh());
    }

    public function testLeaseReturnsRemainingLifetime(): void
    {
        $redis = $this->mockRedis();

        $this->expectSlotClaim($redis, 'test-lock1');
        $redis->shouldReceive('ttl')
            ->once()
            ->with('test-lock1')
            ->andReturn(5);

        $lease = (new ConcurrencyLimiter($redis, 'test-lock', 3, 60))->acquire(5);

        $this->assertSame(5.0, $lease->getRemainingLifetime());
    }

    public function testClusterConnectionTagsSlotKeys(): void
    {
        $redis = $this->mockRedis();
        $redis->shouldReceive('isCluster')->andReturnTrue();

        $this->expectSlotClaim(
            $redis,
            '{test-lock}1',
            function (string $script, array $keys, array $arguments): bool {
                $this->assertSame(['{test-lock}1', '{test-lock}2', '{test-lock}3'], $keys);
                $this->assertSame('{test-lock}', $arguments[0]);

                return true;
            },
        );

        (new ConcurrencyLimiter($redis, 'test-lock', 3, 60))->block(5);
    }

    public function testClusterConnectionLeavesExistingHashTagAlone(): void
    {
        $redis = $this->mockRedis();
        $redis->shouldReceive('isCluster')->andReturnTrue();

        $this->expectSlotClaim(
            $redis,
            '{test-lock}:funnel1',
            function (string $script, array $keys, array $arguments): bool {
                $this->assertSame(['{test-lock}:funnel1'], $keys);
                $this->assertSame('{test-lock}:funnel', $arguments[0]);

                return true;
            },
        );

        (new ConcurrencyLimiter($redis, '{test-lock}:funnel', 1, 60))->block(5);
    }

    /**
     * Create a mock RedisProxy.
     */
    private function mockRedis(): m\MockInterface|RedisProxy
    {
        $redis = m::mock(RedisProxy::class);
        $redis->shouldReceive('isCluster')->byDefault()->andReturnFalse();

        return $redis;
    }

    /**
     * Expect a slot-claim script evaluation on one held Redis connection.
     */
    private function expectSlotClaim(
        m\MockInterface|RedisProxy $redis,
        false|string $result,
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
