<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Support;

use Hypervel\Redis\RedisConnection;
use Hypervel\Session\SessionManager;
use Hypervel\Support\Facades\Redis;
use RedisClusterException;
use RuntimeException;

final class RedisConnections
{
    /**
     * Get the effective name using the default selection from RedisManager::connection().
     */
    public static function name(?string $name): string
    {
        return $name === null || $name === '' ? 'default' : $name;
    }

    /**
     * Get the Redis connection selected by the session manager.
     */
    public static function sessionName(): string
    {
        $name = config()->nullableString('session.connection');

        return $name === null || $name === '' ? SessionManager::DEFAULT_REDIS_CONNECTION : $name;
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
