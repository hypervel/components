<?php

declare(strict_types=1);

namespace Hypervel\Redis\Pool;

use Hypervel\Contracts\Container\Container as ContainerContract;

class PoolFactory
{
    /**
     * @var RedisPool[]
     */
    protected array $pools = [];

    public function __construct(
        protected ContainerContract $container
    ) {
    }

    /**
     * Flush all connections from all pools.
     */
    public function flushAll(): void
    {
        foreach ($this->pools as $pool) {
            $pool->flushAll();
        }
    }

    /**
     * Flush a specific pool, closing all connections.
     */
    public function flushPool(string $name): void
    {
        if (isset($this->pools[$name])) {
            $this->pools[$name]->flushAll();
            unset($this->pools[$name]);
        }
    }

    /**
     * Get or create a pool for the given connection name.
     */
    public function getPool(string $name): RedisPool
    {
        if (isset($this->pools[$name])) {
            return $this->pools[$name];
        }

        return $this->pools[$name] = $this->container->make(RedisPool::class, ['name' => $name]);
    }
}
