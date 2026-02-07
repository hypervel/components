<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Hyperf\Contract\ConfigInterface;
use InvalidArgumentException;

class RedisConfig
{
    /**
     * Create a new redis config helper.
     */
    public function __construct(private ConfigInterface $config) {}

    /**
     * Get the configured Redis connection names.
     *
     * @return list<string>
     */
    public function connectionNames(): array
    {
        $redisConfig = $this->all();
        $names = [];

        foreach ($redisConfig as $name => $connectionConfig) {
            if (in_array($name, ['client', 'options', 'clusters'], true)) {
                continue;
            }

            $this->assertConnectionConfig($name, $connectionConfig);

            $names[] = $name;
        }

        return $names;
    }

    /**
     * Get a single Redis connection config with merged options.
     *
     * @return array<string, mixed>
     */
    public function connectionConfig(string $name): array
    {
        $redisConfig = $this->all();
        $connectionConfig = $redisConfig[$name] ?? null;
        $this->assertConnectionConfig($name, $connectionConfig);

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
     * Get all redis config.
     *
     * @return array<string, mixed>
     */
    private function all(): array
    {
        $redisConfig = $this->config->get('database.redis');
        if (! is_array($redisConfig)) {
            throw new InvalidArgumentException('The redis config must be an array.');
        }

        return $redisConfig;
    }

    /**
     * Validate a redis connection config entry.
     */
    private function assertConnectionConfig(string $name, mixed $connectionConfig): void
    {
        if (! is_array($connectionConfig)) {
            throw new InvalidArgumentException(sprintf('The redis connection [%s] must be an array.', $name));
        }

        $clusterConfig = $connectionConfig['cluster'] ?? [];
        if (! is_array($clusterConfig)) {
            throw new InvalidArgumentException(sprintf('The redis connection [%s] cluster config must be an array.', $name));
        }

        $sentinelConfig = $connectionConfig['sentinel'] ?? [];
        if (! is_array($sentinelConfig)) {
            throw new InvalidArgumentException(sprintf('The redis connection [%s] sentinel config must be an array.', $name));
        }

        $clusterEnabled = (bool) ($clusterConfig['enable'] ?? false);
        $sentinelEnabled = (bool) ($sentinelConfig['enable'] ?? false);

        if ($clusterEnabled && $sentinelEnabled) {
            throw new InvalidArgumentException(sprintf('The redis connection [%s] cannot enable both cluster and sentinel.', $name));
        }

        if ($clusterEnabled) {
            $seeds = $clusterConfig['seeds'] ?? null;
            if (! is_array($seeds) || $seeds === []) {
                throw new InvalidArgumentException(sprintf('The redis connection [%s] cluster seeds must be a non-empty array.', $name));
            }

            return;
        }

        if ($sentinelEnabled) {
            $nodes = $sentinelConfig['nodes'] ?? null;
            $masterName = $sentinelConfig['master_name'] ?? null;

            if (! is_array($nodes) || $nodes === []) {
                throw new InvalidArgumentException(sprintf('The redis connection [%s] sentinel nodes must be a non-empty array.', $name));
            }

            if (! is_string($masterName) || $masterName === '') {
                throw new InvalidArgumentException(sprintf('The redis connection [%s] sentinel master name must be configured.', $name));
            }

            return;
        }

        if (! array_key_exists('host', $connectionConfig) || ! array_key_exists('port', $connectionConfig)) {
            throw new InvalidArgumentException(sprintf('The redis connection [%s] must define host and port.', $name));
        }
    }
}
