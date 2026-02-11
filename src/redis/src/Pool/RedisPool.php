<?php

declare(strict_types=1);

namespace Hypervel\Redis\Pool;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Pool\ConnectionInterface;
use Hypervel\Pool\Pool;
use Hypervel\Redis\Frequency;
use Hypervel\Redis\RedisConfig;
use Hypervel\Redis\RedisConnection;
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
        $configService = $container->get(RedisConfig::class);
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
        return new RedisConnection($this->container, $this, $this->config);
    }
}
