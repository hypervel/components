<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis;

use Hypervel\Contracts\Redis\LimiterTimeoutException;
use Hypervel\Redis\Limiters\DurationLimiterBuilder;
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
    public function testAllowSetsMaxLocks()
    {
        $builder = $this->createBuilder();

        $result = $builder->allow(10);

        $this->assertSame($builder, $result);
        $this->assertSame(10, $builder->maxLocks);
    }

    public function testEverySetsDecayInSeconds()
    {
        $builder = $this->createBuilder();

        $result = $builder->every(60);

        $this->assertSame($builder, $result);
        $this->assertSame(60, $builder->decay);
    }

    public function testBlockSetsTimeout()
    {
        $builder = $this->createBuilder();

        $result = $builder->block(10);

        $this->assertSame($builder, $result);
        $this->assertSame(10, $builder->timeout);
    }

    public function testSleepSetsSleepDuration()
    {
        $builder = $this->createBuilder();

        $result = $builder->sleep(500);

        $this->assertSame($builder, $result);
        $this->assertSame(500, $builder->sleep);
    }

    public function testDefaultTimeoutIsThreeSeconds()
    {
        $builder = $this->createBuilder();

        $this->assertSame(3, $builder->timeout);
    }

    public function testDefaultSleepIs750Milliseconds()
    {
        $builder = $this->createBuilder();

        $this->assertSame(750, $builder->sleep);
    }

    public function testThenExecutesCallbackWhenLockAcquired()
    {
        $redis = $this->mockRedis();
        // DurationLimiter::acquire() Lua script returns success
        $redis->shouldReceive('eval')
            ->once()
            ->andReturn([1, time() + 60, 4]);

        $builder = new DurationLimiterBuilder($redis, 'test-key');
        $builder->allow(5)->every(60)->block(0);

        $result = $builder->then(function () {
            return 'success';
        });

        $this->assertSame('success', $result);
    }

    public function testThenCallsFailureCallbackOnTimeout()
    {
        $redis = $this->mockRedis();
        // DurationLimiter::acquire() always fails
        $redis->shouldReceive('eval')
            ->andReturn([0, time() + 60, 0]);

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

    public function testThenThrowsExceptionWithoutFailureCallback()
    {
        $redis = $this->mockRedis();
        // DurationLimiter::acquire() always fails
        $redis->shouldReceive('eval')
            ->andReturn([0, time() + 60, 0]);

        $builder = new DurationLimiterBuilder($redis, 'test-key');
        $builder->allow(5)->every(60)->block(0)->sleep(1);

        $this->expectException(LimiterTimeoutException::class);

        $builder->then(function () {
            return 'should-not-reach';
        });
    }

    public function testFluentChaining()
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
}
