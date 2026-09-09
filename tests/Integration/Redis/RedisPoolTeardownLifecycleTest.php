<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Redis;

use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Redis\Pool\PoolManager;
use Hypervel\Redis\Pool\RedisPool;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;

/**
 * Retains a live pool across application teardown to verify that cleanup
 * closes its sockets instead of leaving them for cycle collection.
 */
class RedisPoolTeardownLifecycleTest extends TestCase
{
    use InteractsWithRedis;

    private static ?PoolManager $capturedManager = null;

    private static ?RedisPool $capturedPool = null;

    public function testTearDownLifecyclePurgesRedisPools(): void
    {
        // Exercise the application path that creates the pool owned by teardown.
        Redis::ping();

        $poolManager = $this->app->make(PoolManager::class);
        $pool = $poolManager->pool('default');

        $this->assertGreaterThan(0, $pool->getManagedCount());

        self::$capturedManager = $poolManager;
        self::$capturedPool = $pool;
    }

    protected function tearDown(): void
    {
        // Assert after the framework's pool cleanup has run.
        parent::tearDown();

        if (self::$capturedManager === null || self::$capturedPool === null) {
            return;
        }

        try {
            $this->assertSame(
                0,
                self::$capturedPool->getIdleCount(),
                'Pool channel should be empty after lifecycle teardown'
            );
            $this->assertSame(
                0,
                self::$capturedPool->getManagedCount(),
                'Pool managed count should be 0 after lifecycle teardown'
            );

            $this->assertSame(
                [],
                self::$capturedManager->getPools(),
                'The pool registry should be empty after lifecycle teardown'
            );
        } finally {
            self::$capturedManager = null;
            self::$capturedPool = null;
        }
    }
}
