<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Contracts\Limiters\LimiterTimeoutException;
use Hypervel\Redis\Limiters\ConcurrencyLease;
use Hypervel\Redis\Limiters\ConcurrencyLimiterBuilder;
use Hypervel\Redis\RedisProxy;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

/**
 * Tests for ConcurrencyLimiterBuilder.
 *
 * ConcurrencyLimiterBuilder provides a fluent API for configuring and executing
 * a ConcurrencyLimiter via Redis::funnel('key')->limit(10)->then(...).
 */
class ConcurrencyLimiterBuilderTest extends TestCase
{
    public function testLimitSetsMaxLocks(): void
    {
        $builder = $this->createBuilder();

        $result = $builder->limit(10);

        $this->assertSame($builder, $result);
        $this->assertSame(10, $builder->maxLocks);
    }

    public function testReleaseAfterSetsReleaseAfterInSeconds(): void
    {
        $builder = $this->createBuilder();

        $result = $builder->releaseAfter(120);

        $this->assertSame($builder, $result);
        $this->assertSame(120, $builder->releaseAfter);
    }

    public function testBlockSetsTimeout(): void
    {
        $builder = $this->createBuilder();

        $result = $builder->block(10);

        $this->assertSame($builder, $result);
        $this->assertSame(10, $builder->timeout);
    }

    public function testSleepSetsSleepDuration(): void
    {
        $builder = $this->createBuilder();

        $result = $builder->sleep(500);

        $this->assertSame($builder, $result);
        $this->assertSame(500, $builder->sleep);
    }

    public function testDefaultReleaseAfterIs60Seconds(): void
    {
        $builder = $this->createBuilder();

        $this->assertSame(60, $builder->releaseAfter);
    }

    public function testDefaultTimeoutIsThreeSeconds(): void
    {
        $builder = $this->createBuilder();

        $this->assertSame(3, $builder->timeout);
    }

    public function testDefaultSleepIs250Milliseconds(): void
    {
        $builder = $this->createBuilder();

        $this->assertSame(250, $builder->sleep);
    }

    public function testThenExecutesCallbackWhenLockAcquired(): void
    {
        $redis = $this->mockRedis();
        // ConcurrencyLimiter::acquire() Lua script returns a slot name
        $redis->shouldReceive('eval')
            ->once()
            ->andReturn('test-key1');

        // ConcurrencyLimiter::release() called after callback
        $redis->shouldReceive('eval')
            ->once()
            ->withArgs(function (string $script, int $numKeys, string $key, string $id): bool {
                $this->assertSame(1, $numKeys);
                $this->assertSame('test-key1', $key);

                return true;
            })
            ->andReturn(1);

        $builder = new ConcurrencyLimiterBuilder($redis, 'test-key');
        $builder->limit(5)->block(0);

        $result = $builder->then(function () {
            return 'success';
        });

        $this->assertSame('success', $result);
    }

    public function testThenPropagatesReleaseFailureAfterSuccessfulCallback(): void
    {
        $redis = $this->mockRedis();
        $releaseException = new RuntimeException('release failed');

        $redis->shouldReceive('eval')
            ->once()
            ->andReturn('test-key1');
        $redis->shouldReceive('eval')
            ->once()
            ->andThrow($releaseException);

        $builder = new ConcurrencyLimiterBuilder($redis, 'test-key');
        $builder->limit(5)->block(0);

        try {
            $builder->then(fn (): string => 'success');

            $this->fail('Expected the release failure to be thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($releaseException, $exception);
        }
    }

    public function testThenPreservesCallbackFailureWhenReleaseAlsoFails(): void
    {
        $redis = $this->mockRedis();
        $callbackException = new RuntimeException('callback failed');

        $redis->shouldReceive('eval')
            ->once()
            ->andReturn('test-key1');
        $redis->shouldReceive('eval')
            ->once()
            ->andThrow(new RuntimeException('release failed'));

        $builder = new ConcurrencyLimiterBuilder($redis, 'test-key');
        $builder->limit(5)->block(0);

        try {
            $builder->then(function () use ($callbackException): never {
                throw $callbackException;
            });

            $this->fail('Expected the callback failure to be thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($callbackException, $exception);
        }
    }

    public function testThenCallsFailureCallbackOnTimeout(): void
    {
        $redis = $this->mockRedis();
        // ConcurrencyLimiter::acquire() always fails
        $redis->shouldReceive('eval')
            ->andReturn(false);

        $builder = new ConcurrencyLimiterBuilder($redis, 'test-key');
        $builder->limit(5)->block(0)->sleep(1);

        $failureCalled = false;
        $result = $builder->then(
            function () {
                return 'should-not-reach';
            },
            function (LimiterTimeoutException $e) use (&$failureCalled) {
                $failureCalled = true;
                return 'fallback';
            }
        );

        $this->assertTrue($failureCalled);
        $this->assertSame('fallback', $result);
    }

    public function testThenThrowsExceptionWithoutFailureCallback(): void
    {
        $redis = $this->mockRedis();
        // ConcurrencyLimiter::acquire() always fails
        $redis->shouldReceive('eval')
            ->andReturn(false);

        $builder = new ConcurrencyLimiterBuilder($redis, 'test-key');
        $builder->limit(5)->block(0)->sleep(1);

        $this->expectException(LimiterTimeoutException::class);

        $builder->then(function () {
            return 'should-not-reach';
        });
    }

    public function testAcquireReturnsLease(): void
    {
        $redis = $this->mockRedis();
        $redis->shouldReceive('eval')
            ->once()
            ->andReturn('test-key1');

        $builder = new ConcurrencyLimiterBuilder($redis, 'test-key');
        $builder->limit(5)->block(0);

        $this->assertInstanceOf(ConcurrencyLease::class, $builder->acquire());
    }

    public function testThenDoesNotRouteCallbackTimeoutExceptionToFailureCallback(): void
    {
        $redis = $this->mockRedis();
        $redis->shouldReceive('eval')
            ->once()
            ->andReturn('test-key1');
        $redis->shouldReceive('eval')
            ->once()
            ->andReturn(1);

        $builder = new ConcurrencyLimiterBuilder($redis, 'test-key');
        $builder->limit(5)->block(0);

        $failureCalled = false;
        $callbackException = new LimiterTimeoutException;

        try {
            $builder->then(
                function () use ($callbackException): never {
                    throw $callbackException;
                },
                function () use (&$failureCalled) {
                    $failureCalled = true;
                }
            );

            $this->fail('Expected LimiterTimeoutException was not thrown.');
        } catch (LimiterTimeoutException $exception) {
            $this->assertSame($callbackException, $exception);
        }

        $this->assertFalse($failureCalled);
    }

    public function testFluentChaining(): void
    {
        $builder = $this->createBuilder();

        $result = $builder->limit(10)->releaseAfter(120)->block(5)->sleep(500);

        $this->assertSame($builder, $result);
        $this->assertSame(10, $builder->maxLocks);
        $this->assertSame(120, $builder->releaseAfter);
        $this->assertSame(5, $builder->timeout);
        $this->assertSame(500, $builder->sleep);
    }

    /**
     * Create a builder with a mock Redis connection.
     */
    private function createBuilder(): ConcurrencyLimiterBuilder
    {
        return new ConcurrencyLimiterBuilder($this->mockRedis(), 'test-key');
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
}
