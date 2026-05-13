<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Redis;

use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Redis\Pool\PoolFactory;
use Hypervel\Redis\Pool\RedisPool;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;
use ReflectionClass;

/**
 * Verifies that InteractsWithRedis::tearDownInteractsWithRedis() flushes the
 * Redis connection pool before $this->app->flush() runs.
 *
 * Captures the PoolFactory and a live pool during the test body, then asserts
 * post-teardown state in a custom tearDown() that runs AFTER parent::tearDown().
 * Without the trait-level pool flush, the captured pool would still hold its
 * socket and the factory would still cache the pool - the FD leak path that
 * trips long ParaTest runs.
 */
class RedisPoolTeardownLifecycleTest extends TestCase
{
    use InteractsWithRedis;

    private static ?PoolFactory $capturedFactory = null;

    private static ?RedisPool $capturedPool = null;

    public function testTearDownLifecyclePurgesRedisPools(): void
    {
        // Run a real command so the manager caches a proxy AND the factory
        // creates a live pool with a phpredis connection in its channel.
        Redis::ping();

        $factory = $this->app->make(PoolFactory::class);
        $pool = $factory->getPool('default');

        // Sanity: the pool actually has a real connection
        $this->assertGreaterThan(0, $pool->getCurrentConnections());

        self::$capturedFactory = $factory;
        self::$capturedPool = $pool;
    }

    protected function tearDown(): void
    {
        // parent::tearDown() runs tearDownTheTestEnvironment, where the
        // pool-purge lifecycle hook lives. After it returns, the captured
        // references should reflect a fully torn-down pool layer.
        parent::tearDown();

        if (self::$capturedFactory === null || self::$capturedPool === null) {
            return;
        }

        try {
            // The pool's channel should be drained
            $this->assertSame(
                0,
                self::$capturedPool->getConnectionsInChannel(),
                'Pool channel should be empty after lifecycle teardown'
            );
            $this->assertSame(
                0,
                self::$capturedPool->getCurrentConnections(),
                'Pool currentConnections should be 0 after lifecycle teardown'
            );

            // The factory's $pools cache should be cleared so the previous
            // pool object can be refcount-collected (no public accessor for
            // this - reflection is the only way to verify it directly).
            $reflection = new ReflectionClass(self::$capturedFactory);
            $poolsProperty = $reflection->getProperty('pools');
            $this->assertSame(
                [],
                $poolsProperty->getValue(self::$capturedFactory),
                'PoolFactory $pools should be empty after lifecycle teardown'
            );
        } finally {
            self::$capturedFactory = null;
            self::$capturedPool = null;
        }
    }
}
