<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Hypervel\Redis\RedisConfig;
use Hypervel\Support\Facades\Redis;
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
 * interference. The DB is computed as REDIS_DB + TEST_TOKEN, where TEST_TOKEN
 * is set by ParaTest (1, 2, 3...). Sequential runs use REDIS_DB directly.
 *
 * The base DB (REDIS_DB) is reserved as a shared secondary DB for tests that
 * need to call select() to switch databases. No parallel worker uses it as
 * their primary, so it is never flushed during a parallel run. Tests needing
 * a secondary DB should use getSecondaryRedisDb() instead of hardcoding a
 * DB number.
 *
 * If a worker's TEST_TOKEN exceeds the available Redis databases, its Redis
 * tests are skipped (non-Redis tests still run on that worker).
 *
 * Environment Variables:
 * - REDIS_HOST: Redis host (default: 127.0.0.1)
 * - REDIS_PORT: Redis port (default: 6379)
 * - REDIS_DB: Base Redis database number (default: 1)
 * - REDIS_PASSWORD: Redis password (optional)
 */
trait InteractsWithRedis
{
    /**
     * Indicates if connection failed once with defaults, skip all subsequent tests.
     */
    private static bool $connectionFailedOnceWithDefaultsSkip = false;

    /**
     * Indicates if no Redis DB is available for this parallel worker (overflow).
     */
    private static bool $noRedisDbAvailable = false;

    /**
     * Set up Redis for testing (auto-called by setUpTraits).
     *
     * Follows Laravel's InteractsWithRedis pattern:
     * - Only skips if using default host/port AND no explicit REDIS_HOST env var
     * - If explicit config exists and fails, the exception propagates (misconfiguration)
     *
     * When running under ParaTest, assigns a per-worker Redis DB number to
     * prevent cross-process interference.
     */
    protected function setUpInteractsWithRedis(): void
    {
        if (static::$connectionFailedOnceWithDefaultsSkip) {
            $this->markTestSkipped(
                'Redis connection failed with defaults. Set REDIS_HOST & REDIS_PORT to enable ' . static::class
            );
        }

        if (static::$noRedisDbAvailable) {
            $this->markTestSkipped(
                'No Redis database available for this parallel worker. Reduce paratest -p or increase Redis databases.'
            );
        }

        // Apply per-worker DB number for parallel isolation (no-op in sequential mode)
        $this->configureParallelRedisDb();

        $host = env('REDIS_HOST', '127.0.0.1');
        $port = (int) env('REDIS_PORT', 6379);

        try {
            $this->flushRedis();
        } catch (Throwable $e) {
            if ($host === '127.0.0.1' && $port === 6379 && env('REDIS_HOST') === null) {
                static::$connectionFailedOnceWithDefaultsSkip = true;
                $this->markTestSkipped(
                    'Redis connection failed with defaults. Set REDIS_HOST & REDIS_PORT to enable ' . static::class
                );
            }
            // Explicit config exists but failed - rethrow so test fails (misconfiguration)
            throw $e;
        }
    }

    /**
     * Tear down Redis (auto-called via beforeApplicationDestroyed).
     */
    protected function tearDownInteractsWithRedis(): void
    {
        if (static::$connectionFailedOnceWithDefaultsSkip) {
            return;
        }

        try {
            $this->flushRedis();
        } catch (Throwable) {
            // Ignore cleanup errors
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
        return (int) env('REDIS_DB', 0);
    }

    /**
     * Get the primary Redis DB number for the current parallel test worker.
     *
     * Sequential (no TEST_TOKEN): returns REDIS_DB (default 1).
     * Parallel (TEST_TOKEN=N): returns REDIS_DB + N.
     */
    protected function getParallelRedisDb(): int
    {
        $token = env('TEST_TOKEN');

        return $this->getBaseRedisDb() + ($token !== null ? (int) $token : 0);
    }

    /**
     * Get the secondary Redis DB for tests that need to call select().
     *
     * Must always return a DB number different from getParallelRedisDb().
     *
     * Parallel mode: returns the base DB (REDIS_DB). No worker uses it as
     * their primary (workers start at base + 1), so it is never flushed
     * during a parallel run — safe for shared use with unique keys.
     *
     * Sequential mode: returns base + 1, since the primary IS the base DB
     * and we need a different one. No conflict because there are no workers.
     *
     * IMPORTANT: This DB is shared across all parallel workers. Never call
     * flushdb() on it — use unique keys (e.g. uniqid()) and clean up via
     * del() instead.
     */
    protected function getSecondaryRedisDb(): int
    {
        $base = $this->getBaseRedisDb();

        if (env('TEST_TOKEN') !== null) {
            return $base;
        }

        // Sequential: primary == base, so use the next DB up
        return $base + 1;
    }

    /**
     * Configure the Redis DB number for parallel test isolation.
     *
     * Sets the database.redis.default.database config to the per-worker DB number.
     * On the first call per process, also checks whether the DB number is
     * within Redis's configured database limit.
     */
    private function configureParallelRedisDb(): void
    {
        if (env('TEST_TOKEN') === null) {
            return;
        }

        $db = $this->getParallelRedisDb();

        // Check overflow on the first Redis test in this worker
        if (static::$noRedisDbAvailable === false && ! $this->isRedisDbAvailable($db)) {
            static::$noRedisDbAvailable = true;
            $this->markTestSkipped(
                "No Redis database available for this parallel worker (need DB {$db}). "
                . 'Reduce paratest -p or increase Redis databases.'
            );
        }

        $this->app->make('config')->set('database.redis.default.database', $db);
    }

    /**
     * Check if the given Redis DB number is within the server's configured limit.
     */
    private function isRedisDbAvailable(int $db): bool
    {
        try {
            $client = new \Redis();
            $client->connect(
                env('REDIS_HOST', '127.0.0.1'),
                (int) env('REDIS_PORT', 6379)
            );

            $auth = env('REDIS_PASSWORD');
            if ($auth) {
                $client->auth($auth);
            }

            $config = $client->config('GET', 'databases');
            $maxDatabases = (int) ($config['databases'] ?? 16);
            $client->close();

            return $db < $maxDatabases;
        } catch (Throwable) {
            // If we can't check, assume it's available — the actual connection
            // attempt in flushRedis() will catch real failures.
            return true;
        }
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
        $client = new \Redis();
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
     * If Redis is unavailable on the default fallback configuration, cleanup is skipped just like
     * setUpInteractsWithRedis()/tearDownInteractsWithRedis(). If Redis was explicitly configured,
     * connection failures still propagate as real test environment errors.
     */
    protected function cleanupRedisKeysWithPatterns(string ...$patterns): void
    {
        if (static::$connectionFailedOnceWithDefaultsSkip) {
            return;
        }

        try {
            $client = $this->rawRedisClientWithoutPrefix();
        } catch (Throwable $e) {
            if (! $this->hasExplicitRedisConfig()) {
                return;
            }

            throw $e;
        }

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
        $connectionName = 'test_opt_' . ($optPrefix === '' ? 'none' : md5($optPrefix));

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

        // RedisFactory snapshots configured pools in __construct, so reset it after adding runtime test pools.
        $this->app->forgetInstance(\Hypervel\Redis\RedisFactory::class);

        return $connectionName;
    }
}
