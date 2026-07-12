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
     * Flush all connections from all pools and clear the cached pool instances.
     *
     * Boot or tests only. Closes worker-shared pools; connections already
     * checked out by concurrent coroutines are destroyed on release.
     */
    public function flushAll(): void
    {
        $pools = $this->pools;
        $this->pools = [];

        foreach ($pools as $pool) {
            $pool->close();
        }
    }

    /**
     * Flush a specific pool, closing all connections.
     *
     * Boot or tests only. Closes a worker-shared pool; connections already
     * checked out by concurrent coroutines are destroyed on release.
     */
    public function flushPool(string $name): void
    {
        $pool = $this->pools[$name] ?? null;

        if ($pool !== null) {
            unset($this->pools[$name]);
            $pool->close();
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
