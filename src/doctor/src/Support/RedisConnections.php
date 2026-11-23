<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Support;

use Hypervel\Redis\RedisConnection;
use Hypervel\Session\SessionManager;
use Hypervel\Support\Facades\Redis;
use InvalidArgumentException;
use RedisClusterException;
use RuntimeException;

final class RedisConnections
{
    /**
     * Get the configuration keys that select active Redis connections.
     *
     * @return list<string>
     */
    public static function selectors(): array
    {
        $keys = [];
        $cache = Configured::string('cache.default');

        foreach ($cache === null ? [] : ActiveDrivers::cacheStores($cache) as $store) {
            if ($store === 'null') {
                continue;
            }

            $configuration = Configured::array("cache.stores.{$store}");

            if (($configuration['driver'] ?? null) !== 'redis') {
                continue;
            }

            $keys[] = "cache.stores.{$store}.connection";
            if (isset($configuration['lock_connection'])) {
                $keys[] = "cache.stores.{$store}.lock_connection";
            }
        }

        $queue = Configured::string('queue.default');

        foreach ($queue === null ? [] : ActiveDrivers::queueConnections($queue) as $name) {
            $configuration = Configured::array("queue.connections.{$name}");

            if ($name !== 'null' && ($configuration['driver'] ?? null) === 'redis') {
                $keys[] = "queue.connections.{$name}.connection";
            }
        }

        if (Configured::string('session.driver') === 'redis') {
            $keys[] = 'session.connection';
        }

        return $keys;
    }

    /**
     * Resolve the Redis connection selected by a configuration key.
     *
     * RedisManager::connection() and SessionManager::createRedisDriver() supply different defaults.
     * Only the session manager requires the key to exist.
     */
    public static function name(string $key): string
    {
        if ($key === 'session.connection') {
            $name = config()->nullableString($key);

            return $name === null || $name === '' ? SessionManager::DEFAULT_REDIS_CONNECTION : $name;
        }

        $name = config()->nullableString($key, null);

        return $name === null || $name === '' ? 'default' : $name;
    }

    /**
     * Collect valid connection names and report malformed selectors independently.
     *
     * @param list<string> $selectors
     * @return array{0: list<string>, 1: array<string, string>}
     */
    public static function resolve(array $selectors): array
    {
        $names = [];
        $failures = [];

        foreach ($selectors as $key) {
            try {
                $names[] = self::name($key);
            } catch (InvalidArgumentException $exception) {
                $failures[$key] = $exception->getMessage();
            }
        }

        return [array_values(array_unique($names)), $failures];
    }

    /**
     * Ensure the named Redis connection accepts a PING.
     */
    public static function ensureReachable(string $name, bool $includeName = true): void
    {
        Redis::connection($name)->withConnection(static function (RedisConnection $connection) use ($name, $includeName): void {
            // Standalone ERR replies return false, while Cluster can throw a generic exception.
            // Clear stale errors so getLastError() describes this PING on the held client.
            $connection->clearLastError();

            $failure = null;
            try {
                if ($connection->ping() !== false) {
                    return;
                }
            } catch (RedisClusterException $exception) {
                $failure = $exception;
            }

            $reason = $connection->getLastError();
            if ($failure !== null && $reason === null) {
                throw $failure;
            }

            $subject = $includeName ? "Redis connection [{$name}]" : 'Redis';
            throw new RuntimeException(
                $reason === null ? "{$subject} rejected PING." : "{$subject} rejected PING: {$reason}",
                previous: $failure,
            );
        });
    }
}
