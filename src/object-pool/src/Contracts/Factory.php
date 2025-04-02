<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool\Contracts;

interface Factory
{
    /**
     * Get a managed pool by name.
     */
    public function getPool(string $name): ObjectPool;

    /**
     * Create and register a new object pool.
     */
    public function createPool(string $name, callable $callback, array $options = []): ObjectPool;

    /**
     * Get all registered pools.
     */
    public function pools(): array;

    /**
     * Check if a pool exists.
     */
    public function hasPool(string $name): bool;
}
