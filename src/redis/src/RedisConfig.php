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

        if ((bool) ($connectionConfig['cluster']['enabled'] ?? false)) {
            $connectionConfig = $this->normalizeClusterConfiguration($name, $connectionConfig);
        }

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
            $connectionConfig['events'] = $this->eventsOverride;
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
     * Get all Redis config.
     *
     * @return array<string, mixed>
     */
    private function all(): array
    {
        $redisConfig = $this->config->array('database.redis');

        if (array_key_exists('client', $redisConfig) && $redisConfig['client'] !== 'phpredis') {
            throw new InvalidArgumentException('The phpredis Redis client is the only supported client.');
        }

        if (array_key_exists('clusters', $redisConfig)) {
            throw new InvalidArgumentException(
                'The redis.clusters configuration is not supported. Configure cluster settings on a named Redis connection.'
            );
        }

        return $redisConfig;
    }

    /**
     * Validate a Redis connection config entry.
     */
    private function validateConnectionConfig(string $name, mixed $connectionConfig): void
    {
        if (! is_array($connectionConfig)) {
            throw new InvalidArgumentException(sprintf('The redis connection [%s] must be an array.', $name));
        }

        $scheme = $connectionConfig['scheme'] ?? null;

        if ($scheme !== null && (! is_string($scheme) || ! in_array($scheme, ['tcp', 'tls'], true))) {
            throw new InvalidArgumentException(sprintf(
                'The redis connection [%s] scheme must be tcp or tls.',
                $name,
            ));
        }

        if (isset($connectionConfig['context']) && ! is_array($connectionConfig['context'])) {
            throw new InvalidArgumentException(sprintf('The redis connection [%s] context must be an array.', $name));
        }

        $clusterConfig = $connectionConfig['cluster'] ?? [];
        if (! is_array($clusterConfig)) {
            throw new InvalidArgumentException(sprintf('The redis connection [%s] cluster config must be an array.', $name));
        }

        $sentinelConfig = $connectionConfig['sentinel'] ?? [];
        if (! is_array($sentinelConfig)) {
            throw new InvalidArgumentException(sprintf('The redis connection [%s] sentinel config must be an array.', $name));
        }

        $clusterEnabled = (bool) ($clusterConfig['enabled'] ?? false);
        $sentinelEnabled = (bool) ($sentinelConfig['enabled'] ?? false);

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

    /**
     * Normalize the transport shared by all nodes in a Redis Cluster.
     */
    private function normalizeClusterConfiguration(string $name, array $connectionConfig): array
    {
        /** @var null|'tcp'|'tls' $scheme */
        $scheme = $connectionConfig['scheme'] ?? null;
        /** @var array<array-key, mixed> $context */
        $context = $connectionConfig['context'] ?? [];
        /** @var array<array-key, string> $seeds */
        $seeds = $connectionConfig['cluster']['seeds'];

        $seedSchemes = [];

        foreach ($seeds as $seed) {
            if (preg_match('/^([a-z][a-z0-9+.-]*):\/\//i', $seed, $matches) !== 1) {
                continue;
            }

            $seedScheme = strtolower($matches[1]);

            if (! in_array($seedScheme, ['tcp', 'tls', 'ssl'], true)) {
                throw new InvalidArgumentException(sprintf(
                    'The redis connection [%s] cluster seeds may only use tcp, tls, or ssl schemes.',
                    $name,
                ));
            }

            $seedSchemes[] = $seedScheme === 'ssl' ? 'tls' : $seedScheme;
        }

        $seedSchemes = array_values(array_unique($seedSchemes));
        $seedScheme = count($seedSchemes) === 1 ? $seedSchemes[0] : null;
        $selectedScheme = $scheme ?? ($context !== [] ? 'tls' : ($seedScheme ?? 'tcp'));

        if (count($seedSchemes) > 1
            || ($seedScheme !== null && $seedScheme !== $selectedScheme)
            || ($context !== [] && $selectedScheme !== 'tls')) {
            throw new InvalidArgumentException(sprintf(
                'The redis connection [%s] cluster transport is inconsistent. PhpRedis applies one stream context to every discovered node; use a single tcp or tls transport across scheme, context, and seeds.',
                $name,
            ));
        }

        $connectionConfig['scheme'] = $selectedScheme;
        $connectionConfig['context'] = $context;
        $connectionConfig['cluster']['seeds'] = array_map(
            static function (string $seed) use ($selectedScheme): string {
                if (preg_match('/^[a-z][a-z0-9+.-]*(:\/\/.*)$/i', $seed, $matches) === 1) {
                    return $selectedScheme . $matches[1];
                }

                return $selectedScheme . '://' . $seed;
            },
            $seeds,
        );

        return $connectionConfig;
    }
}
