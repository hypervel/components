<?php

declare(strict_types=1);

namespace Hypervel\Database\Pool;

use Hypervel\Contracts\Container\Container;

/**
 * Factory for creating and caching database connection pools.
 */
class PoolFactory
{
    /**
     * The cached pool instances.
     *
     * @var array<string, DbPool>
     */
    protected array $pools = [];

    public function __construct(
        protected Container $container
    ) {
    }

    /**
     * Get or create a pool for the given connection name.
     */
    public function getPool(string $name): DbPool
    {
        if (isset($this->pools[$name])) {
            return $this->pools[$name];
        }

        $pool = $this->container->make(DbPool::class, ['name' => $name]);

        return $this->pools[$name] = $pool;
    }

    /**
     * Check if a pool exists for the given connection name.
     */
    public function hasPool(string $name): bool
    {
        return isset($this->pools[$name]);
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
     * Flush all pools, closing all connections.
     */
    public function flushAll(): void
    {
        foreach ($this->pools as $pool) {
            $pool->flushAll();
        }

        $this->pools = [];
    }
}
