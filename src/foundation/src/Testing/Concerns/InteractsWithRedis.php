<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Hypervel\Container\Container;
use Hypervel\Foundation\Testing\RedisTestConfiguration;
use Hypervel\Redis\Pool\PoolFactory;
use Hypervel\Redis\RedisConfig;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testing\ParallelTesting;
use RuntimeException;
use Throwable;

/**
 * Provides Redis integration testing support.
 *
 * Auto-called by TestCase via setUpTraits():
 * - setUpInteractsWithRedis() runs after app boots
 * - tearDownInteractsWithRedis() runs via beforeApplicationDestroyed()
 *
 * Tests that need Redis config overrides (prefix, DB number) should set
 * them in defineEnvironment() via $app->make('config')->set(...).
 *
 * Standalone ParaTest workers use separate logical databases for isolation.
 * Redis Cluster only supports database zero, so Cluster suites run serially.
 *
 * Tests that need to call select() to switch databases should set
 * REDIS_TEST_SECONDARY_DB and use getSecondaryRedisDb(). The secondary DB is
 * shared by tests that explicitly request it; never call flushdb() on it.
 * Use unique keys and clean them up with del().
 *
 * Environment Variables:
 * - REDIS_HOST: Standalone Redis host
 * - REDIS_CLUSTER_HOSTS_AND_PORTS: Comma-separated Redis Cluster seeds
 * - REDIS_PORT: Redis port (default: 6379)
 * - REDIS_DB: Base Redis database number (default: 0)
 * - REDIS_TEST_DB_MIN: First Redis database available for parallel workers (default: REDIS_DB)
 * - REDIS_TEST_DB_MAX: Last Redis database available for parallel workers (default: 15)
 * - REDIS_TEST_SECONDARY_DB: Shared secondary database for select() tests (optional)
 * - REDIS_PASSWORD: Redis password (optional)
 */
trait InteractsWithRedis
{
    /**
     * Set up Redis for testing (auto-called by setUpTraits).
     *
     * Redis integration tests are opt-in via REDIS_HOST or
     * REDIS_CLUSTER_HOSTS_AND_PORTS.
     *
     * When running under ParaTest, assigns a configured per-worker Redis DB
     * number to prevent cross-process interference.
     */
    protected function setUpInteractsWithRedis(): void
    {
        if (! $this->hasExplicitRedisConfig()) {
            $this->markTestSkipped(
                'Set REDIS_HOST or REDIS_CLUSTER_HOSTS_AND_PORTS to run Redis integration tests for ' . static::class
            );
        }

        RedisTestConfiguration::configure(
            $this->app->make('config'),
            $this->parallelTestingToken(),
        );

        $this->flushRedis();
    }

    /**
     * Tear down Redis (auto-called via beforeApplicationDestroyed).
     */
    protected function tearDownInteractsWithRedis(): void
    {
        try {
            $this->flushRedis();
        } catch (Throwable) {
            // Ignore cleanup errors
        }

        // Flush the Redis connection pool so phpredis sockets are closed
        // before $this->app->flush() drops the pool factory. Without this,
        // the Pool/Connection reference cycle keeps sockets open until PHP's
        // cycle collector eventually fires, which trips the FD limit under
        // long ParaTest runs.
        if ($this->app->resolved(PoolFactory::class)) {
            $this->app->make(PoolFactory::class)->flushAll();
        }
    }

    /**
     * Flush the Redis database.
     */
    protected function flushRedis(): void
    {
        Redis::flushdb();
    }

    /**
     * Determine if Redis integration testing was explicitly configured.
     */
    protected function hasExplicitRedisConfig(): bool
    {
        return RedisTestConfiguration::isConfigured();
    }

    /**
     * Determine if Redis integration testing uses a Cluster.
     */
    protected function usingRedisCluster(): bool
    {
        return RedisTestConfiguration::usesCluster();
    }

    /**
     * Get the primary Redis DB number for the current test worker.
     */
    protected function getParallelRedisDb(): int
    {
        return RedisTestConfiguration::primaryDatabase($this->parallelTestingToken());
    }

