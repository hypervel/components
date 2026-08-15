<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing;

use Hypervel\Contracts\Config\Repository;
use RuntimeException;

class RedisTestConfiguration
{
    /**
     * Determine if Redis integration testing is configured.
     */
    public static function isConfigured(): bool
    {
        return env('REDIS_CLUSTER_HOSTS_AND_PORTS') !== null
            || env('REDIS_HOST') !== null;
    }

    /**
     * Determine if Redis integration testing uses a Cluster.
     */
    public static function usesCluster(): bool
    {
        return env('REDIS_CLUSTER_HOSTS_AND_PORTS') !== null;
    }

    /**
     * Get the configured Redis Cluster seeds.
     *
     * @return list<string>
     */
    public static function clusterSeeds(): array
    {
        $value = env('REDIS_CLUSTER_HOSTS_AND_PORTS');

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException('REDIS_CLUSTER_HOSTS_AND_PORTS must be a comma-separated list of non-empty Redis Cluster seeds.');
        }

        $seeds = array_map(trim(...), explode(',', $value));

        if (in_array('', $seeds, true)) {
            throw new RuntimeException('REDIS_CLUSTER_HOSTS_AND_PORTS must be a comma-separated list of non-empty Redis Cluster seeds.');
        }

        return $seeds;
    }

    /**
     * Get the primary Redis database for the current test worker.
     */
    public static function primaryDatabase(string|false $token): int
    {
        if (static::usesCluster()) {
            if ($token !== false) {
                throw new RuntimeException('Redis Cluster integration tests must run serially. Run them with ./vendor/bin/phpunit instead of ParaTest.');
            }

            return 0;
        }

        return RedisTestDatabases::primaryDatabase($token);
    }

    /**
     * Get the secondary Redis database for the current test worker.
     */
    public static function secondaryDatabase(string|false $token): int
    {
        if (static::usesCluster()) {
            throw new RuntimeException('Redis Cluster does not support secondary logical databases.');
        }

        return RedisTestDatabases::secondaryDatabase($token);
    }

    /**
     * Configure every named Redis connection for integration testing.
     */
    public static function configure(Repository $config, string|false $token): void
    {
        $usesCluster = static::usesCluster();
        $database = static::primaryDatabase($token);
        $clusterSeeds = $usesCluster ? static::clusterSeeds() : [];

        foreach ($config->array('database.redis') as $name => $connection) {
            if (in_array($name, ['client', 'options'], true) || ! is_array($connection)) {
                continue;
            }

            $url = $connection['url'] ?? null;

            if ($url !== null && $url !== '') {
                throw new RuntimeException(
                    "Redis connection [{$name}] must not use a URL during integration tests because the test topology is configured through REDIS_HOST or REDIS_CLUSTER_HOSTS_AND_PORTS."
                );
            }

            if ($usesCluster) {
                unset(
                    $connection['url'],
                    $connection['host'],
                    $connection['port'],
                    $connection['database'],
                    $connection['name'],
                    $connection['retry_interval'],
                    $connection['sentinel'],
                );

                $connection['cluster'] = [
                    'enabled' => true,
                    'seeds' => $clusterSeeds,
                ];
            } else {
                $connection['database'] = $database;
            }

            $config->set("database.redis.{$name}", $connection);
        }
    }
}
