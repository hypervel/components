<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\Middleware;

use Hypervel\Contracts\Redis\Factory as Redis;
use Hypervel\Queue\Middleware\ThrottlesExceptionsWithRedis;
use Hypervel\Redis\RedisProxy;
use Hypervel\Testbench\TestCase;
use Mockery as m;

/**
 * Tests for ThrottlesExceptionsWithRedis middleware.
 *
 * Verifies the middleware resolves the Factory contract, calls ->connection()
 * with the configured connection name, and passes the resolved RedisProxy
 * to DurationLimiter.
 *
 * @internal
 * @coversNothing
 */
class ThrottlesExceptionsWithRedisTest extends TestCase
{
    public function testConnectionSetterIsFluent()
    {
        $middleware = new ThrottlesExceptionsWithRedis();

        $result = $middleware->connection('custom');

        $this->assertSame($middleware, $result);
        $this->assertInstanceOf(ThrottlesExceptionsWithRedis::class, $result);
    }

    public function testDefaultConnectionNameIsNull()
    {
        // Verify handle() resolves Factory and calls ->connection(null)
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('connection')
            ->once()
            ->with(null)
            ->andReturn($this->mockRedisProxy(tooMany: false));

        $this->instance(Redis::class, $redis);

        $middleware = new ThrottlesExceptionsWithRedis();

        $job = m::mock();
        $job->shouldReceive('getJobId')->andReturn('test-job-id');

        $middleware->handle($job, function () {
            // no-op — job succeeds
        });
    }

    public function testHandleResolvesFactoryContractAndCallsConnection()
    {
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('connection')
            ->once()
            ->with('cache')
            ->andReturn($this->mockRedisProxy(tooMany: false));

        $this->instance(Redis::class, $redis);

        $middleware = new ThrottlesExceptionsWithRedis();
        $middleware->connection('cache');

        $job = m::mock();
        $job->shouldReceive('getJobId')->andReturn('test-job-id');

        $middleware->handle($job, function () {
            // no-op — job succeeds
        });
    }

    public function testHandleReleasesJobWhenTooManyAttempts()
    {
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('connection')
            ->once()
            ->with(null)
            ->andReturn($this->mockRedisProxy(tooMany: true));

        $this->instance(Redis::class, $redis);

        $middleware = new ThrottlesExceptionsWithRedis();

        $job = m::mock();
        $job->shouldReceive('getJobId')->andReturn('test-job-id');
        $job->shouldReceive('release')->once();

        $nextCalled = false;
        $middleware->handle($job, function () use (&$nextCalled) {
            $nextCalled = true;
        });

        $this->assertFalse($nextCalled);
    }

    /**
     * Create a mock RedisProxy that handles DurationLimiter's eval() calls.
     */
    private function mockRedisProxy(bool $tooMany): m\MockInterface|RedisProxy
    {
        $proxy = m::mock(RedisProxy::class);

        if ($tooMany) {
            // tooManyAttempts() Lua script returns [decaysAt, remaining]
            $proxy->shouldReceive('eval')
                ->andReturn([time() + 60, 0]);
        } else {
            // tooManyAttempts() returns remaining > 0 (not too many)
            $proxy->shouldReceive('eval')
                ->andReturn([time() + 60, 5]);

            // clear() calls del()
            $proxy->shouldReceive('del')->andReturn(1);
        }

        return $proxy;
    }
}
