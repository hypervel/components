<?php

declare(strict_types=1);

namespace Hypervel\Database\Pool;

use Hyperf\Di\Container;
use Psr\Container\ContainerInterface;

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
        protected ContainerInterface $container
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

        if ($this->container instanceof Container) {
            $pool = $this->container->make(DbPool::class, ['name' => $name]);
        } else {
            $pool = new DbPool($this->container, $name);
        }

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
     * Flush a specific pool.
     */
    public function flushPool(string $name): void
    {
        if (isset($this->pools[$name])) {
            $this->pools[$name]->flush();
            unset($this->pools[$name]);
        }
    }

    /**
     * Flush all pools.
     */
    public function flushAll(): void
    {
        foreach ($this->pools as $pool) {
            $pool->flush();
        }

        $this->pools = [];
    }
}
