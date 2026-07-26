<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Hypervel\Config\Repository;
use Hypervel\Support\ConfigurationUrlParser;
use InvalidArgumentException;

class RedisConfig
{
    /**
     * The worker-wide event enablement override.
     */
    private ?bool $eventsOverride = null;

    /**
     * Create a new redis config helper.
     */
    public function __construct(private Repository $config)
    {
    }

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

            if (! is_array($connectionConfig)) {
                throw new InvalidArgumentException(sprintf('The redis connection [%s] must be an array.', $name));
            }

            $this->validateConnectionConfig(
                $name,
                $this->parseConnectionConfiguration($connectionConfig),
            );

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

        if (! is_array($connectionConfig)) {
            throw new InvalidArgumentException(sprintf('The redis connection [%s] must be an array.', $name));
        }

        $connectionConfig = $this->parseConnectionConfiguration($connectionConfig);
        $this->validateConnectionConfig($name, $connectionConfig);

        $sharedOptions = $redisConfig['options'] ?? [];
        if (! is_array($sharedOptions)) {
            throw new InvalidArgumentException('The redis options config must be an array.');
        }

        $connectionOptions = $connectionConfig['options'] ?? [];
        if (! is_array($connectionOptions)) {
            throw new InvalidArgumentException(sprintf('The redis connection [%s] options must be an array.', $name));
        }

        $connectionConfig['options'] = array_replace($sharedOptions, $connectionOptions);

        if (array_key_exists('prefix', $connectionConfig)) {
            $connectionConfig['options']['prefix'] = $connectionConfig['prefix'];
        }

        if ($this->eventsOverride !== null) {
            $connectionConfig['event']['enable'] = $this->eventsOverride;
        }

        return $connectionConfig;
    }

    /**
     * Enable Redis command events.
     *
     * Boot-only. The worker-wide override affects every subsequently assembled connection config.
     */
    public function enableEvents(): void
    {
        $this->eventsOverride = true;
    }

    /**
     * Disable Redis command events.
     *
     * Boot-only. The worker-wide override affects every subsequently assembled connection config.
     */
    public function disableEvents(): void
    {
        $this->eventsOverride = false;
    }

    /**
     * Parse and normalize a Redis connection configuration.
     *
     * Handles URL-based configuration and translates the `driver` key
     * produced by the URL parser into a `scheme` key for transport
     * protocol selection (tcp/tls). The `driver` key is removed since
     * Redis connections don't have a driver concept like databases.
     */
    private function parseConnectionConfiguration(array $config): array
    {
        $parsed = (new ConfigurationUrlParser)->parseConfiguration($config);

        $driver = strtolower((string) ($parsed['driver'] ?? ''));

        if (in_array($driver, ['tcp', 'tls'], true)) {
            $parsed['scheme'] = $driver;
        }

        unset($parsed['driver']);

        return $parsed;
    }

    /**
     * Get all redis config.
     *
     * @return array<string, mixed>
     */
    private function all(): array
    {
        return $this->config->array('database.redis');
    }

    /**
     * Validate a redis connection config entry.
     */
    private function validateConnectionConfig(string $name, mixed $connectionConfig): void
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

            foreach ($seeds as $seed) {
                if (! is_string($seed) || $seed === '') {
                    throw new InvalidArgumentException(sprintf('The redis connection [%s] cluster seeds must all be non-empty strings.', $name));
                }
            }

            return;
        }

        if ($sentinelEnabled) {
            $nodes = $sentinelConfig['nodes'] ?? null;
            $masterName = $sentinelConfig['master_name'] ?? null;

            if (! is_array($nodes) || $nodes === []) {
                throw new InvalidArgumentException(sprintf('The redis connection [%s] sentinel nodes must be a non-empty array.', $name));
            }

            foreach ($nodes as $node) {
                if (! is_string($node) || $node === '') {
                    throw new InvalidArgumentException(sprintf('The redis connection [%s] sentinel nodes must all be non-empty strings.', $name));
                }
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
