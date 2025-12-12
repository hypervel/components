<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Integration;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;

/**
 * Base test case for Redis integration tests.
 *
 * These tests require a real Redis server and are skipped by default.
 * Set RUN_REDIS_INTEGRATION_TESTS=true in .env to enable them.
 *
 * @internal
 * @coversNothing
 */
abstract class RedisIntegrationTestCase extends TestCase
{
    use RunTestsInCoroutine;

    /**
     * Redis database number used for integration tests.
     * Using DB 15 to avoid conflicts with other data.
     */
    protected int $redisDatabase = 15;

    /**
     * Cache key prefix for integration tests.
     */
    protected string $cachePrefix = 'integration_test:';

    protected function setUp(): void
    {
        if (! env('RUN_REDIS_INTEGRATION_TESTS', false)) {
            $this->markTestSkipped(
                'Redis integration tests are disabled. Set RUN_REDIS_INTEGRATION_TESTS=true in .env to enable.'
            );
        }

        parent::setUp();

        $this->configureRedis();
        $this->configureCache();
    }

    protected function tearDown(): void
    {
        // Flush the test database to clean up after tests
        if (env('RUN_REDIS_INTEGRATION_TESTS', false)) {
            $this->flushTestDatabase();
        }

        parent::tearDown();
    }

    /**
     * Configure Redis connection settings from environment variables.
     */
    protected function configureRedis(): void
    {
        $config = $this->app->get(ConfigInterface::class);

        $config->set('database.redis.default', [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'auth' => env('REDIS_AUTH', null) ?: null,
            'port' => (int) env('REDIS_PORT', 6379),
            'db' => $this->redisDatabase,
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 10,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat' => -1,
                'max_idle_time' => 60.0,
            ],
        ]);

        $config->set('database.redis.options.prefix', $this->cachePrefix);
    }

    /**
     * Configure cache to use Redis as the default driver.
     */
    protected function configureCache(): void
    {
        $config = $this->app->get(ConfigInterface::class);

        $config->set('cache.default', 'redis');
        $config->set('cache.prefix', $this->cachePrefix);
    }

    /**
     * Flush all keys in the test Redis database.
     */
    protected function flushTestDatabase(): void
    {
        try {
            Redis::flushdb();
        } catch (\Throwable) {
            // Ignore errors during cleanup
        }
    }
}
