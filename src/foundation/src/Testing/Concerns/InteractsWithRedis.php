<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Hypervel\Container\Container;
use Hypervel\Foundation\Testing\RedisTestDatabases;
use Hypervel\Redis\Pool\PoolFactory;
use Hypervel\Redis\RedisConfig;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testing\ParallelTesting;
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
 * Parallel Testing (ParaTest):
 * Each ParaTest worker gets its own Redis DB number to prevent cross-process
 * interference. Sequential runs use REDIS_DB directly. Parallel runs allocate
 * worker databases from REDIS_TEST_DB_MIN through REDIS_TEST_DB_MAX.
 *
 * Tests that need to call select() to switch databases should set
 * REDIS_TEST_SECONDARY_DB and use getSecondaryRedisDb(). The secondary DB is
 * shared by tests that explicitly request it; never call flushdb() on it.
 * Use unique keys and clean them up with del().
 *
 * Environment Variables:
 * - REDIS_HOST: Redis host; must be set to enable Redis integration tests
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
     * Redis integration tests are opt-in via REDIS_HOST. Port, password, and
     * database settings are only read after REDIS_HOST is present.
     *
     * When running under ParaTest, assigns a configured per-worker Redis DB
     * number to prevent cross-process interference.
     */
    protected function setUpInteractsWithRedis(): void
    {
        if (! $this->hasExplicitRedisConfig()) {
            $this->markTestSkipped(
                'Set REDIS_HOST to run Redis integration tests for ' . static::class
            );
        }

        // Apply per-worker DB number for parallel isolation (no-op in sequential mode)
        $this->configureParallelRedisDb();

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
     * Check if REDIS_HOST was explicitly set in environment.
     */
    protected function hasExplicitRedisConfig(): bool
    {
        return env('REDIS_HOST') !== null;
    }

    /**
     * Get the base Redis DB number from environment.
     *
     * Reads from env directly (not config) because configureParallelRedisDb()
     * mutates the config value — reading config here would cause the DB number
     * to drift upward across tests in the same worker.
     *
     * Default matches database.php: env('REDIS_DB', 0).
     */
    protected function getBaseRedisDb(): int
    {
        return RedisTestDatabases::baseDatabase();
    }

    /**
     * Get the first Redis DB number available for parallel test workers.
     */
    protected function getRedisTestDbMin(): int
    {
        return RedisTestDatabases::minimumDatabase();
    }

    /**
     * Get the last Redis DB number available for parallel test workers.
     */
    protected function getRedisTestDbMax(): int
    {
        return RedisTestDatabases::maximumDatabase();
    }

    /**
     * Get the configured secondary Redis DB number.
     */
    protected function getConfiguredSecondaryRedisDb(): ?int
    {
        return RedisTestDatabases::configuredSecondaryDatabase();
    }

    /**
     * Get the primary Redis DB number for the current test worker.
     */
    protected function getParallelRedisDb(): int
    {
        return RedisTestDatabases::primaryDatabase($this->parallelTestingToken());
    }

    /**
     * Get the primary Redis DB number for a parallel testing token.
     */
    protected function redisDatabaseForParallelToken(string $token): int
    {
        return RedisTestDatabases::databaseForToken($token);
    }

    /**
     * Get the secondary Redis DB for tests that need to call select().
     *
     * Must always return a DB number different from getParallelRedisDb().
     *
     * This DB is shared across all parallel workers. Never call flushdb() on
     * it — use unique keys and clean up via del() instead.
     */
    protected function getSecondaryRedisDb(): int
    {
        return RedisTestDatabases::secondaryDatabase($this->parallelTestingToken());
    }

    /**
     * Configure the Redis DB number for parallel test isolation.
     *
     * Sets the database.redis.default.database config to the per-worker DB number.
     */
    private function configureParallelRedisDb(): void
    {
        $token = $this->parallelTestingToken();

        if ($token === false) {
            return;
        }

        $database = $this->redisDatabaseForParallelToken($token);

        $this->app->make('config')->set('database.redis.default.database', $database);
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
     * Get the configured Redis worker databases.
     *
     * @return array<int, int>
     */
    protected function redisWorkerDatabases(): array
    {
        return RedisTestDatabases::workerDatabases();
    }

    /**
     * Get the zero-based Redis worker index for a ParaTest token.
     */
    protected function redisWorkerIndex(string $token): int
    {
        return RedisTestDatabases::workerIndex($token);
    }

    /**
     * Get a non-negative integer Redis environment value.
     */
    protected function integerRedisEnvironment(string $key, int $default): int
    {
        return RedisTestDatabases::integerEnvironment($key, $default);
    }

    /**
     * Parse a non-negative integer Redis environment value.
     */
    protected function integerRedisEnvironmentValue(string $key, mixed $value): int
    {
        return RedisTestDatabases::integerEnvironmentValue($key, $value);
    }

    /**
     * Get a raw phpredis client for direct Redis operations.
     *
     * This client has OPT_PREFIX set to the test prefix, so keys
     * are automatically prefixed when using this client.
     */
    protected function redisClient(string $connectionName = 'default'): \Redis
    {
        $client = $this->rawRedisClientWithoutPrefix($connectionName);
        $connectionConfig = $this->app->make(RedisConfig::class)->connectionConfig($connectionName);
        $prefix = $connectionConfig['options']['prefix'] ?? '';

        if (is_string($prefix) && $prefix !== '') {
            $client->setOption(\Redis::OPT_PREFIX, $prefix);
        }

        return $client;
    }

    /**
     * Get a raw phpredis client WITHOUT any OPT_PREFIX.
     *
     * Useful for verifying actual key names in Redis. Uses the per-worker
     * DB number for parallel safety.
     */
    protected function rawRedisClientWithoutPrefix(string $connectionName = 'default'): \Redis
    {
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
     * Clean up keys matching a pattern using raw client (no prefix).
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

    /**
     * Clean up keys matching multiple patterns using the trait's standard Redis test semantics.
     *
     * If Redis was not explicitly enabled, cleanup is skipped just like
     * setUpInteractsWithRedis(). If REDIS_HOST is set, connection failures
     * still propagate as real test environment errors.
     */
    protected function cleanupRedisKeysWithPatterns(string ...$patterns): void
    {
        if (! $this->hasExplicitRedisConfig()) {
            return;
        }

        $client = $this->rawRedisClientWithoutPrefix();

        try {
            foreach ($patterns as $pattern) {
                $keys = $client->keys($pattern);
                if (! empty($keys)) {
                    $client->del(...$keys);
                }
            }
        } finally {
            $client->close();
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

        $config = $this->app->make('config');

        // Check if already exists
        if ($config->get("database.redis.{$connectionName}") !== null) {
            return $connectionName;
        }

        $connectionConfig = [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', null) ?: null,
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => $this->getParallelRedisDb(),
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

        $config->set("database.redis.{$connectionName}", $connectionConfig);

        return $connectionName;
    }
}
