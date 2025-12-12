<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Redis\Integration;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;
use Throwable;

/**
 * Base test case for Redis integration tests.
 *
 * These tests require a real Redis server and are skipped by default.
 * Set RUN_REDIS_INTEGRATION_TESTS=true in .env to enable them.
 *
 * Parallel Test Safety (paratest):
 * - Uses TEST_TOKEN env var to create unique OPT_PREFIX per worker
 * - e.g., worker 1 gets prefix "int_test_1:", worker 2 gets "int_test_2:"
 * - flushByPattern('*') only flushes keys under that worker's prefix
 *
 * NOTE: Concrete test classes extending this MUST add @group redis-integration
 * for proper test filtering. PHPUnit doesn't inherit groups from abstract classes.
 *
 * @internal
 * @coversNothing
 */
abstract class RedisIntegrationTestCase extends TestCase
{
    use RunTestsInCoroutine;

    /**
     * Default Redis database number for integration tests.
     * Can be overridden via REDIS_DB env var.
     */
    protected int $redisDefaultDatabase = 8;

    /**
     * Base cache key prefix for integration tests.
     */
    protected string $redisBasePrefix = 'int_test';

    /**
     * Computed prefix (includes TEST_TOKEN if running in parallel).
     */
    protected string $cachePrefix;

    protected function setUp(): void
    {
        if (! env('RUN_REDIS_INTEGRATION_TESTS', false)) {
            $this->markTestSkipped(
                'Redis integration tests are disabled. Set RUN_REDIS_INTEGRATION_TESTS=true in .env to enable.'
            );
        }

        $this->computeParallelSafeConfig();

        parent::setUp();

        $this->configureRedis();
        $this->configureCache();
        $this->flushTestDatabase();
    }

    /**
     * Compute parallel-safe prefix based on TEST_TOKEN from paratest.
     *
     * Each worker gets a unique prefix (e.g., int_test_1:, int_test_2:).
     * This provides isolation without needing separate databases.
     */
    protected function computeParallelSafeConfig(): void
    {
        $testToken = env('TEST_TOKEN', '');

        if ($testToken !== '') {
            $this->cachePrefix = "{$this->redisBasePrefix}_{$testToken}:";
        } else {
            $this->cachePrefix = "{$this->redisBasePrefix}:";
        }
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
            'db' => (int) env('REDIS_DB', $this->redisDefaultDatabase),
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
     * Flush all keys matching the test prefix.
     *
     * Uses flushByPattern('*') which, combined with OPT_PREFIX, only deletes
     * keys belonging to this test. Safer than flushdb() for parallel tests.
     */
    protected function flushTestDatabase(): void
    {
        try {
            Redis::flushByPattern('*');
        } catch (Throwable) {
            // Ignore errors during cleanup
        }
    }
}
