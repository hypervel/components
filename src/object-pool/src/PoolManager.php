<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Hypervel\ObjectPool\Contracts\Factory as FactoryContract;
use Hypervel\ObjectPool\Contracts\ObjectPool;
use Psr\Container\ContainerInterface;
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
        protected ContainerInterface $container
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
     */
    public function set(string $name, ObjectPool $pool): static
    {
        $this->pools[$name] = $pool;

        return $this;
    }

    /**
     * Set multiple pools the manager.
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
     */
    public function remove(string $name): static
    {
        unset($this->pools[$name]);

        return $this;
    }

    /**
     * Flush all pools.
     */
    public function flush(): static
    {
        $this->pools = [];

        return $this;
    }
}
