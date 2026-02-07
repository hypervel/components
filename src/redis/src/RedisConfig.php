<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use InvalidArgumentException;

class RedisConfig
{
    /**
     * Get the configured Redis connection names.
     *
     * @param array<string, mixed> $redisConfig
     * @return list<string>
     */
    public static function connectionNames(array $redisConfig): array
    {
        $names = [];

        foreach ($redisConfig as $name => $connectionConfig) {
            if (in_array($name, ['client', 'options', 'clusters'], true)) {
                continue;
            }

            self::assertConnectionConfig($name, $connectionConfig);

            $names[] = $name;
        }

        return $names;
    }

    /**
     * Get a single Redis connection config with merged options.
     *
     * @param array<string, mixed> $redisConfig
     * @return array<string, mixed>
     */
    public static function connectionConfig(array $redisConfig, string $name): array
    {
        $connectionConfig = $redisConfig[$name] ?? null;
        self::assertConnectionConfig($name, $connectionConfig);

        $sharedOptions = $redisConfig['options'] ?? [];
        if (! is_array($sharedOptions)) {
            throw new InvalidArgumentException('The redis options config must be an array.');
        }

        $connectionOptions = $connectionConfig['options'] ?? [];
        if (! is_array($connectionOptions)) {
            throw new InvalidArgumentException(sprintf('The redis connection [%s] options must be an array.', $name));
        }

        $connectionConfig['options'] = array_replace($sharedOptions, $connectionOptions);

        return $connectionConfig;
    }

    /**
     * Validate a redis connection config entry.
     */
    private static function assertConnectionConfig(string $name, mixed $connectionConfig): void
    {
        if (! is_array($connectionConfig)) {
            throw new InvalidArgumentException(sprintf('The redis connection [%s] must be an array.', $name));
        }

        if (! self::isConnectionConfig($connectionConfig)) {
            throw new InvalidArgumentException(sprintf('The redis connection [%s] does not have a valid connection shape.', $name));
        }
    }

    /**
     * Determine if config entry looks like a redis connection.
     *
     * @param array<string, mixed> $connectionConfig
     */
    private static function isConnectionConfig(array $connectionConfig): bool
    {
        return array_intersect(
            array_keys($connectionConfig),
            [
                'auth',
                'cluster',
                'context',
                'db',
                'event',
                'host',
                'options',
                'pool',
                'port',
                'read_timeout',
                'reserved',
                'retry_interval',
                'sentinel',
                'timeout',
            ],
        ) !== [];
    }
}
