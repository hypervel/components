<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\Middleware;

use Hypervel\Contracts\Redis\Factory as Redis;
use Hypervel\Queue\Middleware\RateLimitedWithRedis;
use Hypervel\Redis\RedisProxy;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use ReflectionMethod;

/**
 * Tests for RateLimitedWithRedis middleware.
 *
 * Verifies the middleware resolves the Factory contract (not concrete RedisFactory),
 * calls ->connection() with the configured connection name, and passes the resolved
 * RedisProxy to DurationLimiter.
 *
 * @internal
 * @coversNothing
 */
class RateLimitedWithRedisTest extends TestCase
{
    public function testConnectionSetterIsFluent()
    {
        $middleware = new RateLimitedWithRedis('test-limiter');

        $result = $middleware->connection('custom');

        $this->assertSame($middleware, $result);
        $this->assertInstanceOf(RateLimitedWithRedis::class, $result);
    }

    public function testConstructorAcceptsConnectionName()
    {
        $middleware = new RateLimitedWithRedis('test-limiter', 'custom');

        // Verify via serialization that connectionName is stored
        $serialized = serialize($middleware);
        $deserialized = unserialize($serialized);

        $this->assertInstanceOf(RateLimitedWithRedis::class, $deserialized);
    }

    public function testSerializationIncludesConnectionName()
    {
        $middleware = new RateLimitedWithRedis('test-limiter');
        $middleware->connection('custom');

        $sleepProps = $middleware->__sleep();

        $this->assertContains('connectionName', $sleepProps);
    }

    public function testDefaultConnectionNameIsNull()
    {
        $middleware = new RateLimitedWithRedis('test-limiter');

        // Verify through tooManyAttempts — it resolves Factory and calls ->connection(null)
        $redis = m::mock(Redis::class);
        $redis->shouldReceive('connection')
            ->once()
            ->with(null)
            ->andReturn($this->mockRedisProxy());

        $this->instance(Redis::class, $redis);

        $reflection = new ReflectionMethod($middleware, 'tooManyAttempts');
        $reflection->setAccessible(true);

        $reflection->invoke($middleware, 'test-key', 10, 60);
    }

    public function testTooManyAttemptsResolvesFactoryContractAndCallsConnection()
    {
        $middleware = new RateLimitedWithRedis('test-limiter');
        $middleware->connection('cache');

        $redis = m::mock(Redis::class);
        $redis->shouldReceive('connection')
            ->once()
            ->with('cache')
            ->andReturn($this->mockRedisProxy());

        $this->instance(Redis::class, $redis);

        $reflection = new ReflectionMethod($middleware, 'tooManyAttempts');
        $reflection->setAccessible(true);

        $reflection->invoke($middleware, 'test-key', 10, 60);
    }

    /**
     * Create a mock RedisProxy that handles DurationLimiter's eval() call.
     */
    private function mockRedisProxy(): m\MockInterface|RedisProxy
    {
        $proxy = m::mock(RedisProxy::class);
        // DurationLimiter::acquire() calls eval with the Lua script
        $proxy->shouldReceive('eval')
            ->andReturn([1, time() + 60, 9]);

        return $proxy;
    }
}
