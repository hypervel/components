<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Contracts\Limiters\LimiterTimeoutException;
use Hypervel\Redis\Limiters\DurationLimiterBuilder;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * Tests for DurationLimiterBuilder.
 *
 * DurationLimiterBuilder provides a fluent API for configuring and executing
 * a DurationLimiter via Redis::throttle('key')->allow(10)->every(60)->then(...).
 */
class DurationLimiterBuilderTest extends TestCase
{
    public function testAllowSetsMaxLocks(): void
    {
        $builder = $this->createBuilder();

        $result = $builder->allow(10);

        $this->assertSame($builder, $result);
        $this->assertSame(10, $builder->maxLocks);
    }

    public function testEverySetsDecayInSeconds(): void
    {
        $builder = $this->createBuilder();

        $result = $builder->every(60);

        $this->assertSame($builder, $result);
        $this->assertSame(60, $builder->decay);
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

    public function testDefaultTimeoutIsThreeSeconds(): void
    {
        $builder = $this->createBuilder();

        $this->assertSame(3, $builder->timeout);
    }

    public function testDefaultSleepIs750Milliseconds(): void
    {
        $builder = $this->createBuilder();

        $this->assertSame(750, $builder->sleep);
    }

    public function testThenExecutesCallbackWhenLockAcquired(): void
    {
        $redis = $this->mockRedis();
        // DurationLimiter::acquire() Lua script returns success
        $this->expectEvaluation($redis, [1, time() + 60, 4]);

        $builder = new DurationLimiterBuilder($redis, 'test-key');
        $builder->allow(5)->every(60)->block(0);

        $result = $builder->then(function () {
            return 'success';
        });

        $this->assertSame('success', $result);
    }

    public function testThenCallsFailureCallbackOnTimeout(): void
    {
        $redis = $this->mockRedis();
        // DurationLimiter::acquire() always fails
        $this->expectEvaluation($redis, [0, time() + 60, 0]);

        $builder = new DurationLimiterBuilder($redis, 'test-key');
        $builder->allow(5)->every(60)->block(0)->sleep(1);

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
        // DurationLimiter::acquire() always fails
        $this->expectEvaluation($redis, [0, time() + 60, 0]);

        $builder = new DurationLimiterBuilder($redis, 'test-key');
        $builder->allow(5)->every(60)->block(0)->sleep(1);

        $this->expectException(LimiterTimeoutException::class);

        $builder->then(function () {
            return 'should-not-reach';
        });
    }

    public function testFluentChaining(): void
    {
        $builder = $this->createBuilder();

        $result = $builder->allow(10)->every(60)->block(5)->sleep(500);

        $this->assertSame($builder, $result);
        $this->assertSame(10, $builder->maxLocks);
        $this->assertSame(60, $builder->decay);
        $this->assertSame(5, $builder->timeout);
        $this->assertSame(500, $builder->sleep);
    }

    /**
     * Create a builder with a mock Redis connection.
     */
    private function createBuilder(): DurationLimiterBuilder
    {
        return new DurationLimiterBuilder($this->mockRedis(), 'test-key');
    }

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
    private function expectEvaluation(m\MockInterface|RedisProxy $redis, mixed $result): void
    {
        $connection = m::mock(RedisConnection::class);

        $redis->shouldReceive('withConnection')
            ->once()
            ->andReturnUsing(fn (callable $callback): mixed => $callback($connection));

        $connection->shouldReceive('evalWithShaCache')
            ->once()
            ->andReturn($result);
    }
}
