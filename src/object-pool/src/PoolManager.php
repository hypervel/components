<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Hypervel\Contracts\Container\Container;
use Hypervel\ObjectPool\Contracts\Factory as FactoryContract;
use Hypervel\ObjectPool\Contracts\ObjectPool;
use RuntimeException;

class PoolManager implements FactoryContract
{
    /**
     * Registered object pools managed by the manager.
     *
     * @var ObjectPool[]
     */
    protected array $pools = [];

    /**
     * Create a new pool manager with the given configuration.
     */
    public function __construct(
        protected Container $container
    ) {
    }

    /**
     * Get a managed pool by name.
     */
    public function get(string $name): ObjectPool
    {
        if (! $pool = $this->pools[$name] ?? null) {
            throw new RuntimeException("The pool name `{$name}` does not exist.");
        }

        return $pool;
    }

    /**
     * Create and register a new object pool.
     *
     * Boot-only. Pools persist on the singleton PoolManager for the worker
     * lifetime and are shared by every coroutine.
     */
    public function create(string $name, callable $callback, array $options = []): ObjectPool
    {
        if (isset($this->pools[$name])) {
            throw new RuntimeException("The pool name `{$name}` already exists.");
        }

        $pool = new SimpleObjectPool(
            $this->container,
            $callback,
            $options
        );

        return $this->pools[$name] = $pool;
    }

    /**
     * Get all registered pools.
     */
    public function pools(): array
    {
        return $this->pools;
    }

    /**
     * Set a pool to the manager.
     *
     * Boot or tests only. Replaces a pool on the singleton PoolManager;
     * concurrent coroutines may already hold a reference to the prior pool.
     */
    public function set(string $name, ObjectPool $pool): static
    {
        $this->pools[$name] = $pool;

        return $this;
    }

    /**
     * Set multiple pools the manager.
     *
     * Boot or tests only. Delegates to set() for each pool, mutating the
     * singleton PoolManager shared by every coroutine.
     */
    public function setPools(array $pools): static
    {
        foreach ($pools as $name => $pool) {
            $this->set($name, $pool);
        }

        return $this;
    }

    /**
     * Check if a pool exists.
     */
    public function has(string $name): bool
    {
        return isset($this->pools[$name]);
    }

    /**
     * Remove a pool from the manager.
     *
     * Boot or tests only. Removes a pool from the singleton PoolManager;
     * concurrent coroutines may already hold a reference to the removed pool.
     */
    public function remove(string $name): static
    {
        unset($this->pools[$name]);

        return $this;
    }

    /**
     * Flush all pools.
     *
     * Boot or tests only. Clears the singleton PoolManager registry; concurrent
     * coroutines may already hold pool references that next lookup cannot find.
     */
    public function flush(): static
    {
        $this->pools = [];

        return $this;
    }
}
