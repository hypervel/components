<?php

declare(strict_types=1);

namespace Hypervel\Redis\Pool;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Pool\ConnectionInterface;
use Hypervel\Pool\Pool;
use Hypervel\Redis\Frequency;
use Hypervel\Redis\PhpRedisClusterConnection;
use Hypervel\Redis\PhpRedisConnection;
use Hypervel\Redis\RedisConfig;
use Hypervel\Support\Arr;

class RedisPool extends Pool
{
    protected array $config;

    /**
     * Create a new Redis pool instance.
     */
    public function __construct(
        Container $container,
        protected string $name
    ) {
        $configService = $container->make(RedisConfig::class);
        $this->config = $configService->connectionConfig($this->name);
        $poolOptions = Arr::get($this->config, 'pool', []);

        $this->frequency = new Frequency($this);

        parent::__construct($container, $poolOptions);
    }

    /**
     * Get the pool name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the Redis connection configuration.
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Create a new pooled Redis connection.
     */
    protected function createConnection(): ConnectionInterface
    {
        if ($this->config['cluster']['enable'] ?? false) {
            return new PhpRedisClusterConnection($this->container, $this, $this->config);
        }

        return new PhpRedisConnection($this->container, $this, $this->config);
    }
}