    /**
     * Get the secondary Redis DB for standalone tests that need to call select().
     *
     * Must always return a DB number different from getParallelRedisDb().
     *
     * This DB is shared across all parallel workers. Never call flushdb() on
     * it — use unique keys and clean up via del() instead.
     */
    protected function getSecondaryRedisDb(): int
    {
        return RedisTestConfiguration::secondaryDatabase($this->parallelTestingToken());
    }

    /**
     * Get the current parallel testing token.
     */
    protected function parallelTestingToken(): string|false
    {
        // Testbench defineEnvironment() hooks can ask for Redis DBs before
        // refreshApplication() assigns the created application to the test case.
        return ($this->app ?? Container::getInstance())->make(ParallelTesting::class)->token();
    }

    /**
     * Get the configured Redis connection for direct assertions.
     */
    protected function redisClient(string $connectionName = 'default'): RedisProxy
    {
        return Redis::connection($connectionName);
    }

    /**
     * Get a topology-aware Redis connection without a key prefix.
     */
    protected function redisClientWithoutPrefix(): RedisProxy
    {
        $connectionName = $this->createRedisConnectionWithOptions('test_no_prefix', [
            'prefix' => '',
        ]);

        return Redis::connection($connectionName);
    }

    /**
     * Get a raw phpredis client WITHOUT any OPT_PREFIX.
     *
     * Useful for verifying actual key names in Redis. Uses the per-worker
     * DB number for parallel safety.
     */
    protected function rawRedisClientWithoutPrefix(string $connectionName = 'default'): \Redis
    {
        if ($this->usingRedisCluster()) {
            throw new RuntimeException('Raw standalone Redis clients are unavailable during Redis Cluster integration tests.');
        }

        $connectionConfig = $this->app->make(RedisConfig::class)->connectionConfig($connectionName);
        $client = new \Redis;
        $client->connect(
            (string) $connectionConfig['host'],
            (int) $connectionConfig['port']
        );

        $password = $connectionConfig['password'] ?? null;
        $username = $connectionConfig['username'] ?? null;

        if (is_string($password) && $password !== '') {
            $client->auth(
                is_string($username) && $username !== ''
                    ? [$username, $password]
                    : $password
            );
        }

        $client->select((int) ($connectionConfig['database'] ?? 0));

        return $client;
    }

    /**
     * Clean up keys matching multiple patterns using the trait's standard Redis test semantics.
     *
     * If Redis was not explicitly enabled, cleanup is skipped just like
     * setUpInteractsWithRedis(). When a topology is configured, connection
     * failures still propagate as real test environment errors.
     */
    protected function cleanupRedisKeysWithPatterns(string ...$patterns): void
    {
        if (! $this->hasExplicitRedisConfig()) {
            return;
        }

        $connection = $this->redisClientWithoutPrefix();

        foreach ($patterns as $pattern) {
            $keys = $connection->keys($pattern);
            if (! empty($keys)) {
                $connection->del(...$keys);
            }
        }
    }

    /**
     * Create a named Redis connection with a specific OPT_PREFIX for testing.
     *
     * Use this when a test needs multiple connections with different prefixes.
     * For a single no-prefix connection, just set the prefix on the default
     * connection in defineEnvironment() instead.
     */
    protected function createRedisConnectionWithPrefix(string $optPrefix): string
    {
        $connectionName = 'test_opt_' . ($optPrefix === '' ? 'none' : hash('xxh128', $optPrefix));

        return $this->createRedisConnectionWithOptions($connectionName, [
            'prefix' => $optPrefix,
        ]);
    }

    /**
     * Create a Redis connection with custom options for integration assertions.
     *
     * @param array<string, mixed> $options
     */
    protected function createRedisConnectionWithOptions(string $name, array $options, int $maxConnections = 10): string
    {
        $config = $this->app->make('config');

        if ($config->get("database.redis.{$name}") !== null) {
            return $name;
        }

        $connection = $config->array('database.redis.default');
        $pool = $config->array('database.redis.default.pool');

        $connection['options'] = $options;
        $connection['pool'] = array_replace($pool, [
            'max_connections' => $maxConnections,
        ]);

        $config->set("database.redis.{$name}", $connection);

        return $name;
    }
}
