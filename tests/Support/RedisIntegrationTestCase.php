<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;
use Throwable;

/**
 * Base test case for Redis integration tests.
 *
 * Provides parallel-safe Redis testing infrastructure:
 * - Uses TEST_TOKEN env var (from paratest) to create unique prefixes per worker
 * - Configures Redis connection from environment variables
 * - Flushes only keys matching the test prefix (safe for parallel execution)
 *
 * Subclasses should override configurePackage() to add package-specific
 * configuration (e.g., setting cache.default, queue.default, etc.).
 *
 * NOTE: Concrete test classes extending this (or its subclasses) MUST add
 * @group redis-integration for proper test filtering in CI.
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
    protected int $redisDatabase = 8;

    /**
     * Base key prefix for integration tests.
     */
    protected string $basePrefix = 'int_test';

    /**
     * Computed prefix (includes TEST_TOKEN if running in parallel).
     */
    protected string $testPrefix;

    protected function setUp(): void
    {
        if (! env('RUN_REDIS_INTEGRATION_TESTS', false)) {
            $this->markTestSkipped(
                'Redis integration tests are disabled. Set RUN_REDIS_INTEGRATION_TESTS=true in .env to enable.'
            );
        }

        $this->computeTestPrefix();

        parent::setUp();

        $this->configureRedis();
        $this->configurePackage();
        $this->flushTestKeys();
    }

    protected function tearDown(): void
    {
        if (env('RUN_REDIS_INTEGRATION_TESTS', false)) {
            $this->flushTestKeys();
        }

        parent::tearDown();
    }

    /**
     * Compute parallel-safe prefix based on TEST_TOKEN from paratest.
     *
     * Each worker gets a unique prefix (e.g., int_test_1:, int_test_2:).
     * This provides isolation without needing separate databases.
     */
    protected function computeTestPrefix(): void
    {
        $testToken = env('TEST_TOKEN', '');

        if ($testToken !== '') {
            $this->testPrefix = "{$this->basePrefix}_{$testToken}:";
        } else {
            $this->testPrefix = "{$this->basePrefix}:";
        }
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
            'db' => (int) env('REDIS_DB', $this->redisDatabase),
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 10,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat' => -1,
                'max_idle_time' => 60.0,
            ],
        ]);

        $config->set('database.redis.options.prefix', $this->testPrefix);
    }

    /**
     * Configure package-specific settings.
     *
     * Override this method in subclasses to add package-specific configuration
     * (e.g., cache.default, cache.prefix for cache tests).
     */
    protected function configurePackage(): void
    {
        // Override in subclasses
    }

    /**
     * Flush all keys matching the test prefix.
     *
     * Uses flushByPattern('*') which, combined with OPT_PREFIX, only deletes
     * keys belonging to this test. Safer than flushdb() for parallel tests.
     */
    protected function flushTestKeys(): void
    {
        try {
            Redis::flushByPattern('*');
        } catch (Throwable) {
            // Ignore errors during cleanup
        }
    }

    // =========================================================================
    // CUSTOM CONNECTION HELPERS (for OPT_PREFIX testing)
    // =========================================================================

    /**
     * Track custom connections created during tests for cleanup.
     *
     * @var array<string>
     */
    private array $customConnections = [];

    /**
     * Create a Redis connection with a specific OPT_PREFIX.
     *
     * This allows testing different prefix configurations:
     * - Empty string for no OPT_PREFIX
     * - Custom string for specific OPT_PREFIX
     *
     * The connection is registered in config and can be used to create stores.
     *
     * @param string $optPrefix The OPT_PREFIX to set (empty string for none)
     * @return string The connection name to use with RedisStore
     */
    protected function createConnectionWithOptPrefix(string $optPrefix): string
    {
        $connectionName = 'test_opt_' . ($optPrefix === '' ? 'none' : md5($optPrefix));

        // Don't recreate if already exists
        if (in_array($connectionName, $this->customConnections, true)) {
            return $connectionName;
        }

        $config = $this->app->get(ConfigInterface::class);

        // Build connection config with correct test database
        // Note: We can't rely on redis.default because FoundationServiceProvider
        // copies database.redis.* to redis.* at boot (before test's setUp runs)
        $connectionConfig = [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'auth' => env('REDIS_AUTH', null) ?: null,
            'port' => (int) env('REDIS_PORT', 6379),
            'db' => (int) env('REDIS_DB', $this->redisDatabase),
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 10,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat' => -1,
                'max_idle_time' => 60.0,
            ],
            'options' => [
                'prefix' => $optPrefix,
            ],
        ];

        // Register the new connection directly to redis.* (runtime config location)
        $config->set("redis.{$connectionName}", $connectionConfig);

        $this->customConnections[] = $connectionName;

        return $connectionName;
    }

    /**
     * Get a raw phpredis client without any OPT_PREFIX.
     *
     * Useful for verifying actual key names in Redis.
     */
    protected function rawRedisClientWithoutPrefix(): \Redis
    {
        $client = new \Redis();
        $client->connect(
            env('REDIS_HOST', '127.0.0.1'),
            (int) env('REDIS_PORT', 6379)
        );

        $auth = env('REDIS_AUTH');
        if ($auth) {
            $client->auth($auth);
        }

        $client->select((int) env('REDIS_DB', $this->redisDatabase));

        return $client;
    }

    /**
     * Clean up keys matching a pattern using raw client.
     */
    protected function cleanupKeysWithPattern(string $pattern): void
    {
        $client = $this->rawRedisClientWithoutPrefix();
        $keys = $client->keys($pattern);
        if (! empty($keys)) {
            $client->del(...$keys);
        }
        $client->close();
    }
}
